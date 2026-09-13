# Lymow (unofficial)

Battery, work status, and the mower camera on this panel. **Not affiliated with Lymow.** Start, dock, and pause are not sent from here.

Status decoding follows the MIT-licensed [ha-lymow](https://github.com/8408323/ha-lymow) notes.

## Account

Settings → **Lymow**:

1. Tick **Lymow** under Settings → Modules. The **Lymow** login section appears just below.
2. Enter the same **email and password** as the Lymow phone app.
3. Region **Auto** tries Europe first, then NA / AU / Asia.
4. **Sign in / Test Lymow**. Battery and work state come from Lymow cloud MQTT after that (not from the camera). The first reading can take up to a minute. Needs `paho-mqtt` and `websocket-client` on the panel host (`python3 -m pip install --break-system-packages paho-mqtt websocket-client` on a Pi).

Leave the password blank on later saves to keep the stored one.

## Camera

LAN IP of the mower. The panel always uses `rtsp://IP:10022/h264ESVideoTest` (same path as Homebridge CameraUI). Needs `ffmpeg` on the panel host (`sudo apt install -y ffmpeg` on a Pi).

On the Lymow card: **Stills** (a frame every few seconds) or **Stream** (ffmpeg keeps the RTSP session open and the page refreshes the picture several times a second).

Sign-in may fill the IP from the cloud if it is still the default.

## Vestaboard

Settings → Modules → **Vestaboard live module** → Lymow.

The Note shows **LYMOW** plus battery **%** (colour chip on the last column, so `87%` stays whole), work state, and **CAM UP / DOWN**.
