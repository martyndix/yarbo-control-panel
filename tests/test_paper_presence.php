<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/YarboPaperDevice.php';
require_once __DIR__ . '/../src/YarboVestaboard.php';
require_once __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-presence-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Martyn', 'kind' => 'papermono', 'token' => 'aabbccddeeff0011'],
        ['id' => 'tab2', 'name' => 'Barbara', 'kind' => 'papermono', 'token' => 'aabbccddeeff0022'],
    ],
    'prefs' => ['timezone' => 'Europe/Zurich'],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);

$never = null;
foreach ($devices->publicTablets() as $row) {
    if ($row['id'] === 'tab1') {
        $never = $row;
    }
}
if (($never['presence_label'] ?? '') !== 'Offline — never seen' || ($never['last_seen_label'] ?? '') !== 'Never seen') {
    fwrite(STDERR, 'never seen ' . json_encode($never) . "\n");
    exit(1);
}

$devices->touch('tab1', '0.1.57', 100, false, false);
$devices->touch('tab2', '0.1.57', 84, true, true);

$after = [];
foreach ($devices->publicTablets() as $row) {
    $after[$row['id']] = $row;
}
if (($after['tab1']['presence_label'] ?? '') !== 'Online — home network' || !empty($after['tab1']['remote'])) {
    fwrite(STDERR, 'home presence ' . json_encode($after['tab1']) . "\n");
    exit(1);
}
if (($after['tab2']['presence_label'] ?? '') !== 'Online — remote' || empty($after['tab2']['remote'])) {
    fwrite(STDERR, 'remote presence ' . json_encode($after['tab2']) . "\n");
    exit(1);
}

$store = json_decode((string) file_get_contents($root . '/data/papermono-devices.json'), true);
if (!is_array($store)) {
    fwrite(STDERR, "store missing\n");
    exit(1);
}
$store['devices'][0]['last_seen_at'] = gmdate('c', time() - 1000);
$store['devices'][0]['last_via'] = 'lan';
$store['devices'][1]['last_seen_at'] = gmdate('c', time() - 1000);
$store['devices'][1]['last_via'] = 'remote';
file_put_contents($root . '/data/papermono-devices.json', json_encode($store, JSON_UNESCAPED_SLASHES));

$stale = [];
foreach ($devices->publicTablets() as $row) {
    $stale[$row['id']] = $row;
}
if (($stale['tab1']['presence_label'] ?? '') !== 'Offline — last seen on the home network' || !empty($stale['tab1']['remote'])) {
    fwrite(STDERR, 'offline home ' . json_encode($stale['tab1']) . "\n");
    exit(1);
}
if (($stale['tab2']['presence_label'] ?? '') !== 'Offline — last seen remotely' || !empty($stale['tab2']['remote'])) {
    fwrite(STDERR, 'offline remote ' . json_encode($stale['tab2']) . "\n");
    exit(1);
}

$stamp = '2026-09-30T13:50:44+00:00';
$label = $devices->formatLastSeen($stamp);
if ($label !== '30-Sep-2026 15:50:44') {
    fwrite(STDERR, "last seen format {$label}\n");
    exit(1);
}

$_SERVER['HTTP_X_YARBO_PAPER_VIA'] = 'remote';
if (!Yarbo\YarboPaperRemote::requestIsRemote()) {
    fwrite(STDERR, "header must mark Funnel polls as remote\n");
    exit(1);
}
unset($_SERVER['HTTP_X_YARBO_PAPER_VIA']);
if (Yarbo\YarboPaperRemote::requestIsRemote()) {
    fwrite(STDERR, "LAN polls must not be remote\n");
    exit(1);
}
if (!Yarbo\YarboPaperRemote::requestIsRemote(['via' => 'remote'])) {
    fwrite(STDERR, "via=remote query must mark remote\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../src/YarboPaperRemote.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$css = file_get_contents(__DIR__ . '/../public/assets/style.css');
$change = file_get_contents(__DIR__ . '/../CHANGELOG.md');
if ($src === false || $js === false || $css === false || $change === false) {
    fwrite(STDERR, "missing sources\n");
    exit(1);
}
if (!str_contains($src, 'VIA_HEADER') || !str_contains($src, ': remote')) {
    fwrite(STDERR, "funnel proxy must mark remote polls\n");
    exit(1);
}
if (!str_contains($js, 'paperPresenceHtml') || !str_contains($js, 'paperLastSeenText')) {
    fwrite(STDERR, "paired list must use presence and last-seen helpers\n");
    exit(1);
}
if (!str_contains($css, 'is-online.is-remote')) {
    fwrite(STDERR, "paired list must style remote presence\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.45]')) {
    fwrite(STDERR, "changelog 4.0.45 missing\n");
    exit(1);
}

echo "ok\n";
