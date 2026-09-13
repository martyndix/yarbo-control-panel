# Tesla Powerwall

The Powerwall module shows **house draw**, **solar generation**, and **battery %** on the web dashboard, Vestaboard (when it is the live module), and the e-paper companions.

You do **not** need the local Gateway password. **Tesla cloud (Fleet API)** is the default.

## Tesla cloud (recommended)

Energy product APIs are free on [developer.tesla.com](https://developer.tesla.com/dashboard). Vehicle polling is not required.

1. Create a Tesla developer account and an **application**.
2. Enable scope **energy_device_data** (and `openid` / `offline_access` / `user_data`).
3. This panel must be reachable by Tesla over **HTTPS** (Cloudflare Tunnel, Tailscale Funnel, or a reverse proxy). Tesla will not use a LAN-only `http://192.168…` origin for the public key.
4. In Settings → Tesla Powerwall:
   - Set **Public panel URL** to that HTTPS origin (no trailing slash).
   - Paste **Client ID** (and secret if Tesla issued one).
   - Choose region: **eu**, **na**, or **cn**.
   - **Generate Tesla public key**. The file is written to `public/.well-known/appspecific/com.tesla.3p.public-key.pem`.
5. In the Tesla developer dashboard, set:
   - Allowed origin / Allowed redirect: your HTTPS origin
   - Redirect URI: `https://YOUR-HOST/api/tesla.php?action=callback`
   - Public key URL: `https://YOUR-HOST/.well-known/appspecific/com.tesla.3p.public-key.pem`
6. **Save** in the panel, then **Sign in with Tesla**. After OAuth, the panel stores a refresh token and polls `live_status`.

If you already have a Fleet **refresh token**, paste it instead of OAuth.

**Test Powerwall** should then show house draw / solar / battery.

Cloudflare quick path: `cloudflared tunnel --url http://127.0.0.1:8080` while the panel runs, use the `https://*.trycloudflare.com` URL as the public panel URL (token is temporary unless you use a named tunnel).

## Local Gateway (optional)

Use this if you find the Backup Gateway on the LAN.

1. Tesla app → energy site, or router DHCP (names like Tesla, Powerwall, Tegra).
2. Sticker **inside the Gateway door / QR**: customer password is usually the **last 5 characters** of the printed password, **not** Tesla.com.
3. Settings → Local Gateway: IP, email, that password → Test.

Local JSON: `POST /api/login/Basic`, then `GET /api/meters/aggregates` and `GET /api/system_status/soe`. Powerwall 3 often has a weaker local API; use cloud if login fails.

## Vestaboard

Settings → Modules → **Vestaboard live module** → Powerwall. Quiet hours and Vestaboard-app hold still apply.
