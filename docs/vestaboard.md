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

Battery percent is rounded to 5% so the flaps are not constantly rewriting. Full uses the same rule as the panel (on the pad and capacity ≥ 95%). Updates are sent only when that 3-line message changes, and not more than once every 15 seconds (Vestaboard’s hardware rate limit).

## Setup

1. Enable the Local API on the Note and keep the **local API key** Vestaboard emails you. See [Local API introduction](https://docs.vestaboard.com/docs/local-api/introduction).
2. Put the Note and this panel on the same LAN. Use **IPv4** (`vestaboard.local` or the board’s IP). IPv6 is unreliable per Vestaboard.
3. Open the panel → **Settings → Vestaboard Note**.
4. Check **Enable Vestaboard Note**.
5. Enter the host and API key. Use the 3×15 preview (live or samples).
6. **Test connection**, then **Save**. **Send now** writes the current layout immediately.
7. Restart the panel (or reboot the Pi) so `scripts/vestaboard_watch.php` is running beside the MQTT agent. After that, the Note updates with no browser open.

The key is stored in `data/vestaboard-config.json` (not committed).

## Limits

- Vestaboard **Note** only (3×15), not Flagship 6×22.
- The watcher is started by `scripts/panel.sh`. A panel that is not started that way will only update when you click **Send now**.
- If the board is unreachable, the watcher backs off and retries; MQTT is not blocked.
