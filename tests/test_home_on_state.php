<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/YarboMatterFabric.php';
require __DIR__ . '/../src/YarboMatterAgentClient.php';
require __DIR__ . '/../src/YarboHub.php';
require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboUnifi.php';
require __DIR__ . '/../src/YarboHome.php';

use Yarbo\YarboHome;
use Yarbo\YarboMatterFabric;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$off = YarboMatterFabric::flatten([[
    'node_id' => 1,
    'available' => true,
    'is_bridge' => true,
    'attributes' => [
        '0/40/1' => 'Signify Netherlands B.V.',
        '0/40/3' => 'Hue Bridge',
        '2/29/0' => [['deviceType' => 0x0013, 'revision' => 1]],
        '2/6/0' => ['value' => false],
        '2/8/0' => 80,
        '2/57/5' => 'Lamp',
        '3/29/0' => [['deviceType' => 0x0013, 'revision' => 1]],
        '3/6/0' => ['0' => 0],
        '3/8/0' => 40,
        '3/57/5' => 'Under Bed',
        '4/29/0' => [['deviceType' => 0x0013, 'revision' => 1]],
        '4/6/0' => true,
        '4/8/0' => 10,
        '4/57/5' => 'Downlight',
    ],
]]);
$byId = [];
foreach ($off as $row) {
    $byId[$row['id']] = $row;
}
assert_true(($byId['1:2']['on'] ?? true) === false, 'wrapped false must be off');
assert_true(($byId['1:3']['on'] ?? true) === false, 'nested 0 must be off');
assert_true(($byId['1:4']['on'] ?? false) === true, 'bool true must stay on');
assert_true(YarboMatterFabric::attrBool(['value' => false]) === false, 'attrBool wrapped false');
assert_true(YarboMatterFabric::attrBool('false') === false, 'attrBool string false');
assert_true(YarboMatterFabric::attrBool(['value' => true]) === true, 'attrBool wrapped true');

$overlaid = YarboHome::overlayDeviceStates(
    [
        ['id' => '1:2', 'name' => 'Lamp', 'on' => true, 'brightness' => 80],
        ['id' => '1:4', 'name' => 'Downlight', 'on' => true, 'brightness' => 10],
    ],
    [
        ['id' => '1:2', 'on' => false, 'brightness' => 80],
    ]
);
assert_true(($overlaid[0]['on'] ?? true) === false, 'overlay must copy off');
assert_true(($overlaid[1]['on'] ?? false) === true, 'overlay must leave unmatched devices');
$kept = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'on' => true]],
    []
);
assert_true(($kept[0]['on'] ?? false) === true, 'empty live state must keep cached On/Off');

$root = sys_get_temp_dir() . '/yarbo-home-on-' . bin2hex(random_bytes(3));
mkdir($root . '/data/matter-server', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['1:2' => 'Lamp', '1:4' => 'Downlight'],
    'room_defs' => [['id' => 'r1', 'name' => 'Master Bedroom']],
    'rooms' => ['1:2' => 'r1', '1:4' => 'r1'],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [
        ['id' => 's1', 'name' => 'Evening', 'actions' => [['id' => '1:2', 'on' => true], ['id' => '1:4', 'on' => true]]],
    ],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
    'active_scene_id' => 's1',
], JSON_UNESCAPED_SLASHES));
file_put_contents(
    $root . '/data/matter-server/aabbcc.json',
    json_encode(['nodes' => ['1' => [
        'node_id' => 1,
        'available' => true,
        'is_bridge' => true,
        'attributes' => [
            '0/40/1' => 'Signify',
            '0/40/3' => 'Hue Bridge',
            '2/29/0' => [['deviceType' => 0x0013]],
            '2/6/0' => false,
            '2/8/0' => 80,
            '2/57/5' => 'Lamp',
            '4/29/0' => [['deviceType' => 0x0013]],
            '4/6/0' => false,
            '4/8/0' => 10,
            '4/57/5' => 'Downlight',
        ],
    ]]], JSON_UNESCAPED_SLASHES)
);

$home = new YarboHome($root);
$dash = $home->dashboard();
$byName = [];
foreach ($dash['devices'] as $row) {
    $byName[$row['name']] = $row;
}
assert_true(($byName['Lamp']['on'] ?? true) === false, 'dashboard lamp should be off');
assert_true(($byName['Downlight']['on'] ?? true) === false, 'dashboard downlight should be off');
assert_true(($dash['rooms'][0]['on'] ?? true) === false, 'room should be off when lights are off');
assert_true(($dash['scenes'][0]['on'] ?? true) === false, 'sticky scene id must not keep scene green');

file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'saved_at' => time(),
    'devices' => [
        ['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false],
        ['id' => '1:4', 'name' => 'Downlight', 'kind' => 'light', 'on' => false],
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents(
    $root . '/data/matter-server/aabbcc.json',
    json_encode(['nodes' => ['1' => [
        'node_id' => 1,
        'available' => true,
        'is_bridge' => true,
        'attributes' => [
            '0/40/1' => 'Signify',
            '0/40/3' => 'Hue Bridge',
            '2/29/0' => [['deviceType' => 0x0013]],
            '2/6/0' => true,
            '2/8/0' => 80,
            '2/57/5' => 'Lamp',
            '4/29/0' => [['deviceType' => 0x0013]],
            '4/6/0' => true,
            '4/8/0' => 10,
            '4/57/5' => 'Downlight',
        ],
    ]]], JSON_UNESCAPED_SLASHES)
);
$cached = $home->localHomeDevices();
$cachedBy = [];
foreach ($cached['devices'] as $row) {
    $cachedBy[$row['id']] = $row;
}
assert_true(($cachedBy['1:2']['on'] ?? true) === false, 'cache off must win over stale disk on');
assert_true(($cachedBy['1:4']['on'] ?? true) === false, 'cache off must win over stale disk on for both lights');

$fabricPath = $root . '/data/matter-server/aabbcc.json';
file_put_contents(
    $fabricPath,
    json_encode(['nodes' => ['1' => [
        'node_id' => 1,
        'available' => true,
        'is_bridge' => true,
        'attributes' => [
            '0/40/1' => 'Signify',
            '0/40/3' => 'Hue Bridge',
            '2/29/0' => [['deviceType' => 0x0013]],
            '2/6/0' => false,
            '2/57/5' => 'Lamp',
            '5/29/0' => [['deviceType' => 0x0013]],
            '5/6/0' => true,
            '5/57/5' => 'Extra',
        ],
    ]]], JSON_UNESCAPED_SLASHES)
);
$fabricMtime = (int) filemtime($fabricPath);
file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'saved_at' => time(),
    'fabric_mtime' => $fabricMtime,
    'devices' => [
        ['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false],
    ],
], JSON_UNESCAPED_SLASHES));
$skipped = $home->localHomeDevices();
$skippedIds = array_column($skipped['devices'], 'id');
assert_true($skipped['fabric']['source'] === 'cache', 'fresh cache should skip re-parsing Hue fabric');
assert_true(!in_array('1:5', $skippedIds, true), 'stale fabric extra light must wait until the fabric file changes');

touch($fabricPath, $fabricMtime + 5);
$refreshed = $home->localHomeDevices();
$refreshedIds = array_column($refreshed['devices'], 'id');
assert_true(in_array('1:5', $refreshedIds, true), 'newer fabric file must add the extra light');

$millRoot = sys_get_temp_dir() . '/yarbo-mill-' . bin2hex(random_bytes(3));
mkdir($millRoot . '/data/matter-server', 0775, true);
file_put_contents($millRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($millRoot . '/data/home.json', json_encode([
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
$millFabric = $millRoot . '/data/matter-server/mill.json';
file_put_contents($millFabric, json_encode(['nodes' => ['25' => [
    'node_id' => 25,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'Mill',
        '0/40/3' => 'Mill Wi-Fi Panel Heater Gen4',
        '1/29/0' => [['deviceType' => 0x0100, 'revision' => 1]],
        '1/6/0' => true,
    ],
]]], JSON_UNESCAPED_SLASHES));
$millHome = new YarboHome($millRoot);
$millDash = $millHome->dashboard();
$millRow = $millDash['devices'][0] ?? [];
assert_true(($millRow['kind'] ?? '') === 'heater', 'mill panel heater must not stay a light ' . json_encode($millRow));
assert_true(empty($millRow['colorable']) && empty($millRow['dimmable']), 'mill heater must not show colour or brightness');

$millMtime = (int) filemtime($millFabric);
file_put_contents($millRoot . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'saved_at' => time(),
    'fabric_mtime' => $millMtime,
    'devices' => [[
        'id' => '25:1',
        'name' => 'Mill Wi-Fi Panel Heater Gen4',
        'product' => 'Mill Wi-Fi Panel Heater Gen4',
        'vendor' => 'Mill',
        'kind' => 'light',
        'on' => true,
        'colorable' => true,
        'dimmable' => true,
    ]],
], JSON_UNESCAPED_SLASHES));
$staleKind = $millHome->dashboard();
$staleRow = $staleKind['devices'][0] ?? [];
assert_true(($staleRow['kind'] ?? '') === 'heater', 'cached mill light must reclassify to heater ' . json_encode($staleRow));
assert_true(empty($staleRow['colorable']), 'cached mill heater must drop colour picker');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(
    !preg_match('/function homeColorInputsHtml[\s\S]{0,500}d\.kind === [\'"]light[\'"]/', $js),
    'colour picker must not treat every light as colourable'
);
assert_true(str_contains($js, 'class="home-kelvin"'), 'colour-temperature slider must be distinct from brightness');

echo "ok\n";
