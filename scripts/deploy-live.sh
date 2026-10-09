#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

SERVER="10.1.10.186"
APP_DIR="/var/www/nextcloud/apps"
TARBALL="$REPO_ROOT/dist/nextgallery-0.4.0.tar.gz"

if [ ! -f "$TARBALL" ]; then
    echo "==> Packaging $TARBALL..."
    python3 "$REPO_ROOT/scripts/package-app.py" "$REPO_ROOT/dist"
fi

echo "==> Deploying NextGallery directly to root@${SERVER}:${APP_DIR}/nextgallery..."
ssh "root@${SERVER}" "tar -xzf - -C ${APP_DIR} && chown -R www-data:www-data ${APP_DIR}/nextgallery" < "$TARBALL"

echo "==> NextGallery update successfully deployed to Nextcloud!"
