# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

## [4.0.33] - 2026-10-02

### Fixed
- Home Automations starts the background runner when you open Home or Automations if it was not already running. Times cannot fire (and Last ran stays empty) without that process. The Automations page keeps polling so the warning clears once the runner is up.

## [4.0.32] - 2026-10-02

### Fixed
- Time automations can fire again the same day after a previous attempt (including a leftover “already ran today” from an older build). Saving a rule also clears that mark so a new time can be tested.
- Opening Home stores this browser’s timezone when the panel clock is still UTC, so 21:00 follows the wall clock without a separate Automations visit.
- The runner evaluates time rules before UniFi polls, so a slow Protect/Access refresh cannot skip the minute.

## [4.0.31] - 2026-10-02

### Fixed
- Home Automations **time** rules no longer stick on a saved **UTC** timezone. The runner uses the panel OS zone (or the browser’s zone when that is still UTC), so 21:00 means 21:00 local.
- A time or sunset rule whose Then fails is not marked as fired for the day. It retries after cooldown, including after the exact minute has passed.
- Motion When uses Protect `motionDetectedAt` as well as the short `isMotionDetected` pulse, and the runner polls sensors about every 2 seconds so a PIR blip is not missed.
- Live UniFi open/motion overwrites Home’s cached row instead of keeping a stale copy. The Automations page shows the last runner error when a Then fails.

## [4.0.30] - 2026-10-02

### Added
- Home Automations fills sunrise/sunset **latitude and longitude** from this browser’s GPS when the page loads (and **Use my location**). Saved coords are used by the background runner.
- Each Then chip has **off after** (stay on, 1–30 min, or 1 hr). If the When fires again, that timer restarts.

### Fixed
- When and Only if dropdowns only list states the selected device can do. A motion sensor does not show open/closed; a spotlight does not show open/closed.

## [4.0.29] - 2026-10-02

### Fixed
- Home Automations **time** rules use a saved timezone (the browser’s zone when the panel is on UTC) and show **now HH:MM**, so 21:00 means 21:00 local rather than UTC. If a tick skips the exact minute, the rule still fires later that day.
- The automations page warns when the background runner is not active.

### Changed
- **Only if → Device is…** is grouped by type (Lights, Sensors, Relays, Doors, and so on) and includes cameras, hidden devices, and UniFi devices that are not on the Home grid. Sensors can be **motion** / **no motion**.

## [4.0.28] - 2026-10-02

### Added
- Home Automations **Then** can run a **scene** (for example Kitchen motion → Outdoor Lights). Scenes stay in the Add tray under Scenes.
- **Turn off after** timer on Then: after the rule fires, those lights or the scene turn off. If the When fires again, the timer restarts.

## [4.0.27] - 2026-10-02

### Changed
- Home Automations **Add** chips are grouped by type (Time, Lights, Sensors, Relays, Doors, Controllers, Scenes, and so on) and sorted by name inside each group.

## [4.0.26] - 2026-10-02

### Added
- Home **Automations**: a When / Then page (drag chips, or tap) for time, sunrise/sunset, device edges, **open/on for X minutes**, and sensor thresholds. Rules are stored in `data/home-automations.json` and run from `scripts/home_automations.php` next to the Vestaboard watcher — not on `php -S`. Times follow the panel OS timezone. Settings → Panel updates restarts the sidecar.

### Fixed
- Room and group **On/Off** skip UniFi sensors, cameras, and Access doors so a Kitchen sensor no longer returns **That UniFi device is read-only on Home**. If nothing in the room can toggle, Home says **No controllable devices**.

## [4.0.25] - 2026-10-02

### Fixed
- Access **Lock** on a door controller is only a label. It still sends the same remote **Unlock** as before (`PUT /doors/{id}/unlock`). 4.0.24 sent Access `lock_rule`, which did not work. Controllers with no door-position sensor stay on Unlock.

## [4.0.24] - 2026-10-02

### Changed
- Access door controllers now rename **Unlock** to **Lock** when the door-position chip is **Open** (and back to Unlock when Closed). Pressing Lock sends Access `PUT /doors/{id}/lock_rule` (`lock_now`, then `lock_early` if the console rejects that type) instead of another unlock. Same on Home and the UniFi page.

## [4.0.23] - 2026-10-02

### Fixed
- UniFi floodlight On/Off on Home now follows the **Protect app** (and other clients). 4.0.22 kept the last website click and did not re-read `GET /lights` while you stayed on Home. A background poll updates the LED state; a short overlay still covers Protect lag after a website click so the green indicator does not snap back.

## [4.0.22] - 2026-10-02

### Fixed
- UniFi floodlight **On/Off on the website** now stays in sync with the lamp. A click that actually switched the light was still returning HTTP 401 from a later UniFi OS login, so the green indicator snapped back. A successful force PATCH now stores On/Off and is returned as success even if a follow-up login is 401. Home keeps that state across refreshes.

## [4.0.21] - 2026-10-02

### Fixed
- Protect **UP Sense** tiles (Open/Closed, temperature, humidity) now refresh in the same background job as Access door position. 4.0.19 only re-read Access `GET /doors`, so a Garage-style Protect sensor stayed on `Closed · 21.9° · 72% RH` while you stayed on Home. Access controllers are unchanged.
- UniFi floodlights now log into UniFi OS with the stored local admin (cookie + CSRF) and PATCH private `lightOnSettings.isLedForceOn` when the public Integration `isLightForceEnabled` response does not confirm the LED. A 200 HTML login page is still ignored. If no local admin is saved and the lamp does not switch, Test UniFi / the click error asks you to add one under Settings → UniFi.

## [4.0.20] - 2026-10-02

### Fixed
- UniFi floodlights now force the LED through Protect’s **public** Integration API (`PATCH /proxy/protect/integration/v1/lights/{id}` with `isLightForceEnabled` and `ledLevel` 6), which is what the Control Plane API key can authenticate. 4.0.17 tried the private `/proxy/protect/api` path first; that endpoint needs a login cookie, often returns the UniFi OS HTML page as HTTP 200, and the panel treated that as success so the real PATCH never ran. HTML/login responses are ignored; the private API remains a fallback only when it returns JSON.

## [4.0.19] - 2026-10-02

### Fixed
- Home **Open / Closed** on Access door controllers now follows the door. 4.0.17 stopped polling Access on every Home refresh so light clicks were not stuck behind GET `/doors`; that left the chip frozen. Home still returns the last inventory immediately, and a background process re-reads Access door position so the next 3-second Home refresh can show Open.

## [4.0.18] - 2026-10-01

### Fixed
- Apple Home / Hue app On/Off, brightness, and colour pull back onto the website again, without blocking website clicks. The Matter agent (v15) reads On/Off on the same poll as brightness (Hue often does not push those events), fills the live list from `get_nodes` if listen is empty, does not wait for the Hue `start_listening` dump before polling, and pauses that poll while a panel command is in flight. Settings → Panel updates restarts the agent; do not pair Hue again.

## [4.0.17] - 2026-10-01

### Fixed
- Home On/Off from the website reaches Hue and other Matter lights again. 4.0.15 polled Access door status on every Home refresh so Open/Closed stayed live; on the single-threaded panel server that blocked light clicks. Home now reads the last UniFi inventory; Open/Closed still updates on the UniFi page.
- UniFi floodlights now PATCH Protect’s private `lightOnSettings.isLedForceOn` (the same path Home Assistant uses) before the public `isLightForceEnabled` API.

## [4.0.16] - 2026-10-01

### Fixed
- UniFi floodlights now force the LED on/off (`isLightForceEnabled`, with brightness `ledLevel` 6 when turning on). The previous PATCH set Protect’s **schedule** to `always`/`off` and treated that as the light being on, so the website showed On while the floodlight stayed off.

## [4.0.15] - 2026-10-01

### Fixed
- Home now shows UniFi sensor readings and Access **Open / Closed** on the same tiles as Unlock. Those fields were on the UniFi page but dropped when Home built its grid, so controllers only had an Unlock button.

## [4.0.14] - 2026-10-01

### Added
- Access door position now shows as **Open** / **Closed** in the same actions box as Unlock on the door controller (and door) tile, like temperature and humidity on Protect sensors. Home refreshes that status from Access every few seconds. Tick the controller on Home — you do not need a separate door-sensor row.

## [4.0.13] - 2026-10-01

### Fixed
- Access doors still showed 0 when the same UniFi OS Control Plane key was pasted into Protect and Access. That page is shared; the key is only for Protect. Hitting `:12445` with it returns `you entered no-man zone`. Test UniFi now says to create a token **inside the Access app** (Settings → General → Advanced → API Token) and tries the Access proxy with `X-API-KEY` before the standalone OpenAPI port.

## [4.0.12] - 2026-10-01

### Fixed
- Home no longer marks every Hue light On just because brightness is stored while they are Off. The Matter agent (v14) takes On/Off only from the On/Off cluster; CurrentLevel updates the slider only. Settings → Panel updates restarts the agent; do not pair Hue again.

## [4.0.11] - 2026-10-01

### Fixed
- Home brightness and colour from Apple Home / the Hue app now pull onto the web tiles. Hue often does not push Level Control or Color Control subscription events (On/Off still does), so the Matter agent (v13) reads those attributes on a separate poll websocket every few seconds and never blocks On/Off/colour commands. Settings → Panel updates restarts the agent; do not pair Hue again.

## [4.0.10] - 2026-10-01

### Fixed
- Home brightness and colour tiles now follow Apple Home and the Hue app. The Matter agent (v12) applies live Level Control and Color Control events (HS, XY, colour temperature), not only On/Off. A panel On/Off click no longer blocks the brightness slider from those apps for a few seconds. Settings → Panel updates restarts the agent; do not pair Hue again.

## [4.0.9] - 2026-10-01

### Fixed
- White-ambiance Hue bulbs show two sliders on purpose: **brightness** (blue) and **colour temperature** (amber, warm to cool). They are not full-colour, so there is no colour picker.
- UniFi floodlights now PATCH `lightModeSettings.mode` `always`/`off` first (without `enableAt`, which some consoles reject), then force-on and LED level, and only keep a payload if the light reports on.
- Access follows the official OpenAPI host `https://CONSOLE:12445` with `Authorization: Bearer` ([API reference](https://assets.identity.ui.com/unifi-access/api_reference.pdf)). `GET /devices?refresh=true` lists hubs. If the token returns `CODE_UNAUTHORIZED` (“You do not have permission to perform this action”), Test UniFi explains that the token needs `view:space` and `view:device` from Access → Settings → General → Advanced → API Token — a Protect Integration key cannot list door controllers.

## [4.0.8] - 2026-10-01

### Fixed
- Mill Wi-Fi panel heaters (and other named heaters) no longer show as colour lights. They advertise as On/Off Light in Matter, so Home now classifies by product name, drops the colour picker, and labels them **Heater**. Old Home cache is discarded so a previous `kind=light` row cannot stick.
- Hue colour from the panel now sends XY `MoveToColor` first (then HS), keeps the optimistic colour, and does not reload the whole Home grid after a colour click. The Matter agent restarts (v11); do not pair Hue again.
- UniFi floodlights PATCH `isLightForceEnabled` together with `lightModeSettings.mode` `always`/`off`, then fall back to force-only if the console rejects extra fields.
- Access doors and controllers are discovered on UniFi OS **and** standalone `:12445`. Probe says when an Access API token is missing instead of reporting “Connected … 0 doors, 0 controllers”. Unlock uses the same URL fallback.

## [4.0.7] - 2026-10-01

### Fixed
- Home On/Off from the **web page** now reaches Hue and UniFi lights. Apple Home → panel already worked because the 3-second GET poll could run; that same poll (plus status) was occupying the single-threaded `php -S` server so click POSTs never ran, then the poll snapped the dots back Off. Clicks now pause those GETs, keep the optimistic On/Off for a few seconds, and Home polls reuse the device cache instead of re-parsing the Hue fabric every time. UniFi On/Off is written into the cached inventory so the next Home refresh stays in sync. Do not pair Hue again.

## [4.0.6] - 2026-10-01

### Fixed
- Home On/Off from the panel now uses a **command** websocket that never waits on the Hue `start_listening` dump. Live Apple Home updates stay on a second listen socket. Clicks were returning OK, then the dots snapped back off because the command was stuck behind the dump and the poll still showed Off. The update restarts the Matter agent (v10); do not pair Hue again.

## [4.0.5] - 2026-10-01

### Fixed
- Home On/Off now stays in sync both ways: panel clicks still reach the lights, and Apple Home / wall-switch changes update the dots. The Matter agent keeps one websocket, calls `start_listening` once, and applies `attribute_updated` events instead of dumping `get_nodes` every few seconds (that left the live list empty). An empty agent list no longer freezes the saved On/Off cache. The update restarts the agent (v9); do not pair Hue again.

## [4.0.4] - 2026-10-01

### Fixed
- Home On/Off from the panel no longer waits behind Matter `get_nodes` polling, so lights respond instead of appearing to do nothing. The green on/off dots update as you click and follow Apple Home within a couple of seconds.

## [4.0.3] - 2026-10-01

### Fixed
- Protect **floodlights** now PATCH `isLightForceEnabled` (official Integration API). Sending `lightMode` was rejected with `AJV_PARSE_ERROR` / additional properties.
- Protect **relays** (UL-Relay outputs) are no longer treated as Access doors. Relays use `POST /relays/{id}/outputs/{outputId}/activate`. Access **door controllers** (UA Hub / Gate Hub) stay on `/developer/devices` and unlock the bound door.

## [4.0.2] - 2026-10-01

### Fixed
- UniFi **Show on Home** ticks no longer snap back while the list is polling. Ticks save as you click, and dashboard refresh updates status in place so camera stills stay on screen.
- The Show on Home list is grouped (cameras, lights, hubs, doors, door sensors) with the checkbox on the same row as the name.

### Added
- Access **door hubs** (UA Hub / Gate Hub) from `/api/v1/developer/devices`, with Unlock on the bound door.
- **Door position sensors** when Access reports `door_position_status` on a door. Unlock uses PUT (with POST fallback) per the Access developer API.

## [4.0.1] - 2026-10-01

### Fixed
- Settings → **Save** now persists module ticks. Broker IP, serial, email, and URL fields on other panes are `display:none` while Modules is open, so the browser was blocking submit with no error. The form uses `novalidate`; empty broker/serial still fail in JavaScript (and jump to **Yarbo**) and on the server.

## [4.0.0] - 2026-10-01

### Added
- **UniFi** module: local Protect Integration API (cameras, lights, sensors) and Access Developer API (door unlock, Gate Hub open/close/stop). Settings → UniFi stores the console host, Protect API key, and Access token. Tick devices to show them on the Home grid like Matter rows. See [docs/unifi.md](docs/unifi.md).

### Changed
- Panel version is **4.0.0**. The previous release is **3.0.70** if you need to stay on 3.x.
- Settings treats Yarbo as a peer of Lymow, Powerwall, Home, and Vestaboard. **Connection** is only the panel name. Broker IP, serial, robot name, cloud fallback, and rain sensitivity live on **Settings → Yarbo**.

## [3.0.70] - 2026-09-30

### Fixed
- PaperMono firmware **0.1.56** draws the lock-screen Wi-Fi mark as upper arcs only. 0.1.55 left the bottom half of those circles visible.

## [3.0.69] - 2026-09-30

### Fixed
- PaperMono firmware **0.1.55** keeps the touchscreen working while USB is plugged in. Reading the charger every loop had put the IP2316 on the shared I2C bus with the FT6336G. Plugged-in / charging now uses PM1 VBUS a couple of times a second, and LoRa setup holds the charger off the bus. The lock-screen logo is drawn in full below the battery so the Vestaboard grid cannot cover the bottom half.

## [3.0.68] - 2026-09-30

### Fixed
- Home classifies thermostat heaters and robotic vacuums even when Matter also advertises them as dimmable lights. They show a **Heater** or **Vacuum** label and no colour picker.

## [3.0.67] - 2026-09-30

### Fixed
- Settings → E-paper shows **Charging** when a PaperMono / Paper Colour is on USB even if the battery is already full. PaperMono firmware **0.1.54** and Paper Colour **0.2.17-colour** also draw a lightning bolt to the right of the lock-screen battery when plugged in.
- Home no longer treats thermostat heaters and robotic vacuums as colour lights. They keep On/Off where Matter provides it, without a colour picker.

### Changed
- Paired-tablet **Save name** and **Revoke** sit behind a ⚙️. Revoke asks twice, then you must type the tablet name.

## [3.0.66] - 2026-09-30

### Fixed
- Settings → Modules now saves an unticked dashboard and hides it on the website, PaperMono, Paper Colour, and Vestaboard live / rotate lists. PaperMono firmware **0.1.53** also drops POWER, LYMOW, HOUSE, and NOTE view buttons when that module is off. Queue a Wi-Fi update after this panel update so existing tablets match.

## [3.0.65] - 2026-09-30

### Added
- Settings → E-paper **Paired devices** shows each PaperMono / Paper Colour tablet battery percent and **Charging** when the tablet is on USB. PaperMono firmware **0.1.52** and Paper Colour **0.2.16-colour** send this on every status poll. Queue a Wi-Fi update after this panel update so existing tablets report power.

## [3.0.64] - 2026-09-30

### Fixed
- Home can turn lights on again after the Hue fabric JSON was left with only CSA `vendor_info` (no `nodes`). The Matter agent reads/writes node stubs via Docker `cp` when the file is root-owned (`chmod 600`), reloads python-matter-server so it can interview the existing CHIP fabric, and retries a command that failed with **Node N is not (yet) available**. An uninterviewed stub is not shown as a single **Matter node** row — saved names, rooms, and scenes stay visible until Hue endpoints return. Matter Docker is started with `--primary-interface` on the default IPv4 route, and an IPv6 default route is added on that interface so CHIP mDNS can reach a Hue Bridge on IPv4-only ISPs. If Home Assistant already runs `matter-server` on this Pi, Yarbo leaves port 5580 with that container instead of starting a second copy, and does not cache a lone **Matter node** stub over saved lights. Do not pair the Hue Bridge a second time.

## [3.0.63] - 2026-09-30

### Fixed
- Home reads more Matter fabric JSON shapes (a bare `{"1": node}` map as well as `"nodes": {...}`) and rebuilds light rows from saved names, rooms, and scenes when the live list is empty. Do not pair the Hue Bridge a second time.

## [3.0.62] - 2026-09-30

### Fixed
- Settings → Panel updates starts `yarbo-panel` again after an update even if the service was stopped first. Previously it only restarted a service that was already running, so the web UI stayed down.

## [3.0.61] - 2026-09-30

### Fixed
- Home can read the Hue fabric after Docker writes `data/matter-server` as root-only (`chmod 600`). The panel now `chmod`s those JSON files via Docker on start/update, and no longer shows **Add device** as if the lights were gone.

## [3.0.60] - 2026-09-30

### Fixed
- The panel can load again while Matter/Docker is recovering. Home and PaperMono HOUSE now read saved lights from `data/matter-server` (and `last_devices`) instead of waiting on the Matter agent. PHP’s built-in server is single-threaded, so a hung Home request had blocked every other page.

### Added
- Pi diagnostic: from the panel folder run `sudo bash scripts/matter_diagnose.sh`, or paste the one-liner in [docs/home.md](docs/home.md).

## [3.0.59] - 2026-09-30

### Fixed
- Home no longer shows **Fetch is aborted** while recovering Matter storage. The dashboard returns saved lights immediately; Docker remount and interviews run in the background.

## [3.0.58] - 2026-09-30

### Fixed
- Home recovers Matter lights from the Pi’s `data/matter-server` files and from the Docker volume when the live server answers empty (wrong mount, reset `chip.json`, or a leftover empty container). The pairing box is shown when there are no lights so the Hue Bridge code can be added once if the fabric really is gone.

## [3.0.57] - 2026-09-30

### Fixed
- Home no longer drops Matter lights when the Matter server answers with an empty node list (Hue Bridge endpoints that only advertise Bridged Node + On/Off were skipped, and a successful empty reply overwrote the last known devices). The previous list is kept until the fabric answers again.
- PaperMono firmware **0.1.51**: lock screen keeps the logo above the Vestaboard preview so the board cannot cover it. A reboot opens the page menu instead of the old Home pad.

## [3.0.56] - 2026-09-30

### Changed
- PaperMono firmware **0.1.50**: the name you set on a menu button is the title at the top of that page (so a renamed NOTE is no longer stuck as VESTABOARD).

## [3.0.55] - 2026-09-30

### Changed
- PaperMono firmware **0.1.49**: the unlock menu has one **YARBO** button. Side buttons A/B then step Status, Health, and Plans. Menu button order is set in Settings → E-paper with ▲/▼.

## [3.0.54] - 2026-09-30

### Changed
- **Messages** is a header button next to Settings (not a dashboard card). It only appears when a PaperMono or Paper Colour is paired, and a blob matches the software-update badge when mail is unread. The To dropdown and message box use the same field styling as Settings.
- PaperMono firmware **0.1.48**: notes wait on the panel if the destination tablet is off, then show when it next polls. Messages older than **7 days** are deleted from the panel store and from tablet inboxes.

## [3.0.53] - 2026-09-30

### Changed
- PaperMono firmware **0.1.47**: Settings → E-paper can show or hide each unlock-menu button. Yarbo Status / Health / Plans sit in a **YARBO** group. Vestaboard is one **NOTE** page (live grid plus YARBO / POWER / LYMOW / ALL). Hidden pages are skipped on A/B.

## [3.0.52] - 2026-09-30

### Changed
- PaperMono firmware **0.1.46**: Unlock always opens the page menu (no HOME tile — Home is still on A/B). Menu button names are set in Settings → E-paper. HOUSE uses the same 2-column tiles as the menu; long names wrap onto two lines.
- Settings no longer has **Unlock to**. Paper Colour button C still uses the last stored page (default Home).

## [3.0.51] - 2026-09-30

### Changed
- PaperMono firmware **0.1.45**: Unlock opens a **MENU** of page buttons so you can jump without stepping A/B. **MAIL** shows a notification blob when a message is unread. Unlocked pages have a **MENU** chip (blob if mail is waiting) to return to that grid. Settings → **Unlock to** is still highlighted on the menu, and is still the Paper Colour C-button destination.

## [3.0.50] - 2026-09-30

### Added
- The web dashboard is a MAIL device. Set its name on the **Mail** card (or Settings → E-paper). Tablets see that name in To / inbox, same as another PaperMono. Send to one tablet or ALL; opening a note marks it read.

## [3.0.49] - 2026-09-29

### Changed
- Settings → E-paper companions keeps screen mocks behind a closed **Screen previews** section (HOUSE, MAIL, lock screen with envelope / Unlock / OFF). Open it only when you want the pictures.
- PaperMono firmware **0.1.44**: every screen tap resets the lock timer (including MAIL typing). An unread message holds the frontlight at 100% while the cyan LED is on, until the note is opened.

## [3.0.48] - 2026-09-29

### Added
- PaperMono MAIL read receipts: the sender’s inbox keeps the outgoing note and shows **Sent**, then **Read** (with time) once the other tablet opens it. Broadcasts show **Read by** names. Firmware **0.1.43**.

## [3.0.47] - 2026-09-29

### Fixed
- PaperMono MAIL text was white-on-black (leftover from the inverted WRITE button), so rows were hard to read and **No messages yet** sat off the left edge. Body text is black on white again.
- Lock-screen envelope outline is as thick as the battery badge.

### Changed
- MAIL inbox and the open message show the time and date (house timezone). Firmware **0.1.42**.

## [3.0.46] - 2026-09-29

### Changed
- Home **⚙️** PaperMono HOUSE assignment lists every tablet as a card (name, button count, and current buttons). Tapping a tablet immediately shows that tablet’s HOUSE list. Add with a filter, drag to order, and copy buttons from another tablet. Changes save as you go.
- PaperMono HOUSE shows up to **12** buttons on one screen (no MORE pager). Assign up to 12 lights or scenes per tablet. Firmware **0.1.41**.
- PaperMono **MAIL**: unread messages keep the cyan LED on until you open one. The lock screen shows an envelope; tap it for a newest-first inbox, then view and reply. Beeps are louder.

### Fixed
- Switching the PaperMono tablet dropdown left the previous tablet’s ticks on screen, so you could not see what that device actually had assigned.

## [3.0.45] - 2026-09-29

### Fixed
- PaperMono USB flash wrote firmware, then failed to send Wi-Fi: esptool’s RTS reset drops the ESP32-S3 USB serial, and opening `/dev/ttyACM0` again was resetting the chip before it could reply **CFG_OK**. The helper now waits for the port to come back, keeps serial open (DTR/RTS idle, no hangup on close), and retries config for longer. **Send Wi-Fi only** uses the same path. esptool 5.x is called with `write-flash` / `--flash-mode` so the deprecation warnings stop. Firmware **0.1.39** / **0.2.15-colour** announces `PAPER_READY` on USB during setup.

## [3.0.44] - 2026-09-29

### Added
- Home **⚙️** drag handles reorder rooms, groups, lights, and scenes. PaperMono HOUSE buttons can be ticked and dragged so the tablet shows them in that order.

## [3.0.43] - 2026-09-29

### Fixed
- Home colour pickers were hitting a leftover Matter agent that did not know the colour command, which showed **Unknown Matter command**. Panel updates now restart that agent, and the running panel replaces an old one so colour and colour-temperature writes reach the lights.

## [3.0.42] - 2026-09-29

### Changed
- Home **⚙️** hides the scene editor and PaperMono assignment. The dashboard keeps compact scene Run buttons.

### Fixed
- Colour pickers show on Matter/Hue lights even when the Hue Bridge omits Color Control values until you set a colour.

## [3.0.41] - 2026-09-29

### Added
- Home scene editor: tick which lights belong to a scene, set on/off, brightness, and colour, or **Use lights that are on**.
- Colour control on Matter/Hue colour bulbs (colour picker; warm–cool slider for colour-temperature-only lights).
- PaperMono HOUSE treats a matching scene as on; a second tap turns that scene’s lights off. No extra firmware flash.

### Fixed
- Saving a scene no longer includes every light (including ones that were off), so running it does not turn the whole house on.

## [3.0.40] - 2026-09-29

### Fixed
- PaperMono **HOUSE** buttons show the full light or scene name across the button, instead of cutting it at 18 characters. Firmware **0.1.38**.

## [3.0.39] - 2026-09-29

### Added
- Companion setting **Unlock to** picks which page opens after Unlock (PaperMono) or button C (Paper Colour). Firmware **0.1.37** / **0.2.14-colour**.

## [3.0.38] - 2026-09-29

### Changed
- PaperMono lock screen uses a larger logo and drops the Vestaboard grid further down so the two do not sit on top of each other. Firmware **0.1.36**.

## [3.0.37] - 2026-09-29

### Fixed
- PaperMono lock-screen logo is flattened to a 160×160 PNG, scaled to fit, and drawn below the battery so it cannot clip the outline. The firmware no longer clips the top-left of a larger image (that looked like a missing logo and a broken battery). Percent is back inside the icon. Firmware **0.1.35**.

## [3.0.36] - 2026-09-29

### Fixed
- PaperMono battery percent sits beside the icon instead of on a white plate that hid the fill. The outline is stroked (no leftover inner-corner blobs) and the cap joins the body. Firmware **0.1.34**.

## [3.0.35] - 2026-09-29

### Fixed
- Settings no longer stays on **Updating…** after a wireless flash: the queue clears once the tablet polls after a binary was served, even if it still reports the previous version.
- PaperMono battery outline is a thick complete shape with a white gutter so the fill does not erase the body. Vestaboard letters on the lock screen and BOARD page are larger. Firmware **0.1.33**.

## [3.0.34] - 2026-09-29

### Fixed
- PaperMono lock-screen Vestaboard uses the live Note layout instead of stored empty `board_codes`.
- Battery percent sits on a white plate so the fill no longer punches glyph boxes through the icon.
- Wireless Update no longer loops **UPDATING**: the panel drops `ota_pending` after it has served a binary (or if the tablet is still on the same version after 90 seconds), and the tablet only attempts OTA once per boot. The firmware endpoint builds first when the sources are newer. Firmware **0.1.32**.

## [3.0.33] - 2026-09-29

### Fixed
- PaperMono still receives Vestaboard, Home lights, and the tablet name when the Pi is not connected to the robot MQTT broker. Health shows that MQTT status; other pages no longer show **MQTT not connected**.
- Home PaperMono assignment ticks are not wiped by the dashboard refresh before you press Save. Firmware **0.1.31**.

## [3.0.32] - 2026-09-28

### Changed
- PaperMono no longer draws **A next / B prev** on the glass. The hardware keys still change page.
- Lock and unlock use a full refresh so the previous screen does not ghost through.

### Fixed
- The header padlock tap is read while the e-paper is refreshing, and the hit area is larger.
- Padlock frames are drawn after the icon so the shackle no longer breaks the box. Firmware **0.1.30**.

## [3.0.31] - 2026-09-28

### Fixed
- USB Wi-Fi config is retried until the tablet replies **CFG_OK**. Opening serial no longer resets the ESP32, and an empty reply is no longer treated as success (that left PaperMono on the setup screen). Firmware **0.1.29** / **0.2.13-colour**.

## [3.0.30] - 2026-09-28

### Changed
- PaperMono unlocked pages no longer show the device name or firmware version. The name stays on the lock screen and the powered-off card.
- Battery sits at the top of the header, Wi-Fi underneath, so the page title is not covered.
- Lock screen no longer draws a padlock in the top-right. Unlock uses a solid U-shaped shackle.
- Vestaboard 3×15 boxes stay on the lock screen until the panel says the module is off. USB flash stores that flag with Wi-Fi.
- Settings no longer labels e-paper as Beta. Firmware **0.1.28** / **0.2.12-colour**.

## [3.0.29] - 2026-09-27

### Changed
- PaperMono **A** and **B** keep working during an e-paper refresh (presses queue and both wrap around the page list).
- Lock-screen Vestaboard grid is hidden when the Vestaboard module is off.
- Home/lock Wi-Fi icon sits halfway between the battery and the left edge, with thicker arcs and a thicker “offline” slash. Padlock shackles are thicker. Firmware **0.1.27-beta**.

## [3.0.28] - 2026-09-27

### Changed
- PaperMono off card says **TAP RED BUTTON TO BEGIN**. Firmware **0.1.26-beta**.

## [3.0.27] - 2026-09-27

### Fixed
- PaperMono frontlight brightness actually changes. LoRa setup was resetting the power-chip PWM that drives the light.
- Companion timezone, brightness, and lock layout are stored on the tablet at USB flash, so they work before the Pi is reachable. Until a zone is saved, the clock uses CET/CEST instead of UTC.

### Changed
- Lock screen defaults to **Logo and Vestaboard** and draws empty 3×15 boxes offline.
- The powered-off e-paper card shows the device name, **OFF**, and **TAP SCREEN TO BEGIN**. Firmware **0.1.25-beta**.

## [3.0.26] - 2026-09-27

### Changed
- Vestaboard live views always include **Powerwall**, **Lymow**, and **ALL** (not only when those dashboards are ticked). PaperMono **NOTE** has the same four buttons.
- PaperMono lock screen **Logo and Vestaboard** draws the 3×15 grid even when the Note is off in Settings.
- PaperMono clock timezone is set in Settings → E-paper companions (the Pi often stays on UTC, which left the tablet two hours behind). Firmware **0.1.24-beta**.

## [3.0.25] - 2026-09-27

### Fixed
- PaperMono touch uses the panel’s native coordinates, so taps land on the buttons (the extra remap was shifting every press).
- The side **red LED** stays off in normal use. It is the power chip’s default after boot, not a Wi-Fi or device-error light. A real Yarbo error still blinks red. Firmware **0.1.23-beta**.

## [3.0.24] - 2026-09-27

### Changed
- PaperMono Radio keyboard uses a real Latin font at readable size; typed text is much larger. Labels on other pages are enlarged the same way.
- The header **padlock** is a boxed button and actually locks (touch mapping is applied on unlocked pages). The lock screen stays lit so you can see it change.
- Short-press the **side button** to power off. **DEVICE → OFF** hit area matches the large button. Hold ~2 seconds is still hardware flash mode. Firmware **0.1.22-beta**.

## [3.0.23] - 2026-09-27

### Changed
- PaperMono draws a whole page in memory, then **one** e-paper update (the keyboard no longer paints letter by letter). Fast waveform for those updates; full quality only every **10** changes, as the panel docs recommend.
- Typing on Radio only refreshes the message line, not the whole keyboard.
- Power off writes a huge **OFF** on the glass, then turns off the frontlight and LED. E-paper keeps **OFF** after shutdown. Lock screen has **OFF** next to Unlock. Firmware **0.1.21-beta**.

## [3.0.22] - 2026-09-27

### Changed
- PaperMono **page changes use a full refresh** so the previous screen does not ghost; live updates use the faster waveform.
- Unlock is a large **Unlock** padlock (same idea as the lock icon). The 1 then 2 corner taps are gone.
- Tiny page tabs are gone. Cycle screens with the **A** (next) and **B** (prev) keys. All pages stay in the loop (Yarbo, Powerwall, Lymow, Home, Radio, Device, …) even before the panel has sent module flags.
- Clock uses **NTP** when the tablet has internet, so the time is not stuck on `--:--` waiting for the Pi. Firmware **0.1.20-beta**.

## [3.0.21] - 2026-09-27

### Changed
- PaperMono uses the **fast** e-paper waveform for page changes (full quality refresh only every 10 updates). The factory demo felt quick for the same reason.
- PaperMono no longer auto-locks while Wi-Fi is down. Pages stay usable offline; Stop/Dock and other panel commands still need the server.
- Lock screen is a **padlock** on every page (top right). Short-press the side button also locks. Hold 2 seconds is still hardware flash mode — power off is **DEVICE → OFF**.
- Lock screen shows a Wi-Fi icon, or the same icon with a slash when it is not connected. Firmware **0.1.19-beta**.

## [3.0.20] - 2026-09-27

### Fixed
- PaperMono firmware builds again: `applyFrontlight` was dropped while adding the battery badge, so **Build firmware** failed.

## [3.0.19] - 2026-09-27

### Fixed
- PaperMono lock: tapping **1** then **2** unlocks even when the touch chip reports flipped or swapped coordinates (the frontlight was coming on, but the corner hit-test missed). Box **1** fills after the first tap.
- PaperMono battery is a battery outline with the percentage inside (no more **TAB** prefix). Needs a USB reflash of firmware **0.1.18-beta**.

## [3.0.18] - 2026-09-27

### Fixed
- PaperMono lock screen **unlock works without Wi-Fi** (touch was ignored while joining). Type is larger, with bigger 1 / 2 tap targets. Logo still loads from the panel after the tablet is on site Wi-Fi.

## [3.0.17] - 2026-09-27

### Fixed
- USB flash writes a **factory image** (bootloader + partitions + app). The previous kit put PlatformIO’s app-only `firmware.bin` at `0x0`, which overwrote the bootloader. The tablet then watchdog-looped and kept the factory e-paper demo.

## [3.0.16] - 2026-09-27

### Fixed
- USB setup kit waits for the tablet to boot after esptool, then retries Wi-Fi config until it sees `CFG_OK` (an empty serial reply is no longer treated as success).

## [3.0.15] - 2026-09-27

### Fixed
- USB setup kit on a Mac no longer asks for `python3 -m pip install` (Homebrew Python blocks that). `flash.py` creates a `.venv` in the unzipped folder and installs esptool there.

## [3.0.14] - 2026-09-27

### Fixed
- PaperMono firmware builds again: the Vestaboard Note page function name was dropped when HOUSE was added, so **Build firmware** failed on `drawNotePage`.

## [3.0.13] - 2026-09-27

### Added
- **USB setup kit** for PaperMono and Paper Colour: Settings → **Download USB setup kit** packs firmware, site Wi-Fi, and a laptop `flash.py` so the first USB flash can happen on a Mac or Windows PC instead of the panel host.

## [3.0.12] - 2026-09-27

### Added
- Home rooms can contain named **groups** (for example Living Room → Spots) with their own on/off and brightness. Add and assign them from **⚙️**.

### Changed
- **Add device** pairing stays hidden until you tap **⚙️** on Home.

## [3.0.11] - 2026-09-27

### Fixed
- Vestaboard rotate still lists Powerwall, Lymow, and ALL when the robot is offline. Home is not a Note view yet.

## [3.0.10] - 2026-09-27

### Fixed
- Home room status dot turns green when any light in the room is on.

## [3.0.9] - 2026-09-27

### Fixed
- An offline Yarbo no longer blocks the rest of the panel: module tabs, Vestaboard Note, Home, Powerwall, and Lymow still load. The robot error stays on the Yarbo screen.

## [3.0.8] - 2026-09-26

### Changed
- Home rooms stay collapsed: tap **+** beside the room name to show its lights.

## [3.0.7] - 2026-09-26

### Added
- Home **rooms** group devices. Create a room from **⚙️**, assign lights, and turn the whole room on/off or set brightness.

## [3.0.6] - 2026-09-26

### Added
- Home devices can be **renamed** on the panel (saved in `data/home.json`). Open **⚙️** to edit names, hide/remove, or see the Hidden list. Lights that are on use a green row.

## [3.0.5] - 2026-09-26

### Changed
- Home device tiles are compact single-row controls (name, on/off, brightness, hide/remove). Hidden devices are smaller still: name plus Unhide/Remove only.

## [3.0.4] - 2026-09-26

### Added
- Home devices can be **hidden** (stay paired, leave the dashboard and PaperMono list) or **removed**. A single Hue light cannot be unpaired from Matter; Hide applies to that light, and Remove on the group heading unpairs the whole bridge.

## [3.0.3] - 2026-09-26

### Fixed
- Home device list uses each light’s own Matter name (Hue app names on a shared Hue Bridge) instead of repeating the vendor on every row. Lights from one bridge are grouped, with **Remove this device** to forget the whole node.

## [3.0.2] - 2026-09-26

### Fixed
- **Set up Matter server** no longer shows “fetch is aborted”. The panel starts Docker in the background and the page keeps polling instead of waiting for the whole download.

## [3.0.1] - 2026-09-26

### Added
- **Home / Matter setup** is part of Settings → Panel updates and the installer: Docker, IPv6, and python-matter-server start without a terminal. Settings → Home and the Home dashboard have **Set up Matter server** if the first pass is still running.

## [3.0.0] - 2026-09-26

### Added
- **Home** module: Matter controller for lights, Hue Bridge endpoints, plugs, and heaters. Pair with a code from Hue, Apple Home, or the device. Panel scenes and PaperMono HOUSE assignment. See [docs/home.md](docs/home.md).

## [2.0.57] - 2026-09-26

### Fixed
- **Save to robot** retries the path write on the LAN when cloud `get_map` / `save_pathway` times out, instead of failing with a cache note. Load saved mowing areas is the encode source for Save; Load map backups stays optional for Previous Maps files.
- **Anonymous usage ping** now sends immediately after a panel version change, so the install stats page shows the version you just installed instead of waiting ~20 hours.

### Changed
- Location Map no longer has **Listen for map save**. Save confirmations are shorter.

## [2.0.56] - 2026-09-26

### Fixed
- **Load saved mowing areas** and **Save to robot** no longer hang when cloud `get_map` never returns (common right after an app map edit). The cloud Python process is killed on a deadline, live load uses one short attempt plus the last cached map, and Load map backups prefers the newest backup slot (including auto-save).

## [2.0.55] - 2026-09-26

### Fixed
- **Save to robot** no longer times out at 90s after an earlier write created a mowing **area** with the same name as a pathway. The zone list labels **area** vs **path**. Save skips that leftover area, sends one `save_pathway`, and tells you to delete the extra area in the Yarbo app. Load map backups is the stored backup; Load saved mowing areas is the live robot map.

## [2.0.54] - 2026-09-26

### Fixed
- **Save to robot** no longer waits many minutes. Pathway Save sends at most two `save_pathway` payloads (12s each), verifies `get_map` over cloud first, and the page stops after 90s.

## [2.0.53] - 2026-09-26

### Fixed
- **Save to robot** writes pathways with `save_pathway` (captured when renaming a path in the official app). Earlier builds skipped remaining payload shapes after the first timeout, so the real command never got a matching payload. Status still reports per-list metres so a path write is not confused with a mowing-area move.

## [2.0.52] - 2026-09-26

### Fixed
- **Save to robot** no longer probes `save_path_area` / `save_pathway` / `save_path` (the robot never replies; the official [Yarbo Data SDK](https://github.com/YarboInc/YarboDataSDK) has no map-write command). Pathway Save uses a Listen-captured command when one exists, otherwise `save_clean_area` with path-only wraps. If that still does not write the path, the panel asks you to Listen while renaming that pathway.

## [2.0.51] - 2026-09-26

### Fixed
- **Save to robot** no longer dies with MQTT error 66 (broker closed an idle LAN socket while probing pathway commands on cloud). The panel reconnects if the socket drops, skips unknown commands after one timeout, and still returns the save result.

## [2.0.50] - 2026-09-26

### Fixed
- **Save to robot** no longer sends a pathway through `save_clean_area`. That command moved 4.37 m, but not onto the pathway draft (it writes a mowing area), then Save stopped without trying pathway commands. Pathways now use `save_path_area` / `save_pathway` / `save_path`. A wrong move is rolled back by whatever list actually changed. Status reports per-list metres.

## [2.0.49] - 2026-09-26

### Fixed
- **Save to robot** no longer sends a pathway as `{pathways:[zone]}` on `save_clean_area` (that ACKs and leaves live vertices unchanged). Pathway edits retry a live-frame bare zone (the payload that moved ~86 m before rebase), then `save_path_area` / `save_pathway`. If none of those move `get_map`, the panel asks you to Listen while renaming that pathway.

## [2.0.48] - 2026-09-26

### Fixed
- **Save to robot** converts the edited zone into the live `get_map` frame before `save_clean_area`. Sending backup-file local metres as a bare zone moved the polygon ~80 m. Read-back is matched by zone id. Stale Listen results no longer appear on page load.

## [2.0.47] - 2026-09-26

### Fixed
- **Save to robot** writes with `save_clean_area` (captured when renaming an area), not `map_recovery` / a patched backup file. `upload_cloud_map_backup` is sent empty only after live `get_map` actually moves.

## [2.0.46] - 2026-09-26

### Fixed
- **Save to robot** no longer sends expanded map JSON to `upload_cloud_map_backup` (that was rejected with state `-1`, then `map_recovery` by id restored the original slot). The patched map is now sent as compressed `data` — the same wrapping as `get_map`. Recovery by id only runs if that stored slot actually changed.

## [2.0.45] - 2026-09-26

### Fixed
- **Save to robot** now stores the patched backup with `upload_cloud_map_backup`, then `map_recovery` by id. Sending edited vertices inside `map_recovery` ACKs (`state 0`) and leaves live `get_map` unchanged. If the stored slot is not replaced, Save says so instead of claiming the robot moved.

## [2.0.44] - 2026-09-26

### Fixed
- The same zone was drawn several times (five “Pathway 7” rows). Moving one copy left the others in place. The map now shows each unique zone once, and Save copies that edit onto every duplicate slot in the backup file.

## [2.0.43] - 2026-09-26

### Fixed
- Save no longer reports success when the live map is unchanged (`vs original 0`). LAN `map_recovery` can ACK and do nothing; the panel now retries over cloud and only treats the write as done if the robot vertices actually moved.
- Restore sends the patched backup file only (it was merging the original fetch envelope back in).
- Edit one zone at a time so the original line is not left on the map under a second set of vertex handles.

## [2.0.42] - 2026-09-26

### Fixed
- Editing a zone no longer leaves the original line in the draft. Save was writing the leftover original vertices (encode looked like metres of movement; the robot map did not change). Drag existing vertices only — drawing a new polygon on top is ignored. Read-back match is 25 cm so a 7 cm get_map residual counts as success.

## [2.0.41] - 2026-09-26

### Fixed
- Backup files from `get_map_buckup_from_id` use singular zone keys (`area`, `pathway`, `nogozone`, …). The panel now draws that blob and writes it back with those keys, so Save to robot can use the real backup file instead of live `get_map`.

## [2.0.40] - 2026-09-26

### Fixed
- **Load map backups** fetches the backup *file* (`id` only, 30s, LAN topic aliases) instead of treating the live `get_map` as a writable backup. `map_recovery` of get_map ACKs and does not change vertices. Save stays off until that file is in hand; the live map is still drawn for viewing.

## [2.0.39] - 2026-09-26

### Fixed
- **Save to robot** waits for the map to apply, verifies with LAN then cloud `get_map`, and only restores the Pi copy when a real backup blob was used and the robot map was read and did not match. A failed read-back no longer undoes a restore that may have worked.

## [2.0.38] - 2026-09-26

### Fixed
- **Load map backups** fetches the backup blob on LAN and cloud even when the ID list arrived without geometry, and falls back to the live `get_map` so Save to robot can still turn on. The status line shows list-entry keys and fetch errors when the blob is missing.

## [2.0.37] - 2026-09-26

### Fixed
- **Save to robot** no longer treats an empty backup-list wrapper (`areas: []`) as the map. It finds the nested get_map blob, draws that backup on the Location Map, and matches draft zones by id so edited vertices can be written back.

## [2.0.36] - 2026-09-26

### Fixed
- **Load map backups** uses Yarbo cloud MQTT after the LAN broker times out. Backup/restore is a phone-app cloud path; enable Settings → cloud fallback with the same account.

## [2.0.35] - 2026-09-26

### Added
- **Load map backups** on the Location Map: reads `get_all_map_backup` / `get_map_buckup_from_id` (read-only). If the blob matches `get_map`, **Save to robot** restores an edited copy via `map_recovery` while docked. Original backup is saved on the Pi and put back if read-back fails.

## [2.0.34] - 2026-09-26

### Added
- **Listen for map save** on the Location Map: a 2-minute background listen for the unpublished MQTT command Yardstick uses. Save a map in the official app while it runs. Does not change the robot map. **Save to robot** stays disabled.

## [2.0.33] - 2026-09-25

### Fixed
- Anonymous usage ping uses `curl` (the same HTTPS path as GitHub updates) and also fires from the web UI, so a Pi on the LAN can check in even if PHP URL wrappers are off.

## [2.0.32] - 2026-09-25

### Added
- Anonymous daily usage ping (install id, panel version, module flags, tablet counts, OS family). Documented in the README and Settings → Updates. Set `YARBO_METRICS=0` to turn it off.

## [2.0.31] - 2026-09-25

### Added
- After the first USB flash, PaperMono and Paper Colour firmware can be pushed over Wi-Fi from **Paired devices** or **Settings → Updates**. The tablet must be online. PaperMono shows **UPDATING**, beeps, and lights green; Colour shows the message. Wi-Fi stays up for the download, then the tablet reboots onto the new image. Firmware **0.1.14-beta** / **0.2.11-colour**.

## [2.0.30] - 2026-09-25

### Changed
- Single-page e-paper modules (Lymow, Powerwall, Radio, Device) show the module name once. Firmware **0.1.13-beta** / **0.2.10-colour**.

## [2.0.29] - 2026-09-25

### Changed
- PaperMono and Paper Colour top line follows the current module: **YARBO** (Colour: **YARBO · COLOUR**), **POWERWALL**, **LYMOW**, **VESTABOARD**, **RADIO**, or **DEVICE**. Firmware **0.1.12-beta** / **0.2.9-colour**.

## [2.0.28] - 2026-09-25

### Changed
- PaperMono header is **YARBO** (no BETA). Paper Colour header is **YARBO · COLOUR**. Firmware **0.1.11-beta** / **0.2.8-colour**.
- E-paper lock examples use the tablet names from Settings, and the Vestaboard 3×15 tiles stay inside the board.

### Removed
- Four Spectra colour squares on the Paper Colour home example.

## [2.0.27] - 2026-09-25

### Added
- Settings → Modules can turn **Yarbo** off like Powerwall and Lymow. At least one module must stay on. PaperMono **0.1.10-beta** and Paper Colour **0.2.7-color** hide Home / Status / Health / Plans when Yarbo is off, and PaperMono gains a Powerwall page.
- E-paper Settings examples follow the enabled modules, and the lock-screen mock uses the current tablet name, logo, and Vestaboard layout.

### Changed
- Broker IP and serial are required only when the Yarbo module is on. Vestaboard ALL and rotate views list only enabled modules.

## [2.0.26] - 2026-09-25

### Added
- Settings is a full page with a left sidebar of sections (Connection, Cloud, Rain, Modules, Vestaboard, E-paper, Appearance, Updates). Lymow and Powerwall appear in the sidebar when those modules are on. `#settings` and `#settings/papermono` open the matching section.

### Fixed
- PaperMono firmware **0.1.9-beta** compiles with RadioLib 7: LoRa `transmit()` uses a mutable String so Settings → **Build firmware** succeeds.

## [2.0.25] - 2026-09-25

### Added
- Settings **Build firmware** compiles PaperMono or Paper Colour on the panel host (installs PlatformIO if needed). **Flash** also builds first when the binary is missing or the source is newer, so you do not need a Pi terminal.

## [2.0.24] - 2026-09-25

### Added
- PaperMono **0.1.8-beta**: pocket lock (logo / live Vestaboard / both), opposite-corner unlock, DEVICE page (tablet battery, clock, power off), BOARD live Vestaboard preview, RADIO messaging over LoRa with Wi-Fi fallback, RGB + buzzer on receive and on Yarbo / Lymow / Powerwall errors.
- Paper Colour **0.2.6-color**: BOARD live Vestaboard preview and a lock screensaver (C toggles it). Same lock layout choice as PaperMono.
- Settings **E-paper companions**: rename each tablet, lock-screen layout, lock/frontlight timers, brightness, and alert toggles. All of these are central — tablets apply them on the next poll.

### Changed
- PaperMono red power button short-press returns to the lock screen. Full power-off is the DEVICE **OFF** control.

## [2.0.23] - 2026-09-24

### Changed
- Paper companion header logo is larger (about 180px on PaperMono, 140px on Paper Colour) and keeps a transparent PNG background. Re-upload the logo, then reflash **0.1.7-beta** / **0.2.5-color**.

## [2.0.22] - 2026-09-24

### Added
- Settings **Header logo** for PaperMono and Paper Colour (one PNG/JPEG, previewed on the mocks). After a reflash (**0.1.6-beta** / **0.2.4-color**) the tablet draws it top-right on every page.

## [2.0.21] - 2026-09-18

### Changed
- Vestaboard Rotate modal puts each checkbox on the same row as its label, in a tighter two-column view list.

## [2.0.20] - 2026-09-18

### Added
- Vestaboard **Rotate** on the Note card: pick which views to cycle (Yarbo, Powerwall, Lymow, ALL) and minutes per view. The board keeps rotating with the browser closed.

## [2.0.19] - 2026-09-18

### Changed
- Vestaboard Lymow page matches Yarbo: **LYMOW MOWING**, **BATTERY n%**, **WORK DONE n%** (and the same charging / idle / docking / paused lines).

## [2.0.18] - 2026-09-17

### Changed
- Map zones starts collapsed. The zone list, draft edit/export buttons, and hint only appear after opening **Map zones**.

## [2.0.17] - 2026-09-17

### Changed
- Vestaboard Lymow page shows **MOWING n%** (or **PAUSE n%**) on the middle row while a job is running, instead of **STATE n%**.

## [2.0.16] - 2026-09-16

### Fixed
- Panel updates follow GitHub `main` after a rewritten release (the first v2.0.15 was published then removed). Settings no longer stays on “update available” because `origin/main` was stuck on the deleted commit.

## [2.0.15] - 2026-09-16

### Changed
- Vestaboard Powerwall middle line shows **GRID** (import positive W, export negative W) when solar is 0W, and **SOLAR** again when generation resumes.

## [2.0.14] - 2026-09-14

### Fixed
- Powerwall battery % now polls Tesla when the 12s cache expires (the dashboard no longer keeps a 10-minute-old reading). Local Gateway SoC is converted to the Tesla-app scale. Vestaboard still holds 1% chatter for 2 minutes, but a jump of 2% or more writes immediately.

## [2.0.13] - 2026-09-13

### Changed
- Vestaboard **Powerwall**, **Lymow**, and **ALL** view buttons only appear when those modules are enabled (Note card, Settings, PaperMono **NOTE**). Paper Colour skips **WALL** / **LYMOW** pager pages the same way. Reflash PaperMono **0.1.5-beta** and Paper Colour **0.2.3-color**.
- Powerwall Vestaboard colour chip tracks live battery % on the same scale as Yarbo and Lymow: green ≥60%, yellow ≥40%, orange ≥20%, red below that. The printed % is still at most every 2 minutes.

## [2.0.12] - 2026-09-13

### Added
- Settings **Panel name** for the title (for example **28LPC Control Panel**). Leave blank for **Control Panel**.
- PaperMono **LYMOW** page, and Paper Colour Lymow page shows battery/state with the Lymow name. Reflash PaperMono **0.1.4-beta** and Paper Colour **0.2.2-color**.

### Changed
- The Lymow name sits under the title on the Lymow page, same place as the Yarbo name. The Lymow-app nickname is used when Settings is blank.

## [2.0.11] - 2026-09-13

### Added
- Settings **Lymow name**, shown on the Lymow page (uses the Lymow-app nickname when that field is blank).

### Changed
- Vestaboard view pills (Yarbo / Powerwall / Lymow / ALL) sit on the **Vestaboard Note** card so they are not mixed with the module tabs.
- The Yarbo name under the title only appears on the Yarbo page.

## [2.0.10] - 2026-09-13

### Added
- Header **Note** pills to switch the Vestaboard live view (Yarbo, Powerwall, Lymow, ALL) without opening Settings.
- PaperMono **NOTE** page for the same Vestaboard view (YARBO / WALL / LYMOW / ALL). Reflash PaperMono to **0.1.3-beta**.

### Changed
- Vestaboard Powerwall, Lymow, and ALL views no longer require those dashboards to be enabled.

## [2.0.9] - 2026-09-13

### Added
- Lymow **Progress** on the web card, and mowing **%** on the Vestaboard **STATE** row while a job is running.
- Vestaboard live module **Yarbo + Powerwall + Lymow batteries**: three rows of `%` with a colour chip on each.

### Changed
- Lymow card no longer shows the Camera up / signed in / IP hint; Battery, State, Progress, Charging, and Camera stay on the stats row.
- Vestaboard Lymow page is **LYMOW 99%**, **STATE WAIT** (or **STATE 42%** while mowing), **CHARGING YES/NO**. Colour chip stays on row 0.

### Fixed
- Lymow mowing percent was up to a minute late because the MQTT listener only wrote state after a 70s wait. It now stays connected and writes each status message as it arrives, and asks the mower for a refresh about every 15 seconds.

## [2.0.8] - 2026-09-13

### Fixed
- Lymow Sign in with region **Auto** no longer fails with `'auto'`. Settings can stay on Auto; the resolved AWS region (e.g. `eu-west-1`) is kept for MQTT.

## [2.0.7] - 2026-09-13

### Added
- Lymow MQTT libraries install themselves on the Pi when missing (`python3 -m pip install --break-system-packages paho-mqtt websocket-client`), on **Panel updates** and again when you **Sign in / Test Lymow**.

## [2.0.6] - 2026-09-13

### Fixed
- Lymow battery: install `websocket-client` with paho (required for AWS IoT websockets), register as a connected app, and fall back to the IoT device shadow over HTTPS if MQTT is quiet.
- Powerwall Vestaboard colour chip uses the same battery scale as Yarbo (green ≥60%, yellow ≥40%, orange ≥20%, red below).

## [2.0.5] - 2026-09-13

### Fixed
- Lymow battery/state after sign-in: subscribe once MQTT is actually connected, send the app’s read-only status query, wait long enough for a heartbeat, and keep a background MQTT listener. Missing `paho-mqtt` is shown on the card instead of a silent dash.

## [2.0.4] - 2026-09-13

### Added
- Lymow **app login** in Settings (email, password, region) for battery and work status. Unofficial; protocol notes from ha-lymow. No start/dock/pause from the panel.
- Lymow card: battery, state, charging, camera, plus **Stills** / **Stream**.

### Fixed
- Vestaboard Lymow page no longer clips **CAMERA** with the colour chip. Layout is **LYMOW 87%**, work state, **CAM UP/DOWN**.

## [2.0.3] - 2026-09-13

### Fixed
- Lymow camera: Settings is **IP only** (not a full RTSP URL). The dashboard shows JPEG stills about every 2–3 seconds with the ffmpeg error if a frame fails, instead of a broken MJPEG image.
- Settings → E-paper companions: large **PaperMono / Paper Colour** cards, Colour mockups, and **Flash Paper Colour firmware** so the Colour path is obvious. Colour setup screen on the tablet says Paper Colour (`0.2.1-color`).

## [2.0.2] - 2026-09-13

### Added
- Settings → **E-paper companions** lets you pick **PaperMono** or **Paper Colour**, then flash the matching firmware over USB (same Wi-Fi CFG as PaperMono).

### Changed
- Paired tablets store hardware kind. Compact status and firmware download use that kind so Paper Colour keeps `0.2.0-color` instead of the grayscale binary.

## [2.0.1] - 2026-09-13

### Fixed
- Powerwall Vestaboard: colour chip no longer eats the last character (`89%` and `OFFLINE` stay whole). Label is **POWERWALL**. Solar, draw, and grid show **watts** on the Note and the web card.
- Late Powerwall polls keep the last good reading instead of posting OFFLINE and tripping a false **Vestaboard app** hold.
- Vestaboard GET lag after our own write is not treated as an app message (recent hashes + 30s settle). Dashboard button is **Resume previous status**.

### Changed
- Powerwall Note updates: battery % at most every 2 minutes; solar and draw at most every 5 minutes.

## [2.0.0] - 2026-09-13

### Added
- **Modules:** header switcher for Yarbo, Tesla Powerwall, and Lymow. Enable them in Settings. Vestaboard shows one **live** module (Quiet hours and Vestaboard-app hold unchanged).
- **Tesla Powerwall:** house draw, solar, and battery % via **Tesla Fleet cloud** (setup in Settings and `docs/powerwall.md`) or optional local Gateway.
- **Lymow camera:** LAN RTSP (default `rtsp://192.168.40.154:10022/h264ESVideoTest`), same stream as Homebridge CameraUI.
- **Paper Colour** firmware tree (`firmware/papercolor/`, `docs/papercolor.md`) for the no-touch M5Stack PaperColor: A/B pages, C sleep.

## [1.3.53] - 2026-09-10

### Fixed
- Vestaboard dashboard preview now shows **white** and **violet** flaps (the smiley eyes were blank/black). Quiet hours colour chips include those two as well.

## [1.3.52] - 2026-09-10

### Added
- Vestaboard pauses Yarbo status when you write from the Vestaboard app: **one hour** during the day, or **until Quiet hours end** if that window is on (including a custom message already on the board). The dashboard preview shows the physical Note and **Resume Yarbo status** takes the panel back immediately.

### Changed
- README and Vestaboard docs use a clearer Note mockup (idle, 96%, READY).

## [1.3.51] - 2026-09-08

### Changed
- Work Plans live status is a compact card (Running / Paused / Idle, progress bar, remaining area) instead of a debug line with MQTT field names.

## [1.3.50] - 2026-09-08

### Fixed
- Vestaboard **WORK DONE** now uses the same Progress Ratio as the Yarbo app: **actual cleaned area ÷ plan total** (`actualCleanArea / totalCleanArea`). v1.3.49 used planned-path `finishCleanArea`, which read a few points high (85% on the Note vs 81.6% in the app). The Note still rounds to a whole number (82%); Work Plans **Plan activity** shows one decimal.

## [1.3.49] - 2026-09-08

### Fixed
- Vestaboard **WORK DONE n%** now uses live **plan_feedback** (finished area ÷ total area), not DeviceMSG. v1.3.47–1.3.48 kept **MOWER PRO** while mowing because this firmware never puts work-plan percent on the status snapshot. Work Plans **Plan activity** shows the same percent.

## [1.3.48] - 2026-09-08

### Fixed
- Vestaboard **WORK DONE n%** now looks for plan progress anywhere in DeviceMSG (not only `StateMSG.percent`). v1.3.47 kept **MOWER PRO** when this firmware omitted that one field. Work Plans **Plan activity** shows the percent and which field it came from.

## [1.3.47] - 2026-09-08

### Changed
- Vestaboard **MOWING** (and blowing) shows **WORK DONE** plus whole-number plan percent complete on the bottom row when the robot publishes it, instead of the attached module. Progress-only Note writes are limited to once every 2 minutes so the flaps do not chatter.

## [1.3.46] - 2026-09-07

### Fixed
- **Vestaboard Quiet hours** now end at 07:00 in your local timezone, not UTC. The background watcher uses that clock with no browser open, so the Note returns to live Yarbo status at the end of the window (the 07:50 screenshot was still in quiet hours because PHP was on UTC).

## [1.3.45] - 2026-09-04

### Changed
- Vestaboard **MOWING** now has a green tile after the word so the working state is easier to read at a glance.

## [1.3.44] - 2026-09-04

### Fixed
- **Vestaboard Quiet hours** now restore live Yarbo status when the window ends even if no browser tab is open. Overnight the robot is often asleep, so the watcher used to skip the morning write and wait for the dashboard. It now keeps last night’s live layout and posts that (or fresh telemetry) as soon as quiet hours end.

## [1.3.43] - 2026-09-03

### Fixed
- **Vestaboard Quiet hours** now posts live Yarbo status as soon as the window ends, even if the robot sat still all night (same layout as before quiet). Previously the Note stayed on the night message until telemetry changed.

## [1.3.42] - 2026-09-03

### Added
- **Vestaboard Quiet hours** in Settings: start/end on this host’s clock, plus a 3×15 editor for letters and colour chips. At the start of the window the Note shows that message once (no overnight flapping); live Yarbo status resumes at the end.

## [1.3.41] - 2026-09-02

### Added
- **PaperMono extra pages** (firmware **0.1.2-beta**): hardware keys cycle **Home → Status → Health → Plans**. Status and Health match the web cards. Plans lists saved work plans; tap a row then **START** (same MQTT start as the web panel). Stop / Dock / Pause / Lights stay on Home. Reflash the tablet after the panel update.

## [1.3.40] - 2026-09-02

### Changed
- **Robot name is set in Settings**, next to the serial, and that is what shows under the panel title and on PaperMono. MQTT/cloud often publish the serial itself (`24460102…`), so that is no longer used as a nickname.

## [1.3.39] - 2026-08-31

### Added
- **App-set mower name** under the panel title (and on PaperMono): MQTT nickname if the robot publishes one, otherwise the cloud `get_devices()` name from the Yarbo app Settings → Name. Cached about 6 hours so status polling does not log into the cloud every 5 seconds. Hidden until a name is known.

## [1.3.38] - 2026-08-31

### Fixed
- **Vestaboard stayed on DOCKING / HEADING HOME after a long charge** (Status Charging: No, battery already 99%): leftover `on_going_recharging` is ignored once the robot is sitting still at 95%+, and wireless pad current/voltage counts as on the dock even when `charging_status` stays 0. Status then shows Full; the Note shows **IDLE** / **CHARGED**.

## [1.3.37] - 2026-08-31

### Changed
- Vestaboard **PAUSED** centres **PLAN HOLD** with yellow tiles on both sides of the bottom row.

### Fixed
- **Vestaboard only updated while a browser tab was open**: a telemetry miss could skip the background writer, and older `php -S` units never started it. The watcher no longer overwrites the Note with OFFLINE on a single miss, ticks about every 8s, and the MQTT agent also pushes `--once` every 15s. Settings → Panel updates rewrites a leftover `php -S` unit to `panel.sh`.

## [1.3.36] - 2026-08-31

### Fixed
- **Vestaboard stayed on DOCKING / HEADING HOME after the robot was already charging**: leftover `on_going_recharging` is ignored once Charging is Yes (same pad rule as Status). The Note then shows **CHARGING** / **ON DOCK**, or **IDLE** / **CHARGED** when Full.

## [1.3.35] - 2026-08-31

### Fixed
- **Start plan and return-to-dock did nothing while manual drive still worked**: the agent waited up to 12s for a robot ack while holding the MQTT lock, so keepalive could not keep `working_state` 1 and idle firmware dropped those jobs. They now send the official payloads immediately and keep the robot awake until planning or docking actually starts.

## [1.3.34] - 2026-08-31

### Changed
- Rain on Status and the Vestaboard matches the Yarbo app slider (20–1000). Readings below 20 always clear. Set the value in **Settings → Rain sensitivity** (blank = 20).
- Vestaboard Settings layout no longer overlaps the token hint, preview caption, or buttons.

## [1.3.33] - 2026-08-30

### Changed
- Status **STATE** shows **Rain** instead of **rain**.

## [1.3.32] - 2026-08-30

### Changed
- Vestaboard rain layout is **IDLE** on the top line, attached head plus **RAIN** and a blue chip on the bottom (no DETECTED).

## [1.3.31] - 2026-08-30

### Changed
- Rain on Status and the Vestaboard follows the wet sensor reading (any value above 0), not only a start-plan rain error. Status shows **Rain** (Wet / Dry) and Connection & Health shows the raw MQTT rain fields.

## [1.3.30] - 2026-08-30

### Fixed
- Status and Vestaboard no longer treat leftover app-awake (`working_state` 1) as mowing while the robot is Full on the charger (for example after a rain-blocked plan is cancelled).

### Added
- Vestaboard shows **RAIN** and a blue chip when rain is detected; Status **STATE** is `rain`.

## [1.3.29] - 2026-08-30

### Changed
- Vestaboard no longer shows an error when the Cloud API says that message is already on the Note (HTTP 409).

## [1.3.28] - 2026-08-30

### Changed
- Vestaboard dashboard pushes when the mockup is ahead of the Note, and the watcher reloads after a panel update so it is not stuck on old PHP.

## [1.3.27] - 2026-08-30

### Changed
- Vestaboard dashboard shows **Last written** as how long ago (clock time on hover).
- Vestaboard battery percent matches Status (no longer rounded to 5%).

## [1.3.26] - 2026-08-30

### Added
- Vestaboard dashboard card shows when the Note was last updated.

## [1.3.25] - 2026-08-30

### Fixed
- Vestaboard digits were one code too high (`100%` showed as `200%` on the Note). The panel mockup used the text string, so it looked correct.

## [1.3.24] - 2026-08-30

### Added
- Vestaboard Note can use **Local API** or **Cloud API** (token from the Vestaboard app). Existing Local setups stay on Local.

## [1.3.23] - 2026-08-26

### Added
- Location Map can go full screen (header button or Escape to exit).

## [1.3.22] - 2026-08-26

### Added
- Battery colour next to the percentage: green (≥60% / Full), yellow (≥40%), orange (≥20%), red (low). The Vestaboard Note uses the same scale as a colour chip on the battery line.
- Error state is marked in red on the Status **Error** tile, and with red colour chips on the Vestaboard ERROR/CODE lines.

## [1.3.21] - 2026-08-26

### Changed
- Settings → Vestaboard Note now explains how to get the Local API key, with a link to Vestaboard’s [request form](https://www.vestaboard.com/local-api).

## [1.3.20] - 2026-08-26

### Changed
- Vestaboard dashboard card is flaps only — dropped the “Showing IDLE — same 3×15 layout” caption.

## [1.3.19] - 2026-08-26

### Added
- **Vestaboard Note dashboard section**: when enabled in Settings, the same 3×15 flap layout appears as a main-page card (hide or reorder it like the other sections).

## [1.3.18] - 2026-08-26

### Added
- **Vestaboard Note (optional)**: enable in Settings to push a 3×15 Yarbo status board (mowing / charging / idle / error) over the Local API. Live flap mockup in Settings; a background watcher updates the Note even with no browser open. See `docs/vestaboard.md`.

## [1.3.17] - 2026-08-23

### Fixed
- **Install USB tools looked stuck on “Refreshing USB ports…”**: the port list had already finished; Settings now marks that step done. Onboard Pi UARTs such as `/dev/ttyAMA10` are hidden so they are not mistaken for the PaperMono.

## [1.3.16] - 2026-08-23

### Added
- **Install USB tools** on Settings → PaperMono: installs `pyserial` and `esptool` into the panel’s `.venv` so you do not have to run `pip3` by hand. Missing-tool errors now show as a message, not inside the port dropdown.

## [1.3.15] - 2026-08-23

### Added
- **PaperMono companion (beta)**: flash **[M5Stack PaperMono SKU C153](https://docs.m5stack.com/en/core/PaperMono)** ([shop](https://shop.m5stack.com/products/m5papermono-with-lora-nfc-800x480-3-97-eink-display)) from **Settings**, then use its 480×800 SSD1677 e-paper for battery/state plus Stop, Dock, Pause, and Lights. Not PaperMono-Lite. The panel stays the MQTT brain. Firmware follows the maker’s e-paper rules (full refresh every 10 partials). See `docs/papermono.md`.

## [1.3.14] - 2026-08-22

### Fixed
- **Charging showed Full at 25%**: `BatteryMSG.status >= 3` is not a full-charge flag. Full / 100% is only used when the robot is on the pad and capacity is 95% or more.

## [1.3.13] - 2026-08-20

### Fixed
- **Battery temperature kept disappearing**: idle firmware often answers `battery_cell_temp_msg` with zeros or an empty ack. The agent now keeps the last real cell reading (and does not re-query while idle), and the UI holds that value.

### Added
- Click **Battery Temp** to see each cell’s temperature.

## [1.3.12] - 2026-08-20

### Fixed
- **Controls Stop did nothing useful and asked for confirmation**: it now sends immediately (no dialog). The agent publishes `cmd_vel` 0, hard/soft chassis stop (`dstopp` / `dstop`), then official `stop` / `stop_plan`, without waiting for a robot ack.
- **Manual drive did not move, especially from the dock**: hold-to-drive no longer opens a confirm dialog (that cancelled the pointer hold). A full robot on the pad now disables wireless charge before `cmd_vel`, and Stop no longer leaves a work-hold that starved drive keepalive.

## [1.3.11] - 2026-08-20

### Fixed
- **Status showed 95% and Charging: Yes while the Yarbo app said fully charged 100%**: MQTT `BatteryMSG.capacity` often sits at ~95% on the dock with `charging_status` still set. The panel now shows Full / 100% in that case, matching the official app.

## [1.3.10] - 2026-08-20

### Fixed
- **Battery temperature flashed then went back to a dash**: a later `battery_cell_temp_msg` ack (`{topic, state}` with no temps) was overwriting the real cell reading. The agent now keeps the last payload that actually contains a temperature, and the UI holds that value across empty polls.

## [1.3.9] - 2026-08-20

### Fixed
- **Battery temperature stayed blank on HaLow**: DeviceMSG `BatteryMSG` has capacity, not cell temps. Status now reads `battery_cell_temp_msg` (cached ~30s) without taking the controller.
- **Connection type showed Unknown while HaLow was the live link**: `wlan0: -1` is down, not primary. The panel uses the lowest non-negative `route_priority` (`hg0` → HaLow) and labels WiFi as down.
- **Start plan confirm used the numeric id**: the dialog and toasts now use the plan name (for example “Back to Charger 2”).
- **Delete was a red button on every plan row**: it is behind **Manage…** in a modal, with an in-modal confirm.

## [1.3.8] - 2026-08-20

### Fixed
- **Start plan and return-to-dock still no-op after Controller On / drive worked**: those jobs were sent on a separate work session with incomplete payloads (`start_plan` `{planId}` only, empty `cmd_recharge`). They now run on the live controller session. Start plan sends one `{planId, id, percent}` message; dock sends official `wireless_charging_cmd {cmd: 0}` then `cmd_recharge {cmd: 2}`. The agent waits for the robot ack and the UI shows that result instead of a false “sent” toast.

## [1.3.7] - 2026-08-20

### Fixed
- **Controls showed `Unknown op. Valid: ping, drive, publish, publish_variants`**: that is the PHP fallback MQTT agent, which had stolen port 8765 and did not implement Controller / Lights / Buzzer / telemetry. The panel now replaces that process with the Python agent when python-yarbo is installed. The PHP agent also implements those ops and keeps the robot awake for ~25s after start-plan, so either engine can run a job.

## [1.3.6] - 2026-08-19

### Fixed
- **Status tiles went blank after the 1.3.5 update**: killing leftover agents let a PHP fallback grab port 8765 before the Python agent finished connecting, and the panel hid those telemetry misses as "transient". The Python agent now listens immediately, status falls back when the agent cannot read telemetry, and the first failed poll shows an error instead of empty dashes.
- **Start plan still flashed then idled**: that PHP fallback only wakes twice and has no keepalive, so the job still dropped. Restarting now waits until the Python agent is actually listening.

## [1.3.5] - 2026-08-19

### Fixed
- **Start plan woke the robot then dropped it back to idle**: lights flashed, the panel showed a telemetry timeout, status flipped idle then active, and the plan never ran. Firmware only stays app-awake for about half a second unless something keeps holding it. The agent now keeps `set_working_state` 1 until planning/docking actually starts, then stops poking so the job can run.
- **Status poll fought the start command**: after a failed snapshot the panel opened a second MQTT client against the same broker. Status now stays on the persistent agent, and a brief gap after start/dock is treated as transient instead of a serial-number error.
- **Settings update could keep the old MQTT agent**: leftover `mqtt_agent` processes are stopped before the panel service restarts, so start-plan hold and other agent fixes actually load.

## [1.3.4] - 2026-08-19

### Fixed
- **Start plan and return-to-dock did nothing while manual drive still worked**: those commands need the robot awake (`set_working_state` 1). The quiet work session skipped that wake, so idle firmware dropped `start_plan` / `cmd_recharge`. They now wake once, stop the drive pad latch, and then leave keepalive off so the job can run.
- **Start plan sent two MQTT payloads**: only `planId` is sent (plus `percent` when it is above 0). The extra `id` variant could abort a start. 0% means from the beginning and no longer sends `percent: 0`.

## [1.3.3] - 2026-08-19

### Fixed
- **Heading line on the map stayed fixed**: the green line in front of the robot marker is facing direction. It now follows CombinedOdom yaw (which turns with the robot) instead of RTK compass heading, which often stays at 0 until dual-antenna heading is valid.

## [1.3.2] - 2026-08-19

### Fixed
- **Opening the panel stopped a running job**: the PHP MQTT agent no longer calls `get_controller` on startup, and map/plan reads no longer take the controller role. Watching live status does not interrupt work.
- **Starting a plan from the panel was cancelled if the phone app was open**: start/delete plan and waypoint go now use the persistent agent with a quiet controller hold (no manual wake / idle), so the official app cannot immediately steal the job back.

### Changed
- Pause, resume, stop, and return-to-dock also use a quiet work session instead of waking the robot into manual mode.
- Controls copy: watching does not need the official app closed; commanding still takes over from it.

## [1.3.1] - 2026-08-15

### Fixed
- **Blank panel / “Failed to Fetch” on macOS `php -S`**: auto-starting the MQTT agent no longer blocks the single-threaded PHP built-in server. Existing Pi systemd units that still run `php -S` keep working.
- **Map east–west flip**: local XY conversion now matches the official Yarbo Data SDK (X positive is west, Y positive is north). Reload saved areas after updating.
- **Duplicate / filled pathways**: `get_map` uses the app zone lists once; pathways, sidewalks, and dead-ends are LineStrings; charging points are Points.
- **Installer false success**: Homebrew venvs without pip no longer report `yarbo-data-sdk installed`. Optional Python packages no longer abort the PHP install.

### Changed
- **Start command**: Mac/manual installs should use `./scripts/dev.sh` (runs `scripts/panel.sh`: MQTT agent, then PHP). New systemd units do the same. Existing `php -S` services are unchanged until you re-run `sudo ./scripts/install.sh`.
- **Python venv**: create/repair with `ensurepip` / `--upgrade-deps` so `python-yarbo` can install on macOS.

## [1.3.0] - 2026-07-14

### Safety
- **Manual drive**: when testing the D-pad, use **extreme care**. Clear the area first — keep people, pets, furniture, and obstacles well out of the way. Assume the robot may accelerate or turn immediately while you hold a direction, and be ready to release / hit Stop. Manual control is for open, flat ground only; you are responsible for collision avoidance.

### Fixed
- **Buzzer**: now works via the persistent MQTT agent — official `set_sound_param` + `song_cmd` (`find yarbo`) plus millisecond-timestamped `cmd_buzzer`
- **Manual drive**: D-pad `cmd_vel` now moves the robot (firmware 3.13 ignores string `set_working_state: "manual"`; panel uses wake `state: 1`, `emergency_unlock`, and ~10 Hz `cmd_vel` bursts)
- **Lights sticking on**: sustained lights need app-controller hold + soft wake (`set_working_state=1`); no more connect–disconnect flash-then-off when the agent is running
- **Controller speech spam**: keepalive / lights / drive / buzzer no longer re-run `get_controller` (only explicit Controller On announces)
- **False charging / charge-pad block**: Charging UI and drive warnings use only `StateMSG.charging_status` (`BodyMsg.recharge_state` can false-positive)
- **Local php -S hang / Settings “Load failed”**: status polling pauses while Settings is open; fail-fast on unreachable MQTT
- **Controls feeling dead on php -S**: drive pulses no longer wait on a long controller ack; status polling pauses while driving
- **Agent keepalive / false success**: default agent is `scripts/mqtt_agent.py` (python-yarbo) so lights/drive stay reliable; PHP agent alone could look “ok” after the broker drop
- **Empty MQTT payloads**: PHP encodes empty payloads as JSON `{}` (matches python-yarbo / HA)
- **MQTT agent spawn cwd**: auto-started agent now `cd`s to the project root so `config.php` resolves

### Added
- **Persistent MQTT agent** (`scripts/mqtt_agent.py` / `mqtt_agent.php`, `./scripts/dev.sh`) — long-lived broker session for controller, lights, buzzer, and drive
- **Controller On/Off tile** (Controls + Manual Drive) — explicit app-controller hold with soft keepalive
- **Controller gate** — lights / buzzer / pause / dock / drive pad require Connected controller
- **`power_fault` awareness** — status Error line and drive banner when firmware reports a power fault that may lock chassis/audio

### Changed
- **Local controls** aligned with [python-yarbo](https://github.com/markus-lassfolk/python-yarbo) / [home-assistant-yarbo](https://github.com/markus-lassfolk/home-assistant-yarbo)
- Prefer `./scripts/dev.sh` for local development (agent + panel); hard-refresh the browser and close the official Yarbo app while testing controls
- Status prefers the MQTT agent so polling does not open competing MQTT clients
- Lights tile tracks agent desired state (firmware LED telemetry is often unreliable)

## [1.2.0] - 2026-07-09

### Added
- **Test local connection** in Settings: step-by-step diagnostics (TCP port 1883, MQTT connect, robot telemetry, cloud SDK)
- **Cloud login test**: Test cloud connection now performs a real Yarbo account login when credentials are saved

### Fixed
- **Cloud SDK detection**: installs `yarbo-data-sdk` into a project `.venv` so the panel always uses the same Python interpreter under systemd
- **Cloud bridge environment**: PHP passes `HOME` and `PATH` when spawning the Python bridge (matches update script behaviour)

### Changed
- **Connection errors**: dashboard and diagnostics distinguish MQTT connect failures from robot-not-responding (serial/wake) cases
- **Telemetry timeout**: increased from 3s to 6s on the status endpoint

## [1.1.9] - 2026-07-09

### Fixed
- **Update-available UI**: green Panel updates section, View release notes button, and settings badge now stay in sync; opening Settings no longer clears update UI on a failed re-check
- **Connection errors**: telemetry timeout (504) and MQTT errors now use the same friendly message on server and client

### Changed
- **View release notes button**: always visible in Settings; shows installed version notes when up to date, or pending update notes when an update is available
- **Asset cache busting**: `app.js` and `style.css` load with a version query string so browsers pick up updates after `git pull`

## [1.1.8] - 2026-07-09

### Fixed
- **Update changelog in Settings**: release notes appear inline when checking for updates; Panel updates section moves to the top with a stronger green highlight when an update is available
- **Remote changelog loading**: improved git access for release notes on the Pi (`safe.directory`, branch-aware remote ref, fallback when version compare finds no entries)

### Changed
- **View release notes button**: opens a read-only popup with changelog details when an update is available

## [1.1.7] - 2026-07-09

### Added
- **Hide dashboard sections**: Settings → Appearance checkboxes to show/hide panel sections (saved in browser)
- **Update changelog preview**: confirmation popup before installing an update, showing release notes from `CHANGELOG.md`

### Changed
- **Settings update highlight**: when an update is available, the Panel updates section is highlighted with a callout banner (matches the badge on the Settings button)
- **Reset dashboard layout**: restores default section order and visibility

## [1.1.6] - 2026-07-09

### Changed
- **Header**: removed "Local MQTT control" subtitle from the top of the panel

### Fixed
- **MQTT connection errors**: raw broker errors (e.g. "Connection refused") are now shown as plain-language guidance pointing users to Settings → Connection (broker IP, robot powered on, same network)

## [1.1.5] - 2026-07-09

### Added
- **Settings update badge**: green dot on the Settings button when a panel update is available (checked automatically on page load)
- **Lights control state**: tile icon and label reflect on/off (`💡` On / `🔅` Off), synced from robot telemetry when available
- **Reorderable dashboard sections**: drag ⋮⋮ handles to reorder cards; order saved in browser `localStorage`
- **Light / dark / auto themes**: Settings → Appearance (auto follows system colour scheme)
- **Compact control tiles**: icon-style controls with lights toggle, pause/resume from telemetry, and smaller footprint

### Fixed
- **Map zones panel in day mode**: zone list background now follows the active theme instead of staying dark
- **Settings update hang**: panel update polling now uses fetch timeouts, remembers restart progress, and reloads when the target git commit is detected (fixes "Waiting for panel to restart" stuck after a successful update)

## [1.1.4] - 2026-07-06

### Added
- **Map center button**: Leaflet control to recenter on the robot's live GPS fix
- **Map persistence**: loaded mowing areas and map viewport restore after page refresh (browser `localStorage`)
- **Map zones inspector**: per-zone visibility toggles, GeoJSON export, and per-zone **Edit** shortcut
- **Map load indicator**: spinner and progress bar overlay while saved areas fetch from the robot
- **Draft map editor**: drag vertices to adjust boundaries; draft syncs back to the map view when editing stops; **Save to robot** remains disabled until write commands are verified
- **Map MQTT discovery**: `scripts/capture_map_mqtt.php` to log traffic while saving in the Yarbo app; `discover_map.php --probe-writes` for safe write-command probes
- **`YarboGeo::gpsToLocal()`**: inverse coordinate helper for a future map encode path

### Fixed
- **Edit map button styling**: toggle no longer strips the base `btn` class (which caused native browser button chrome)
- **Panel update "already running"**: PHP no longer creates the update lock before `update.sh` starts; stale locks clear when progress is no longer active

## [1.1.3] - 2026-07-06

### Fixed
- **Saved mowing areas**: decode base64+zlib `get_map` payloads from MQTT; support Yarbo app map format (`areas` / `pathways` with per-zone `ref` and `range` points)
- **Map MQTT reliability**: batch `get_map` + `read_gps_ref` on one connection with retries (fixes empty map loads when sequential commands timed out)
- **Cloud map reads**: `cloud_bridge.py` follows yarbo-data-sdk v0.2 MQTT lifecycle; cloud payloads normalized like local feedback envelopes

## [1.1.2] - 2026-07-02

### Fixed
- **Settings panel update "Load failed"**: updates now run in the background so the service restart no longer drops the HTTP response; the UI polls until the panel is back and reloads automatically

## [1.1.1] - 2026-07-02

### Fixed
- **Cloud SDK install on fresh Pi/Linux**: installer and `update.sh` now install `yarbo-data-sdk` reliably on Debian/Python 3.13+ (auto `python3-pip`, `--break-system-packages` when needed, correct `yarbo_robot_sdk` import detection)

### Added
- **Panel updates**: Settings UI to check for and install updates from GitHub; `scripts/update.sh` CLI; passwordless `systemctl restart yarbo-panel` when installed with `sudo ./scripts/install.sh`

## [1.1.0] - 2026-07-02

### Added
- **One-command installer** (`scripts/install.sh`): Composer setup, `config.php`, `data/`, optional `yarbo-data-sdk`
- **`sudo ./scripts/install.sh --deps`**: apt packages on Debian/Pi, plus automatic **systemd** service (`yarbo-panel`) enabled on boot
- **Optional cloud reads** for saved maps/plans (`scripts/cloud_bridge.py`, `/api/cloud.php`) via Yarbo Data SDK
- **Web Settings** for broker IP, serial, and optional cloud credentials (no `config.php` editing required)
- **WiFi diagnostics** from `get_connect_wifi_name` (network name, signal %, security, IP)
- **Work plans** and **named waypoints** UI with local/cloud data source selectors
- **Head controls** card (mower blade height/speed, snow chute angle)
- **Map pipeline** improvements: `read_gps_ref`, local→GPS conversion (`YarboGeo`), zone GeoJSON extraction
- **Dual MQTT payload** compatibility for pause/stop/dock/start_plan (`YarboCommands`)
- Richer plan activity fields from `StateMSG`

### Changed
- **README** restructured for hybrid local-first + optional cloud; Pi quick-start is 2 commands with web Settings (no manual `config.php` editing)
- **Screenshots** refreshed with fictional demo data (no personal location/network details)
- Settings modal scrollable layout for connection, cloud, and panel updates sections

### Fixed
- Toast notifications appearing behind the Settings modal
- Map API JSON parse errors on Safari when MQTT payloads contained invalid UTF-8 sequences

## [1.0.0] - 2026-07-01

### Added
- Initial release: local MQTT control panel for Yarbo robots
- Status, drive, pause/stop/dock, work plans, waypoints, cameras (experimental), GPS map
