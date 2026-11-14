<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Shared GPS breadcrumb for the active Yarbo mow (cleared when back on dock).
 */
final class YarboMowTrail
{
    public const MAX_POINTS = 2500;
    public const MIN_DISTANCE_M = 0.8;

    public function __construct(private string $projectRoot)
    {
    }

    public function path(): string
    {
        return rtrim($this->projectRoot, '/') . '/data/yarbo-mow-trail.json';
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function read(): array
    {
        return $this->normalizePoints($this->loadUnlocked()['points'] ?? []);
    }

    /**
     * Append or clear from parsed telemetry. Safe for concurrent status polls.
     *
     * @param array<string, mixed> $parsed
     * @return list<array{0: float, 1: float}>
     */
    public function observe(array $parsed): array
    {
        $dir = rtrim($this->projectRoot, '/') . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [];
        }

        $path = $this->path();
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) {
            return $this->read();
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return $this->read();
        }

        try {
            $state = $this->loadUnlocked();
            $points = $this->normalizePoints($state['points'] ?? []);
            $onPad = !empty($parsed['on_charge_pad']);
            $planRunning = !empty($parsed['plan_running']);
            $returning = !empty($parsed['returning_to_dock']);
            $active = $planRunning || $returning;

            if ($onPad && !$planRunning) {
                if ($points !== []) {
                    $this->writeUnlocked([]);
                }

                return [];
            }

            if (!$active) {
                return $points;
            }

            $gpsValid = !empty($parsed['gps_valid']);
            $lat = self::nullableFloat($parsed['latitude'] ?? null);
            $lon = self::nullableFloat($parsed['longitude'] ?? null);
            if (!$gpsValid || $lat === null || $lon === null) {
                return $points;
            }

            $last = $points !== [] ? $points[count($points) - 1] : null;
            if ($last !== null && self::haversineM($last[0], $last[1], $lat, $lon) < self::MIN_DISTANCE_M) {
                return $points;
            }

            $points[] = [$lat, $lon];
            if (count($points) > self::MAX_POINTS) {
                $points = array_values(array_slice($points, -self::MAX_POINTS));
            }
            $this->writeUnlocked($points);

            return $points;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{points: list<mixed>, updated_at: ?string}
     */
    private function loadUnlocked(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['points' => [], 'updated_at' => null];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['points' => [], 'updated_at' => null];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['points' => [], 'updated_at' => null];
        }

        return [
            'points' => is_array($decoded['points'] ?? null) ? $decoded['points'] : [],
            'updated_at' => isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : null,
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private function writeUnlocked(array $points): void
    {
        $payload = json_encode(
            [
                'points' => array_values($points),
                'updated_at' => gmdate('c'),
            ],
            JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return;
        }
        $path = $this->path();
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $payload . "\n", LOCK_EX) === false) {
            return;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * @param mixed $points
     * @return list<array{0: float, 1: float}>
     */
    private function normalizePoints(mixed $points): array
    {
        if (!is_array($points)) {
            return [];
        }
        $out = [];
        foreach ($points as $pt) {
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $lat = self::nullableFloat($pt[0] ?? null);
            $lon = self::nullableFloat($pt[1] ?? null);
            if ($lat === null || $lon === null) {
                continue;
            }
            $out[] = [$lat, $lon];
        }

        return $out;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public static function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
