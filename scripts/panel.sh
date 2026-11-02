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

# shellcheck source=scripts/lib/mqtt_agent.sh
source "${ROOT}/scripts/lib/mqtt_agent.sh"
# shellcheck source=scripts/lib/matter_agent.sh
source "${ROOT}/scripts/lib/matter_agent.sh"
# shellcheck source=scripts/lib/panel_https.sh
source "${ROOT}/scripts/lib/panel_https.sh"

if [[ -z "$PHP_BIN" ]]; then
  echo "ERROR: php not found on PATH" >&2
  exit 1
fi

cleanup() {
  if [[ -n "${HTTPS_WATCH_PID:-}" ]]; then
    kill "${HTTPS_WATCH_PID}" 2>/dev/null || true
  fi
  if [[ -n "${HTTPS_PROXY_PID:-}" ]]; then
    kill "${HTTPS_PROXY_PID}" 2>/dev/null || true
  fi
  if [[ -n "${PHP_PID:-}" ]]; then
    kill "${PHP_PID}" 2>/dev/null || true
  fi
  if [[ -n "${AGENT_PID:-}" ]]; then
    kill "${AGENT_PID}" 2>/dev/null || true
  fi
  if [[ -n "${VESTABOARD_PID:-}" ]]; then
    pkill -P "${VESTABOARD_PID}" 2>/dev/null || true
    kill "${VESTABOARD_PID}" 2>/dev/null || true
  fi
  if [[ -n "${HOME_AUTOMATIONS_PID:-}" ]]; then
    pkill -P "${HOME_AUTOMATIONS_PID}" 2>/dev/null || true
    kill "${HOME_AUTOMATIONS_PID}" 2>/dev/null || true
  fi
  if [[ -n "${METRICS_PID:-}" ]]; then
    pkill -P "${METRICS_PID}" 2>/dev/null || true
    kill "${METRICS_PID}" 2>/dev/null || true
  fi
  if [[ -n "${MATTER_PID:-}" ]]; then
    kill "${MATTER_PID}" 2>/dev/null || true
  fi
  pkill -f '[s]cripts/matter_agent.py' 2>/dev/null || true
  pkill -f '[s]cripts/panel_https_proxy.py' 2>/dev/null || true
  yarbo_stop_matter_agent 2>/dev/null || true
  pkill -f '[p]aper_remote_router.php' 2>/dev/null || true
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

yarbo_stop_mqtt_agent "${AGENT_PORT}"

echo "==> Starting MQTT agent on 127.0.0.1:${AGENT_PORT}"
echo "    ${AGENT_CMD[*]}"
YARBO_MQTT_AGENT_PORT="${AGENT_PORT}" "${AGENT_CMD[@]}" &
AGENT_PID=$!
if ! yarbo_wait_mqtt_agent "${AGENT_PORT}" 10; then
  if ! kill -0 "${AGENT_PID}" 2>/dev/null; then
    echo "ERROR: MQTT agent failed to start" >&2
    exit 1
  fi
  echo "WARNING: MQTT agent process is up but port ${AGENT_PORT} is not listening yet" >&2
fi

echo "==> Starting Vestaboard Note watcher"
(
  while true; do
    "$PHP_BIN" "${ROOT}/scripts/vestaboard_watch.php" && echo "vestaboard_watch: reloading" || echo "vestaboard_watch: exited, restarting" >&2
    sleep 1
  done
) &
VESTABOARD_PID=$!

echo "==> Starting Home automations"
mkdir -p "${ROOT}/data"
(
  while true; do
    set +e
    "$PHP_BIN" "${ROOT}/scripts/home_automations.php" --root="${ROOT}" >> "${ROOT}/data/home-automations.log" 2>&1
    code=$?
    set -e
    if [[ "$code" -eq 75 ]]; then
      echo "home_automations: already running"
      sleep 5
    elif [[ "$code" -eq 0 ]]; then
      echo "home_automations: reloading"
    else
      echo "home_automations: exited, restarting" >&2
    fi
    sleep 1
  done
) &
HOME_AUTOMATIONS_PID=$!

MATTER_PY="${ROOT}/.venv/bin/python"
if [[ ! -x "$MATTER_PY" ]]; then
  MATTER_PY="$(command -v python3 || true)"
fi
if [[ "$(uname -s)" != "Darwin" && -f "${ROOT}/scripts/lib/matter_server.sh" ]]; then
    echo "==> Ensuring Matter server is running (Home module)"
  (
    set +e
    # shellcheck source=scripts/lib/matter_server.sh
    source "${ROOT}/scripts/lib/matter_server.sh" || exit 0
    yarbo_matter_make_storage_readable
    sudo -n /usr/local/sbin/yarbo-matter-setup "${ROOT}" >/dev/null 2>&1 && exit 0
    yarbo_matter_setup >/dev/null 2>&1 || true
  ) >/dev/null 2>&1 &
fi

if [[ -n "$MATTER_PY" && -f "${ROOT}/scripts/matter_agent.py" ]]; then
  echo "==> Starting Matter agent on 127.0.0.1:${YARBO_MATTER_AGENT_PORT:-8766}"
  mkdir -p "${ROOT}/data"
  yarbo_stop_matter_agent
  "$MATTER_PY" "${ROOT}/scripts/matter_agent.py" >> "${ROOT}/data/matter-agent.log" 2>&1 &
  MATTER_PID=$!
fi

echo "==> Starting anonymous usage ping"
(
  while true; do
    "$PHP_BIN" "${ROOT}/scripts/metrics_ping.php" >/dev/null 2>&1 || true
    sleep 86400
  done
) &
METRICS_PID=$!

if [[ -f "${ROOT}/scripts/paper_remote.sh" ]]; then
  if grep -q '"enabled": true' "${ROOT}/data/paper-remote.json" 2>/dev/null; then
    echo "==> Starting paper remote gate (tablets off-LAN)"
    bash "${ROOT}/scripts/paper_remote.sh" ensure >/dev/null 2>&1 || true
  fi
fi

# Stop a leftover TLS proxy from a previous crashed run (same port).
pkill -f '[s]cripts/panel_https_proxy.py' 2>/dev/null || true

echo "==> Starting panel on http://${HOST}:${PORT}"
echo "    Keep this process running. Hard-refresh the browser after start."
echo "    Close the official Yarbo app while testing controls/drive."
"$PHP_BIN" -d max_execution_time=1200 -S "${HOST}:${PORT}" -t "${ROOT}/public" &
PHP_PID=$!

# Wait until the HTTP listener accepts before binding HTTPS in front of it.
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if ! kill -0 "${PHP_PID}" 2>/dev/null; then
    echo "ERROR: PHP panel failed to start" >&2
    wait "${PHP_PID}" || true
    exit 1
  fi
  if "$PHP_BIN" -r 'exit(@fsockopen("127.0.0.1", (int)($argv[1]??8080), $e, $s, 0.2) ? 0 : 1);' "${PORT}" 2>/dev/null; then
    break
  fi
  sleep 0.25
done

HTTPS_PROXY_PID=""
HTTPS_WATCH_PID=""
if yarbo_panel_https_enabled; then
  echo "==> Ensuring HTTPS on port $(yarbo_panel_https_port)"
  if [[ -x "${ROOT}/scripts/panel_https_ensure.sh" ]] || [[ -f "${ROOT}/scripts/panel_https_ensure.sh" ]]; then
    ensure_json="$(bash "${ROOT}/scripts/panel_https_ensure.sh" 2>/dev/null || true)"
    echo "    ${ensure_json}"
    if [[ "${ensure_json}" == *'"listening":true'* ]] || [[ "${ensure_json}" == *'"listening": true'* ]]; then
      echo "    HTTPS UI: https://<panel-ip>:$(yarbo_panel_https_port) (self-signed — accept once for mic / Announce)"
    else
      echo "    WARNING: HTTPS did not come up yet — watchdog will retry; see data/panel-https.log" >&2
    fi
  else
    yarbo_panel_start_https_proxy "${ROOT}" "${PORT}" || true
  fi

  # Keep :8443 up while HTTP :8080 is running (proxy can exit without taking the panel down).
  echo "==> Watching HTTPS proxy (auto-restart if :$(yarbo_panel_https_port) drops)"
  (
    while true; do
      sleep "${YARBO_PANEL_HTTPS_WATCH_SECS:-20}"
      if ! yarbo_panel_https_enabled; then
        continue
      fi
      if ! kill -0 "${PHP_PID}" 2>/dev/null; then
        break
      fi
      bash "${ROOT}/scripts/panel_https_ensure.sh" >/dev/null 2>&1 || true
    done
  ) &
  HTTPS_WATCH_PID=$!
fi

wait "${PHP_PID}"
exit $?
