#!/usr/bin/env bash
# Find the Hue Bridge on the LAN and test IPv6 multicast. Does not pair.
set -u
IFACE="$(ip -4 route show default 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
IFACE="${IFACE:-eth0}"
echo "=== iface $IFACE addrs ==="
ip -br addr show
echo "=== ipv6 table local ==="
ip -6 route show table local
echo "=== ipv6 maddr $IFACE ==="
ip -6 maddr show dev "$IFACE" 2>/dev/null | head -n 20
echo "=== add multicast route ==="
sudo ip -6 route add multicast ff00::/8 dev "$IFACE" table local 2>&1 || true
ip -6 route show table local | grep -E 'ff00|multicast' || echo "no multicast ff00 route"
echo "=== mdns send test ==="
python3 - "$IFACE" <<'PY'
import socket, sys
iface = sys.argv[1]
idx = socket.if_nametoindex(iface)
pkt = b"\x12\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x07_matter\x04_tcp\x05local\x00\x00\x0c\x00\x01"
s4 = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
try:
    s4.sendto(pkt, ("224.0.0.251", 5353))
    print("v4 mdns send ok")
except OSError as e:
    print("v4 mdns send", e)
s4.close()
s6 = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
try:
    s6.sendto(pkt, ("ff02::fb", 5353))
    print("v6 mdns send no-scope ok")
except OSError as e:
    print("v6 mdns send no-scope", type(e).__name__, e)
try:
    s6.sendto(pkt, ("ff02::fb", 5353, 0, idx))
    print("v6 mdns send scoped ok idx", idx)
except OSError as e:
    print("v6 mdns send scoped", type(e).__name__, e)
s6.close()
PY
echo "=== neigh ==="
ip neigh show
echo "=== docker ==="
docker ps --format '{{.Names}} {{.Image}}' 2>/dev/null || true
echo "=== homebridge configs ==="
for p in /var/lib/homebridge/config.json /home/admin/.homebridge/config.json /homebridge/config.json /opt/homebridge/config.json /etc/homebridge/config.json; do
  if sudo test -f "$p"; then
    echo "-- $p --"
    sudo python3 - "$p" <<'PY'
import json, re, sys
from pathlib import Path
path = Path(sys.argv[1])
hint = re.compile(r"hue|ipbridge|signify|philips|bridgeid", re.I)
data = json.loads(path.read_text())
print("keys", list(data)[:12] if isinstance(data, dict) else type(data).__name__)
plats = data.get("platforms") if isinstance(data, dict) else None
if not isinstance(plats, list):
    raise SystemExit
for plat in plats:
    if not isinstance(plat, dict):
        continue
    blob = json.dumps(plat)
    if not hint.search(str(plat.get("platform") or "") + blob[:800]):
        continue
    shown = {k: plat[k] for k in plat if k.lower() in ("platform","name","host","hosts","ip","ipaddress","bridge","port") or "ip" in k.lower()}
    print(shown)
PY
  else
    echo "missing $p"
  fi
done
echo "=== hue http on known neigh ==="
python3 - <<'PY'
import socket, urllib.request
ips = []
try:
    out = open("/proc/net/arp").read().splitlines()[1:]
except OSError:
    out = []
for line in out:
    parts = line.split()
    if parts:
        ips.append(parts[0])
seen = []
for ip in ips:
    for path in ("/description.xml", "/api/config"):
        url = f"http://{ip}{path}"
        try:
            with urllib.request.urlopen(url, timeout=0.7) as resp:
                body = resp.read(800).decode("utf-8", "replace")
        except Exception:
            continue
        if "hue" in body.lower() or "philips" in body.lower() or "bridgeid" in body.lower():
            print("HUE", url, body.replace("\n", " ")[:220])
            seen.append(ip)
            break
print("scanned", len(ips), "arp entries, hue_hits", seen or "none")
PY
echo "done. Do not pair."
