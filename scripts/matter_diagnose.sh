#!/usr/bin/env bash
# Print why Home / the panel is stuck. Safe to paste on the Pi.
# Usage: sudo bash scripts/matter_diagnose.sh
set -u
echo "=== yarbo diagnose $(date -Is) ==="

ROOT=""
if command -v systemctl >/dev/null 2>&1; then
  ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel 2>/dev/null || true)"
fi
if [[ -z "$ROOT" || ! -d "$ROOT" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
echo "root=$ROOT"

echo
echo "--- service ---"
systemctl is-active yarbo-panel 2>/dev/null || echo "yarbo-panel: not active"
systemctl show yarbo-panel -p WorkingDirectory,MainPID,ActiveState,SubState,NRestarts,Result --no-pager 2>/dev/null || true

echo
echo "--- git ---"
if [[ -d "$ROOT/.git" ]]; then
  git -C "$ROOT" log -1 --oneline 2>/dev/null || true
  git -C "$ROOT" describe --tags --always 2>/dev/null || true
  git -C "$ROOT" status -sb 2>/dev/null || true
else
  echo "not a git clone"
fi

echo
echo "--- ports (8080 panel, 8766 agent, 5580 matter) ---"
if command -v ss >/dev/null 2>&1; then
  ss -lntp 2>/dev/null | grep -E ':8080|:8766|:5580' || echo "none of 8080/8766/5580 listening"
else
  netstat -lntp 2>/dev/null | grep -E ':8080|:8766|:5580' || echo "none of 8080/8766/5580 listening"
fi

echo
echo "--- docker ---"
docker_cmd() {
  if command -v docker >/dev/null 2>&1; then
    timeout 8 docker "$@" 2>/dev/null && return 0
    timeout 8 sudo -n docker "$@" 2>/dev/null && return 0
  fi
  return 1
}
if docker_cmd ps -a --filter name=yarbo-matter-server --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'; then
  docker_cmd inspect -f 'running={{.State.Running}} mount={{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' yarbo-matter-server || true
else
  echo "docker not responding (or not installed) within 8s"
fi

echo
echo "--- matter storage ---"
if [[ -d "$ROOT/data/matter-server" ]]; then
  ls -lah "$ROOT/data/matter-server" | head -n 40
else
  echo "missing $ROOT/data/matter-server"
fi
echo "home.json last_devices:"
php -r '
$path = $argv[1];
$raw = is_file($path) ? file_get_contents($path) : "";
$j = json_decode($raw, true);
$n = is_array($j["last_devices"] ?? null) ? count($j["last_devices"]) : 0;
echo $n . " remembered devices\n";
' "$ROOT/data/home.json" 2>/dev/null || echo "(could not read home.json)"

echo
echo "--- timed panel APIs ---"
timed_curl() {
  local url="$1"
  local out="/tmp/yarbo-diag-$(basename "$url" .php).json"
  local line
  line="$(timeout 6 curl -sS -o "$out" -w "http=%{http_code} time=%{time_total}\n" "$url" 2>&1 || echo "curl-failed")"
  echo "$url $line"
  head -c 500 "$out" 2>/dev/null; echo
}
timed_curl "http://127.0.0.1:8080/api/status.php"
timed_curl "http://127.0.0.1:8080/api/home.php"

echo
echo "--- matter agent ping (8766) ---"
timeout 3 curl -sS -m 2 -H "Content-Type: application/json" -d '{"op":"ping"}' http://127.0.0.1:8766/ 2>&1 || echo "agent ping failed"
echo

echo
echo "--- matter-agent.log (last 50) ---"
if [[ -f "$ROOT/data/matter-agent.log" ]]; then
  tail -n 50 "$ROOT/data/matter-agent.log"
else
  echo "no matter-agent.log"
fi

echo
echo "--- journalctl yarbo-panel (last 40) ---"
journalctl -u yarbo-panel -n 40 --no-pager 2>/dev/null || echo "no journal"

echo
echo "=== end diagnose ==="
echo "Paste this whole output back if the panel is still stuck."
echo "To unstick without waiting: sudo timeout 15 docker stop yarbo-matter-server; sudo systemctl restart yarbo-panel"
