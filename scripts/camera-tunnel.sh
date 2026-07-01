#!/usr/bin/env bash
# Forward Yarbo internal camera RTSP ports to localhost for the PHP control panel.
#
# Usage:
#   SSH_TARGET="user@your-robot-host" ./scripts/camera-tunnel.sh
#
# Then in config.php set:
#   'camera_host' => '127.0.0.1',
# or leave camera_auto_detect enabled (default).
#
# Test one stream:
#   ffplay -rtsp_transport tcp rtsp://127.0.0.1:19201/live/chn0

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$ROOT/config.php"

if [[ -z "${SSH_TARGET:-}" ]]; then
  echo "Set SSH_TARGET to your robot SSH login."
  echo "Yarbo does not publish owner SSH credentials — you need access from Yarbo support."
  echo
  echo '  SSH_TARGET="root@192.168.1.223" ./scripts/camera-tunnel.sh'
  exit 1
fi

echo "Starting camera port forwards on 127.0.0.1:19201-19204"
echo "SSH target: $SSH_TARGET"
echo "Leave this terminal open while using camera streams."
echo

exec ssh -N \
  -L 127.0.0.1:19201:37.38.39.23:8080 \
  -L 127.0.0.1:19202:37.38.39.13:8080 \
  -L 127.0.0.1:19203:37.38.39.33:8080 \
  -L 127.0.0.1:19204:37.38.39.43:8080 \
  "$SSH_TARGET"
