#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_CMD=""

if command -v python3 >/dev/null 2>&1; then
    PYTHON_CMD="python3"
elif command -v python >/dev/null 2>&1; then
    PYTHON_CMD="python"
else
    echo "Python 3.10+ is required. / Python 3.10 oder neuer wird benötigt." >&2
    exit 1
fi

if ! "$PYTHON_CMD" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)'; then
    echo "Python 3.10+ is required. / Python 3.10 oder neuer wird benötigt." >&2
    exit 1
fi

exec "$PYTHON_CMD" "$SCRIPT_DIR/install-embedding-server.py" "$@"
