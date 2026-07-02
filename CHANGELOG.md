# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

### Added
- One-command installer: `scripts/install.sh` — Composer, `config.php`, `data/`, optional `yarbo-data-sdk`, and **systemd auto-start on boot** when run with `sudo`
- Optional Yarbo cloud bridge for map/plan **reads** (`scripts/cloud_bridge.py`, `GET/POST /api/cloud.php`)
- Web **Settings** cloud section: account credentials, data source (`auto` / `local` / `cloud`), test connection
- Map/plan data source selectors on the main UI (local MQTT with optional cloud fallback)
- `read_gps_ref` in map pipeline and local→GPS conversion (`src/YarboGeo.php`)
- Official-style zone GeoJSON extraction when map payloads include zone lists
- Head-specific controls card (mower blade height/speed, snow chute angle) via `POST /api/head.php`
- Dual MQTT payload compatibility for pause/stop/return/start_plan (`src/YarboCommands.php`)
- Richer plan activity fields from `StateMSG` (`plan_id`, `percent`, pause/error hints)
- WiFi diagnostics from `get_connect_wifi_name`: network name, signal %, security, IP (`src/YarboWifi.php`)
- Live GPS map in the web UI (`Location Map` card) with:
  - Leaflet map rendering
  - Street and Satellite layer toggle
  - Robot position marker and heading line
  - GPS fix status text
- GPS fields in status API response:
  - `latitude`
  - `longitude`
  - `altitude`
  - `fix_quality`
  - `gps_valid`
- `scripts/discover_map.php` feasibility tool to probe map-related MQTT commands (`get_map`, `read_clean_area`, `read_all_plan`, `read_recharge_point`) and dump raw results to `debug/map-dumps/`.
- Beta saved-area extraction support:
  - new endpoint `public/api/map.php`
  - new normalizer `src/YarboMap.php`
  - UI button **Load saved mowing areas (beta)** with map overlay/empty-state handling
- Connection & health diagnostics card:
  - connection type/status (HaLow/4G/WiFi best-effort)
  - battery temperature
  - wireless charging telemetry (voltage/current)
  - RTK status/fix diagnostics and link metrics (`rtcm_age`, `route_priority`, `net_module_status`)
- Work plans and waypoints:
  - `GET/POST /api/plans.php` for `read_all_plan`, `start_plan`, `del_plan`
  - `GET/POST /api/waypoints.php` for `start_way_point` and named waypoint bookmarks (`data/waypoints.json`)
  - UI cards for loading plans, start percentage, and waypoint navigation
- In-panel connection settings (`Settings` button) to edit broker IP and serial via `GET/POST /api/settings.php`

### Changed
- `POST /api/command.php` and plan start now send firmware-compatible command variants
- `GET /api/map.php` and `GET /api/plans.php` support `?source=local|cloud|auto`
- Settings API stores cloud credentials in `data/cloud-config.json` (gitignored)
- Telemetry parsing now reads GNSS coordinates from `rtk_base_data.rover.gngga` (NMEA GNGGA) when available.
- Telemetry normalization now includes additional network and battery diagnostic objects for UI rendering.
- Battery temperature extraction now supports both direct fields and per-cell averages (`temperature1`..`temperature6`), with UI source hinting.

### Notes
- Current discovery on test mower returned no matching `data_feedback` responses for map commands, so mowing-area overlays remain blocked until the robot returns structured map payloads.

