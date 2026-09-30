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

To unstick the panel immediately:

```bash
sudo timeout 15 docker stop yarbo-matter-server; sudo systemctl restart yarbo-panel
```

After **3.0.60**, Home no longer waits on Docker during page load. If Home says **0 devices**, pairing is stored in `data/matter-server/` (not git). If that folder is empty, add the Hue Bridge pairing code once; names, rooms, and scenes stay in `data/home.json`. From 3.0.60 you can also run `sudo bash scripts/matter_diagnose.sh` in the panel folder.

