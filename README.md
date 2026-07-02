# Yarbo PHP Control Panel

A lightweight, local web control panel for [Yarbo](https://www.yarbo.com/) robot mowers and snow blowers. It talks directly to the robot's on-board MQTT broker on your home network — **no Yarbo cloud account required**.

This project was originally built to run on a **Raspberry Pi** (alongside Homebridge) as an always-on panel you can open from any phone, tablet, or computer on your LAN. It also runs fine on a Mac, Linux PC, NAS, or any machine with PHP — useful for development and testing.

The MQTT protocol is based on community reverse-engineering documented in [home-assistant-yarbo](https://github.com/markus-lassfolk/home-assistant-yarbo) and [python-yarbo](https://github.com/markus-lassfolk/python-yarbo).

> **Disclaimer — read before use**
>
> This is **unofficial** software. It is **not** affiliated with, endorsed by, or supported by Yarbo. By using this project you agree that:
>
> - You use it **entirely at your own risk**.
> - The author accepts **no liability** for any damage, injury, data loss, property damage, robot malfunction, or any other harm arising from its use.
> - There is **no guarantee** that it will work with your robot, firmware version, or network setup.
> - Commands sent via this panel (including manual drive) can move or stop your machine and may conflict with the official Yarbo app.
> - The MQTT protocol is reverse-engineered and **may change** without notice in future Yarbo firmware updates.
>
> If you are not comfortable with these risks, do not use this software.

---

## Screenshots

Sample telemetry data shown for illustration.

<p align="center">
  <img src="docs/screenshots/panel-desktop.png" alt="Yarbo Control Panel — desktop view showing status, manual drive, and controls" width="720">
</p>

<p align="center">
  <em>Status, manual drive D-pad, and command buttons — runs in any modern browser on your LAN.</em>
</p>

<p align="center">
  <img src="docs/screenshots/panel-location-map.png" alt="Location map mock showing live GPS marker, heading line, and Street/Satellite toggle" width="720"><br>
  <sub>Location Map mock-up (example data)</sub>
</p>

<p align="center">
  <img src="docs/screenshots/panel-location-map-satellite.png" alt="Location map example using satellite imagery with GPS marker overlay" width="720"><br>
  <sub>Location Map satellite example (sample GPS fix)</sub>
</p>

<table>
  <tr>
    <td align="center" width="50%">
      <img src="docs/screenshots/panel-status.png" alt="Status panel with battery, state, heading, and head type" width="100%"><br>
      <sub>Live status from MQTT</sub>
    </td>
    <td align="center" width="50%">
      <img src="docs/screenshots/panel-mobile.png" alt="Yarbo Control Panel on a phone-sized screen" width="280"><br>
      <sub>Mobile-friendly layout</sub>
    </td>
  </tr>
</table>

---

## What it does

Open the panel in a browser and you can:

- **View live status** — battery, working state, charging, heading, attached head type, error codes (polled every 5 seconds)
- **View live GPS on a map** — Leaflet map with Street/Satellite layers, robot position, heading line, and GPS lock status
- **Control the robot** — lights, buzzer, pause, resume, return to dock, graceful stop
- **Manual drive** — hold-to-drive D-pad (forward, back, left, right) via MQTT `cmd_vel`
- **Camera streams** — *not currently functional for most users* (see [Camera support](#camera-support-not-currently-working) below)

Commands acquire the MQTT **controller role** first (`get_controller`), which may take control away from the official Yarbo mobile app while you are using the panel.

---

## How it works

```
Browser  →  PHP web server  →  MQTT (port 1883)  →  Yarbo robot
           (this project)         on your LAN
```

1. The browser loads a simple HTML/JS UI from the PHP built-in web server.
2. API endpoints (`/api/status.php`, `/api/command.php`, `/api/drive.php`) connect to the Yarbo MQTT broker.
3. Payloads are zlib-compressed JSON, matching the format used by the Yarbo app and Home Assistant integration.
4. Telemetry is requested with `get_device_msg` and parsed from the `data_feedback` topic.

GPS map fields (`latitude`, `longitude`, `altitude`, `fix_quality`, `gps_valid`) are parsed from `rtk_base_data.rover.gngga` when the robot reports a valid GNSS/RTK fix.

You need your Yarbo's **IP address** (MQTT broker host) and **serial number** (printed on the device / found in the Yarbo app).

---

## Requirements

| Requirement | Notes |
|-------------|-------|
| **PHP 8.1+** | CLI and built-in web server |
| **PHP zlib extension** | Usually included by default |
| **Composer** | To install the MQTT client dependency |
| **Same network as Yarbo** | The host must reach the robot on port **1883** |
| **ffmpeg** | Only relevant if experimenting with cameras (not working for most users — see below) |

---

## Quick start (any platform)

```bash
git clone https://github.com/martyndix/yarbo-control-panel.git
cd yarbo-control-panel
composer install
cp config.example.php config.php
```

Edit `config.php` — at minimum set `broker_host`, `serial`, and optionally `cameras_enabled`:

```php
'broker_host' => '192.168.1.223',   // Yarbo IP on your LAN
'broker_port' => 1883,
'serial'      => 'YOUR_SERIAL_HERE',
'cameras_enabled' => false,           // keep false — camera support does not work yet (see README)
```

Verify the broker is reachable:

```bash
nc -zv <yarbo-ip> 1883
```

Start the panel:

```bash
php -S 0.0.0.0:8080 -t public
```

Open **http://localhost:8080** (or `http://<host-ip>:8080` from another device on your network).

---

## Installation by platform

### Raspberry Pi (recommended — always-on server)

Ideal for a Pi running 24/7 (e.g. next to Homebridge). Tested on Raspberry Pi OS with PHP 8.4.

**1. Install PHP and Composer**

```bash
sudo apt update
sudo apt install -y php php-cli php-mbstring php-xml php-zlib composer unzip git
```

If `composer` is not in apt, install it manually:

```bash
cd ~
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
```

**2. Clone and configure**

```bash
git clone https://github.com/martyndix/yarbo-control-panel.git ~/yarbo
cd ~/yarbo
composer install --no-dev --optimize-autoloader
cp config.example.php config.php
nano config.php
```

**3. Test manually**

```bash
cd ~/yarbo
php -S 0.0.0.0:8080 -t public
```

Visit `http://<pi-ip>:8080` from your browser, then stop the server with `Ctrl+C`.

**4. Auto-start on boot (systemd)**

A sample service file is included at `deploy/yarbo-panel.service`. Adjust the `User`, paths, and `ExecStart` PHP path if needed (`which php`).

```bash
sudo cp deploy/yarbo-panel.service /etc/systemd/system/yarbo-panel.service
# Edit paths/user if your setup differs:
sudo nano /etc/systemd/system/yarbo-panel.service

sudo systemctl daemon-reload
sudo systemctl enable yarbo-panel
sudo systemctl start yarbo-panel
sudo systemctl status yarbo-panel
```

Useful commands:

```bash
sudo systemctl restart yarbo-panel    # after config changes
sudo journalctl -u yarbo-panel -f     # live logs
```

A printable quick-reference for Pi administration is in [`docs/yarbo-pi-quick-reference.pdf`](docs/yarbo-pi-quick-reference.pdf) (also available as [HTML](docs/yarbo-pi-commands.html)).

---

### macOS

**1. Install PHP and Composer** (if not already present)

```bash
brew install php composer
```

**2. Clone, configure, and run**

```bash
git clone https://github.com/martyndix/yarbo-control-panel.git
cd yarbo-control-panel
composer install
cp config.example.php config.php
nano config.php
php -S 0.0.0.0:8080 -t public
```

Open **http://localhost:8080**.

macOS does not use systemd. To keep it running in the background you can use `launchd`, a terminal multiplexer, or run it on a Pi instead.

---

### Linux (Debian/Ubuntu and similar)

Same steps as Raspberry Pi — install PHP and Composer via your package manager, clone the repo, run `composer install`, configure `config.php`, and either:

- Run manually: `php -S 0.0.0.0:8080 -t public`
- Install the systemd unit from `deploy/yarbo-panel.service` (adjust user and paths)

**Fedora/RHEL:**

```bash
sudo dnf install php php-cli composer git
```

---

### Windows

Not the primary target platform, but it works if you have PHP and Composer installed:

1. Install [PHP for Windows](https://windows.php.net/download/) (8.1+) and [Composer](https://getcomposer.org/download/)
2. Clone this repo in PowerShell or Git Bash
3. Run `composer install`, copy `config.example.php` to `config.php`, edit your settings
4. Start the server: `php -S 0.0.0.0:8080 -t public`
5. Open **http://localhost:8080**

For an always-on setup, a Raspberry Pi or Linux VM is simpler than running a Windows service.

---

### Docker

Docker is not included yet. The panel is a single PHP process with no database — a Pi or small Linux host with systemd is the intended deployment.

---

## Configuration

Copy `config.example.php` to `config.php`. **`config.php` is git-ignored** — never commit your serial number or network details.

| Setting | Description |
|---------|-------------|
| `broker_host` | Yarbo robot IP address (MQTT broker) |
| `broker_port` | Usually `1883` |
| `serial` | Robot serial number, e.g. `24460102QU2KB269` |
| `cameras_enabled` | `false` — **keep disabled**; local camera streams do not work yet (see below) |
| `camera_host` | Override RTSP host; `null` uses `broker_host` (experimental only) |
| `ffmpeg_path` | Path to ffmpeg binary (only needed if experimenting with cameras) |

---

## GPS map support

The panel now includes a **Location Map** card (Leaflet) showing live robot GPS and heading.

- Base layers: **OpenStreetMap (Street)** and **Esri World Imagery (Satellite)**
- Marker updates from `/api/status.php` every 5 seconds
- Map shows a clear message when no valid fix is available

Notes:

- GPS depends on the robot reporting valid `gngga` telemetry.
- Indoors / under cover / without RTK lock, `gps_valid` may be `false`.
- If coordinates are missing, move the robot outdoors and wait for lock.

---

## Controls

| UI control | MQTT command | Notes |
|------------|--------------|-------|
| Lights On / Off | `light_ctrl` | All 7 LED channels |
| Buzzer | `cmd_buzzer` | |
| Pause | `planning_paused` | |
| Resume | `resume` | |
| Return to Dock | `cmd_recharge` | |
| Stop | `dstop` | Graceful stop |
| Manual drive D-pad | `set_working_state` + `cmd_vel` | Hold to move; enters manual mode |

---

## Camera support (not currently working)

**Local camera streams do not work in practice on current Yarbo hardware/firmware.** This is not a bug in this project — Yarbo has not opened up local camera access to third-party tools.

What we know from testing and community documentation:

- The official Yarbo app uses **cloud-based video** (Smart Vision), not LAN RTSP streams exposed to your network.
- The robot has internal RTSP cameras, but they sit on a private internal network and are **not reachable** from a normal home LAN connection to the robot's Wi‑Fi IP.
- MQTT commands such as `camera_toggle` and `smart_vision_control` can be sent, but they do **not** make local RTSP streams available to this panel.
- Ports 19201–19204 are documented in community reverse-engineering, but in real-world use they are not open on the broker IP for most owners.

For these reasons, **leave `cameras_enabled` set to `false`** in `config.php`. The camera-related code remains in the repository for future use if Yarbo ever enables local stream access, but it should be treated as **experimental and non-functional** today.

| Camera | Documented port | Documented URL |
|--------|-----------------|----------------|
| Front | 19201 | `rtsp://HOST:19201/live/chn0` |
| Left | 19202 | `rtsp://HOST:19202/live/chn0` |
| Right | 19203 | `rtsp://HOST:19203/live/chn0` |
| Rear | 19204 | `rtsp://HOST:19204/live/chn0` |

Do not install ffmpeg or spend time on camera tunnels unless you have independently verified RTSP access on your specific unit.

---

## Security

- The Yarbo MQTT broker (port **1883**) has **no authentication**. Anyone on your Wi‑Fi who knows the robot IP can read telemetry and send commands.
- Keep this control panel on your **LAN only** — do not port-forward port 8080 or 1883 to the internet.
- Manual drive can move the robot — use only on flat, clear ground and away from people.

---

## Troubleshooting

| Problem | What to check |
|---------|---------------|
| Status shows an error | `nc -zv <yarbo-ip> 1883` from the host running the panel |
| Commands do nothing | Yarbo app may hold controller; try again — panel calls `get_controller` first |
| Page won't load | Is PHP running? `curl http://127.0.0.1:8080/api/status.php` |
| Pi service won't start | `sudo journalctl -u yarbo-panel -n 50` — check PHP path in the service file |
| Wrong subnet | Pi and Yarbo must be able to route to each other (e.g. `192.168.9.x` → `192.168.1.x`) |

**API smoke test:**

```bash
curl -s http://localhost:8080/api/status.php
curl -X POST -d "action=lights_on" http://localhost:8080/api/command.php
```

---

## Project structure

```
yarbo-control-panel/
├── CHANGELOG.md          # Release notes (updated each publish)
├── config.example.php    # Copy to config.php (not in git)
├── public/               # Web root (index.php, assets, api/)
├── src/                  # MQTT codec, client, telemetry
├── deploy/               # systemd service template for Pi/Linux
├── docs/                 # Pi quick-reference (PDF + HTML)
└── scripts/              # Helper scripts (camera tunnel)
```

---

## Changelog

Release notes are tracked in [`CHANGELOG.md`](CHANGELOG.md).  
For each published update, add an entry with date, version tag (or `Unreleased`), and key changes.

---

## Credits

- Protocol and command reference: [home-assistant-yarbo](https://github.com/markus-lassfolk/home-assistant-yarbo) / [python-yarbo](https://github.com/markus-lassfolk/python-yarbo)
- MQTT client: [php-mqtt/client](https://github.com/php-mqtt/client)

---

## License and disclaimer

This is unofficial community software — **not affiliated with Yarbo**.

See the [disclaimer at the top of this README](#yarbo-php-control-panel) for the full terms. In short: **no warranty, no guarantee of fitness for any purpose, and no liability** to the author for any consequences of using this software. You assume all responsibility for how you use it and for the safety of people, property, and equipment around your robot.
