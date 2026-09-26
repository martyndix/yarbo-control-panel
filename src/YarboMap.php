<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMap
{
    /**
     * Normalize raw map command payloads into map UI friendly shape.
     *
     * @param array<string, mixed> $responses
     * @return array{
     *   status: string,
     *   source: string|null,
     *   warnings: array<int, string>,
     *   probes: array<string, array{ok: bool, has_data: bool, data_keys: array<int, string>}>,
     *   feature_collection: array{type: string, features: array<int, array<string, mixed>>}
     * }
     */
    public static function normalize(array $responses, ?array $gpsRef = null): array
    {
        $warnings = [];
        $features = [];
        $source = null;
        $probes = [];
        $ref = $gpsRef !== null ? YarboGeo::extractGpsRef($gpsRef) : null;

        foreach ($responses as $cmd => $envelope) {
            if (!is_array($envelope)) {
                $probes[$cmd] = ['ok' => false, 'has_data' => false, 'data_keys' => []];
                continue;
            }

            $data = YarboCodec::decodePayloadField($envelope['data'] ?? null);
            if ($data === []) {
                $data = YarboCodec::decodePayloadField($envelope);
            }

            $hasData = $data !== [];
            $probes[$cmd] = [
                'ok' => is_array($envelope),
                'has_data' => $hasData,
                'data_keys' => array_map('strval', array_keys($data)),
            ];

            if (!$hasData) {
                continue;
            }

            if ($source === null) {
                $source = (string) $cmd;
            }

            if ($cmd === 'get_map') {
                if ($ref === null) {
                    $ref = YarboGeo::extractGpsRefFromMapData($data);
                }
                $mapFeatures = self::extractAppMapFeatures($data);
                if ($mapFeatures === [] && $ref !== null) {
                    $mapFeatures = self::extractOfficialMapFeatures($data, $ref);
                }
                if ($mapFeatures !== []) {
                    $features = array_merge($features, $mapFeatures);
                    continue;
                }
            }

            $features = array_merge($features, self::extractFeaturesFromPayload($data, (string) $cmd, $ref));
        }

        if ($ref === null && $features === []) {
            $warnings[] = 'No GPS reference from read_gps_ref — local map coordinates cannot be converted to lat/lon.';
        }

        if ($features === []) {
            $status = self::hasAnyData($responses) ? 'structured_no_geometry' : 'empty';
            if ($status === 'empty') {
                $warnings[] = 'No stored map/area data was returned by this robot.';
            } else {
                $warnings[] = 'Map responses were returned, but no drawable geometry could be detected yet.';
            }
        } else {
            $status = 'ready';
        }

        return [
            'status' => $status,
            'source' => $source,
            'gps_ref' => $ref,
            'warnings' => $warnings,
            'probes' => $probes,
            'feature_collection' => self::sanitizeFeatureCollection([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]),
        ];
    }

    /**
     * @param array{type: string, features: array<int, array<string, mixed>>} $collection
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public static function sanitizeFeatureCollection(array $collection): array
    {
        $features = [];
        foreach ($collection['features'] as $feature) {
            if (!is_array($feature) || !is_array($feature['geometry'] ?? null)) {
                continue;
            }
            $geometry = $feature['geometry'];
            if (!self::geometryHasFiniteCoordinates($geometry)) {
                continue;
            }
            $features[] = $feature;
        }

        return [
            'type' => 'FeatureCollection',
            'features' => self::dedupeFeatures($features),
        ];
    }

    /**
     * @param list<array<string, mixed>> $features
     * @return list<array<string, mixed>>
     */
    public static function dedupeFeatures(array $features): array
    {
        $seen = [];
        $out = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $id = self::featureIdentity($feature);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $feature;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $feature
     */
    private static function featureIdentity(array $feature): string
    {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geom = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];

        return implode('|', [
            (string) ($props['zone_type'] ?? ''),
            (string) ($props['name'] ?? ''),
            (string) ($geom['type'] ?? ''),
            json_encode($geom['coordinates'] ?? null, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Patch original get_map data with a GeoJSON draft. Keeps every original
     * zone key and only replaces range / charging points. Does not talk to the robot.
     *
     * @param array<string, mixed> $originalMap
     * @param array{type?: string, features?: array<int, mixed>} $collection
     * @return array{ok: bool, map?: array<string, mixed>, errors: list<string>, max_delta_m: float}
     */
    public static function encodeDraft(array $originalMap, array $collection): array
    {
        $errors = [];
        $encoded = json_decode(json_encode($originalMap, JSON_THROW_ON_ERROR), true);
        if (!is_array($encoded)) {
            return ['ok' => false, 'errors' => ['Could not copy the original map'], 'max_delta_m' => 0.0];
        }

        $features = is_array($collection['features'] ?? null) ? $collection['features'] : [];
        $maxDelta = 0.0;
        $unpathed = 0;
        $best = [];

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $patch = self::draftFeaturePatch($encoded, $feature);
            if (($patch['skip'] ?? false) === true) {
                $unpathed++;
                continue;
            }
            if (($patch['error'] ?? null) !== null) {
                $errors[] = (string) $patch['error'];
                continue;
            }
            $key = (string) ($patch['key'] ?? '');
            $delta = (float) ($patch['delta_m'] ?? 0);
            if ($key === '') {
                continue;
            }
            if (!isset($best[$key]) || $delta >= (float) $best[$key]['delta_m']) {
                $best[$key] = $patch;
            }
        }

        if ($best === [] && $unpathed > 0) {
            $errors[] = 'Drawn shape has no map path. Drag vertices of the existing zone; do not draw a new polygon on top of it.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'max_delta_m' => $maxDelta];
        }

        foreach ($best as $patch) {
            $encoded = self::applyDraftPatch($encoded, $patch);
            $maxDelta = max($maxDelta, (float) ($patch['delta_m'] ?? 0));
        }

        $encoded = self::syncDuplicateRanges($originalMap, $encoded);

        return ['ok' => true, 'map' => $encoded, 'errors' => [], 'max_delta_m' => $maxDelta];
    }

    /**
     * @param array<string, mixed> $encoded
     * @param array<string, mixed> $feature
     * @return array<string, mixed>
     */
    private static function draftFeaturePatch(array $encoded, array $feature): array
    {
        $path = (string) ($feature['properties']['path'] ?? '');
        $parsed = self::parseMapPath($path);
        if ($parsed === null) {
            return ['skip' => true];
        }
        [$list, $listIndex] = $parsed;
        $zoneId = $feature['properties']['zone_id'] ?? $feature['properties']['source_key'] ?? null;
        $kind = (string) ($feature['properties']['kind'] ?? ($feature['geometry']['type'] ?? ''));
        $isLine = $kind === 'line' || $kind === 'LineString';
        $mapRef = YarboGeo::extractGpsRef($encoded);

        if ($list === 'chargingData' || $path === 'chargingData' || $list === 'chargingPoint' || $path === 'chargingPoint') {
            $station = $encoded;
            $writeKey = null;
            if ($list === 'chargingData' || $path === 'chargingData') {
                if (is_array($encoded['chargingData'] ?? null)) {
                    $station = $encoded['chargingData'];
                    $writeKey = 'chargingData';
                }
            } elseif (is_array($encoded['chargingPoint'] ?? null) && !isset($encoded['chargingPoint']['x']) && !isset($encoded['chargingPoint']['X'])) {
                $station = $encoded['chargingPoint'];
                $writeKey = 'chargingPoint';
            }
            $result = self::encodeChargingPoint($station, $feature);
            if ($result['error'] !== null) {
                return ['error' => $result['error']];
            }

            return [
                'key' => $writeKey ?? 'chargingPoint',
                'delta_m' => $result['delta_m'],
                'writer' => $writeKey === null ? 'root' : 'key',
                'write_key' => $writeKey,
                'zone' => $result['zone'],
            ];
        }

        if ($list === 'allchargingData' || $list === 'chargingPoints') {
            $zones = is_array($encoded[$list] ?? null) ? $encoded[$list] : [];
            $zoneKey = self::findZoneKey($zones, $listIndex, $zoneId);
            if ($zoneKey === null) {
                return ['error' => sprintf(
                    'Draft charging station %s is not on the original map (%s has %d stations)',
                    $path,
                    $list,
                    count($zones)
                )];
            }
            $result = self::encodeChargingPoint($zones[$zoneKey], $feature);
            if ($result['error'] !== null) {
                return ['error' => $result['error']];
            }

            return [
                'key' => $list . '[' . $zoneKey . ']',
                'delta_m' => $result['delta_m'],
                'writer' => 'list',
                'list' => $list,
                'zone_key' => $zoneKey,
                'zone' => $result['zone'],
            ];
        }

        $rawList = $encoded[$list] ?? null;
        if (self::isSingleZone($rawList)) {
            $result = self::encodeZoneRange($rawList, $feature, $isLine, $mapRef);
            if ($result['error'] !== null) {
                return ['error' => $path . ': ' . $result['error']];
            }

            return [
                'key' => $list,
                'delta_m' => $result['delta_m'],
                'writer' => 'single',
                'list' => $list,
                'zone' => $result['zone'],
            ];
        }

        $zones = is_array($rawList) ? $rawList : [];
        $zoneKey = self::findZoneKey($zones, $listIndex, $zoneId);
        if ($zoneKey === null) {
            return ['error' => sprintf(
                'Draft zone %s is not on the original map (%s has %d zones)',
                $path,
                $list,
                count($zones)
            )];
        }
        $result = self::encodeZoneRange($zones[$zoneKey], $feature, $isLine, $mapRef);
        if ($result['error'] !== null) {
            return ['error' => $path . ': ' . $result['error']];
        }

        return [
            'key' => $list . '[' . $zoneKey . ']',
            'delta_m' => $result['delta_m'],
            'writer' => 'list',
            'list' => $list,
            'zone_key' => $zoneKey,
            'zone' => $result['zone'],
        ];
    }

    /**
     * @param array<string, mixed> $encoded
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function applyDraftPatch(array $encoded, array $patch): array
    {
        $writer = (string) ($patch['writer'] ?? '');
        $zone = is_array($patch['zone'] ?? null) ? $patch['zone'] : [];
        if ($writer === 'root') {
            return $zone;
        }
        if ($writer === 'key') {
            $encoded[(string) $patch['write_key']] = $zone;

            return $encoded;
        }
        if ($writer === 'single') {
            $encoded[(string) $patch['list']] = $zone;

            return $encoded;
        }
        if ($writer === 'list') {
            $encoded[(string) $patch['list']][$patch['zone_key']] = $zone;
        }

        return $encoded;
    }

    /**
     * If the backup file stored the same zone several times, copy an edit onto every copy.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $encoded
     * @return array<string, mixed>
     */
    private static function syncDuplicateRanges(array $original, array $encoded): array
    {
        foreach (array_keys(self::canonicalListNames()) as $canonical) {
            $key = self::presentListKey($encoded, $canonical);
            if ($key === null || !isset($encoded[$key]) || self::isSingleZone($encoded[$key])) {
                continue;
            }
            $origKey = self::presentListKey($original, $canonical) ?? $key;
            $origList = is_array($original[$origKey] ?? null) ? $original[$origKey] : [];
            if (self::isSingleZone($origList)) {
                continue;
            }
            $newBySig = [];
            foreach ($encoded[$key] as $i => $zone) {
                if (!is_array($zone)) {
                    continue;
                }
                $origZone = is_array($origList[$i] ?? null) ? $origList[$i] : [];
                $origSig = self::rangeSignature($origZone['range'] ?? null);
                $newSig = self::rangeSignature($zone['range'] ?? null);
                if ($origSig !== '' && $origSig !== $newSig) {
                    $newBySig[$origSig] = $zone['range'];
                }
            }
            if ($newBySig === []) {
                continue;
            }
            foreach ($encoded[$key] as $i => $zone) {
                if (!is_array($zone)) {
                    continue;
                }
                $origZone = is_array($origList[$i] ?? null) ? $origList[$i] : [];
                $origSig = self::rangeSignature($origZone['range'] ?? null);
                if (isset($newBySig[$origSig])) {
                    $encoded[$key][$i]['range'] = $newBySig[$origSig];
                }
            }
        }

        return $encoded;
    }

    private static function rangeSignature(mixed $range): string
    {
        if (!is_array($range) || $range === []) {
            return '';
        }
        $parts = [];
        foreach ($range as $point) {
            if (!is_array($point)) {
                continue;
            }
            $x = is_numeric($point['x'] ?? null) ? round((float) $point['x'], 3) : 0.0;
            $y = is_numeric($point['y'] ?? null) ? round((float) $point['y'], 3) : 0.0;
            $parts[] = $x . ',' . $y;
        }

        return implode(';', $parts);
    }

    /**
     * Largest vertex move (metres) between two app-format maps. Missing lists count as 0.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function maxRangeDelta(array $a, array $b): float
    {
        $max = 0.0;
        foreach (array_keys(self::canonicalListNames()) as $canonical) {
            $left = self::zoneList($a, $canonical);
            $right = self::zoneList($b, $canonical);
            $n = min(count($left), count($right));
            for ($i = 0; $i < $n; $i++) {
                $r1 = is_array($left[$i]['range'] ?? null) ? $left[$i]['range'] : [];
                $r2 = is_array($right[$i]['range'] ?? null) ? $right[$i]['range'] : [];
                $p = min(count($r1), count($r2));
                for ($j = 0; $j < $p; $j++) {
                    $x1 = is_numeric($r1[$j]['x'] ?? null) ? (float) $r1[$j]['x'] : 0.0;
                    $y1 = is_numeric($r1[$j]['y'] ?? null) ? (float) $r1[$j]['y'] : 0.0;
                    $x2 = is_numeric($r2[$j]['x'] ?? null) ? (float) $r2[$j]['x'] : 0.0;
                    $y2 = is_numeric($r2[$j]['y'] ?? null) ? (float) $r2[$j]['y'] : 0.0;
                    $max = max($max, hypot($x1 - $x2, $y1 - $y2));
                }
            }
        }

        return $max;
    }

    /**
     * Largest vertex move between maps, matching zones by id then name (not list index).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param list<string>|null $onlyCanonical
     */
    public static function maxAlignedRangeDelta(array $a, array $b, ?array $onlyCanonical = null): float
    {
        $max = 0.0;
        foreach (self::alignedListDeltas($a, $b) as $canonical => $delta) {
            if ($onlyCanonical !== null && !in_array($canonical, $onlyCanonical, true)) {
                continue;
            }
            $max = max($max, $delta);
        }

        return $max;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, float>
     */
    public static function alignedListDeltas(array $a, array $b): array
    {
        $out = [];
        foreach (array_keys(self::canonicalListNames()) as $canonical) {
            $out[$canonical] = self::alignedListDelta($a, $b, $canonical);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function alignedListDelta(array $a, array $b, string $canonical): float
    {
        $max = 0.0;
        $left = self::zoneList($a, $canonical);
        $right = self::zoneList($b, $canonical);
        foreach ($left as $lz) {
            $rz = self::findMatchingZone($right, $lz);
            if ($rz === null) {
                continue;
            }
            $r1 = is_array($lz['range'] ?? null) ? $lz['range'] : [];
            $r2 = is_array($rz['range'] ?? null) ? $rz['range'] : [];
            $p = min(count($r1), count($r2));
            for ($j = 0; $j < $p; $j++) {
                $x1 = is_numeric($r1[$j]['x'] ?? null) ? (float) $r1[$j]['x'] : 0.0;
                $y1 = is_numeric($r1[$j]['y'] ?? null) ? (float) $r1[$j]['y'] : 0.0;
                $x2 = is_numeric($r2[$j]['x'] ?? null) ? (float) $r2[$j]['x'] : 0.0;
                $y2 = is_numeric($r2[$j]['y'] ?? null) ? (float) $r2[$j]['y'] : 0.0;
                $max = max($max, hypot($x1 - $x2, $y1 - $y2));
            }
        }

        return $max;
    }

    /**
     * @param list<array<string, mixed>> $zones
     * @param array<string, mixed> $needle
     * @return array<string, mixed>|null
     */
    public static function findMatchingZone(array $zones, array $needle): ?array
    {
        $id = $needle['id'] ?? null;
        if ($id !== null && $id !== '') {
            foreach ($zones as $zone) {
                if (is_array($zone) && (string) ($zone['id'] ?? '') === (string) $id) {
                    return $zone;
                }
            }
        }
        $name = $needle['name'] ?? null;
        if ($name !== null && $name !== '') {
            foreach ($zones as $zone) {
                if (is_array($zone) && (string) ($zone['name'] ?? '') === (string) $name) {
                    return $zone;
                }
            }
        }

        return null;
    }

    /**
     * Copy edited range/name onto a live get_map zone, converting local metres between refs.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public static function rebaseZoneOnto(array $source, array $target): array
    {
        $out = $target;
        if (array_key_exists('name', $source)) {
            $out['name'] = $source['name'];
        }
        $range = $source['range'] ?? null;
        if (!is_array($range)) {
            return $out;
        }
        $srcRef = YarboGeo::extractGpsRef($source);
        $dstRef = YarboGeo::extractGpsRef($target);
        if ($srcRef === null || $dstRef === null) {
            $out['range'] = $range;

            return $out;
        }
        $sameRef = abs($srcRef['latitude'] - $dstRef['latitude']) < 1e-8
            && abs($srcRef['longitude'] - $dstRef['longitude']) < 1e-8;
        if ($sameRef) {
            $out['range'] = $range;

            return $out;
        }
        $converted = [];
        foreach ($range as $point) {
            if (!is_array($point)) {
                continue;
            }
            $x = is_numeric($point['x'] ?? null) ? (float) $point['x'] : 0.0;
            $y = is_numeric($point['y'] ?? null) ? (float) $point['y'] : 0.0;
            [$lat, $lon] = YarboGeo::localToGps($x, $y, $srcRef['latitude'], $srcRef['longitude']);
            [$nx, $ny] = YarboGeo::gpsToLocal($lat, $lon, $dstRef['latitude'], $dstRef['longitude']);
            $next = $point;
            $next['x'] = $nx;
            $next['y'] = $ny;
            $converted[] = $next;
        }
        $out['range'] = $converted;

        return $out;
    }

    /**
     * @param array<string, mixed> $map
     * @param array<string, mixed> $zone
     * @return array<string, mixed>
     */
    public static function replaceMatchingZone(array $map, string $canonical, array $zone): array
    {
        $key = self::presentListKey($map, $canonical);
        if ($key === null) {
            return $map;
        }
        $value = $map[$key];
        if (self::isSingleZone($value)) {
            $map[$key] = $zone;

            return $map;
        }
        if (!is_array($value)) {
            return $map;
        }
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (self::findMatchingZone([$item], $zone) !== null) {
                $value[$i] = $zone;
                $map[$key] = $value;

                return $map;
            }
        }

        return $map;
    }

    /**
     * get_map uses plural list keys; backup files use the singular form.
     *
     * @return array<string, list<string>>
     */
    public static function canonicalListNames(): array
    {
        return [
            'areas' => ['areas', 'area'],
            'pathways' => ['pathways', 'pathway'],
            'nogozones' => ['nogozones', 'nogozone'],
            'novisionzones' => ['novisionzones', 'novisionzone'],
            'deadends' => ['deadends', 'deadend'],
            'sidewalks' => ['sidewalks', 'sidewalk'],
            'elec_fence' => ['elec_fence'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function zoneList(array $data, string $canonical): array
    {
        foreach (self::canonicalListNames()[$canonical] ?? [$canonical] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (self::isSingleZone($value)) {
                return [$value];
            }
            $out = [];
            foreach ($value as $zone) {
                if (is_array($zone)) {
                    $out[] = $zone;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [];
    }

    public static function presentListKey(array $data, string $canonical): ?string
    {
        foreach (self::canonicalListNames()[$canonical] ?? [$canonical] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || $data[$key] === []) {
                continue;
            }

            return $key;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function isSingleZone(mixed $value): bool
    {
        return is_array($value) && isset($value['range']) && is_array($value['range']);
    }

    public static function isAppMap(array $data): bool
    {
        return self::appGeometryCount($data) > 0;
    }

    /**
     * Count drawable app-format zones (empty `areas: []` wrappers score 0).
     *
     * @param array<string, mixed> $data
     */
    public static function appGeometryCount(array $data): int
    {
        $count = 0;
        foreach (array_keys(self::canonicalListNames()) as $canonical) {
            foreach (self::zoneList($data, $canonical) as $zone) {
                if (is_array($zone['range'] ?? null) && $zone['range'] !== []) {
                    $count++;
                }
            }
        }
        $charging = $data['chargingData'] ?? $data['chargingPoint'] ?? null;
        if (is_array($charging)) {
            $point = $charging['chargingPoint'] ?? $charging['charging_point'] ?? $charging;
            if (is_array($point) && (isset($point['x']) || isset($point['X']))) {
                $count++;
            }
        }
        foreach (['allchargingData', 'chargingPoints'] as $key) {
            $allCharging = $data[$key] ?? null;
            if (!is_array($allCharging) || isset($allCharging['x']) || isset($allCharging['X'])) {
                continue;
            }
            foreach ($allCharging as $station) {
                if (!is_array($station)) {
                    continue;
                }
                $point = $station['chargingPoint'] ?? $station['charging_point'] ?? $station;
                if (is_array($point) && (isset($point['x']) || isset($point['X']))) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array{0: string, 1: int|string}|null
     */
    private static function parseMapPath(string $path): ?array
    {
        if ($path === 'chargingData' || $path === 'chargingPoint') {
            return [$path, 0];
        }
        if (preg_match('/^(areas|area|nogozones|nogozone|novisionzones|novisionzone|elec_fence|pathways|pathway|sidewalks|sidewalk|deadends|deadend|allchargingData|chargingPoints)\[([^\]]+)\]$/', $path, $matches)) {
            $index = $matches[2];
            if (ctype_digit($index)) {
                return [$matches[1], (int) $index];
            }

            return [$matches[1], $index];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $zones
     */
    private static function findZoneKey(array $zones, int|string $listIndex, mixed $zoneId): int|string|null
    {
        if (array_key_exists($listIndex, $zones) && is_array($zones[$listIndex])) {
            return $listIndex;
        }
        $asString = (string) $listIndex;
        if (array_key_exists($asString, $zones) && is_array($zones[$asString])) {
            return $asString;
        }
        if ($zoneId !== null && $zoneId !== '') {
            foreach ($zones as $key => $zone) {
                if (!is_array($zone)) {
                    continue;
                }
                foreach (['id', 'area_id', 'map_id'] as $idKey) {
                    if (isset($zone[$idKey]) && (string) $zone[$idKey] === (string) $zoneId) {
                        return $key;
                    }
                }
            }
        }
        if (is_int($listIndex) || ctype_digit($asString)) {
            $want = (int) $listIndex;
            $i = 0;
            foreach ($zones as $key => $zone) {
                if (!is_array($zone)) {
                    continue;
                }
                if ($i === $want) {
                    return $key;
                }
                $i++;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $feature
     * @return array{zone: array<string, mixed>, delta_m: float, error: ?string}
     */
    private static function encodeZoneRange(array $zone, array $feature, bool $isLine, ?array $fallbackRef = null): array
    {
        $ref = YarboGeo::extractGpsRef($zone) ?? $fallbackRef;
        if ($ref === null) {
            return ['zone' => $zone, 'delta_m' => 0.0, 'error' => 'zone has no GPS ref'];
        }
        $coords = self::featureCoordinates($feature, $isLine);
        $min = $isLine ? 2 : 3;
        if (count($coords) < $min) {
            return ['zone' => $zone, 'delta_m' => 0.0, 'error' => 'not enough vertices'];
        }

        $original = is_array($zone['range'] ?? null) ? $zone['range'] : [];
        $range = [];
        $maxDelta = 0.0;
        foreach ($coords as $i => $position) {
            $lon = (float) $position[0];
            $lat = (float) $position[1];
            [$x, $y] = YarboGeo::gpsToLocal($lat, $lon, $ref['latitude'], $ref['longitude']);
            $point = is_array($original[$i] ?? null) ? $original[$i] : [];
            $oldX = is_numeric($point['x'] ?? null) ? (float) $point['x'] : $x;
            $oldY = is_numeric($point['y'] ?? null) ? (float) $point['y'] : $y;
            $maxDelta = max($maxDelta, hypot($x - $oldX, $y - $oldY));
            $point['x'] = $x;
            $point['y'] = $y;
            $range[] = $point;
        }

        $origClosed = self::rangeIsClosed($original);
        $newClosed = self::rangeIsClosed($range);
        if ($origClosed && !$newClosed && $range !== []) {
            $closing = is_array($original[count($original) - 1] ?? null) ? $original[count($original) - 1] : [];
            $closing['x'] = $range[0]['x'];
            $closing['y'] = $range[0]['y'];
            $range[] = $closing;
        } elseif (!$origClosed && $newClosed) {
            array_pop($range);
        }

        $zone['range'] = $range;

        return ['zone' => $zone, 'delta_m' => $maxDelta, 'error' => null];
    }

    /**
     * @param array<int, mixed> $range
     */
    private static function rangeIsClosed(array $range): bool
    {
        if (count($range) < 2) {
            return false;
        }
        $first = $range[0];
        $last = $range[count($range) - 1];
        if (!is_array($first) || !is_array($last)) {
            return false;
        }
        $x1 = $first['x'] ?? null;
        $y1 = $first['y'] ?? null;
        $x2 = $last['x'] ?? null;
        $y2 = $last['y'] ?? null;
        if (!is_numeric($x1) || !is_numeric($y1) || !is_numeric($x2) || !is_numeric($y2)) {
            return false;
        }

        return hypot((float) $x1 - (float) $x2, (float) $y1 - (float) $y2) < 0.001;
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $feature
     * @return array{zone: array<string, mixed>, delta_m: float, error: ?string}
     */
    private static function encodeChargingPoint(array $zone, array $feature): array
    {
        $ref = YarboGeo::extractGpsRef($zone);
        if ($ref === null) {
            return ['zone' => $zone, 'delta_m' => 0.0, 'error' => 'charging station has no GPS ref'];
        }
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $position = $geometry['coordinates'] ?? null;
        if (!is_array($position) || count($position) < 2) {
            return ['zone' => $zone, 'delta_m' => 0.0, 'error' => 'charging point is not a Point'];
        }
        $lon = (float) $position[0];
        $lat = (float) $position[1];
        [$x, $y] = YarboGeo::gpsToLocal($lat, $lon, $ref['latitude'], $ref['longitude']);
        $key = isset($zone['chargingPoint']) ? 'chargingPoint' : 'charging_point';
        $point = is_array($zone[$key] ?? null) ? $zone[$key] : [];
        $oldX = is_numeric($point['x'] ?? null) ? (float) $point['x'] : $x;
        $oldY = is_numeric($point['y'] ?? null) ? (float) $point['y'] : $y;
        $point['x'] = $x;
        $point['y'] = $y;
        $zone[$key] = $point;

        return ['zone' => $zone, 'delta_m' => hypot($x - $oldX, $y - $oldY), 'error' => null];
    }

    /**
     * @param array<string, mixed> $feature
     * @return list<array{0: float, 1: float}>
     */
    private static function featureCoordinates(array $feature, bool $isLine): array
    {
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            return [];
        }
        $line = $isLine ? $coordinates : (is_array($coordinates[0] ?? null) ? $coordinates[0] : []);
        if (!is_array($line)) {
            return [];
        }
        $out = [];
        foreach ($line as $position) {
            if (!is_array($position) || count($position) < 2) {
                continue;
            }
            $out[] = [(float) $position[0], (float) $position[1]];
        }
        if (!$isLine && count($out) >= 2) {
            $first = $out[0];
            $last = $out[count($out) - 1];
            if (abs($first[0] - $last[0]) < 1e-10 && abs($first[1] - $last[1]) < 1e-10) {
                array_pop($out);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private static function geometryHasFiniteCoordinates(array $geometry): bool
    {
        $type = (string) ($geometry['type'] ?? '');
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            return false;
        }

        if ($type === 'Point') {
            return self::isFinitePosition($coordinates);
        }

        if ($type === 'LineString') {
            if ($coordinates === []) {
                return false;
            }
            foreach ($coordinates as $position) {
                if (!self::isFinitePosition($position)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'Polygon') {
            foreach ($coordinates as $ring) {
                if (!is_array($ring)) {
                    return false;
                }
                foreach ($ring as $position) {
                    if (!self::isFinitePosition($position)) {
                        return false;
                    }
                }
            }
            return true;
        }

        return false;
    }

    /**
     * @param mixed $position
     */
    private static function isFinitePosition(mixed $position): bool
    {
        if (!is_array($position) || count($position) < 2) {
            return false;
        }
        $lon = $position[0];
        $lat = $position[1];
        if (!is_numeric($lon) || !is_numeric($lat)) {
            return false;
        }

        return YarboGeo::isValidGps((float) $lat, (float) $lon);
    }

    /**
     * @param array<string, mixed> $mapData
     * @param array{latitude: float, longitude: float} $ref
     * @return array<int, array<string, mixed>>
     */
    private static function extractOfficialMapFeatures(array $mapData, array $ref): array
    {
        $zoneTypes = [
            'clean_area_list' => 'clean',
            'forbidden_area_list' => 'forbidden',
            'obstacle_area_list' => 'obstacle',
            'path_area_list' => 'path',
            'recharge_area_list' => 'recharge',
            'cleanAreaList' => 'clean',
            'forbiddenAreaList' => 'forbidden',
        ];

        $features = [];
        foreach ($zoneTypes as $key => $zoneType) {
            $zones = $mapData[$key] ?? null;
            if (!is_array($zones)) {
                continue;
            }

            foreach ($zones as $index => $zone) {
                if (!is_array($zone)) {
                    continue;
                }

                $points = $zone['point_list'] ?? $zone['points'] ?? $zone['polygon'] ?? null;
                if (!is_array($points)) {
                    continue;
                }

                $coords = self::localPointListToCoordinates($points, $ref);
                if (count($coords) < 3) {
                    continue;
                }

                $features[] = self::polygonFeature(
                    $coords,
                    'get_map',
                    sprintf('%s[%d]', $key, (int) $index),
                    [
                        'zone_type' => $zoneType,
                        'zone_id' => $zone['id'] ?? $zone['area_id'] ?? $index,
                        'name' => $zone['name'] ?? null,
                        'map_list' => $key,
                    ],
                );
            }
        }

        return $features;
    }

    /**
     * Yarbo app map format: areas/pathways with per-zone ref + range points (x/y meters).
     *
     * @param array<string, mixed> $mapData
     * @return array<int, array<string, mixed>>
     */
    private static function extractAppMapFeatures(array $mapData): array
    {
        $polygonTypes = [
            'areas' => 'clean',
            'nogozones' => 'forbidden',
            'novisionzones' => 'no_vision',
            'elec_fence' => 'forbidden',
        ];
        $lineTypes = [
            'pathways' => 'path',
            'sidewalks' => 'sidewalk',
            'deadends' => 'path',
        ];

        $features = [];
        foreach ($polygonTypes as $canonical => $zoneType) {
            $key = self::presentListKey($mapData, $canonical);
            if ($key !== null) {
                $features = array_merge($features, self::extractAppZones($mapData, $key, $zoneType, 'polygon'));
            }
        }
        foreach ($lineTypes as $canonical => $zoneType) {
            $key = self::presentListKey($mapData, $canonical);
            if ($key !== null) {
                $features = array_merge($features, self::extractAppZones($mapData, $key, $zoneType, 'line'));
            }
        }

        $charging = $mapData['chargingData'] ?? null;
        if (is_array($charging)) {
            $point = self::chargingPointFeature($charging, 'chargingData');
            if ($point !== null) {
                $features[] = $point;
            }
        } elseif (isset($mapData['chargingPoint']) && is_array($mapData['chargingPoint'])) {
            $station = isset($mapData['chargingPoint']['x']) || isset($mapData['chargingPoint']['X'])
                ? $mapData
                : $mapData['chargingPoint'];
            $point = self::chargingPointFeature($station, 'chargingPoint');
            if ($point !== null) {
                $features[] = $point;
            }
        }
        $allCharging = $mapData['allchargingData'] ?? $mapData['chargingPoints'] ?? null;
        $allKey = isset($mapData['allchargingData']) ? 'allchargingData' : 'chargingPoints';
        if (is_array($allCharging) && !isset($allCharging['x']) && !isset($allCharging['X'])) {
            foreach ($allCharging as $index => $station) {
                if (!is_array($station)) {
                    continue;
                }
                $point = self::chargingPointFeature($station, sprintf('%s[%d]', $allKey, (int) $index));
                if ($point !== null) {
                    $features[] = $point;
                }
            }
        }

        return $features;
    }

    /**
     * @param array<string, mixed> $mapData
     * @param 'polygon'|'line' $geometry
     * @return array<int, array<string, mixed>>
     */
    private static function extractAppZones(array $mapData, string $key, string $zoneType, string $geometry): array
    {
        $zones = $mapData[$key] ?? null;
        if (!is_array($zones)) {
            return [];
        }
        if (self::isSingleZone($zones)) {
            $zones = [$zones];
        }

        $minPoints = $geometry === 'line' ? 2 : 3;
        $mapRef = YarboGeo::extractGpsRef($mapData);
        $features = [];
        foreach ($zones as $index => $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $zoneRef = YarboGeo::extractGpsRef($zone) ?? $mapRef;
            if ($zoneRef === null) {
                continue;
            }

            $range = $zone['range'] ?? null;
            if (!is_array($range)) {
                continue;
            }

            $coords = self::rangePointsToCoordinates($range, $zoneRef);
            if (count($coords) < $minPoints) {
                continue;
            }

            $sourceKey = is_int($index) || ctype_digit((string) $index) ? (int) $index : (string) $index;
            $props = [
                'zone_type' => $zoneType,
                'zone_id' => $zone['id'] ?? $sourceKey,
                'name' => $zone['name'] ?? null,
                'map_list' => $key,
                'source_key' => $sourceKey,
            ];
            $path = is_int($sourceKey)
                ? sprintf('%s[%d]', $key, $sourceKey)
                : sprintf('%s[%s]', $key, $sourceKey);
            $features[] = $geometry === 'line'
                ? self::lineStringFeature($coords, 'get_map', $path, $props)
                : self::polygonFeature($coords, 'get_map', $path, $props);
        }

        return $features;
    }

    /**
     * @param array<string, mixed> $charging
     * @return array<string, mixed>|null
     */
    private static function chargingPointFeature(array $charging, string $path): ?array
    {
        $zoneRef = YarboGeo::extractGpsRef($charging);
        if ($zoneRef === null) {
            return null;
        }

        $point = $charging['chargingPoint'] ?? $charging['charging_point'] ?? null;
        if (!is_array($point)) {
            return null;
        }

        $x = $point['x'] ?? $point['X'] ?? null;
        $y = $point['y'] ?? $point['Y'] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) {
            return null;
        }

        [$lat, $lon] = YarboGeo::localToGps((float) $x, (float) $y, $zoneRef['latitude'], $zoneRef['longitude']);
        if (!YarboGeo::isValidGps($lat, $lon)) {
            return null;
        }

        return self::pointFeature(
            [$lon, $lat],
            'get_map',
            $path,
            [
                'zone_type' => 'charging',
                'zone_id' => $charging['id'] ?? null,
                'name' => $charging['name'] ?? 'Charging Station',
                'map_list' => str_starts_with($path, 'allchargingData') ? 'allchargingData' : 'chargingData',
            ],
        );
    }

    /**
     * @param array<int, mixed> $points
     * @param array{latitude: float, longitude: float} $ref
     * @return array<int, array{0: float, 1: float}>
     */
    private static function rangePointsToCoordinates(array $points, array $ref): array
    {
        $coords = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $x = $point['x'] ?? null;
            $y = $point['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y)) {
                continue;
            }

            [$lat, $lon] = YarboGeo::localToGps(
                (float) $x,
                (float) $y,
                $ref['latitude'],
                $ref['longitude'],
            );
            if (!YarboGeo::isValidGps($lat, $lon)) {
                continue;
            }
            $coords[] = [$lon, $lat];
        }

        return $coords;
    }

    /**
     * @param array<int, mixed> $points
     * @param array{latitude: float, longitude: float} $ref
     * @return array<int, array{0: float, 1: float}>
     */
    private static function localPointListToCoordinates(array $points, array $ref): array
    {
        $coords = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $x = $point['x'] ?? $point['X'] ?? null;
            $y = $point['y'] ?? $point['Y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y)) {
                continue;
            }

            [$lat, $lon] = YarboGeo::localToGps((float) $x, (float) $y, $ref['latitude'], $ref['longitude']);
            if (!YarboGeo::isValidGps($lat, $lon)) {
                continue;
            }
            $coords[] = [$lon, $lat];
        }

        return $coords;
    }

    /**
     * @param array<string, mixed> $responses
     */
    private static function hasAnyData(array $responses): bool
    {
        foreach ($responses as $envelope) {
            if (!is_array($envelope)) {
                continue;
            }

            $data = YarboCodec::decodePayloadField($envelope['data'] ?? null);
            if ($data === []) {
                $data = YarboCodec::decodePayloadField($envelope);
            }

            if ($data !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{latitude: float, longitude: float}|null $ref
     * @return array<int, array<string, mixed>>
     */
    private static function extractFeaturesFromPayload(array $payload, string $source, ?array $ref = null): array
    {
        $features = [];
        self::walkNode($payload, $source, '$', $features, $ref);
        return $features;
    }

    /**
     * @param mixed $node
     * @param array<int, array<string, mixed>> $features
     * @param array{latitude: float, longitude: float}|null $ref
     */
    private static function walkNode(mixed $node, string $source, string $path, array &$features, ?array $ref = null): void
    {
        if (is_array($node)) {
            if (self::looksLikeLocalPointList($node, $ref)) {
                $coords = self::localPointListToCoordinates($node, $ref);
                if (count($coords) >= 3) {
                    $features[] = self::polygonFeature($coords, $source, $path);
                }
                return;
            }

            if (self::looksLikePointList($node)) {
                $coords = self::pointListToCoordinates($node);
                if (count($coords) === 1) {
                    $features[] = self::pointFeature($coords[0], $source, $path);
                } elseif (count($coords) >= 3) {
                    $features[] = self::polygonFeature($coords, $source, $path);
                }
                return;
            }

            foreach ($node as $k => $v) {
                $nextPath = $path . '.' . (is_int($k) ? '[' . $k . ']' : (string) $k);
                self::walkNode($v, $source, $nextPath, $features, $ref);
            }
        }
    }

    /**
     * @param array<int, mixed> $node
     * @param array{latitude: float, longitude: float}|null $ref
     */
    private static function looksLikeLocalPointList(array $node, ?array $ref): bool
    {
        if ($ref === null || $node === [] || !array_is_list($node)) {
            return false;
        }

        $valid = 0;
        foreach ($node as $item) {
            if (!is_array($item)) {
                return false;
            }
            $x = $item['x'] ?? $item['X'] ?? null;
            $y = $item['y'] ?? $item['Y'] ?? null;
            if (is_numeric($x) && is_numeric($y)) {
                $valid++;
            }
        }

        return $valid === count($node);
    }

    /**
     * @param array<int|string, mixed> $node
     */
    private static function looksLikePointList(array $node): bool
    {
        if ($node === [] || !array_is_list($node)) {
            return false;
        }

        $validPointCount = 0;
        foreach ($node as $item) {
            if (!is_array($item)) {
                return false;
            }
            if (self::extractLatLon($item) !== null) {
                $validPointCount++;
            }
        }
        return $validPointCount === count($node);
    }

    /**
     * @param array<int, mixed> $points
     * @return array<int, array{0: float, 1: float}>
     */
    private static function pointListToCoordinates(array $points): array
    {
        $coords = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $latLon = self::extractLatLon($point);
            if ($latLon !== null) {
                $coords[] = [$latLon[1], $latLon[0]]; // GeoJSON [lon, lat]
            }
        }
        return $coords;
    }

    /**
     * @param array<string, mixed> $point
     * @return array{0: float, 1: float}|null
     */
    private static function extractLatLon(array $point): ?array
    {
        $latCandidates = ['lat', 'latitude', 'y'];
        $lonCandidates = ['lon', 'lng', 'longitude', 'x'];

        $lat = null;
        $lon = null;

        foreach ($latCandidates as $k) {
            if (isset($point[$k]) && is_numeric($point[$k])) {
                $lat = (float) $point[$k];
                break;
            }
        }
        foreach ($lonCandidates as $k) {
            if (isset($point[$k]) && is_numeric($point[$k])) {
                $lon = (float) $point[$k];
                break;
            }
        }

        if ($lat === null || $lon === null) {
            return null;
        }

        // Avoid treating local x/y meters as lat/lon.
        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        return [$lat, $lon];
    }

    /**
     * @param array{0: float, 1: float} $coord
     * @param array<string, mixed> $extraProperties
     * @return array<string, mixed>
     */
    private static function pointFeature(array $coord, string $source, string $path, array $extraProperties = []): array
    {
        return [
            'type' => 'Feature',
            'properties' => array_merge([
                'source' => $source,
                'path' => $path,
                'kind' => 'point',
            ], $extraProperties),
            'geometry' => [
                'type' => 'Point',
                'coordinates' => $coord,
            ],
        ];
    }

    /**
     * @param array<int, array{0: float, 1: float}> $coords
     * @param array<string, mixed> $extraProperties
     * @return array<string, mixed>
     */
    private static function lineStringFeature(
        array $coords,
        string $source,
        string $path,
        array $extraProperties = [],
    ): array {
        return [
            'type' => 'Feature',
            'properties' => array_merge([
                'source' => $source,
                'path' => $path,
                'kind' => 'line',
            ], $extraProperties),
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coords,
            ],
        ];
    }

    /**
     * @param array{0: float, 1: float} $coord
     * @param array<string, mixed> $extraProperties
     * @return array<string, mixed>
     */
    private static function polygonFeature(
        array $coords,
        string $source,
        string $path,
        array $extraProperties = [],
    ): array {
        $closed = $coords;
        $first = $closed[0];
        $last = $closed[count($closed) - 1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            $closed[] = $first;
        }

        return [
            'type' => 'Feature',
            'properties' => array_merge([
                'source' => $source,
                'path' => $path,
                'kind' => 'polygon',
            ], $extraProperties),
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$closed],
            ],
        ];
    }
}

