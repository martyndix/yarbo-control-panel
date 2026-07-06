# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

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
