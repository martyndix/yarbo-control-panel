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
  apt-get install -y php php-cli php-mbstring php-xml php-zlib php-zip composer unzip git python3 python3-pip docker.io \
    shairport-sync avahi-daemon
  if command -v systemctl >/dev/null 2>&1; then
    systemctl enable avahi-daemon >/dev/null 2>&1 || true
    systemctl start avahi-daemon >/dev/null 2>&1 || true
    # Packaged unit uses ALSA and steals the AirPlay name from the panel pipe receiver.
    systemctl stop shairport-sync >/dev/null 2>&1 || true
    systemctl disable shairport-sync >/dev/null 2>&1 || true
    systemctl mask shairport-sync >/dev/null 2>&1 || true
  fi
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
  chmod +x scripts/update.sh 2>/dev/null || true
  chmod +x scripts/dev.sh scripts/panel.sh scripts/panel_https_proxy.py scripts/panel_https_ensure.sh scripts/panel_airplay_ensure.sh scripts/panel_airplay_stop.sh scripts/panel_airplay_hook.sh scripts/panel_airplay_bridge.php scripts/panel_airplay_metadata.php scripts/panel_airplay_watch.sh scripts/panel_homepod_ensure.sh scripts/home_automations.php scripts/vestaboard_watch.php scripts/paper_firmware_build.php scripts/metrics_ping.php scripts/matter_agent.py scripts/homepod_agent.py scripts/matter_check_node.py scripts/matter_fix_ipv6.sh scripts/matter_setup.sh scripts/lib/matter_server.sh scripts/lib/matter_agent.sh scripts/lib/homepod_agent.sh scripts/lib/homepod_deps.sh scripts/lib/panel_https.sh scripts/lib/panel_airplay.sh 2>/dev/null || true
  # Browser mic / Announce needs LAN HTTPS :8443 reachable.
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'Status: active'; then
    ufw allow 8443/tcp comment 'Yarbo panel HTTPS' >/dev/null 2>&1 || true
  fi

  echo "==> Matter server (Home module)"
  # shellcheck source=scripts/lib/matter_server.sh
  source "${ROOT}/scripts/lib/matter_server.sh"
  if yarbo_matter_setup; then
    echo "    Matter server is ready"
  else
    echo "    WARNING: Matter server is not running yet — Settings → Home → Set up Matter server"
  fi

  # shellcheck source=scripts/lib/python_sdk.sh
  source "${ROOT}/scripts/lib/python_sdk.sh"

  local python resolved
  python="$(yarbo_python_bin || true)"

  if [[ -n "$python" ]]; then
    echo "==> Lymow MQTT libraries (paho-mqtt + websocket-client)"
    local lymow_py="$python"
    if [[ -x "${ROOT}/.venv/bin/python" ]]; then
      lymow_py="${ROOT}/.venv/bin/python"
    fi
    if "$lymow_py" -c "import paho.mqtt.client, websocket" >/dev/null 2>&1; then
      echo "    already installed"
    elif run_as_owner "'${lymow_py}' -m pip install --disable-pip-version-check --break-system-packages paho-mqtt websocket-client" \
      || run_as_owner "'${lymow_py}' -m pip install --disable-pip-version-check --user --break-system-packages paho-mqtt websocket-client"; then
      echo "    installed"
    else
      echo "    WARNING: pip install failed — Settings → Lymow → Sign in will retry"
    fi
    echo "==> HomePod / AirPlay sender (pyatv)"
    # shellcheck source=scripts/lib/homepod_deps.sh
    source "${ROOT}/scripts/lib/homepod_deps.sh"
    if run_as_owner "source '${ROOT}/scripts/lib/homepod_deps.sh' && yarbo_ensure_pyatv '${ROOT}'"; then
      echo "    installed ($(run_as_owner "source '${ROOT}/scripts/lib/homepod_deps.sh' && yarbo_homepod_python '${ROOT}'" || true))"
    else
      echo "    WARNING: pyatv pip install failed — Scan for HomePods will retry (see data/homepod-agent.log)"
    fi
    echo "==> Yarbo cloud bridge (map/plan fallback reads)"
    if [[ "${EUID}" -eq 0 ]]; then
      ensure_python_pip "$python" || true
    fi
    if run_as_owner "source '${ROOT}/scripts/lib/python_sdk.sh' && install_yarbo_data_sdk '${python}' '${ROOT}'"; then
      resolved="$(run_as_owner "source '${ROOT}/scripts/lib/python_sdk.sh' && yarbo_resolve_python '${ROOT}'" || true)"
      if [[ -n "$resolved" ]] && run_as_owner "source '${ROOT}/scripts/lib/python_sdk.sh' && yarbo_sdk_installed '${resolved}'"; then
        echo "    yarbo-data-sdk installed (${resolved})"
      else
        echo "    WARNING: Python packages did not import after install — cloud fallback may be unavailable"
      fi
    else
      echo "    WARNING: could not install yarbo-data-sdk (required for Yarbo cloud MQTT controls)"
      echo "    The PHP panel still works. Cloud map/plan reads and the Python MQTT agent need pip."
      echo "    On macOS: brew install python && python3 -m ensurepip --upgrade && ./scripts/install.sh"
      echo "    On Debian/Pi: sudo apt install python3-venv python3-pip && ./scripts/install.sh"
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
Environment=YARBO_PANEL_HOST=${HOST}
Environment=YARBO_PANEL_PORT=${PORT}
Environment=YARBO_PHP_BIN=${php_bin}
ExecStart=${ROOT}/scripts/panel.sh
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

  install_update_sudoers "${owner}"
}

install_update_sudoers() {
  local owner="$1"
  local sudoers_path="/etc/sudoers.d/yarbo-panel-update"
  local systemctl_bin docker_bin wrapper
  systemctl_bin="$(command -v systemctl)"
  docker_bin="$(command -v docker || true)"
  wrapper="/usr/local/sbin/yarbo-matter-setup"

  if [[ -z "$systemctl_bin" ]]; then
    return 0
  fi

  if [[ -f "${ROOT}/scripts/lib/matter_server.sh" ]]; then
    cp "${ROOT}/scripts/lib/matter_server.sh" "$wrapper"
    chmod 755 "$wrapper"
  fi

  echo "==> Allowing ${owner} to restart ${SERVICE_NAME} and set up Matter without a password"
  {
    echo "# Yarbo Control Panel — passwordless helpers for web UI updates (scripts/install.sh)"
    echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} restart ${SERVICE_NAME}"
    echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} start docker"
    echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} enable docker"
    echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} enable --now docker"
    echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} restart docker"
    echo "${owner} ALL=(ALL) NOPASSWD: ${wrapper}"
    if [[ -x "${ROOT}/scripts/paper_remote.sh" ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: ${ROOT}/scripts/paper_remote.sh"
    fi
    if [[ -x /usr/sbin/sysctl ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.all.disable_ipv6=0"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.default.disable_ipv6=0"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.eth0.disable_ipv6=0"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.wlan0.disable_ipv6=0"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.eth0.accept_ra=1"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.wlan0.accept_ra=1"
    fi
    for ip_bin in /usr/sbin/ip /bin/ip /usr/bin/ip; do
      if [[ -x "$ip_bin" ]]; then
        echo "${owner} ALL=(ALL) NOPASSWD: ${ip_bin} -6 route add default dev eth0"
        echo "${owner} ALL=(ALL) NOPASSWD: ${ip_bin} -6 route add default dev wlan0"
      fi
    done
    if [[ -x /usr/sbin/usermod ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/sbin/usermod -aG docker ${owner}"
    fi
    if [[ -n "$docker_bin" ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: ${docker_bin}"
    fi
    if [[ -x /usr/bin/docker ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/docker"
    fi
    if [[ -x /usr/bin/apt-get ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get update -qq"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get install -y docker.io"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get install -y shairport-sync avahi-daemon"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get install -y avahi-daemon shairport-sync"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get install -y python3-pip python3-venv"
      echo "${owner} ALL=(ALL) NOPASSWD: /usr/bin/apt-get install -y python3-venv python3-pip"
    fi
    # AirPlay advertise: Avahi must run; packaged shairport ALSA unit must not steal mDNS.
    if [[ -n "$systemctl_bin" ]]; then
      echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} start avahi-daemon"
      echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} enable avahi-daemon"
      echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} stop shairport-sync"
      echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} disable shairport-sync"
      echo "${owner} ALL=(ALL) NOPASSWD: ${systemctl_bin} mask shairport-sync"
    fi
  } > "${sudoers_path}"
  chmod 440 "${sudoers_path}"
  if ! visudo -cf "${sudoers_path}" >/dev/null 2>&1; then
    rm -f "${sudoers_path}"
    echo "    WARNING: could not install sudoers rule — UI updates may require manual restart"
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

lan_https_url() {
  local ip https_port="${YARBO_PANEL_HTTPS_PORT:-8443}"
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  if [[ -n "$ip" ]]; then
    echo "https://${ip}:${https_port}"
  else
    echo "https://localhost:${https_port}"
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
  echo "HTTPS UI (mic / Announce): $(lan_https_url) — accept the self-signed warning once"
  echo "The panel starts automatically on boot (systemd: ${SERVICE_NAME})."
  echo ""
  echo "Next step: open the URL above, click Settings, and enter your Yarbo broker IP and serial."
  echo ""
  echo "Useful commands:"
  echo "  sudo systemctl status ${SERVICE_NAME}"
  echo "  sudo systemctl restart ${SERVICE_NAME}"
  echo "  sudo journalctl -u ${SERVICE_NAME} -f"
else
  echo "Start the panel with:"
  echo "  ./scripts/dev.sh"
  echo ""
  echo "Then open $(lan_url) and use Settings to enter broker IP and serial."
  echo "Keep that terminal open. Do not use php -S alone — the MQTT agent must start first."
  if command -v systemctl >/dev/null 2>&1; then
    echo ""
    echo "For auto-start on boot, run:"
    echo "  sudo ./scripts/install.sh"
  fi
fi
