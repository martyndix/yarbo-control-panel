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

use Yarbo\YarboCodec;
use Yarbo\YarboMap;
use Yarbo\YarboMapBackup;

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

$compressedField = YarboCodec::encodePayloadField($encoded['map']);
$decodedField = YarboCodec::decodePayloadField($compressedField);
if ($decodedField === [] || YarboMap::maxRangeDelta($encoded['map'], $decodedField) > $roundTripLimit) {
    $failures[] = 'get_map-style compressed data field did not round-trip';
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

        $leftoverLast = [
            'type' => 'FeatureCollection',
            'features' => [$nudged['features'][0], $collection['features'][0]],
        ];
        $leftoverEncode = YarboMap::encodeDraft($map, $leftoverLast);
        if (!($leftoverEncode['ok'] ?? false)) {
            $failures[] = 'leftover-line encode failed: ' . implode('; ', $leftoverEncode['errors'] ?? []);
        } else {
            $leftoverY = (float) $leftoverEncode['map']['areas'][0]['range'][0]['y'];
            if (abs($leftoverY - $newY) > 0.02) {
                $failures[] = sprintf(
                    'leftover original line overwrote the edit (got y %+0.3f m, want %+0.3f m)',
                    $leftoverY - $oldY,
                    $dy
                );
            }
        }

        $drawnOnTop = [
            'type' => 'FeatureCollection',
            'features' => [
                $nudged['features'][0],
                [
                    'type' => 'Feature',
                    'properties' => ['zone_type' => 'clean', 'name' => 'New zone'],
                    'geometry' => $nudged['features'][0]['geometry'],
                ],
            ],
        ];
        $drawnEncode = YarboMap::encodeDraft($map, $drawnOnTop);
        if (!($drawnEncode['ok'] ?? false)) {
            $failures[] = 'pathless extra polygon should be skipped: ' . implode('; ', $drawnEncode['errors'] ?? []);
        } elseif (abs(((float) $drawnEncode['map']['areas'][0]['range'][0]['y']) - $newY) > 0.02) {
            $failures[] = 'pathless extra polygon dropped the vertex edit';
        }
    }

    $wrapper = [
        'areas' => [],
        'pathways' => [],
        'nogozones' => [],
        'backups' => [
            ['id' => 7, 'name' => 'auto', 'map' => $map],
        ],
    ];
    $located = YarboMapBackup::locateMapFromList($wrapper, 7);
    if (($located['map']['areas'][0]['id'] ?? null) !== ($map['areas'][0]['id'] ?? null)) {
        $failures[] = 'locateMapFromList kept the empty list wrapper instead of the nested backup map';
    }
    $emptyEncode = YarboMap::encodeDraft($wrapper, $collection);
    if ($emptyEncode['ok'] ?? false) {
        $failures[] = 'encodeDraft should reject an empty areas[] wrapper';
    }
    $nestedEncode = YarboMap::encodeDraft($located['map'], $collection);
    if (!($nestedEncode['ok'] ?? false)) {
        $failures[] = 'encodeDraft failed on nested backup map: ' . implode('; ', $nestedEncode['errors'] ?? []);
    }

    $keyedMap = $map;
    $keyedMap['areas'] = [
        'area-open' => $map['areas'][0],
        'area-closed' => $map['areas'][1],
    ];
    $keyedCollection = json_decode(
        json_encode(YarboMap::normalize(['get_map' => ['data' => $keyedMap]])['feature_collection'], JSON_THROW_ON_ERROR),
        true
    );
    $keyedEncode = YarboMap::encodeDraft($keyedMap, $keyedCollection);
    if (!($keyedEncode['ok'] ?? false)) {
        $failures[] = 'encodeDraft failed on string-keyed areas: ' . implode('; ', $keyedEncode['errors'] ?? []);
    } elseif (($keyedEncode['map']['areas']['area-open']['extra_keep_me'] ?? null) !== true) {
        $failures[] = 'string-keyed encode dropped extra_keep_me';
    }

    $singularPath = __DIR__ . '/../tests/fixtures/map_backup_singular.json';
    $singularMap = json_decode((string) file_get_contents($singularPath), true);
    if (!is_array($singularMap) || YarboMap::appGeometryCount($singularMap) < 3) {
        $failures[] = 'singular backup fixture was not counted as drawable';
    } else {
        $singularNorm = YarboMap::normalize(['get_map' => ['data' => $singularMap]]);
        $singularCol = json_decode(json_encode($singularNorm['feature_collection'], JSON_THROW_ON_ERROR), true);
        $singularEncode = YarboMap::encodeDraft($singularMap, $singularCol);
        if (!($singularEncode['ok'] ?? false)) {
            $failures[] = 'encodeDraft failed on singular backup keys: ' . implode('; ', $singularEncode['errors'] ?? []);
        } elseif (($singularEncode['map']['area'][0]['extra_keep_me'] ?? null) !== true) {
            $failures[] = 'singular backup encode dropped extra_keep_me';
        } elseif (!isset($singularEncode['map']['area']) || isset($singularEncode['map']['areas'])) {
            $failures[] = 'singular backup encode should keep area and not invent areas';
        }
    }

    $dupMap = $map;
    $dupMap['pathways'] = [$map['pathways'][0], $map['pathways'][0]];
    $dupNorm = YarboMap::normalize(['get_map' => ['data' => $dupMap]]);
    $dupFeatures = $dupNorm['feature_collection']['features'] ?? [];
    $dupPaths = array_values(array_filter($dupFeatures, static fn ($f) => ($f['properties']['zone_type'] ?? '') === 'path'));
    if (count($dupPaths) !== 1) {
        $failures[] = sprintf('identical pathway copies should draw once, got %d', count($dupPaths));
    } else {
        $dupCol = ['type' => 'FeatureCollection', 'features' => $dupNorm['feature_collection']['features']];
        $ring = &$dupCol['features'][array_key_first(array_filter(
            array_keys($dupCol['features']),
            static fn ($i) => ($dupCol['features'][$i]['properties']['zone_type'] ?? '') === 'path'
        ))];
        if (($ring['geometry']['type'] ?? '') === 'LineString') {
            $ring['geometry']['coordinates'][0][1] += 0.5 / 111_320.0;
        }
        unset($ring);
        $dupEncode = YarboMap::encodeDraft($dupMap, $dupCol);
        if (!($dupEncode['ok'] ?? false)) {
            $failures[] = 'duplicate pathway encode failed: ' . implode('; ', $dupEncode['errors'] ?? []);
        } else {
            $y0 = (float) $dupEncode['map']['pathways'][0]['range'][0]['y'];
            $y1 = (float) $dupEncode['map']['pathways'][1]['range'][0]['y'];
            $oldY = (float) $map['pathways'][0]['range'][0]['y'];
            if (abs($y0 - $oldY - 0.5) > 0.05 || abs($y1 - $y0) > 0.02) {
                $failures[] = sprintf(
                    'duplicate pathway edit should move every copy (y0=%+.3f y1=%+.3f old=%+.3f)',
                    $y0 - $oldY,
                    $y1 - $oldY,
                    0.0
                );
            }
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
