<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Display name for the robot: Settings first, then MQTT/cloud if that is not the serial.
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
        $mqtt = $this->clean((string) ($parsed['mqtt_robot_name'] ?? ''), $serial);
        $parsed['robot_name'] = $this->resolve($mqtt, $serial);
        unset($parsed['mqtt_robot_name']);

        return $parsed;
    }

    public function resolve(?string $mqttName, string $serial): ?string
    {
        $cache = $this->loadCache();
        $settingsName = $this->clean((string) ($cache['settings_name'] ?? ''), $serial);
        if ($settingsName !== null) {
            return $settingsName;
        }

        $mqttName = $this->clean($mqttName ?? '', $serial);
        if ($mqttName !== null) {
            $this->saveDiscovered($mqttName, 'mqtt', $serial);

            return $mqttName;
        }

        $cachedName = $this->clean((string) ($cache['name'] ?? ''), $serial);
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
     * Name entered in Settings (not MQTT/cloud).
     */
    public function settingsName(string $serial): ?string
    {
        $cache = $this->loadCache();

        return $this->clean((string) ($cache['settings_name'] ?? ''), $serial);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveFromInput(array $input, string $serial): bool
    {
        if (!array_key_exists('robot_name', $input) && !array_key_exists('settings_name', $input)) {
            return true;
        }

        $raw = $input['robot_name'] ?? $input['settings_name'] ?? '';
        $name = $this->clean(is_string($raw) || is_numeric($raw) ? (string) $raw : '', $serial);
        $cache = $this->loadCache();
        $cache['settings_name'] = $name;
        $cache['serial'] = $serial;
        $cache['updated_at'] = gmdate('c');
        if ($name !== null) {
            $cache['name'] = $name;
            $cache['source'] = 'settings';
            $cache['last_fail_at'] = null;
        } elseif (($cache['source'] ?? '') === 'settings') {
            $cache['name'] = null;
            $cache['source'] = null;
        }

        return $this->writeCache($cache);
    }

    /**
     * @param list<array<string, mixed>> $devices
     */
    public function rememberCloudDevices(array $devices, string $serial): ?string
    {
        $settingsName = $this->settingsName($serial);
        if ($settingsName !== null) {
            return $settingsName;
        }

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $sn = trim((string) ($device['sn'] ?? ''));
            if ($serial !== '' && $sn !== '' && strcasecmp($sn, $serial) !== 0) {
                continue;
            }
            $name = $this->clean((string) ($device['name'] ?? ''), $serial !== '' ? $serial : $sn);
            if ($name === null) {
                continue;
            }
            $this->saveDiscovered($name, 'cloud', $sn !== '' ? $sn : $serial);

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
        $name = $this->clean((string) ($result['name'] ?? ''), $serial);
        if ($name === null) {
            $this->saveFail();

            return null;
        }
        $this->saveDiscovered($name, 'cloud', $serial);

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

    private function saveDiscovered(string $name, string $source, string $serial): void
    {
        if ($this->settingsName($serial) !== null) {
            return;
        }
        $cache = $this->loadCache();
        $cache['name'] = $name;
        $cache['source'] = $source;
        $cache['serial'] = $serial;
        $cache['updated_at'] = gmdate('c');
        $cache['last_fail_at'] = null;
        $this->writeCache($cache);
    }

    private function saveFail(): void
    {
        $cache = $this->loadCache();
        $cache['last_fail_at'] = gmdate('c');
        $this->writeCache($cache);
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function writeCache(array $cache): bool
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $payload = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        return file_put_contents($this->cachePath(), $payload . "\n", LOCK_EX) !== false;
    }

    public function clean(string $name, string $serial = ''): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 48) {
            return null;
        }
        if ($serial !== '' && strcasecmp($name, $serial) === 0) {
            return null;
        }
        if (self::looksLikeSerial($name)) {
            return null;
        }

        return $name;
    }

    public static function looksLikeSerial(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (preg_match('/^[0-9A-Fa-f]{8,}$/', $name) === 1) {
            return true;
        }

        // Yarbo SNs are 16 alphanumerics, typically 8 digits then mixed.
        return preg_match('/^[0-9]{8}[0-9A-Za-z]{8}$/', $name) === 1;
    }
}
