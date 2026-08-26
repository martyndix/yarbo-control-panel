# Vestaboard Note (optional)

Show Yarbo status on a [Vestaboard Note](https://docs.vestaboard.com/docs/local-api/introduction) on your LAN. The panel uses the **Local API** only (no Vestaboard cloud). The Note is **3 rows × 15 columns**.

This is optional. Leave **Enable Vestaboard Note** off if you do not have a board.

## What it shows

Uppercase 15-character lines, for example:

```
YARBO    MOWING
BATTERY     85%
MOWER
```

```
YARBO  CHARGING
BATTERY     55%
ON DOCK
```

```
YARBO      IDLE
BATTERY    FULL
CHARGED
```

Battery percent is rounded to 5% so the flaps are not constantly rewriting. A colour chip sits at the end of the battery line: green at 60% or more (and Full), yellow from 40%, orange from 20%, red below that. In an error state the Note shows red chips on the ERROR and CODE lines. Full uses the same rule as the panel (on the pad and capacity ≥ 95%). Updates are sent only when that 3-line message changes, and not more than once every 15 seconds (Vestaboard’s hardware rate limit).

## Setup

1. Request a one-time **enablement token** from Vestaboard’s [Local API request form](https://www.vestaboard.com/local-api). The Note must be paired and online. They email that token to the owner — it is **not** the API key. Official steps: [Authentication](https://docs.vestaboard.com/docs/local-api/authentication/).
2. On the same LAN (IPv4; `vestaboard.local` or the board’s IP), exchange the email token for the real key:

   ```bash
   curl -X POST \
     -H "X-Vestaboard-Local-Api-Enablement-Token: YOUR_EMAIL_TOKEN" \
     http://vestaboard.local:7000/local-api/enablement
   ```

   Copy `apiKey` from the JSON response. If `vestaboard.local` fails, use the Note’s IPv4 address. IPv6 is unreliable per Vestaboard.
3. Open the panel → **Settings → Vestaboard Note**.
4. Check **Enable Vestaboard Note**.
5. Enter the host and that **apiKey**. Use the 3×15 preview (live or samples).
6. **Test connection**, then **Save**. **Send now** writes the current layout immediately. A **Vestaboard Note** section also appears on the main dashboard with the same 3×15 layout (you can hide or reorder it under Appearance).
7. Restart the panel (or reboot the Pi) so `scripts/vestaboard_watch.php` is running beside the MQTT agent. After that, the Note updates with no browser open.

The key is stored in `data/vestaboard-config.json` (not committed).

## Limits

- Vestaboard **Note** only (3×15), not Flagship 6×22.
- The watcher is started by `scripts/panel.sh`. A panel that is not started that way will only update when you click **Send now**.
- If the board is unreachable, the watcher backs off and retries; MQTT is not blocked.
