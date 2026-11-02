#!/usr/bin/env bash
# Stop / helper for scripts/homepod_agent.py (HomePod AirPlay sender).

yarbo_homepod_agent_port() {
  echo "${YARBO_HOMEPOD_AGENT_PORT:-8767}"
}

yarbo_stop_homepod_agent() {
  local port
  port="$(yarbo_homepod_agent_port)"
  if command -v lsof >/dev/null 2>&1; then
    lsof -tiTCP:"${port}" -sTCP:LISTEN 2>/dev/null | xargs -r kill -TERM 2>/dev/null || true
  fi
  pkill -f '[s]cripts/homepod_agent.py' 2>/dev/null || true
  sleep 0.2
  pkill -KILL -f '[s]cripts/homepod_agent.py' 2>/dev/null || true
}
