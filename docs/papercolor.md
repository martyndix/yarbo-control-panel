# Paper Colour companion

Firmware for **[M5Stack PaperColor](https://docs.m5stack.com/en/core/PaperColor)** (shop: M5Paper Color, 4″ Spectra 6, **400×600**, ESP32-S3). **No touchscreen.**

| | |
| --- | --- |
| **Model** | M5Stack PaperColor |
| **Screen** | E Ink Spectra 6 (white, black, red, yellow, green, blue), ~10–20 s full refresh |
| **Buttons** | **A / B** previous / next page. **C** lock screensaver / unlock (opens Home, or the last **Unlock to** page stored on the tablet) |
| **SoC** | ESP32-S3R8, same USB-C flash story as PaperMono |

This is **not** PaperS3 (touch, grayscale) and **not** PaperMono C153.

## First-time setup (same as PaperMono)

On the **same machine that runs the panel**:

1. Plug the PaperColor in by USB. Hold the side power/reset about **3 seconds** for download mode (M5Stack docs).
2. Open the panel → **Settings → E-paper companions**.
3. Choose **Paper Colour** (not PaperMono). Click **Build firmware** and wait until it finishes.
4. Refresh USB ports and select the tablet. If the list fails, click **Install USB tools**.
5. Enter 2.4 GHz Wi-Fi, the panel URL as the tablet will reach it (not `localhost`), and a device name.
6. Click **Flash Paper Colour firmware & send Wi-Fi**. Leave Settings open. Flash builds first if needed.

Rebuild after panel updates that bump Colour firmware (`0.2.11-colour` and later): **Build firmware**, then either USB flash once or, after this OTA-capable build is on the tablet, Settings → **Update** over Wi-Fi.

**Send Wi-Fi only** reuses already-flashed firmware and pushes the same `CFG:{...}` JSON as PaperMono. After esptool resets the ESP32-S3, USB serial drops and the helper waits for `CFG_OK`.

Do not flash with PlatformIO env `papermono` — that is the grayscale touch UI.

## Set up away from the panel

Same USB setup kit as PaperMono. On **Settings → E-paper companions** pick **Paper Colour**, enter the **site** 2.4 GHz Wi-Fi and the panel URL the tablet will use (not `localhost`), then **Download Paper Colour USB setup kit**.

On the laptop, unzip and run `python3 flash.py` (Mac) or `py flash.py` (Windows). The script creates a local `.venv` and installs `esptool` there — Homebrew Python will reject a system-wide `pip install`. Hold power about **3 seconds** for download mode. Windows may need the Espressif USB JTAG/serial driver if no COM port appears. If the script flashes then loops **No CFG_OK yet**, leave USB in on the setup screen and run `python3 flash.py --wifi-only` (or `py flash.py --wifi-only`).

The zip contains the Wi-Fi password and pairing token — keep it private. Full Mac/Windows steps: [`docs/papermono.md`](papermono.md#set-up-away-from-the-panel). Later updates stay Settings → **Update** over Wi-Fi.

## Pages

Yarbo Home / Status / Health / Plans when the Yarbo module is on. Extra pages for Powerwall and Lymow only when those modules are enabled. **BOARD** is a live 3×15 Vestaboard preview when the Note is enabled. The top line is **YARBO · COLOUR**, **POWERWALL**, **LYMOW**, or **VESTABOARD** for that page (Lymow and Powerwall do not repeat the name). **C** shows the lock screensaver (logo, Vestaboard, or both — chosen in Settings). Optional **header logo** is the same Settings upload as PaperMono (reflash **0.2.11-colour**). Do not expect 15-second redraws — Spectra 6 is a set-and-leave sign.

Compact status is `GET /api/device.php?action=compact`. Off-LAN use is the same Settings → E-paper **Remote access** toggle as PaperMono (`docs/papermono.md`). Firmware **0.2.18-colour** tries the LAN URL first, then HTTPS, and shows **R** at the bottom next to the IP when it is on the remote URL.
