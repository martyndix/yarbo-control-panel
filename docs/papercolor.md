# Paper Colour companion

Firmware for **[M5Stack PaperColor](https://docs.m5stack.com/en/core/PaperColor)** (shop: M5Paper Color, 4″ Spectra 6, **400×600**, ESP32-S3). **No touchscreen.**

| | |
| --- | --- |
| **Model** | M5Stack PaperColor |
| **Screen** | E Ink Spectra 6 (white, black, red, yellow, green, blue), ~10–20 s full refresh |
| **Buttons** | **A / B** previous / next **enabled** page (off modules are skipped). **C** lock screensaver / unlock (opens Home, or the last **Unlock to** page stored on the tablet). In **Vestaboard viewer only**, A/B (and C) flip landscape left/right when there is no IMU |
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

Yarbo Home / Status / Health / Plans only when the Yarbo module is on — A/B never land on a turned-off module, including after a reboot (module flags are stored on the tablet and in USB CFG). Extra pages for Powerwall and Lymow only when those modules are enabled. **BOARD** is a live 3×15 Vestaboard preview when the Note is enabled. Settings → E-paper **Vestaboard viewer only** (ticked only while the Note is on) boots Paper Colour as a landscape Vestaboard that fills **600×400**, with the local time above the flaps, larger letters, and the PaperMono battery badge (percent in the cell, charging bolt when plugged in) in the bottom-left. If `M5.Imu` is present it picks landscape left vs right from gravity; otherwise A/B set rotation 1 vs 3. Viewer mode does not auto-lock. The top line is **YARBO · COLOUR**, **POWERWALL**, **LYMOW**, or **VESTABOARD** for that page (Lymow and Powerwall do not repeat the name). **C** shows the lock screensaver (logo, Vestaboard, or both — chosen in Settings). Optional **header logo** is the same Settings upload as PaperMono (reflash **0.2.11-colour**). The two RGB LEDs go **red** when a device on the live Vestaboard is in error, and **blue** when Yarbo rain is on the Note (red wins). Firmware **0.2.26-colour** skips a full refresh when only the clock changed on the paged UI, and the viewer clock/battery piggyback on a board change (or at most once an hour / 10% battery / plug). Frames use nearest-palette `epd_fastest`. Do not expect 15-second redraws — Spectra 6 is a set-and-leave sign.

## Refresh

The 4″ panel is an **ED2208-DOA / EL040EF1** Spectra 6, driven by M5GFX `Panel_ED2208`. A `display(x, y, w, h)` dirty rect still writes the **whole** framebuffer (`0x10`) and runs a **full** `0x12` waveform. `epd_quality` vs `epd_fastest` only picks RGB pair-dither vs nearest palette — not a faster LUT.

Larger ED2208-GCA Spectra 6 panels have an undocumented `0x83` partial window. On those 7.3″ boards it is **not faster** (~same 28–35 s as full) and **fades colour** outside the window. M5’s 4″ driver does not send `0x83`, and this firmware does not either.

That means:

- **Time, battery chrome** — do not start a 15–30 s cycle. Paged UI ignores clock ticks. The Vestaboard viewer still draws the current time whenever it *does* refresh, and will refresh for the hour rolling over, a 10% battery step, or USB plug, or when the live board hash changes.
- **One Vestaboard digit** — still a full refresh. There is no working cell-level update.
- **Colour tiles** — always a full refresh when the board changes. Palette solids use `epd_fastest` so dither does not speckle the flaps.

Compact status is `GET /api/device.php?action=compact`. Off-LAN use is the same Settings → E-paper **Remote access** toggle as PaperMono (`docs/papermono.md`). Firmware **0.2.18-colour** tries the LAN URL first, then HTTPS, and shows **R** at the bottom next to the IP when it is on the remote URL.
