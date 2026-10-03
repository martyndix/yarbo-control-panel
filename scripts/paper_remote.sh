#!/usr/bin/env bash
# Paper tablet remote access: path-limited PHP gate + optional Tailscale Funnel.
# Tablets never run Tailscale. This runs on the panel host (Pi).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CMD="${1:-status}"
GATE_PORT="${YARBO_PAPER_REMOTE_PORT:-8089}"
PANEL_PORT="${YARBO_PANEL_PORT:-8080}"
PHP_BIN="${YARBO_PHP_BIN:-$(command -v php || true)}"
PID_FILE="${ROOT}/data/paper-remote-gate.pid"
LOG_FILE="${ROOT}/data/paper-remote.log"
ROUTER="${ROOT}/scripts/paper_remote_router.php"

mkdir -p "${ROOT}/data"

json_escape() {
  php -r 'echo json_encode($argv[1] ?? "");' "$1"
}

emit() {
  echo "$1"
}

tailscale_bin() {
  command -v tailscale 2>/dev/null || true
}

gate_pid() {
  if [[ -f "$PID_FILE" ]]; then
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      echo "$pid"
      return 0
    fi
  fi
  pgrep -f '[p]aper_remote_router.php' 2>/dev/null | head -n1 || true
}

gate_listening() {
  if command -v ss >/dev/null 2>&1; then
    ss -lnt 2>/dev/null | grep -q ":${GATE_PORT} " && return 0
  fi
  python3 - "$GATE_PORT" <<'PY' 2>/dev/null && return 0
import socket, sys
s = socket.socket()
s.settimeout(0.2)
try:
    s.connect(("127.0.0.1", int(sys.argv[1])))
    sys.exit(0)
except Exception:
    sys.exit(1)
finally:
    s.close()
PY
  return 1
}

start_gate() {
  if gate_listening; then
    emit '{"ok":true,"gate":true,"message":"Remote gate already listening"}'
    return 0
  fi
  if [[ -z "$PHP_BIN" ]]; then
    emit '{"ok":false,"error":"php not found"}'
    return 1
  fi
  if [[ ! -f "$ROUTER" ]]; then
    emit '{"ok":false,"error":"paper_remote_router.php missing"}'
    return 1
  fi
  YARBO_PANEL_PORT="${PANEL_PORT}" "$PHP_BIN" -d max_execution_time=180 -S "127.0.0.1:${GATE_PORT}" "$ROUTER" >>"$LOG_FILE" 2>&1 &
  echo $! > "$PID_FILE"
  sleep 0.3
  if gate_listening; then
    emit '{"ok":true,"gate":true,"message":"Remote gate listening on 127.0.0.1:'"${GATE_PORT}"'"}'
    return 0
  fi
  emit '{"ok":false,"error":"Remote gate did not start. See data/paper-remote.log"}'
  return 1
}

stop_gate() {
  local pid
  pid="$(gate_pid)"
  if [[ -n "$pid" ]]; then
    kill "$pid" 2>/dev/null || true
    sleep 0.2
    kill -9 "$pid" 2>/dev/null || true
  fi
  pkill -f '[p]aper_remote_router.php' 2>/dev/null || true
  rm -f "$PID_FILE"
  emit '{"ok":true,"gate":false}'
}

ts_json_field() {
  local key="$1"
  python3 - "$key" <<'PY' 2>/dev/null || true
import json, sys
key = sys.argv[1]
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
cur = data
for part in key.split("."):
    if isinstance(cur, dict) and part in cur:
        cur = cur[part]
    else:
        cur = ""
        break
if cur is True:
    print("true")
elif cur is False or cur is None:
    print("")
else:
    print(cur)
PY
}

status_json() {
  local installed="false"
  local logged_in="false"
  local auth_url=""
  local dns_name=""
  local funnel_on="false"
  local needs_acl="false"
  local error=""
  local ts
  ts="$(tailscale_bin)"
  if [[ -n "$ts" ]]; then
    installed="true"
    local st
    st="$("$ts" status --json 2>/dev/null || true)"
    if [[ -n "$st" ]]; then
      local backend
      backend="$(printf '%s' "$st" | ts_json_field BackendState)"
      auth_url="$(printf '%s' "$st" | ts_json_field AuthURL)"
      dns_name="$(printf '%s' "$st" | ts_json_field Self.DNSName)"
      dns_name="${dns_name%.}"
      if [[ "$backend" == "Running" ]]; then
        logged_in="true"
      fi
    fi
    local fs
    fs="$("$ts" funnel status 2>/dev/null || true)"
    if printf '%s' "$fs" | grep -qiE 'https://|Funnel on|:443'; then
      funnel_on="true"
    fi
    if printf '%s' "$fs" | grep -qiE 'Access denied|funnel.*not enabled|node attribute'; then
      needs_acl="true"
      error="Enable Funnel in the Tailscale admin console, then click Start Funnel."
    fi
  fi
  local gate="false"
  if gate_listening; then
    gate="true"
  fi
  printf '{'
  printf '"ok":true,'
  printf '"installed":%s,' "$installed"
  printf '"logged_in":%s,' "$logged_in"
  printf '"auth_url":%s,' "$(json_escape "$auth_url")"
  printf '"dns_name":%s,' "$(json_escape "$dns_name")"
  printf '"funnel_on":%s,' "$funnel_on"
  printf '"needs_funnel_acl":%s,' "$needs_acl"
  printf '"gate":%s,' "$gate"
  printf '"gate_port":%s,' "$GATE_PORT"
  printf '"error":%s,' "$(json_escape "$error")"
  printf '"sudo_hint":%s' "$(json_escape "sudo ${ROOT}/scripts/paper_remote.sh install")"
  printf '}\n'
}

maybe_sudo() {
  if [[ "${EUID}" -eq 0 ]]; then
    return 0
  fi
  if sudo -n true 2>/dev/null; then
    exec sudo -n "$0" "$CMD"
  fi
  emit "{\"ok\":false,\"error\":\"Needs sudo. On the Pi run: sudo ${ROOT}/scripts/paper_remote.sh ${CMD}\",\"sudo_hint\":\"sudo ${ROOT}/scripts/paper_remote.sh ${CMD}\"}"
  return 1
}

install_tailscale() {
  maybe_sudo || return 1
  if [[ -n "$(tailscale_bin)" ]]; then
    emit '{"ok":true,"installed":true,"message":"Tailscale is already installed"}'
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    emit '{"ok":false,"error":"curl is required to install Tailscale"}'
    return 1
  fi
  if curl -fsSL https://tailscale.com/install.sh | sh >>"$LOG_FILE" 2>&1; then
    emit '{"ok":true,"installed":true,"message":"Tailscale installed. Click Log in next."}'
    return 0
  fi
  emit '{"ok":false,"error":"Tailscale install failed. See data/paper-remote.log or run the command over SSH."}'
  return 1
}

tailscale_up() {
  local ts
  ts="$(tailscale_bin)"
  if [[ -z "$ts" ]]; then
    emit '{"ok":false,"error":"Install Tailscale first"}'
    return 1
  fi
  if [[ "${EUID}" -ne 0 ]] && ! "$ts" status >/dev/null 2>&1; then
    if sudo -n true 2>/dev/null; then
      exec sudo -n "$0" up
    fi
  fi
  # timeout so we return an auth URL instead of hanging the Settings request
  "$ts" up --timeout=8s >>"$LOG_FILE" 2>&1 || true
  status_json
}

funnel_on() {
  local ts
  ts="$(tailscale_bin)"
  if [[ -z "$ts" ]]; then
    emit '{"ok":false,"error":"Install Tailscale first"}'
    return 1
  fi
  start_gate >/dev/null || true
  local out
  set +e
  out="$("$ts" funnel --bg "${GATE_PORT}" 2>&1)"
  local code=$?
  set -e
  printf '%s\n' "$out" >>"$LOG_FILE"
  if [[ $code -ne 0 ]]; then
    if printf '%s' "$out" | grep -qiE 'Access denied|not enabled|node attribute|Funnel is not'; then
      emit '{"ok":false,"needs_funnel_acl":true,"error":"Enable Funnel for this tailnet (Tailscale admin console → DNS / Access controls), then click Start Funnel again."}'
      return 1
    fi
    emit "{\"ok\":false,\"error\":$(json_escape "$(printf '%s' "$out" | tr '\n' ' ' | cut -c1-240)")}"
    return 1
  fi
  status_json
}

funnel_off() {
  local ts
  ts="$(tailscale_bin)"
  if [[ -n "$ts" ]]; then
    "$ts" funnel reset >>"$LOG_FILE" 2>&1 || true
  fi
  emit '{"ok":true,"funnel_on":false}'
}

ensure() {
  start_gate >/dev/null || true
  local ts
  ts="$(tailscale_bin)"
  if [[ -n "$ts" ]]; then
    if "$ts" status >/dev/null 2>&1; then
      "$ts" funnel --bg "${GATE_PORT}" >>"$LOG_FILE" 2>&1 || true
    fi
  fi
  status_json
}

case "$CMD" in
  status) status_json ;;
  install) install_tailscale ;;
  up) tailscale_up ;;
  funnel-on) funnel_on ;;
  funnel-off) funnel_off ;;
  start-gate) start_gate ;;
  stop-gate) stop_gate ;;
  ensure) ensure ;;
  *)
    emit '{"ok":false,"error":"Unknown command"}'
    exit 1
    ;;
esac
