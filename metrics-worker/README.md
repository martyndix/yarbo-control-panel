# Anonymous install metrics (Cloudflare Worker)

Panels POST `/ping` once a day. You read counts on `/stats?token=...`.

## One-time setup

1. Create a free [Cloudflare](https://dash.cloudflare.com/sign-up) account if you do not have one.
2. On your Mac, in this folder:

```bash
cd metrics-worker
npm install -g wrangler
npx wrangler login
```

A browser window opens. Approve the login.

3. Create the database and apply the schema:

```bash
npx wrangler d1 create yarbo-panel-metrics
```

Copy the `database_id` UUID from the output. Paste it over `00000000-0000-0000-0000-000000000000` in `wrangler.toml`.

```bash
npx wrangler d1 execute yarbo-panel-metrics --remote --file=./schema.sql
```

4. Set the private stats token (pick a long random string; this is the password for your stats page):

```bash
npx wrangler secret put STATS_TOKEN
```

Paste the token when prompted. Save it in your password manager.

5. Deploy:

```bash
npx wrangler deploy
```

Wrangler prints a URL like `https://yarbo-panel-metrics.<your-subdomain>.workers.dev`.

6. Open stats (bookmark this):

`https://yarbo-panel-metrics.<your-subdomain>.workers.dev/stats?token=YOUR_TOKEN`

7. If that host is **not** `yarbo-panel-metrics.martyndix.workers.dev`, set the ping URL on each machine that runs the panel (including yours), then restart the panel:

```bash
# systemd (Pi)
sudo systemctl edit yarbo-panel
# add:
# [Service]
# Environment=YARBO_METRICS_URL=https://yarbo-panel-metrics.YOUR-SUBDOMAIN.workers.dev/ping
sudo systemctl restart yarbo-panel
```

On a Mac, export `YARBO_METRICS_URL` in the shell before `./scripts/dev.sh`.

Pings fail silently until this Worker is live. After it is live, wait for panels to start (or up to ~20 hours) to see counts.

Do not commit `STATS_TOKEN`. Committing `database_id` in `wrangler.toml` after step 3 is fine.
