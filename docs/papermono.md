# PaperMono companion (beta)

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
- Onboard NFC (ST25R3916) and LoRa (SX1262). Firmware **0.1.14-beta** uses LoRa for RADIO (868 MHz, house sync word) with Wi-Fi fallback.

It has **no browser**. This panel flashes native firmware over USB from **Settings**, then the tablet talks HTTP JSON to the panel. The panel stays the MQTT brain.

This is **beta**. Treat it as a companion, not a replacement for the web UI or the official app.

Home and extra-page mocks (not photos of a flashed unit):

![PaperMono home mock](screenshots/papermono-home.png)

![PaperMono status mock](screenshots/papermono-status.png)

![PaperMono health mock](screenshots/papermono-health.png)

![PaperMono work plans mock](screenshots/papermono-plans.png)

![PaperMono setup mock](screenshots/papermono-setup.png)

## What it shows

- **Home / Status / Health / Plans:** Yarbo robot tiles (Stop / Dock on Home). Hidden when the Yarbo module is off in Settings.
- Optional **header logo** (Settings → E-paper companions). Same image on Paper Colour. Prefer a transparent PNG. Needs firmware **0.1.14-beta**.
- Top line is the current module (**YARBO**, **POWERWALL**, **LYMOW**, **VESTABOARD**, **RADIO**, **DEVICE**), not a fixed YARBO brand. Single-page modules do not repeat that name as a second title.
- **Note:** Vestaboard live view picker. Only lists modules that are on. Hidden when Vestaboard is off.
- **Board:** live 3×15 preview of what the Vestaboard Note is showing. Hidden when Vestaboard is off.
- **Powerwall:** house battery, solar, and draw. Hidden when the Powerwall module is off.
- **Lymow:** Lymow battery, state, and charging. Hidden when the Lymow module is off.
- **Radio:** house messages (~180 characters) to **ALL** or a named peer. Tries LoRa first, then Wi-Fi via the panel.
- **Device:** this tablet’s battery, clock, and **OFF** (full power-off). Brightness, mute, and timers are Settings only.
- **Lock screen:** idle timer from Settings. Layout is logo, Vestaboard, or both. Tablet name is on the lock screen. Unlock is two opposite-corner taps (1 then 2). The red side button returns to lock. Incoming messages stay locked and badge + beep/flash if alerts are on.
- Hardware keys cycle enabled pages when unlocked. Stop / Dock are Home buttons only.

It does **not** include map, cameras, plan delete, or hold-to-drive. E-paper is too slow for those. NFC, mic, IMU, and the SD slot are unused in this firmware. Sleep timers, brightness, buzzer, and RGB alerts are set in **Settings → E-paper companions**, not on the tablet.

## E-paper care (manufacturer)

The SSD1677 panel is easy to damage if driven badly. Firmware follows these rules:

- After about **10 partial (fast) refreshes**, run **one full-screen refresh** to clear ghosting.
- **Do not** stream uninterrupted partial refreshes (DC imbalance can permanently damage the panel).
- Skip a redraw when status has not changed.
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
   - Optional header logo (PNG/JPEG). Preview shows on the mocks; reflash so the glass updates.
6. Click **Flash firmware & send Wi-Fi**. Leave Settings open. Flash will build first if the binary is missing or stale.

**Send Wi-Fi only** reuses already-flashed firmware and pushes a new `CFG:` line over serial (SSID, password, panel URL, token).

## Set up away from the panel

The first flash still has to be USB. It does not have to be USB into the Pi. If you are away from the site, download a **USB setup kit** from the panel, flash the tablet on a laptop, then ship it.

The kit is the same firmware at `0x0` plus the same `CFG:` serial line. A lone `.bin` is not enough: Wi-Fi, panel URL, and the pairing token are not baked into the image.

**On the panel (any browser that can reach Settings):**

1. Open **Settings → E-paper companions** and leave **PaperMono** selected.
2. Enter the **site** 2.4 GHz SSID and password, the panel URL as the tablet will reach the Pi (for example `http://192.168.1.50:8080`, not `localhost`), and a device name.
3. Click **Download PaperMono USB setup kit**. The panel builds firmware if needed and registers a pairing token. Treat the zip as a secret (Wi-Fi password + token).
4. If you never flash that zip, revoke the new row in the paired list.

**On a Mac:**

1. Unzip the kit. Keep `firmware.bin`, `config.json`, `flash.py`, and `README.txt` in the same folder.
2. Install Python 3 if needed, then: `python3 -m pip install esptool pyserial`
3. Plug the PaperMono in by USB-C. Hold power about **2 seconds** for download mode (red LED blinks).
4. `cd` into the unzipped folder and run `python3 flash.py`
5. If more than one serial device is listed: `python3 flash.py --port /dev/cu.usbmodemXXXX` (`python3 flash.py --list-ports` to list them).
6. Keep USB in until the setup screen clears, then ship the tablet to the site Wi-Fi.

**On Windows:**

1. Unzip the kit into one folder (same four files as above).
2. Install Python from python.org and tick **Add python.exe to PATH**, then: `py -m pip install esptool pyserial`
3. If no COM port appears, install the Espressif USB JTAG/serial driver (ESP32-S3 native USB).
4. Plug USB-C and hold power about **2 seconds** for download mode.
5. In the unzipped folder run `py flash.py` (or `py flash.py --port COM3`).
6. Keep USB in until the setup screen clears, then ship the tablet to the site 2.4 GHz Wi-Fi.

Later firmware still goes over Wi-Fi: Settings → paired device **Update**. You do not need this kit again unless you wipe the tablet.

## Runtime

The tablet polls `GET /api/device.php?action=compact` about every 15 seconds with header `X-PaperMono-Token`. Compact status includes `vestaboard_enabled`, `vestaboard_live`, `powerwall_enabled`, `lymow_enabled`, `home_enabled`, and `home_items`. The Plans page also calls `GET /api/device.php?action=plans` (cached on the Pi for about five minutes). Commands POST JSON `{ "action": "command", "command": "stop" }` (also `return_to_dock`, `pause`, `resume`, `lights_on`, `lights_off`, `start_plan` with `plan_id`, `vestaboard_live`, and Home `home_toggle` / `home_scene` with `home_id`).

Stop / Dock / Pause / Lights / start plan use the same MQTT agent as the web Controls. Stop is immediate (no confirm). Starting a plan takes the controller, same as the web **Start** button. Changing the Vestaboard view does not take the robot controller. After the first USB flash of **0.1.15-beta**, later firmware is pushed over Wi-Fi: Settings → paired device **Update**, or Settings → Updates. The tablet must have polled recently. Compact status includes `ota_pending`; the tablet downloads `GET /api/device.php?action=firmware`, stays on Wi-Fi, then reboots. PaperMono beeps and lights green. The header logo is `GET /api/device.php?action=logo` when compact status includes `logo_hash`. The **HOUSE** page (Home module) lists assigned Matter lights and panel scenes.

## Limits

- One MQTT controller at a time, same as the web panel.
- Status watch does not take the controller. Stop / Dock / Pause / Lights do, via the agent.
- Mild ghosting between full refreshes is normal.
- If flash fails, check the USB port, click **Install USB tools** if `pyserial` / `esptool` are missing, and click **Build firmware** if the binary is missing.
