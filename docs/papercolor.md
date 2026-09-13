# Paper Colour companion (beta)

Firmware for **[M5Stack PaperColor](https://docs.m5stack.com/en/core/PaperColor)** (shop: M5Paper Color, 4″ Spectra 6, **400×600**, ESP32-S3). **No touchscreen.**

| | |
| --- | --- |
| **Model** | M5Stack PaperColor |
| **Screen** | E Ink Spectra 6 (white, black, red, yellow, green, blue), ~10–20 s full refresh |
| **Buttons** | **A / B** previous / next page. **C** sleep / wake |
| **SoC** | ESP32-S3R8, same USB-C flash story as PaperMono |

This is **not** PaperS3 (touch, grayscale) and **not** PaperMono C153.

## Build and flash

```bash
cd firmware/papercolor
pio run -e papercolor
```

Hold the side power/reset about **3 seconds** for download mode. Then flash with esptool / PlatformIO, same idea as PaperMono. USB Wi-Fi config uses the same `CFG:{...}` JSON as PaperMono (`docs/papermono.md`).

Point `panel_url` at this control panel. Compact status is `GET /api/device.php?action=compact`.

## Pages

Yarbo Home / Status when the robot is online. Extra pages when Powerwall or Lymow modules are enabled (battery %, solar/draw, camera up/down). Do not expect 15-second redraws — Spectra 6 is a set-and-leave sign.
