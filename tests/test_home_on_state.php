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
$colourLive = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'on' => true, 'brightness' => 20, 'color_hex' => '#ff0000']],
    [['id' => '1:2', 'on' => true, 'brightness' => 55, 'color_hex' => '#00ff00', 'color_temp' => 2700]]
);
assert_true(($colourLive[0]['brightness'] ?? 0) === 55, 'overlay must copy live brightness');
assert_true(($colourLive[0]['color_hex'] ?? '') === '#00ff00', 'overlay must copy live colour');
assert_true(($colourLive[0]['color_temp'] ?? 0) === 2700, 'overlay must copy live kelvin');
$keepHex = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'on' => true, 'color_hex' => '#112233']],
    [['id' => '1:2', 'on' => true, 'color_hex' => '']]
);
assert_true(($keepHex[0]['color_hex'] ?? '') === '#112233', 'empty live colour must not wipe the tile');
$heaterLive = YarboHome::overlayDeviceStates(
    [['id' => '25:1', 'name' => 'Heater', 'kind' => 'heater', 'on' => false, 'local_temperature' => 18.0, 'heating_setpoint' => 20.0]],
    [['id' => '25:1', 'on' => true, 'local_temperature' => 21.4, 'heating_setpoint' => 22.0, 'has_thermostat' => true]]
);
assert_true(($heaterLive[0]['on'] ?? false) === true, 'overlay must copy heater on');
assert_true(($heaterLive[0]['local_temperature'] ?? 0) === 21.4, 'overlay must copy room temp');
assert_true(($heaterLive[0]['heating_setpoint'] ?? 0) === 22.0, 'overlay must copy setpoint');
$millSplit = YarboHome::overlayDeviceStates(
    [['id' => '26:1', 'node_id' => 26, 'endpoint' => 1, 'name' => 'Hall', 'kind' => 'heater', 'on' => false]],
    [[
        'id' => '26:2',
        'node_id' => 26,
        'endpoint' => 2,
        'name' => 'Mill Wi-Fi Panel Heater Gen4',
        'kind' => 'heater',
        'on' => false,
        'has_thermostat' => true,
        'local_temperature' => 21.9,
        'heating_setpoint' => 5.0,
    ]]
);
assert_true(count($millSplit) === 1, 'mill split must not keep both OnOff and thermostat rows');
assert_true(($millSplit[0]['id'] ?? '') === '26:2', 'mill split overlay must use the thermostat endpoint');
$extraMill = YarboHome::overlayDeviceStates(
    [['id' => '25:1', 'kind' => 'heater', 'on' => false]],
    [
        ['id' => '25:1', 'kind' => 'heater', 'on' => true, 'has_thermostat' => true],
        ['id' => '28:2', 'node_id' => 28, 'endpoint' => 2, 'kind' => 'heater', 'on' => false, 'has_thermostat' => true],
    ]
);
$extraIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $extraMill);
assert_true(in_array('25:1', $extraIds, true) && in_array('28:2', $extraIds, true), 'overlay must add live mill heaters the cache omitted');
$noHue = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => true]],
    [
        ['id' => '1:2', 'kind' => 'light', 'on' => false],
        ['id' => '1:96', 'name' => 'BSB003 96', 'kind' => 'light', 'on' => true, 'dimmable' => true],
        ['id' => '1:100', 'name' => 'BSB003 100', 'kind' => 'light', 'on' => true, 'dimmable' => true],
    ]
);
$noHueIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $noHue);
assert_true(!in_array('1:96', $noHueIds, true) && !in_array('1:100', $noHueIds, true), 'overlay must not dump leftover Hue endpoints');
$cachedHue = YarboHome::overlayDeviceStates(
    [
        ['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false],
        ['id' => '1:96', 'name' => 'BSB003 96', 'kind' => 'light', 'on' => true],
        ['id' => '1:100', 'name' => 'BSB003 100', 'kind' => 'light', 'on' => true],
    ],
    [['id' => '1:2', 'kind' => 'light', 'on' => true]]
);
$cachedHueIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $cachedHue);
assert_true(in_array('1:2', $cachedHueIds, true), 'overlay keeps the real lamp');
assert_true(!in_array('1:96', $cachedHueIds, true) && !in_array('1:100', $cachedHueIds, true), 'overlay must drop leftover Hue endpoints already in the cache');
assert_true(YarboHome::dropLeftoverHueEndpoints([
    ['id' => '1:2', 'name' => 'Hall', 'kind' => 'light'],
    ['id' => '1:96', 'name' => 'BSB003 96', 'kind' => 'light'],
]) === [['id' => '1:2', 'name' => 'Hall', 'kind' => 'light']], 'drop leftover Hue from a saved list');
assert_true(YarboHome::looksLikeLeftoverHueEndpoint(['id' => '1:96', 'name' => 'BSB003 96']), 'BSB003 96 is a leftover Hue endpoint');
assert_true(!YarboHome::looksLikeLeftoverHueEndpoint(['id' => '1:12', 'name' => 'Hall', 'kind' => 'light']), 'named Hue lights are not leftover');
assert_true(!YarboHome::looksLikeLeftoverHueEndpoint([
    'id' => '25:1',
    'name' => 'Mill Wi-Fi Panel Heater Gen4 1',
    'product' => 'Mill Wi-Fi Panel Heater Gen4',
    'kind' => 'heater',
]), 'unnamed mill heaters are not leftover Hue endpoints');
assert_true(!YarboHome::looksLikeLeftoverHueEndpoint([
    'id' => '41:1',
    'name' => 'Hue ambiance 1',
    'product' => 'Hue ambiance',
    'kind' => 'light',
]), 'unnamed Hue lamps are not leftover Bridge endpoints');
assert_true(YarboHome::looksLikeLeftoverHueEndpoint([
    'id' => '1:96',
    'name' => 'Hue Bridge 96',
    'product' => 'Hue Bridge',
]), 'Hue Bridge 96 is a leftover endpoint');
$newHue = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => true]],
    [
        ['id' => '1:2', 'kind' => 'light', 'on' => false],
        ['id' => '1:12', 'node_id' => 1, 'endpoint' => 12, 'name' => 'Hall', 'kind' => 'light', 'on' => false],
        ['id' => '1:96', 'name' => 'BSB003 96', 'kind' => 'light', 'on' => true, 'dimmable' => true],
        ['id' => '1:100', 'name' => 'BSB003 100', 'kind' => 'light', 'on' => true, 'dimmable' => true],
    ]
);
$newHueIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $newHue);
assert_true(in_array('1:12', $newHueIds, true), 'overlay must add a new named Hue light');
assert_true(!in_array('1:96', $newHueIds, true) && !in_array('1:100', $newHueIds, true), 'overlay still drops leftover Hue endpoints when adding a new light');
$noStale = YarboHome::overlayDeviceStates(
    [['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false]],
    [
        ['id' => '1:2', 'on' => true],
        ['id' => '1:9', 'name' => 'Ghost', 'kind' => 'light', 'on' => true],
    ],
    false
);
$noStaleIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $noStale);
assert_true(count($noStale) === 1 && ($noStale[0]['on'] ?? false) === true, 'disk overlay copies live On/Off');
assert_true(!in_array('1:9', $noStaleIds, true), 'disk overlay must not keep stale remembered ids');
assert_true(YarboHome::lastDevicesTopologyKey([
    ['id' => '1:2', 'kind' => 'light', 'node_id' => 1, 'endpoint' => 2, 'on' => true],
]) === YarboHome::lastDevicesTopologyKey([
    ['id' => '1:2', 'kind' => 'light', 'node_id' => 1, 'endpoint' => 2, 'on' => false],
]), 'topology key ignores On/Off');

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
$hideLamp = $home->hideDevice('1:2', true);
assert_true(($hideLamp['ok'] ?? false) === true, 'hide lamp');
$afterHide = $home->dashboard();
$afterHideIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $afterHide['devices'] ?? []);
$afterHiddenIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $afterHide['hidden_devices'] ?? []);
assert_true(!in_array('1:2', $afterHideIds, true), 'hidden lamp must leave the Home grid');
assert_true(in_array('1:2', $afterHiddenIds, true), 'hidden lamp must appear under Hidden');
$unhideLamp = $home->hideDevice('1:2', false);
assert_true(($unhideLamp['ok'] ?? false) === true, 'unhide lamp');
$afterShow = $home->dashboard();
$afterShowIds = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $afterShow['devices'] ?? []);
assert_true(in_array('1:2', $afterShowIds, true), 'unhidden lamp must return to Home');

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
    'disk_ids' => ['1:2'],
    'devices' => [
        ['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false],
        ['id' => '1:96', 'name' => 'BSB003 96', 'kind' => 'light', 'on' => true],
        ['id' => '1:9', 'name' => 'Ghost', 'kind' => 'light', 'on' => true],
    ],
], JSON_UNESCAPED_SLASHES));
$skipped = $home->localHomeDevices();
$skippedIds = array_column($skipped['devices'], 'id');
assert_true($skipped['fabric']['source'] === 'cache', 'fresh cache should skip re-parsing Hue fabric');
assert_true(!in_array('1:5', $skippedIds, true), 'stale fabric extra light must wait until the fabric file changes');
assert_true(!in_array('1:96', $skippedIds, true), 'fresh cache must drop leftover Hue endpoints');
assert_true(!in_array('1:9', $skippedIds, true), 'fresh cache must drop remembered ids that are not on the fabric');

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
        '1/513/0' => 2140,
        '1/513/18' => 2100,
        '1/513/28' => 0,
        '1/1026/0' => 2140,
    ],
]]], JSON_UNESCAPED_SLASHES));
$millHome = new YarboHome($millRoot);
$millDash = $millHome->dashboard();
$millRow = $millDash['devices'][0] ?? [];
assert_true(($millRow['kind'] ?? '') === 'heater', 'mill panel heater must not stay a light ' . json_encode($millRow));
assert_true(empty($millRow['colorable']) && empty($millRow['dimmable']), 'mill heater must not show colour or brightness');
assert_true(($millRow['on'] ?? true) === false, 'mill SystemMode Off must show as off');
assert_true(($millRow['local_temperature'] ?? null) === 21.4, 'mill must show room temperature ' . json_encode($millRow));
assert_true(($millRow['heating_setpoint'] ?? null) === 21.0, 'mill must show heating setpoint ' . json_encode($millRow));

$millMtime = (int) filemtime($millFabric);
file_put_contents($millRoot . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'saved_at' => time(),
    'fabric_mtime' => $millMtime,
    'disk_ids' => ['25:1'],
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

$unifiHomeRoot = sys_get_temp_dir() . '/yarbo-home-unifi-' . bin2hex(random_bytes(3));
mkdir($unifiHomeRoot . '/data', 0775, true);
file_put_contents($unifiHomeRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($unifiHomeRoot . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:hub:h1', 'unifi:sensor:s1'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($unifiHomeRoot . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [[
        'id' => 'unifi:hub:h1',
        'native_id' => 'h1',
        'door_id' => 'door1',
        'name' => 'UA Hub Door Mini 2076',
        'kind' => 'hub',
        'source' => 'unifi',
        'product' => 'UA-Hub-Door-Mini',
        'on' => false,
        'status' => 'Online · Locked · Closed',
        'has_dps' => true,
        'dps' => 'close',
        'dps_label' => 'Closed',
        'open' => false,
        'locked' => true,
        'gate' => false,
    ]],
    'sensors' => [[
        'id' => 'unifi:sensor:s1',
        'native_id' => 's1',
        'name' => 'Toilet Downstairs',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'UP Sense',
        'on' => false,
        'status' => '21.4° · 48% RH',
        'temperature' => 21.4,
        'humidity' => 48,
        'open' => false,
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));
$homeUnifi = new YarboHome($unifiHomeRoot);
$homeDash = $homeUnifi->dashboard();
$byHomeId = [];
foreach ($homeDash['devices'] ?? [] as $row) {
    if (is_array($row) && isset($row['id'])) {
        $byHomeId[$row['id']] = $row;
    }
}
assert_true(isset($byHomeId['unifi:hub:h1']), 'Home must include the Access controller ' . json_encode($homeDash['devices'] ?? []));
assert_true(($byHomeId['unifi:hub:h1']['dps_label'] ?? '') === 'Closed', 'Home controller must keep Open/Closed ' . json_encode($byHomeId['unifi:hub:h1'] ?? []));
assert_true(($byHomeId['unifi:hub:h1']['has_dps'] ?? false) === true, 'Home controller must keep has_dps');
assert_true(isset($byHomeId['unifi:sensor:s1']), 'Home must include the UniFi sensor');
assert_true(($byHomeId['unifi:sensor:s1']['status'] ?? '') === '21.4° · 48% RH', 'Home sensor must keep temperature status');
assert_true((float) ($byHomeId['unifi:sensor:s1']['humidity'] ?? 0) === 48.0, 'Home sensor must keep humidity');

assert_true(YarboHome::countsTowardRoomOn(['kind' => 'light', 'on' => true]) === true, 'lights count toward room on');
assert_true(YarboHome::countsTowardRoomOn(['kind' => 'heater', 'on' => true]) === true, 'heaters count toward room on');
assert_true(YarboHome::countsTowardRoomOn(['kind' => 'camera', 'on' => true]) === false, 'cameras must not count toward room on');

$camRoomRoot = sys_get_temp_dir() . '/yarbo-home-cam-room-' . bin2hex(random_bytes(3));
mkdir($camRoomRoot . '/data', 0775, true);
file_put_contents($camRoomRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($camRoomRoot . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:camera:cam-drive', 'unifi:camera:cam-garage', 'unifi:light:boiler'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($camRoomRoot . '/data/home.json', json_encode([
    'names' => [],
    'room_defs' => [
        ['id' => 'drive', 'name' => 'Driveway'],
        ['id' => 'garage', 'name' => 'Garage'],
    ],
    'rooms' => [
        'unifi:camera:cam-drive' => 'drive',
        'unifi:camera:cam-garage' => 'garage',
        'unifi:light:boiler' => 'garage',
    ],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($camRoomRoot . '/data/unifi-inventory.json', json_encode([
    'cameras' => [
        [
            'id' => 'unifi:camera:cam-drive',
            'native_id' => 'cam-drive',
            'name' => 'Driveway',
            'kind' => 'camera',
            'source' => 'unifi',
            'on' => true,
            'available' => true,
            'status' => 'Online',
        ],
        [
            'id' => 'unifi:camera:cam-garage',
            'native_id' => 'cam-garage',
            'name' => 'Garage Outside',
            'kind' => 'camera',
            'source' => 'unifi',
            'on' => true,
            'available' => true,
            'status' => 'Online',
        ],
    ],
    'lights' => [[
        'id' => 'unifi:light:boiler',
        'native_id' => 'boiler',
        'name' => 'Boiler',
        'kind' => 'light',
        'source' => 'unifi',
        'on' => true,
        'status' => 'On',
    ]],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => [],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));
$camHome = new YarboHome($camRoomRoot);
$camDash = $camHome->dashboard();
$camById = [];
foreach ($camDash['devices'] ?? [] as $row) {
    if (is_array($row) && isset($row['id'])) {
        $camById[$row['id']] = $row;
    }
}
$camRooms = [];
foreach ($camDash['rooms'] ?? [] as $row) {
    if (is_array($row) && isset($row['id'])) {
        $camRooms[$row['id']] = $row;
    }
}
assert_true(isset($camById['unifi:camera:cam-drive']), 'camera-only room still lists the camera');
assert_true(empty($camById['unifi:camera:cam-drive']['on']), 'stale camera on must not stay on');
assert_true(empty($camById['unifi:camera:cam-garage']['on']), 'garage camera must not look on');
assert_true(($camById['unifi:light:boiler']['on'] ?? false) === true, 'garage light stays on');
assert_true(($camRooms['drive']['on'] ?? true) === false, 'camera-only room must not go green');
assert_true(($camRooms['garage']['on'] ?? false) === true, 'room with a light on stays green');

$sceneRelayRoot = sys_get_temp_dir() . '/yarbo-scene-relay-' . bin2hex(random_bytes(3));
mkdir($sceneRelayRoot . '/data', 0775, true);
file_put_contents($sceneRelayRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($sceneRelayRoot . '/data/home.json', json_encode([
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
$sceneRelayHome = new YarboHome($sceneRelayRoot);
$sceneRelaySaved = $sceneRelayHome->saveScene([
    'name' => 'Patio',
    'actions' => [
        [
            'id' => 'unifi:relay:usl1:0',
            'on' => true,
            'brightness' => 80,
            'color_hex' => '#ff0000',
            'color_temp' => 2700,
        ],
        ['id' => 'unifi:relay:usl1:1', 'on' => false],
        ['id' => 'unifi:light:flood1', 'on' => true, 'brightness' => 40],
    ],
]);
assert_true(($sceneRelaySaved['ok'] ?? false) === true, 'scene can save UniFi relay outputs');
$sceneRelayActions = $sceneRelaySaved['scene']['actions'] ?? [];
$sceneRelayById = [];
foreach ($sceneRelayActions as $row) {
    if (is_array($row) && isset($row['id'])) {
        $sceneRelayById[$row['id']] = $row;
    }
}
$out0 = $sceneRelayById['unifi:relay:usl1:0'] ?? [];
$out1 = $sceneRelayById['unifi:relay:usl1:1'] ?? [];
$flood = $sceneRelayById['unifi:light:flood1'] ?? [];
assert_true(($out0['on'] ?? false) === true, 'relay output stays on in the scene');
assert_true(($out1['on'] ?? true) === false, 'relay output can be off in the scene');
assert_true(($flood['on'] ?? false) === true, 'UniFi floodlight can join a scene');
assert_true(($out0['brightness'] ?? null) === null, 'relay scene members are on/off, not dimmable');
assert_true(($out0['color_hex'] ?? null) === null, 'relay scene members have no colour');
assert_true(($flood['brightness'] ?? null) === null, 'UniFi lights in scenes are on/off only');
$sceneRelayRun = $sceneRelayHome->runScene((string) ($sceneRelaySaved['scene']['id'] ?? ''));
assert_true(($sceneRelayRun['ok'] ?? true) === false, 'scene run without Protect key fails on the UniFi command');
assert_true(
    str_contains((string) ($sceneRelayRun['error'] ?? ''), 'Protect API key')
        || str_contains((string) ($sceneRelayRun['error'] ?? ''), 'Protect'),
    'scene run sends UniFi on/off, not Matter: ' . json_encode($sceneRelayRun)
);
$sceneRelayStop = $sceneRelayHome->stopScene((string) ($sceneRelaySaved['scene']['id'] ?? ''));
assert_true(($sceneRelayStop['ok'] ?? true) === false, 'scene stop without Protect key fails on the UniFi command');
assert_true(
    str_contains((string) ($sceneRelayStop['error'] ?? ''), 'Protect API key')
        || str_contains((string) ($sceneRelayStop['error'] ?? ''), 'Protect'),
    'scene stop sends UniFi off: ' . json_encode($sceneRelayStop)
);

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(
    !preg_match('/function homeColorInputsHtml[\s\S]{0,500}d\.kind === [\'"]light[\'"]/', $js),
    'colour picker must not treat every light as colourable'
);
assert_true(str_contains($js, 'class="home-kelvin"'), 'colour-temperature slider must be distinct from brightness');
assert_true(str_contains($js, 'data-home-kelvin'), 'Home poll must be able to patch the kelvin slider');
assert_true(str_contains($js, 'if (extras.color_temp != null)'), 'Apple Home colour temperature must update the tile');
assert_true(str_contains($js, 'data-home-dps'), 'Home door controller must show Open/Closed next to Unlock/Lock');
assert_true(str_contains($js, 'unifiDpsMetaHtml'), 'UniFi door position belongs in the actions box');
assert_true(str_contains($js, 'function unifiDoorHasPosition'), 'Lock label is only for controllers with a door-position sensor');
assert_true(str_contains($js, "return unifiDoorIsOpen(d) ? 'Lock' : 'Unlock'"), 'Open doors with DPS rename Unlock to Lock');
assert_true(str_contains($js, 'data-unifi-cmd="unlock"'), 'Lock is a label; the click still sends unlock');
assert_true(!str_contains($js, 'function unifiDoorLockCommand'), 'door tiles must not send a lock command');
assert_true(str_contains($js, 'patchUnifiDoorLockButton'), 'Home must rename Unlock to Lock when Open/Closed patches');
assert_true(str_contains($js, 'unifiDoorLockButtonHtml(d, { home: true })'), 'Home door actions must use the live Unlock/Lock label');
assert_true(str_contains($js, "if (!heater) {\n        setHomeDeviceOn(id, nextOn);"), 'heater On must wait for Matter before showing On');
assert_true(str_contains($js, "kind: device?.kind || ''"), 'Home On/Off command must send device kind');
assert_true(str_contains($js, 'celsius: Number(device.heating_setpoint)'), 'heater On sends the current heating temperature');
assert_true(str_contains($js, "mode: 'heat'"), 'heater On sends Heat mode, not Auto');
assert_true(str_contains($js, "kind: device?.kind || 'heater'"), 'Home heater setpoint must send heater kind');
$homePhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');
assert_true(str_contains($homePhp, "\$body['kind'] = \$kind"), 'PHP Home command must pass kind to the Matter agent');
assert_true(str_contains($homePhp, "\$input['kind'] ?? \$input['device_kind']"), 'PHP Home command reads kind from the page');
assert_true(str_contains($homePhp, 'cachedDeviceSetpoint'), 'heater On uses the last heating temperature');
assert_true(str_contains($homePhp, '? 80.0 : 60.0'), 'heater Matter writes get a longer PHP wait');
assert_true(str_contains($homePhp, "['op' => 'states'], 2.5, false)"), 'live mill list waits long enough for states');
assert_true(str_contains($homePhp, 'unionDeviceLists'), 'pairing merges the new node into last_devices');
assert_true(str_contains($homePhp, 'function scanDevices'), 'Look for devices re-interviews the Hue Bridge');
assert_true(str_contains($homePhp, 'looksLikeLeftoverHueEndpoint'), 'new Hue lights skip leftover Bridge endpoints');
assert_true(str_contains($homePhp, 'function countsTowardRoomOn'), 'rooms ignore cameras when choosing green');
assert_true(str_contains($homePhp, "if (self::countsTowardRoomOn(\$device) && !empty(\$device['on']))"), 'room on only from switchable devices');
assert_true(str_contains($js, "btn.textContent = 'Adding…'"), 'Add device shows Adding while pairing');
assert_true(str_contains($js, "action: 'scan'"), 'Look for devices calls scan');
assert_true(str_contains($js, 'home-scene-filter'), 'scene editor search');
assert_true(str_contains($js, "homeSceneListMode === 'in'"), 'scene editor In scene tab');
assert_true(str_contains($js, 'function homeSceneEligible'), 'scene editor skips vacuums and cameras');
assert_true(
    (bool) preg_match(
        '/function homeSceneEligible\(d\) \{[\s\S]{0,400}return kind === \'light\' \|\| kind === \'relay\';/',
        $js
    ),
    'scene editor includes UniFi lights and relay outputs'
);
assert_true(
    !preg_match('/function homeSceneEligible\(d\) \{\s*if \(!d \|\| homeIsUnifi\(d\)\) return false/', $js),
    'scene editor must not skip every UniFi device'
);
assert_true(str_contains($js, 'Tick lights and relays this scene should change'), 'scene hint mentions relays');
assert_true(str_contains($homePhp, "empty(\$action['on']) ? 'off' : 'on'"), 'scene run sends UniFi relays on/off');
assert_true(str_contains($js, 'on: onEl ? Boolean(onEl.checked) : prev.on'), 'ticking a compact scene row keeps the live on state');
assert_true(str_contains($js, 'loadHomeDashboard({ force: true })'), 'pairing reloads Home even if a poll is in flight');
assert_true(str_contains($js, 'applyHomeHiddenLocal(id, true)'), 'Hide must move the tile without a full page refresh');
assert_true(str_contains($js, 'await reloadHomeList()'), 'Hide must force-reload Home');
assert_true(str_contains($js, 'const lit = on && !camera'), 'camera cards must not render as green on');
assert_true(str_contains($js, "Boolean(on) && !card.classList.contains('home-device--camera')"), 'camera patch must not add is-on');

$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(
    str_contains($css, '.home-device-actions .home-device-meta'),
    'sensor-style meta next to Unlock must be visible in the actions box'
);
assert_true(str_contains($css, 'grid-area: label'), 'manage name box sits in its own grid area');
assert_true(str_contains($css, 'handle label label label manage'), 'manage name row is not shared with heater controls');
assert_true(str_contains($css, 'flex: 1 1 8rem'), 'manage name input can grow once it has a row');
assert_true(str_contains($css, '.home-device--camera.is-on'), 'camera is-on must reset to the neutral row');
assert_true(str_contains($css, '.home-scene-filter'), 'scene editor search field');
assert_true(str_contains($css, 'max-height: min(28rem, 60vh)'), 'scene member list scrolls instead of growing');
assert_true(str_contains($css, '.home-scene-member:not(.is-in)'), 'unticked scene rows stay compact');

echo "ok\n";
