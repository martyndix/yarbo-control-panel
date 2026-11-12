#!/usr/bin/env bash
# Shared UniFi Protect events subscriber helpers. Sourced by panel.sh and update.sh.

yarbo_stop_unifi_protect_events() {
  pkill -f '[s]cripts/unifi_protect_events.py' 2>/dev/null || true
  sleep 0.2
}
