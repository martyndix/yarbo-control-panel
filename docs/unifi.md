# UniFi Protect and Access

Optional module: cameras, floodlights, sensors, and door unlock from a **local UniFi OS console**. Selected devices can appear on the **Home** grid next to Matter lights.

Not affiliated with Ubiquiti.

## What it can do

- List Protect cameras and show JPEG stills on the UniFi page and on Home. Stills refresh every few seconds. Click a still for a live view (Protect RTSPS via ffmpeg when installed, otherwise a fast snapshot stream).
- Toggle Protect floodlights (`PATCH /proxy/protect/integration/v1/lights/{id}` with `isLightForceEnabled` and `ledLevel` 6). If that does not confirm the LED, the panel logs into UniFi OS with the stored local admin and PATCHes private `lightOnSettings.isLedForceOn` (cookie + CSRF). A Control Plane API key alone cannot authenticate `/proxy/protect/api`; an HTML login page is not treated as success. Home re-reads light state in the background so On/Off from the Protect app shows on the website.
- Toggle Protect relays (`POST /relays/{id}/outputs/{outputId}/activate`), including SuperLink **USL-Relay**. Each USL-Relay is four Home rows (Out 1, Out 2, In 1, In 2). Outputs are On/Off; inputs are open/closed sensors. Relays are dry-contact outputs in Protect, not Access doors. Output ids start at 0.
- Show Protect sensor status (open/closed, motion, temperature/humidity). Home re-reads `GET /sensors` in the background so those chips stay live without blocking light clicks.
- Unlock Access doors and **door controllers** (UA Hub / Gate Hub) from the Access OpenAPI (`/api/v1/developer/doors` and `/devices`). When a door-position sensor shows **Open**, that button is labelled **Lock** but still sends the same remote unlock. With no DPS, it stays Unlock. Gate Hub three-button mode can send Open / Close / Stop (`control_cmd`).
- Show **Open / Closed** on the door controller (and door) tile, next to Unlock/Lock, from the Access door-position sensor (`door_position_status`). Home refreshes that in the background so the chip and button can change without blocking light clicks. A separate door-position row is still listed if you want it on Home on its own.
- Tick **Show on Home** per device so it behaves like another Home row (camera still, light/relay on/off, door/controller Unlock/Lock + position, sensor text). Ticks save as you click.
- Optional **doors tile** under Vestaboard on the Home tab lists every Access door with Unlock/Lock and live Open/Closed, even if it is not ticked for the room grid. Untick it in Settings → UniFi or Home ⚙️. A second tick adds door sensors (Access position and Protect contacts) that have Open/Closed but no unlock.
- Optional **Speakers / radio** (header **Radio** button): choose a station (UK nationals are built in; add your own URLs in Settings), tick speaker-capable Protect cameras/readers (for example a **G3 Reader Pro**), set volume, Play/Stop. Audio uses Protect **talkback** (Home Assistant’s media_player path). The Pi runs ffmpeg and must reach each device on **UDP 7004**. Untick **Show Radio Player in the header** in Settings → UniFi to hide the button and stop playback.
- Optional **AirPlay bridge**: the panel Pi advertises itself on the LAN (`shairport-sync`). Audio is piped to a FIFO and ffmpeg fans it out to the same Protect talkback speakers. Protect readers cannot receive AirPlay natively.

## What it cannot do (this version)

- Remote UniFi via `unifi.ui.com`.
- A Vestaboard UniFi page.
- PaperMono HOUSE buttons for UniFi devices.
- Protect recordings, event history, live two-way mic talk, or WebRTC (radio talkback out only).
- Access user / credential administration.
- Access after **Identity Enterprise** (the OpenAPI is disabled there).
- Native AirPlay / Chromecast **on** UniFi Protect hardware (use the panel AirPlay bridge instead).
- Bit-perfect HomePod-style sync across multiple Protect speakers (separate talkback sockets).

## Settings

1. Tick **UniFi** under Settings → Modules.
2. Open **Settings → UniFi**.
3. **Console host** is the LAN IP or hostname of the UniFi OS device (Dream Machine, Cloud Gateway, UNVR). Do not include `https://`.
4. Leave **Verify TLS** off unless you have installed a trusted certificate (UniFi’s default certificate is self-signed).
5. **Protect API key:** UniFi OS → Settings → Control Plane → Integrations → Create API Key. Cameras, lights, sensors, and relays need this (`X-API-KEY`). UniFi OS also shows this page under Protect → Integrations and Access → Integrations — it is the **same Control Plane key**. It cannot list Access doors.
6. **Access API token:** this is a **different** secret. Open the **Access application** (not Control Plane) → Settings → General → Advanced → API Token → Create New. Select permission scopes when creating it (they cannot be changed later):
   - `view:space` — list doors
   - `view:device` — list hubs / controllers
   - `edit:space` — remote unlock
   If Test UniFi says `you entered no-man zone` or 0 doors / 0 controllers after pasting the Control Plane key into both fields, recreate the token inside Access and paste only that into Access API token.
7. Optional **Protect local username/password** is a UniFi OS local admin. Floodlights need this when the public Integration force-on does not light the lamp — the API key cannot log into the private light API.
8. If Access is **not** hosted on UniFi OS, tick **Access is standalone (port 12445)**. The official OpenAPI host is always `https://CONSOLE:12445` (self-signed cert).
9. **Test UniFi connection**, then tick devices under **Show on Home**.

`you entered no-man zone` / `CODE_NOT_FOUND` means the panel hit Access with a Control Plane / Protect key (or the OpenAPI is disabled). Recreate the token **inside Access**. `CODE_UNAUTHORIZED` / “You do not have permission to perform this action” means an Access token reached the API but those scopes are missing.

Leave a key or token blank on later saves to keep the stored value. Credentials stay in `data/unifi-config.json` and are never sent back to the browser.

## APIs

Protect Integration API (local):

```
https://CONSOLE/proxy/protect/integration/v1/...
Header: X-API-KEY
```

Access OpenAPI (official, [API reference](https://assets.identity.ui.com/unifi-access/api_reference.pdf)):

```
https://CONSOLE:12445/api/v1/developer/...
Header: Authorization: Bearer TOKEN
```

The panel also tries UniFi OS proxy paths (`/proxy/access/api/v1/developer` and `/proxy/access/integration/v1/developer`) with Bearer or `X-API-KEY` if port 12445 is blocked.

Door list: `GET /doors` (`view:space`). Device/hub list: `GET /devices?refresh=true` (`view:device`). Unlock: `PUT /doors/{id}/unlock` (`edit:space`; POST if the console rejects PUT). The Lock label on an Open door uses that same unlock. Gate Hub: add `?control_cmd=open|close|stop`. Door position is `door_position_status` on each door.

Protect lights: the panel PATCHes the public Integration API `{ "isLightForceEnabled": true, "lightDeviceSettings": { "ledLevel": 6 } }` (`/proxy/protect/integration/v1/lights/{id}` with `X-API-KEY`). If a local admin is stored, it also logs in at `/api/auth/login` (session cached) and PATCHes `/proxy/protect/api/lights/{id}` `{ "lightOnSettings": { "isLedForceOn": true } }` with cookie + CSRF. A later HTTP 401 from UniFi OS login does not revert the website On/Off if a force PATCH already succeeded. Home re-reads `GET /lights` (and private `/proxy/protect/api/lights` when a session exists) in the same background job as sensors, so On/Off from the Protect app shows on the website. A short overlay covers Protect lag after a website click. Off sends `isLightForceEnabled` / `isLedForceOn` false. Relays: `POST /relays/{id}/outputs/{outputId}/activate` with `{ "state": "on"|"off" }`.

Protect sensors: `GET /proxy/protect/integration/v1/sensors` (`isOpened`, `stats.temperature.value`, `stats.humidity.value`). Home does not poll this on the request that serves the grid; a background process updates `data/unifi-inventory.json` so the next 3-second Home refresh can show Open and new readings. Access `GET /doors` (controller Open/Closed) is a separate poll in that same job.

Protect cameras: stills are `GET /cameras/{id}/snapshot`. Live view prefers `GET /cameras/{id}/rtsps-stream` (medium, then low) piped through ffmpeg as MJPEG (`/api/unifi.php?action=stream`). If ffmpeg or RTSPS is missing, that same URL sends a snapshot MJPEG loop (`fresh` snapshots). Thumbnails skip the 4-second JPEG cache only when `fresh=1`.

## Home

Turn **Home** on as well. UniFi rows use ids like `unifi:camera:…`. Hide or Remove of a camera or floodlight only unchecks Show on Home — nothing is unpaired on the console. Hide of one **USL-Relay** port (Out 1/2 or In 1/2) stays off Home; the other ports stay listed. Scenes can include Protect floodlights and relay outputs as on/off members (not cameras, sensors, or doors).

White-ambiance Matter bulbs show **brightness** (blue slider) and **colour temperature** (amber slider, warm to cool). Full-colour bulbs keep a colour picker.

Protect talkback (Speakers): `POST /cameras/{id}/talkback-session` (Integration API) returns a UDP/RTP URL; the panel streams the station through ffmpeg as AAC ADTS to that camera on UDP (usually port 7004). The volume slider always scales the ffmpeg stream. Optional Protect hardware volume uses the private API `PATCH /proxy/protect/api/cameras/{id}` with `speakerSettings.speakerVolume` and a UniFi OS session (local admin) — an API key alone gets HTTP 401 on that path and is not used.

**Announcement** (Radio Player modal, above Radio): hold **Hold to record** while speaking (live level meter), release to stop, listen, then Send to the ticked speakers. Or **Choose file**. Upload is `POST /api/unifi.php` with `action=radio_announce` (multipart `audio` + `targets` + `volume`) into `data/unifi-announce/`, then ffmpeg talkback. When the clip ends the player returns to Idle (header badge clears). If a station or AirPlay bridge was playing, it is interrupted for the announcement and **resumes automatically** afterward (unless you press Stop).

**AirPlay** (Radio Player modal): **Start AirPlay** advertises the panel on the LAN (`shairport-sync` → `data/airplay/audio.fifo`, and `nqptp` when installed) and remembers the ticked speakers. Status stays **Available / waiting** until an Apple device connects and plays — then talkback fan-out starts and the UI shows **Playing from** the client device name (from shairport metadata when available). **Stop AirPlay** tears down the bridge and the receiver. An **Announcement** interrupts AirPlay and resumes the bridge afterward if the device is still connected. Set the advertised name in Settings → UniFi.

Panel **Updates** (and a fresh `install.sh --deps`) install `shairport-sync` and `avahi-daemon` automatically via the Matter root helper and the panel’s passwordless `apt-get` sudoers rules — no SSH required. If packages are still missing, Radio Player shows **Install & start AirPlay**; tapping it retries the install (see `data/panel-airplay.log`). AirPlay 2 (Control Center multi-room style) additionally needs `nqptp` — build from [shairport-sync AIRPLAY2.md](https://github.com/mikebrady/shairport-sync/blob/master/AIRPLAY2.md) if your distro package is classic-only.

Open UDP **319/320** (PTP) and allow mDNS if you use a firewall. The panel tries `ufw allow` when packages install / Start AirPlay runs. Sync across several Protect speakers is looser than HomePods.

Mic hold-to-record needs a **secure context** (`https://<panel-ip>:8443`, self-signed — accept once). The panel watches :8443 and restarts the TLS proxy if it dies while HTTP :8080 is still up; Settings → Panel updates → **Start HTTPS** forces a refresh. Paper tablets keep `http://<panel-ip>:8080`. Set `YARBO_PANEL_HTTPS=0` to disable the proxy.

## Rollback

- **Radio Player only:** Settings → UniFi → untick **Show Radio Player in the header** (stops playback and hides the button). Station list stays in `data/unifi-radio-stations.json`.
- **Whole UniFi module:** untick UniFi under Settings → Modules. Credentials stay in `data/unifi-config.json`.
- **Panel version:** install a previous panel build from Settings → Panel updates if you want to undo **4.0.158** entirely. **4.0.160** only fixes Speakers panel contrast (theme text/card colours).
