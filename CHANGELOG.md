# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

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
