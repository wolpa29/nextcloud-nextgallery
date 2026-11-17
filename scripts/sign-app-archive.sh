#!/usr/bin/env bash
# Insert Nextcloud's per-file integrity signature into an existing release archive.
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 NEXTGALLERY_TARBALL PRIVATE_KEY NEXTCLOUD_CERTIFICATE" >&2
  exit 2
fi

archive=$(realpath "$1")
key=$(realpath "$2")
certificate=$(realpath "$3")
work=$(mktemp -d)
trap 'rm -r -- "$work"' EXIT
umask 022

openssl pkey -in "$key" -pubout > "$work/key.pub"
openssl x509 -in "$certificate" -pubkey -noout > "$work/certificate.pub"
cmp -s "$work/key.pub" "$work/certificate.pub" || {
  echo 'The signing key and certificate do not match.' >&2
  exit 1
}

tar --no-same-owner -xzf "$archive" -C "$work"
test -f "$work/nextgallery/appinfo/info.xml"
docker run --rm --network none --user "$(id -u):$(id -g)" \
  --volume "$work/nextgallery:/app" \
  --volume "$key:/signing.key:ro" \
  --volume "$certificate:/signing.crt:ro" \
  --tmpfs "/usr/src/nextcloud/config:uid=$(id -u),gid=$(id -g),mode=0700" \
  --workdir /usr/src/nextcloud --entrypoint php \
  nextcloud:35.0.1-apache occ integrity:sign-app \
  --path=/app --privateKey=/signing.key --certificate=/signing.crt

test -s "$work/nextgallery/appinfo/signature.json"
chmod 644 "$work/nextgallery/appinfo/signature.json"
tar -czf "$work/signed.tar.gz" -C "$work" nextgallery
mv -- "$work/signed.tar.gz" "$archive"
