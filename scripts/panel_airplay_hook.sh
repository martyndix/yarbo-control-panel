#!/usr/bin/env bash
# Called by shairport-sync sessioncontrol when a client enters/leaves active state.
# Args: active | inactive
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ACTION="${1:-}"
LOG="${ROOT}/data/panel-airplay.log"
mkdir -p "${ROOT}/data/airplay"

{
  echo "==> panel_airplay_hook ${ACTION} $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  php "${ROOT}/scripts/panel_airplay_bridge.php" "${ACTION}"
} >>"$LOG" 2>&1 || true

exit 0
