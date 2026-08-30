# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

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
