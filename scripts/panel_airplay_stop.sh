#!/usr/bin/env bash
# Stop the AirPlay receiver. JSON on stdout.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/panel_airplay.sh
source "${ROOT}/scripts/lib/panel_airplay.sh"

LOG="$(yarbo_airplay_log "$ROOT")"
NAME="$(yarbo_airplay_read_name "$ROOT")"
FIFO="$(yarbo_airplay_fifo "$ROOT")"
INSTALLED=0
NQPTP=0
if yarbo_airplay_installed; then INSTALLED=1; fi
if [[ -n "$(yarbo_airplay_nqptp_bin)" ]]; then NQPTP=1; fi

yarbo_airplay_stop_receiver "$ROOT"

php -r 'echo json_encode([
  "ok" => true,
  "running" => false,
  "installed" => $argv[1] === "1",
  "nqptp" => $argv[2] === "1",
  "name" => $argv[3] !== "" ? $argv[3] : null,
  "fifo" => $argv[4] !== "" ? $argv[4] : null,
  "pid" => null,
  "message" => "AirPlay receiver stopped",
  "error" => null,
], JSON_UNESCAPED_SLASHES) . "\n";' \
  "$INSTALLED" "$NQPTP" "$NAME" "$FIFO"
exit 0
