<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * App-set robot nickname: MQTT if present, else cached cloud get_devices() name.
 */
final class YarboRobotName
{
    private const CACHE_TTL_S = 21600;
    private const FAIL_BACKOFF_S = 900;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function cachePath(): string
    {
        return $this->projectRoot . '/data/robot-name.json';
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public function apply(array $parsed, string $serial): array
    {
        $mqtt = $this->clean((string) ($parsed['mqtt_robot_name'] ?? ''));
        $parsed['robot_name'] = $this->resolve($mqtt, $serial);
        unset($parsed['mqtt_robot_name']);

        return $parsed;
    }

    public function resolve(?string $mqttName, string $serial): ?string
    {
        $mqttName = $this->clean($mqttName ?? '');
        if ($mqttName !== null) {
            $this->saveCache($mqttName, 'mqtt', $serial);

            return $mqttName;
        }

        $cache = $this->loadCache();
        $cachedName = $this->clean((string) ($cache['name'] ?? ''));
        $age = $this->cacheAgeSeconds($cache);
        if ($cachedName !== null && $age !== null && $age < self::CACHE_TTL_S) {
            return $cachedName;
        }

        $cloudName = $this->fetchCloudName($serial, $cache);
        if ($cloudName !== null) {
            return $cloudName;
        }

        return $cachedName;
    }

    /**
     * @param list<array<string, mixed>> $devices
     */
    public function rememberCloudDevices(array $devices, string $serial): ?string
    {
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $sn = trim((string) ($device['sn'] ?? ''));
            if ($serial !== '' && $sn !== '' && strcasecmp($sn, $serial) !== 0) {
                continue;
            }
            $name = $this->clean((string) ($device['name'] ?? ''));
            if ($name === null) {
                continue;
            }
            $this->saveCache($name, 'cloud', $sn !== '' ? $sn : $serial);

            return $name;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function fetchCloudName(string $serial, array $cache): ?string
    {
        if ($serial === '') {
            return null;
        }
        $lastFail = isset($cache['last_fail_at']) ? strtotime((string) $cache['last_fail_at']) : false;
        if ($lastFail !== false && (time() - $lastFail) < self::FAIL_BACKOFF_S) {
            return null;
        }

        $settings = new YarboCloudSettings($this->projectRoot . '/data');
        $cloud = new YarboCloud($settings, $this->projectRoot);
        $result = $cloud->fetchRobotName($serial);
        if ($result === null) {
            return null;
        }
        $name = $this->clean((string) ($result['name'] ?? ''));
        if ($name === null) {
            $this->saveFail();

            return null;
        }
        $this->saveCache($name, 'cloud', $serial);

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCache(): array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function cacheAgeSeconds(array $cache): ?int
    {
        $at = $cache['updated_at'] ?? null;
        if (!is_string($at) || $at === '') {
            return null;
        }
        $ts = strtotime($at);

        return $ts === false ? null : time() - $ts;
    }

    private function saveCache(string $name, string $source, string $serial): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'name' => $name,
            'source' => $source,
            'serial' => $serial,
            'updated_at' => gmdate('c'),
            'last_fail_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        file_put_contents($this->cachePath(), $payload . "\n", LOCK_EX);
    }

    private function saveFail(): void
    {
        $cache = $this->loadCache();
        $cache['last_fail_at'] = gmdate('c');
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            return;
        }
        $payload = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        file_put_contents($this->cachePath(), $payload . "\n", LOCK_EX);
    }

    private function clean(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 48) {
            return null;
        }
        if (preg_match('/^[0-9A-Fa-f]{8,}$/', $name) === 1) {
            return null;
        }

        return $name;
    }
}
