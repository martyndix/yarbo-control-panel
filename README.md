# Yarbo PHP Control Panel

Local web control panel for [Yarbo](https://www.yarbo.com/) robot mowers. Connects directly to the robot's on-board MQTT broker on your LAN — no cloud account required.

Based on the community protocol documented in [home-assistant-yarbo](https://github.com/markus-lassfolk/home-assistant-yarbo).

## Requirements

- PHP 8.1+ with the `zlib` extension
- Composer
- **ffmpeg** (for camera streams)
- Your machine on the same network as the Yarbo base station (or VPN with access)

## Camera streams

Yarbo has four RTSP cameras (front, left, right, rear). They are **not** served over MQTT — the control panel uses ffmpeg to proxy RTSP into the browser.

Default RTSP URLs (community-documented):

| Camera | Port | URL |
|--------|------|-----|
| Front | 19201 | `rtsp://HOST:19201/live/chn0` |
| Left | 19202 | `rtsp://HOST:19202/live/chn0` |
| Right | 19203 | `rtsp://HOST:19203/live/chn0` |
| Rear | 19204 | `rtsp://HOST:19204/live/chn0` |

By default `HOST` is your `broker_host`. On many Yarbo units the cameras live on an internal network and are **not** exposed on the broker IP. In that case you need an SSH tunnel to your Mac, then set `camera_host` to `127.0.0.1` in `config.php`:

```bash
# Example tunnel (requires SSH access to the robot — not always available)
ssh -L 19201:37.38.39.23:8080 -L 19202:37.38.39.13:8080 \
    -L 19203:37.38.39.33:8080 -L 19204:37.38.39.43:8080 user@yarbo-host -N
```

The panel shows **Live** (MJPEG stream) or **Snapshot** (refreshed stills) modes. Camera online/offline badges come from MQTT `camera_state` telemetry.

Install ffmpeg on macOS: `brew install ffmpeg`

## Setup

```bash
composer install
cp config.example.php config.php
```

Edit `config.php` with your Yarbo broker IP and serial number.

## Run

```bash
php -S 0.0.0.0:8080 -t public
```

Open `http://localhost:8080` in your browser.

## Security

The Yarbo MQTT broker (port 1883) has **no authentication**. Anyone on your WiFi can read telemetry and send commands. Keep this control panel on your LAN only — do not expose port 1883 or this web server to the internet.

## Controls

| Button | MQTT command |
|--------|--------------|
| Lights On/Off | `light_ctrl` |
| Buzzer | `cmd_buzzer` |
| Pause | `planning_paused` |
| Resume | `resume` |
| Return to Dock | `cmd_recharge` |
| Stop | `dstop` |

Action commands acquire the controller role first, which may take control from the Yarbo mobile app.

## Testing

```bash
# Check broker is reachable
nc -zv <yarbo-ip> 1883

# Fetch status
curl http://localhost:8080/api/status.php

# Send a command
curl -X POST -d "action=lights_on" http://localhost:8080/api/command.php
```
