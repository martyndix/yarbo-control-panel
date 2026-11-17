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
  local flag iface
  for iface in all default eth0 wlan0 end0; do
    flag="/proc/sys/net/ipv6/conf/${iface}/disable_ipv6"
    if [[ -f "$flag" ]]; then
      yarbo_root sysctl -w "net.ipv6.conf.${iface}.disable_ipv6=0" >/dev/null 2>&1 || true
    fi
  done
  yarbo_matter_ensure_ipv6_route
}

yarbo_matter_write_thread_sysctl_conf() {
  local iface conf
  iface="$(yarbo_matter_primary_interface)"
  conf="/etc/sysctl.d/99-yarbo-matter-thread.conf"
  {
    echo "# Yarbo Matter — accept Apple HomePod/Apple TV Thread RIO routes"
    echo "net.ipv6.conf.all.disable_ipv6=0"
    echo "net.ipv6.conf.default.disable_ipv6=0"
    echo "net.ipv6.conf.all.accept_ra=2"
    echo "net.ipv6.conf.default.accept_ra=2"
    echo "net.ipv6.conf.all.accept_ra_rt_info_max_plen=64"
    echo "net.ipv6.conf.default.accept_ra_rt_info_max_plen=64"
    if [[ -n "$iface" ]]; then
      echo "net.ipv6.conf.${iface}.disable_ipv6=0"
      echo "net.ipv6.conf.${iface}.accept_ra=2"
      echo "net.ipv6.conf.${iface}.accept_ra_rt_info_max_plen=64"
    fi
    for iface in end0 eth0 wlan0; do
      if [[ -d "/sys/class/net/${iface}" ]]; then
        echo "net.ipv6.conf.${iface}.disable_ipv6=0"
        echo "net.ipv6.conf.${iface}.accept_ra=2"
        echo "net.ipv6.conf.${iface}.accept_ra_rt_info_max_plen=64"
      fi
    done
    for iface in tailscale0 tailscale1; do
      if [[ -d "/sys/class/net/${iface}" ]]; then
        echo "net.ipv6.conf.${iface}.accept_ra=0"
      fi
    done
  } | yarbo_root tee "$conf" >/dev/null 2>&1 || true
  yarbo_root sysctl --system >/dev/null 2>&1 || true
}

yarbo_matter_ensure_ipv6_route() {
  local iface target
  iface="$(yarbo_matter_primary_interface)"
  if [[ -z "$iface" ]]; then
    return 0
  fi
  yarbo_matter_write_thread_sysctl_conf
  # accept_ra=2 works when ip_forward=1; rt_info_max_plen accepts Apple Thread /64 RIOs.
  for target in all default "$iface" end0 eth0 wlan0; do
    if [[ -f "/proc/sys/net/ipv6/conf/${target}/accept_ra" ]]; then
      yarbo_root sysctl -w "net.ipv6.conf.${target}.disable_ipv6=0" >/dev/null 2>&1 || true
      yarbo_root sysctl -w "net.ipv6.conf.${target}.accept_ra=2" >/dev/null 2>&1 || true
      yarbo_root sysctl -w "net.ipv6.conf.${target}.accept_ra_rt_info_max_plen=64" >/dev/null 2>&1 || true
    fi
  done
  for target in tailscale0 tailscale1; do
    if [[ -f "/proc/sys/net/ipv6/conf/${target}/accept_ra" ]]; then
      yarbo_root sysctl -w "net.ipv6.conf.${target}.accept_ra=0" >/dev/null 2>&1 || true
    fi
  done
  yarbo_root ip -6 route add multicast ff00::/8 dev "$iface" table local >/dev/null 2>&1 || true
  if command -v rdisc6 >/dev/null 2>&1; then
    yarbo_root rdisc6 -1 -r 1 -w 1000 "$iface" >/dev/null 2>&1 || true
  fi
  if ip -6 route show default 2>/dev/null | grep -q .; then
    return 0
  fi
  yarbo_root ip -6 route add default dev "$iface" >/dev/null 2>&1 \
    || ip -6 route add default dev "$iface" >/dev/null 2>&1 \
    || true
}

yarbo_matter_iface_has_ipv6_ll() {
  local iface="$1"
  [[ -n "$iface" ]] || return 1
  ip -6 -o addr show dev "$iface" scope link 2>/dev/null | grep -q .
}

yarbo_matter_iface_is_virtual() {
  local iface="$1"
  case "$iface" in
    tailscale*|wg*|tun*|tap*|docker*|br-*|veth*|zt*|cni*|flannel*|virbr*|vmnet*) return 0 ;;
  esac
  return 1
}

yarbo_matter_primary_interface() {
  if [[ -n "${YARBO_MATTER_PRIMARY_INTERFACE:-}" ]]; then
    echo "$YARBO_MATTER_PRIMARY_INTERFACE"
    return
  fi
  local iface route
  route="$(ip -4 route show default 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
  if yarbo_matter_iface_is_virtual "$route"; then
    route=""
  fi
  if [[ -n "$route" && -d "/sys/class/net/${route}" ]] && yarbo_matter_iface_has_ipv6_ll "$route"; then
    echo "$route"
    return
  fi
  for iface in end0 eth0 enp1s0 enp0s3 wlan0; do
    if [[ -d "/sys/class/net/${iface}" ]] && yarbo_matter_iface_has_ipv6_ll "$iface"; then
      echo "$iface"
      return
    fi
  done
  if [[ -n "$route" && -d "/sys/class/net/${route}" ]]; then
    echo "$route"
    return
  fi
  for iface in end0 eth0 wlan0; do
    if [[ -d "/sys/class/net/${iface}" ]]; then
      echo "$iface"
      return
    fi
  done
  echo "eth0"
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

yarbo_matter_make_storage_readable() {
  local name
  name="$(yarbo_matter_name)"
  timeout 8 docker exec "$name" sh -c 'chmod a+r /data/*.json /data/*.json.backup /data/*.ini 2>/dev/null; chmod a+X /data' >/dev/null 2>&1 \
    || timeout 8 sudo -n docker exec "$name" sh -c 'chmod a+r /data/*.json /data/*.json.backup /data/*.ini 2>/dev/null; chmod a+X /data' >/dev/null 2>&1 \
    || true
  chmod a+rX "${ROOT}/data/matter-server" 2>/dev/null || true
  chmod a+r "${ROOT}/data/matter-server/"*.json "${ROOT}/data/matter-server/"*.json.backup "${ROOT}/data/matter-server/"*.ini 2>/dev/null || true
}

yarbo_matter_prefer_shared() {
  if ! yarbo_docker inspect matter-server >/dev/null 2>&1; then
    return 1
  fi
  local name
  name="$(yarbo_matter_name)"
  yarbo_docker stop "$name" >/dev/null 2>&1 || true
  yarbo_docker start matter-server >/dev/null 2>&1 || true
  local i
  for i in $(seq 1 40); do
    if yarbo_matter_port_up; then
      yarbo_matter_write_status "done" "Matter server is running"
      return 0
    fi
    sleep 1
  done
  return 1
}


yarbo_matter_tailscale_shield() {
  local iface
  # Hide Tailscale IPv6 only. Stopping the tunnel rewrites DNS and withdraws
  # subnet routes, which makes every UniFi device look disconnected.
  for iface in tailscale0 tailscale1; do
    if [[ -d "/sys/class/net/${iface}" ]]; then
      yarbo_root sysctl -w "net.ipv6.conf.${iface}.accept_ra=0" >/dev/null 2>&1 || true
      yarbo_root sysctl -w "net.ipv6.conf.${iface}.disable_ipv6=1" >/dev/null 2>&1 || true
    fi
  done
  # Drop leftover Tailscale ULA routes (destination first, then full line).
  ip -6 route show 2>/dev/null | while IFS= read -r line; do
    case "$line" in
      *fd7a:115c:a1e0:*|*dev\ tailscale*)
        dest="${line%% *}"
        if [[ -n "$dest" ]]; then
          yarbo_root ip -6 route del "$dest" >/dev/null 2>&1 || true
        fi
        # shellcheck disable=SC2086
        yarbo_root ip -6 route del $line >/dev/null 2>&1 || true
        ;;
    esac
  done
  yarbo_matter_ensure_ipv6_route
}

yarbo_matter_tailscale_unshield() {
  local iface
  for iface in tailscale0 tailscale1; do
    if [[ -d "/sys/class/net/${iface}" ]]; then
      yarbo_root sysctl -w "net.ipv6.conf.${iface}.disable_ipv6=0" >/dev/null 2>&1 || true
    fi
  done
}

# CHIP's mDNS binds UDP 5353. avahi-daemon on the host takes those replies,
# so the panel can see _matterc while Matter Server discovery times out.
yarbo_matter_mdns_quiesce() {
  local was=0
  if pgrep -x avahi-daemon >/dev/null 2>&1; then
    was=1
  fi
  mkdir -p /run
  if [[ "$was" == "1" ]]; then
    echo 1 > /run/yarbo-matter-avahi-was-up 2>/dev/null || echo 1 > "${ROOT}/data/yarbo-matter-avahi-was-up"
  fi
  yarbo_root systemctl stop avahi-daemon >/dev/null 2>&1 || true
  yarbo_root systemctl stop avahi-daemon.socket >/dev/null 2>&1 || true
  yarbo_root killall avahi-daemon >/dev/null 2>&1 || true
}

yarbo_matter_mdns_restore() {
  local flag="/run/yarbo-matter-avahi-was-up"
  if [[ ! -f "$flag" && -f "${ROOT}/data/yarbo-matter-avahi-was-up" ]]; then
    flag="${ROOT}/data/yarbo-matter-avahi-was-up"
  fi
  if [[ -f "$flag" ]]; then
    yarbo_root systemctl start avahi-daemon >/dev/null 2>&1 || true
    rm -f /run/yarbo-matter-avahi-was-up "${ROOT}/data/yarbo-matter-avahi-was-up" 2>/dev/null || true
  fi
}


yarbo_matter_thread_route_add() {
  # Usage: thread-route-add <prefix> <via-fe80> <iface>
  local prefix="${1:-}" via="${2:-}" iface="${3:-}"
  if [[ -z "$prefix" || -z "$via" || -z "$iface" ]]; then
    echo "usage: thread-route-add <prefix> <via> <iface>" >&2
    return 1
  fi
  case "$prefix" in
    fd7a:115c:a1e0:*) echo "refusing Tailscale ULA" >&2; return 1 ;;
  esac
  case "$via" in
    fe80:*) ;;
    *) echo "via must be fe80::" >&2; return 1 ;;
  esac
  yarbo_root ip -6 route replace "$prefix" via "$via" dev "$iface" metric 100
}

yarbo_matter_router_solicit() {
  # Usage: router-solicit <iface>
  # Sends an ICMPv6 router solicitation as root and prints JSON.
  local iface="${1:-}"
  if [[ -z "$iface" || ! "$iface" =~ ^[A-Za-z0-9._:-]+$ ]]; then
    echo "usage: router-solicit <iface>" >&2
    return 1
  fi
  python3 "${ROOT}/scripts/matter_agent.py" --solicit-ra "$iface"
}

yarbo_matter_prefix_advertise() {
  # Usage: prefix-advertise <iface> <prefix/64> [fe80 hop]
  # Tells the HomePod the prefix is a route back to this Pi.
  local iface="${1:-}" prefix="${2:-}" hop="${3:-}"
  if [[ -z "$iface" || -z "$prefix" || ! "$iface" =~ ^[A-Za-z0-9._:-]+$ ]]; then
    echo "usage: prefix-advertise <iface> <prefix/64> [fe80-hop]" >&2
    return 1
  fi
  case "$prefix" in
    fd7a:115c:a1e0:*) echo "refusing Tailscale ULA" >&2; return 1 ;;
    */64) ;;
    *) echo "prefix must be /64" >&2; return 1 ;;
  esac
  case "$hop" in
    ""|fe80:*) ;;
    *) echo "hop must be fe80::" >&2; return 1 ;;
  esac
  if [[ -n "$hop" ]]; then
    python3 "${ROOT}/scripts/matter_agent.py" --advertise-prefix "$iface" "$prefix" "$hop"
  else
    python3 "${ROOT}/scripts/matter_agent.py" --advertise-prefix "$iface" "$prefix"
  fi
}

yarbo_matter_setup() {
  mkdir -p "${ROOT}/data/matter-server"
  yarbo_matter_write_status "running" "Setting up the Matter server"
  # Refresh root helper first so ipv6-route / Thread RIO sysctls are not stale.
  yarbo_matter_install_wrapper
  yarbo_matter_enable_ipv6
  yarbo_matter_make_storage_readable

  if yarbo_matter_prefer_shared; then
    return 0
  fi

  # Port up alone is not enough — CHIP may be missing --primary-interface for mDNS.
  if yarbo_matter_port_up; then
    local name_early iface_early args_early have_early
    name_early="$(yarbo_matter_name)"
    iface_early="$(yarbo_matter_primary_interface)"
    if yarbo_docker inspect "$name_early" >/dev/null 2>&1; then
      args_early="$(yarbo_docker inspect -f '{{range .Args}}{{.}} {{end}}' "$name_early" 2>/dev/null || true)"
      args_early+=" $(yarbo_docker inspect -f '{{range .Config.Cmd}}{{.}} {{end}}' "$name_early" 2>/dev/null || true)"
      have_early="$(printf '%s' "$args_early" | tr -s ' ')"
      if printf '%s' " $have_early " | grep -q " --primary-interface ${iface_early} "; then
        yarbo_matter_write_status "done" "Matter server is running"
        return 0
      fi
    elif ! yarbo_docker inspect "$name_early" >/dev/null 2>&1; then
      # Shared / foreign listener on 5580 — do not tear down.
      yarbo_matter_write_status "done" "Matter server is running"
      return 0
    fi
  fi

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
  local iface
  iface="$(yarbo_matter_primary_interface)"
  if yarbo_docker inspect "$name" >/dev/null 2>&1; then
    local args have
    # CHIP flags are usually in Args (after the image entrypoint), not only Config.Cmd.
    args="$(yarbo_docker inspect -f '{{range .Args}}{{.}} {{end}}' "$name" 2>/dev/null || true)"
    args+=" $(yarbo_docker inspect -f '{{range .Config.Cmd}}{{.}} {{end}}' "$name" 2>/dev/null || true)"
    have="$(printf '%s' "$args" | tr -s ' ')"
    if printf '%s' " $have " | grep -q " --primary-interface ${iface} "; then
      yarbo_docker start "$name" >/dev/null 2>&1 || true
    elif printf '%s' " $have " | grep -q " --primary-interface "; then
      # Explicit wrong NIC — recreate for Thread/mDNS discovery.
      yarbo_docker stop "$name" >/dev/null 2>&1 || true
      yarbo_docker rm "$name" >/dev/null 2>&1 || true
    elif [[ -z "$have" ]] && yarbo_matter_port_up; then
      # Inspect returned empty Args but server listens — leave alone (avoid drop mid-pair).
      yarbo_docker start "$name" >/dev/null 2>&1 || true
    else
      # Flag missing while Args are readable — recreate so CHIP binds the LAN NIC.
      yarbo_docker stop "$name" >/dev/null 2>&1 || true
      yarbo_docker rm "$name" >/dev/null 2>&1 || true
    fi
  fi
  if ! yarbo_docker inspect "$name" >/dev/null 2>&1; then
    yarbo_docker run -d \
      --name "$name" \
      --restart unless-stopped \
      --security-opt apparmor=unconfined \
      --network host \
      -v "${ROOT}/data/matter-server:/data" \
      "$image" \
      --storage-path /data \
      --paa-root-cert-dir /data/credentials \
      --primary-interface "$iface" >/dev/null 2>&1 || true
  fi

  local i
  for i in $(seq 1 40); do
    if yarbo_matter_port_up; then
      yarbo_matter_make_storage_readable
      yarbo_matter_write_status "done" "Matter server is running"
      return 0
    fi
    sleep 1
  done
  yarbo_matter_write_status "failed" "" "Docker started but the Matter server is not listening yet. Wait a minute, then tap Set up Matter server."
  return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  cmd=""
  if [[ -n "${1:-}" && -d "${1}/scripts" ]]; then
    ROOT="$1"
    cmd="${2:-}"
  elif [[ "${1:-}" == "ipv6-route" || "${1:-}" == "airplay-deps" || "${1:-}" == "airplay-quiesce" || "${1:-}" == "tailscale-shield" || "${1:-}" == "tailscale-unshield" || "${1:-}" == "thread-route-add" || "${1:-}" == "router-solicit" || "${1:-}" == "prefix-advertise" || "${1:-}" == "mdns-quiesce" || "${1:-}" == "mdns-restore" ]]; then
    cmd="$1"
    if [[ -n "${YARBO_ROOT:-}" && -d "${YARBO_ROOT}/scripts" ]]; then
      ROOT="$YARBO_ROOT"
    else
      ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
    fi
  elif [[ -n "${YARBO_ROOT:-}" && -d "${YARBO_ROOT}/scripts" ]]; then
    ROOT="$YARBO_ROOT"
  else
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  fi
  if [[ "$cmd" == "ipv6-route" || "${1:-}" == "ipv6-route" ]]; then
    # Always copy latest script first — otherwise an old wrapper never gains Thread RIO sysctls.
    yarbo_matter_install_wrapper
    # Re-exec freshly installed wrapper once so this process is not the stale copy.
    if [[ -x /usr/local/sbin/yarbo-matter-setup && "${YARBO_MATTER_IPV6_REEXEC:-}" != "1" ]]; then
      YARBO_MATTER_IPV6_REEXEC=1 exec /usr/local/sbin/yarbo-matter-setup "$ROOT" ipv6-route
    fi
    yarbo_matter_enable_ipv6
    exit 0
  fi
  if [[ "$cmd" == "thread-route-add" ]]; then
    yarbo_matter_install_wrapper
    # Invoked as: setup <ROOT> thread-route-add <prefix> <via> <iface>
    #         or: setup thread-route-add <prefix> <via> <iface>
    if [[ -n "${1:-}" && -d "${1}/scripts" && "${2:-}" == "thread-route-add" ]]; then
      yarbo_matter_thread_route_add "${3:-}" "${4:-}" "${5:-}"
    elif [[ "${1:-}" == "thread-route-add" ]]; then
      yarbo_matter_thread_route_add "${2:-}" "${3:-}" "${4:-}"
    else
      yarbo_matter_thread_route_add "${1:-}" "${2:-}" "${3:-}"
    fi
    exit $?
  fi
  if [[ "$cmd" == "router-solicit" ]]; then
    yarbo_matter_install_wrapper
    if [[ -n "${1:-}" && -d "${1}/scripts" && "${2:-}" == "router-solicit" ]]; then
      yarbo_matter_router_solicit "${3:-}"
    elif [[ "${1:-}" == "router-solicit" ]]; then
      yarbo_matter_router_solicit "${2:-}"
    else
      yarbo_matter_router_solicit "${1:-}"
    fi
    exit $?
  fi
  if [[ "$cmd" == "prefix-advertise" ]]; then
    yarbo_matter_install_wrapper
    if [[ -n "${1:-}" && -d "${1}/scripts" && "${2:-}" == "prefix-advertise" ]]; then
      yarbo_matter_prefix_advertise "${3:-}" "${4:-}" "${5:-}"
    elif [[ "${1:-}" == "prefix-advertise" ]]; then
      yarbo_matter_prefix_advertise "${2:-}" "${3:-}" "${4:-}"
    else
      yarbo_matter_prefix_advertise "${1:-}" "${2:-}" "${3:-}"
    fi
    exit $?
  fi
  if [[ "$cmd" == "mdns-quiesce" ]]; then
    yarbo_matter_install_wrapper
    yarbo_matter_mdns_quiesce
    exit 0
  fi
  if [[ "$cmd" == "mdns-restore" ]]; then
    yarbo_matter_install_wrapper
    yarbo_matter_mdns_restore
    exit 0
  fi
  if [[ "$cmd" == "tailscale-shield" ]]; then
    yarbo_matter_install_wrapper
    yarbo_matter_tailscale_shield
    exit 0
  fi
  if [[ "$cmd" == "tailscale-unshield" ]]; then
    yarbo_matter_install_wrapper
    yarbo_matter_tailscale_unshield
    exit 0
  fi
  if [[ "$cmd" == "airplay-deps" ]]; then
    # Refresh this wrapper from the panel tree, then install AirPlay apt packages.
    yarbo_matter_install_wrapper
    # shellcheck source=scripts/lib/panel_airplay.sh
    source "${ROOT}/scripts/lib/panel_airplay.sh"
    yarbo_install_airplay_deps
    exit $?
  fi
  if [[ "$cmd" == "airplay-quiesce" ]]; then
    # Start Avahi + stop/mask packaged shairport-sync (no apt). Used by Start AirPlay.
    yarbo_matter_install_wrapper
    # shellcheck source=scripts/lib/panel_airplay.sh
    source "${ROOT}/scripts/lib/panel_airplay.sh"
    yarbo_airplay_quiesce_as_root
    exit $?
  fi
  # Always refresh the root helper even when Matter is already up, so new
  # subcommands (airplay-deps / airplay-quiesce) land before the next update step uses them.
  cd "$ROOT"
  yarbo_matter_install_wrapper
  yarbo_matter_setup
fi
