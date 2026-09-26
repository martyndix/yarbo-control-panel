#!/usr/bin/env bash
# Start python-matter-server without a terminal.
# Used by Settings → Home and by the panel after an update.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
if sudo -n /usr/local/sbin/yarbo-matter-setup "$ROOT" >/dev/null 2>&1; then
  exit 0
fi
exec bash "$ROOT/scripts/lib/matter_server.sh" "$ROOT"
