<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboMatterFabric;
use Yarbo\YarboSensorHistory;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$climate = [
    'node_id' => 80,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'VINDSTYRKA',
        '1/29/0' => [['deviceType' => 0x0302, 'revision' => 1]],
        '1/1026/0' => 2140,
        '1/1029/0' => 4750,
    ],
];
$contact = [
    'node_id' => 81,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'PARASOLL',
        '1/29/0' => [['deviceType' => 0x0015, 'revision' => 1]],
        '1/69/0' => false, // Contact Sensor StateValue FALSE = open
    ],
];
$plug = [
    'node_id' => 82,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'INSPELNING',
        '1/29/0' => [['deviceType' => 0x010A, 'revision' => 1]],
        '1/6/0' => true,
        '1/144/8' => 12500,
    ],
];
$grillplats = [
    'node_id' => 83,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'GRILLPLATS',
        '1/29/0' => [['deviceType' => 0x010A, 'revision' => 1]],
        '1/6/0' => true,
        '2/29/0' => [['deviceType' => 0x0510, 'revision' => 1]],
        '2/144/8' => 12500,
        '2/144/11' => 231000,
        '2/144/12' => 55,
    ],
];
$occupancy = [
    'node_id' => 84,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'VALLHORN',
        '1/29/0' => [['deviceType' => 0x0107, 'revision' => 1]],
        '1/1030/0' => 1, // Occupancy Sensing OccupancyBitmap bit0 occupied
    ],
];
$occupancyIdle = [
    'node_id' => 85,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'VALLHORN',
        '1/29/0' => [['deviceType' => 0x0107, 'revision' => 1]],
        '1/1030/0' => 0,
    ],
];

$byId = [];
foreach (YarboMatterFabric::flatten([$climate, $contact, $plug, $grillplats, $occupancy, $occupancyIdle]) as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}

$clim = $byId['80:1'] ?? [];
assert_true(($clim['kind'] ?? '') === 'sensor', 'climate kind');
assert_true(($clim['temperature'] ?? null) === 21.4, 'climate temp');
assert_true(($clim['humidity'] ?? null) === 47.5, 'climate humidity');
assert_true(str_contains((string) ($clim['status'] ?? ''), '°'), 'climate status temp');

$timmer = [
    'node_id' => 90,
    'available' => true,
    'attributes' => [
        '0/40/1' => 'IKEA of Sweden',
        '0/40/3' => 'TIMMERFLOTTE',
        '1/29/0' => [['deviceType' => 0x0302, 'revision' => 1]],
        '1/40/5' => 'Workshop Temperature',
        '1/1026/0' => 2340,
        '2/29/0' => [['deviceType' => 0x0307, 'revision' => 1]],
        '2/40/5' => 'Workshop Humidity',
        '2/1029/0' => 4900,
        '3/29/0' => [['deviceType' => 0x0015, 'revision' => 1]],
        '3/40/5' => 'Workshop Door',
        '3/69/0' => true,
    ],
];
$timmerById = [];
foreach (YarboMatterFabric::flatten([$timmer]) as $row) {
    $timmerById[(string) ($row['id'] ?? '')] = $row;
}
$workshop = $timmerById['90:1'] ?? [];
assert_true(!isset($timmerById['90:2']), 'humidity endpoint is not its own row');
assert_true(($workshop['name'] ?? '') === 'Workshop', 'shared climate name');
assert_true(($workshop['temperature'] ?? null) === 23.4, 'combined temperature');
assert_true(($workshop['humidity'] ?? null) === 49.0, 'combined humidity');
assert_true(str_contains((string) ($workshop['status'] ?? ''), '°'), 'combined status temp');
assert_true(str_contains((string) ($workshop['status'] ?? ''), 'RH'), 'combined status humidity');
assert_true(($workshop['climate_endpoints'] ?? null) === [1, 2], 'climate endpoints');
assert_true(YarboHome::isOpenCloseSensor($workshop) === false, 'climate row stays off open/close');
$door = $timmerById['90:3'] ?? [];
assert_true(($door['has_open'] ?? false) === true, 'door on the same node stays');
assert_true(($door['open'] ?? null) === false, 'door StateValue true is closed');
$overlaid = YarboHome::overlayDeviceStates([
    [
        'id' => '90:1',
        'node_id' => 90,
        'endpoint' => 1,
        'name' => 'Workshop Temperature',
        'kind' => 'sensor',
        'temperature' => 23.4,
    ],
    [
        'id' => '90:2',
        'node_id' => 90,
        'endpoint' => 2,
        'name' => 'Workshop Humidity',
        'kind' => 'sensor',
        'humidity' => 49.0,
    ],
], [$workshop]);
assert_true(count($overlaid) === 1, 'saved split rows collapse to one');
assert_true(($overlaid[0]['id'] ?? '') === '90:1', 'canonical climate id');
assert_true(($overlaid[0]['temperature'] ?? null) === 23.4, 'overlay keeps temperature');
assert_true(($overlaid[0]['humidity'] ?? null) === 49.0, 'overlay copies humidity');
assert_true(($overlaid[0]['name'] ?? '') === 'Workshop', 'overlay uses the shared name');

$cont = $byId['81:1'] ?? [];
assert_true(($cont['kind'] ?? '') === 'sensor', 'contact kind');
assert_true(($cont['has_open'] ?? false) === true, 'contact has_open');
assert_true(($cont['open'] ?? null) === true, 'contact open when StateValue is false');
assert_true(str_contains((string) ($cont['status'] ?? ''), 'Open'), 'contact Open status');
assert_true(YarboHome::isOpenCloseSensor($cont), 'ACCESS treats Matter contact as open/close');
assert_true(YarboHome::contactIsOpen($cont) === true, 'contactIsOpen true');
$closedContact = $contact;
$closedContact['attributes']['1/69/0'] = true;
$closedRow = [];
foreach (YarboMatterFabric::flatten([$closedContact]) as $row) {
    if (($row['id'] ?? '') === '81:1') {
        $closedRow = $row;
    }
}
assert_true(($closedRow['open'] ?? null) === false, 'MYGGBETT StateValue true is closed');
assert_true(str_contains((string) ($closedRow['status'] ?? ''), 'Closed'), 'contact Closed status');
assert_true(YarboHome::contactIsOpen($closedRow) === false, 'contactIsOpen false when closed');
assert_true(YarboHome::deviceAcceptsCommand('81:1', 'toggle', 'sensor') === false, 'sensors reject toggle');

$plugRow = $byId['82:1'] ?? [];
assert_true(($plugRow['kind'] ?? '') === 'plug', 'plug kind');
assert_true(($plugRow['power_w'] ?? null) === 12.5, 'plug watts from milliwatts');
$grill = $byId['83:1'] ?? [];
assert_true(($grill['kind'] ?? '') === 'plug', 'grillplats plug kind');
assert_true(($grill['power_w'] ?? null) === 12.5, 'grillplats watts from sibling');
assert_true(($grill['voltage_v'] ?? null) === 231.0, 'grillplats volts');
assert_true(($grill['current_a'] ?? null) === 0.055, 'grillplats amps');
assert_true(!isset($byId['83:2']), 'electrical sensor endpoint not a Home row');

$occ = $byId['84:1'] ?? [];
assert_true(($occ['kind'] ?? '') === 'sensor', 'occupancy kind');
assert_true(($occ['has_motion'] ?? false) === true, 'occupancy has_motion');
assert_true(($occ['motion'] ?? null) === true, 'occupancy motion true');
assert_true(($occ['on'] ?? null) === true, 'occupancy on follows motion');
assert_true(str_contains((string) ($occ['status'] ?? ''), 'Motion'), 'occupancy Motion status');
assert_true(YarboHome::isOpenCloseSensor($occ) === false, 'occupancy is not open/close');
$occIdle = $byId['85:1'] ?? [];
assert_true(($occIdle['has_motion'] ?? false) === true, 'idle occupancy has_motion');
assert_true(($occIdle['motion'] ?? null) === false, 'idle occupancy motion false');
assert_true(str_contains((string) ($occIdle['status'] ?? ''), 'OK'), 'idle occupancy OK status');

$root = sys_get_temp_dir() . '/yarbo-matter-access-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'hidden' => [],
    'last_devices' => [[
        'id' => '81:1',
        'node_id' => 81,
        'endpoint' => 1,
        'name' => 'Kitchen window',
        'kind' => 'sensor',
        'has_open' => true,
        'open' => true,
        'status' => 'Open',
        'on' => true,
        'available' => true,
    ], [
        'id' => '80:1',
        'node_id' => 80,
        'endpoint' => 1,
        'name' => 'Air quality',
        'kind' => 'sensor',
        'temperature' => 21.4,
        'humidity' => 47.5,
        'status' => '21.4° · 48% RH',
        'on' => false,
        'available' => true,
    ], [
        'id' => '84:1',
        'node_id' => 84,
        'endpoint' => 1,
        'name' => 'Hall motion',
        'kind' => 'sensor',
        'has_motion' => true,
        'motion' => true,
        'motion_at' => time(),
        'status' => 'Motion',
        'on' => true,
        'available' => true,
    ], [
        'id' => '82:1',
        'node_id' => 82,
        'endpoint' => 1,
        'name' => 'Desk plug',
        'kind' => 'plug',
        'power_w' => 12.5,
        'on' => true,
        'available' => true,
    ]],
], JSON_UNESCAPED_SLASHES));

$items = (new YarboHome($root))->paperAccessItems();
$ids = array_map(static fn ($row) => (string) ($row['id'] ?? ''), $items);
assert_true(in_array('81:1', $ids, true), 'Matter contact on ACCESS: ' . json_encode($items));
assert_true(!in_array('80:1', $ids, true), 'climate-only sensor stays off ACCESS');
assert_true(!in_array('84:1', $ids, true), 'motion sensor stays off ACCESS');
assert_true(!in_array('82:1', $ids, true), 'plug stays off ACCESS');

$home = new YarboHome($root);
$auto = $home->automationDevices();
$autoById = [];
foreach ($auto as $row) {
    $autoById[(string) ($row['id'] ?? '')] = $row;
}
$autoOcc = $autoById['84:1'] ?? [];
assert_true(($autoOcc['has_motion'] ?? false) === true, 'automationDevices has_motion');
assert_true(($autoOcc['motion'] ?? null) === true, 'automationDevices motion');
$autoCont = $autoById['81:1'] ?? [];
assert_true(($autoCont['has_open'] ?? false) === true, 'automationDevices contact has_open');

$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($agent, 'ELECTRICAL_POWER = 0x0090'), 'agent Electrical Power cluster');
assert_true(str_contains($agent, 'BOOLEAN_STATE = 0x0045'), 'agent Boolean State cluster');
assert_true(str_contains($agent, 'OCCUPANCY_SENSING = 0x0406'), 'agent Occupancy Sensing cluster');
assert_true(str_contains($agent, 'HUMIDITY_MEASUREMENT = 0x0405'), 'agent Humidity cluster');
assert_true(str_contains($agent, 'AGENT_VERSION = 119'), 'agent v119');

$histRoot = sys_get_temp_dir() . '/yarbo-climate-hist-' . bin2hex(random_bytes(3));
mkdir($histRoot . '/data/sensor-history', 0775, true);
file_put_contents($histRoot . '/data/hub-config.json', json_encode(['modules' => ['home' => true]], JSON_UNESCAPED_SLASHES));
$history = new YarboSensorHistory($histRoot);
file_put_contents($history->pathFor('90:1'), json_encode([
    'id' => '90:1',
    'name' => 'Workshop Temperature',
    'kind' => 'sensor',
    'updated_at' => time(),
    'samples' => [[1000, 23.4, null], [3000, 23.6, null]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($history->pathFor('90:2'), json_encode([
    'id' => '90:2',
    'name' => 'Workshop Humidity',
    'kind' => 'sensor',
    'updated_at' => time(),
    'samples' => [[1010, null, 49], [3010, null, 50]],
], JSON_UNESCAPED_SLASHES));
$series = $history->series('90:1', 1, 5000, '30m');
assert_true(($series['has_humidity'] ?? false) === true, 'one graph includes humidity');
$sawTemp = false;
$sawHum = false;
foreach ($series['points'] ?? [] as $point) {
    if (($point['temperature'] ?? null) !== null && ($point['humidity'] ?? null) !== null) {
        $sawTemp = true;
        $sawHum = true;
    }
}
assert_true($sawTemp && $sawHum, 'split history files draw on one chart');
assert_true(str_contains($js, 'home-device-power'), 'Home shows plug watts');
assert_true(str_contains($js, 'homeIsSensorKind(d)'), 'Home renders Matter sensors');
assert_true(str_contains($js, 'homeMemberHasMotion'), 'Home person emoji uses motion');
assert_true(str_contains($change, '## [4.0.325]'), 'changelog 4.0.325');
assert_true(str_contains($change, '## [4.0.249]'), 'changelog 4.0.249');

echo "ok: matter sensors + plug power\n";
