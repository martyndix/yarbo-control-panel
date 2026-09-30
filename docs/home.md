# Home (Matter)

Optional module: control **Matter** lights, plugs, switches, and heaters from this panel. Pair with a code from the **Hue app**, **Apple Home**, or the device itself. This is **not** a clone of the Home app.

Not affiliated with Apple, Signify/Philips Hue, or the Connectivity Standards Alliance.

## What it can do

- Add any Matter device that is already on your LAN (Wi-Fi / Ethernet / Thread via an existing border router such as a HomePod or Apple TV).
- **Philips Hue:** pair the **Hue Bridge** once (Hue app → Settings → Smart Home → Matter). Zigbee Hue bulbs then appear as Matter light rows (one pairing, many lights). Tap **⚙️** on the Home card to rename a light, **Hide** it on this panel, or **Remove** (unpairs a standalone Matter device, or the whole Hue Bridge). Hidden lights stay off the dashboard until you open ⚙️ again.
- On/off, brightness, and colour (Hue/Matter colour bulbs). Colour temperature-only bulbs get a warm–cool slider. After a panel update, Settings → Panel updates restarts the Matter agent so colour commands are not rejected as unknown.
- **Rooms:** tap **⚙️**, add a room, then put lights in it. Drag the **⋮⋮** handle to reorder rooms, groups, and lights. The room row controls every light in it; tap **+** to see the individual lights. Inside a room you can add a **group** (for example Spots) with its own name, on/off, and brightness. Rooms and groups are this panel’s grouping — not Apple Home or Hue rooms.
- Pairing (**Add device**) is behind **⚙️**.
- **Panel scenes:** pick which lights belong to a scene and set on/off, brightness, and colour. **Use lights that are on** snapshots only the lights that are currently on. Tap a saved scene to edit it. These are not Apple Home scenes or Hue app scenes.
- Assign up to eight lights or panel scenes to a PaperMono **HOUSE** page. Drag assigned rows to set button order (top is first). A scene button fills when that scene is active; tap again to turn those lights off.

## What it cannot do

- Read Apple Home’s accessory list, rooms, or scenes.
- Run Hue entertainment / gradient extras or Hue app scenes.
- Commission a factory-new Thread device over Bluetooth from the Pi (no Thread radio on the Pi). Share from Apple Home or pair a device that is already on the network.

## Raspberry Pi setup

You do **not** need a terminal. On a Pi panel:

1. Open **Settings → Panel updates** and install the latest version (this pulls Docker and starts python-matter-server).
2. Turn on **Settings → Modules → Home**.
3. If the Home dashboard still says the Matter server is not running, tap **Set up Matter server** (also in Settings → Home). The first download can take a few minutes.

The panel talks to [python-matter-server](https://github.com/home-assistant-libs/python-matter-server) on port **5580** via `scripts/matter_agent.py`. Storage is `data/matter-server/`. Fresh installs with `sudo ./scripts/install.sh` do the same setup.

On a Mac, Matter pairing is optional and needs Docker Desktop; use the Pi for day-to-day Home.

### Pairing tips

- Hue Bridge: Matter code from the Hue app. Do **not** also share the same bridge from Apple Home (fabric slots are limited; Apple already uses two).
- Already in Apple Home: accessory → **Turn On Pairing Mode** → paste the ~5-minute code (the printed QR is not reused).
- Other Matter devices: code or `MT:…` QR text from the vendor app.

Thread devices keep using Apple’s (or another) border router. IPv6 is turned on automatically on the Pi when the Matter server is set up.

## PaperMono

Flash firmware **0.1.51** (USB first, then Wi-Fi OTA). On the Home dashboard, tap **⚙️**, pick a tablet card (each shows how many HOUSE buttons it has), tick lights/scenes for that tablet (up to 12), and drag them into button order. Changes save as you go. The HOUSE page lists all assigned buttons on one screen. Scene buttons fill when the scene matches the lights, and a second tap turns those lights off (panel **3.0.41**, no extra flash required for that behaviour).

If the whole panel will not load (blank page, spinning forever), Home is probably blocking PHP. On the Pi paste this and send the output:

```bash
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel 2>/dev/null)"
echo "=== $(date -Is) root=$ROOT ==="
systemctl is-active yarbo-panel; systemctl show yarbo-panel -p MainPID,ActiveState,SubState,NRestarts --no-pager
git -C "$ROOT" log -1 --oneline; git -C "$ROOT" describe --tags --always
ss -lntp | grep -E ':8080|:8766|:5580' || true
timeout 8 docker ps -a --filter name=yarbo-matter-server --format '{{.Names}} {{.Status}}' 2>/dev/null || timeout 8 sudo -n docker ps -a --filter name=yarbo-matter-server
ls -lah "$ROOT/data/matter-server" | head
echo "--- timed APIs ---"
timeout 6 curl -sS -o /tmp/yarbo-status.json -w "status http=%{http_code} time=%{time_total}\n" http://127.0.0.1:8080/api/status.php
timeout 6 curl -sS -o /tmp/yarbo-home.json -w "home http=%{http_code} time=%{time_total}\n" http://127.0.0.1:8080/api/home.php
head -c 400 /tmp/yarbo-home.json; echo
timeout 3 curl -sS -m 2 -H 'Content-Type: application/json' -d '{"op":"ping"}' http://127.0.0.1:8766/; echo
echo "--- logs ---"
tail -n 40 "$ROOT/data/matter-agent.log"
journalctl -u yarbo-panel -n 40 --no-pager
```

To unstick the panel immediately, make the Hue fabric readable, then install **3.0.61**:

```bash
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel)"
sudo systemctl stop yarbo-panel
docker exec yarbo-matter-server sh -c 'chmod a+r /data/*.json /data/*.json.backup /data/*.ini; chmod a+X /data'
sudo chown -R "$(stat -c %U "$ROOT"):$(stat -c %G "$ROOT")" "$ROOT/data/matter-server"
cd "$ROOT"
git fetch origin main
./scripts/update.sh
```

Then hard-refresh. **Do not pair the Hue Bridge again** unless that folder is empty — pairing a second time uses another fabric slot.

If Home still says **0 devices**, paste this dump (do not stop the panel):

```bash
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel)"
python3 - <<PY
import json, os, stat
from pathlib import Path
root = Path("$ROOT") / "data/matter-server"
print("dir", root, "readable", os.access(root, os.R_OK))
for p in sorted(root.glob("*")):
    if not p.is_file():
        continue
    st = p.stat()
    print(f"{p.name} size={st.st_size} mode={oct(st.st_mode & 0o777)} uid={st.st_uid} readable={os.access(p, os.R_OK)}")
    if not p.name.endswith(".json") or p.name.startswith("chip"):
        continue
    try:
        data = json.loads(p.read_text())
    except Exception as e:
        print("  json error", type(e).__name__, e)
        continue
    keys = list(data)[:12] if isinstance(data, dict) else type(data).__name__
    print("  top", keys)
    nodes = data.get("nodes") if isinstance(data, dict) else None
    print("  nodes", type(nodes).__name__, (len(nodes) if hasattr(nodes, "__len__") and not isinstance(nodes, str) else None))
    if isinstance(nodes, dict) and nodes:
        first = next(iter(nodes.values()))
        if isinstance(first, dict):
            attrs = first.get("attributes") if isinstance(first.get("attributes"), dict) else {}
            print("  first node keys", list(first)[:12], "attrs", len(attrs), "sample", list(attrs)[:8])
PY
echo "--- home.json ---"
php -r '$j=json_decode(file_get_contents($argv[1]), true); echo "names=".count($j["names"]??[])." last=".count($j["last_devices"]??[])." scenes=".count($j["scenes"]??[])."\n"; $ids=[]; foreach($j["scenes"]??[] as $s){ foreach($s["actions"]??[] as $a){ if(!empty($a["id"])) $ids[$a["id"]]=true; } } echo "scene_device_ids=".count($ids)."\n";' "$ROOT/data/home.json"
echo "--- /api/home.php ---"
curl -sS -m 4 http://127.0.0.1:8080/api/home.php | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo "devices=".count($d["devices"]??[])." fabric=".json_encode($d["fabric"]??[])." err=".($d["server"]["error"]??"")."\n";'
```

After **3.0.60**, Home no longer waits on Docker during page load. **3.0.63** also reads a bare fabric node map and rebuilds lights from saved names/scenes. **3.0.64** puts node stubs back into the fabric JSON so python-matter-server can interview the existing CHIP fabric and commands work again. If that folder is truly empty, add the Hue Bridge pairing code once; names, rooms, and scenes stay in `data/home.json`. From 3.0.60 you can also run `sudo bash scripts/matter_diagnose.sh` in the panel folder.

If lights are listed but a room toggle says **Node N is not (yet) available**, the live Matter server has no node records (or they are offline). Do **not** pair the Hue Bridge again. Paste this on the Pi (it does not stop the panel), wait about 30 seconds, then toggle a light:

```bash
ROOT="$(systemctl show -p WorkingDirectory --value yarbo-panel)"
export ROOT
python3 - <<'PY'
import json, os, shutil, socket, subprocess, time
from pathlib import Path
root = Path(os.environ["ROOT"])
storage = root / "data" / "matter-server"
home = json.loads((root / "data" / "home.json").read_text())
ids = set()
for mapping in (home.get("names") or {}, home.get("rooms") or {}, home.get("groups") or {}):
    for device_id in mapping:
        if isinstance(device_id, str) and ":" in device_id:
            try:
                ids.add(int(device_id.split(":", 1)[0]))
            except ValueError:
                pass
for row in home.get("last_devices") or []:
    try:
        n = int((row or {}).get("node_id") or 0)
    except (TypeError, ValueError):
        n = 0
    if n > 0:
        ids.add(n)
ids = sorted(i for i in ids if i > 0)
print("node_ids", ids)
fabric = None
best = -1
for p in storage.glob("*.json"):
    if p.name.startswith("chip.json") or p.name.endswith(".backup"):
        continue
    size = p.stat().st_size
    if size > best:
        fabric, best = p, size
print("fabric", fabric)
if not ids or fabric is None:
    raise SystemExit("no fabric file or no saved node ids")
data = json.loads(fabric.read_text())
nodes = dict(data.get("nodes") or {}) if isinstance(data.get("nodes"), dict) else {}
now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
for node_id in ids:
    row = nodes.get(str(node_id))
    attrs = row.get("attributes") if isinstance(row, dict) else None
    if isinstance(attrs, dict) and attrs:
        continue
    nodes[str(node_id)] = {
        "node_id": node_id,
        "date_commissioned": now,
        "last_interview": now,
        "interview_version": 0,
        "available": False,
        "is_bridge": False,
        "attributes": {},
        "attribute_subscriptions": [],
    }
data["nodes"] = nodes
data["last_node_id"] = max(int(data.get("last_node_id") or 0), max(ids))
backup = Path(str(fabric) + ".nodes-restore")
if not backup.exists():
    shutil.copy2(fabric, backup)

def docker(*args):
    for prefix in ([], ["sudo", "-n"]):
        try:
            return subprocess.run(prefix + ["docker", *args], capture_output=True, text=True, timeout=30)
        except FileNotFoundError:
            continue
    raise SystemExit("docker not found")

print("stopping yarbo-matter-server")
docker("stop", "yarbo-matter-server")
fabric.write_text(json.dumps(data, separators=(",", ":")))
os.chmod(fabric, 0o644)
print("starting yarbo-matter-server")
docker("start", "yarbo-matter-server")
deadline = time.time() + 30
while time.time() < deadline:
    probe = socket.socket()
    probe.settimeout(0.4)
    try:
        probe.connect(("127.0.0.1", 5580))
        print("matter port 5580 is up")
        break
    except OSError:
        time.sleep(1)
    finally:
        probe.close()
else:
    print("port 5580 not up yet; wait and retry a light")
print("nodes now", list(nodes))
print("wait ~30s for Hue interview, then toggle a light. Do not pair again.")
PY
```

