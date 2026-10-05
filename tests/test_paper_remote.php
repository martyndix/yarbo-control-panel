<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperRemote.php';
require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';
require __DIR__ . '/../src/YarboPowerwall.php';
require __DIR__ . '/../src/YarboLymow.php';
require __DIR__ . '/../src/YarboHome.php';

$root = sys_get_temp_dir() . '/yarbo-paper-remote-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'aabbccddeeff00112233445566778899'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$remote = new Yarbo\YarboPaperRemote($root);
$off = $remote->tabletOrigin();
if ($off !== '') {
    fwrite(STDERR, "disabled origin should be empty\n");
    exit(1);
}

$saved = $remote->save([
    'enabled' => true,
    'provider' => 'custom',
    'origin' => 'https://panel.example.ts.net/',
]);
if (!($saved['ok'] ?? false)) {
    fwrite(STDERR, 'save failed ' . json_encode($saved) . "\n");
    exit(1);
}
if ($remote->tabletOrigin() !== 'https://panel.example.ts.net') {
    fwrite(STDERR, 'origin ' . $remote->tabletOrigin() . "\n");
    exit(1);
}

$bad = $remote->save([
    'enabled' => true,
    'provider' => 'custom',
    'origin' => 'http://192.168.1.50:8080',
]);
if (($bad['ok'] ?? true) === true) {
    fwrite(STDERR, "http LAN origin should be rejected\n");
    exit(1);
}

$devices = new Yarbo\YarboPaperDevice($root);
$dash = $devices->dashboard();
if (($dash['paper_remote']['origin'] ?? '') !== 'https://panel.example.ts.net') {
    fwrite(STDERR, 'dashboard remote ' . json_encode($dash['paper_remote'] ?? null) . "\n");
    exit(1);
}

$allow = $remote->allowRemoteRequest(
    'GET',
    '/api/device.php',
    ['action' => 'compact'],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    ''
);
if (!($allow['ok'] ?? false)) {
    fwrite(STDERR, 'compact should be allowed ' . json_encode($allow) . "\n");
    exit(1);
}

$logo = $remote->allowRemoteRequest('GET', '/api/device.php', ['action' => 'logo'], [], '');
if (($logo['ok'] ?? true) === true || ($logo['status'] ?? 0) !== 401) {
    fwrite(STDERR, 'logo without token must 401 ' . json_encode($logo) . "\n");
    exit(1);
}

$logoOk = $remote->allowRemoteRequest(
    'GET',
    '/api/device.php',
    ['action' => 'logo'],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    ''
);
if (!($logoOk['ok'] ?? false)) {
    fwrite(STDERR, 'logo with token should pass ' . json_encode($logoOk) . "\n");
    exit(1);
}

$dashBlock = $remote->allowRemoteRequest(
    'GET',
    '/api/device.php',
    ['action' => 'dashboard'],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    ''
);
if (($dashBlock['ok'] ?? true) === true) {
    fwrite(STDERR, "dashboard must not be remote\n");
    exit(1);
}

$reg = $remote->allowRemoteRequest(
    'POST',
    '/api/device.php',
    [],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    json_encode(['action' => 'register', 'token' => 'aabbccddeeff00112233445566778899'])
);
if (($reg['ok'] ?? true) === true) {
    fwrite(STDERR, "register must not be remote\n");
    exit(1);
}

$cmd = $remote->allowRemoteRequest(
    'POST',
    '/api/device.php',
    [],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    json_encode(['action' => 'command', 'command' => 'stop', 'token' => 'aabbccddeeff00112233445566778899'])
);
if (!($cmd['ok'] ?? false)) {
    fwrite(STDERR, 'command should be allowed ' . json_encode($cmd) . "\n");
    exit(1);
}

$index = $remote->allowRemoteRequest(
    'GET',
    '/',
    [],
    ['X-PaperMono-Token' => 'aabbccddeeff00112233445566778899'],
    ''
);
if (($index['status'] ?? 0) !== 404) {
    fwrite(STDERR, "index must 404 remotely " . json_encode($index) . "\n");
    exit(1);
}

$offSave = $remote->save(['enabled' => false]);
if (!($offSave['ok'] ?? false) || $remote->tabletOrigin() !== '') {
    fwrite(STDERR, 'disable should clear tablet origin ' . json_encode($offSave) . "\n");
    exit(1);
}

$fw = file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$net = file_get_contents(__DIR__ . '/../firmware/papermono/src/paper_net.h');
$color = file_get_contents(__DIR__ . '/../firmware/papercolor/src/main.cpp');
$ver = file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$cver = file_get_contents(__DIR__ . '/../firmware/papercolor/src/version.h');
$html = file_get_contents(__DIR__ . '/../public/index.php');
if ($fw === false || $net === false || $color === false || $ver === false || $cver === false || $html === false) {
    fwrite(STDERR, "missing firmware source\n");
    exit(1);
}
foreach ([
    'String remoteUrl;',
    'bool usingRemote = false;',
    'prefs.putString("remurl", remoteUrl);',
    'paperNetGet',
    'PAPER_ISRG_ROOT_X1',
    'drawString("R"',
    '#define PAPERMONO_FW_VERSION "0.1.61"',
] as $needle) {
    $hay = $fw . "\n" . $net . "\n" . $ver;
    if (!str_contains($hay, $needle)) {
        fwrite(STDERR, "papermono missing: {$needle}\n");
        exit(1);
    }
}
foreach ([
    'String remoteUrl;',
    'bool usingRemote = false;',
    'wifi = String("R  ") + wifi',
    'paperNetGet',
    '#define PAPERMONO_FW_VERSION "0.2.18-colour"',
] as $needle) {
    $hay = $color . "\n" . file_get_contents(__DIR__ . '/../firmware/papercolor/src/paper_net.h') . "\n" . $cver;
    if (!str_contains($hay, $needle)) {
        fwrite(STDERR, "papercolor missing: {$needle}\n");
        exit(1);
    }
}
if (!str_contains($html, 'id="papermono-remote-enabled"')) {
    fwrite(STDERR, "settings remote toggle missing\n");
    exit(1);
}
if (!str_contains($html, 'Funnel is not a General access rule')) {
    fwrite(STDERR, "settings funnel help missing\n");
    exit(1);
}
if (!str_contains($html, 'JSON editor')) {
    fwrite(STDERR, "settings JSON editor step missing\n");
    exit(1);
}
if (!str_contains($html, 'id="papermono-remote-funnel-url-wrap"')) {
    fwrite(STDERR, "funnel approval link wrap missing\n");
    exit(1);
}

$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$sh = file_get_contents(__DIR__ . '/../scripts/paper_remote.sh');
$change = file_get_contents(__DIR__ . '/../CHANGELOG.md');
if ($js === false || $sh === false || $change === false) {
    fwrite(STDERR, "missing panel sources\n");
    exit(1);
}
if (str_contains($js, "|| 'Done.'")) {
    fwrite(STDERR, "paper remote must not report Done with no next step\n");
    exit(1);
}
if (!str_contains($js, 'openPaperRemoteUrl')) {
    fwrite(STDERR, "login must try to open the Tailscale URL\n");
    exit(1);
}
if (!str_contains($js, 'JSON editor')) {
    fwrite(STDERR, "login/funnel result must name the JSON editor\n");
    exit(1);
}
if (!str_contains($sh, 'JSON editor')) {
    fwrite(STDERR, "paper_remote.sh must explain JSON editor\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.44]')) {
    fwrite(STDERR, "changelog 4.0.44 missing\n");
    exit(1);
}
if (str_contains($phpSrc = file_get_contents(__DIR__ . '/../src/YarboPaperRemote.php') ?: '', '2>/dev/null')) {
    fwrite(STDERR, "paper remote PHP must not hide Tailscale stderr\n");
    exit(1);
}
if (!str_contains($sh, '--yes') || !str_contains($sh, 'http://127.0.0.1')) {
    fwrite(STDERR, "funnel-on must use --yes and proxy 127.0.0.1\n");
    exit(1);
}
if (str_contains($phpSrc, 'Command produced no output')) {
    fwrite(STDERR, "generic no-output error must be replaced\n");
    exit(1);
}

$missingScript = new Yarbo\YarboPaperRemote($root . '/no-script');
$emptyTs = $missingScript->tailscaleStatus();
foreach (['auth_url', 'funnel_enable_url', 'needs_funnel_acl', 'backend'] as $key) {
    if (!array_key_exists($key, $emptyTs)) {
        fwrite(STDERR, "tailscale status missing {$key}\n");
        exit(1);
    }
}

echo "ok\n";
