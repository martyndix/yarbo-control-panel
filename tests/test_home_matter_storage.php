<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboMatterFabric;

function hue_bridge(int $count): array
{
    $attributes = [
        '0/40/1' => 'Signify Netherlands B.V.',
        '0/40/3' => 'Hue Bridge',
        '0/40/5' => 'Hue Bridge',
        '1/29/0' => [['0' => 0x000E, '1' => 1]],
    ];
    for ($endpoint = 2; $endpoint < $count + 2; $endpoint++) {
        $attributes[$endpoint . '/29/0'] = [['deviceType' => 0x0013, 'revision' => 1]];
        $attributes[$endpoint . '/6/0'] = $endpoint % 2 === 0;
        $attributes[$endpoint . '/8/0'] = 80;
        $attributes[$endpoint . '/57/5'] = 'Light ' . $endpoint;
    }

    return [
        'node_id' => 1,
        'available' => true,
        'is_bridge' => true,
        'attributes' => $attributes,
    ];
}

$devices = YarboMatterFabric::flatten([hue_bridge(70)]);
if (count($devices) !== 70) {
    fwrite(STDERR, 'expected 70 Hue lights, got ' . count($devices) . "\n");
    exit(1);
}
$ids = [];
foreach ($devices as $row) {
    $ids[$row['id']] = true;
}
if (!isset($ids['1:2'], $ids['1:71'])) {
    fwrite(STDERR, "missing Hue endpoints\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/yarbo-home-storage-' . bin2hex(random_bytes(3));
mkdir($root . '/data/matter-server', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents(
    $root . '/data/matter-server/aabbcc.json',
    json_encode(['nodes' => ['1' => hue_bridge(70)]], JSON_UNESCAPED_SLASHES)
);

$home = new YarboHome($root);
$start = microtime(true);
$dash = $home->dashboard();
$elapsed = microtime(true) - $start;
if ($elapsed > 1.0) {
    fwrite(STDERR, "dashboard blocked for {$elapsed}s\n");
    exit(1);
}
if (count($dash['devices'] ?? []) !== 70) {
    fwrite(STDERR, 'dashboard devices ' . count($dash['devices'] ?? []) . ' fabric=' . json_encode($dash['fabric'] ?? []) . "\n");
    exit(1);
}
if (($dash['fabric']['source'] ?? '') !== 'disk') {
    fwrite(STDERR, 'expected disk source, got ' . json_encode($dash['fabric'] ?? []) . "\n");
    exit(1);
}

$rememberedOnly = sys_get_temp_dir() . '/yarbo-home-cache-' . bin2hex(random_bytes(3));
mkdir($rememberedOnly . '/data', 0775, true);
file_put_contents($rememberedOnly . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($rememberedOnly . '/data/home.json', json_encode([
    'names' => ['1:1' => 'Kitchen spots'],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '1:1', 'name' => 'Kitchen spots', 'kind' => 'light', 'node_id' => 1, 'endpoint' => 1],
    ],
], JSON_UNESCAPED_SLASHES));
$cached = (new YarboHome($rememberedOnly))->localHomeDevices();
if (count($cached['devices']) !== 1 || ($cached['fabric']['source'] ?? '') !== 'cache') {
    fwrite(STDERR, 'cache fallback failed ' . json_encode($cached) . "\n");
    exit(1);
}

$items = $home->paperItems('missing-tablet');
if ($items !== []) {
    fwrite(STDERR, "empty tablet should have no paper items\n");
    exit(1);
}

$locked = sys_get_temp_dir() . '/yarbo-home-locked-' . bin2hex(random_bytes(3));
mkdir($locked . '/data/matter-server', 0775, true);
file_put_contents($locked . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($locked . '/data/home.json', json_encode([
    'names' => [],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
$secret = $locked . '/data/matter-server/aabbcc.json';
file_put_contents($secret, json_encode(['nodes' => ['1' => hue_bridge(4)]], JSON_UNESCAPED_SLASHES));
chmod($secret, 0000);
$blocked = (new YarboHome($locked))->localHomeDevices();
chmod($secret, 0644);
if (($blocked['fabric']['unreadable_files'][0] ?? '') !== 'aabbcc.json') {
    fwrite(STDERR, 'unreadable fabric not reported ' . json_encode($blocked['fabric'] ?? []) . "\n");
    exit(1);
}
if (!str_contains((string) ($blocked['error'] ?? ''), 'cannot read')) {
    fwrite(STDERR, 'missing unreadable hint ' . json_encode($blocked) . "\n");
    exit(1);
}

echo "ok\n";
