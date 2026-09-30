#!/usr/bin/env bash
# Point Home at Home Assistant's live Matter fabric. Does not pair.
set -u
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel 2>/dev/null || true)"
if [[ -z "$ROOT" || ! -d "$ROOT/data" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT"
echo "root=$ROOT"
echo "=== disable yarbo matter container ==="
docker update --restart=no yarbo-matter-server 2>/dev/null || true
docker stop yarbo-matter-server || true
docker run --rm -v "$ROOT/data/matter-server:/data" alpine sh -c 'mv /data/1415963636765591517.json /data/1415963636765591517.json.yarbo-stub 2>/dev/null || true'
echo "=== copy HA matter storage ==="
rm -rf /tmp/yarbo-ha-matter
mkdir -p /tmp/yarbo-ha-matter
docker cp matter-server:/data/. /tmp/yarbo-ha-matter/ || sudo docker cp matter-server:/data/. /tmp/yarbo-ha-matter/
ls -lah /tmp/yarbo-ha-matter | sed -n '1,30p'
echo "=== cache lights from HA files ==="
python3 - <<'PY'
import json, shutil, time
from pathlib import Path

ROOT = Path.cwd()
SRC = Path("/tmp/yarbo-ha-matter")
OLD_NODE = 13


def looks_like_node(row):
    return isinstance(row, dict) and ("node_id" in row or "nodeId" in row)


def collect(raw, depth=0):
    if depth > 6:
        return []
    if isinstance(raw, list):
        out = []
        for item in raw:
            out.extend(collect(item, depth + 1))
        return out
    if not isinstance(raw, dict):
        return []
    if looks_like_node(raw):
        return [raw]
    for key in ("nodes", "result", "data"):
        if key in raw:
            found = collect(raw[key], depth + 1)
            if found:
                return found
    return [v for v in raw.values() if looks_like_node(v)]


def rewrite(device_id, old_node, new_node):
    if not isinstance(device_id, str) or ":" not in device_id:
        return device_id
    node_s, rest = device_id.split(":", 1)
    try:
        if int(node_s) == old_node:
            return f"{new_node}:{rest}"
    except ValueError:
        pass
    return device_id


def remap(store, old_node, new_node):
    if old_node == new_node or old_node <= 0 or new_node <= 0:
        return 0
    changed = 0
    def one(value):
        nonlocal changed
        if isinstance(value, str) and ":" in value:
            rewritten = rewrite(value, old_node, new_node)
            if rewritten != value:
                changed += 1
            return rewritten
        return value
    for key in ("names", "rooms", "groups"):
        mapping = store.get(key)
        if isinstance(mapping, dict):
            store[key] = {one(k): v for k, v in mapping.items()}
    for key in ("hidden", "device_order"):
        if isinstance(store.get(key), list):
            store[key] = [one(item) for item in store[key]]
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


found = {}
for path in sorted(SRC.glob("*.json")):
    name = path.name.lower()
    if name.startswith("chip.json") or name.endswith(".backup"):
        print("skip", path.name, path.stat().st_size)
        continue
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as e:
        print("json fail", path.name, e)
        continue
    rows = collect(data)
    print("file", path.name, "size", path.stat().st_size, "nodes", len(rows))
    for row in rows:
        try:
            nid = int(row.get("node_id") or row.get("nodeId") or 0)
        except (TypeError, ValueError):
            nid = 0
        if nid <= 0:
            continue
        attrs = row.get("attributes") if isinstance(row.get("attributes"), dict) else {}
        prev = found.get(nid)
        prev_n = len((prev or {}).get("attributes") or {})
        if prev is None or len(attrs) > prev_n:
            found[nid] = row

devices = []
print("=== node summary ===")
for nid, row in sorted(found.items()):
    attrs = row.get("attributes") if isinstance(row.get("attributes"), dict) else {}
    available = bool(row.get("available", True))
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
        if not any(str(k).startswith(f"{ep}/6/") for k in attrs):
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
    print(f" node {nid} available={available} attrs={len(attrs)} lights={lights} bridge={row.get('is_bridge')}")

print("devices", len(devices))
if not devices:
    raise SystemExit("no lights in HA matter storage")
counts = {}
for d in devices:
    counts[d["node_id"]] = counts.get(d["node_id"], 0) + 1
hue_node = max(counts, key=counts.get)
print("hue_node", hue_node, "lights", counts[hue_node])

home = ROOT / "data" / "home.json"
store = json.loads(home.read_text(encoding="utf-8"))
changed = remap(store, OLD_NODE, hue_node)
if changed:
    backup = home.with_suffix(".json.pre-remap")
    if not backup.exists():
        shutil.copy2(home, backup)
store["last_devices"] = [
    {"id": d["id"], "name": d.get("name"), "kind": d.get("kind"), "node_id": d.get("node_id"), "endpoint": d.get("endpoint")}
    for d in devices
]
home.write_text(json.dumps(store, indent=2) + "\n", encoding="utf-8")
cache = ROOT / "data" / "home-nodes-cache.json"
cache.write_text(json.dumps({"v": 4, "saved_at": int(time.time()), "devices": devices}, separators=(",", ":")), encoding="utf-8")
print("home.json remapped", changed, OLD_NODE, "->", hue_node)
print("wrote", cache.name, "and last_devices", len(devices))
print("refresh Home. Leave yarbo-matter-server stopped. Do not pair.")
PY
echo "=== yarbo container ==="
docker inspect yarbo-matter-server --format 'running={{.State.Running}} restart={{.HostConfig.RestartPolicy.Name}}' 2>/dev/null || true
echo "done"
