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

$bare = YarboMatterFabric::flatten(YarboMatterFabric::nodesFromResult(['1' => hue_bridge(8), 'last_node_id' => 1]));
if (count($bare) !== 8) {
    fwrite(STDERR, 'bare fabric map flattened to ' . count($bare) . "\n");
    exit(1);
}

$metaRoot = sys_get_temp_dir() . '/yarbo-home-meta-' . bin2hex(random_bytes(3));
mkdir($metaRoot . '/data', 0775, true);
file_put_contents($metaRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($metaRoot . '/data/home.json', json_encode([
    'names' => ['1:10' => 'Porch', '1:11' => 'Garden'],
    'room_defs' => [],
    'rooms' => ['1:10' => 'r1'],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [
        ['id' => 's1', 'name' => 'Outdoor Lights', 'actions' => [['id' => '1:10', 'on' => true], ['id' => '1:12', 'on' => true]]],
    ],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
$meta = (new YarboHome($metaRoot))->localHomeDevices();
$metaIds = array_column($meta['devices'], 'id');
sort($metaIds);
if ($metaIds !== ['1:10', '1:11', '1:12'] || ($meta['fabric']['source'] ?? '') !== 'meta') {
    fwrite(STDERR, 'meta fallback failed ' . json_encode($meta) . "\n");
    exit(1);
}

$stubRoot = sys_get_temp_dir() . '/yarbo-home-stub-' . bin2hex(random_bytes(3));
mkdir($stubRoot . '/data/matter-server', 0775, true);
file_put_contents($stubRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($stubRoot . '/data/home.json', json_encode([
    'names' => ['13:2' => 'Landing', '13:3' => 'Hall'],
    'room_defs' => [['id' => 'r1', 'name' => 'Landing']],
    'rooms' => ['13:2' => 'r1'],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '13:1', 'name' => 'Matter node 13', 'kind' => 'light', 'node_id' => 13, 'endpoint' => 1],
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents(
    $stubRoot . '/data/matter-server/aabbcc.json',
    json_encode(['nodes' => ['13' => ['node_id' => 13, 'available' => false, 'attributes' => []]]], JSON_UNESCAPED_SLASHES)
);
$stubbed = (new YarboHome($stubRoot))->localHomeDevices();
$stubIds = array_column($stubbed['devices'], 'id');
sort($stubIds);
if ($stubIds !== ['13:2', '13:3']) {
    fwrite(STDERR, 'stub node hid saved lights ' . json_encode($stubbed) . "\n");
    exit(1);
}

$heaterNode = [
    'node_id' => 20,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Hall heater',
        '1/29/0' => [['0' => 0x0300, '1' => 1]],
        '1/6/0' => true,
        '1/8/0' => 80,
        '1/513/0' => 2100,
    ],
];
$vacuumNode = [
    'node_id' => 21,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Upstairs vacuum',
        '1/29/0' => [['deviceType' => 0x0074, 'revision' => 1]],
        '1/6/0' => false,
        '1/8/0' => 10,
        '1/84/0' => 1,
    ],
];
$bridgedHeater = [
    'node_id' => 22,
    'available' => true,
    'is_bridge' => true,
    'attributes' => [
        '0/40/3' => 'Hue Bridge',
        '2/29/0' => [['deviceType' => 0x0013, 'revision' => 1]],
        '2/6/0' => true,
        '2/8/0' => 40,
        '2/513/0' => 2000,
        '2/57/5' => 'Bathroom heater',
    ],
];
$kinds = [];
foreach (YarboMatterFabric::flatten([$heaterNode, $vacuumNode, $bridgedHeater]) as $row) {
    $kinds[$row['id']] = $row;
}
if (($kinds['20:1']['kind'] ?? '') !== 'heater' || !empty($kinds['20:1']['colorable']) || !empty($kinds['20:1']['dimmable'])) {
    fwrite(STDERR, 'heater ' . json_encode($kinds['20:1'] ?? null) . "\n");
    exit(1);
}
if (($kinds['21:1']['kind'] ?? '') !== 'vacuum' || !empty($kinds['21:1']['colorable'])) {
    fwrite(STDERR, 'vacuum ' . json_encode($kinds['21:1'] ?? null) . "\n");
    exit(1);
}
if (($kinds['22:2']['kind'] ?? '') !== 'heater') {
    fwrite(STDERR, 'bridged heater ' . json_encode($kinds['22:2'] ?? null) . "\n");
    exit(1);
}

$lightTypedHeater = [
    'node_id' => 23,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Towel rail',
        '1/29/0' => [['deviceType' => 0x0101, 'revision' => 1]],
        '1/6/0' => true,
        '1/8/0' => 200,
        '1/768/3' => 200,
        '1/513/0' => 2100,
    ],
];
$lightTypedVacuum = [
    'node_id' => 24,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Robot vac',
        '1/29/0' => [['deviceType' => 0x0100, 'revision' => 1]],
        '1/6/0' => false,
        '1/8/0' => 40,
        '1/84/0' => 1,
        '1/97/0' => 1,
    ],
];
$kinds = [];
foreach (YarboMatterFabric::flatten([$lightTypedHeater, $lightTypedVacuum]) as $row) {
    $kinds[$row['id']] = $row;
}
if (($kinds['23:1']['kind'] ?? '') !== 'heater' || !empty($kinds['23:1']['colorable']) || !empty($kinds['23:1']['dimmable'])) {
    fwrite(STDERR, 'light-typed heater ' . json_encode($kinds['23:1'] ?? null) . "\n");
    exit(1);
}
if (($kinds['24:1']['kind'] ?? '') !== 'vacuum' || !empty($kinds['24:1']['colorable']) || !empty($kinds['24:1']['dimmable'])) {
    fwrite(STDERR, 'light-typed vacuum ' . json_encode($kinds['24:1'] ?? null) . "\n");
    exit(1);
}

echo "ok\n";
