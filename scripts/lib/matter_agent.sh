#!/usr/bin/env bash
# Shared Matter agent process helpers. Sourced by panel.sh and update.sh.

yarbo_matter_agent_port() {
  echo "${YARBO_MATTER_AGENT_PORT:-8766}"
}

# Stop leftover matter_agent.py processes, including background orphans that can
# survive a panel restart and keep serving old command logic (no colour).
yarbo_stop_matter_agent() {
  local port="${1:-$(yarbo_matter_agent_port)}"
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

  pkill -f '[s]cripts/matter_agent.py' 2>/dev/null || true
  sleep 0.2
}
