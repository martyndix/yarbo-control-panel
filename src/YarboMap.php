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
    public static function normalize(array $responses): array
    {
        $warnings = [];
        $features = [];
        $source = null;
        $probes = [];

        foreach ($responses as $cmd => $envelope) {
            $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
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

            $features = array_merge($features, self::extractFeaturesFromPayload($data, (string) $cmd));
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
            'warnings' => $warnings,
            'probes' => $probes,
            'feature_collection' => [
                'type' => 'FeatureCollection',
                'features' => $features,
            ],
        ];
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
     * Recursively scan payload for lat/lon-like point arrays and build geometry.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function extractFeaturesFromPayload(array $payload, string $source): array
    {
        $features = [];
        self::walkNode($payload, $source, '$', $features);
        return $features;
    }

    /**
     * @param mixed $node
     * @param array<int, array<string, mixed>> $features
     */
    private static function walkNode(mixed $node, string $source, string $path, array &$features): void
    {
        if (is_array($node)) {
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
                self::walkNode($v, $source, $nextPath, $features);
            }
        }
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
     * @param array<int, array{0: float, 1: float}> $coords
     * @return array<string, mixed>
     */
    private static function polygonFeature(array $coords, string $source, string $path): array
    {
        $closed = $coords;
        $first = $closed[0];
        $last = $closed[count($closed) - 1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            $closed[] = $first;
        }

        return [
            'type' => 'Feature',
            'properties' => [
                'source' => $source,
                'path' => $path,
                'kind' => 'polygon',
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$closed],
            ],
        ];
    }
}

