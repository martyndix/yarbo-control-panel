#!/usr/bin/env bash
# Local development: persistent MQTT agent + PHP panel
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${YARBO_PANEL_PORT:-8080}"
AGENT_PORT="${YARBO_MQTT_AGENT_PORT:-8765}"

cleanup() {
  if [[ -n "${AGENT_PID:-}" ]]; then
    kill "${AGENT_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

pick_agent() {
  local venv_py="$ROOT/.venv/bin/python"
  if [[ -x "$venv_py" ]] && "$venv_py" -c "import yarbo" 2>/dev/null; then
    echo "$venv_py" scripts/mqtt_agent.py
    return
  fi
  if command -v python3 >/dev/null 2>&1 && python3 -c "import yarbo" 2>/dev/null; then
    echo python3 scripts/mqtt_agent.py
    return
  fi
  echo php scripts/mqtt_agent.php
}

AGENT_CMD=($(pick_agent))

echo "==> Starting MQTT agent on 127.0.0.1:${AGENT_PORT}"
echo "    ${AGENT_CMD[*]}"
YARBO_MQTT_AGENT_PORT="${AGENT_PORT}" "${AGENT_CMD[@]}" &
AGENT_PID=$!
sleep 1.2

if ! kill -0 "${AGENT_PID}" 2>/dev/null; then
  echo "ERROR: MQTT agent failed to start" >&2
  exit 1
fi

echo "==> Starting panel on http://0.0.0.0:${PORT}"
echo "    Keep this terminal open. Hard-refresh the browser after start."
echo "    Close the official Yarbo app while testing controls/drive."
php -d max_execution_time=120 -S "0.0.0.0:${PORT}" -t public
