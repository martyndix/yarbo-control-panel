<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboUnifi;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-home-doors-tile-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:sensor:s1'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['unifi:door:garage' => 'Garage door'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [
        [
            'id' => 'unifi:door:garage',
            'native_id' => 'garage',
            'name' => 'Garage',
            'kind' => 'door',
            'source' => 'unifi',
            'on' => false,
            'locked' => true,
            'has_dps' => true,
            'dps' => 'open',
            'dps_label' => 'Open',
            'open' => true,
            'gate' => false,
        ],
        [
            'id' => 'unifi:door:side',
            'native_id' => 'side',
            'name' => 'Side',
            'kind' => 'door',
            'source' => 'unifi',
            'on' => true,
            'locked' => false,
            'has_dps' => true,
            'dps' => 'close',
            'dps_label' => 'Closed',
            'open' => false,
            'gate' => false,
        ],
    ],
    'hubs' => [
        [
            'id' => 'unifi:hub:h-garage',
            'native_id' => 'h-garage',
            'door_id' => 'garage',
            'name' => 'Garage hub',
            'kind' => 'hub',
            'source' => 'unifi',
            'on' => false,
            'locked' => true,
            'has_dps' => true,
            'dps' => 'open',
            'dps_label' => 'Open',
            'open' => true,
        ],
        [
            'id' => 'unifi:hub:h-gate',
            'native_id' => 'h-gate',
            'door_id' => 'gate-1',
            'name' => 'Drive gate',
            'kind' => 'hub',
            'source' => 'unifi',
            'on' => true,
            'locked' => false,
            'has_dps' => true,
            'dps' => 'close',
            'dps_label' => 'Closed',
            'open' => false,
            'gate' => true,
        ],
        [
            'id' => 'unifi:hub:unbound',
            'native_id' => 'unbound',
            'door_id' => '',
            'name' => 'Spare hub',
            'kind' => 'hub',
            'source' => 'unifi',
            'on' => false,
        ],
    ],
    'sensors' => [
        [
            'id' => 'unifi:sensor:s1',
            'native_id' => 's1',
            'name' => 'Hall PIR',
            'kind' => 'sensor',
            'source' => 'unifi',
            'on' => false,
            'status' => 'Clear',
        ],
        [
            'id' => 'unifi:sensor:dps-garage',
            'native_id' => 'dps-garage',
            'door_id' => 'garage',
            'name' => 'Garage position',
            'kind' => 'sensor',
            'source' => 'unifi',
            'product' => 'Door position sensor',
            'on' => true,
            'has_dps' => true,
            'dps' => 'open',
            'dps_label' => 'Open',
            'open' => true,
        ],
        [
            'id' => 'unifi:sensor:side-door',
            'native_id' => 'side-door',
            'name' => 'Side Door',
            'kind' => 'sensor',
            'source' => 'unifi',
            'product' => 'UP Sense',
            'on' => true,
            'has_open' => true,
            'open' => true,
            'status' => 'Open',
        ],
        [
            'id' => 'unifi:sensor:rin-r1-0',
            'native_id' => 'rin-r1-0',
            'name' => 'Workshop In 1',
            'kind' => 'sensor',
            'source' => 'unifi',
            'product' => 'USL-Relay input',
            'on' => false,
            'has_open' => true,
            'open' => true,
            'status' => 'Open',
        ],
    ],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));

$unifi = new YarboUnifi($root);
assert_true($unifi->doorsTileEnabled() === true, 'doors tile defaults on when the key is missing');
$view = $unifi->publicView();
assert_true(($view['doors_tile'] ?? null) === true, 'publicView includes doors_tile on');
assert_true(($view['doors_tile_sensors'] ?? true) === false, 'door sensors on the tile default off');

$lockable = $unifi->lockableDoors();
$ids = array_column($lockable, 'id');
assert_true(in_array('unifi:door:garage', $ids, true), 'lockable doors include Access doors not ticked Show on Home');
assert_true(in_array('unifi:door:side', $ids, true), 'lockable doors include every Access door');
assert_true(!in_array('unifi:hub:h-garage', $ids, true), 'hub bound to a listed door is not duplicated on the tile');
assert_true(in_array('unifi:hub:h-gate', $ids, true), 'bound hub with no matching door row still has Unlock/Lock');
assert_true(!in_array('unifi:hub:unbound', $ids, true), 'unbound hub has no unlock button so it stays off the tile');
assert_true(!in_array('unifi:sensor:s1', $ids, true), 'sensors are not lockable doors');

$home = new YarboHome($root);
$dash = $home->dashboard();
assert_true(($dash['doors_tile'] ?? null) === true, 'Home dashboard reports doors_tile on');
$tileIds = array_column($dash['unifi_doors'] ?? [], 'id');
assert_true(in_array('unifi:door:garage', $tileIds, true), 'Home tile lists doors that are not on the room grid');
assert_true(in_array('unifi:door:side', $tileIds, true), 'Home tile lists the second door');
assert_true(in_array('unifi:hub:h-gate', $tileIds, true), 'Home tile lists a bound hub with no door row');
assert_true(!in_array('unifi:hub:h-garage', $tileIds, true), 'Home tile does not duplicate the garage hub');
assert_true(!in_array('unifi:sensor:s1', $tileIds, true), 'Home tile is doors only, not the PIR on Show on Home');
assert_true(!in_array('unifi:sensor:side-door', $tileIds, true), 'door sensors stay off the tile until ticked');
assert_true(($dash['doors_tile_sensors'] ?? true) === false, 'Home dashboard reports door sensors off');
$homeIds = array_column($dash['devices'] ?? [], 'id');
assert_true(!in_array('unifi:door:garage', $homeIds, true), 'unticked door stays off the room grid');
assert_true(in_array('unifi:sensor:s1', $homeIds, true), 'ticked sensor still on the room grid');

$byTile = [];
foreach ($dash['unifi_doors'] as $row) {
    $byTile[$row['id']] = $row;
}
assert_true(($byTile['unifi:door:garage']['name'] ?? '') === 'Garage door', 'tile uses the Home rename');
assert_true(($byTile['unifi:door:garage']['dps_label'] ?? '') === 'Open', 'tile keeps live Open');
assert_true(($byTile['unifi:door:garage']['open'] ?? null) === true, 'tile keeps open boolean');
assert_true(($byTile['unifi:door:side']['dps_label'] ?? '') === 'Closed', 'tile keeps live Closed');
assert_true(($byTile['unifi:hub:h-gate']['gate'] ?? false) === true, 'gate hub keeps gate controls');

assert_true($unifi->setDoorsTile(false), 'can turn the doors tile off');
assert_true($unifi->doorsTileEnabled() === false, 'doors tile off persists');
$off = (new YarboHome($root))->dashboard();
assert_true(($off['doors_tile'] ?? true) === false, 'Home dashboard reports doors_tile off');
assert_true(($off['unifi_doors'] ?? ['x']) === [], 'no door rows while the tile is off');

assert_true($unifi->setDoorsTile(true), 'can turn the doors tile back on');
$onAgain = (new YarboHome($root))->dashboard();
assert_true(($onAgain['doors_tile'] ?? false) === true, 'doors tile on again');
assert_true(in_array('unifi:door:garage', array_column($onAgain['unifi_doors'] ?? [], 'id'), true), 'doors return when the tile is on');

assert_true($unifi->setDoorSensorsTile(true), 'can tick door sensors on the tile');
assert_true($unifi->doorSensorsTileEnabled() === true, 'door sensors tick persists');
$withSensors = (new YarboHome($root))->dashboard();
$sensorIds = array_column($withSensors['unifi_doors'] ?? [], 'id');
assert_true(($withSensors['doors_tile_sensors'] ?? false) === true, 'Home dashboard reports door sensors on');
assert_true(in_array('unifi:sensor:side-door', $sensorIds, true), 'Protect contact sensors appear when the tick is on');
assert_true(in_array('unifi:door:garage', $sensorIds, true), 'lockable doors stay on the tile with sensors');
assert_true(!in_array('unifi:sensor:dps-garage', $sensorIds, true), 'Access position sensor is not duplicated next to Unlock/Lock');
assert_true(!in_array('unifi:sensor:s1', $sensorIds, true), 'PIR sensors stay off the doors tile');
assert_true(!in_array('unifi:sensor:rin-r1-0', $sensorIds, true), 'relay inputs stay off the doors tile');
$side = [];
foreach ($withSensors['unifi_doors'] as $row) {
    if (($row['id'] ?? '') === 'unifi:sensor:side-door') {
        $side = $row;
        break;
    }
}
assert_true(($side['kind'] ?? '') === 'sensor', 'non-controllable row is a sensor');
assert_true(($side['open'] ?? null) === true, 'door sensor keeps live Open');
assert_true(($side['has_open'] ?? false) === true, 'door sensor keeps has_open');

assert_true(YarboUnifi::isDoorSensor([
    'kind' => 'sensor',
    'native_id' => 'dps-x',
    'product' => 'Door position sensor',
]), 'Access DPS is a door sensor');
assert_true(YarboUnifi::isDoorSensor([
    'kind' => 'sensor',
    'native_id' => 'abc',
    'has_open' => true,
    'open' => false,
]), 'Protect contact with has_open is a door sensor');
assert_true(!YarboUnifi::isDoorSensor([
    'kind' => 'sensor',
    'native_id' => 'pir-light1',
    'open' => true,
]), 'floodlight PIR is not a door sensor');
assert_true(!YarboUnifi::isDoorSensor([
    'kind' => 'door',
    'native_id' => 'garage',
    'has_dps' => true,
]), 'a lockable door is not classified as a door sensor');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$html = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
assert_true(str_contains($html, 'id="home-doors-card"'), 'Home markup has the doors card');
assert_true(str_contains($html, 'data-module="home"'), 'doors card is a Home-tab pane');
$vbPos = strpos($html, 'id="vestaboard-card"');
$doorsPos = strpos($html, 'id="home-doors-card"');
$homePos = strpos($html, 'id="home-card"');
assert_true($vbPos !== false && $doorsPos !== false && $vbPos < $doorsPos, 'doors card sits under Vestaboard in the markup');
assert_true($homePos !== false && $doorsPos < $homePos, 'doors card sits above the Home grid');
assert_true(str_contains($html, 'id="home-doors-tile"'), 'Home ⚙️ has the doors-tile tick');
assert_true(str_contains($html, 'id="home-doors-tile-sensors"'), 'Home ⚙️ has the door-sensors tick');
assert_true(str_contains($html, 'id="settings-unifi-doors-tile"'), 'Settings → UniFi has the doors-tile tick');
assert_true(str_contains($html, 'id="settings-unifi-doors-tile-sensors"'), 'Settings → UniFi has the door-sensors tick');
assert_true(str_contains($js, 'function renderHomeDoorsTile'), 'JS renders the compact doors tile');
assert_true(str_contains($js, 'function homeDoorsTileCardHtml'), 'JS builds compact Unlock/Lock rows');
assert_true(str_contains($js, 'function pinHomeDoorsAfterVestaboard'), 'JS keeps the tile under Vestaboard after reorder');
assert_true(str_contains($js, 'unifiDoorLockButtonHtml(d, { home: true })'), 'tile reuses the live Unlock/Lock label');
assert_true(str_contains($js, "unifiDpsMetaHtml(d, 'data-home-dps')"), 'tile shows live Open/Closed');
assert_true(str_contains($js, "action: 'doors_tile'"), 'JS saves the optional tick without Settings → Save');
assert_true(str_contains($js, "action: 'doors_tile_sensors'"), 'JS saves the door-sensors tick without Settings → Save');
assert_true(str_contains($js, "const sensor = String(d.kind || '') === 'sensor'"), 'sensor rows on the tile have no Unlock/Lock');
assert_true(str_contains($js, 'homeUnifiDoorClick'), 'Unlock/Lock on the tile uses the Home UniFi command');
assert_true(str_contains($css, '.home-doors-card'), 'compact doors tile has CSS');
assert_true(str_contains($change, '## [4.0.149]'), 'changelog 4.0.149');
assert_true(str_contains($change, '## [4.0.150]'), 'changelog 4.0.150');

fwrite(STDOUT, "test_home_doors_tile.php ok\n");
