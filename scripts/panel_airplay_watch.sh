#!/usr/bin/env bash
# Poll for a real AirPlay/RTSP client when session hooks are unavailable.
# Calls panel_airplay_bridge.php active|inactive on edge transitions.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/panel_airplay.sh
source "${ROOT}/scripts/lib/panel_airplay.sh"

LOG="$(yarbo_airplay_log "$ROOT")"
STATE_FILE="$(yarbo_airplay_dir "$ROOT")/watch_state"
mkdir -p "$(yarbo_airplay_dir "$ROOT")"
echo 0 >"$STATE_FILE"

was=0
while true; do
  if ! yarbo_airplay_running "$ROOT"; then
    if [[ "$was" -eq 1 ]]; then
      php "${ROOT}/scripts/panel_airplay_bridge.php" inactive >>"$LOG" 2>&1 || true
      was=0
      echo 0 >"$STATE_FILE"
    fi
    sleep 2
    continue
  fi
  if yarbo_airplay_has_network_client "$ROOT"; then
    now=1
  else
    now=0
  fi
  if [[ "$now" -ne "$was" ]]; then
    if [[ "$now" -eq 1 ]]; then
      echo "==> panel_airplay_watch client connected $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG"
      php "${ROOT}/scripts/panel_airplay_bridge.php" active >>"$LOG" 2>&1 || true
    else
      echo "==> panel_airplay_watch client gone $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG"
      php "${ROOT}/scripts/panel_airplay_bridge.php" inactive >>"$LOG" 2>&1 || true
    fi
    was=$now
    echo "$now" >"$STATE_FILE"
  fi
  sleep 2
done
