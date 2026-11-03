#!/usr/bin/env bash
# Emergency speed recovery for a Pi stalled by huge AirPlay/HomePod logs or
# stuck radio/AirPlay processes.
#
# SAFE: does NOT touch home.json, Matter pairing, UniFi credentials, scenes,
# automations, PaperMono devices, or other settings — only logs, transient
# player state, and AirPlay/ffmpeg helper processes.
#
# Usage (on the Pi):
#   cd /home/admin/yarbo   # or your panel path
#   bash scripts/panel_recover_speed.sh           # free the Pi now
#   bash scripts/panel_recover_speed.sh --update  # free + pull main + restart
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
DO_UPDATE=0
for arg in "$@"; do
  case "$arg" in
    --update|-u) DO_UPDATE=1 ;;
    -h|--help)
      echo "Usage: $0 [--update]"
      echo "  Frees a stalled panel (trim logs, stop AirPlay helpers, clear stuck Playing)."
      echo "  Does not delete lights, Home devices, or settings under data/."
      echo "  --update  also runs scripts/update.sh and restarts yarbo-panel."
      exit 0
      ;;
  esac
done

mkdir -p "${ROOT}/data" "${ROOT}/data/airplay" "${ROOT}/data/homepod"

echo "==> panel_recover_speed $(date -u +%Y-%m-%dT%H:%M:%SZ) root=${ROOT}"

# --- 1) Stop anything that can re-flood logs or burn CPU -------------------
echo "==> Stopping AirPlay / HomePod stream helpers (settings kept)"
# shellcheck source=scripts/lib/panel_airplay.sh
if [[ -f "${ROOT}/scripts/lib/panel_airplay.sh" ]]; then
  # shellcheck disable=SC1091
  source "${ROOT}/scripts/lib/panel_airplay.sh"
  yarbo_airplay_stop_receiver "${ROOT}" 2>/dev/null || true
fi
pkill -x shairport-sync 2>/dev/null || true
pkill -x nqptp 2>/dev/null || true
pkill -f '[p]anel_airplay_watch.sh' 2>/dev/null || true
pkill -f '[p]anel_airplay_metadata.php' 2>/dev/null || true
pkill -f '[p]anel_airplay_bridge.php' 2>/dev/null || true
# Fan-out / remux ffmpeg left from radio or AirPlay bridge.
pkill -f '[f]fmpeg.*(airplay|live\.mp3|unifi-radio|homepod)' 2>/dev/null || true
# Do NOT kill the Homepod agent itself here if the panel is up — panel.sh owns it.
# Kill orphaned agent only when we are about to update/restart.
if [[ "$DO_UPDATE" -eq 1 ]]; then
  pkill -f '[s]cripts/homepod_agent.py' 2>/dev/null || true
fi

# --- 2) Truncate fat logs (the usual ARG_MAX / I/O death spiral) -----------
echo "==> Truncating oversized logs"
trim_log() {
  local f="$1"
  if [[ -f "$f" ]]; then
    local sz
    sz="$(wc -c <"$f" 2>/dev/null | tr -d ' ' || echo 0)"
    if [[ "${sz:-0}" -gt 65536 ]]; then
      echo "    truncate $f (${sz} bytes)"
      : >"$f"
    fi
  fi
}
trim_log "${ROOT}/data/panel-airplay.log"
trim_log "${ROOT}/data/homepod-agent.log"
trim_log "${ROOT}/data/panel-https.log"
trim_log "${ROOT}/data/php-server.log"
trim_log "${ROOT}/data/unifi-radio-airplay.log"
# Any other multi‑MB logs under data/ (never delete non-log settings files).
while IFS= read -r -d '' f; do
  trim_log "$f"
done < <(find "${ROOT}/data" -type f -name '*.log' -size +64k -print0 2>/dev/null || true)

# Leftover remux cache from the HomePod agent.
if [[ -d "${ROOT}/data/homepod/cache" ]]; then
  find "${ROOT}/data/homepod/cache" -type f -mtime +0 -delete 2>/dev/null || true
  find "${ROOT}/data/homepod/cache" -type f -size +1M -delete 2>/dev/null || true
fi

# --- 3) Clear stuck Playing without wiping speaker picks / volume ----------
echo "==> Clearing stuck Radio / Announcement / AirPlay playing state"
php -r '
$root = $argv[1];
$stateFile = $root . "/data/unifi-radio-state.json";
$sessionFile = $root . "/data/airplay/session.json";
$bridgeFile = $root . "/data/airplay/bridge.json";
if (is_file($stateFile)) {
  $state = json_decode((string) @file_get_contents($stateFile), true);
  if (!is_array($state)) {
    $state = [];
  }
  foreach ($state["streams"] ?? [] as $row) {
    $pid = (int) ($row["pid"] ?? 0);
    if ($pid > 1 && function_exists("posix_kill")) {
      @posix_kill($pid, 15);
      usleep(50000);
      @posix_kill($pid, 9);
    }
  }
  // Keep volume + last ticked targets so the checklist stays as the user left it.
  $targets = is_array($state["targets"] ?? null) ? $state["targets"] : [];
  $volume = isset($state["volume"]) ? (int) $state["volume"] : 60;
  $state = [
    "playing" => false,
    "station_id" => null,
    "station_name" => null,
    "station_url" => null,
    "clip_path" => null,
    "volume" => max(0, min(100, $volume)),
    "streams" => [],
    "homepod_targets" => [],
    "targets" => $targets,
    "started_at" => null,
    "ends_at" => null,
    "resume_after" => null,
    "error" => null,
  ];
  @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
  echo "radio state idle\n";
}
@mkdir(dirname($sessionFile), 0755, true);
@file_put_contents($sessionFile, json_encode([
  "active" => false,
  "client_name" => null,
  "updated_at" => time(),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
if (is_file($bridgeFile)) {
  $bridge = json_decode((string) @file_get_contents($bridgeFile), true);
  if (is_array($bridge)) {
    $bridge["armed"] = false;
    @file_put_contents($bridgeFile, json_encode($bridge, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
  }
}
echo "airplay session cleared\n";
' "${ROOT}" || true

# Drop stale AirPlay bridge pid marker.
rm -f "${ROOT}/data/homepod/bridge-http.pid" 2>/dev/null || true

echo "==> Recovery done (settings preserved)"
echo "    Kept: data/home.json, UniFi/Matter/HomePod pairing, scenes, automations, tablets."
echo "    Cleared: fat *.log files, stuck Playing, AirPlay receiver processes."

if [[ "$DO_UPDATE" -eq 1 ]]; then
  echo "==> Running panel update (preserves data/)…"
  if [[ -x "${ROOT}/scripts/update.sh" ]] || [[ -f "${ROOT}/scripts/update.sh" ]]; then
    bash "${ROOT}/scripts/update.sh" || {
      echo "ERROR: update.sh failed — settings are still on disk; fix network/git and retry." >&2
      exit 1
    }
  else
    echo "ERROR: scripts/update.sh missing" >&2
    exit 1
  fi
  if command -v systemctl >/dev/null 2>&1; then
    echo "==> Restarting yarbo-panel"
    if command -v sudo >/dev/null 2>&1 && sudo -n systemctl restart yarbo-panel 2>/dev/null; then
      echo "    restarted via sudo -n"
    elif systemctl --user restart yarbo-panel 2>/dev/null; then
      echo "    restarted via systemctl --user"
    else
      echo "    Run: sudo systemctl restart yarbo-panel"
    fi
  fi
  echo "==> Update path finished. Open the panel and avoid Start AirPlay until it feels snappy."
fi

exit 0
