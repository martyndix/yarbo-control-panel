#!/usr/bin/env bash
# Give port 5580 back to Home Assistant's Matter server and remap saved 13:x names.
# Does not pair. Run from the panel folder: bash scripts/matter_use_ha.sh
set -u
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel 2>/dev/null || true)"
if [[ -z "$ROOT" || ! -d "$ROOT/data" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT"
echo "root=$ROOT"
echo "=== hue 192.168.9.12 ==="
curl -sS -m 3 -o /tmp/hue-desc.xml -w "desc http=%{http_code}\n" http://192.168.9.12/description.xml || true
head -c 240 /tmp/hue-desc.xml 2>/dev/null; echo
curl -sS -m 3 -o /tmp/hue-cfg.json -w "api http=%{http_code}\n" http://192.168.9.12/api/config || true
head -c 240 /tmp/hue-cfg.json 2>/dev/null; echo
echo "=== stop yarbo matter, restart HA matter ==="
docker stop yarbo-matter-server || true
docker restart matter-server
echo "waiting for 5580"
python3 - <<'PY'
import socket, time
deadline = time.time() + 40
while time.time() < deadline:
    s = socket.socket()
    s.settimeout(0.4)
    try:
        s.connect(("127.0.0.1", 5580))
        print("5580 up")
        break
    except OSError:
        time.sleep(1)
    finally:
        s.close()
else:
    raise SystemExit("5580 not up")
print("wait 15s for HA node subscriptions")
time.sleep(15)
PY
echo "=== HA matter logs ==="
docker logs --tail 20 matter-server 2>&1 | grep -E 'Loaded [0-9]+ nodes|Subscription|initialized|Error|Timeout' || docker logs --tail 12 matter-server 2>&1 | tail -n 12
echo "=== nodes + remap ==="
python3 - <<'PY'
import json, os, shutil, socket, struct, time, uuid
from pathlib import Path

ROOT = Path.cwd()
HOST, PORT, OLD_NODE = "127.0.0.1", 5580, 13

def rewrite_device_id(device_id, old_node, new_node):
    if not isinstance(device_id, str) or ":" not in device_id:
        return device_id
    node_s, rest = device_id.split(":", 1)
    try:
        if int(node_s) == old_node:
            return f"{new_node}:{rest}"
    except ValueError:
        pass
    return device_id

def remap_home_node_id(store, old_node, new_node):
    if old_node == new_node or old_node <= 0 or new_node <= 0:
        return 0
    changed = 0
    def one(value):
        nonlocal changed
        if isinstance(value, str) and ":" in value:
            rewritten = rewrite_device_id(value, old_node, new_node)
            if rewritten != value:
                changed += 1
            return rewritten
        return value
    for key in ("names", "rooms", "groups"):
        mapping = store.get(key)
        if isinstance(mapping, dict):
            store[key] = {one(k): v for k, v in mapping.items()}
    if isinstance(store.get("hidden"), list):
        store["hidden"] = [one(item) for item in store["hidden"]]
    if isinstance(store.get("device_order"), list):
        store["device_order"] = [one(item) for item in store["device_order"]]
    paper = store.get("paper")
    if isinstance(paper, dict):
        store["paper"] = {pk: [one(item) for item in lst] if isinstance(lst, list) else lst for pk, lst in paper.items()}
    for scene in store.get("scenes") or []:
        if not isinstance(scene, dict):
            continue
        for action in scene.get("actions") or []:
            if isinstance(action, dict) and "id" in action:
                action["id"] = one(action.get("id"))
    for row in store.get("last_devices") or []:
        if not isinstance(row, dict):
            continue
        if "id" in row:
            row["id"] = one(row.get("id"))
        try:
            if int(row.get("node_id") or 0) == old_node:
                row["node_id"] = new_node
                changed += 1
        except (TypeError, ValueError):
            pass
    return changed

def send(sock, payload):
    data = json.dumps(payload, separators=(",", ":")).encode()
    key = os.urandom(4)
    n = len(data)
    header = bytearray([0x81, 0x80 | n])
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
                mask = buf[idx - 4 : idx]
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
            del buf[:total]
            if payload:
                return json.loads(payload.decode())
    return None

def flatten(raw):
    nodes = raw if isinstance(raw, list) else list((raw or {}).values()) if isinstance(raw, dict) else []
    devices = []
    for node in nodes:
        if not isinstance(node, dict):
            continue
        nid = int(node.get("node_id") or 0)
        attrs = node.get("attributes") if isinstance(node.get("attributes"), dict) else {}
        eps = set()
        for key in attrs:
            if isinstance(key, str) and "/" in key:
                try:
                    eps.add(int(key.split("/", 1)[0]))
                except ValueError:
                    pass
        lights = 0
        for ep in eps:
            if ep == 0:
                continue
            on_key = f"{ep}/6/0"
            if on_key in attrs or any(str(k).startswith(f"{ep}/6/") for k in attrs):
                lights += 1
        devices.append((nid, bool(node.get("available")), len(attrs), lights, node.get("is_bridge")))
    return devices

sock = socket.create_connection((HOST, PORT), timeout=8)
sock.sendall(
    f"GET /ws HTTP/1.1\r\nHost: {HOST}:{PORT}\r\nUpgrade: websocket\r\n"
    f"Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
    f"Sec-WebSocket-Version: 13\r\n\r\n".encode()
)
buf = b""
while b"\r\n\r\n" not in buf:
    buf += sock.recv(4096)
hello = recv_json(sock, 12)
print("hello", {k: hello.get(k) for k in ("fabric_id", "compressed_fabric_id")} if isinstance(hello, dict) else hello)
mid = uuid.uuid4().hex[:12]
send(sock, {"message_id": mid, "command": "get_nodes"})
nodes = []
while True:
    msg = recv_json(sock, 25)
    if msg is None:
        raise SystemExit("get_nodes timed out")
    if msg.get("message_id") == mid:
        if msg.get("error"):
            raise SystemExit("get_nodes " + str(msg.get("error")))
        nodes = msg.get("result") or []
        break
summary = flatten(nodes)
print("nodes", len(summary))
for nid, avail, n_attrs, lights, bridge in summary:
    print(f" node {nid} available={avail} attrs={n_attrs} lights={lights} bridge={bridge}")
if not summary:
    raise SystemExit("HA Matter has no nodes yet; wait a minute and re-run")
hue_node = max(summary, key=lambda row: (row[3], row[2]))[0]
print("hue_node", hue_node)
home = ROOT / "data" / "home.json"
store = json.loads(home.read_text())
changed = remap_home_node_id(store, OLD_NODE, hue_node)
if changed:
    backup = home.with_suffix(".json.pre-remap")
    if not backup.exists():
        shutil.copy2(home, backup)
    home.write_text(json.dumps(store, indent=2) + "\n")
print("home.json remapped", changed, OLD_NODE, "->", hue_node)
print("refresh Home. Do not pair.")
PY
