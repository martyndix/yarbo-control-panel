# UniFi Protect and Access

Optional module: cameras, floodlights, sensors, and door unlock from a **local UniFi OS console**. Selected devices can appear on the **Home** grid next to Matter lights.

Not affiliated with Ubiquiti.

## What it can do

- List Protect cameras and show JPEG stills on the UniFi page and on Home.
- Toggle Protect floodlights (`PATCH /proxy/protect/integration/v1/lights/{id}` with `isLightForceEnabled` and `ledLevel` 6). If that does not confirm the LED, the panel logs into UniFi OS with the stored local admin and PATCHes private `lightOnSettings.isLedForceOn` (cookie + CSRF). A Control Plane API key alone cannot authenticate `/proxy/protect/api`; an HTML login page is not treated as success.
- Toggle Protect relays (`POST /relays/{id}/outputs/{outputId}/activate`). Relays are dry-contact outputs in Protect, not Access doors.
- Show Protect sensor status (open/closed, motion, temperature/humidity). Home re-reads `GET /sensors` in the background so those chips stay live without blocking light clicks.
- Unlock Access doors and **door controllers** (UA Hub / Gate Hub) from the Access OpenAPI (`/api/v1/developer/doors` and `/devices`). Gate Hub three-button mode can send Open / Close / Stop (`control_cmd`).
- Show **Open / Closed** on the door controller (and door) tile, next to Unlock, from the Access door-position sensor (`door_position_status`). Home refreshes that in the background so the chip can change without blocking light clicks. A separate door-position row is still listed if you want it on Home on its own.
- Tick **Show on Home** per device so it behaves like another Home row (camera still, light/relay on/off, door/controller Unlock + position, sensor text). Ticks save as you click.

## What it cannot do (this version)

- Remote UniFi via `unifi.ui.com`.
- A Vestaboard UniFi page.
- PaperMono HOUSE buttons for UniFi devices.
- Protect recordings, event history, or two-way talk.
- Access user / credential administration.
- Access after **Identity Enterprise** (the OpenAPI is disabled there).

## Settings

1. Tick **UniFi** under Settings → Modules.
2. Open **Settings → UniFi**.
3. **Console host** is the LAN IP or hostname of the UniFi OS device (Dream Machine, Cloud Gateway, UNVR). Do not include `https://`.
4. Leave **Verify TLS** off unless you have installed a trusted certificate (UniFi’s default certificate is self-signed).
5. **Protect API key:** UniFi OS → Settings → Control Plane → Integrations → Create API Key. Cameras, lights, sensors, and relays need this (`X-API-KEY`). UniFi OS also shows this page under Protect → Integrations and Access → Integrations — it is the **same Control Plane key**. It cannot list Access doors.
6. **Access API token:** this is a **different** secret. Open the **Access application** (not Control Plane) → Settings → General → Advanced → API Token → Create New. Select permission scopes when creating it (they cannot be changed later):
   - `view:space` — list doors
   - `view:device` — list hubs / controllers
   - `edit:space` — remote unlock
   If Test UniFi says `you entered no-man zone` or 0 doors / 0 controllers after pasting the Control Plane key into both fields, recreate the token inside Access and paste only that into Access API token.
7. Optional **Protect local username/password** is a UniFi OS local admin. Floodlights need this when the public Integration force-on does not light the lamp — the API key cannot log into the private light API.
8. If Access is **not** hosted on UniFi OS, tick **Access is standalone (port 12445)**. The official OpenAPI host is always `https://CONSOLE:12445` (self-signed cert).
9. **Test UniFi connection**, then tick devices under **Show on Home**.

`you entered no-man zone` / `CODE_NOT_FOUND` means the panel hit Access with a Control Plane / Protect key (or the OpenAPI is disabled). Recreate the token **inside Access**. `CODE_UNAUTHORIZED` / “You do not have permission to perform this action” means an Access token reached the API but those scopes are missing.

Leave a key or token blank on later saves to keep the stored value. Credentials stay in `data/unifi-config.json` and are never sent back to the browser.

## APIs

Protect Integration API (local):

```
https://CONSOLE/proxy/protect/integration/v1/...
Header: X-API-KEY
```

Access OpenAPI (official, [API reference](https://assets.identity.ui.com/unifi-access/api_reference.pdf)):

```
https://CONSOLE:12445/api/v1/developer/...
Header: Authorization: Bearer TOKEN
```

The panel also tries UniFi OS proxy paths (`/proxy/access/api/v1/developer` and `/proxy/access/integration/v1/developer`) with Bearer or `X-API-KEY` if port 12445 is blocked.

Door list: `GET /doors` (`view:space`). Device/hub list: `GET /devices?refresh=true` (`view:device`). Unlock: `PUT /doors/{id}/unlock` (`edit:space`; POST if the console rejects PUT). Gate Hub: add `?control_cmd=open|close|stop`. Door position is `door_position_status` on each door.

Protect lights: the panel PATCHes the public Integration API `{ "isLightForceEnabled": true, "lightDeviceSettings": { "ledLevel": 6 } }` (`/proxy/protect/integration/v1/lights/{id}` with `X-API-KEY`). If a local admin is stored, it also logs in at `/api/auth/login` (session cached) and PATCHes `/proxy/protect/api/lights/{id}` `{ "lightOnSettings": { "isLedForceOn": true } }` with cookie + CSRF. A later HTTP 401 from UniFi OS login does not revert the website On/Off if a force PATCH already succeeded. Home keeps that commanded state so the green indicator matches the lamp. Off sends `isLightForceEnabled` / `isLedForceOn` false. Relays: `POST /relays/{id}/outputs/{outputId}/activate` with `{ "state": "on"|"off" }`.

Protect sensors: `GET /proxy/protect/integration/v1/sensors` (`isOpened`, `stats.temperature.value`, `stats.humidity.value`). Home does not poll this on the request that serves the grid; a background process updates `data/unifi-inventory.json` so the next 3-second Home refresh can show Open and new readings. Access `GET /doors` (controller Open/Closed) is a separate poll in that same job.

## Home

Turn **Home** on as well. UniFi rows use ids like `unifi:camera:…`. Hide or Remove on Home only unchecks Show on Home — nothing is unpaired on the console. Scenes stay Matter-only.

White-ambiance Matter bulbs show **brightness** (blue slider) and **colour temperature** (amber slider, warm to cool). Full-colour bulbs keep a colour picker.

## Rollback

This is panel **4.0.22**. If it misbehaves, stay on **3.0.70** (or close the 4.0 pull request). UniFi settings live in `data/unifi-config.json`; deleting that file and unticking the module returns the panel to the previous module set.
