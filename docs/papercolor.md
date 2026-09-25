# Paper Colour companion (beta)

Firmware for **[M5Stack PaperColor](https://docs.m5stack.com/en/core/PaperColor)** (shop: M5Paper Color, 4″ Spectra 6, **400×600**, ESP32-S3). **No touchscreen.**

| | |
| --- | --- |
| **Model** | M5Stack PaperColor |
| **Screen** | E Ink Spectra 6 (white, black, red, yellow, green, blue), ~10–20 s full refresh |
| **Buttons** | **A / B** previous / next page. **C** lock screensaver / unlock |
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

Rebuild after panel updates that bump Colour firmware (`0.2.9-colour` and later): click **Build firmware** with Paper Colour selected (or Flash, which builds when source is newer).

**Send Wi-Fi only** reuses already-flashed firmware and pushes the same `CFG:{...}` JSON as PaperMono.

Do not flash with PlatformIO env `papermono` — that is the grayscale touch UI.

## Pages

Yarbo Home / Status / Health / Plans when the Yarbo module is on. Extra pages for Powerwall and Lymow only when those modules are enabled. **BOARD** is a live 3×15 Vestaboard preview when the Note is enabled. The top line is **YARBO · COLOUR**, **POWERWALL**, **LYMOW**, or **VESTABOARD** for that page. **C** shows the lock screensaver (logo, Vestaboard, or both — chosen in Settings). Optional **header logo** is the same Settings upload as PaperMono (reflash **0.2.9-colour**). Do not expect 15-second redraws — Spectra 6 is a set-and-leave sign.

Compact status is `GET /api/device.php?action=compact`.
