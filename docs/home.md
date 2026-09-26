# Home (Matter)

Optional module: control **Matter** lights, plugs, switches, and heaters from this panel. Pair with a code from the **Hue app**, **Apple Home**, or the device itself. This is **not** a clone of the Home app.

Not affiliated with Apple, Signify/Philips Hue, or the Connectivity Standards Alliance.

## What it can do

- Add any Matter device that is already on your LAN (Wi-Fi / Ethernet / Thread via an existing border router such as a HomePod or Apple TV).
- **Philips Hue:** pair the **Hue Bridge** once (Hue app → Settings → Smart Home → Matter). Zigbee Hue bulbs then appear as Matter light endpoints.
- On/off and brightness.
- **Panel scenes** (sets of those on/off/brightness states). These are not Apple Home scenes or Hue app scenes.
- Assign up to eight lights or panel scenes to a PaperMono **HOUSE** page.

## What it cannot do

- Read Apple Home’s accessory list, rooms, or scenes.
- Run Hue entertainment / gradient extras or Hue app scenes.
- Commission a factory-new Thread device over Bluetooth from the Pi (no Thread radio on the Pi). Share from Apple Home or pair a device that is already on the network.

## Raspberry Pi setup

The panel starts `scripts/matter_agent.py`, which talks to [python-matter-server](https://github.com/home-assistant-libs/python-matter-server) on port **5580**. The easiest way to run that server is Docker **with host networking and IPv6**:

```bash
docker pull ghcr.io/home-assistant-libs/python-matter-server:stable
```

The Matter agent will `docker run --network host` a container named `yarbo-matter-server` if Docker is installed. Storage is `data/matter-server/`.

Enable **Settings → Modules → Home**, then use the **Home** dashboard to paste a pairing code.

### Pairing tips

- Hue Bridge: Matter code from the Hue app. Do **not** also share the same bridge from Apple Home (fabric slots are limited; Apple already uses two).
- Already in Apple Home: accessory → **Turn On Pairing Mode** → paste the ~5-minute code (the printed QR is not reused).
- Other Matter devices: code or `MT:…` QR text from the vendor app.

Thread devices keep using Apple’s (or another) border router. Turn on IPv6 on the Pi.

## PaperMono

Flash firmware **0.1.15-beta** (USB first, then Wi-Fi OTA). On the Home dashboard, pick a tablet and tick lights/scenes, then **Save PaperMono assignment**. The HOUSE page appears when the Home module is on.
