<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * 30-minute history for climate sensors, heaters, and metering plugs.
 * Per-device JSON ring buffers capped at one year.
 * Samples are [time, °C, humidity, watts, volts, amps]. Climate rows omit the last three.
 */
final class YarboSensorHistory
{
    public const SAMPLE_INTERVAL_S = 1800;
    public const SAMPLE_MIN_GAP_S = 1500;
    public const OUTSIDE_ID = 'outside';
    public const OUTSIDE_MIN_GAP_S = 600;
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
        foreach ($this->homeMeterRows() as $row) {
            $id = $row['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            $byId[$id]['power_w'] = $row['power_w'];
            $byId[$id]['voltage_v'] = $row['voltage_v'];
            $byId[$id]['current_a'] = $row['current_a'];
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
     * @return list<array{id: string, name: string, kind: string, temperature: null, humidity: null, power_w: ?float, voltage_v: ?float, current_a: ?float}>
     */
    private function homeMeterRows(): array
    {
        $byId = [];
        foreach (['home.json' => 'last_devices', 'home-nodes-cache.json' => 'devices'] as $file => $key) {
            foreach ($this->deviceRowsFromJson($file, $key) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mapped = $this->mapMeterRow($row);
                if ($mapped === null) {
                    continue;
                }
                $prev = $byId[$mapped['id']] ?? null;
                if ($prev === null) {
                    $byId[$mapped['id']] = $mapped;
                    continue;
                }
                foreach (['power_w', 'voltage_v', 'current_a'] as $key) {
                    if ($mapped[$key] !== null) {
                        $prev[$key] = $mapped[$key];
                    }
                }
                $byId[$mapped['id']] = $prev;
            }
        }

        return array_values($byId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, kind: string, temperature: null, humidity: null, power_w: ?float, voltage_v: ?float, current_a: ?float}|null
     */
    private function mapMeterRow(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || strtolower(trim((string) ($row['kind'] ?? ''))) !== 'plug') {
            return null;
        }
        $power = self::optionalFloat($row['power_w'] ?? null);
        $volts = self::optionalFloat($row['voltage_v'] ?? null);
        $amps = self::optionalFloat($row['current_a'] ?? null);
        if ($power === null && $volts === null && $amps === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? $id),
            'kind' => 'plug',
            'temperature' => null,
            'humidity' => null,
            'power_w' => $power,
            'voltage_v' => $volts,
            'current_a' => $amps,
        ];
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

        $this->recordCachedOutside($now);
        $sources = $this->listSources();
        $sampled = 0;
        $skipped = 0;
        foreach ($sources as $src) {
            if ($this->appendSample(
                $src['id'],
                $src['name'],
                $src['kind'],
                $src['temperature'] ?? null,
                $src['humidity'] ?? null,
                $now,
                isset($src['power_w']) ? self::optionalFloat($src['power_w']) : null,
                isset($src['voltage_v']) ? self::optionalFloat($src['voltage_v']) : null,
                isset($src['current_a']) ? self::optionalFloat($src['current_a']) : null,
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
        ?int $now = null,
        ?float $powerW = null,
        ?float $voltageV = null,
        ?float $currentA = null,
        ?int $minGap = null,
    ): bool {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        if ($temperature === null && $humidity === null && $powerW === null && $voltageV === null && $currentA === null) {
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
        $minGap ??= self::SAMPLE_MIN_GAP_S;
        if ($lastTs > 0 && ($now - $lastTs) < $minGap) {
            return false;
        }
        $sample = [
            $now,
            $temperature !== null ? round($temperature, 2) : null,
            $humidity !== null ? round($humidity, 1) : null,
        ];
        if ($powerW !== null || $voltageV !== null || $currentA !== null) {
            $sample[] = $powerW !== null ? round($powerW, 1) : null;
            $sample[] = $voltageV !== null ? round($voltageV, 1) : null;
            $sample[] = $currentA !== null ? round($currentA, 3) : null;
        }
        $samples[] = $sample;
        $store['id'] = $id;
        $store['name'] = $name !== '' ? $name : (string) ($store['name'] ?? $id);
        $store['kind'] = $kind !== '' ? $kind : (string) ($store['kind'] ?? 'sensor');
        $store['updated_at'] = $now;
        $store['samples'] = $this->pruneSamples($samples, $now);

        return $this->write($id, $store);
    }

    /**
     * Keep the outdoor temperature from the weather reading.
     * A new reading inside ten minutes does not add another point.
     */
    public function recordOutsideTemperature(?float $temperature, ?int $at = null): bool
    {
        if ($temperature === null || !is_finite($temperature) || $temperature < -80.0 || $temperature > 60.0) {
            return false;
        }

        return $this->appendSample(
            self::OUTSIDE_ID,
            'Outside',
            'weather',
            round($temperature, 1),
            null,
            $at,
            null,
            null,
            null,
            self::OUTSIDE_MIN_GAP_S,
        );
    }

    /**
     * Fetch the current weather, which stores its temperature as it is saved.
     */
    public function sampleOutside(?int $now = null): bool
    {
        try {
            $coords = (new YarboHomeAutomations($this->projectRoot))->coordsPublic();
        } catch (\Throwable) {
            return false;
        }
        $lat = is_numeric($coords['latitude'] ?? null) ? (float) $coords['latitude'] : null;
        $lon = is_numeric($coords['longitude'] ?? null) ? (float) $coords['longitude'] : null;
        try {
            $weather = (new YarboWeather($this->projectRoot))->forCoords($lat, $lon);
        } catch (\Throwable) {
            return false;
        }
        if (!($weather['ok'] ?? false) || !is_numeric($weather['temp_c'] ?? null)) {
            return false;
        }
        $at = (int) ($weather['at'] ?? 0);

        return $this->recordOutsideTemperature((float) $weather['temp_c'], $at > 0 ? $at : ($now ?? time()));
    }

    private function recordCachedOutside(?int $now = null): void
    {
        $now ??= time();
        $path = rtrim($this->projectRoot, '/') . '/' . YarboWeather::CACHE_FILE;
        if (!is_file($path)) {
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_numeric($decoded['temp_c'] ?? null)) {
            return;
        }
        $fetched = (int) ($decoded['fetched_at'] ?? 0);
        if ($fetched <= 0 || ($now - $fetched) > 45 * 60) {
            return;
        }
        $this->recordOutsideTemperature((float) $decoded['temp_c'], $now);
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: ?float}|null
     */
    public static function sampleTuple(array $row): ?array
    {
        if (!array_key_exists(0, $row)) {
            return null;
        }

        return [
            (int) $row[0],
            self::optionalFloat($row[1] ?? null),
            self::optionalFloat($row[2] ?? null),
            self::optionalFloat($row[3] ?? null),
            self::optionalFloat($row[4] ?? null),
            self::optionalFloat($row[5] ?? null),
        ];
    }

    /**
     * @param list<mixed> $samples
     * @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: ?float}>
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
            $point = self::sampleTuple($row);
            if ($point === null) {
                continue;
            }
            if ($point[1] === null && $point[2] === null && $point[3] === null && $point[4] === null && $point[5] === null) {
                continue;
            }
            $out[] = $point;
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
        $keep = [self::safeId(self::OUTSIDE_ID) => true];
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
        $samples = $this->pruneSamples(
            $this->samplesWithClimateSiblings($id, is_array($store['samples'] ?? null) ? $store['samples'] : []),
            $to
        );
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
        $climate = false;
        foreach ($points as $point) {
            if (($point['temperature'] ?? null) !== null || ($point['humidity'] ?? null) !== null) {
                $climate = true;
                break;
            }
        }
        if ($climate && $id !== self::OUTSIDE_ID) {
            $points = $this->withOutsideTemperature($points, $bucket, $to);
        }
        $hasHumidity = false;
        $hasPower = false;
        $hasVoltage = false;
        $hasCurrent = false;
        $hasOutside = false;
        foreach ($points as $p) {
            if (isset($p['humidity']) && $p['humidity'] !== null) {
                $hasHumidity = true;
            }
            if (isset($p['power_w']) && $p['power_w'] !== null) {
                $hasPower = true;
            }
            if (isset($p['voltage_v']) && $p['voltage_v'] !== null) {
                $hasVoltage = true;
            }
            if (isset($p['current_a']) && $p['current_a'] !== null) {
                $hasCurrent = true;
            }
            if (isset($p['outside']) && $p['outside'] !== null) {
                $hasOutside = true;
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
            'has_power' => $hasPower,
            'has_voltage' => $hasVoltage,
            'has_current' => $hasCurrent,
            'has_outside' => $hasOutside,
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
     * Fold temperature and humidity files that used to be separate endpoints.
     *
     * @param list<mixed> $samples
     * @return list<array{0: int, 1: ?float, 2: ?float}>
     */
    private function samplesWithClimateSiblings(string $id, array $samples): array
    {
        if (!preg_match('/^(\d+):\d+$/', $id, $match)) {
            return $samples;
        }
        $node = $match[1];
        $live = [];
        foreach ($this->listSources() as $src) {
            $live[$src['id']] = true;
        }
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return $samples;
        }
        $folded = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $base = basename((string) $path, '.json');
            if (!preg_match('/^(\d+)_(\d+)$/', $base, $file)) {
                continue;
            }
            if ($file[1] !== $node) {
                continue;
            }
            $otherId = $file[1] . ':' . $file[2];
            if ($otherId === $id || isset($live[$otherId])) {
                continue;
            }
            $other = $this->loadRaw((string) $path);
            $extra = is_array($other['samples'] ?? null) ? $other['samples'] : [];
            if ($extra === []) {
                continue;
            }
            $samples = self::mergeClimateSamples($samples, $extra);
            $folded[] = (string) $path;
        }
        if ($folded !== []) {
            $store = $this->load($id);
            $store['id'] = $id;
            $store['samples'] = $samples;
            if ($this->write($id, $store)) {
                foreach ($folded as $path) {
                    if ($path !== $this->pathFor($id)) {
                        @unlink($path);
                    }
                }
            }
        }

        return $samples;
    }

    /**
     * @param list<mixed> $primary
     * @param list<mixed> $extra
     * @return list<array{0: int, 1: ?float, 2: ?float}>
     */
    public static function mergeClimateSamples(array $primary, array $extra): array
    {
        $rows = [];
        foreach ($primary as $row) {
            if (!is_array($row) || !isset($row[0])) {
                continue;
            }
            $tuple = self::sampleTuple($row);
            if ($tuple !== null) {
                $rows[] = $tuple;
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($extra as $row) {
            if (!is_array($row) || !isset($row[0])) {
                continue;
            }
            $incoming = self::sampleTuple($row);
            if ($incoming === null) {
                continue;
            }
            $ts = $incoming[0];
            $temp = $incoming[1];
            $hum = $incoming[2];
            $best = null;
            $bestDelta = 1201;
            foreach ($rows as $i => $have) {
                $delta = abs($have[0] - $ts);
                if ($delta > 1200 || $delta >= $bestDelta) {
                    continue;
                }
                $fills = ($have[1] === null && $temp !== null) || ($have[2] === null && $hum !== null);
                if (!$fills && $delta > 0) {
                    continue;
                }
                $best = $i;
                $bestDelta = $delta;
            }
            if ($best === null) {
                $rows[] = $incoming;
                usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                continue;
            }
            if ($rows[$best][1] === null && $temp !== null) {
                $rows[$best][1] = $temp;
            }
            if ($rows[$best][2] === null && $hum !== null) {
                $rows[$best][2] = $hum;
            }
            for ($i = 3; $i <= 5; $i++) {
                if (($rows[$best][$i] ?? null) === null && $incoming[$i] !== null) {
                    $rows[$best][$i] = $incoming[$i];
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $rows;
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
                'power_w' => $row[3] ?? null,
                'voltage_v' => $row[4] ?? null,
                'current_a' => $row[5] ?? null,
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
        $tz = $this->historyTimezone();
        /** @var array<string, array{t: int, temps: list<float>, hums: list<float>, watts: list<float>, volts: list<float>, amps: list<float>}> $buckets */
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
                    'watts' => [],
                    'volts' => [],
                    'amps' => [],
                ];
            }
            if ($row[1] !== null) {
                $buckets[$day]['temps'][] = (float) $row[1];
            }
            if ($row[2] !== null) {
                $buckets[$day]['hums'][] = (float) $row[2];
            }
            if (($row[3] ?? null) !== null) {
                $buckets[$day]['watts'][] = (float) $row[3];
            }
            if (($row[4] ?? null) !== null) {
                $buckets[$day]['volts'][] = (float) $row[4];
            }
            if (($row[5] ?? null) !== null) {
                $buckets[$day]['amps'][] = (float) $row[5];
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $b) {
            $temps = $b['temps'];
            $hums = $b['hums'];
            $watts = $b['watts'];
            $volts = $b['volts'];
            $amps = $b['amps'];
            $out[] = [
                't' => $b['t'],
                'temperature' => $temps !== [] ? round(array_sum($temps) / count($temps), 2) : null,
                'humidity' => $hums !== [] ? round(array_sum($hums) / count($hums), 1) : null,
                'temp_min' => $temps !== [] ? round(min($temps), 2) : null,
                'temp_max' => $temps !== [] ? round(max($temps), 2) : null,
                'humidity_min' => $hums !== [] ? round(min($hums), 1) : null,
                'humidity_max' => $hums !== [] ? round(max($hums), 1) : null,
                'power_w' => $watts !== [] ? round(array_sum($watts) / count($watts), 1) : null,
                'power_min' => $watts !== [] ? round(min($watts), 1) : null,
                'power_max' => $watts !== [] ? round(max($watts), 1) : null,
                'voltage_v' => $volts !== [] ? round(array_sum($volts) / count($volts), 1) : null,
                'current_a' => $amps !== [] ? round(array_sum($amps) / count($amps), 3) : null,
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
        $outs = [];
        $watts = [];
        $volts = [];
        $amps = [];
        foreach ($points as $p) {
            if (isset($p['temperature']) && is_numeric($p['temperature'])) {
                $temps[] = (float) $p['temperature'];
            }
            if ($hasHumidity && isset($p['humidity']) && is_numeric($p['humidity'])) {
                $hums[] = (float) $p['humidity'];
            }
            if (isset($p['power_w']) && is_numeric($p['power_w'])) {
                $watts[] = (float) $p['power_w'];
            }
            if (isset($p['voltage_v']) && is_numeric($p['voltage_v'])) {
                $volts[] = (float) $p['voltage_v'];
            }
            if (isset($p['current_a']) && is_numeric($p['current_a'])) {
                $amps[] = (float) $p['current_a'];
            }
            if (isset($p['outside']) && is_numeric($p['outside'])) {
                $outs[] = (float) $p['outside'];
            }
        }

        return [
            'temperature' => $this->metricTrend($temps),
            'humidity' => $hasHumidity ? $this->metricTrend($hums) : null,
            'outside' => $this->metricTrend($outs),
            'power_w' => $this->metricTrend($watts, 5.0),
            'voltage_v' => $this->metricTrend($volts, 0.5),
            'current_a' => $this->metricTrend($amps, 0.05),
        ];
    }

    private function historyTimezone(): \DateTimeZone
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

        return $tz;
    }

    /**
     * @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: ?float}>
     */
    private function outsideSamples(int $now): array
    {
        $store = $this->load(self::OUTSIDE_ID);
        $samples = is_array($store['samples'] ?? null) ? $store['samples'] : [];

        return $this->pruneSamples($samples, $now);
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function withOutsideTemperature(array $points, string $bucket, int $now): array
    {
        $samples = $this->outsideSamples($now);
        if ($samples === []) {
            return $points;
        }
        $tz = $bucket === '1d' ? $this->historyTimezone() : null;
        foreach ($points as $index => $point) {
            $t = (int) ($point['t'] ?? 0);
            $points[$index]['outside'] = $bucket === '1d'
                ? $this->outsideOnDay($t, $samples, $tz ?? new \DateTimeZone('Europe/Zurich'))
                : $this->outsideNear($t, $samples);
        }

        return $points;
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: ?float}> $samples
     */
    private function outsideNear(int $t, array $samples): ?float
    {
        $best = null;
        $bestDelta = 40 * 60 + 1;
        foreach ($samples as $row) {
            if ($row[1] === null) {
                continue;
            }
            $delta = abs((int) $row[0] - $t);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = (float) $row[1];
            }
        }
        if ($best === null || $bestDelta > 40 * 60) {
            return null;
        }

        return round($best, 2);
    }

    /**
     * @param list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: ?float}> $samples
     */
    private function outsideOnDay(int $t, array $samples, \DateTimeZone $tz): ?float
    {
        $day = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
        $values = [];
        foreach ($samples as $row) {
            if ($row[1] === null) {
                continue;
            }
            $sampleDay = (new \DateTimeImmutable('@' . (int) $row[0]))->setTimezone($tz)->format('Y-m-d');
            if ($sampleDay === $day) {
                $values[] = (float) $row[1];
            }
        }
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param list<float> $values
     * @return array{latest: ?float, avg: ?float, min: ?float, max: ?float, delta: ?float, slope: string}|null
     */
    private function metricTrend(array $values, float $flat = 0.3): ?array
    {
        if ($values === []) {
            return null;
        }
        $first = $values[0];
        $last = $values[count($values) - 1];
        $delta = round($last - $first, 2);
        $slope = 'flat';
        if ($delta > $flat) {
            $slope = 'up';
        } elseif ($delta < -$flat) {
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
