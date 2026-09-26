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
            'features' => $features,
        ];
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

        foreach ($features as $index => $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $path = (string) ($feature['properties']['path'] ?? '');
            $parsed = self::parseMapPath($path);
            if ($parsed === null) {
                $errors[] = sprintf('Feature %d is missing a map path (areas[0], pathways[1], …)', $index);
                continue;
            }
            [$list, $listIndex] = $parsed;

            if ($list === 'chargingData') {
                $zone = is_array($encoded['chargingData'] ?? null) ? $encoded['chargingData'] : null;
                if (!is_array($zone)) {
                    $errors[] = 'Draft charging point has no matching chargingData on the original map';
                    continue;
                }
                $result = self::encodeChargingPoint($zone, $feature);
                if ($result['error'] !== null) {
                    $errors[] = $result['error'];
                    continue;
                }
                $encoded['chargingData'] = $result['zone'];
                $maxDelta = max($maxDelta, $result['delta_m']);
                continue;
            }

            if ($list === 'allchargingData') {
                $zones = is_array($encoded['allchargingData'] ?? null) ? $encoded['allchargingData'] : [];
                if (!isset($zones[$listIndex]) || !is_array($zones[$listIndex])) {
                    $errors[] = sprintf('Draft charging station %s is not on the original map', $path);
                    continue;
                }
                $result = self::encodeChargingPoint($zones[$listIndex], $feature);
                if ($result['error'] !== null) {
                    $errors[] = $result['error'];
                    continue;
                }
                $encoded['allchargingData'][$listIndex] = $result['zone'];
                $maxDelta = max($maxDelta, $result['delta_m']);
                continue;
            }

            $zones = is_array($encoded[$list] ?? null) ? $encoded[$list] : [];
            if (!isset($zones[$listIndex]) || !is_array($zones[$listIndex])) {
                $errors[] = sprintf('Draft zone %s is not on the original map', $path);
                continue;
            }
            $kind = (string) ($feature['properties']['kind'] ?? ($feature['geometry']['type'] ?? ''));
            $isLine = $kind === 'line' || $kind === 'LineString';
            $result = self::encodeZoneRange($zones[$listIndex], $feature, $isLine);
            if ($result['error'] !== null) {
                $errors[] = $path . ': ' . $result['error'];
                continue;
            }
            $encoded[$list][$listIndex] = $result['zone'];
            $maxDelta = max($maxDelta, $result['delta_m']);
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'max_delta_m' => $maxDelta];
        }

        return ['ok' => true, 'map' => $encoded, 'errors' => [], 'max_delta_m' => $maxDelta];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parseMapPath(string $path): ?array
    {
        if ($path === 'chargingData') {
            return ['chargingData', 0];
        }
        if (preg_match('/^(areas|nogozones|novisionzones|elec_fence|pathways|sidewalks|deadends|allchargingData)\[(\d+)\]$/', $path, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $feature
     * @return array{zone: array<string, mixed>, delta_m: float, error: ?string}
     */
    private static function encodeZoneRange(array $zone, array $feature, bool $isLine): array
    {
        $ref = YarboGeo::extractGpsRef($zone);
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
        foreach ($polygonTypes as $key => $zoneType) {
            $features = array_merge($features, self::extractAppZones($mapData, $key, $zoneType, 'polygon'));
        }
        foreach ($lineTypes as $key => $zoneType) {
            $features = array_merge($features, self::extractAppZones($mapData, $key, $zoneType, 'line'));
        }

        $charging = $mapData['chargingData'] ?? null;
        if (is_array($charging)) {
            $point = self::chargingPointFeature($charging, 'chargingData');
            if ($point !== null) {
                $features[] = $point;
            }
        }
        $allCharging = $mapData['allchargingData'] ?? null;
        if (is_array($allCharging)) {
            foreach ($allCharging as $index => $station) {
                if (!is_array($station)) {
                    continue;
                }
                $point = self::chargingPointFeature($station, sprintf('allchargingData[%d]', (int) $index));
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

        $minPoints = $geometry === 'line' ? 2 : 3;
        $features = [];
        foreach ($zones as $index => $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $zoneRef = YarboGeo::extractGpsRef($zone);
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

            $props = [
                'zone_type' => $zoneType,
                'zone_id' => $zone['id'] ?? $index,
                'name' => $zone['name'] ?? null,
                'map_list' => $key,
            ];
            $path = sprintf('%s[%d]', $key, (int) $index);
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

