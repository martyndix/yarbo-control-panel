# Changelog

All notable changes to this project are documented in this file.

This project follows a simple Keep a Changelog style with newest entries first.

## [Unreleased]

### Added
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

### Changed
- Telemetry parsing now reads GNSS coordinates from `rtk_base_data.rover.gngga` (NMEA GNGGA) when available.
- Telemetry normalization now includes additional network and battery diagnostic objects for UI rendering.
- Battery temperature extraction now supports both direct fields and per-cell averages (`temperature1`..`temperature6`), with UI source hinting.

### Notes
- Current discovery on test mower returned no matching `data_feedback` responses for map commands, so mowing-area overlays remain blocked until the robot returns structured map payloads.

