# Paper Colour companion (beta)

Firmware for **[M5Stack PaperColor](https://docs.m5stack.com/en/core/PaperColor)** (shop: M5Paper Color, 4″ Spectra 6, **400×600**, ESP32-S3). **No touchscreen.**

| | |
| --- | --- |
| **Model** | M5Stack PaperColor |
| **Screen** | E Ink Spectra 6 (white, black, red, yellow, green, blue), ~10–20 s full refresh |
| **Buttons** | **A / B** previous / next page. **C** sleep / wake |
| **SoC** | ESP32-S3R8, same USB-C flash story as PaperMono |

This is **not** PaperS3 (touch, grayscale) and **not** PaperMono C153.

## First-time setup (same as PaperMono)

On the **same machine that runs the panel**:

1. Build the Colour firmware (once):

   ```bash
   pip3 install platformio
   pio run -e papercolor -d firmware/papercolor
   ```

2. Plug the PaperColor in by USB. Hold the side power/reset about **3 seconds** for download mode (M5Stack docs).
3. Open the panel → **Settings → E-paper companions**.
4. Choose **Paper Colour** (not PaperMono). That is what selects the Colour binary — the USB port list is the same.
5. Refresh USB ports and select the tablet. If the list fails, click **Install USB tools**.
6. Enter 2.4 GHz Wi-Fi, the panel URL as the tablet will reach it (not `localhost`), and a device name.
7. Click **Flash Paper Colour firmware & send Wi-Fi**. Leave Settings open for one to two minutes.

Rebuild after panel updates that bump Colour firmware (`0.2.2-color` and later):

```bash
pio run -e papercolor -d firmware/papercolor
```

Then flash again from Settings with **Paper Colour** selected.

**Send Wi-Fi only** reuses already-flashed firmware and pushes the same `CFG:{...}` JSON as PaperMono.

Do not flash with PlatformIO env `papermono` — that is the grayscale touch UI.

## Pages

Yarbo Home / Status when the robot is online. Extra pages for Powerwall and Lymow (battery %, state). The Lymow page header shows the Lymow-app name (or the name set in Settings). Do not expect 15-second redraws — Spectra 6 is a set-and-leave sign. Reflash **0.2.2-color** for the Lymow name.

Compact status is `GET /api/device.php?action=compact`.
