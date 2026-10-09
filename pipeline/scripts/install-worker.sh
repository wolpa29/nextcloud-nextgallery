#!/usr/bin/env bash
# Install the bundled worker after the app is enabled and the database backed up.
set -euo pipefail
if [[ $EUID -ne 0 || $# -lt 2 || $# -gt 3 ]]; then
  echo 'Usage: sudo install-worker.sh /var/www/nextcloud https://nextcloud.example [public-ca.crt]' >&2; exit 2
fi
nc_root=$(realpath "$1")
nc_url=$2
ca_file=${3:-}
app_path=$(runuser -u www-data -- php "$nc_root/occ" app:getpath media_ai)
state_path=$(runuser -u www-data -- php "$nc_root/occ" config:system:get datadirectory)/.media_ai
[[ -f "$app_path/worker/app_worker.py" && "$state_path" == /* && "$state_path" != /\.media_ai && ! -L "$state_path" ]]
command -v ffmpeg >/dev/null && command -v ffprobe >/dev/null || { echo 'Install ffmpeg (including ffprobe) before installing the worker' >&2; exit 2; }
command -v node >/dev/null && command -v npm >/dev/null || { echo 'Install Node.js (18+) and npm for the LM Studio SDK transport' >&2; exit 2; }
(cd "$app_path/worker/lmstudio" && npm ci --ignore-scripts --no-audit --no-fund)
# Nextcloud CLI creates private bootstrap and an encrypted worker token.
setup_options=(--url "$nc_url")
if [[ -n "$ca_file" ]]; then setup_options+=(--ca-file "$ca_file"); fi
runuser -u www-data -- php "$nc_root/occ" media-ai:setup "${setup_options[@]}"
python3 -m venv /opt/nextcloud-media-ai-venv
/opt/nextcloud-media-ai-venv/bin/pip install -r "$app_path/worker/requirements.txt"
cat > /etc/systemd/system/nextcloud-media-ai.service <<EOF
[Unit]
Description=Nextcloud Media AI worker
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=www-data
Group=www-data
UMask=0077
ExecStart=/opt/nextcloud-media-ai-venv/bin/python "$app_path/worker/app_worker.py" --bootstrap "$state_path/bootstrap.json"
Restart=on-failure
RestartSec=30
Nice=10
IOSchedulingClass=idle
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$state_path
MemoryMax=1200M
Environment=PYTHONDONTWRITEBYTECODE=1
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now nextcloud-media-ai.service
systemctl --no-pager --full status nextcloud-media-ai.service
