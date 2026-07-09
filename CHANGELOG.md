# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

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
