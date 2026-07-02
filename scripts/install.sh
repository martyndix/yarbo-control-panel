#!/usr/bin/env bash
# Yarbo Control Panel — installer for Raspberry Pi / Linux / macOS
#
# Usage:
#   ./scripts/install.sh                 Project setup (Composer, config, data/)
#   sudo ./scripts/install.sh            Above + systemd auto-start on boot (Linux)
#   sudo ./scripts/install.sh --deps     apt packages + project + systemd (Debian/Pi)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${YARBO_PANEL_PORT:-8080}"
HOST="${YARBO_PANEL_HOST:-0.0.0.0}"
SERVICE_NAME="yarbo-panel"
WITH_DEPS=false

for arg in "$@"; do
  case "$arg" in
    --deps|--with-deps) WITH_DEPS=true ;;
    -h|--help)
      sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
  esac
done

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "ERROR: '$1' is required but not installed." >&2
    exit 1
  fi
}

run_as_owner() {
  if [[ "${EUID}" -eq 0 && -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    sudo -u "${SUDO_USER}" -H bash -c "cd '$ROOT' && $*"
  else
    bash -c "cd '$ROOT' && $*"
  fi
}

install_owner() {
  if [[ "${EUID}" -eq 0 && -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    echo "${SUDO_USER}"
  else
    id -un
  fi
}

install_apt_deps() {
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "ERROR: --deps requires apt-get (Debian/Ubuntu/Raspberry Pi OS)." >&2
    exit 1
  fi
  echo "==> Installing system packages (apt)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y php php-cli php-mbstring php-xml php-zlib composer unzip git python3 python3-pip
}

install_project() {
  local owner
  owner="$(install_owner)"

  need_cmd php
  need_cmd composer

  echo "==> PHP $(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  echo "==> Installing PHP dependencies"
  run_as_owner "composer install --no-dev --optimize-autoloader"

  mkdir -p data
  chmod 755 data 2>/dev/null || true

  if [[ ! -f config.php ]]; then
    cp config.example.php config.php
    echo "==> Created config.php (defaults — set broker IP and serial in the web Settings page)"
  else
    echo "==> config.php already exists (left unchanged)"
  fi

  if [[ "${EUID}" -eq 0 && -n "${SUDO_USER:-}" ]]; then
    chown -R "${owner}:${owner}" "$ROOT/data" 2>/dev/null || true
    chown "${owner}:${owner}" "$ROOT/config.php" 2>/dev/null || true
  fi

  chmod +x scripts/cloud_bridge.py 2>/dev/null || true

  local python=""
  if command -v python3 >/dev/null 2>&1; then
    python=python3
  elif command -v python >/dev/null 2>&1; then
    python=python
  fi

  if [[ -n "$python" ]]; then
    echo "==> Optional: Yarbo cloud bridge (map/plan fallback reads)"
    if "$python" -c "import yarbo_data_sdk" 2>/dev/null; then
      echo "    yarbo-data-sdk already installed"
    elif run_as_owner "$python -m pip install --user yarbo-data-sdk" 2>/dev/null; then
      echo "    yarbo-data-sdk installed for ${owner}"
    else
      echo "    Skipped yarbo-data-sdk (install later: pip install yarbo-data-sdk)"
    fi
  else
    echo "==> Python not found — cloud map/plan reads unavailable until Python 3 is installed"
  fi
}

install_systemd_service() {
  if ! command -v systemctl >/dev/null 2>&1; then
    echo "==> systemd not found — skipping auto-start setup"
    return 0
  fi

  if [[ "${EUID}" -ne 0 ]]; then
    echo "==> Run with sudo to install the systemd service for auto-start on boot"
    return 0
  fi

  local owner php_bin unit_path
  owner="$(install_owner)"
  php_bin="$(command -v php)"
  unit_path="/etc/systemd/system/${SERVICE_NAME}.service"

  echo "==> Installing systemd service (${SERVICE_NAME})"
  cat > "${unit_path}" <<EOF
[Unit]
Description=Yarbo PHP Control Panel
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${owner}
WorkingDirectory=${ROOT}
ExecStart=${php_bin} -S ${HOST}:${PORT} -t ${ROOT}/public
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable "${SERVICE_NAME}"
  systemctl restart "${SERVICE_NAME}"

  if systemctl is-active --quiet "${SERVICE_NAME}"; then
    echo "    Service is running"
  else
    echo "    WARNING: service failed to start — check: journalctl -u ${SERVICE_NAME} -n 30"
  fi
}

lan_url() {
  local ip
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  if [[ -n "$ip" ]]; then
    echo "http://${ip}:${PORT}"
  else
    echo "http://localhost:${PORT}"
  fi
}

echo "==> Yarbo Control Panel installer"
echo "    Project: ${ROOT}"

if $WITH_DEPS; then
  if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: --deps must be run with sudo." >&2
    exit 1
  fi
  install_apt_deps
fi

install_project
install_systemd_service

echo ""
echo "Install complete."
echo ""
if command -v systemctl >/dev/null 2>&1 && systemctl is-enabled "${SERVICE_NAME}" >/dev/null 2>&1; then
  echo "Panel URL: $(lan_url)"
  echo "The panel starts automatically on boot (systemd: ${SERVICE_NAME})."
  echo ""
  echo "Next step: open the URL above, click Settings, and enter your Yarbo broker IP and serial."
  echo ""
  echo "Useful commands:"
  echo "  sudo systemctl status ${SERVICE_NAME}"
  echo "  sudo systemctl restart ${SERVICE_NAME}"
  echo "  sudo journalctl -u ${SERVICE_NAME} -f"
else
  echo "Start the panel manually:"
  echo "  php -S ${HOST}:${PORT} -t public"
  echo ""
  echo "Then open $(lan_url) and use Settings to enter broker IP and serial."
  if command -v systemctl >/dev/null 2>&1; then
    echo ""
    echo "For auto-start on boot, run:"
    echo "  sudo ./scripts/install.sh"
  fi
fi
