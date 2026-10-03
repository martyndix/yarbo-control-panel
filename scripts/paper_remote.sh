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
if ! touch "$LOG_FILE" 2>/dev/null; then
  LOG_FILE="/tmp/yarbo-paper-remote.log"
fi

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
    if ss -lnt 2>/dev/null | grep -q ":${GATE_PORT} "; then
      return 0
    fi
  fi
  if python3 - "$GATE_PORT" <<'PY' 2>/dev/null; then
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
    return 0
  fi
  return 1
}

append_log() {
  printf '%s\n' "$1" >>"$LOG_FILE" 2>/dev/null || true
}

run_with_timeout() {
  if command -v timeout >/dev/null 2>&1; then
    timeout 25 "$@"
  else
    "$@"
  fi
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
  set +e
  YARBO_PANEL_PORT="${PANEL_PORT}" "$PHP_BIN" -d max_execution_time=180 -S "127.0.0.1:${GATE_PORT}" "$ROUTER" >>"$LOG_FILE" 2>&1 &
  local php_pid=$!
  set -e
  echo "$php_pid" > "$PID_FILE" 2>/dev/null || true
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
  php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($d)) {
      exit;
    }
    $cur = $d;
    foreach (explode(".", $argv[1]) as $part) {
      if (!is_array($cur) || !array_key_exists($part, $cur)) {
        exit;
      }
      $cur = $cur[$part];
    }
    if ($cur === true) {
      echo "true";
    } elseif ($cur === false || $cur === null) {
      echo "";
    } elseif (is_scalar($cur)) {
      echo (string) $cur;
    }
  ' "$key"
}

first_https() {
  printf '%s' "$1" | grep -oE 'https://[^[:space:]"'\'']+' | head -n1 || true
}

status_json() {
  local installed="false"
  local logged_in="false"
  local auth_url=""
  local dns_name=""
  local funnel_on="false"
  local needs_acl="false"
  local error=""
  local backend=""
  local funnel_enable_url=""
  local ts
  ts="$(tailscale_bin)"
  if [[ -n "$ts" ]]; then
    installed="true"
    local st
    st="$("$ts" status --json 2>/dev/null || true)"
    if [[ -n "$st" ]]; then
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
    if printf '%s' "$fs" | grep -qiE 'Access denied|funnel.*not enabled|node attribute|Add Funnel'; then
      needs_acl="true"
      error="Funnel is not a General access rule. Open Access controls, click JSON editor in the left sidebar, add nodeAttrs funnel, Save. Then Start Funnel again."
    fi
    if [[ "$funnel_on" != "true" ]]; then
      funnel_enable_url="$(first_https "$fs")"
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
  printf '"backend":%s,' "$(json_escape "$backend")"
  printf '"auth_url":%s,' "$(json_escape "$auth_url")"
  printf '"dns_name":%s,' "$(json_escape "$dns_name")"
  printf '"funnel_on":%s,' "$funnel_on"
  printf '"needs_funnel_acl":%s,' "$needs_acl"
  printf '"funnel_enable_url":%s,' "$(json_escape "$funnel_enable_url")"
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
  local out
  set +e
  out="$("$ts" up --timeout=8s 2>&1)"
  set -e
  printf '%s\n' "$out" >>"$LOG_FILE"
  local from_out
  from_out="$(first_https "$out")"
  local st
  st="$(status_json)"
  if [[ -n "$from_out" ]]; then
    st="$(printf '%s' "$st" | php -r '
      $j = json_decode(stream_get_contents(STDIN), true);
      if (!is_array($j)) {
        exit;
      }
      if (($j["auth_url"] ?? "") === "") {
        $j["auth_url"] = $argv[1];
      }
      echo json_encode($j) . "\n";
    ' "$from_out")"
  fi
  php -r '
    $j = json_decode($argv[1], true);
    if (!is_array($j)) {
      $j = [];
    }
    $cli = trim($argv[2]);
    $auth = trim((string) ($j["auth_url"] ?? ""));
    $logged = !empty($j["logged_in"]);
    if ($logged) {
      $j["ok"] = true;
      $j["message"] = "This Pi is already logged in to Tailscale. Funnel is a second switch. Access controls opens Policies (General access rules) — skip that. Click JSON editor in the left sidebar, add nodeAttrs funnel, Save, then click Start Funnel.";
    } elseif ($auth !== "") {
      $j["ok"] = true;
      $j["message"] = "Open this Tailscale login URL on your phone or computer, then click Log in again: " . $auth;
    } else {
      $j["ok"] = false;
      $j["error"] = "No login page appeared. If this Pi is already logged in, skip to Funnel: Access controls → JSON editor (left sidebar) → add nodeAttrs funnel. Otherwise run on the Pi: " . (string) ($j["sudo_hint"] ?? "sudo ./scripts/paper_remote.sh up");
      if ($cli !== "") {
        $j["error"] .= " CLI: " . $cli;
      }
    }
    echo json_encode($j) . "\n";
  ' "$st" "$(printf '%s' "$out" | tr '\n' ' ' | cut -c1-180)"
}

funnel_on() {
  set +e
  if [[ "${EUID}" -ne 0 ]] && sudo -n true 2>/dev/null; then
    exec sudo -n "$0" funnel-on
  fi
  local ts
  ts="$(tailscale_bin)"
  if [[ -z "$ts" ]]; then
    emit '{"ok":false,"error":"Install Tailscale first"}'
    return 1
  fi
  start_gate >/dev/null 2>/dev/null
  local target="http://127.0.0.1:${GATE_PORT}"
  local out=""
  local extra=""
  local code=1
  out="$(run_with_timeout "$ts" funnel --bg --yes --https=443 "$target" 2>&1)"
  code=$?
  if [[ $code -ne 0 ]]; then
    extra="$(run_with_timeout "$ts" funnel --bg --yes "$target" 2>&1)"
    code=$?
    out="${out}"$'\n'"${extra}"
  fi
  if [[ $code -ne 0 ]]; then
    extra="$(run_with_timeout "$ts" funnel --bg --yes "${GATE_PORT}" 2>&1)"
    code=$?
    out="${out}"$'\n'"${extra}"
  fi
  append_log "$out"
  local from_out
  from_out="$(first_https "$out")"
  local st
  st="$(status_json)"
  if [[ -z "$st" ]]; then
    st='{"ok":false}'
  fi
  if [[ -n "$from_out" ]]; then
    st="$(printf '%s' "$st" | php -r '
      $j = json_decode(stream_get_contents(STDIN), true);
      if (!is_array($j)) {
        $j = [];
      }
      $j["funnel_enable_url"] = $argv[1];
      echo json_encode($j) . "\n";
    ' "$from_out")"
  fi
  local on
  on="$(printf '%s' "$st" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo !empty($j["funnel_on"]) ? "1" : "0";')"
  if [[ "$on" == "1" ]]; then
    php -r '
      $j = json_decode($argv[1], true);
      if (!is_array($j)) {
        $j = [];
      }
      $j["ok"] = true;
      $j["funnel_on"] = true;
      $j["message"] = "Funnel is on. Tick Allow tablets to reach this panel, then Save remote access.";
      echo json_encode($j) . "\n";
    ' "$st"
    return 0
  fi
  local help
  help=$'Funnel did not start.\n\nOn the Pi run:\nsudo '"${ROOT}"$'/scripts/paper_remote.sh funnel-on\n\nIf nodeAttrs and DNS are already on, that command prints the real Tailscale error (often needs sudo). On DNS, MagicDNS, HTTPS Certificates, and Funnel if that page has a Funnel switch, must be on.'
  if [[ -n "$out" ]]; then
    help="$help"$'\n\n'"CLI: $(printf '%s' "$out" | tr '\n' ' ' | cut -c1-240)"
  elif [[ $code -eq 124 ]]; then
    help="$help"$'\n\n'"CLI timed out waiting for Tailscale."
  fi
  php -r '
    $j = json_decode($argv[1], true);
    if (!is_array($j)) {
      $j = [];
    }
    $j["ok"] = false;
    $j["error"] = $argv[2];
    $j["sudo_hint"] = $argv[3];
    echo json_encode($j) . "\n";
  ' "$st" "$help" "sudo ${ROOT}/scripts/paper_remote.sh funnel-on"
  return 1
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
  start_gate >/dev/null 2>/dev/null || true
  local ts
  ts="$(tailscale_bin)"
  if [[ -n "$ts" ]]; then
    if "$ts" status >/dev/null 2>&1; then
      "$ts" funnel --bg --yes --https=443 "http://127.0.0.1:${GATE_PORT}" >>"$LOG_FILE" 2>&1 || true
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
