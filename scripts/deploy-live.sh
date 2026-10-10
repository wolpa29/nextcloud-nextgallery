#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

SERVER="10.1.10.186"
APP_DIR="/var/www/nextcloud/apps"
VERSION=$(node -p "JSON.parse(require('fs').readFileSync('package.json')).version")
TARBALL="$REPO_ROOT/dist/nextgallery-${VERSION}.tar.gz"
CONTROL_SOCKET=${NEXTGALLERY_SSH_CONTROL_SOCKET:?Set NEXTGALLERY_SSH_CONTROL_SOCKET to the authenticated SSH control socket}
ssh_options=(-S "$CONTROL_SOCKET" -o ControlMaster=no -o BatchMode=yes -o PubkeyAuthentication=no -o PasswordAuthentication=no)
ssh "${ssh_options[@]}" -O check "root@${SERVER}"

echo "==> Packaging $TARBALL..."
python3 "$REPO_ROOT/scripts/package-app.py" "$REPO_ROOT/dist"
python3 "$REPO_ROOT/scripts/verify-worker-package.py" "$TARBALL"

echo "==> Deploying NextGallery directly to root@${SERVER}:${APP_DIR}/nextgallery..."
ssh "${ssh_options[@]}" "tar -xzf - -C ${APP_DIR} && chown -R www-data:www-data ${APP_DIR}/nextgallery" < "$TARBALL"

echo "==> NextGallery update successfully deployed to Nextcloud!"
