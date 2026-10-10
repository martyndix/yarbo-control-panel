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
foreach ($devices as $row) {
    if (!empty($row['colorable']) || !empty($row['color_hs']) || !empty($row['color_ct'])) {
        fwrite(STDERR, 'Hue OnOff light must not show colour ' . json_encode($row) . "\n");
        exit(1);
    }
}

$white = [
    'node_id' => 40,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Hue white',
        '1/29/0' => [['deviceType' => 0x0101, 'revision' => 1]],
        '1/6/0' => false,
        '1/8/0' => 80,
        '1/768/0' => 0,
        '1/768/16394' => 0,
    ],
];
$ct = [
    'node_id' => 41,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Hue ambiance',
        '1/29/0' => [['deviceType' => 0x010C, 'revision' => 1]],
        '1/6/0' => false,
        '1/8/0' => 80,
        '1/768/7' => 370,
        '1/768/16394' => 16,
        '1/768/16395' => 153,
        '1/768/16396' => 500,
    ],
];
$rgb = [
    'node_id' => 42,
    'available' => true,
    'attributes' => [
        '0/40/3' => 'Hue color',
        '1/29/0' => [['deviceType' => 0x010D, 'revision' => 1]],
        '1/6/0' => true,
        '1/8/0' => 80,
        '1/768/16394' => 25,
    ],
];
$bySpec = [];
foreach (YarboMatterFabric::flatten([$white, $ct, $rgb]) as $row) {
    $bySpec[$row['id']] = $row;
}
if (!empty($bySpec['40:1']['colorable']) || !empty($bySpec['40:1']['color_ct'])) {
    fwrite(STDERR, 'dimmable white must not show colour ' . json_encode($bySpec['40:1'] ?? null) . "\n");
    exit(1);
}
if (empty($bySpec['41:1']['color_ct']) || !empty($bySpec['41:1']['colorable'])) {
    fwrite(STDERR, 'white ambiance must be CT only ' . json_encode($bySpec['41:1'] ?? null) . "\n");
    exit(1);
}
if (empty($bySpec['42:1']['colorable'])) {
    fwrite(STDERR, 'extended colour must stay colourable ' . json_encode($bySpec['42:1'] ?? null) . "\n");
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
        '1/84/0' => [
            ['label' => 'Idle', 'mode' => 0, 'modeTags' => [['value' => 0x4000]]],
            ['label' => 'Cleaning', 'mode' => 1, 'modeTags' => [['value' => 0x4001]]],
        ],
        '1/84/1' => 0,
        '1/85/0' => [
            ['label' => 'Vacuum', 'mode' => 0, 'modeTags' => [['value' => 0x4000]]],
            ['label' => 'Mop', 'mode' => 1, 'modeTags' => [['value' => 0x4001]]],
        ],
        '1/85/1' => 0,
        '1/97/0' => 0,
        '1/336/0' => [
            ['areaID' => 1, 'locationInfo' => ['locationName' => 'Kitchen']],
            ['areaID' => 2, 'locationInfo' => ['locationName' => 'Hall']],
        ],
        '1/336/2' => [1, 2],
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
$vacNames = array_column($kinds['21:1']['areas'] ?? [], 'name');
if ($vacNames !== ['Kitchen', 'Hall'] || empty($kinds['21:1']['can_mop'])) {
    fwrite(STDERR, 'vacuum rooms ' . json_encode($kinds['21:1'] ?? null) . "\n");
    exit(1);
}
$vacRemember = sys_get_temp_dir() . '/yarbo-vac-cache-' . bin2hex(random_bytes(3));
mkdir($vacRemember . '/data', 0775, true);
file_put_contents($vacRemember . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($vacRemember . '/data/home.json', json_encode([
    'names' => [],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [$kinds['21:1']],
], JSON_UNESCAPED_SLASHES));
$vacCached = (new YarboHome($vacRemember))->localHomeDevices();
$vacRow = $vacCached['devices'][0] ?? [];
$vacCachedNames = array_column($vacRow['areas'] ?? [], 'name');
if (($vacRow['kind'] ?? '') !== 'vacuum' || $vacCachedNames !== ['Kitchen', 'Hall'] || empty($vacRow['can_mop'])) {
    fwrite(STDERR, 'vacuum last_devices rooms ' . json_encode($vacRow) . "\n");
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
$millHeater = [
    'node_id' => 25,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'Mill',
        '0/40/3' => 'Mill Wi-Fi Panel Heater Gen4',
        '1/29/0' => [['deviceType' => 0x0100, 'revision' => 1]],
        '1/513/0' => 2140,
        '1/513/18' => 2100,
        '1/513/28' => 0,
        '1/1026/0' => 2140,
    ],
];
$kinds = [];
foreach (YarboMatterFabric::flatten([$lightTypedHeater, $lightTypedVacuum, $millHeater]) as $row) {
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
if (($kinds['25:1']['kind'] ?? '') !== 'heater' || !empty($kinds['25:1']['colorable']) || !empty($kinds['25:1']['dimmable'])) {
    fwrite(STDERR, 'mill panel heater ' . json_encode($kinds['25:1'] ?? null) . "\n");
    exit(1);
}
if (($kinds['25:1']['on'] ?? true) !== false) {
    fwrite(STDERR, 'mill SystemMode Off must be off ' . json_encode($kinds['25:1']) . "\n");
    exit(1);
}
if (($kinds['25:1']['local_temperature'] ?? null) !== 21.4 || ($kinds['25:1']['heating_setpoint'] ?? null) !== 21.0) {
    fwrite(STDERR, 'mill heater temps ' . json_encode($kinds['25:1']) . "\n");
    exit(1);
}
$millSplit = [
    'node_id' => 26,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'Mill',
        '0/40/3' => 'Mill Wi-Fi Panel Heater Gen4',
        '1/29/0' => [['deviceType' => 0x0100, 'revision' => 1]],
        '1/6/0' => true,
        '2/29/0' => [['deviceType' => 0x0300, 'revision' => 1]],
        '2/513/0' => 2000,
        '2/513/18' => 2200,
        '2/513/28' => 4,
    ],
];
$splitKinds = [];
foreach (YarboMatterFabric::flatten([$millSplit]) as $row) {
    $splitKinds[$row['id']] = $row;
}
if (isset($splitKinds['26:1']) || !isset($splitKinds['26:2'])) {
    fwrite(STDERR, 'mill OnOff sibling must not be a heater row ' . json_encode($splitKinds) . "\n");
    exit(1);
}
if (($splitKinds['26:2']['kind'] ?? '') !== 'heater' || ($splitKinds['26:2']['on'] ?? false) !== true) {
    fwrite(STDERR, 'mill thermostat endpoint ' . json_encode($splitKinds['26:2'] ?? null) . "\n");
    exit(1);
}

$cachedMill = YarboMatterFabric::reclassifyRow([
    'id' => '25:1',
    'name' => 'Mill Wi-Fi Panel Heater Gen4',
    'kind' => 'light',
    'product' => 'Mill Wi-Fi Panel Heater Gen4',
    'vendor' => 'Mill',
    'colorable' => true,
    'dimmable' => true,
]);
if (($cachedMill['kind'] ?? '') !== 'heater' || !empty($cachedMill['colorable']) || !empty($cachedMill['dimmable'])) {
    fwrite(STDERR, 'cached mill heater ' . json_encode($cachedMill) . "\n");
    exit(1);
}

$boilerLight = YarboMatterFabric::reclassifyRow([
    'id' => '31:1',
    'name' => 'Boiler',
    'kind' => 'light',
    'dimmable' => true,
]);
if (($boilerLight['kind'] ?? '') !== 'light') {
    fwrite(STDERR, 'Boiler is a light name, not a heater ' . json_encode($boilerLight) . "\n");
    exit(1);
}
if (YarboMatterFabric::nameLooksHeater('Boiler') || YarboMatterFabric::nameLooksHeater('furnace')) {
    fwrite(STDERR, "Boiler/furnace must not classify as a heater\n");
    exit(1);
}
if (!YarboMatterFabric::nameLooksHeater('Hall heater') || !YarboMatterFabric::nameLooksHeater('Mill Wi-Fi Panel Heater Gen4')) {
    fwrite(STDERR, "heater names must still match\n");
    exit(1);
}
if (!YarboMatterFabric::isUnsupportedCluster('InteractionModelError: UnsupportedCluster (0xc3)')) {
    fwrite(STDERR, "0xc3 must be recognized\n");
    exit(1);
}

echo "ok\n";
