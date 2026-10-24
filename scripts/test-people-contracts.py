#!/usr/bin/env python3
"""Isolated People integration test suite running all 10 real People database and controller contracts.
Uses isolated disposable containers with unique names/ports and synthetic media only.
"""
import argparse, json, os, shutil, subprocess, sys, time, urllib.request, urllib.error, struct, zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def png(color):
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    width = height = 80
    rows = b''.join(b'\x00' + bytes(color) * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')

def copy_app(source, target):
    target.mkdir(parents=True, exist_ok=True)
    for child in target.iterdir():
        if child.is_dir(): shutil.rmtree(child)
        else: child.unlink()
    for rel in ['appinfo', 'lib', 'templates', 'css', 'js', 'img', 'worker']:
        if (source / rel).exists():
            shutil.copytree(source / rel, target / rel, ignore=shutil.ignore_patterns('node_modules', '__pycache__', '*.pyc'))

def main():
    parser = argparse.ArgumentParser(description='Run real People PHP contracts in isolated Nextcloud.')
    parser.add_argument('--nc-version', default='34', choices=['34', '35'], help='Nextcloud major version (34 or 35)')
    parser.add_argument('--port', type=int, default=8798, help='Unique host port for Nextcloud')
    parser.add_argument('--prefix', default='nextgallery-ci-people', help='Unique container name prefix')
    parser.add_argument('--keep', action='store_true', help='Keep containers on finish')
    args = parser.parse_args()

    name = f"{args.prefix}-{args.nc_version}"
    dbname = f"{name}-db"
    redisname = f"{name}-redis"
    network = f"{name}-net"
    port = args.port

    out = ROOT / f'artifacts/people-ci-{args.nc_version}'
    out.mkdir(parents=True, exist_ok=True)
    (out / 'ISOLATED_SYNTHETIC_TEST').touch()
    (out / 'result.json').unlink(missing_ok=True)
    try: os.chmod(out, 0o777)
    except Exception: pass

    runtime = out / 'runtime'
    runtime.mkdir(exist_ok=True)
    app_target = runtime / 'nextgallery'
    copy_app(ROOT, app_target)

    samples = out / 'samples'
    samples.mkdir(exist_ok=True)
    for dest, color in [('dog.png', (230, 190, 70)), ('bike.png', (60, 150, 180)), ('invoice.png', (180, 190, 170))]:
        (samples / dest).write_bytes(png(color))
    try: os.chmod(samples, 0o777)
    except Exception: pass

    def run(*cmd, check=True):
        res = subprocess.run(cmd, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if check and res.returncode != 0:
            print(res.stdout, flush=True)
            raise subprocess.CalledProcessError(res.returncode, cmd, res.stdout)
        return res.stdout

    def docker(*cmd): return run('docker', *cmd)
    def occ(*cmd): return docker('exec', '-u', 'www-data', name, 'php', 'occ', *cmd)

    containers = []
    image = 'nextcloud:34.0.5-apache' if args.nc_version == '34' else 'nextcloud:35.0.1-apache'

    CONTRACTS = [
        'native-people-snapshots-contract.php',
        'faces-account-scope-contract.php',
        'faces-contract.php',
        'faces-quality-contract.php',
        'faces-order-contract.php',
        'faces-resort-contract.php',
        'faces-shared-contract.php',
        'faces-suggestions-contract.php',
        'faces-user-features-contract.php',
        'native-people-contract.php',
    ]

    results = {}
    try:
        print(f"Creating network {network}...", flush=True)
        docker('network', 'create', network)

        print(f"Starting MariaDB container {dbname}...", flush=True)
        docker('run', '-d', '--name', dbname, '--network', network,
               '-e', 'MARIADB_ROOT_PASSWORD=SyntheticRootOnly2026',
               '-e', 'MARIADB_DATABASE=nextcloud',
               '-e', 'MARIADB_USER=nextcloud',
               '-e', 'MARIADB_PASSWORD=SyntheticDbOnly2026',
               'mariadb:10.11')
        containers.append(dbname)

        print(f"Starting Redis container {redisname}...", flush=True)
        docker('run', '-d', '--name', redisname, '--network', network, 'redis:8.2-alpine')
        containers.append(redisname)

        print(f"Starting Nextcloud {args.nc_version} container {name} on port {port}...", flush=True)
        docker('run', '-d', '--name', name, '--network', network,
               '-p', f'127.0.0.1:{port}:80',
               '-v', f'{app_target}:/var/www/html/custom_apps/nextgallery:ro',
               '-v', f'{ROOT}/tests:/test-scripts:ro',
               '-v', f'{out}:/test-output',
               '-v', f'{samples}:/samples:ro',
               '-e', 'NEXTCLOUD_ADMIN_USER=synthetic-admin',
               '-e', 'NEXTCLOUD_ADMIN_PASSWORD=SyntheticAdminOnly2026',
               '-e', 'NEXTCLOUD_TRUSTED_DOMAINS=127.0.0.1 localhost',
               '-e', f'REDIS_HOST={redisname}',
               '-e', f'MYSQL_HOST={dbname}',
               '-e', 'MYSQL_DATABASE=nextcloud',
               '-e', 'MYSQL_USER=nextcloud',
               '-e', 'MYSQL_PASSWORD=SyntheticDbOnly2026',
               image)
        containers.insert(0, name)

        print("Waiting for Nextcloud installation...", flush=True)
        ready = False
        for _ in range(120):
            try:
                data = json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/status.php', timeout=2))
                if data.get('installed'):
                    ready = True
                    break
            except Exception:
                pass
            time.sleep(1)
        assert ready, docker('logs', name)[-3000:]

        print("Installing python3 in container...", flush=True)
        docker('exec', name, 'sh', '-c', 'apt-get update -qq && apt-get install -y -qq --no-install-recommends python3')

        print("Enabling nextgallery app...", flush=True)
        print(occ('app:enable', 'nextgallery'), flush=True)

        for phase in ['prepare', 'prepare-early']:
            print(docker('exec', '-u', 'www-data', name, 'php', '/test-scripts/people-table-upgrade-contract.php', phase), flush=True)
            print(occ('upgrade'), flush=True)
            print(docker('exec', '-u', 'www-data', name, 'php', '/test-scripts/people-table-upgrade-contract.php', 'verify'), flush=True)

        for contract in CONTRACTS:
            print(f"\n=======================================================", flush=True)
            print(f"Running contract: {contract}", flush=True)
            print(f"=======================================================", flush=True)
            t0 = time.time()
            res = docker('exec', '-u', 'www-data', name, 'php', f'/test-scripts/{contract}')
            dt = time.time() - t0
            print(res, flush=True)
            results[contract] = {'status': 'passed', 'duration_s': round(dt, 2), 'output': res.strip()}

        summary = {
            'nc_version': args.nc_version,
            'contracts': CONTRACTS,
            'total': len(CONTRACTS),
            'passed': len(results),
            'failed': 0,
            'results': results
        }
        (out / 'result.json').write_text(json.dumps(summary, indent=2))
        print(f"\nALL {len(CONTRACTS)} PEOPLE CONTRACTS PASSED SUCCESSFULLY ON NEXTCLOUD {args.nc_version}!", flush=True)

    finally:
        if not args.keep:
            print("\nCleaning up containers...", flush=True)
            for c in containers:
                run('docker', 'rm', '-fv', c, check=False)
            run('docker', 'network', 'rm', network, check=False)

if __name__ == '__main__':
    main()
