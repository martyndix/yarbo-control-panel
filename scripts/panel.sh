#!/usr/bin/env bash
# Persistent MQTT agent + PHP panel.
# Used by ./scripts/dev.sh (Mac/manual) and systemd (Pi).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${YARBO_PANEL_PORT:-8080}"
HOST="${YARBO_PANEL_HOST:-0.0.0.0}"
AGENT_PORT="${YARBO_MQTT_AGENT_PORT:-8765}"
PHP_BIN="${YARBO_PHP_BIN:-$(command -v php)}"

if [[ -z "$PHP_BIN" ]]; then
  echo "ERROR: php not found on PATH" >&2
  exit 1
fi

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

echo "==> Starting panel on http://${HOST}:${PORT}"
echo "    Keep this process running. Hard-refresh the browser after start."
echo "    Close the official Yarbo app while testing controls/drive."
"$PHP_BIN" -d max_execution_time=120 -S "${HOST}:${PORT}" -t "${ROOT}/public"
