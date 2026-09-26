<?php

declare(strict_types=1);

/**
 * Prove GeoJSON decode → encode is lossless for an unchanged map.
 * Does not talk to the robot. Never prints lat/lon.
 *
 * Usage:
 *   php scripts/map_roundtrip.php [path-to-get_map.json]
 */

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboMap;

$defaultFixture = __DIR__ . '/../tests/fixtures/map_app_sample.json';
$lastMap = __DIR__ . '/../data/map-last.json';
$path = $argv[1] ?? (is_file($lastMap) ? $lastMap : $defaultFixture);

if (!is_file($path)) {
    fwrite(STDERR, "Map file not found: {$path}\n");
    exit(1);
}

$raw = json_decode((string) file_get_contents($path), true);
if (!is_array($raw)) {
    fwrite(STDERR, "Not JSON: {$path}\n");
    exit(1);
}

$map = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
$source = basename($path);
$isFixture = realpath($path) === realpath($defaultFixture);
$failures = [];

$normalized = YarboMap::normalize(['get_map' => ['data' => $map]]);
if (($normalized['status'] ?? '') !== 'ready') {
    fwrite(STDERR, "Fixture did not decode to a drawable map (status=" . ($normalized['status'] ?? '?') . ")\n");
    exit(1);
}

$collection = json_decode(
    json_encode($normalized['feature_collection'], JSON_THROW_ON_ERROR),
    true
);
$encoded = YarboMap::encodeDraft($map, $collection);
if (!($encoded['ok'] ?? false)) {
    fwrite(STDERR, "encodeDraft failed:\n - " . implode("\n - ", $encoded['errors'] ?? []) . "\n");
    exit(1);
}

$maxDelta = (float) ($encoded['max_delta_m'] ?? 0);
$roundTripLimit = 0.02;
if ($maxDelta > $roundTripLimit) {
    $failures[] = sprintf('unchanged map moved by %.4f m (limit %.3f m)', $maxDelta, $roundTripLimit);
}

$out = $encoded['map'];
assertZoneCount($map, $out, 'areas', $failures);
assertZoneCount($map, $out, 'pathways', $failures);
assertZoneCount($map, $out, 'nogozones', $failures);

if ($isFixture) {
    if (($map['areas'][0]['extra_keep_me'] ?? null) !== true) {
        $failures[] = 'fixture missing extra_keep_me (test data)';
    } elseif (($out['areas'][0]['extra_keep_me'] ?? null) !== true) {
        $failures[] = 'extra zone keys were dropped';
    }

    $origClosedCount = count($map['areas'][1]['range']);
    $outClosedCount = count($out['areas'][1]['range']);
    if ($origClosedCount !== $outClosedCount) {
        $failures[] = sprintf('closed polygon vertex count %d → %d', $origClosedCount, $outClosedCount);
    }

    $origZ = $map['areas'][0]['range'][0]['z'] ?? null;
    $outZ = $out['areas'][0]['range'][0]['z'] ?? null;
    if ($origZ !== $outZ) {
        $failures[] = 'range extra keys were dropped';
    }

    $nudged = $collection;
    $ring = &$nudged['features'][0]['geometry']['coordinates'][0];
    $ring[0][1] += 0.5 / 111_320.0;
    unset($ring);
    $nudgedResult = YarboMap::encodeDraft($map, $nudged);
    if (!($nudgedResult['ok'] ?? false)) {
        $failures[] = 'nudged encode failed: ' . implode('; ', $nudgedResult['errors'] ?? []);
    } else {
        $newY = (float) $nudgedResult['map']['areas'][0]['range'][0]['y'];
        $oldY = (float) $map['areas'][0]['range'][0]['y'];
        $dy = $newY - $oldY;
        if (abs($dy - 0.5) > 0.02) {
            $failures[] = sprintf('north nudge expected +0.50 m y, got %+.3f m', $dy);
        }
        $newX = (float) $nudgedResult['map']['areas'][0]['range'][0]['x'];
        $oldX = (float) $map['areas'][0]['range'][0]['x'];
        if (abs($newX - $oldX) > 0.02) {
            $failures[] = sprintf('north nudge should not change x (Δx=%.3f m)', $newX - $oldX);
        }
    }
}

$featureCount = count($collection['features'] ?? []);
echo "source={$source}\n";
echo "features={$featureCount}\n";
echo sprintf("unchanged_max_delta_m=%.6f\n", $maxDelta);
if ($failures === []) {
    echo "roundtrip=ok\n";
    if ($isFixture) {
        echo "nudge=ok\n";
    }
    exit(0);
}

echo "roundtrip=fail\n";
foreach ($failures as $failure) {
    echo " - {$failure}\n";
}
exit(1);

/**
 * @param array<string, mixed> $original
 * @param array<string, mixed> $encoded
 * @param list<string> $failures
 */
function assertZoneCount(array $original, array $encoded, string $key, array &$failures): void
{
    $a = is_array($original[$key] ?? null) ? $original[$key] : [];
    $b = is_array($encoded[$key] ?? null) ? $encoded[$key] : [];
    if (count($a) !== count($b)) {
        $failures[] = sprintf('%s count %d → %d', $key, count($a), count($b));
        return;
    }
    foreach ($a as $i => $zone) {
        $origRange = is_array($zone['range'] ?? null) ? $zone['range'] : [];
        $outRange = is_array($b[$i]['range'] ?? null) ? $b[$i]['range'] : [];
        if (count($origRange) !== count($outRange)) {
            $failures[] = sprintf('%s[%d] vertices %d → %d', $key, $i, count($origRange), count($outRange));
        }
    }
}
