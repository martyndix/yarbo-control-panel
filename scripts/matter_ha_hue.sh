#!/usr/bin/env bash
# Inspect HA vs Yarbo Matter servers and find a Hue host. Does not pair.
set -u
echo "=== listeners 5580/5581 ==="
ss -lntp 2>/dev/null | grep -E ':5580|:5581' || netstat -lntp 2>/dev/null | grep -E ':5580|:5581' || true
echo "=== matter containers ==="
docker ps -a --filter ancestor=ghcr.io/home-assistant-libs/python-matter-server:stable --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
for name in yarbo-matter-server matter-server; do
  echo "-- $name --"
  docker inspect "$name" --format 'net={{.HostConfig.NetworkMode}} running={{.State.Running}} cmd={{json .Config.Cmd}}'
  docker inspect "$name" --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'
  echo "logs:"
  docker logs --tail 15 "$name" 2>&1 | grep -E 'primary|unreachable|Timeout|initialized|Loaded [0-9]+ nodes|port|Error|5580' || docker logs --tail 8 "$name" 2>&1 | tail -n 8
done
echo "=== homebridge platforms ==="
sudo python3 - <<'PY'
import json
from pathlib import Path
p = Path("/var/lib/homebridge/config.json")
data = json.loads(p.read_text())
for plat in data.get("platforms") or []:
    if not isinstance(plat, dict):
        continue
    keys = {k: plat[k] for k in plat if k.lower() in ("platform","name","host","hosts","ip","ipaddress","bridge","port")}
    print(keys or {"platform": plat.get("platform")})
PY
echo "=== HA hue / matter config ==="
docker exec -i homeassistant python3 - <<'PY'
import json, os, glob
roots = ["/config/.storage", "/config"]
for root, _dirs, files in os.walk("/config/.storage"):
    for name in files:
        path = os.path.join(root, name)
        try:
            text = open(path, encoding="utf-8").read()
        except OSError:
            continue
        low = text.lower()
        if "hue" not in low and "matter" not in low and "5580" not in low:
            continue
        if name not in ("core.config_entries", "core.device_registry", "core.entity_registry", "http"):
            if "hue" not in name.lower() and "matter" not in name.lower():
                continue
        try:
            data = json.loads(text)
        except Exception:
            print(path, "not json", len(text))
            continue
        data = data.get("data", data)
        entries = data.get("entries", data) if isinstance(data, dict) else data
        if not isinstance(entries, list):
            continue
        for ent in entries:
            if not isinstance(ent, dict):
                continue
            domain = str(ent.get("domain") or "")
            title = str(ent.get("title") or "")
            blob = json.dumps(ent.get("data") or {})
            if domain in ("hue", "matter") or "hue" in title.lower() or "matter" in title.lower():
                shown = {k: (ent.get("data") or {}).get(k) for k in ("host","bridge_id","port","url","integration_created_addon")}
                shown = {k: v for k, v in shown.items() if v is not None}
                print(f"{domain} title={title} {shown} unique={ent.get('unique_id')}")
print("done ha scan")
PY
echo "=== HA matter ws nodes ==="
python3 - <<'PY'
import json, os, socket, struct, time, uuid

def try_port(port):
    try:
        sock = socket.create_connection(("127.0.0.1", port), timeout=2)
    except OSError as e:
        print(f"port {port} closed {e}")
        return
    sock.sendall(
        f"GET /ws HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nUpgrade: websocket\r\n"
        f"Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        f"Sec-WebSocket-Version: 13\r\n\r\n".encode()
    )
    buf = b""
    sock.settimeout(5)
    try:
        while b"\r\n\r\n" not in buf:
            buf += sock.recv(4096)
    except OSError as e:
        print(f"port {port} handshake {e}")
        sock.close()
        return
    def recv_json(timeout):
        sock.settimeout(timeout)
        acc = bytearray()
        end = time.time() + timeout
        while time.time() < end:
            try:
                chunk = sock.recv(4096)
            except (TimeoutError, socket.timeout):
                continue
            if not chunk:
                return None
            acc.extend(chunk)
            if len(acc) < 2:
                continue
            ln = acc[1] & 0x7F
            idx = 2
            if ln == 126:
                if len(acc) < 4:
                    continue
                ln = struct.unpack("!H", acc[2:4])[0]
                idx = 4
            if acc[1] & 0x80:
                idx += 4
            if len(acc) < idx + ln:
                continue
            payload = bytes(acc[idx:idx+ln])
            return json.loads(payload.decode())
        return None
    hello = recv_json(8)
    fabric = hello.get("compressed_fabric_id") if isinstance(hello, dict) else hello
    print(f"port {port} hello fabric={fabric}")
    data = json.dumps({"message_id": "n", "command": "get_nodes"}, separators=(",", ":")).encode()
    key = os.urandom(4)
    hdr = bytes([0x81, 0x80 | len(data)]) + key
    sock.sendall(hdr + bytes(b ^ key[i%4] for i,b in enumerate(data)))
    msg = recv_json(12)
    nodes = (msg or {}).get("result") or []
    if isinstance(nodes, dict):
        nodes = list(nodes.values())
    print(f"port {port} nodes={len(nodes) if hasattr(nodes,'__len__') else nodes}")
    if isinstance(nodes, list) and nodes and isinstance(nodes[0], dict):
        print(" first available", nodes[0].get("available"), "attrs", len(nodes[0].get("attributes") or {}), "id", nodes[0].get("node_id"))
    sock.close()

for port in (5580, 5581, 5582):
    try_port(port)
PY
echo "=== subnet hue http (192.168.9.0/24) ==="
python3 - <<'PY'
import socket, urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

def probe(i):
    ip = f"192.168.9.{i}"
    for path in ("/description.xml", "/api/config"):
        try:
            with urllib.request.urlopen(f"http://{ip}{path}", timeout=0.35) as resp:
                body = resp.read(600).decode("utf-8", "replace")
        except Exception:
            continue
        if "hue" in body.lower() or "philips" in body.lower() or "bridgeid" in body.lower():
            return f"HUE {ip}{path} {body.replace(chr(10),' ')[:180]}"
    return None

hits = []
with ThreadPoolExecutor(max_workers=32) as pool:
    futs = [pool.submit(probe, i) for i in range(1, 255)]
    for fut in as_completed(futs):
        hit = fut.result()
        if hit:
            hits.append(hit)
print("\n".join(hits) if hits else "no Hue HTTP on 192.168.9.0/24")
PY
echo "done. Do not pair."
