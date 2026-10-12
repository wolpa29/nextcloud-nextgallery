#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_CMD=""

if command -v python3 >/dev/null 2>&1; then
    PYTHON_CMD="python3"
elif command -v python >/dev/null 2>&1; then
    PYTHON_CMD="python"
else
    echo "FEHLER: Python 3 ist nicht installiert. Bitte Python 3 installieren." >&2
    exit 1
fi

exec "$PYTHON_CMD" "$SCRIPT_DIR/install-embedding-server.py" "$@"
