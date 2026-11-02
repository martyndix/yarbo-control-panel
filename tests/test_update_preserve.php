<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$update = (string) file_get_contents($root . '/scripts/update.sh');
$ignore = (string) file_get_contents($root . '/.gitignore');
$change = (string) file_get_contents($root . '/CHANGELOG.md');
$html = (string) file_get_contents($root . '/public/index.php');
$pw = (string) file_get_contents($root . '/src/YarboPowerwall.php');

if ($update === '' || $ignore === '' || $change === '') {
    fwrite(STDERR, "could not read update files\n");
    exit(1);
}

foreach ([
    'powerwall-config.json',
    'lymow-config.json',
    'unifi-config.json',
    'vestaboard-config.json',
    'hub-config.json',
    'home.json',
    'home.json.bak',
    'home-automations.json',
] as $name) {
    if (!str_contains($update, $name)) {
        fwrite(STDERR, "update.sh must preserve {$name}\n");
        exit(1);
    }
}

if (!str_contains($update, 'Preserved') || !str_contains($update, 'Restored local setting files')) {
    fwrite(STDERR, "update.sh must copy settings aside before git and restore after\n");
    exit(1);
}

if (!str_contains($update, 'YARBO_UPDATE_FROM_COPY') || !str_contains($update, '.update-script-copy')) {
    fwrite(STDERR, "update.sh must exec a copy so git cannot splice the running script\n");
    exit(1);
}

$gitBlock = strpos($update, 'step "Preserved');
$restoreBlock = strpos($update, 'Restored local setting files');
$resetBlock = strpos($update, 'run_owner "git reset --hard');
if ($gitBlock === false || $restoreBlock === false || $resetBlock === false || !($gitBlock < $resetBlock && $resetBlock < $restoreBlock)) {
    fwrite(STDERR, "preserve must wrap git reset --hard\n");
    exit(1);
}

if (!str_contains($ignore, '/data/.update-preserve/')) {
    fwrite(STDERR, "gitignore must exclude the update preserve folder\n");
    exit(1);
}

if (!str_contains($ignore, '/data/.update-script-copy')) {
    fwrite(STDERR, "gitignore must exclude the update script copy\n");
    exit(1);
}

if (!str_contains($pw, "\$tmp = \$path . '.tmp'") || !str_contains($pw, 'rename($tmp, $path)')) {
    fwrite(STDERR, "Powerwall save must write via a temp file then rename\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.112]')) {
    fwrite(STDERR, "changelog 4.0.112\n");
    exit(1);
}

if (!str_contains($html, 'copied aside and restored after the pull')) {
    fwrite(STDERR, "Settings update hint must mention restore\n");
    exit(1);
}

if (!str_contains($html, 'id="settings-panel-restart"') || !str_contains($html, 'Restart panel')) {
    fwrite(STDERR, "Settings must have Restart panel button\n");
    exit(1);
}

$api = (string) file_get_contents($root . '/public/api/update.php');
$php = (string) file_get_contents($root . '/src/YarboUpdate.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
if (!str_contains($api, "'restart'") || !str_contains($php, 'function restartPanelAsync')) {
    fwrite(STDERR, "update API must support restart\n");
    exit(1);
}
if (!str_contains($js, 'runPanelRestart') || !str_contains($js, "action: 'restart'")) {
    fwrite(STDERR, "app.js must wire Restart panel\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.169]')) {
    fwrite(STDERR, "changelog 4.0.169\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.170]')) {
    fwrite(STDERR, "changelog 4.0.170\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.171]')) {
    fwrite(STDERR, "changelog 4.0.171\n");
    exit(1);
}
if (!str_contains($html, 'id="settings-https-ensure"') || !str_contains($html, 'Start HTTPS')) {
    fwrite(STDERR, "Settings must have Start HTTPS button\n");
    exit(1);
}
$httpsPhp = (string) file_get_contents($root . '/src/YarboPanelHttps.php');
$ensureSh = (string) file_get_contents($root . '/scripts/panel_https_ensure.sh');
if (!str_contains($httpsPhp, 'class YarboPanelHttps') || !str_contains($ensureSh, 'panel_https_proxy.py')) {
    fwrite(STDERR, "HTTPS ensure helpers missing\n");
    exit(1);
}
if (!str_contains($api, 'https_ensure') || !str_contains($api, 'https-status')) {
    fwrite(STDERR, "update API must support https ensure/status\n");
    exit(1);
}
if (!str_contains($js, 'runHttpsEnsure')) {
    fwrite(STDERR, "app.js must wire Start HTTPS\n");
    exit(1);
}
if (!str_contains($js, 'HTTPS was down — starting automatically')) {
    fwrite(STDERR, "app.js must auto-start HTTPS when down\n");
    exit(1);
}
$panelSh = (string) file_get_contents($root . '/scripts/panel.sh');
if (!str_contains($panelSh, 'Watching HTTPS proxy') || !str_contains($panelSh, 'HTTPS_WATCH_PID')) {
    fwrite(STDERR, "panel.sh must watch/restart HTTPS proxy\n");
    exit(1);
}
if (!str_contains((string) file_get_contents($root . '/CHANGELOG.md'), '## [4.0.184]')) {
    fwrite(STDERR, "changelog 4.0.184\n");
    exit(1);
}
if (!str_contains((string) file_get_contents($root . '/CHANGELOG.md'), '## [4.0.185]')) {
    fwrite(STDERR, "changelog 4.0.185\n");
    exit(1);
}
if (!str_contains((string) file_get_contents($root . '/CHANGELOG.md'), '## [4.0.186]')) {
    fwrite(STDERR, "changelog 4.0.186\n");
    exit(1);
}
if (!str_contains((string) file_get_contents($root . '/CHANGELOG.md'), '## [4.0.187]')) {
    fwrite(STDERR, "changelog 4.0.187\n");
    exit(1);
}
$updateSh = (string) file_get_contents($root . '/scripts/update.sh');
if (!str_contains($updateSh, 'yarbo_ensure_pyatv')) {
    fwrite(STDERR, "update.sh must install pyatv via yarbo_ensure_pyatv\n");
    exit(1);
}
if (!str_contains($php, 'clearOrphanedUpdateState')) {
    fwrite(STDERR, "restart must clear orphaned update state\n");
    exit(1);
}

// Stale "restarting" without a lock must not block Restart panel.
require $root . '/vendor/autoload.php';
use Yarbo\YarboUpdate;

$staleRoot = sys_get_temp_dir() . '/yarbo-upd-stale-' . bin2hex(random_bytes(3));
mkdir($staleRoot . '/data', 0775, true);
file_put_contents($staleRoot . '/data/update-status.json', json_encode([
    'state' => 'restarting',
    'message' => 'Restarting panel service',
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));
$upd = new YarboUpdate($staleRoot);
$restart = $upd->restartPanelAsync();
if (!($restart['ok'] ?? false)) {
    fwrite(STDERR, 'stale restarting blocked restart: ' . ($restart['error'] ?? '') . "\n");
    exit(1);
}

$tmpRoot = sys_get_temp_dir() . '/yarbo-upd-preserve-' . bin2hex(random_bytes(3));
mkdir($tmpRoot . '/data', 0775, true);
file_put_contents($tmpRoot . '/data/powerwall-config.json', json_encode([
    'transport' => 'local',
    'gateway_host' => '192.168.9.77',
    'gateway_password' => 'abcde',
], JSON_PRETTY_PRINT) . "\n");
$preserve = $tmpRoot . '/data/.update-preserve';
mkdir($preserve, 0775, true);
copy($tmpRoot . '/data/powerwall-config.json', $preserve . '/powerwall-config.json');
unlink($tmpRoot . '/data/powerwall-config.json');
copy($preserve . '/powerwall-config.json', $tmpRoot . '/data/powerwall-config.json');
$restored = json_decode((string) file_get_contents($tmpRoot . '/data/powerwall-config.json'), true);
if (($restored['gateway_host'] ?? '') !== '192.168.9.77' || ($restored['gateway_password'] ?? '') !== 'abcde') {
    fwrite(STDERR, "preserve/restore lost gateway login\n");
    exit(1);
}

echo "ok: update preserve\n";
