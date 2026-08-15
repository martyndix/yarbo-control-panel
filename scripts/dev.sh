#!/usr/bin/env bash
# Local / macOS: persistent MQTT agent + PHP panel
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec "$ROOT/scripts/panel.sh"
