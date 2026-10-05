# PaperMono companion

This firmware and Settings flash path are built for **M5Stack PaperMono, SKU C153** (the full unit with NFC and LoRa). That is the board whose datasheet you pasted: ESP32-S3R8, 3.97″ SSD1677 480×800 e-paper, FT6336G touch. Paper Colour (Spectra 6, no touch) uses the same Settings page: pick **Paper Colour** so the panel flashes `firmware/papercolor/` instead. See `docs/papercolor.md`.

| | |
| --- | --- |
| **Model** | M5Stack PaperMono |
| **SKU** | **C153** |
| **Docs** | [docs.m5stack.com/en/core/PaperMono](https://docs.m5stack.com/en/core/PaperMono) |
| **Shop** | [M5PaperMono with LoRa & NFC (3.97″)](https://shop.m5stack.com/products/m5papermono-with-lora-nfc-800x480-3-97-eink-display) |
| **Not this SKU** | [PaperMono-Lite (C153-Lite)](https://docs.m5stack.com/en/core/PaperMono-Lite) — same screen/SoC, no NFC/LoRa. RADIO will use Wi-Fi fallback only on Lite. |

Hardware we compile for:

- ESP32-S3R8, 16MB flash, 8MB octal PSRAM, 2.4 GHz Wi-Fi
- 3.97″ SSD1677 e-paper, **480×800** portrait (shop listings sometimes write 800×480), 4-level grayscale
- FT6336G touch, built-in frontlight
- Two user buttons + power (ON / OFF / RESET / BOOT)
- 1150mAh battery, USB-C
- Onboard NFC (ST25R3916) and LoRa (SX1262). RADIO uses LoRa (868 MHz, house sync word) with Wi-Fi fallback.

It has **no browser**. This panel flashes native firmware over USB from **Settings**, then the tablet talks HTTP JSON to the panel. The panel stays the MQTT brain.

Treat it as a companion, not a replacement for the web UI or the official app.

Home and extra-page mocks (not photos of a flashed unit):

![PaperMono home mock](screenshots/papermono-home.png)

![PaperMono status mock](screenshots/papermono-status.png)

![PaperMono health mock](screenshots/papermono-health.png)

![PaperMono work plans mock](screenshots/papermono-plans.png)

![PaperMono setup mock](screenshots/papermono-setup.png)

## What it shows

- **Home / Status / Health / Plans:** Yarbo robot tiles (Stop / Dock on Home). Hidden when the Yarbo module is off in Settings.
- Optional **lock-screen logo** (Settings → E-paper companions). Same image on Paper Colour. A PNG or JPEG is flattened onto white and the tablet downloads it on the next poll.
- Top line is the current module. Unlocked pages use the **same name as the menu button** (so a renamed NOTE is the title on that page). Device name and firmware stay on the lock screen and the powered-off card. A **MENU** chip under the brand opens the page grid; a black blob on that chip means unread mail.
- **Note:** live 3×15 Vestaboard plus **YARBO / POWER / LYMOW / ALL** view buttons on the same page. Writes the Note when it is enabled; the lock screen still previews the same grid if you chose Vestaboard there.
- **Powerwall:** house battery, solar, and draw.
- **Lymow:** Lymow battery, state, and charging.
- **Mail:** newest messages first, each with time and date. Sent notes stay in your inbox as **Sent**, then **Read** when the other tablet (or desktop **Messages**) opens them. Tap a row to read it, **REPLY** to type, **WRITE** for a new note (~180 characters) to **ALL** or a named tablet. Tries LoRa first, then Wi-Fi via the panel (sends are always stored on the Pi so receipts work). If the other tablet is off, the note stays on the panel and arrives on the next poll. Messages older than 7 days are removed. An unread message keeps the cyan LED on and the frontlight at 100% until you open one. The lock screen shows a thick envelope — tap it to open the inbox. Beeps use full speaker volume. Any tap on the screen resets the idle lock timer.
- The web panel **Messages** button (next to Settings) is another named device. It only shows when a PaperMono is paired. Tablets can send to it. Set the desktop name there or in Settings → E-paper.
- **Device:** this tablet’s battery, clock, current Wi-Fi, and a large **OFF** control. **REMOTE WIFI** scans nearby 2.4 GHz networks and saves a travel SSID/password on the tablet (it does not replace the home network from USB flash). Home is tried first; travel is used when home is gone. **CLEAR** removes the travel network. Power off writes the device name, a huge **OFF**, and **TAP RED BUTTON TO BEGIN**, then kills the frontlight and LED; the e-paper keeps that card with the device shut down. A **short press** of the side (red) button also powers off. Holding the power button for about 2 seconds is still **download/flash mode**. Brightness, mute, and timers are Settings only.
- **Lock screen:** tap the **padlock** on any unlocked page (top right, boxed). The lock screen itself has no padlock icon. **Logo and Vestaboard** draws the 3×15 grid when Vestaboard is on (or until the tablet has heard from the panel). **Unlock** always opens the **MENU**. One **YARBO** tile opens Status; A/B then step Health and Plans. Tick which buttons to show, rename them, and change their order in Settings → E-paper → **Menu buttons** (next poll, no reflash). **MAIL** on the menu shows a notification blob when a message is waiting. An envelope also appears on the lock screen; tap it to open **MAIL** directly. **OFF** on the lock screen shuts the tablet down. Clock uses CEST until you save a timezone, then USB flash stores that offset. Battery is top-right, Wi-Fi underneath.
- **A** next page, **B** previous. On Yarbo screens they wrap Status / Health / Plans only — use **MENU** to leave. On other pages they skip those inner Yarbo screens (Home is still on A/B, not on the unlock menu). Hidden menu pages are skipped. From the unlock menu, A/B close the menu and step. Stop / Dock are Home buttons only.
- **House:** up to 12 lights, scenes, or UniFi Access doors on one screen, using the same 2-column tiles as the menu. Long names wrap so the full label stays visible. Door tiles show a door glyph; invert follows the door position sensor (open = filled).

It does **not** include map, cameras, plan delete, or hold-to-drive. E-paper is too slow for those. NFC, mic, IMU, and the SD slot are unused in this firmware. Sleep timers, brightness, buzzer, and RGB alerts are set in **Settings → E-paper companions**, not on the tablet. The side LED is **off** in normal use (the hardware turns red on at boot until firmware clears it). A Yarbo error blinks red.

## E-paper care (manufacturer)

The SSD1677 panel is easy to damage if driven badly. Firmware follows these rules:

- After about **10 fast refreshes**, run **one full-screen refresh** to clear ghosting. Draw the whole frame, then one `display()` — do not refresh per letter or widget.
- **Do not** stream uninterrupted partial refreshes (DC imbalance can permanently damage the panel).
- Skip a redraw when status has not changed.
- A/B is taken while the panel is still refreshing. A tap is queued until that waveform finishes, then applied (HOUSE HTTP is not started mid-refresh). The next screen draws once; intermediate pages are skipped. Navigation uses the fast update; a full refresh still runs after about 10 fast ones, and once at boot.
- Use the panel’s built-in OTP waveforms (M5GFX `epd_quality` / `epd_fastest`). Do not load custom LUTs unless you know DC balance.
- Keep the device out of direct sun and strong UV; heat and UV can ruin the film.
- Status poll is 15 seconds, not a tight loop.

## Architecture

```
Desktop Settings  →  PHP /api/device.php  →  USB (esptool + serial CFG:)
PaperMono firmware → GET compact status + POST command (token)
                     → PHP → MQTT agent → robot
```

Paired devices are stored in `data/papermono-devices.json` (not committed). The firmware token is written over USB; it is not shown again in Settings after flash.

## First-time setup

On the **same machine that runs the panel**:

1. Plug the PaperMono in by USB. To enter download mode, hold the power button about 2 seconds until the red LED blinks, then release (M5Stack docs). First boot of our firmware shows a setup screen until config arrives.
2. Open the panel → **Settings → E-paper companions** and leave **PaperMono** selected (not Paper Colour).
3. Click **Build firmware** and wait until the status says it is built (first time can take several minutes).
4. Refresh USB ports and select the PaperMono serial device. If the list fails because `pyserial` / `esptool` are missing, click **Install USB tools**.
5. Enter:
   - Wi-Fi SSID and password (**2.4 GHz only**)
   - Panel URL as the tablet will reach it (for example `http://192.168.1.50:8080`, not `localhost`)
   - A device name
   - Optional lock-screen logo (PNG/JPEG). Preview shows on the mocks; the tablet fetches it on the next Wi-Fi poll (no reflash).
6. Click **Flash firmware & send Wi-Fi**. Leave Settings open. Flash will build first if the binary is missing or stale.

**Send Wi-Fi only** reuses already-flashed firmware and pushes a new `CFG:` line over serial (SSID, password, panel URL, token). After a successful write, esptool resets the ESP32-S3 and USB serial (`/dev/ttyACM0`) drops for a few seconds; the panel waits until the tablet prints **PAPER_READY** (listening after the slow e-paper boot), then retries until it replies `CFG_OK`. If that still fails, leave the tablet on the **setup** screen with USB in and click Send Wi-Fi only — do not hold the power button.

## Set up away from the panel

The first flash still has to be USB. It does not have to be USB into the Pi. If you are away from the site, download a **USB setup kit** from the panel, flash the tablet on a laptop, then ship it.

The kit is a **factory flash image** at `0x0` (bootloader, partitions, and app) plus the same `CFG:` serial line. PlatformIO’s app-only `firmware.bin` must not be written at `0x0`.

**On the panel (any browser that can reach Settings):**

1. Open **Settings → E-paper companions** and leave **PaperMono** selected.
2. Enter the **site** 2.4 GHz SSID and password, the panel URL as the tablet will reach the Pi (for example `http://192.168.1.50:8080`, not `localhost`), and a device name.
3. Click **Download PaperMono USB setup kit**. The panel builds firmware if needed and registers a pairing token. Treat the zip as a secret (Wi-Fi password + token).
4. If you never flash that zip, revoke the new row in the paired list.

**On a Mac:**

1. Unzip the kit. Keep `firmware.bin`, `config.json`, `flash.py`, and `README.txt` in the same folder.
2. `cd` into that folder and run `python3 flash.py`. The script makes a `.venv` next to the kit and installs `esptool` there. Homebrew Python will reject `python3 -m pip install esptool` (`externally-managed-environment`) — do not use that. If you already have this zip, run:
   ```
   python3 -m venv .venv
   .venv/bin/pip install esptool pyserial
   .venv/bin/python flash.py
   ```
3. Plug the PaperMono in by USB-C. Hold power about **2 seconds** for download mode (red LED blinks). The factory demo can stay on the glass until our firmware boots and does a slow full refresh — unplug, short-press power, and wait. A blinking red LED means it is still in download mode.
4. If more than one serial device is listed: `python3 flash.py --port /dev/cu.usbmodemXXXX` (`python3 flash.py --list-ports` to list them).
5. Keep USB in until the setup screen clears, then ship the tablet to the site Wi-Fi.
6. If flash succeeded but the script loops **No CFG_OK yet**, leave USB in on the **setup** screen (not download mode) and run `python3 flash.py --wifi-only`.

**On Windows:**

1. Unzip the kit into one folder (same four files as above).
2. Install Python from python.org and tick **Add python.exe to PATH**, then in the unzipped folder run `py flash.py` (it creates `.venv` and installs `esptool`). Or `py flash.py --port COM3`.
3. If no COM port appears, install the Espressif USB JTAG/serial driver (ESP32-S3 native USB).
4. Plug USB-C and hold power about **2 seconds** for download mode.
5. Keep USB in until the setup screen clears, then ship the tablet to the site 2.4 GHz Wi-Fi.
6. If it loops **No CFG_OK yet** after a successful flash: `py flash.py --wifi-only` with USB in and the setup screen showing.

Later firmware still goes over Wi-Fi: Settings → paired device **Update**. You do not need this kit again unless you wipe the tablet.

## Runtime

The tablet polls `GET /api/device.php?action=compact` about every 15 seconds with header `X-PaperMono-Token`. Compact status includes `vestaboard_enabled`, `vestaboard_live`, `powerwall_enabled`, `lymow_enabled`, `home_enabled`, `home_items`, and `remote_url` when Settings → E-paper **Remote access** is on. The Plans page also calls `GET /api/device.php?action=plans` (cached on the Pi for about five minutes). Commands POST JSON `{ "action": "command", "command": "stop" }` (also `return_to_dock`, `pause`, `resume`, `lights_on`, `lights_off`, `start_plan` with `plan_id`, `vestaboard_live`, and Home `home_toggle` / `home_scene` with `home_id`).

Stop / Dock / Pause / Lights / start plan use the same MQTT agent as the web Controls. Stop is immediate (no confirm). Starting a plan takes the controller, same as the web **Start** button. Changing the Vestaboard view does not take the robot controller. After the first USB flash, later firmware is pushed over Wi-Fi: Settings → paired device **Update**, or Settings → Updates. Click **Build firmware** first — the firmware download will not compile on the fly (that used to stall the tablet until **Updating…** reverted). Compact status includes `ota_pending`; while that is set the Pi returns only `ota_pending` and `firmware_latest` so old firmware can start the download. The tablet then downloads `GET /api/device.php?action=firmware`. The panel keeps `ota_pending` until the tablet reports the new version (or 15 minutes). 0.1.56 only attempts OTA once per boot; reboot it if the first try failed. PaperMono beeps and lights green. The lock-screen 3×15 grid is the live Vestaboard layout (not a stored empty board). The header logo is `GET /api/device.php?action=logo` (same token; required when the tablet is on the remote URL). The **HOUSE** page (Home module) lists up to 12 assigned Matter lights, panel scenes, and UniFi Access doors on one screen (no MORE pager); each name uses the full width of its button. Scene rows fill when the scene is active; tap again to turn those lights off. Door rows show a door glyph (firmware **0.1.58**) and fill when the connected position sensor reads Open.

## Remote access

Tablets on another Wi-Fi cannot see `http://192.168.x.x:8080`. Settings → E-paper **Remote access** is a separate toggle:

1. Leave the **Panel URL** as the LAN address the tablet uses at home.
2. Turn on **Allow tablets to reach this panel from another network**.
3. **Tailscale Funnel** (recommended, free): Install Tailscale on this Pi, then **Log in**. A login link should appear (and open). If nothing opens, the Pi is usually already logged in — Funnel is a **second** switch. Open [Access controls](https://login.tailscale.com/admin/acls). That page is **Policies → General access rules** (who can talk to whom). Funnel is not a rule there. In the left sidebar click **JSON editor** and add `"nodeAttrs": [{ "target": ["autogroup:member"], "attr": ["funnel"] }]`, then Save. Or **Definitions → Node attributes**. Turn on [DNS](https://login.tailscale.com/admin/dns) **MagicDNS**, **HTTPS Certificates**, and **Funnel** if that page has a Funnel switch, then **Start Funnel**. If Settings says **Command produced no output**, on the Pi run `sudo ./scripts/paper_remote.sh funnel-on`. The panel opens a local gate on port 8089 that only forwards `/api/device.php` with a pairing token (compact, plans, firmware, logo, commands). Settings and the rest of the UI stay off the internet.
4. Or paste an **existing HTTPS URL** (named Cloudflare tunnel / Tesla public panel URL). That origin must already reach this panel; tablets still only call `/api/device.php`.

Do **not** port-forward 8080. On the home SSID the tablet tries the LAN URL first (~2 s), then HTTPS. On travel Wi-Fi (firmware **0.1.62**) it skips LAN and uses Funnel so the ESP32 does not hang connecting to a 192.168 address on another network. Firmware **0.1.57** / **0.2.18-colour** stores `remote_url` from compact (or USB CFG). Queue a Wi-Fi **Update** after this panel version so existing tablets learn it. A small **R** (like phone roaming) appears next to Wi-Fi when the last successful poll used the remote URL.

On PaperMono **DEVICE**, **REMOTE WIFI** (firmware **0.1.60**) stores a second 2.4 GHz network for when the flashed home SSID is not there. Scan leaves the home join so hotel/cafe networks can show (several seconds). **TYPE** enters the name if scan finds nothing. It does not overwrite USB `CFG:` credentials. Turn on **Remote access** before you leave so Funnel HTTPS is already on the tablet. Firmware **0.1.62** then talks to the Pi over Funnel (the **R** next to Wi-Fi) instead of waiting on the home LAN.

## Limits

- One MQTT controller at a time, same as the web panel.
- Status watch does not take the controller. Stop / Dock / Pause / Lights do, via the agent.
- Mild ghosting between full refreshes is normal.
- If flash fails, check the USB port, click **Install USB tools** if `pyserial` / `esptool` are missing, and click **Build firmware** if the binary is missing.
