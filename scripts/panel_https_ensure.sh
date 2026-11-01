#!/usr/bin/env bash
# Ensure the panel HTTPS proxy is running (idempotent). Used by panel.sh and Settings.
# Prints one JSON object on stdout.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/panel_https.sh
source "${ROOT}/scripts/lib/panel_https.sh"

PORT_HTTP="${YARBO_PANEL_PORT:-8080}"
PORT_HTTPS="$(yarbo_panel_https_port)"
LOG="${ROOT}/data/panel-https.log"
mkdir -p "${ROOT}/data"

json_out() {
  php -r 'echo json_encode([
    "ok" => $argv[1] === "1",
    "listening" => $argv[2] === "1",
    "enabled" => $argv[3] === "1",
    "https_port" => (int) $argv[4],
    "http_port" => (int) $argv[5],
    "pid" => $argv[6] !== "" ? (int) $argv[6] : null,
    "message" => $argv[7] !== "" ? $argv[7] : null,
    "error" => $argv[8] !== "" ? $argv[8] : null,
    "log_tail" => $argv[9] !== "" ? $argv[9] : null,
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$1" "$2" "$3" "$4" "$5" "$6" "$7" "$8" "$9"
}

log_tail() {
  if [[ -f "$LOG" ]]; then
    tail -n 25 "$LOG" | tr '\n' '|' | sed 's/|$//'
  else
    echo ""
  fi
}

https_listening() {
  if command -v curl >/dev/null 2>&1; then
    curl -sk --max-time 2 -o /dev/null "https://127.0.0.1:${PORT_HTTPS}/" && return 0
  fi
  php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $s, 0.4) ? 0 : 1);' "$PORT_HTTPS" 2>/dev/null
}

proxy_pid() {
  pgrep -f '[s]cripts/panel_https_proxy.py' 2>/dev/null | head -n1 || true
}

open_firewall() {
  if command -v ufw >/dev/null 2>&1; then
    if sudo -n ufw status 2>/dev/null | grep -qi 'Status: active'; then
      sudo -n ufw allow "${PORT_HTTPS}/tcp" comment 'Yarbo panel HTTPS' >/dev/null 2>&1 || true
    fi
  fi
}

ENABLED=1
if ! yarbo_panel_https_enabled; then
  ENABLED=0
  json_out 1 0 0 "$PORT_HTTPS" "$PORT_HTTP" "" "HTTPS is disabled (YARBO_PANEL_HTTPS=0)" "" "$(log_tail)"
  exit 0
fi

open_firewall

if https_listening; then
  pid="$(proxy_pid)"
  json_out 1 1 1 "$PORT_HTTPS" "$PORT_HTTP" "$pid" "HTTPS is listening on port ${PORT_HTTPS}" "" "$(log_tail)"
  exit 0
fi

# Not listening — (re)start proxy.
pkill -f '[s]cripts/panel_https_proxy.py' 2>/dev/null || true
sleep 0.2

py="${ROOT}/.venv/bin/python"
if [[ ! -x "$py" ]]; then
  py="$(command -v python3 || true)"
fi
if [[ -z "$py" ]]; then
  json_out 0 0 1 "$PORT_HTTPS" "$PORT_HTTP" "" "" "python3 not found" "$(log_tail)"
  exit 0
fi

pair="$(yarbo_panel_ensure_cert "$ROOT" || true)"
if [[ -z "$pair" || "$pair" != *"|"* ]]; then
  json_out 0 0 1 "$PORT_HTTPS" "$PORT_HTTP" "" "" "Could not create TLS certificate (need openssl)" "$(log_tail)"
  exit 0
fi
crt="${pair%%|*}"
key="${pair##*|}"

: >>"$LOG"
echo "==> panel_https_ensure $(date -u +%Y-%m-%dT%H:%M:%SZ) starting proxy" >>"$LOG"
nohup "$py" "${ROOT}/scripts/panel_https_proxy.py" \
  --listen-host "${YARBO_PANEL_HTTPS_HOST:-0.0.0.0}" \
  --listen-port "$PORT_HTTPS" \
  --upstream-host "${YARBO_PANEL_HTTPS_UPSTREAM:-127.0.0.1}" \
  --upstream-port "$PORT_HTTP" \
  --cert "$crt" \
  --key "$key" \
  --no-wait-upstream >>"$LOG" 2>&1 &
pid=$!
disown "$pid" 2>/dev/null || true
sleep 0.8

if https_listening; then
  json_out 1 1 1 "$PORT_HTTPS" "$PORT_HTTP" "$(proxy_pid)" "HTTPS started on port ${PORT_HTTPS}" "" "$(log_tail)"
  exit 0
fi

err="HTTPS proxy did not start on port ${PORT_HTTPS}"
if ! kill -0 "$pid" 2>/dev/null; then
  err="${err} (process exited — see log)"
fi
json_out 0 0 1 "$PORT_HTTPS" "$PORT_HTTP" "" "" "$err" "$(log_tail)"
exit 0
