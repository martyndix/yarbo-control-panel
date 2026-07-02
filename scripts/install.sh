#!/usr/bin/env bash
# Yarbo Control Panel — one-command install for Raspberry Pi / Linux / macOS
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> Yarbo Control Panel installer"
echo "    Project: $ROOT"

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "ERROR: '$1' is required but not installed." >&2
    exit 1
  fi
}

need_cmd php
need_cmd composer

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "==> PHP $PHP_VERSION"

echo "==> Installing PHP dependencies"
composer install --no-dev --optimize-autoloader

mkdir -p data
chmod 755 data 2>/dev/null || true

if [[ ! -f config.php ]]; then
  cp config.example.php config.php
  echo "==> Created config.php from config.example.php"
  echo "    Edit broker_host and serial in the web Settings page or config.php"
else
  echo "==> config.php already exists (left unchanged)"
fi

PYTHON=""
if command -v python3 >/dev/null 2>&1; then
  PYTHON=python3
elif command -v python >/dev/null 2>&1; then
  PYTHON=python
fi

if [[ -n "$PYTHON" ]]; then
  echo "==> Optional: Yarbo cloud bridge (map/plan fallback reads)"
  if "$PYTHON" -c "import yarbo_data_sdk" 2>/dev/null; then
    echo "    yarbo-data-sdk already installed"
  else
    echo "    Installing yarbo-data-sdk (optional)..."
    if "$PYTHON" -m pip install --user yarbo-data-sdk 2>/dev/null; then
      echo "    yarbo-data-sdk installed"
    else
      echo "    Skipped yarbo-data-sdk (install later with: pip install yarbo-data-sdk)"
    fi
  fi
  chmod +x scripts/cloud_bridge.py 2>/dev/null || true
else
  echo "==> Python not found — cloud fallback reads will be unavailable until Python 3 is installed"
fi

PORT="${YARBO_PANEL_PORT:-8080}"
HOST="${YARBO_PANEL_HOST:-0.0.0.0}"

echo ""
echo "Install complete."
echo ""
echo "Start the panel:"
echo "  php -S ${HOST}:${PORT} -t public"
echo ""
echo "Then open http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo 'localhost'):${PORT}"
echo "Configure broker IP, serial, and optional cloud login in Settings."
echo ""
echo "For always-on use on Linux/Pi, copy deploy/yarbo-panel.service to systemd."
