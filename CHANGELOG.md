# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

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
- README describes **local-first** control with **optional cloud** map/plan reads
- Install docs streamlined: **2 commands on Pi**, configure via web **Settings**
- `GET /api/map.php` and `GET /api/plans.php` support `?source=local|cloud|auto`
- Settings API stores cloud credentials in `data/cloud-config.json` (gitignored)
- Screenshot assets refreshed with fictional sample data (no personal location/network details)
- Pi quick-reference HTML updated (`docs/yarbo-pi-commands.html`)

### Fixed
- Settings cloud test result and toasts appearing behind the modal (z-index + inline status)
- Map API JSON encoding and client parsing when responses contain invalid floats or non-JSON bodies

### Notes
- Saved mowing-area overlays still depend on the robot returning map data via local MQTT or cloud fallback; some firmware returns no `data_feedback` for map commands.

## [1.0.0] - 2026-07-01

### Added
- Live GPS map (Leaflet, Street/Satellite, heading overlay)
- Connection & health diagnostics card (HaLow/4G/WiFi, battery temp, RTK, route priority, LTE module)
- Beta saved-area extraction (`/api/map.php`, `YarboMap`)
- Work plans and waypoints APIs
- In-panel Settings for broker IP and serial
- `scripts/discover_map.php` map command probe tool

### Changed
- Telemetry parses GNSS from `rtk_base_data.rover.gngga` (NMEA GNGGA)
- Battery temperature supports per-cell averages (`temperature1`..`temperature6`)
