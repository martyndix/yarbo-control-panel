# Home (Matter)

Optional module: control **Matter** lights, plugs, switches, and heaters from this panel. Pair with a code from the **Hue app**, **Apple Home**, or the device itself. This is **not** a clone of the Home app.

Not affiliated with Apple, Signify/Philips Hue, or the Connectivity Standards Alliance.

## What it can do

- Add any Matter device that is already on your LAN (Wi-Fi / Ethernet / Thread via an existing border router such as a HomePod or Apple TV).
- **Philips Hue:** pair the **Hue Bridge** once (Hue app → Settings → Smart Home → Matter). Zigbee Hue bulbs then appear as Matter light rows (one pairing, many lights). Names should match the Hue app.
- On/off and brightness.
- **Panel scenes** (sets of those on/off/brightness states). These are not Apple Home scenes or Hue app scenes.
- Assign up to eight lights or panel scenes to a PaperMono **HOUSE** page.

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

Flash firmware **0.1.15-beta** (USB first, then Wi-Fi OTA). On the Home dashboard, pick a tablet and tick lights/scenes, then **Save PaperMono assignment**. The HOUSE page appears when the Home module is on.
