#!/usr/bin/env bash
# Shared UniFi Protect events subscriber helpers. Sourced by panel.sh and update.sh.

yarbo_stop_unifi_protect_events() {
  pkill -f '[s]cripts/unifi_protect_events.py' 2>/dev/null || true
  sleep 0.2
}

# Start the Integration subscribe/events sidecar if it is not already alive.
# Safe to call from PHP (Home automations / UniFi refresh) after updates.
yarbo_ensure_unifi_protect_events() {
  local root="${1:-}"
  if [[ -z "$root" || ! -d "$root" ]]; then
    return 1
  fi
  if pgrep -f '[s]cripts/unifi_protect_events.py' >/dev/null 2>&1; then
    return 0
  fi
  local py="${root}/.venv/bin/python"
  if [[ ! -x "$py" ]]; then
    py="$(command -v python3 || true)"
  fi
  if [[ -z "$py" || ! -f "${root}/scripts/unifi_protect_events.py" ]]; then
    return 1
  fi
  mkdir -p "${root}/data"
  nohup "$py" "${root}/scripts/unifi_protect_events.py" >> "${root}/data/unifi-protect-events.log" 2>&1 &
  return 0
}
