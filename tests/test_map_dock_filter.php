<?php

declare(strict_types=1);

/**
 * allchargingData holds real docks and zone-linked recharge approach points.
 * Only physical docks (hasChargingStation / named) should become map markers.
 */

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboMap;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
}

$ref = ['latitude' => 51.5, 'longitude' => -0.12];

// Zone-id approach points (the spurious "5" / "1" / "3" docks).
$spurious = [
    [
        'id' => 5,
        'name' => '',
        'hasChargingStation' => 0,
        'ref' => $ref,
        'chargingPoint' => ['x' => 10.0, 'y' => 2.0],
    ],
    [
        'id' => 1,
        'name' => '1',
        'hasChargingStation' => false,
        'ref' => $ref,
        'chargingPoint' => ['x' => -4.0, 'y' => 8.0],
    ],
    [
        'id' => 3,
        'ref' => $ref,
        'chargingPoint' => ['x' => 1.0, 'y' => -6.0],
    ],
];

foreach ($spurious as $i => $station) {
    assert_true(
        !YarboMap::isMapDockStation($station),
        "spurious station {$i} must not count as a dock",
    );
}

$real = [
    'id' => 9,
    'name' => 'Dock',
    'hasChargingStation' => true,
    'ref' => $ref,
    'chargingPoint' => ['x' => 0.5, 'y' => -0.2],
];
assert_true(YarboMap::isMapDockStation($real), 'named pad with flag must be a dock');

$legacyNamed = [
    'id' => 2,
    'name' => 'Charging Station',
    'ref' => $ref,
    'chargingPoint' => ['x' => 0.0, 'y' => 0.0],
];
assert_true(YarboMap::isMapDockStation($legacyNamed), 'legacy named station without flag must keep');

$map = [
    'ref' => $ref,
    'areas' => [
        [
            'id' => 'area-1',
            'name' => 'Lawn',
            'ref' => $ref,
            'range' => [
                ['x' => 0, 'y' => 0],
                ['x' => 10, 'y' => 0],
                ['x' => 10, 'y' => 8],
                ['x' => 0, 'y' => 8],
            ],
        ],
    ],
    // Primary active pose (SDK often sets hasChargingStation 0 here).
    'chargingData' => [
        'id' => 1,
        'enable' => true,
        'hasChargingStation' => 0,
        'ref' => $ref,
        'chargingPoint' => ['x' => 0.0, 'y' => 0.0],
        'startPoint' => ['x' => 1.0, 'y' => 0.0],
    ],
    'allchargingData' => array_merge($spurious, [$real]),
];

$norm = YarboMap::normalize(['get_map' => ['data' => $map]], null);
assert_true(($norm['status'] ?? '') === 'ready', 'map with docks must be ready');

$charging = array_values(array_filter(
    $norm['feature_collection']['features'] ?? [],
    static fn ($f) => is_array($f) && ($f['properties']['zone_type'] ?? '') === 'charging',
));

assert_true(count($charging) === 2, 'expect primary chargingData + one real allcharging dock, got ' . count($charging));

$names = array_map(
    static fn ($f) => (string) ($f['properties']['name'] ?? ''),
    $charging,
);
assert_true(in_array('Dock', $names, true), 'real dock name must survive: ' . json_encode($names));
foreach (['5', '1', '3'] as $bad) {
    assert_true(!in_array($bad, $names, true), "spurious dock name {$bad} must not appear");
}

// allchargingData-only maps (groundskeeper-style) still show the real pad.
$onlyAll = [
    'ref' => $ref,
    'allchargingData' => [$real],
];
$onlyNorm = YarboMap::normalize(['get_map' => ['data' => $onlyAll]], null);
$onlyCharge = array_values(array_filter(
    $onlyNorm['feature_collection']['features'] ?? [],
    static fn ($f) => is_array($f) && ($f['properties']['zone_type'] ?? '') === 'charging',
));
assert_true(count($onlyCharge) === 1, 'allchargingData-only real dock must draw');
assert_true(($onlyCharge[0]['properties']['name'] ?? '') === 'Dock', 'allchargingData-only name');

// Fixture sample still draws its chargingData dock.
$sample = json_decode((string) file_get_contents(__DIR__ . '/fixtures/map_app_sample.json'), true);
assert_true(is_array($sample), 'sample fixture');
$sampleNorm = YarboMap::normalize(['get_map' => ['data' => $sample]], null);
$sampleCharge = array_values(array_filter(
    $sampleNorm['feature_collection']['features'] ?? [],
    static fn ($f) => is_array($f) && ($f['properties']['zone_type'] ?? '') === 'charging',
));
assert_true(count($sampleCharge) === 1, 'sample fixture must keep one dock');
assert_true(($sampleCharge[0]['properties']['name'] ?? '') === 'Dock', 'sample dock name');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.287]'), 'changelog 4.0.287');

echo "map dock filter ok\n";
