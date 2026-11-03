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
    "avahi" => $argv[5] === "1",
    "name" => $argv[6] !== "" ? $argv[6] : null,
    "fifo" => $argv[7] !== "" ? $argv[7] : null,
    "pid" => $argv[8] !== "" ? (int) $argv[8] : null,
    "message" => $argv[9] !== "" ? $argv[9] : null,
    "error" => $argv[10] !== "" ? $argv[10] : null,
    "log_tail" => $argv[11] !== "" ? $argv[11] : null,
  ], JSON_UNESCAPED_SLASHES) . "\n";' \
    "$1" "$2" "$3" "$4" "$5" "$6" "$7" "$8" "$9" "${10}" "${11}"
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

AVAHI=0
if yarbo_airplay_avahi_running; then AVAHI=1; fi

if [[ "$INSTALLED" -ne 1 ]]; then
  json_out 0 0 0 "$NQPTP" "$AVAHI" "$NAME" "$FIFO" "" "" \
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

# Make sure Avahi is up and the packaged ALSA shairport unit is not stealing mDNS.
yarbo_airplay_ensure_avahi "$ROOT" || true
yarbo_airplay_quiesce_packaged_service "$ROOT"
if yarbo_airplay_avahi_running; then AVAHI=1; else AVAHI=0; fi

# Always refresh conf (session hooks / name) and restart so Start AirPlay applies them.
if yarbo_airplay_running "$ROOT"; then
  echo "==> panel_airplay_ensure restarting receiver to refresh conf $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG"
  yarbo_airplay_stop_receiver "$ROOT"
  sleep 0.3
fi

if ! yarbo_airplay_start_receiver "$ROOT"; then
  if yarbo_airplay_avahi_running; then AVAHI=1; else AVAHI=0; fi
  err="Could not start shairport-sync — see data/panel-airplay.log"
  if [[ "$AVAHI" -ne 1 ]]; then
    err="Could not start AirPlay (avahi-daemon is not running). Update the panel or run: sudo systemctl start avahi-daemon"
  fi
  json_out 0 0 1 "$NQPTP" "$AVAHI" "$NAME" "$FIFO" "" "" \
    "$err" "$(log_tail)"
  exit 0
fi

if yarbo_airplay_avahi_running; then AVAHI=1; else AVAHI=0; fi

if yarbo_airplay_our_receiver_running "$ROOT" || yarbo_airplay_running "$ROOT"; then
  msg="AirPlay receiver started as ${NAME}"
  if [[ "$AVAHI" -ne 1 ]]; then
    msg="${msg} — WARNING: avahi-daemon is off, so iPhones may not see it"
  fi
  json_out 1 1 1 "$NQPTP" "$AVAHI" "$NAME" "$FIFO" "$(yarbo_airplay_pid "$ROOT")" \
    "$msg" "" "$(log_tail)"
  exit 0
fi

json_out 0 0 1 "$NQPTP" "$AVAHI" "$NAME" "$FIFO" "" "" \
  "shairport-sync exited — see data/panel-airplay.log" "$(log_tail)"
exit 0
