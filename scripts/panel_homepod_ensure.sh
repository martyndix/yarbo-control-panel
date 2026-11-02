#!/usr/bin/env bash
# Ensure pyatv is installed and the HomePod agent is running. JSON on stdout.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/homepod_deps.sh
source "${ROOT}/scripts/lib/homepod_deps.sh"
# shellcheck source=scripts/lib/homepod_agent.sh
source "${ROOT}/scripts/lib/homepod_agent.sh"

LOG="$(yarbo_homepod_log "$ROOT")"
mkdir -p "${ROOT}/data/homepod" "${ROOT}/data"

json_out() {
  php -r 'echo json_encode([
    "ok" => $argv[1] === "1",
    "pyatv" => $argv[2] === "1",
    "agent_running" => $argv[3] === "1",
    "python" => $argv[4] !== "" ? $argv[4] : null,
    "message" => $argv[5] !== "" ? $argv[5] : null,
    "error" => $argv[6] !== "" ? $argv[6] : null,
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$1" "$2" "$3" "$4" "$5" "$6"
}

port_open() {
  local port
  port="$(yarbo_homepod_agent_port)"
  php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $s, 0.25) ? 0 : 1);' "$port" 2>/dev/null
}

if [[ "${YARBO_HOMEPOD:-1}" == "0" || "${YARBO_HOMEPOD:-1}" == "false" || "${YARBO_HOMEPOD:-1}" == "off" ]]; then
  json_out 0 0 0 "" "" "HomePod speakers disabled (YARBO_HOMEPOD=0)"
  exit 0
fi

echo "==> panel_homepod_ensure $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG"

if ! yarbo_ensure_pyatv "$ROOT"; then
  py="$(yarbo_homepod_python "$ROOT" || true)"
  json_out 0 0 0 "${py}" "" \
    "Could not install pyatv. Update the panel again, or check data/homepod-agent.log."
  exit 0
fi

py="$(yarbo_homepod_python "$ROOT")"
# Restart agent so it picks up a freshly installed pyatv (import is at process start).
yarbo_stop_homepod_agent
sleep 0.2
YARBO_HOMEPOD_AGENT_PORT="$(yarbo_homepod_agent_port)" \
  "$py" "${ROOT}/scripts/homepod_agent.py" >>"$LOG" 2>&1 &
disown || true

ok_agent=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if port_open; then
    ok_agent=1
    break
  fi
  sleep 0.15
done

if [[ "$ok_agent" -eq 1 ]]; then
  json_out 1 1 1 "$py" "pyatv ready" ""
else
  json_out 0 1 0 "$py" "" "pyatv installed but HomePod agent did not start — see data/homepod-agent.log"
fi
