#!/usr/bin/env python3
"""Configure and manage a private Docker Compose NextGallery embedding service.

Supports both CPU and GPU (NVIDIA CUDA with multi-GPU device selection).
Provides an interactive guided setup wizard and outputs an exact summary of
settings to enter in Nextcloud.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time


def private_write(path: Path, text: str) -> None:
    temporary = path.with_name(path.name + '.tmp')
    with temporary.open('w', encoding='utf-8') as file:
        file.write(text)
    temporary.chmod(0o600)
    temporary.replace(path)


def detect_gpus() -> list[dict[str, str]]:
    """Detect available NVIDIA GPUs via nvidia-smi."""
    gpus: list[dict[str, str]] = []
    try:
        res = subprocess.run(
            ['nvidia-smi', '--query-gpu=index,name,memory.total', '--format=csv,noheader,nounits'],
            capture_output=True, text=True, timeout=5
        )
        if res.returncode == 0:
            for line in res.stdout.strip().splitlines():
                parts = [p.strip() for p in line.split(',')]
                if len(parts) >= 3:
                    gpus.append({
                        'index': parts[0],
                        'name': parts[1],
                        'memory_mb': parts[2]
                    })
    except Exception:
        pass
    return gpus


def wait_for_docker(timeout: int = 15) -> bool:
    """Wait until Docker daemon responds to docker info."""
    start = time.time()
    while time.time() - start < timeout:
        try:
            res = subprocess.run(['docker', 'info'], capture_output=True, timeout=3)
            if res.returncode == 0:
                return True
        except Exception:
            pass
        time.sleep(0.5)
    return False


def check_docker_nvidia_support() -> bool:
    """Check if Docker daemon can actually run containers with the NVIDIA GPU driver."""
    # 1. Quick probe with any locally available image if Docker is running
    try:
        img_res = subprocess.run(
            ['docker', 'images', '-q'],
            capture_output=True, text=True, timeout=5
        )
        if img_res.returncode == 0:
            images = [line.strip() for line in img_res.stdout.splitlines() if line.strip()]
            if images:
                probe = subprocess.run(
                    ['docker', 'run', '--rm', '--gpus', 'all', '--entrypoint', 'true', images[0]],
                    capture_output=True, text=True, timeout=10
                )
                if probe.returncode == 0:
                    return True
                err = (probe.stderr or '') + (probe.stdout or '')
                # If docker specifically failed due to missing nvidia driver/toolkit:
                if 'could not select device driver' in err or 'nvidia-container-cli' in err:
                    return False
    except Exception:
        pass

    # 2. Check /etc/docker/daemon.json or CDI configuration
    daemon_file = Path('/etc/docker/daemon.json')
    if daemon_file.is_file():
        try:
            data = json.loads(daemon_file.read_text())
            if 'nvidia' in data.get('runtimes', {}):
                return True
        except Exception:
            pass

    return Path('/etc/cdi/nvidia.yaml').is_file() or Path('/var/run/cdi/nvidia.yaml').is_file()


def setup_nvidia_container_toolkit_auto() -> bool:
    """Attempt automatic configuration or installation of nvidia-container-toolkit."""
    print('\nStarte automatische Einrichtung des NVIDIA Container Toolkits für Docker...')

    # Step 1: If nvidia-ctk is already installed on host, configure docker runtime and restart docker
    if shutil.which('nvidia-ctk'):
        print('-> nvidia-ctk auf diesem System gefunden. Konfiguriere Docker-Laufzeit (/etc/docker/daemon.json)...')
        try:
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            print('-> Starte Docker-Dienst neu...')
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            if check_docker_nvidia_support():
                return True
        except (subprocess.CalledProcessError, FileNotFoundError):
            print('-> Konfiguration mit vorhandenem nvidia-ctk fehlgeschlagen, versuche Paketinstallation...', file=sys.stderr)

    # Step 2: Try installing via package manager
    if shutil.which('apt-get'):
        print('-> Installiere / Aktualisiere nvidia-container-toolkit via apt...')
        try:
            subprocess.run(['sudo', 'apt-get', 'update'], check=False)
            res = subprocess.run(['sudo', 'apt-get', 'install', '-y', 'nvidia-container-toolkit'])
            if res.returncode != 0:
                print('-> Richte offizielles NVIDIA Container Toolkit Apt-Repository ein...')
                keyring = '/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg'
                subprocess.run(
                    f'curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o {keyring}',
                    shell=True, check=True
                )
                list_cmd = (
                    f'curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | '
                    f'sed "s#deb https://#deb [signed-by={keyring}] https://#g" | '
                    f'sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list'
                )
                subprocess.run(list_cmd, shell=True, check=True)
                subprocess.run(['sudo', 'apt-get', 'update'], check=True)
                subprocess.run(['sudo', 'apt-get', 'install', '-y', 'nvidia-container-toolkit'], check=True)

            print('-> Konfiguriere Docker-Laufzeit (nvidia-ctk)...')
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            print('-> Starte Docker-Dienst neu...')
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(f'-> Automatische Installation fehlgeschlagen: {e}', file=sys.stderr)
            return False

    elif shutil.which('dnf'):
        print('-> Installiere nvidia-container-toolkit via dnf...')
        try:
            subprocess.run(['sudo', 'dnf', 'install', '-y', 'nvidia-container-toolkit'], check=True)
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(f'-> Automatische Installation fehlgeschlagen: {e}', file=sys.stderr)
            return False

    elif shutil.which('pacman'):
        print('-> Installiere nvidia-container-toolkit via pacman...')
        try:
            subprocess.run(['sudo', 'pacman', '-S', '--noconfirm', 'nvidia-container-toolkit'], check=True)
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(f'-> Automatische Installation fehlgeschlagen: {e}', file=sys.stderr)
            return False

    return False


def detect_lan_ips() -> list[str]:
    """Detect IPv4 addresses on this PC suitable for LAN communication."""
    ips: list[str] = []
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
            if ip and not ip.startswith('127.'):
                ips.append(ip)
    except Exception:
        pass
    try:
        hostname = socket.gethostname()
        for info in socket.getaddrinfo(hostname, None, socket.AF_INET):
            ip = info[4][0]
            if ip and not ip.startswith(('127.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.')) and ip not in ips:
                ips.append(ip)
    except Exception:
        pass
    return ips


def configure(directory: Path, repository: Path, profile: str = 'lite', port: int = 9379,
              threads: int = 4, device: str = 'cpu', gpu_id: str | None = None) -> tuple[str, str]:
    # Docker exposes the selected host GPU as CUDA device 0 inside the container.
    if device.startswith('cuda') and gpu_id is not None and str(gpu_id).lower() != 'all':
        device = 'cuda'
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    if directory.is_symlink():
        raise ValueError('Use a real configuration directory')
    directory.chmod(0o700)
    key_file = directory / 'api-key.txt'
    if key_file.exists():
        if key_file.is_symlink():
            raise ValueError('Linked key file is not supported')
        key = key_file.read_text().strip()
        if len(key) != 64 or any(c not in '0123456789abcdef' for c in key):
            raise ValueError('Invalid saved API key')
    else:
        key = secrets.token_hex(32)
        private_write(key_file, key + '\n')

    model = 'embeddinggemma-2-full' if profile == 'full' else 'embeddinggemma-2'
    project = 'nextgallery-embedding-' + hashlib.sha256(str(directory).encode()).hexdigest()[:10]

    env_vars: dict[str, str] = {
        'NEXTGALLERY_EMBEDDING_PROFILE': profile,
        'NEXTGALLERY_EMBEDDING_KEY': '${NEXTGALLERY_EMBEDDING_KEY}',
        'NEXTGALLERY_EMBEDDING_THREADS': str(threads),
        'NEXTGALLERY_EMBEDDING_DEVICE': device,
    }

    service: dict[str, object] = {
        'build': {
            'context': str(repository),
            'dockerfile': 'docker/embedding-server/Dockerfile'
        },
        'ports': [f'{port}:9379'],
        'environment': env_vars,
        'volumes': ['models:/data'],
        'restart': 'unless-stopped',
        'read_only': True,
        'tmpfs': ['/tmp'],
        'security_opt': ['no-new-privileges:true'],
        'cap_drop': ['ALL']
    }

    if device.startswith('cuda'):
        vis_device = str(gpu_id) if (gpu_id is not None and str(gpu_id).lower() != 'all') else 'all'
        env_vars['NVIDIA_VISIBLE_DEVICES'] = vis_device
        env_vars['NVIDIA_DRIVER_CAPABILITIES'] = 'compute,utility'

        res_device: dict[str, object] = {
            'driver': 'nvidia',
            'capabilities': ['gpu']
        }
        if gpu_id is not None and str(gpu_id).lower() != 'all':
            res_device['device_ids'] = [str(gpu_id)]
        else:
            res_device['count'] = 'all'

        service['deploy'] = {
            'resources': {
                'reservations': {
                    'devices': [res_device]
                }
            }
        }

    config = {
        'name': project,
        'services': {'embedding': service},
        'volumes': {'models': {}}
    }

    lan_ips = detect_lan_ips()
    primary_ip = lan_ips[0] if lan_ips else 'PC-IP'

    private_write(directory / 'compose.json', json.dumps(config, indent=2) + '\n')
    private_write(directory / '.env', 'NEXTGALLERY_EMBEDDING_KEY=' + key + '\n')
    connection_data = {
        'url': f'http://{primary_ip}:{port}/v1',
        'family': 'gemma2',
        'model': model,
        'api_key': key,
        'profile': profile,
        'device': device,
        'gpu_id': gpu_id if device.startswith('cuda') else None,
        'port': port,
        'available_urls': [f'http://{ip}:{port}/v1' for ip in lan_ips]
    }
    private_write(directory / 'connection.json', json.dumps(connection_data, indent=2) + '\n')
    return key, model


def repair_saved_gpu_device(directory: Path) -> bool:
    """Correct legacy host CUDA ordinals without replacing saved service settings."""
    compose_file = directory / 'compose.json'
    config = json.loads(compose_file.read_text())
    service = config.get('services', {}).get('embedding', {})
    environment = service.get('environment', {})
    device = environment.get('NEXTGALLERY_EMBEDDING_DEVICE', '')
    reservations = service.get('deploy', {}).get('resources', {}).get('reservations', {}).get('devices', [])
    single_gpu = (len(reservations) == 1 and reservations[0].get('driver') == 'nvidia'
                  and len(reservations[0].get('device_ids', [])) == 1)
    if not single_gpu or not device.startswith('cuda:'):
        return False

    connection_file = directory / 'connection.json'
    connection = json.loads(connection_file.read_text()) if connection_file.is_file() else None
    environment['NEXTGALLERY_EMBEDDING_DEVICE'] = 'cuda'
    private_write(compose_file, json.dumps(config, indent=2) + '\n')
    if connection is not None:
        connection['device'] = 'cuda'
        private_write(connection_file, json.dumps(connection, indent=2) + '\n')
    print('GPU-Zuordnung korrigiert: Die ausgewählte Grafikkarte verwendet im Container CUDA-Gerät 0.')
    return True


def print_summary(directory: Path, key: str, model: str, port: int, profile: str,
                  device: str, gpu_info: str | None = None) -> None:
    lan_ips = detect_lan_ips()
    primary_ip = lan_ips[0] if lan_ips else 'PC-IP'
    url = f'http://{primary_ip}:{port}/v1'

    print('\n' + '=' * 80)
    print('  NextGallery · Externer Embedding-Server erfolgreich eingerichtet!')
    print('=' * 80)
    print('\nTrage nun folgende Werte in deiner Nextcloud ein:')
    print('  Pfad: NextGallery -> Einstellungen -> Visuelle Bildsuche · Embeddings\n')

    rows = [
        ('Embedding-Dienst', 'Externer Embedding-Server'),
        ('Modellfamilie', 'EmbeddingGemma 2'),
        ('Modell-ID', model),
        ('API-Adresse des externen Servers', url),
        ('API-Key', key),
    ]

    col1_w = max(len(r[0]) for r in rows)
    col2_w = max(len(r[1]) for r in rows)
    border = '+-' + '-' * col1_w + '-+-' + '-' * col2_w + '-+'
    print(border)
    print(f"| {'Feld in Nextcloud':<{col1_w}} | {'Einzutragender Wert':<{col2_w}} |")
    print(border)
    for k, v in rows:
        print(f"| {k:<{col1_w}} | {v:<{col2_w}} |")
    print(border)

    if lan_ips:
        print('\nErkannte IP-Adressen dieses PCs:')
        for ip in lan_ips:
            print(f'  • http://{ip}:{port}/v1')
        if len(lan_ips) > 1:
            print('  (Wähle die IP-Adresse, unter der dieser Rechner für Nextcloud im LAN erreichbar ist)')

    print('\nHardware-Konfiguration:')
    if device.startswith('cuda'):
        desc = gpu_info or f'GPU {device}'
        print(f'  • Grafikkarte (NVIDIA CUDA): {desc}')
    else:
        print(f'  • CPU-Modus')

    print('\nNächste Schritte in Nextcloud:')
    print('  1. Die Werte oben genau so in den Einstellungen eintragen.')
    print('  2. Unten auf „Speichern“ klicken.')
    print('  3. Auf „Verbindung prüfen“ klicken.')
    print('     (Hinweis: Der erste Modell-Download kann einige Minuten dauern; erst wenn')
    print('      „Bereit für Bilder, Video-Standbilder und Suchanfragen“ gemeldet wird,')
    print('      ist das Modell fertig geladen).')
    print('  4. Die Checkbox „Für Benutzer aktivieren“ setzen und erneut „Speichern“!')

    print('\nNützliche Befehle auf diesem Rechner:')
    print(f'  • Fortschritt / Download-Logs verfolgen:')
    print(f'    docker compose --env-file "{directory}/.env" -f "{directory}/compose.json" logs -f')
    print(f'  • Dienst stoppen:')
    print(f'    python3 scripts/install-embedding-server.py --directory "{directory}" --action stop')
    print(f'  • Dienst starten:')
    print(f'    python3 scripts/install-embedding-server.py --directory "{directory}" --action start')
    print(f'  • Konfigurationsdatei:')
    print(f'    {directory / "connection.json"}')
    print('=' * 80 + '\n')


def prompt_choice(prompt_text: str, options: list[str], default: int = 1) -> int:
    while True:
        try:
            val = input(f'{prompt_text} [{default}]: ').strip()
        except (EOFError, KeyboardInterrupt):
            print('\nAbgebrochen.')
            sys.exit(1)
        if not val:
            return default
        if val.isdigit() and 1 <= int(val) <= len(options):
            return int(val)
        print(f'Bitte eine Zahl zwischen 1 und {len(options)} eingeben.')


def prompt_string(prompt_text: str, default: str) -> str:
    try:
        val = input(f'{prompt_text} [{default}]: ').strip()
    except (EOFError, KeyboardInterrupt):
        print('\nAbgebrochen.')
        sys.exit(1)
    return val if val else default


def prompt_yes_no(prompt_text: str, default_yes: bool = True) -> bool:
    suffix = '[J/n]' if default_yes else '[j/N]'
    while True:
        try:
            val = input(f'{prompt_text} {suffix}: ').strip().lower()
        except (EOFError, KeyboardInterrupt):
            print('\nAbgebrochen.')
            sys.exit(1)
        if not val:
            return default_yes
        if val in ('j', 'ja', 'y', 'yes'):
            return True
        if val in ('n', 'nein', 'no'):
            return False
        print('Bitte mit j (ja) oder n (nein) antworten.')


def interactive_wizard(repository: Path, directory: Path) -> tuple[dict[str, object], str]:
    print('=' * 80)
    print('  NextGallery · Externer Embedding-Server Einrichtungsassistent (Docker)')
    print('=' * 80)
    print('Dieser Assistent führt dich Schritt für Schritt durch die Installation des')
    print('externen KI-Dienstes für NextGallery auf diesem Rechner.\n')

    # Check Docker
    try:
        subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (FileNotFoundError, subprocess.CalledProcessError):
        print('FEHLER: Docker ist nicht installiert oder läuft derzeit nicht.')
        print('Bitte stelle sicher, dass Docker (oder Docker Desktop) gestartet ist.')
        print('Siehe auch: https://docs.docker.com/get-started/get-docker/')
        sys.exit(1)

    # Check if already installed
    if (directory / 'compose.json').is_file():
        print(f'Vorhandene Installation gefunden in: {directory}')
        choice = prompt_choice(
            'Was möchtest du tun?\n'
            '  [1] Neu einrichten / Konfiguration aktualisieren\n'
            '  [2] Dienst starten\n'
            '  [3] Dienst stoppen\n'
            '  [4] Verbindungsdaten für Nextcloud anzeigen\n'
            '  [5] Dienst deinstallieren\n'
            'Deine Wahl',
            ['reconfigure', 'start', 'stop', 'show_info', 'uninstall'],
            default=1
        )
        if choice == 2:
            return {'action': 'start'}, 'start'
        elif choice == 3:
            return {'action': 'stop'}, 'stop'
        elif choice == 4:
            return {'action': 'info'}, 'info'
        elif choice == 5:
            delete_vol = prompt_yes_no('Auch heruntergeladene Modelle löschen?', default_yes=False)
            return {'action': 'uninstall', 'delete_models': delete_vol}, 'uninstall'

    # Step 1: Model Selection
    print('\n' + '-' * 80)
    print('Schritt 1: Modell auswählen')
    print('-' * 80)
    print('  [1] EmbeddingGemma 2 · Original (Full) [Empfohlen]')
    print('      - Modell-ID: embeddinggemma-2-full')
    print('      - Download: ~1.52 GB · Benötigt: ~4-6 GB RAM/VRAM')
    print('      - Höchste semantische Suchgenauigkeit')
    print('      - Unterstützt GPU-Beschleunigung (NVIDIA CUDA) und CPU\n')
    print('  [2] EmbeddingGemma 2 · sparsam (Lite)')
    print('      - Modell-ID: embeddinggemma-2')
    print('      - Download: ~388 MB · Benötigt: ~2 GB RAM')
    print('      - Sehr ressourcenschonend, schneller Download')
    print('      - Reine CPU-Ausführung (LiteRT)')

    model_choice = prompt_choice('Wähle das gewünschte Modell', ['full', 'lite'], default=1)
    profile = 'full' if model_choice == 1 else 'lite'

    # Step 2: Hardware Acceleration (CPU / GPU)
    print('\n' + '-' * 80)
    print('Schritt 2: Hardware-Beschleunigung (CPU oder GPU)')
    print('-' * 80)

    gpus = detect_gpus()
    device = 'cpu'
    gpu_id: str | None = None
    gpu_info_str: str | None = None
    threads = 4

    if gpus:
        print('Erkannte NVIDIA Grafikkarten auf diesem System:')
        gpu_opts: list[str] = []
        for i, g in enumerate(gpus):
            desc = f"GPU {g['index']}: {g['name']} ({g['memory_mb']} MB VRAM)"
            print(f"  [{i + 1}] {desc}")
            gpu_opts.append(f"gpu_{g['index']}")

        all_idx = len(gpus) + 1
        cpu_idx = len(gpus) + 2
        print(f"  [{all_idx}] Alle verfügbaren GPUs verwenden")
        print(f"  [{cpu_idx}] CPU verwenden (keine GPU-Beschleunigung)")
        gpu_opts.extend(['all', 'cpu'])

        dev_choice = prompt_choice('Wähle die Hardware für die Berechnung', gpu_opts, default=1)

        if dev_choice <= len(gpus):
            selected_gpu = gpus[dev_choice - 1]
            gpu_id = selected_gpu['index']
            device = 'cuda'
            gpu_info_str = f"GPU {selected_gpu['index']}: {selected_gpu['name']} ({selected_gpu['memory_mb']} MB VRAM)"
        elif dev_choice == all_idx:
            device = 'cuda'
            gpu_id = 'all'
            gpu_info_str = f"Alle {len(gpus)} GPUs"
        else:
            device = 'cpu'
            gpu_id = None
    else:
        print('Keine NVIDIA-Grafikkarte automatisch via nvidia-smi erkannt.')
        print('  [1] CPU verwenden [Standard]')
        print('  [2] GPU manuell konfigurieren (z. B. NVIDIA Container Toolkit aktiv)')
        no_gpu_choice = prompt_choice('Deine Wahl', ['cpu', 'manual_gpu'], default=1)
        if no_gpu_choice == 2:
            device = 'cuda'
            manual_id = prompt_string('NVIDIA GPU-Index angeben (z. B. 0 oder "all")', '0')
            gpu_id = manual_id
            gpu_info_str = f'Manuelle GPU {gpu_id}'
        else:
            device = 'cpu'
            gpu_id = None

    if device.startswith('cuda'):
        # Proactively check if Docker daemon actually has nvidia runtime registered
        while not check_docker_nvidia_support():
            print('\n' + '!' * 80)
            print('ACHTUNG: NVIDIA GPU(s) erkannt, aber Docker kann noch nicht auf die GPU zugreifen.')
            print('         Das NVIDIA Container Toolkit fehlt oder ist in Docker noch nicht aktiviert.')
            print('         (Docker-Fehler: could not select device driver "nvidia")')
            print('!' * 80 + '\n')
            action = prompt_choice(
                'Wie möchtest du das NVIDIA Container Toolkit einrichten?\n'
                '  [1] Automatisch für Docker einrichten (benötigt sudo-Rechte) [Empfohlen]\n'
                '  [2] Befehle anzeigen, um es selbst manuell in einem Terminal auszuführen\n'
                '  [3] Stattdessen auf CPU ausweichen (funktioniert sofort ohne Toolkit)\n'
                'Deine Wahl',
                ['auto_setup', 'manual_commands', 'fallback_cpu'],
                default=1
            )
            if action == 1:
                success = setup_nvidia_container_toolkit_auto()
                if success:
                    print('\n✅ NVIDIA Container Toolkit erfolgreich in Docker aktiviert!\n')
                    break
                else:
                    print('\nAutomatische Einrichtung konnte nicht abgeschlossen werden.', file=sys.stderr)
                    if not prompt_yes_no('Erneut versuchen oder manuell konfigurieren?', default_yes=True):
                        device = 'cpu'
                        gpu_id = None
                        gpu_info_str = None
                        print('\nHardware wurde auf CPU umgestellt.')
                        break
            elif action == 2:
                print('\nFühre in einem zweiten Terminal folgende Befehle aus:')
                print('  sudo nvidia-ctk runtime configure --runtime=docker')
                print('  sudo systemctl restart docker')
                print('\nFalls das Paket noch nicht installiert ist (Ubuntu/Debian):')
                print('  sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit')
                print('  sudo nvidia-ctk runtime configure --runtime=docker')
                print('  sudo systemctl restart docker\n')
                prompt_string('Drücke Enter, sobald du die Befehle ausgeführt hast (oder "c" für CPU)', '')
                wait_for_docker()
                if check_docker_nvidia_support():
                    print('\n✅ NVIDIA-Laufzeit in Docker erkannt! Fahre mit GPU fort...\n')
                    break
                else:
                    print('\nNVIDIA-Laufzeit in Docker noch nicht aktiv.')
                    if not prompt_yes_no('Erneut prüfen?', default_yes=True):
                        device = 'cpu'
                        gpu_id = None
                        gpu_info_str = None
                        print('\nHardware wurde auf CPU umgestellt.')
                        break
            elif action == 3:
                device = 'cpu'
                gpu_id = None
                gpu_info_str = None
                print('\nHardware wurde auf CPU umgestellt.')
                break

        if device.startswith('cuda') and profile == 'lite':
            print('\nHinweis: Das sparsame Lite-Modell nutzt eine reine CPU-Laufzeit.')
            print('Für echte GPU-Beschleunigung mit CUDA wird das Originalmodell benötigt.')
            if prompt_yes_no('Möchtest du zum Originalmodell (full) wechseln?', default_yes=True):
                profile = 'full'
    else:
        threads_str = prompt_string('Anzahl CPU-Threads für die Berechnung [1-64]', '4')
        try:
            threads = max(1, min(64, int(threads_str)))
        except ValueError:
            threads = 4

    # Step 3: Port and directory
    print('\n' + '-' * 80)
    print('Schritt 3: Netzwerk-Port und Verzeichnis')
    print('-' * 80)
    port_str = prompt_string('Port für den Embedding-Dienst', '9379')
    try:
        port = int(port_str)
        if not (1 <= port <= 65535):
            port = 9379
    except ValueError:
        port = 9379

    dir_str = prompt_string('Konfigurationsverzeichnis', str(directory))
    target_dir = Path(dir_str).expanduser().resolve()

    # Confirmation
    print('\n' + '-' * 80)
    print('Zusammenfassung:')
    print('-' * 80)
    model_name = 'EmbeddingGemma 2 · Original (embeddinggemma-2-full)' if profile == 'full' else 'EmbeddingGemma 2 · sparsam (embeddinggemma-2)'
    hw_name = gpu_info_str if device.startswith('cuda') else f'CPU ({threads} Threads)'
    print(f'  • Modell:         {model_name}')
    print(f'  • Hardware:       {hw_name}')
    print(f'  • Port:           {port}')
    print(f'  • Verzeichnis:    {target_dir}')

    if not prompt_yes_no('\nJetzt einrichten und Dienst starten?', default_yes=True):
        print('Einrichtung abgebrochen.')
        sys.exit(0)

    params: dict[str, object] = {
        'directory': target_dir,
        'profile': profile,
        'port': port,
        'threads': threads,
        'device': device,
        'gpu_id': gpu_id,
        'gpu_info': gpu_info_str,
        'action': 'install'
    }
    return params, 'install'


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Install and start the external NextGallery embedding service using Docker.')
    parser.add_argument('--directory', type=Path, default=Path.home() / '.nextgallery-embedding-server',
                        help='Private configuration directory on this host.')
    parser.add_argument('--profile', choices=['lite', 'full'], default='lite',
                        help='Gemma model profile (lite: 440M LiteRT CPU, full: Original PyTorch float32/GPU).')
    parser.add_argument('--port', type=int, default=9379,
                        help='Port to expose the service on.')
    parser.add_argument('--threads', type=int, default=4,
                        help='CPU thread count when running on CPU.')
    parser.add_argument('--device', choices=['cpu', 'cuda'], default='cpu',
                        help='Compute device to run inference on.')
    parser.add_argument('--gpu-id', default=None,
                        help='Specific GPU index to use (e.g. 0, 1) or "all".')
    parser.add_argument('--action', choices=['install', 'start', 'stop', 'uninstall', 'info'], default='install',
                        help='Action to perform.')
    parser.add_argument('--delete-models', action='store_true',
                        help='Also remove downloaded models when uninstalling.')
    parser.add_argument('--prepare-only', action='store_true',
                        help='Write configuration only; do not run Docker or download models.')
    parser.add_argument('--interactive', action='store_true',
                        help='Run the interactive step-by-step setup wizard.')

    args = parser.parse_args(argv)
    repository = Path(__file__).resolve().parents[1]
    directory = args.directory.expanduser()

    # Determine if interactive wizard should run:
    # Triggered if explicitly requested, or if executed in terminal without CLI action/profile flags
    is_interactive = args.interactive or (
        argv is None and sys.stdin.isatty() and len(sys.argv) == 1
    )

    gpu_info_label: str | None = None
    if is_interactive:
        wizard_params, selected_action = interactive_wizard(repository, directory)
        args.action = selected_action
        if selected_action == 'install':
            args.profile = wizard_params['profile']
            args.port = wizard_params['port']
            args.threads = wizard_params['threads']
            args.device = wizard_params['device']
            args.gpu_id = wizard_params['gpu_id']
            gpu_info_label = wizard_params.get('gpu_info')
            directory = wizard_params['directory']
        elif selected_action == 'uninstall':
            args.delete_models = wizard_params.get('delete_models', False)

    if not 1 <= args.port <= 65535 or not 1 <= args.threads <= 64:
        parser.error('Invalid port or CPU thread count')

    if directory.is_symlink():
        parser.error('Use a real configuration directory')
    directory = directory.resolve()

    if args.action == 'info':
        conn_file = directory / 'connection.json'
        if not conn_file.is_file():
            print(f'Keine Konfiguration gefunden in: {directory}', file=sys.stderr)
            return 1
        data = json.loads(conn_file.read_text())
        print_summary(
            directory=directory,
            key=data.get('api_key', ''),
            model=data.get('model', ''),
            port=data.get('port', args.port),
            profile=data.get('profile', 'lite'),
            device=data.get('device', 'cpu'),
            gpu_info=f"GPU {data.get('gpu_id')}" if data.get('gpu_id') is not None else None
        )
        return 0

    if args.action != 'install' and not (directory / 'compose.json').is_file():
        parser.error('Service has not been configured yet')

    if args.delete_models and args.action != 'uninstall':
        parser.error('--delete-models only applies to uninstall')

    key, model = '', ''
    if args.action == 'install':
        key, model = configure(
            directory=directory,
            repository=repository,
            profile=args.profile,
            port=args.port,
            threads=args.threads,
            device=args.device,
            gpu_id=args.gpu_id
        )
    elif args.action == 'start':
        repair_saved_gpu_device(directory)
        saved = json.loads((directory / 'compose.json').read_text())['services']['embedding']
        environment = saved['environment']
        args.device = environment.get('NEXTGALLERY_EMBEDDING_DEVICE', 'cpu')
        args.profile = environment.get('NEXTGALLERY_EMBEDDING_PROFILE', 'lite')
        args.threads = int(environment.get('NEXTGALLERY_EMBEDDING_THREADS', '4'))
        args.gpu_id = environment.get('NVIDIA_VISIBLE_DEVICES') if args.device.startswith('cuda') else None
        connection_file = directory / 'connection.json'
        if connection_file.is_file():
            args.port = json.loads(connection_file.read_text()).get('port', args.port)

    if args.prepare_only:
        print('Configuration prepared. No model downloaded.')
        return 0

    command = ['docker', 'compose', '--env-file', str(directory / '.env'), '-f', str(directory / 'compose.json')]
    try:
        subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (FileNotFoundError, subprocess.CalledProcessError):
        print('FEHLER: Docker ist nicht installiert oder der Docker-Dienst läuft nicht.', file=sys.stderr)
        return 1

    action_args = (
        ['up', '-d', '--build'] if args.action == 'install'
        else ['up', '-d'] if args.action == 'start'
        else ['stop'] if args.action == 'stop'
        else ['down'] + (['--volumes'] if args.delete_models else [])
    )

    while True:
        result = subprocess.run(command + action_args)
        code = getattr(result, 'returncode', 0)
        if isinstance(code, int) and code != 0:
            if args.device.startswith('cuda') and (args.action in ('install', 'start')):
                if sys.stdin.isatty():
                    print('\n' + '!' * 80, file=sys.stderr)
                    print('ACHTUNG: Docker konnte die NVIDIA-Grafikkarte nicht einbinden!', file=sys.stderr)
                    print('Fehler: could not select device driver "nvidia" with capabilities: [[gpu]]', file=sys.stderr)
                    print('Das NVIDIA Container Toolkit ist für Docker noch nicht registriert.', file=sys.stderr)
                    print('!' * 80 + '\n', file=sys.stderr)

                    fix_choice = prompt_choice(
                        'Wie möchtest du das Problem beheben?\n'
                        '  [1] NVIDIA Container Toolkit jetzt automatisch für Docker einrichten (benötigt sudo) und erneut versuchen [Empfohlen]\n'
                        '  [2] Befehle anzeigen, um sie in einem zweiten Terminal auszuführen, und danach erneut versuchen\n'
                        '  [3] Auf CPU-Berechnung umstellen und Dienst sofort starten\n'
                        '  [4] Abbrechen\n'
                        'Deine Wahl',
                        ['auto_fix', 'manual_commands', 'switch_cpu', 'cancel'],
                        default=1
                    )

                    if fix_choice == 1:
                        success = setup_nvidia_container_toolkit_auto()
                        if success:
                            print('\n✅ NVIDIA Container Toolkit erfolgreich eingerichtet!')
                            print('Starte Dienst erneut mit GPU...\n')
                            continue
                        else:
                            print('\nAutomatische Einrichtung nicht erfolgreich.', file=sys.stderr)
                            if prompt_yes_no('Stattdessen auf CPU-Modus umstellen und starten?', default_yes=True):
                                fix_choice = 3
                            else:
                                return code

                    if fix_choice == 2:
                        print('\nFühre in einem zweiten Terminal folgende Befehle aus:')
                        print('  sudo nvidia-ctk runtime configure --runtime=docker')
                        print('  sudo systemctl restart docker')
                        print('\nFalls das Paket noch nicht installiert ist (Ubuntu/Debian):')
                        print('  sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit')
                        print('  sudo nvidia-ctk runtime configure --runtime=docker')
                        print('  sudo systemctl restart docker\n')
                        prompt_string('Drücke Enter, sobald du die Befehle ausgeführt hast (oder "c" für Abbrechen)', '')
                        wait_for_docker()
                        print('Starte Dienst erneut...\n')
                        continue

                    if fix_choice == 3:
                        print('\nStelle Konfiguration auf CPU-Berechnung um...')
                        args.device = 'cpu'
                        args.gpu_id = None
                        gpu_info_label = None
                        key, model = configure(
                            directory=directory,
                            repository=repository,
                            profile=args.profile,
                            port=args.port,
                            threads=args.threads,
                            device='cpu',
                            gpu_id=None
                        )
                        print('Starte Dienst im CPU-Modus...\n')
                        continue

                    if fix_choice == 4:
                        print('Einrichtung abgebrochen.')
                        return code

                else:
                    print('\n' + '=' * 80, file=sys.stderr)
                    print('HINWEIS ZUR DOCKER GPU-FEHLERMELDUNG:', file=sys.stderr)
                    print('Docker konnte die NVIDIA-Grafikkarte nicht einbinden.', file=sys.stderr)
                    print('Fehler: could not select device driver "nvidia" with capabilities: [[gpu]]', file=sys.stderr)
                    print('\nUrsache: In Docker ist das nvidia-container-toolkit noch nicht registriert.', file=sys.stderr)
                    print('\nSchnell-Lösung (auf Ubuntu/Debian):', file=sys.stderr)
                    print('  1. Toolkit in Docker registrieren und Dienst neu starten:', file=sys.stderr)
                    print('     sudo nvidia-ctk runtime configure --runtime=docker', file=sys.stderr)
                    print('     sudo systemctl restart docker', file=sys.stderr)
                    print('\n  2. Falls das Paket noch nicht auf dem System ist:', file=sys.stderr)
                    print('     sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit', file=sys.stderr)
                    print('     sudo nvidia-ctk runtime configure --runtime=docker', file=sys.stderr)
                    print('     sudo systemctl restart docker', file=sys.stderr)
                    print('\nDanach dieses Installationsskript einfach erneut ausführen:', file=sys.stderr)
                    print('  ./scripts/install-embedding-server.sh', file=sys.stderr)
                    print('=' * 80 + '\n', file=sys.stderr)
            return code
        break

    if args.action == 'install':
        print_summary(
            directory=directory,
            key=key,
            model=model,
            port=args.port,
            profile=args.profile,
            device=args.device,
            gpu_info=gpu_info_label or (f"GPU {args.gpu_id}" if args.gpu_id is not None else None)
        )
    elif args.action == 'start':
        conn_file = directory / 'connection.json'
        if conn_file.is_file():
            data = json.loads(conn_file.read_text())
            print_summary(
                directory=directory,
                key=data.get('api_key', ''),
                model=data.get('model', ''),
                port=data.get('port', args.port),
                profile=data.get('profile', 'lite'),
                device=data.get('device', 'cpu'),
                gpu_info=f"GPU {data.get('gpu_id')}" if data.get('gpu_id') is not None else None
            )
        else:
            print('Dienst gestartet.')
    else:
        print('Service ' + ('stopped.' if args.action == 'stop' else 'uninstalled.'))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
