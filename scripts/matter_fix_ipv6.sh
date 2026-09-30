#!/usr/bin/env bash
# Fix CHIP mDNS on IPv4-only LANs, find Hue over SSDP, interview node 13.
# Does not pair. Run as admin: bash scripts/matter_fix_ipv6.sh
set -u
echo "=== routes ==="
ip -4 route show default
ip -6 route show
IFACE="$(ip -4 route show default 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
IFACE="${IFACE:-eth0}"
echo "iface=$IFACE"
echo "=== add ipv6 default (sudo password ok) ==="
sudo sysctl -w "net.ipv6.conf.${IFACE}.accept_ra=1" || true
if ip -6 route show default | grep -q .; then
  echo "ipv6 default already present"
else
  sudo ip -6 route add default dev "$IFACE" || echo "route add failed"
fi
ip -6 route show default
echo "=== ssdp / homebridge hue ==="
python3 - <<'PY'
import json, re, socket, time
from pathlib import Path
hint = re.compile(r"hue|ipbridge|signify|philips", re.I)
msg = (
    'M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\n'
    'MAN: "ssdp:discover"\r\nMX: 2\r\nST: upnp:rootdevice\r\n\r\n'
).encode()
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
s.settimeout(0.5)
s.bind(("", 0))
try:
    s.sendto(msg, ("239.255.255.250", 1900))
except OSError as e:
    print("ssdp send", e)
found = []
end = time.time() + 3
while time.time() < end:
    try:
        data, addr = s.recvfrom(4096)
    except TimeoutError:
        continue
    except OSError:
        break
    text = data.decode("utf-8", "replace")
    if hint.search(text):
        loc = next((ln.split(":",1)[1].strip() for ln in text.splitlines() if ln.lower().startswith("location:")), "")
        found.append(f"{addr[0]} {loc[:120]}")
s.close()
print("ssdp", found or ["no Hue replies"])
for path in (Path("/var/lib/homebridge/config.json"), Path.home()/".homebridge/config.json", Path("/homebridge/config.json")):
    if not path.is_file():
        continue
    try:
        data = json.loads(path.read_text())
    except Exception as e:
        print(path, e)
        continue
    for plat in data.get("platforms") or []:
        if not isinstance(plat, dict):
            continue
        blob = json.dumps(plat)
        if not hint.search(str(plat.get("platform") or "") + blob[:400]):
            continue
        for key in ("host", "hosts", "ip", "ipaddress", "bridge"):
            if key in plat:
                print(path, plat.get("platform"), key, plat[key])
PY
echo "=== restart matter ==="
docker restart yarbo-matter-server
sleep 20
docker logs --tail 25 yarbo-matter-server 2>&1 | grep -E 'primary|unreachable|Timeout|initialized|Loaded [0-9]+ nodes|Interview' || true
echo "=== interview ==="
python3 - <<'PY'
import json, os, socket, struct, time, uuid
HOST, PORT, NODE = "127.0.0.1", 5580, 13

def send(sock, payload):
    data = json.dumps(payload, separators=(",", ":")).encode()
    key = os.urandom(4)
    n = len(data)
    header = bytearray([0x81, 0x80 | n if n < 126 else 0])
    if n >= 126:
        raise SystemExit("payload too large")
    header.extend(key)
    sock.sendall(header + bytes(b ^ key[i % 4] for i, b in enumerate(data)))

def recv_json(sock, timeout):
    sock.settimeout(timeout)
    buf = bytearray()
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            chunk = sock.recv(4096)
        except (TimeoutError, socket.timeout):
            continue
        if not chunk:
            return None
        buf.extend(chunk)
        while len(buf) >= 2:
            ln = buf[1] & 0x7F
            idx = 2
            if ln == 126:
                if len(buf) < 4:
                    break
                ln = struct.unpack("!H", buf[2:4])[0]
                idx = 4
            if buf[1] & 0x80:
                idx += 4
            total = idx + ln
            if len(buf) < total:
                break
            payload = bytes(buf[idx:total])
            if buf[1] & 0x80:
                mask = buf[idx-4:idx]
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
            del buf[:total]
            if payload:
                return json.loads(payload.decode())
    return None

sock = socket.create_connection((HOST, PORT), timeout=8)
sock.sendall(
    f"GET /ws HTTP/1.1\r\nHost: {HOST}:{PORT}\r\nUpgrade: websocket\r\n"
    f"Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
    f"Sec-WebSocket-Version: 13\r\n\r\n".encode()
)
buf = b""
while b"\r\n\r\n" not in buf:
    buf += sock.recv(4096)
hello = recv_json(sock, 15)
print("hello", hello.get("compressed_fabric_id") if isinstance(hello, dict) else hello)
mid = uuid.uuid4().hex[:12]
send(sock, {"message_id": mid, "command": "interview_node", "args": {"node_id": NODE}})
print("interviewing", NODE)
while True:
    msg = recv_json(sock, 90)
    if msg is None:
        raise SystemExit("interview timed out")
    print("event", msg.get("event") or msg.get("message_id"))
    if msg.get("message_id") == mid:
        if msg.get("error_code") or msg.get("error"):
            raise SystemExit("interview failed: " + str(msg.get("details") or msg.get("error")))
        print("interview ok")
        break
print("Do not pair. Names stay in data/home.json.")
PY
