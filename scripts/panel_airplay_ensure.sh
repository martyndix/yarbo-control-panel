#!/usr/bin/env bash
# Ensure the AirPlay receiver (shairport-sync → FIFO) is running. JSON on stdout.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/panel_airplay.sh
source "${ROOT}/scripts/lib/panel_airplay.sh"

LOG="$(yarbo_airplay_log "$ROOT")"
mkdir -p "$(yarbo_airplay_dir "$ROOT")"

json_out() {
  php -r 'echo json_encode([
    "ok" => $argv[1] === "1",
    "running" => $argv[2] === "1",
    "installed" => $argv[3] === "1",
    "nqptp" => $argv[4] === "1",
    "name" => $argv[5] !== "" ? $argv[5] : null,
    "fifo" => $argv[6] !== "" ? $argv[6] : null,
    "pid" => $argv[7] !== "" ? (int) $argv[7] : null,
    "message" => $argv[8] !== "" ? $argv[8] : null,
    "error" => $argv[9] !== "" ? $argv[9] : null,
    "log_tail" => $argv[10] !== "" ? $argv[10] : null,
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$1" "$2" "$3" "$4" "$5" "$6" "$7" "$8" "$9" "${10}"
}

log_tail() {
  if [[ -f "$LOG" ]]; then
    tail -n 25 "$LOG" | tr '\n' '|' | sed 's/|$//'
  else
    echo ""
  fi
}

NAME="$(yarbo_airplay_read_name "$ROOT")"
FIFO="$(yarbo_airplay_fifo "$ROOT")"
INSTALLED=0
NQPTP=0
if yarbo_airplay_installed; then INSTALLED=1; fi
if [[ -n "$(yarbo_airplay_nqptp_bin)" ]]; then NQPTP=1; fi

if [[ "$INSTALLED" -ne 1 ]]; then
  # Panel update / Start AirPlay: install via passwordless root helper when possible.
  if yarbo_try_install_airplay_deps "$ROOT"; then
    INSTALLED=1
    if [[ -n "$(yarbo_airplay_nqptp_bin)" ]]; then NQPTP=1; fi
  fi
fi

if [[ "$INSTALLED" -ne 1 ]]; then
  json_out 0 0 0 "$NQPTP" "$NAME" "$FIFO" "" "" \
    "Could not install shairport-sync. Update the panel again, or on the Pi run: sudo apt install -y shairport-sync avahi-daemon. Check data/panel-airplay.log for apt output." \
    "$(log_tail)"
  exit 0
fi

# Best-effort open Avahi / PTP-related ports when ufw is active.
if command -v ufw >/dev/null 2>&1; then
  if sudo -n ufw status 2>/dev/null | grep -qi 'Status: active'; then
    sudo -n ufw allow 319,320/udp comment 'Yarbo AirPlay PTP' >/dev/null 2>&1 || true
    sudo -n ufw allow 5353/udp comment 'Yarbo mDNS' >/dev/null 2>&1 || true
  fi
fi

if yarbo_airplay_running "$ROOT"; then
  json_out 1 1 1 "$NQPTP" "$NAME" "$FIFO" "$(yarbo_airplay_pid "$ROOT")" \
    "AirPlay receiver is running as ${NAME}" "" "$(log_tail)"
  exit 0
fi

if ! yarbo_airplay_start_receiver "$ROOT"; then
  json_out 0 0 1 "$NQPTP" "$NAME" "$FIFO" "" "" \
    "Could not start shairport-sync — see data/panel-airplay.log" "$(log_tail)"
  exit 0
fi

if yarbo_airplay_running "$ROOT"; then
  json_out 1 1 1 "$NQPTP" "$NAME" "$FIFO" "$(yarbo_airplay_pid "$ROOT")" \
    "AirPlay receiver started as ${NAME}" "" "$(log_tail)"
  exit 0
fi

json_out 0 0 1 "$NQPTP" "$NAME" "$FIFO" "" "" \
  "shairport-sync exited — see data/panel-airplay.log" "$(log_tail)"
exit 0
