# Lymow (unofficial)

Battery, work status, and the mower camera on this panel. **Not affiliated with Lymow.** Start, dock, and pause are not sent from here.

Status decoding follows the MIT-licensed [ha-lymow](https://github.com/8408323/ha-lymow) notes.

## Account

Settings → **Lymow**:

1. Tick **Lymow** under Settings → Modules. The **Lymow** login section appears just below.
2. Enter the same **email and password** as the Lymow phone app.
3. Region **Auto** tries Europe first, then NA / AU / Asia.
4. **Sign in / Test Lymow**. The panel installs `paho-mqtt` and `websocket-client` on this host if they are missing (`python3 -m pip install --break-system-packages …`). Battery and work state then come from Lymow cloud MQTT (not the camera). The first reading can take up to a minute.

Leave the password blank on later saves to keep the stored one.

**Lymow name** is shown under the title on the Lymow page (same place as the Yarbo name). Leave the Settings field blank to use the nickname from the Lymow app when that is published. PaperMono and Paper Colour Lymow pages show the same name after a reflash (**0.1.4-beta** / **0.2.2-color**).

## Camera

LAN IP of the mower. The panel always uses `rtsp://IP:10022/h264ESVideoTest` (same path as Homebridge CameraUI). Needs `ffmpeg` on the panel host (`sudo apt install -y ffmpeg` on a Pi).

On the Lymow card: **Stills** (a frame every few seconds) or **Stream** (ffmpeg keeps the RTSP session open and the page refreshes the picture several times a second).

Sign-in may fill the IP from the cloud if it is still the default.

## Vestaboard

Settings → Modules → **Vestaboard live module** → Lymow.

The Note shows **LYMOW** plus battery **%** (colour chip on the last column, so `99%` stays whole), **STATE** plus the work label (WAIT, MOWING, DOCKING, …) or the mowing **progress %** while a job is running, and **CHARGING YES** or **NO**.

The Lymow card has a **Progress** stat for the same percent. The MQTT listener writes each robot status message as it arrives (and asks for a status refresh about every 15 seconds) so the tile and Note are not a minute behind.

There is also a Vestaboard live option that stacks **Yarbo**, **Powerwall**, and **Lymow** battery percents (one row each, colour chip on each row). Pick it under Settings → Modules → Vestaboard live module. It does not require the Powerwall or Lymow dashboards to be enabled.
