# Lymow (unofficial)

Battery, work status, Start / Pause / Resume / Dock / Cancel, and the mower camera on this panel. **Not affiliated with Lymow.**

Controls use the same unofficial cloud MQTT `userCtrl` codes as [ha-lymow](https://github.com/8408323/ha-lymow) (MIT): Start = clean (1), Pause (3), Resume (4), Cancel = stop in place and clear task (28), Dock = recharge and keep progress (33) while a task is active, or dock-from-idle (2) after Cancel / when waiting (33 no-ops with no task). Status decoding follows the same notes.

## Account

Settings → **Lymow**:

1. Tick **Lymow** under Settings → Modules. The **Lymow** login section appears just below.
2. Enter the same **email and password** as the Lymow phone app.
3. Region **Auto** tries Europe first, then NA / AU / Asia.
4. **Sign in / Test Lymow**. The panel installs `paho-mqtt` and `websocket-client` on this host if they are missing (`python3 -m pip install --break-system-packages …`). Battery and work state then come from Lymow cloud MQTT (not the camera). The first reading can take up to a minute.

On the Lymow card: tap **Refresh** under **Zones** to load go-zone names from the Lymow map (same unofficial MQTT `QUERY_MAP` as ha-lymow). Select one or more zones, then **Start** (sends those hash IDs only — not “mow everything”). While a task is active, **State** shows the zone name(s) (for example `MOWING · South Lawn`) and those chips stay highlighted. Once the mower is back on charge after the job finishes, **State** is just **CHARGE** (or **IDLE**) — it does not keep the last zone name. **Pause**, **Resume**, and **Dock** work without a zone pick — Dock keeps mow progress while a task is active, and still sends the mower home after Cancel when it is sitting idle. **Cancel** clears the current mowing task and leaves the mower where it is (it does not return to dock by itself). The same commands are available as Automations **Then** actions.

**PaperMono** (firmware **0.1.87+**): the Lymow page shows zone chips (six at a time with **PREV** / **NEXT** when there are more), **ALL ZONES**, **REFRESH**, and **START** / **PAUSE** / **RESUME** / **DOCK** / **CANCEL**. Zones start unselected; **START** is inactive until you pick at least one (or **ALL ZONES**). Update the panel, build/flash PaperMono, then open Lymow (or Robots → Lymow).

Leave the password blank on later saves to keep the stored one. Login files (`data/lymow-config.json`, `data/lymow-cloud.json`) stay on the host across updates; this feature does not wipe them.

To roll back to the previous status-only build, check out git tag `lymow-readonly-baseline` (or the panel version you had before 4.0.222).

**Lymow name** is shown under the title on the Lymow page (same place as the Yarbo name). Leave the Settings field blank to use the nickname from the Lymow app when that is published. PaperMono and Paper Colour Lymow pages show the same name after a reflash.

## Camera

LAN IP of the mower. The panel always uses `rtsp://IP:10022/h264ESVideoTest` (same path as Homebridge CameraUI). Needs `ffmpeg` on the panel host (`sudo apt install -y ffmpeg` on a Pi).

On the Lymow card: **Stills** (a frame every few seconds) or **Stream** (ffmpeg keeps the RTSP session open and the page refreshes the picture several times a second).

Sign-in may fill the IP from the cloud if it is still the default.

## Vestaboard

Settings → Modules → **Vestaboard live module** → Lymow.

The Note uses the same 3-line pattern as Yarbo: **LYMOW** plus the activity verb (**MOWING**, **CHARGING**, **IDLE**, **DOCKING**, **PAUSED**, …), **BATTERY** plus **%** or **FULL** (colour chip on the last column), and a context line (**WORK DONE n%** while mowing, **ON DOCK**, **CHARGED**, **READY**, **HEADING HOME**, **PLAN HOLD**). Mid-mow top-ups show **CHARGING** / **ON DOCK** once the pad is charging — not **DOCKING** / **HEADING HOME**.

The Lymow card has a **Progress** stat for the same percent. The MQTT listener writes each robot status message as it arrives (and asks for a status refresh about every 15 seconds) so the tile and Note are not a minute behind.

There is also a Vestaboard live option that stacks **Yarbo**, **Powerwall**, and **Lymow** battery percents (one row each, colour chip on each row). Pick it under Settings → Modules → Vestaboard live module. **ALL** only appears when Powerwall or Lymow is enabled.
