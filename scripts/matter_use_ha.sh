#!/usr/bin/env bash
# Give port 5580 back to Home Assistant's Matter server and cache live lights.
# Does not pair. Run: bash scripts/matter_use_ha.sh
set -u
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel 2>/dev/null || true)"
if [[ -z "$ROOT" || ! -d "$ROOT/data" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT"
echo "root=$ROOT"
echo "=== hue 192.168.9.12 ==="
curl -sSI -m 3 http://192.168.9.12/ 2>/dev/null | head -n 12 || true
echo "=== disable yarbo matter container ==="
docker update --restart=no yarbo-matter-server 2>/dev/null || true
docker stop yarbo-matter-server || true
docker run --rm -v "$ROOT/data/matter-server:/data" alpine sh -c 'mv /data/1415963636765591517.json /data/1415963636765591517.json.yarbo-stub 2>/dev/null || true'
docker restart matter-server
echo "waiting for 5580"
python3 - <<'PY'
import socket, time
deadline = time.time() + 45
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
print("wait 20s for HA node subscriptions")
time.sleep(20)
PY
echo "=== HA matter logs ==="
docker logs --tail 25 matter-server 2>&1 | grep -E 'Loaded [0-9]+ nodes|Subscription|initialized|Error|Timeout|Using' || docker logs --tail 15 matter-server 2>&1 | tail -n 15
echo "=== nodes + cache ==="
python3 - <<'PY'
import json, os, shutil, socket, struct, time, uuid
from pathlib import Path

ROOT = Path.cwd()
HOST, PORT, OLD_NODE = "127.0.0.1", 5580, 13


def recvn(sock, n):
    buf = bytearray()
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise ConnectionError("socket closed")
        buf.extend(chunk)
    return bytes(buf)


def recv_frame(sock):
    hdr = recvn(sock, 2)
    opcode = hdr[0] & 0x0F
    fin = (hdr[0] & 0x80) != 0
    masked = (hdr[1] & 0x80) != 0
    ln = hdr[1] & 0x7F
    if ln == 126:
        ln = struct.unpack("!H", recvn(sock, 2))[0]
    elif ln == 127:
        ln = struct.unpack("!Q", recvn(sock, 8))[0]
    mask = recvn(sock, 4) if masked else b""
    payload = recvn(sock, ln)
    if masked:
        payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
    return opcode, fin, payload


def recv_json(sock, timeout):
    sock.settimeout(timeout)
    deadline = time.time() + timeout
    acc = bytearray()
    while time.time() < deadline:
        opcode, fin, payload = recv_frame(sock)
        if opcode == 0x9:
            # ping
            key = os.urandom(4)
            pong = bytes([0x8A, 0x80 | len(payload)]) + key + bytes(b ^ key[i % 4] for i, b in enumerate(payload))
            sock.sendall(pong)
            continue
        if opcode in (0xA, 0x8):
            if opcode == 0x8:
                return None
            continue
        if opcode in (0x1, 0x2, 0x0):
            acc.extend(payload)
            if fin:
                return json.loads(acc.decode("utf-8"))
            continue
    return None


def send(sock, payload):
    data = json.dumps(payload, separators=(",", ":")).encode()
    key = os.urandom(4)
    n = len(data)
    header = bytearray([0x81])
    if n < 126:
        header.append(0x80 | n)
    elif n < 65536:
        header.extend([0x80 | 126])
        header.extend(struct.pack("!H", n))
    else:
        header.extend([0x80 | 127])
        header.extend(struct.pack("!Q", n))
    header.extend(key)
    sock.sendall(header + bytes(b ^ key[i % 4] for i, b in enumerate(data)))


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


def flatten(nodes):
    if isinstance(nodes, dict):
        nodes = list(nodes.values())
    devices = []
    summary = []
    for node in nodes or []:
        if not isinstance(node, dict):
            continue
        nid = int(node.get("node_id") or 0)
        available = bool(node.get("available", True))
        attrs = node.get("attributes") if isinstance(node.get("attributes"), dict) else {}
        eps = set()
        for key in attrs:
            if isinstance(key, str) and "/" in key:
                try:
                    eps.add(int(key.split("/", 1)[0]))
                except ValueError:
                    pass
        lights = 0
        for ep in sorted(eps):
            if ep == 0:
                continue
            has_on = any(str(k).startswith(f"{ep}/6/") for k in attrs)
            if not has_on:
                continue
            lights += 1
            on_val = attrs.get(f"{ep}/6/0")
            devices.append({
                "id": f"{nid}:{ep}",
                "node_id": nid,
                "endpoint": ep,
                "name": f"Light {ep}",
                "kind": "light",
                "on": bool(on_val),
                "available": available,
                "vendor": str(attrs.get("0/40/1") or ""),
                "product": str(attrs.get("0/40/3") or ""),
            })
        summary.append((nid, available, len(attrs), lights, bool(node.get("is_bridge"))))
    return summary, devices


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
print("hello", {k: hello.get(k) for k in ("fabric_id", "compressed_fabric_id")} if isinstance(hello, dict) else hello)
mid = uuid.uuid4().hex[:12]
send(sock, {"message_id": mid, "command": "get_nodes"})
nodes = None
while True:
    msg = recv_json(sock, 120)
    if msg is None:
        raise SystemExit("get_nodes timed out")
    if msg.get("message_id") == mid:
        if msg.get("error"):
            raise SystemExit("get_nodes " + str(msg.get("error")))
        nodes = msg.get("result")
        break
summary, devices = flatten(nodes)
print("nodes", len(summary), "devices", len(devices))
for nid, avail, n_attrs, lights, bridge in summary:
    print(f" node {nid} available={avail} attrs={n_attrs} lights={lights} bridge={bridge}")
if not devices:
    raise SystemExit("HA Matter has no lights yet; wait a minute and re-run")
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
cache = ROOT / "data" / "home-nodes-cache.json"
cache.write_text(json.dumps({"v": 4, "saved_at": int(time.time()), "devices": devices}, separators=(",", ":")))
print("wrote", cache.name, "devices", len(devices))
# last_devices: keep named rows, don't store a single Matter node stub
if devices and not (len(devices) == 1 and str(devices[0].get("name") or "").startswith("Matter node ")):
    store = json.loads(home.read_text())
    slim = [{"id": d["id"], "name": d.get("name"), "kind": d.get("kind"), "node_id": d.get("node_id"), "endpoint": d.get("endpoint")} for d in devices]
    store["last_devices"] = slim
    home.write_text(json.dumps(store, indent=2) + "\n")
    print("updated last_devices", len(slim))
print("refresh Home. Do not pair. Leave yarbo-matter-server stopped.")
PY
echo "=== yarbo container ==="
docker ps -a --filter name=yarbo-matter-server --format '{{.Names}} {{.Status}} {{.RestartPolicy}}' 2>/dev/null || docker inspect yarbo-matter-server --format 'running={{.State.Running}} restart={{.HostConfig.RestartPolicy.Name}}'
echo "done"
