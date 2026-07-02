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

### Changed
- Telemetry parsing now reads GNSS coordinates from `rtk_base_data.rover.gngga` (NMEA GNGGA) when available.

