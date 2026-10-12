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
import importlib.util
import shlex

_check_spec = importlib.util.spec_from_file_location('embedding_service_check', Path(__file__).with_name('embedding_service_check.py'))
service_check = importlib.util.module_from_spec(_check_spec)
_check_spec.loader.exec_module(service_check)


def load_translations() -> dict[str, dict[str, str]]:
    i18n_path = Path(__file__).resolve().parent / 'embedding-installer-i18n.json'
    if i18n_path.is_file():
        try:
            return json.loads(i18n_path.read_text(encoding='utf-8'))
        except Exception:
            return {}
    return {}


TRANSLATIONS: dict[str, dict[str, str]] = load_translations()
CURRENT_LANGUAGE = 'en'


def set_language(lang: str) -> None:
    global CURRENT_LANGUAGE
    CURRENT_LANGUAGE = 'de' if lang == 'de' else 'en'


def get_language() -> str:
    return CURRENT_LANGUAGE


def t(text: str, **kwargs: object) -> str:
    translated = TRANSLATIONS.get(CURRENT_LANGUAGE, {}).get(text, text)
    if kwargs:
        try:
            return translated.format(**kwargs)
        except Exception:
            return translated
    return translated


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
    print('\n' + t('Starting automatic setup of NVIDIA Container Toolkit for Docker...'))

    # Step 1: If nvidia-ctk is already installed on host, configure docker runtime and restart docker
    if shutil.which('nvidia-ctk'):
        print(t('-> nvidia-ctk found on this system. Configuring Docker runtime (/etc/docker/daemon.json)...'))
        try:
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            print(t('-> Restarting Docker service...'))
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            if check_docker_nvidia_support():
                return True
        except (subprocess.CalledProcessError, FileNotFoundError):
            print(t('-> Configuration with existing nvidia-ctk failed, trying package installation...'), file=sys.stderr)

    # Step 2: Try installing via package manager
    if shutil.which('apt-get'):
        print(t('-> Installing / updating nvidia-container-toolkit via apt...'))
        try:
            subprocess.run(['sudo', 'apt-get', 'update'], check=False)
            res = subprocess.run(['sudo', 'apt-get', 'install', '-y', 'nvidia-container-toolkit'])
            if res.returncode != 0:
                print(t('-> Setting up official NVIDIA Container Toolkit apt repository...'))
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

            print(t('-> Configuring Docker runtime (nvidia-ctk)...'))
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            print(t('-> Restarting Docker service...'))
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(t('-> Automatic installation failed: {err}', err=e), file=sys.stderr)
            return False

    elif shutil.which('dnf'):
        print(t('-> Installing nvidia-container-toolkit via dnf...'))
        try:
            subprocess.run(['sudo', 'dnf', 'install', '-y', 'nvidia-container-toolkit'], check=True)
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(t('-> Automatic installation failed: {err}', err=e), file=sys.stderr)
            return False

    elif shutil.which('pacman'):
        print(t('-> Installing nvidia-container-toolkit via pacman...'))
        try:
            subprocess.run(['sudo', 'pacman', '-S', '--noconfirm', 'nvidia-container-toolkit'], check=True)
            subprocess.run(['sudo', 'nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], check=True)
            subprocess.run(['sudo', 'systemctl', 'restart', 'docker'], check=True)
            wait_for_docker()
            return check_docker_nvidia_support()
        except Exception as e:
            print(t('-> Automatic installation failed: {err}', err=e), file=sys.stderr)
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
    if profile not in ('lite', 'full'):
        raise ValueError('Managed Docker setup supports lite/full only; use custom setup for another service')
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
    print(t('GPU mapping corrected: The selected graphics card uses CUDA device 0 inside the container.'))
    return True


def print_summary(directory: Path, key: str, model: str, port: int, profile: str,
                  device: str, gpu_info: str | None = None) -> None:
    lan_ips = detect_lan_ips()
    primary_ip = lan_ips[0] if lan_ips else 'PC-IP'
    url = f'http://{primary_ip}:{port}/v1'

    print('\n' + '=' * 80)
    print(f"  {t('NextGallery · External Embedding Server Configured')}")
    print('=' * 80)
    print('\n' + t('Now enter the following values in your Nextcloud:\n  Path: NextGallery -> Settings -> Visual Image Search · Embeddings\n'))

    rows = [
        (t('Embedding Service'), t('External Embedding Server')),
        (t('Model Family'), 'EmbeddingGemma 2'),
        (t('Model ID'), model),
        (t('API Address of external server'), url),
        (t('API Key'), key),
    ]

    col1_w = max(len(r[0]) for r in rows)
    col2_w = max(len(r[1]) for r in rows)
    border = '+-' + '-' * col1_w + '-+-' + '-' * col2_w + '-+'
    print(border)
    print(f"| {t('Field in Nextcloud'):<{col1_w}} | {t('Value to enter'):<{col2_w}} |")
    print(border)
    for k, v in rows:
        print(f"| {k:<{col1_w}} | {v:<{col2_w}} |")
    print(border)

    if len(lan_ips) > 1:
        print('\n' + t('(Select the IP address under which this computer is accessible to Nextcloud on the LAN)'))
        print('  ' + ', '.join(lan_ips))

    print('\n' + t('The model may still be downloading or loading. Check readiness:'))
    check_cmd = 'python3 scripts/install-embedding-server.py'
    if directory != Path.home() / '.nextgallery-embedding-server':
        check_cmd += f' --directory {shlex.quote(str(directory))}'
    print(f'  {check_cmd} --action check')
    print('\n' + t('Next steps in Nextcloud:'))
    print(t('  1. Enter the values above in settings exactly as shown.'))
    print(t('  2. Click "Test connection" before saving and check vector compatibility.'))
    print(t('  3. Pause affected embedding runs and wait for the current file to finish.'))
    print(t('  4. Enable for users and save. Proven compatible vectors are kept.'))
    print('=' * 80 + '\n')


def choose_language() -> str:
    print('Language / Sprache:')
    print('  [1] English (default)')
    print('  [2] Deutsch')
    return 'en' if prompt_choice('Language / Sprache', ['English', 'Deutsch'], default=1) == 1 else 'de'


def prompt_choice(prompt_text: str, options: list[str], default: int = 1) -> int:
    while True:
        try:
            val = input(f'{prompt_text} [{default}]: ').strip()
        except (EOFError, KeyboardInterrupt):
            print(t('Cancelled.'))
            sys.exit(1)
        if not val:
            return default
        if val.isdigit() and 1 <= int(val) <= len(options):
            return int(val)
        print(t('Please enter a number between 1 and {count}.', count=len(options)))


def prompt_string(prompt_text: str, default: str) -> str:
    try:
        val = input(f'{prompt_text} [{default}]: ').strip()
    except (EOFError, KeyboardInterrupt):
        print(t('Cancelled.'))
        sys.exit(1)
    return val if val else default


def prompt_yes_no(prompt_text: str, default_yes: bool = True) -> bool:
    is_de = CURRENT_LANGUAGE == 'de'
    suffix = ('[J/n]' if default_yes else '[j/N]') if is_de else ('[Y/n]' if default_yes else '[y/N]')
    while True:
        try:
            val = input(f'{prompt_text} {suffix}: ').strip().lower()
        except (EOFError, KeyboardInterrupt):
            print(t('Cancelled.'))
            sys.exit(1)
        if not val:
            return default_yes
        if val in ('j', 'ja', 'y', 'yes'):
            return True
        if val in ('n', 'nein', 'no'):
            return False
        print(t('Please answer with y (yes) or n (no).'))


def interactive_wizard(repository: Path, directory: Path, language: str = 'ask') -> tuple[dict[str, object], str]:
    if language == 'ask':
        set_language(choose_language())
    elif language in ('de', 'en'):
        set_language(language)

    print('=' * 80)
    print(f"  {t('NextGallery · External Embedding Server Setup Wizard (Docker)')}")
    print('=' * 80)
    print(t('This wizard guides you step by step through installing the\nexternal AI service for NextGallery on this machine.\n'))

    # Check if already installed
    if (directory / 'compose.json').is_file():
        print(t('Existing installation found in: {directory}', directory=str(directory)))
        choice = prompt_choice(
            t('What would you like to do?\n  [1] Set up again / update configuration\n  [2] Start service\n  [3] Stop service\n  [4] Show connection info for Nextcloud\n  [5] Uninstall service\n  [6] Check readiness (image and text)\n  [7] Custom model / check another service\nYour choice'),
            ['reconfigure', 'start', 'stop', 'show_info', 'uninstall', 'check', 'custom'],
            default=1
        )
        if choice == 2:
            return {'action': 'start'}, 'start'
        elif choice == 3:
            return {'action': 'stop'}, 'stop'
        elif choice == 4:
            return {'action': 'info'}, 'info'
        elif choice == 5:
            delete_vol = prompt_yes_no(t('Also delete downloaded models?'), default_yes=False)
            return {'action': 'uninstall', 'delete_models': delete_vol}, 'uninstall'
        elif choice == 6:
            return {}, 'check'
        elif choice == 7:
            return {}, 'custom'

    # Step 1: Model Selection
    print('\n' + '-' * 80)
    print(t('Step 1: Select Model'))
    print('-' * 80)
    print(t('  [1] Gemma 2 · Original (recommended) · CPU / NVIDIA GPU · 1.52 GB download'))
    print(t('  [2] Gemma 2 · Lite · CPU · 388 MB download'))
    print(t('  [3] Custom model / connect an existing service'))
    model_choice = prompt_choice(t('Select the desired model'), ['full', 'lite', 'custom'], default=1)
    if model_choice == 3:
        return {}, 'custom'
    profile = 'full' if model_choice == 1 else 'lite'

    try:
        subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (FileNotFoundError, subprocess.CalledProcessError):
        print(t('ERROR: Docker is not installed or currently not running.'))
        print(t('Please make sure Docker (or Docker Desktop) is running.'))
        print(t('See also: https://docs.docker.com/get-started/get-docker/'))
        sys.exit(1)

    # Step 2: Hardware Acceleration (CPU / GPU)
    print('\n' + '-' * 80)
    print(t('Step 2: Hardware Acceleration (CPU or GPU)'))
    print('-' * 80)

    gpus = detect_gpus()
    device = 'cpu'
    gpu_id: str | None = None
    gpu_info_str: str | None = None
    threads = 4

    if gpus:
        print(t('Detected NVIDIA graphics cards on this system:'))
        gpu_opts: list[str] = []
        for i, g in enumerate(gpus):
            desc = f"GPU {g['index']}: {g['name']} ({g['memory_mb']} MB VRAM)"
            print(f"  [{i + 1}] {desc}")
            gpu_opts.append(f"gpu_{g['index']}")

        all_idx = len(gpus) + 1
        cpu_idx = len(gpus) + 2
        print(f"  [{all_idx}] " + t('  [{idx}] Use all available GPUs', idx=all_idx).strip())
        print(f"  [{cpu_idx}] " + t('  [{idx}] Use CPU (no GPU acceleration)', idx=cpu_idx).strip())
        gpu_opts.extend(['all', 'cpu'])

        dev_choice = prompt_choice(t('Select hardware for computation'), gpu_opts, default=1)

        if dev_choice <= len(gpus):
            selected_gpu = gpus[dev_choice - 1]
            gpu_id = selected_gpu['index']
            device = 'cuda'
            gpu_info_str = f"GPU {selected_gpu['index']}: {selected_gpu['name']} ({selected_gpu['memory_mb']} MB VRAM)"
        elif dev_choice == all_idx:
            device = 'cuda'
            gpu_id = 'all'
            gpu_info_str = t('All {count} GPUs', count=len(gpus))
        else:
            device = 'cpu'
            gpu_id = None
    else:
        print(t('No NVIDIA graphics card automatically detected via nvidia-smi.\n  [1] Use CPU [Default]\n  [2] Configure GPU manually (e.g. NVIDIA Container Toolkit active)'))
        no_gpu_choice = prompt_choice(t('Your choice'), ['cpu', 'manual_gpu'], default=1)
        if no_gpu_choice == 2:
            device = 'cuda'
            manual_id = prompt_string(t('Specify NVIDIA GPU index (e.g. 0 or "all")'), '0')
            gpu_id = manual_id
            gpu_info_str = t('Manual GPU {gpu_id}', gpu_id=gpu_id)
        else:
            device = 'cpu'
            gpu_id = None

    if device.startswith('cuda'):
        # Proactively check if Docker daemon actually has nvidia runtime registered
        while not check_docker_nvidia_support():
            print('\n' + '!' * 80)
            print(t('WARNING: NVIDIA GPU(s) detected, but Docker cannot access the GPU yet.\n         The NVIDIA Container Toolkit is missing or not yet activated in Docker.\n         (Docker error: could not select device driver "nvidia")'))
            print('!' * 80 + '\n')
            action = prompt_choice(
                t('How would you like to set up the NVIDIA Container Toolkit?\n  [1] Set up automatically for Docker (requires sudo privileges) [Recommended]\n  [2] Show commands to run manually in another terminal\n  [3] Fall back to CPU instead (works immediately without toolkit)\nYour choice'),
                ['auto_setup', 'manual_commands', 'fallback_cpu'],
                default=1
            )
            if action == 1:
                success = setup_nvidia_container_toolkit_auto()
                if success:
                    print('\n' + t('✅ NVIDIA Container Toolkit successfully activated in Docker!') + '\n')
                    break
                else:
                    print('\n' + t('Automatic setup could not be completed.'), file=sys.stderr)
                    if not prompt_yes_no(t('Try again or configure manually?'), default_yes=True):
                        device = 'cpu'
                        gpu_id = None
                        gpu_info_str = None
                        print('\n' + t('Hardware switched to CPU.'))
                        break
            elif action == 2:
                print('\n' + t('Run the following commands in a second terminal:\n  sudo nvidia-ctk runtime configure --runtime=docker\n  sudo systemctl restart docker\n\nIf the package is not installed yet (Ubuntu/Debian):\n  sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit\n  sudo nvidia-ctk runtime configure --runtime=docker\n  sudo systemctl restart docker\n'))
                prompt_string(t('Press Enter once you have executed the commands (or "c" for CPU)'), '')
                wait_for_docker()
                if check_docker_nvidia_support():
                    print('\n' + t('✅ NVIDIA runtime detected in Docker! Proceeding with GPU...\n'))
                    break
                else:
                    print('\n' + t('NVIDIA runtime not yet active in Docker.'))
                    if not prompt_yes_no(t('Check again?'), default_yes=True):
                        device = 'cpu'
                        gpu_id = None
                        gpu_info_str = None
                        print('\n' + t('Hardware switched to CPU.'))
                        break
            elif action == 3:
                device = 'cpu'
                gpu_id = None
                gpu_info_str = None
                print('\n' + t('Hardware switched to CPU.'))
                break

        if device.startswith('cuda') and profile == 'lite':
            print('\n' + t('Note: The resource-saving Lite model uses pure CPU runtime.\nFor real GPU acceleration with CUDA, the original model is required.'))
            if prompt_yes_no(t('Would you like to switch to the original model (full)?'), default_yes=True):
                profile = 'full'
            else:
                device = 'cpu'
                gpu_id = None
                gpu_info_str = None
    else:
        threads_str = prompt_string(t('Number of CPU threads for computation [1-64]'), '4')
        try:
            threads = max(1, min(64, int(threads_str)))
        except ValueError:
            threads = 4

    # Step 3: Port and directory
    print('\n' + '-' * 80)
    print(t('Step 3: Network Port and Directory'))
    print('-' * 80)
    port_str = prompt_string(t('Port for the embedding service'), '9379')
    try:
        port = int(port_str)
        if not (1 <= port <= 65535):
            port = 9379
    except ValueError:
        port = 9379

    dir_str = prompt_string(t('Configuration directory'), str(directory))
    target_dir = Path(dir_str).expanduser().resolve()

    # Confirmation
    print('\n' + '-' * 80)
    print(t('Summary:'))
    print('-' * 80)
    model_name = 'EmbeddingGemma 2 · Original (embeddinggemma-2-full)' if profile == 'full' else 'EmbeddingGemma 2 · sparsam (embeddinggemma-2)' if CURRENT_LANGUAGE == 'de' else 'EmbeddingGemma 2 · Resource-saving (embeddinggemma-2)'
    hw_name = gpu_info_str if device.startswith('cuda') else f"CPU ({threads} Threads)" if CURRENT_LANGUAGE == 'de' else f"CPU ({threads} threads)"
    print(f"  • {t('  • Model:         {model}', model=model_name).strip()}")
    print(f"  • {t('  • Hardware:      {hw}', hw=hw_name).strip()}")
    print(f"  • {t('  • Port:          {port}', port=port).strip()}")
    print(f"  • {t('  • Directory:     {dir}', dir=target_dir).strip()}")

    if not prompt_yes_no('\n' + t('Set up now and start service?'), default_yes=True):
        print(t('Setup cancelled.'))
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
    raw_args = argv if argv is not None else sys.argv[1:]
    early_lang = 'en'
    for i, a in enumerate(raw_args):
        if a == '--language' and i + 1 < len(raw_args):
            early_lang = raw_args[i + 1]
        elif a.startswith('--language='):
            early_lang = a.split('=', 1)[1]
    if early_lang in ('de', 'en'):
        set_language(early_lang)
    else:
        set_language('en')

    parser = argparse.ArgumentParser(description=t('Install and start the external NextGallery embedding service using Docker.'))
    parser.add_argument('--directory', type=Path, default=Path.home() / '.nextgallery-embedding-server',
                        help=t('Private configuration directory on this host.'))
    parser.add_argument('--profile', choices=['lite', 'full', 'custom'], default='lite',
                        help=t('Model setup (lite: Gemma LiteRT CPU, full: Gemma CPU/GPU, custom: existing image/text service).'))
    parser.add_argument('--port', type=int, default=9379,
                        help=t('Port to expose the service on.'))
    parser.add_argument('--threads', type=int, default=4,
                        help=t('CPU thread count when running on CPU.'))
    parser.add_argument('--device', choices=['cpu', 'cuda'], default='cpu',
                        help=t('Compute device to run inference on.'))
    parser.add_argument('--gpu-id', default=None,
                        help=t('Specific GPU index to use (e.g. 0, 1) or "all".'))
    parser.add_argument('--action', choices=['install', 'start', 'stop', 'uninstall', 'info', 'check'], default='install',
                        help=t('Action to perform.'))
    parser.add_argument('--delete-models', action='store_true',
                        help=t('Also remove downloaded models when uninstalling.'))
    parser.add_argument('--prepare-only', action='store_true',
                        help=t('Write configuration only; do not run Docker or download models.'))
    parser.add_argument('--interactive', action='store_true',
                        help=t('Run the interactive step-by-step setup wizard.'))
    parser.add_argument('--language', choices=['de', 'en', 'ask'], default='ask',
                        help=t('Installer language (de, en, ask).'))
    parser.add_argument('--url', help=t('Existing service API address for custom setup or checking.'))
    parser.add_argument('--model', help=t('Exact model ID of an existing service.'))
    parser.add_argument('--api-key-file', type=Path, help=t('Private file containing the existing service API key; never put the key on the command line.'))

    args = parser.parse_args(argv)
    repository = Path(__file__).resolve().parents[1]
    directory = args.directory.expanduser()

    # Determine if interactive wizard should run:
    # Triggered if explicitly requested, or if executed in terminal without CLI action/profile flags
    wizard_args=[]
    index=0
    while index<len(raw_args):
        option=raw_args[index]
        if option=='--language':index+=2
        elif option.startswith('--language='):index+=1
        else:wizard_args.append(option);index+=1
    is_interactive = args.interactive or (argv is None and sys.stdin.isatty() and not wizard_args)

    if not is_interactive:
        if args.language=='ask' and args.profile=='custom' and args.url is None and sys.stdin.isatty():
            args.language=choose_language()
        if args.language == 'ask':
            args.language = 'en'
        set_language(args.language)

    gpu_info_label: str | None = None
    if is_interactive:
        wizard_params, selected_action = interactive_wizard(repository, directory, language=args.language)
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
        elif selected_action == 'custom':
            return service_check.custom_setup(get_language())

    if not 1 <= args.port <= 65535 or not 1 <= args.threads <= 64:
        parser.error(t('Invalid port or CPU thread count'))

    if directory.is_symlink():
        parser.error(t('Use a real configuration directory'))
    directory = directory.resolve()

    if args.delete_models and args.action != 'uninstall':
        parser.error(t('--delete-models only applies to uninstall'))
    secret=None
    if args.api_key_file is not None:
        try:secret=args.api_key_file.expanduser().read_text().strip()
        except OSError:parser.error(t('Cannot read API key file.'))
    if args.action=='check':
        return service_check.run_check(directory,get_language(),args.url,args.model,secret)
    if args.profile=='custom':
        if args.action!='install' or args.prepare_only:
            parser.error(t('Custom setup checks an existing service; use --action check or --profile custom.'))
        return service_check.custom_setup(get_language(),args.url,args.model,secret,interactive=sys.stdin.isatty() and args.url is None)
    if args.url is not None or args.model is not None or args.api_key_file is not None:
        parser.error(t('Existing service options only apply to custom setup or --action check.'))
    if args.action=='install' and args.profile=='lite' and args.device!='cpu':
        parser.error(t('Lite runs on CPU only. Choose full for CUDA or --device cpu.'))

    if args.action == 'info':
        conn_file = directory / 'connection.json'
        if not conn_file.is_file():
            print(t('No configuration found in: {directory}', directory=str(directory)), file=sys.stderr)
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
        parser.error(t('Service has not been configured yet'))

    if args.delete_models and args.action != 'uninstall':
        parser.error(t('--delete-models only applies to uninstall'))

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
        print(t('Configuration prepared. No model downloaded.'))
        return 0

    command = ['docker', 'compose', '--env-file', str(directory / '.env'), '-f', str(directory / 'compose.json')]
    try:
        subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (FileNotFoundError, subprocess.CalledProcessError):
        print(t('ERROR: Docker is not installed or Docker service is not running.'), file=sys.stderr)
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
                    print(t('WARNING: Docker could not bind the NVIDIA graphics card!'), file=sys.stderr)
                    print(t('Error: could not select device driver "nvidia" with capabilities: [[gpu]]'), file=sys.stderr)
                    print(t('The NVIDIA Container Toolkit is not yet registered for Docker.'), file=sys.stderr)
                    print('!' * 80 + '\n', file=sys.stderr)

                    fix_choice = prompt_choice(
                        t('How would you like to resolve the issue?\n  [1] Set up NVIDIA Container Toolkit automatically for Docker now (requires sudo) and retry [Recommended]\n  [2] Show commands to run in a second terminal, then retry\n  [3] Switch to CPU computation and start service immediately\n  [4] Cancel\nYour choice'),
                        ['auto_fix', 'manual_commands', 'switch_cpu', 'cancel'],
                        default=1
                    )

                    if fix_choice == 1:
                        success = setup_nvidia_container_toolkit_auto()
                        if success:
                            print('\n' + t('✅ NVIDIA Container Toolkit successfully set up!'))
                            print(t('Restarting service with GPU...\n'))
                            continue
                        else:
                            print('\n' + t('Automatic setup unsuccessful.'), file=sys.stderr)
                            if prompt_yes_no(t('Switch to CPU mode instead and start?'), default_yes=True):
                                fix_choice = 3
                            else:
                                return code

                    if fix_choice == 2:
                        print('\n' + t('Run the following commands in a second terminal:\n  sudo nvidia-ctk runtime configure --runtime=docker\n  sudo systemctl restart docker\n\nIf the package is not installed yet (Ubuntu/Debian):\n  sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit\n  sudo nvidia-ctk runtime configure --runtime=docker\n  sudo systemctl restart docker\n'))
                        prompt_string(t('Press Enter once you have executed the commands (or "c" to cancel)'), '')
                        wait_for_docker()
                        print(t('Restarting service...\n'))
                        continue

                    if fix_choice == 3:
                        print('\n' + t('Switching configuration to CPU computation...'))
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
                        print(t('Starting service in CPU mode...\n'))
                        continue

                    if fix_choice == 4:
                        print(t('Setup cancelled.'))
                        return code

                else:
                    print('\n' + '=' * 80, file=sys.stderr)
                    print(t('NOTE ON DOCKER GPU ERROR:'), file=sys.stderr)
                    print(t('Docker could not bind the NVIDIA graphics card.'), file=sys.stderr)
                    print(t('Error: could not select device driver "nvidia" with capabilities: [[gpu]]'), file=sys.stderr)
                    print('\n' + t('Cause: The nvidia-container-toolkit is not yet registered in Docker.'), file=sys.stderr)
                    print('\n' + t('Quick fix (on Ubuntu/Debian):'), file=sys.stderr)
                    print('  1. sudo nvidia-ctk runtime configure --runtime=docker')
                    print('     sudo systemctl restart docker')
                    print('\n  2. sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit')
                    print('     sudo nvidia-ctk runtime configure --runtime=docker')
                    print('     sudo systemctl restart docker')
                    print('\n' + t('Then run this installation script again:'), file=sys.stderr)
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
            print(t('Service started.'))
    elif args.action == 'stop':
        print(t('Service stopped.'))
    elif args.action == 'uninstall':
        print(t('Service uninstalled.'))
    elif args.action == 'check':
        # Reserved for Lead implementation of --action check
        pass
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
