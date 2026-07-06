<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Coordinate helpers compatible with Yarbo local map frames.
 *
 * @see https://pypi.org/project/yarbo-data-sdk/
 */
final class YarboGeo
{
    /**
     * @param array<string, mixed> $gpsRefEnvelope data_feedback envelope from read_gps_ref
     * @return array{latitude: float, longitude: float}|null
     */
    public static function extractGpsRef(array $gpsRefEnvelope): ?array
    {
        $data = is_array($gpsRefEnvelope['data'] ?? null) ? $gpsRefEnvelope['data'] : $gpsRefEnvelope;
        $ref = is_array($data['ref'] ?? null) ? $data['ref'] : $data;

        $lat = self::firstNumeric(
            $ref['latitude'] ?? null,
            $ref['lat'] ?? null,
            $data['latitude'] ?? null,
            $data['lat'] ?? null,
        );
        $lon = self::firstNumeric(
            $ref['longitude'] ?? null,
            $ref['lon'] ?? null,
            $ref['lng'] ?? null,
            $data['longitude'] ?? null,
            $data['lon'] ?? null,
            $data['lng'] ?? null,
        );

        if ($lat === null || $lon === null) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lon];
    }

    /**
     * @param array<string, mixed> $mapData decoded get_map payload
     * @return array{latitude: float, longitude: float}|null
     */
    public static function extractGpsRefFromMapData(array $mapData): ?array
    {
        foreach (['areas', 'pathways', 'clean_area_list', 'path_area_list'] as $key) {
            $zones = $mapData[$key] ?? null;
            if (!is_array($zones)) {
                continue;
            }

            foreach ($zones as $zone) {
                if (!is_array($zone)) {
                    continue;
                }

                $ref = self::extractGpsRef($zone);
                if ($ref !== null) {
                    return $ref;
                }
            }
        }

        return null;
    }

    /**
     * Convert local meters (x east, y north) to WGS84 using a GPS reference origin.
     *
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    public static function localToGps(float $x, float $y, float $refLat, float $refLon): array
    {
        $metersPerDegLat = 111_320.0;
        $cosLat = cos(deg2rad($refLat));
        $metersPerDegLon = abs($cosLat) < 1e-9 ? 1e-9 : 111_320.0 * $cosLat;

        $lat = $refLat + ($y / $metersPerDegLat);
        $lon = $refLon + ($x / $metersPerDegLon);

        if (!is_finite($lat) || !is_finite($lon)) {
            return [$refLat, $refLon];
        }

        return [$lat, $lon];
    }

    /**
     * Convert WGS84 to local meters (x east, y north) relative to a GPS reference origin.
     *
     * @return array{0: float, 1: float} [x, y]
     */
    public static function gpsToLocal(float $lat, float $lon, float $refLat, float $refLon): array
    {
        $metersPerDegLat = 111_320.0;
        $cosLat = cos(deg2rad($refLat));
        $metersPerDegLon = abs($cosLat) < 1e-9 ? 1e-9 : 111_320.0 * $cosLat;

        $y = ($lat - $refLat) * $metersPerDegLat;
        $x = ($lon - $refLon) * $metersPerDegLon;

        return [$x, $y];
    }

    public static function isValidGps(float $lat, float $lon): bool
    {
        return is_finite($lat)
            && is_finite($lon)
            && abs($lat) <= 90
            && abs($lon) <= 180;
    }

    private static function firstNumeric(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
