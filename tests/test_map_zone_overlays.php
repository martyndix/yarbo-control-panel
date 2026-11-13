<?php

declare(strict_types=1);

/**
 * Zones must convert local metres via a GPS origin — never treat {x,y} as lon/lat.
 * Cloud map load often lacks per-zone ref; read_gps_ref / map-level ref must still draw.
 */

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboGeo;
use Yarbo\YarboMap;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
}

$range = [
    ['x' => 5.2, 'y' => -3.1],
    ['x' => 12.0, 'y' => -3.1],
    ['x' => 12.0, 'y' => 4.5],
    ['x' => 5.2, 'y' => 4.5],
];

// 1) No refs at all → must NOT invent equator polygons from metres.
$bare = ['areas' => [['id' => 'a1', 'name' => 'Front', 'range' => $range]]];
$bareNorm = YarboMap::normalize(['get_map' => ['data' => $bare]], null);
assert_true(($bareNorm['status'] ?? '') !== 'ready', 'bare local metres must not become ready GeoJSON');
assert_true(($bareNorm['feature_collection']['features'] ?? []) === [], 'bare local metres must yield zero features');

// 2) read_gps_ref fallback must produce named app zones near the origin.
$gps = ['data' => ['ref' => ['latitude' => 59.33, 'longitude' => 18.06]]];
$withRef = YarboMap::normalize(
    ['get_map' => ['data' => $bare], 'read_gps_ref' => $gps],
    $gps,
);
assert_true(($withRef['status'] ?? '') === 'ready', 'read_gps_ref must unlock app zones');
$feat = $withRef['feature_collection']['features'][0] ?? null;
assert_true(is_array($feat), 'expected one feature with gps ref');
assert_true(($feat['properties']['name'] ?? null) === 'Front', 'zone name must survive app extract');
assert_true(($feat['properties']['zone_type'] ?? null) === 'clean', 'zone_type must be clean');
$ring = $feat['geometry']['coordinates'][0] ?? [];
$lon = (float) ($ring[0][0] ?? 0);
$lat = (float) ($ring[0][1] ?? 0);
assert_true(abs($lat - 59.33) < 0.01 && abs($lon - 18.06) < 0.01, 'polygon must sit near gps ref, not metres-as-degrees');

// 3) Top-level map ref (no per-zone ref) must work without walkNode.
$top = [
    'ref' => ['latitude' => 51.5, 'longitude' => -0.12],
    'areas' => [['id' => 'a1', 'name' => 'Lawn', 'range' => $range]],
];
$topNorm = YarboMap::normalize(['get_map' => ['data' => $top]], null);
assert_true(($topNorm['status'] ?? '') === 'ready', 'top-level map ref must work');
assert_true(($topNorm['feature_collection']['features'][0]['properties']['name'] ?? null) === 'Lawn', 'top-level ref keeps name');
assert_true(YarboGeo::extractGpsRefFromMapData($top) !== null, 'extractGpsRefFromMapData must see top-level ref');

// 4) Cloud map API still requests read_gps_ref for the origin fallback.
$mapApi = (string) file_get_contents(dirname(__DIR__) . '/public/api/map.php');
assert_true(str_contains($mapApi, "fetch('read_gps_ref'"), 'cloud map load must fetch read_gps_ref');

// 5) Frontend must re-add areasLayer and fitBounds even after GPS centered.
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($js, 'setAreasLayerVisible(true)'), 'applyLoadedMapFeatures must ensure areasLayer is on the map');
assert_true(
    str_contains($js, 'if (!context.skipFit)')
    && !str_contains($js, 'if (!mapHasCentered && !context.skipFit)'),
    'zone load must fitBounds even when GPS already centered',
);

echo "map zone overlays ok\n";
