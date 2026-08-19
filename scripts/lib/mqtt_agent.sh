#!/usr/bin/env bash
# Shared MQTT agent process helpers. Sourced by panel.sh and update.sh.

yarbo_mqtt_agent_port() {
  echo "${YARBO_MQTT_AGENT_PORT:-8765}"
}

# Stop leftover mqtt_agent.py / .php processes, including setsid orphans
# that can survive a panel restart and keep serving old command logic.
yarbo_stop_mqtt_agent() {
  local port="${1:-$(yarbo_mqtt_agent_port)}"
  local pids=""

  if command -v lsof >/dev/null 2>&1; then
    pids="$(lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null || true)"
  fi

  if [[ -n "$pids" ]]; then
    # shellcheck disable=SC2086
    kill $pids 2>/dev/null || true
    sleep 0.3
    # shellcheck disable=SC2086
    kill -9 $pids 2>/dev/null || true
  elif command -v fuser >/dev/null 2>&1; then
    fuser -k "${port}/tcp" >/dev/null 2>&1 || true
  fi

  pkill -f '[s]cripts/mqtt_agent.py' 2>/dev/null || true
  pkill -f '[s]cripts/mqtt_agent.php' 2>/dev/null || true
  sleep 0.2
}
