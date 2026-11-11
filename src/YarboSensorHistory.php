<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * 30-minute temperature / humidity history for climate sensors and heaters.
 * Per-device JSON ring buffers capped at one year.
 */
final class YarboSensorHistory
{
    public const SAMPLE_INTERVAL_S = 1800;
    public const SAMPLE_MIN_GAP_S = 1500;
    public const RETENTION_S = 365 * 86400;
    public const MAX_SAMPLES = 18000;
    public const DIR_NAME = 'sensor-history';

    public function __construct(private string $projectRoot)
    {
    }

    public function dir(): string
    {
        return rtrim($this->projectRoot, '/') . '/data/' . self::DIR_NAME;
    }

    public static function safeId(string $id): string
    {
        $id = trim($id);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?? '';
        $safe = trim($safe, '._-');

        return $safe !== '' ? $safe : 'unknown';
    }

    public function pathFor(string $id): string
    {
        return $this->dir() . '/' . self::safeId($id) . '.json';
    }

    public static function optionalFloat(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $n = (float) $raw;
        if (!is_finite($n)) {
            return null;
        }

        return $n;
    }

    /**
     * Live climate sources: UniFi Protect sensors + Home devices with temp.
     *
     * @return list<array{id: string, name: string, kind: string, temperature: ?float, humidity: ?float}>
     */
    public function listSources(): array
    {
        $byId = [];

        try {
            $unifi = new YarboUnifi($this->projectRoot);
            foreach ($unifi->allRows() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $kind = strtolower(trim((string) ($row['kind'] ?? '')));
                if ($kind !== 'sensor') {
                    continue;
                }
                $temp = self::optionalFloat($row['temperature'] ?? null);
                $hum = self::optionalFloat($row['humidity'] ?? null);
                if ($temp === null && $hum === null) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $byId[$id] = [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? $id),
                    'kind' => 'sensor',
                    'temperature' => $temp,
                    'humidity' => $hum,
                ];
            }
        } catch (\Throwable) {
        }

        foreach ($this->homeClimateRows() as $row) {
            $id = $row['id'];
            if (isset($byId[$id])) {
                // Prefer UniFi live temp/humidity when the same id exists.
                continue;
            }
            $byId[$id] = $row;
        }

        return array_values($byId);
    }

    /**
     * @return list<array{id: string, name: string, kind: string, temperature: ?float, humidity: ?float}>
     */
    private function homeClimateRows(): array
    {
        $byId = [];
        foreach ($this->deviceRowsFromJson('home.json', 'last_devices') as $row) {
            $mapped = $this->mapClimateRow($row);
            if ($mapped !== null) {
                $byId[$mapped['id']] = $mapped;
            }
        }
        // Live Matter overlay (heaters + climate sensors) — last_devices used to strip temps.
        foreach ($this->deviceRowsFromJson('home-nodes-cache.json', 'devices') as $row) {
            $mapped = $this->mapClimateRow($row);
            if ($mapped === null) {
                continue;
            }
            $prev = $byId[$mapped['id']] ?? null;
            if ($prev === null
                || ($prev['temperature'] === null && $mapped['temperature'] !== null)
                || ($prev['humidity'] === null && $mapped['humidity'] !== null)
            ) {
                $byId[$mapped['id']] = $mapped;
            }
        }
        try {
            $fabric = YarboMatterFabric::devicesFromDirectory(
                rtrim($this->projectRoot, '/') . '/data/matter-server'
            );
            foreach ($fabric as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mapped = $this->mapClimateRow($row);
                if ($mapped === null) {
                    continue;
                }
                $prev = $byId[$mapped['id']] ?? null;
                if ($prev === null
                    || ($prev['temperature'] === null && $mapped['temperature'] !== null)
                    || ($prev['humidity'] === null && $mapped['humidity'] !== null)
                ) {
                    $byId[$mapped['id']] = $mapped;
                }
            }
        } catch (\Throwable) {
        }

        return array_values($byId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deviceRowsFromJson(string $file, string $key): array
    {
        $path = rtrim($this->projectRoot, '/') . '/data/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded[$key] ?? null)) {
            return [];
        }

        return $decoded[$key];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, kind: string, temperature: ?float, humidity: ?float}|null
     */
    private function mapClimateRow(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $temp = self::optionalFloat($row['temperature'] ?? null);
        if ($temp === null) {
            $temp = self::optionalFloat($row['local_temperature'] ?? null);
        }
        $hum = self::optionalFloat($row['humidity'] ?? null);
        if ($temp === null && $hum === null) {
            return null;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? 'sensor')));
        if ($kind === '') {
            $kind = 'sensor';
        }

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? $id),
            'kind' => $kind,
            'temperature' => $temp,
            'humidity' => $hum,
        ];
    }

    /**
     * Sample all climate sources once (respecting min gap).
     *
     * @return array{ok: bool, sampled: int, skipped: int, sources: int}
     */
    public function sampleOnce(?int $now = null): array
    {
        $now ??= time();
        try {
            $hub = new YarboHub($this->projectRoot);
            if ($hub->enabled(YarboHub::MODULE_UNIFI)) {
                (new YarboUnifi($this->projectRoot))->refreshProtectSensorsNow();
            }
        } catch (\Throwable) {
        }

        $sources = $this->listSources();
        $sampled = 0;
        $skipped = 0;
        foreach ($sources as $src) {
            if ($this->appendSample(
                $src['id'],
                $src['name'],
                $src['kind'],
                $src['temperature'],
                $src['humidity'],
                $now
            )) {
                $sampled++;
            } else {
                $skipped++;
            }
        }
        $this->pruneOrphans(array_map(static fn (array $s): string => $s['id'], $sources));

        return [
            'ok' => true,
            'sampled' => $sampled,
            'skipped' => $skipped,
            'sources' => count($sources),
        ];
    }

    public function appendSample(
        string $id,
        string $name,
        string $kind,
        ?float $temperature,
        ?float $humidity = null,
        ?int $now = null
    ): bool {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        if ($temperature === null && $humidity === null) {
            return false;
        }
        $now ??= time();
        $store = $this->load($id);
        $samples = is_array($store['samples'] ?? null) ? $store['samples'] : [];
        $lastTs = 0;
        if ($samples !== []) {
            $last = $samples[count($samples) - 1];
            $lastTs = is_array($last) ? (int) ($last[0] ?? 0) : 0;
        }
        if ($lastTs > 0 && ($now - $lastTs) < self::SAMPLE_MIN_GAP_S) {
            return false;
        }
        $samples[] = [
            $now,
            $temperature !== null ? round($temperature, 2) : null,
            $humidity !== null ? round($humidity, 1) : null,
        ];
        $store['id'] = $id;
        $store['name'] = $name !== '' ? $name : (string) ($store['name'] ?? $id);
        $store['kind'] = $kind !== '' ? $kind : (string) ($store['kind'] ?? 'sensor');
        $store['updated_at'] = $now;
        $store['samples'] = $this->pruneSamples($samples, $now);

        return $this->write($id, $store);
    }

    /**
     * @param list<mixed> $samples
     * @return list<array{0: int, 1: ?float, 2: ?float}>
     */
    public function pruneSamples(array $samples, ?int $now = null): array
    {
        $now ??= time();
        $cut = $now - self::RETENTION_S;
        $out = [];
        foreach ($samples as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $ts = (int) ($row[0] ?? 0);
            if ($ts < $cut) {
                continue;
            }
            $out[] = [
                $ts,
                self::optionalFloat($row[1] ?? null),
                self::optionalFloat($row[2] ?? null),
            ];
        }
        if (count($out) > self::MAX_SAMPLES) {
            $out = array_slice($out, -self::MAX_SAMPLES);
        }

        return array_values($out);
    }

    /**
     * @param list<string> $liveIds
     */
    public function pruneOrphans(array $liveIds): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return;
        }
        $keep = [];
        foreach ($liveIds as $id) {
            $keep[self::safeId((string) $id)] = true;
        }
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $base = basename((string) $path, '.json');
            if ($base === '' || isset($keep[$base])) {
                continue;
            }
            // Keep orphan files that still have recent samples (device temporarily missing).
            $store = $this->loadRaw($path);
            $updated = (int) ($store['updated_at'] ?? 0);
            if ($updated > 0 && (time() - $updated) < (30 * 86400)) {
                continue;
            }
            @unlink($path);
        }
    }

    /**
     * @return array{ok: bool, id: string, name: string, kind: string, bucket: string, from: int, to: int, has_humidity: bool, points: list<array<string, mixed>>, trends: array<string, mixed>}
     */
    public function series(string $id, int $from, int $to, string $bucket = '30m'): array
    {
        $id = trim($id);
        $store = $this->load($id);
        $name = (string) ($store['name'] ?? $id);
        $kind = (string) ($store['kind'] ?? 'sensor');
        foreach ($this->listSources() as $src) {
            if ($src['id'] === $id) {
                $name = $src['name'];
                $kind = $src['kind'];
                break;
            }
        }
        if ($to <= 0) {
            $to = time();
        }
        if ($from <= 0 || $from >= $to) {
            $from = $to - (7 * 86400);
        }
        $bucket = strtolower(trim($bucket));
        if (!in_array($bucket, ['30m', '1d', 'day', 'daily'], true)) {
            $bucket = '30m';
        }
        if ($bucket === 'day' || $bucket === 'daily') {
            $bucket = '1d';
        }
        $samples = $this->pruneSamples(is_array($store['samples'] ?? null) ? $store['samples'] : [], $to);
        $inRange = [];
        foreach ($samples as $row) {
            $ts = (int) $row[0];
            if ($ts < $from || $ts > $to) {
                continue;
            }
            $inRange[] = $row;
        }
        $points = $bucket === '1d'
            ? $this->dailyPoints($inRange)
            : $this->rawPoints($inRange);
        $hasHumidity = false;
        foreach ($points as $p) {
            if (isset($p['humidity']) && $p['humidity'] !== null) {
                $hasHumidity = true;
                break;
            }
        }

        return [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'bucket' => $bucket,
            'from' => $from,
            'to' => $to,
            'has_humidity' => $hasHumidity,
            'points' => $points,
            'trends' => $this->trends($points, $hasHumidity),
        ];
    }

    public function hasHistory(string $id): bool
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return false;
        }
        $store = $this->load($id);

        return is_array($store['samples'] ?? null) && $store['samples'] !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $id): array
    {
        return $this->loadRaw($this->pathFor($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRaw(string $path): array
    {
        if (!is_file($path)) {
            return ['samples' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['samples' => []];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function write(string $id, array $store): bool
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($store, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $path = $this->pathFor($id);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float}> $rows
     * @return list<array{t: int, temperature: ?float, humidity: ?float}>
     */
    private function rawPoints(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                't' => (int) $row[0],
                'temperature' => $row[1],
                'humidity' => $row[2],
            ];
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float}> $rows
     * @return list<array{t: int, temperature: ?float, humidity: ?float, temp_min: ?float, temp_max: ?float, humidity_min: ?float, humidity_max: ?float}>
     */
    private function dailyPoints(array $rows): array
    {
        $tz = new \DateTimeZone('Europe/Zurich');
        try {
            $vb = new YarboVestaboard($this->projectRoot);
            $cfg = $vb->load();
            $name = trim((string) ($cfg['quiet_timezone'] ?? ''));
            if ($name !== '') {
                $tz = new \DateTimeZone($name);
            }
        } catch (\Throwable) {
        }
        /** @var array<string, array{t: int, temps: list<float>, hums: list<float>}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $ts = (int) $row[0];
            $day = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
            if (!isset($buckets[$day])) {
                $noon = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day . ' 12:00:00', $tz);
                $buckets[$day] = [
                    't' => $noon ? $noon->getTimestamp() : $ts,
                    'temps' => [],
                    'hums' => [],
                ];
            }
            if ($row[1] !== null) {
                $buckets[$day]['temps'][] = (float) $row[1];
            }
            if ($row[2] !== null) {
                $buckets[$day]['hums'][] = (float) $row[2];
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $b) {
            $temps = $b['temps'];
            $hums = $b['hums'];
            $out[] = [
                't' => $b['t'],
                'temperature' => $temps !== [] ? round(array_sum($temps) / count($temps), 2) : null,
                'humidity' => $hums !== [] ? round(array_sum($hums) / count($hums), 1) : null,
                'temp_min' => $temps !== [] ? round(min($temps), 2) : null,
                'temp_max' => $temps !== [] ? round(max($temps), 2) : null,
                'humidity_min' => $hums !== [] ? round(min($hums), 1) : null,
                'humidity_max' => $hums !== [] ? round(max($hums), 1) : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return array<string, mixed>
     */
    private function trends(array $points, bool $hasHumidity): array
    {
        $temps = [];
        $hums = [];
        foreach ($points as $p) {
            if (isset($p['temperature']) && is_numeric($p['temperature'])) {
                $temps[] = (float) $p['temperature'];
            }
            if ($hasHumidity && isset($p['humidity']) && is_numeric($p['humidity'])) {
                $hums[] = (float) $p['humidity'];
            }
        }

        return [
            'temperature' => $this->metricTrend($temps),
            'humidity' => $hasHumidity ? $this->metricTrend($hums) : null,
        ];
    }

    /**
     * @param list<float> $values
     * @return array{latest: ?float, avg: ?float, min: ?float, max: ?float, delta: ?float, slope: string}|null
     */
    private function metricTrend(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        $first = $values[0];
        $last = $values[count($values) - 1];
        $delta = round($last - $first, 2);
        $slope = 'flat';
        if ($delta > 0.3) {
            $slope = 'up';
        } elseif ($delta < -0.3) {
            $slope = 'down';
        }

        return [
            'latest' => round($last, 2),
            'avg' => round(array_sum($values) / count($values), 2),
            'min' => round(min($values), 2),
            'max' => round(max($values), 2),
            'delta' => $delta,
            'slope' => $slope,
        ];
    }
}
