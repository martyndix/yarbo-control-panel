#!/usr/bin/env bash
# Install and start python-matter-server (Docker) for the Home module.
# Sourced by install.sh / update.sh, or run: bash scripts/lib/matter_server.sh setup
#
# No CLI is required when this runs from Settings → Panel updates.

yarbo_matter_image() {
  echo "${YARBO_MATTER_DOCKER_IMAGE:-ghcr.io/home-assistant-libs/python-matter-server:stable}"
}

yarbo_matter_name() {
  echo "${YARBO_MATTER_DOCKER_NAME:-yarbo-matter-server}"
}

yarbo_matter_status_file() {
  echo "${ROOT}/data/matter-setup.json"
}

yarbo_matter_write_status() {
  local state="$1"
  local message="${2:-}"
  local error="${3:-}"
  mkdir -p "${ROOT}/data"
  php -r 'file_put_contents($argv[1], json_encode([
    "state" => $argv[2],
    "message" => $argv[3] !== "" ? $argv[3] : null,
    "error" => $argv[4] !== "" ? $argv[4] : null,
    "updated_at" => gmdate("c"),
  ], JSON_UNESCAPED_SLASHES) . "\n");' \
    "$(yarbo_matter_status_file)" "$state" "$message" "$error"
}

yarbo_matter_port_up() {
  local port="${YARBO_MATTER_SERVER_PORT:-5580}"
  if command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1 && return 0
  fi
  if command -v nc >/dev/null 2>&1; then
    nc -z 127.0.0.1 "${port}" >/dev/null 2>&1 && return 0
  fi
  python3 - "$port" <<'PY' 2>/dev/null && return 0
import socket, sys
s = socket.socket()
s.settimeout(0.4)
try:
    s.connect(("127.0.0.1", int(sys.argv[1])))
    raise SystemExit(0)
except OSError:
    raise SystemExit(1)
finally:
    s.close()
PY
  return 1
}

yarbo_root() {
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo -n "$@"
  else
    return 1
  fi
}

yarbo_docker() {
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    docker "$@"
    return $?
  fi
  if command -v docker >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1; then
    sudo -n docker "$@"
    return $?
  fi
  if [[ -x /usr/bin/docker ]] && command -v sudo >/dev/null 2>&1; then
    sudo -n /usr/bin/docker "$@"
    return $?
  fi
  return 1
}

yarbo_matter_enable_ipv6() {
  local flag="/proc/sys/net/ipv6/conf/all/disable_ipv6"
  if [[ -f "$flag" && "$(cat "$flag" 2>/dev/null || echo 0)" == "1" ]]; then
    yarbo_root sysctl -w net.ipv6.conf.all.disable_ipv6=0 >/dev/null 2>&1 || true
  fi
}

yarbo_matter_install_docker() {
  if command -v docker >/dev/null 2>&1; then
    return 0
  fi
  if [[ "$(uname -s)" == "Darwin" ]]; then
    return 1
  fi
  if ! command -v apt-get >/dev/null 2>&1; then
    return 1
  fi
  export DEBIAN_FRONTEND=noninteractive
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    apt-get update -qq >/dev/null
    apt-get install -y docker.io >/dev/null
  else
    yarbo_root apt-get update -qq >/dev/null || return 1
    yarbo_root apt-get install -y docker.io >/dev/null || return 1
  fi
  command -v docker >/dev/null 2>&1
}

yarbo_matter_start_docker_service() {
  if ! command -v systemctl >/dev/null 2>&1; then
    return 0
  fi
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    systemctl enable --now docker >/dev/null 2>&1 || systemctl start docker >/dev/null 2>&1 || true
  else
    yarbo_root systemctl enable --now docker >/dev/null 2>&1 || yarbo_root systemctl start docker >/dev/null 2>&1 || true
  fi
}

yarbo_matter_add_docker_group() {
  local owner
  owner="$(id -un)"
  if [[ -n "${SUDO_USER:-}" && "${EUID:-$(id -u)}" -eq 0 ]]; then
    owner="${SUDO_USER}"
  fi
  if getent group docker >/dev/null 2>&1; then
    if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
      usermod -aG docker "$owner" >/dev/null 2>&1 || true
    else
      yarbo_root usermod -aG docker "$owner" >/dev/null 2>&1 || true
    fi
  fi
}

yarbo_matter_install_wrapper() {
  local src="${ROOT}/scripts/lib/matter_server.sh"
  local dest="/usr/local/sbin/yarbo-matter-setup"
  if [[ ! -f "$src" ]]; then
    return 0
  fi
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    cp "$src" "$dest"
    chmod 755 "$dest"
  else
    yarbo_root cp "$src" "$dest" 2>/dev/null || true
    yarbo_root chmod 755 "$dest" 2>/dev/null || true
  fi
}

yarbo_matter_setup() {
  mkdir -p "${ROOT}/data/matter-server"
  yarbo_matter_write_status "running" "Setting up the Matter server"
  yarbo_matter_enable_ipv6

  if yarbo_matter_port_up; then
    yarbo_matter_write_status "done" "Matter server is running"
    return 0
  fi

  yarbo_matter_install_wrapper

  if ! yarbo_matter_install_docker; then
    if [[ "$(uname -s)" == "Darwin" ]]; then
      yarbo_matter_write_status "failed" "" "On a Mac, install Docker Desktop if you want local Matter pairing. The Pi install does this automatically."
    else
      yarbo_matter_write_status "failed" "" "Could not install Docker automatically. Re-run the panel installer once (it does not need a terminal after that), then tap Set up Matter server."
    fi
    return 1
  fi

  yarbo_matter_start_docker_service
  yarbo_matter_add_docker_group
  yarbo_matter_install_wrapper

  if ! yarbo_docker info >/dev/null 2>&1; then
    yarbo_matter_write_status "failed" "" "Docker is installed but this panel user cannot use it yet. Tap Set up Matter server again, or reboot the Pi once."
    return 1
  fi

  yarbo_matter_write_status "running" "Downloading the Matter server (first time can take a few minutes)"
  yarbo_docker pull "$(yarbo_matter_image)" >/dev/null 2>&1 &
  local pull_pid=$!
  local waited=0
  while kill -0 "$pull_pid" 2>/dev/null; do
    sleep 2
    waited=$((waited + 2))
    if (( waited >= 180 )); then
      kill "$pull_pid" 2>/dev/null || true
      yarbo_matter_write_status "failed" "" "Could not download the Matter server image. Check the Pi has internet, then tap Set up Matter server."
      return 1
    fi
  done
  if ! wait "$pull_pid"; then
    yarbo_matter_write_status "failed" "" "Could not download the Matter server image. Check the Pi has internet, then tap Set up Matter server."
    return 1
  fi

  local name image
  name="$(yarbo_matter_name)"
  image="$(yarbo_matter_image)"
  if yarbo_docker inspect "$name" >/dev/null 2>&1; then
    yarbo_docker start "$name" >/dev/null 2>&1 || true
  else
    yarbo_docker run -d \
      --name "$name" \
      --restart unless-stopped \
      --security-opt apparmor=unconfined \
      --network host \
      -v "${ROOT}/data/matter-server:/data" \
      "$image" >/dev/null 2>&1 || true
  fi

  local i
  for i in $(seq 1 40); do
    if yarbo_matter_port_up; then
      yarbo_matter_write_status "done" "Matter server is running"
      return 0
    fi
    sleep 1
  done
  yarbo_matter_write_status "failed" "" "Docker started but the Matter server is not listening yet. Wait a minute, then tap Set up Matter server."
  return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  if [[ -n "${1:-}" && -d "${1}/scripts" ]]; then
    ROOT="$1"
  elif [[ -n "${YARBO_ROOT:-}" && -d "${YARBO_ROOT}/scripts" ]]; then
    ROOT="$YARBO_ROOT"
  else
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  fi
  cd "$ROOT"
  yarbo_matter_setup
fi
