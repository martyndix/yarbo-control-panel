const UUID_RE = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;

function bool01(v) {
  return v ? 1 : 0;
}

function intCount(v) {
  const n = Number(v);
  if (!Number.isFinite(n) || n < 0) return 0;
  return Math.min(32, Math.floor(n));
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === 'POST' && (url.pathname === '/ping' || url.pathname === '/')) {
      return ping(request, env);
    }
    if (request.method === 'GET' && url.pathname === '/stats') {
      return stats(request, url, env);
    }
    return new Response('Not found', { status: 404 });
  },
};

async function ping(request, env) {
  let body;
  try {
    body = await request.json();
  } catch {
    return json({ ok: false, error: 'invalid json' }, 400);
  }
  const id = String(body.id || '');
  if (!UUID_RE.test(id)) {
    return json({ ok: false, error: 'invalid id' }, 400);
  }
  const version = String(body.version || 'unknown').slice(0, 32);
  const modules = body.modules && typeof body.modules === 'object' ? body.modules : {};
  const paper = body.paper && typeof body.paper === 'object' ? body.paper : {};
  const os = ['linux', 'darwin', 'other'].includes(body.os) ? body.os : 'other';
  const now = Math.floor(Date.now() / 1000);

  await env.DB.prepare(
    `INSERT INTO installs (id, version, yarbo, powerwall, lymow, vestaboard, papermono, papercolor, os, first_seen, last_seen)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET
       version = excluded.version,
       yarbo = excluded.yarbo,
       powerwall = excluded.powerwall,
       lymow = excluded.lymow,
       vestaboard = excluded.vestaboard,
       papermono = excluded.papermono,
       papercolor = excluded.papercolor,
       os = excluded.os,
       last_seen = excluded.last_seen`
  )
    .bind(
      id,
      version,
      bool01(modules.yarbo),
      bool01(modules.powerwall),
      bool01(modules.lymow),
      bool01(modules.vestaboard),
      intCount(paper.papermono),
      intCount(paper.papercolor),
      os,
      now,
      now
    )
    .run();

  return json({ ok: true });
}

async function stats(request, url, env) {
  const token = url.searchParams.get('token') || '';
  const expected = env.STATS_TOKEN || '';
  if (!expected || token !== expected) {
    return new Response('Not found', { status: 404 });
  }

  const now = Math.floor(Date.now() / 1000);
  const day = 86400;
  const totals = await env.DB.prepare(
    `SELECT COUNT(*) AS n,
            SUM(CASE WHEN last_seen >= ? THEN 1 ELSE 0 END) AS d7,
            SUM(CASE WHEN last_seen >= ? THEN 1 ELSE 0 END) AS d30,
            SUM(yarbo) AS yarbo,
            SUM(powerwall) AS powerwall,
            SUM(lymow) AS lymow,
            SUM(vestaboard) AS vestaboard,
            SUM(CASE WHEN papermono > 0 THEN 1 ELSE 0 END) AS papermono,
            SUM(CASE WHEN papercolor > 0 THEN 1 ELSE 0 END) AS papercolor,
            SUM(CASE WHEN os = 'linux' THEN 1 ELSE 0 END) AS linux,
            SUM(CASE WHEN os = 'darwin' THEN 1 ELSE 0 END) AS darwin
     FROM installs`
  )
    .bind(now - 7 * day, now - 30 * day)
    .first();

  const versions = await env.DB.prepare(
    `SELECT version, COUNT(*) AS n FROM installs GROUP BY version ORDER BY n DESC, version`
  ).all();

  const n = Number(totals?.n || 0);
  const rows = (versions.results || [])
    .map(
      (row) =>
        `<tr><td>${escapeHtml(row.version)}</td><td>${Number(row.n)}</td></tr>`
    )
    .join('');

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Yarbo panel installs</title>
  <style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; color: #111; background: #f7f5ef; }
    h1 { font-size: 1.4rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; margin: 1.25rem 0; }
    .card { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 1rem; }
    .n { font-size: 1.8rem; font-weight: 700; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid #eee; }
    p.hint { color: #555; font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>Yarbo panel installs</h1>
  <p class="hint">Anonymous pings only. No serials, names, or locations. Updated live.</p>
  <div class="grid">
    <div class="card"><div class="n">${n}</div>Unique installs</div>
    <div class="card"><div class="n">${Number(totals?.d7 || 0)}</div>Active last 7 days</div>
    <div class="card"><div class="n">${Number(totals?.d30 || 0)}</div>Active last 30 days</div>
  </div>
  <div class="grid">
    <div class="card"><div class="n">${Number(totals?.yarbo || 0)}</div>Yarbo</div>
    <div class="card"><div class="n">${Number(totals?.powerwall || 0)}</div>Powerwall</div>
    <div class="card"><div class="n">${Number(totals?.lymow || 0)}</div>Lymow</div>
    <div class="card"><div class="n">${Number(totals?.vestaboard || 0)}</div>Vestaboard</div>
    <div class="card"><div class="n">${Number(totals?.papermono || 0)}</div>PaperMono</div>
    <div class="card"><div class="n">${Number(totals?.papercolor || 0)}</div>Paper Colour</div>
  </div>
  <div class="grid">
    <div class="card"><div class="n">${Number(totals?.linux || 0)}</div>Linux / Pi</div>
    <div class="card"><div class="n">${Number(totals?.darwin || 0)}</div>Mac</div>
  </div>
  <h2>Versions</h2>
  <table>
    <thead><tr><th>Version</th><th>Installs</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="2">None yet</td></tr>'}</tbody>
  </table>
</body>
</html>`;

  return new Response(html, {
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
  });
}

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
