<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/YarboPaperDevice.php';
require_once __DIR__ . '/../src/YarboVestaboard.php';
require_once __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-ota-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        [
            'id' => 'tab1',
            'name' => 'Barbara',
            'kind' => 'papermono',
            'token' => 'aabbccddeeff0011aabbccddeeff0011',
            'last_seen_at' => gmdate('c'),
            'fw_reported' => '0.1.56',
        ],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);

$missing = $devices->requestOta('tab1');
if (($missing['ok'] ?? true) === true || !str_contains((string) ($missing['error'] ?? ''), 'Build')) {
    fwrite(STDERR, 'missing binary must ask to build ' . json_encode($missing) . "\n");
    exit(1);
}

$bin = $root . '/' . Yarbo\YarboPaperDevice::FIRMWARE_RELATIVE;
$factory = $root . '/' . Yarbo\YarboPaperDevice::FIRMWARE_FACTORY_RELATIVE;
mkdir(dirname($bin), 0775, true);
file_put_contents($bin, str_repeat('A', 2048));
file_put_contents($factory, str_repeat('B', 2048));

$queued = $devices->requestOta('tab1');
if (!($queued['ok'] ?? false)) {
    fwrite(STDERR, 'queue failed ' . json_encode($queued) . "\n");
    exit(1);
}

$devices->markOtaServed('tab1');
$devices->touch('tab1', '0.1.56', 85, true, false);

$afterServe = null;
foreach ($devices->publicTablets() as $row) {
    if ($row['id'] === 'tab1') {
        $afterServe = $row;
    }
}
if (empty($afterServe['ota_pending'])) {
    fwrite(STDERR, 'pending must survive a served binary while still on 0.1.56 ' . json_encode($afterServe) . "\n");
    exit(1);
}

$pendingDev = $devices->findByToken('aabbccddeeff0011aabbccddeeff0011');
$compact = $devices->compactStatus('papermono', $pendingDev);
$compactJson = json_encode($compact);
if (($compact['ota_pending'] ?? false) !== true || ($compact['firmware_latest'] ?? '') !== Yarbo\YarboPaperDevice::FIRMWARE_VERSION) {
    fwrite(STDERR, 'ota compact ' . $compactJson . "\n");
    exit(1);
}
if (isset($compact['home_items']) || isset($compact['hub']) || isset($compact['powerwall_pct'])) {
    fwrite(STDERR, "ota compact must stay tiny, got {$compactJson}\n");
    exit(1);
}
if ($compactJson === false || strlen($compactJson) > 120) {
    fwrite(STDERR, "ota compact too large {$compactJson}\n");
    exit(1);
}

$devices->touch('tab1', Yarbo\YarboPaperDevice::FIRMWARE_VERSION, 85, true, false);
$done = null;
foreach ($devices->publicTablets() as $row) {
    if ($row['id'] === 'tab1') {
        $done = $row;
    }
}
if (!empty($done['ota_pending'])) {
    fwrite(STDERR, 'pending must clear once the tablet reports latest ' . json_encode($done) . "\n");
    exit(1);
}

$store = json_decode((string) file_get_contents($root . '/data/papermono-devices.json'), true);
$store['devices'][0]['ota_pending'] = true;
$store['devices'][0]['ota_requested_at'] = gmdate('c', time() - 1000);
$store['devices'][0]['fw_reported'] = '0.1.56';
file_put_contents($root . '/data/papermono-devices.json', json_encode($store, JSON_UNESCAPED_SLASHES));
$devices->touch('tab1', '0.1.56', 85, true, false);
$stale = null;
foreach ($devices->publicTablets() as $row) {
    if ($row['id'] === 'tab1') {
        $stale = $row;
    }
}
if (!empty($stale['ota_pending'])) {
    fwrite(STDERR, 'pending must time out ' . json_encode($stale) . "\n");
    exit(1);
}

$api = file_get_contents(__DIR__ . '/../public/api/device.php');
$js = file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = file_get_contents(__DIR__ . '/../CHANGELOG.md');
if ($api === false || $js === false || $change === false) {
    fwrite(STDERR, "missing sources\n");
    exit(1);
}
if (!preg_match("/action === 'firmware'(.*)if \\(\\\$method !== 'POST'\\)/s", $api, $chunk)) {
    fwrite(STDERR, "firmware action missing\n");
    exit(1);
}
if (str_contains($chunk[1], 'buildFirmware')) {
    fwrite(STDERR, "firmware GET must not compile during the tablet download\n");
    exit(1);
}
if (!str_contains($chunk[1], 'firmwareAvailable')) {
    fwrite(STDERR, "firmware GET must serve an existing binary without compiling\n");
    exit(1);
}
if (str_contains($chunk[1], 'firmwareReadyForOta')) {
    fwrite(STDERR, "firmware GET must not 503 for a stale-but-present binary during download\n");
    exit(1);
}
if (!str_contains($js, 'once per boot') || !str_contains($js, 'Waiting for')) {
    fwrite(STDERR, "paired list must tell you to reboot if Updating sits on the old firmware\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.48]')) {
    fwrite(STDERR, "changelog 4.0.48 missing\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.50]')) {
    fwrite(STDERR, "changelog 4.0.50 missing\n");
    exit(1);
}
$flash = file_get_contents(__DIR__ . '/../scripts/papermono_flash.py');
$kit = file_get_contents(__DIR__ . '/../scripts/paper_setup_kit.py');
if ($flash === false || $kit === false || !str_contains($flash, 'wait_for_app_ready') || !str_contains($kit, '--wifi-only')) {
    fwrite(STDERR, "USB helpers must wait for PAPER_READY and support --wifi-only\n");
    exit(1);
}

echo "ok\n";
