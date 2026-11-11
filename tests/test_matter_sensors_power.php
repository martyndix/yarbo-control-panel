<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboMatterFabric;

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
        '1/69/0' => true,
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

$byId = [];
foreach (YarboMatterFabric::flatten([$climate, $contact, $plug]) as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}

$clim = $byId['80:1'] ?? [];
assert_true(($clim['kind'] ?? '') === 'sensor', 'climate kind');
assert_true(($clim['temperature'] ?? null) === 21.4, 'climate temp');
assert_true(($clim['humidity'] ?? null) === 47.5, 'climate humidity');
assert_true(str_contains((string) ($clim['status'] ?? ''), '°'), 'climate status temp');

$cont = $byId['81:1'] ?? [];
assert_true(($cont['kind'] ?? '') === 'sensor', 'contact kind');
assert_true(($cont['has_open'] ?? false) === true, 'contact has_open');
assert_true(($cont['open'] ?? null) === true, 'contact open');
assert_true(YarboHome::isOpenCloseSensor($cont), 'ACCESS treats Matter contact as open/close');
assert_true(YarboHome::contactIsOpen($cont) === true, 'contactIsOpen true');
assert_true(YarboHome::deviceAcceptsCommand('81:1', 'toggle', 'sensor') === false, 'sensors reject toggle');

$plugRow = $byId['82:1'] ?? [];
assert_true(($plugRow['kind'] ?? '') === 'plug', 'plug kind');
assert_true(($plugRow['power_w'] ?? null) === 12.5, 'plug watts from milliwatts');

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
assert_true(!in_array('82:1', $ids, true), 'plug stays off ACCESS');

$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($agent, 'ELECTRICAL_POWER = 0x0090'), 'agent Electrical Power cluster');
assert_true(str_contains($agent, 'BOOLEAN_STATE = 0x0045'), 'agent Boolean State cluster');
assert_true(str_contains($agent, 'HUMIDITY_MEASUREMENT = 0x0405'), 'agent Humidity cluster');
assert_true(str_contains($js, 'home-device-power'), 'Home shows plug watts');
assert_true(str_contains($js, 'homeIsSensorKind(d)'), 'Home renders Matter sensors');
assert_true(str_contains($change, '## [4.0.249]'), 'changelog 4.0.249');

echo "ok: matter sensors + plug power\n";
