<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-batt-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
        ['id' => 'tab2', 'name' => 'Study', 'kind' => 'papercolor', 'token' => 'tok2'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$before = $devices->publicTablets();
foreach ($before as $row) {
    if (($row['battery_level'] ?? null) !== null || ($row['charging_label'] ?? '') !== '—') {
        fwrite(STDERR, 'unset battery should be unknown ' . json_encode($row) . "\n");
        exit(1);
    }
}

$devices->touch('tab1', '0.1.52', 84, true);
$devices->touch('tab2', '0.2.16-colour', 12, false);

$after = [];
foreach ($devices->publicTablets() as $row) {
    $after[$row['id']] = $row;
}
if (($after['tab1']['battery_level'] ?? null) !== 84 || ($after['tab1']['is_charging'] ?? false) !== true) {
    fwrite(STDERR, 'kitchen battery ' . json_encode($after['tab1'] ?? null) . "\n");
    exit(1);
}
if (($after['tab1']['battery_label'] ?? '') !== '84%' || ($after['tab1']['charging_label'] ?? '') !== 'Charging') {
    fwrite(STDERR, 'kitchen labels ' . json_encode($after['tab1']) . "\n");
    exit(1);
}
if (($after['tab2']['battery_level'] ?? null) !== 12 || ($after['tab2']['is_charging'] ?? true) !== false) {
    fwrite(STDERR, 'study battery ' . json_encode($after['tab2'] ?? null) . "\n");
    exit(1);
}
if (($after['tab2']['charging_label'] ?? '') !== 'Not charging') {
    fwrite(STDERR, 'study charging ' . json_encode($after['tab2']) . "\n");
    exit(1);
}
if (($after['tab1']['ota_charge_ok'] ?? false) !== true) {
    fwrite(STDERR, 'charging kitchen must allow OTA ' . json_encode($after['tab1']) . "\n");
    exit(1);
}
if (($after['tab2']['ota_charge_ok'] ?? true) !== false) {
    fwrite(STDERR, '12% study must block OTA ' . json_encode($after['tab2']) . "\n");
    exit(1);
}

$devices->touch('tab1', '0.1.52');
$kept = null;
foreach ($devices->publicTablets() as $row) {
    if ($row['id'] === 'tab1') {
        $kept = $row;
    }
}
if (($kept['battery_level'] ?? null) !== 84 || ($kept['is_charging'] ?? false) !== true) {
    fwrite(STDERR, 'poll without batt wiped power ' . json_encode($kept) . "\n");
    exit(1);
}

if (Yarbo\YarboPaperDevice::parseBatteryLevel('101') !== null
    || Yarbo\YarboPaperDevice::parseBatteryLevel('nope') !== null
    || Yarbo\YarboPaperDevice::parseBatteryLevel('7') !== 7) {
    fwrite(STDERR, "parseBatteryLevel failed\n");
    exit(1);
}
if (Yarbo\YarboPaperDevice::parseChargingFlag('charging') !== true
    || Yarbo\YarboPaperDevice::parseChargingFlag('0') !== false
    || Yarbo\YarboPaperDevice::parseChargingFlag('') !== null) {
    fwrite(STDERR, "parseChargingFlag failed\n");
    exit(1);
}

$fw = file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$color = file_get_contents(__DIR__ . '/../firmware/papercolor/src/main.cpp');
if ($fw === false || $color === false || !str_contains($fw, '&batt=') || !str_contains($color, 'isCharging()')) {
    fwrite(STDERR, "firmware missing battery query\n");
    exit(1);
}

echo "ok\n";
