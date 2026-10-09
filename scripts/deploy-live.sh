#!/usr/bin/env bash
set -euo pipefail

SERVER="10.1.10.186"
APP_DIR="/var/www/nextcloud/apps"
TARBALL="dist/nextgallery-0.4.0.tar.gz"

if [ ! -f "$TARBALL" ]; then
    echo "Creating package $TARBALL..."
    python3 scripts/package-app.py dist
fi

echo "==> Transferring $TARBALL to root@${SERVER}:/tmp/..."
scp "$TARBALL" "root@${SERVER}:/tmp/nextgallery-update.tar.gz"

echo "==> Extracting and setting permissions on server..."
ssh "root@${SERVER}" "tar -xzf /tmp/nextgallery-update.tar.gz -C ${APP_DIR} && chown -R www-data:www-data ${APP_DIR}/nextgallery && rm -f /tmp/nextgallery-update.tar.gz"

echo "==> NextGallery update successfully deployed to Nextcloud!"
