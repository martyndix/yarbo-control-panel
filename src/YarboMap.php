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

            $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            if ($data === [] && $envelope !== []) {
                $topic = (string) ($envelope['topic'] ?? '');
                if ($topic === '' || $topic === (string) $cmd) {
                    unset($envelope['topic'], $envelope['state'], $envelope['result']);
                    if ($envelope !== []) {
                        $data = $envelope;
                    }
                }
            }
            $hasData = $data !== [];
            $probes[$cmd] = [
                'ok' => true,
                'has_data' => $hasData,
                'data_keys' => array_map('strval', array_keys($data)),
            ];

            if (!$hasData) {
                continue;
            }

            if ($source === null) {
                $source = (string) $cmd;
            }

            if ($cmd === 'get_map' && $ref !== null) {
                $features = array_merge($features, self::extractOfficialMapFeatures($data, $ref));
            }

            $features = array_merge($features, self::extractFeaturesFromPayload($data, (string) $cmd, $ref));
        }

        if ($ref === null) {
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
                    ],
                );
            }
        }

        return $features;
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
            $data = $envelope['data'] ?? null;
            if (is_array($data) && $data !== []) {
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
     * @return array<string, mixed>
     */
    private static function pointFeature(array $coord, string $source, string $path): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'source' => $source,
                'path' => $path,
                'kind' => 'point',
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => $coord,
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

