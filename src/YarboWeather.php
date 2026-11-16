<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Current weather at panel coords (Open-Meteo, no API key).
 * Cached on disk so Home polls do not hammer the API.
 */
final class YarboWeather
{
    public const CACHE_TTL_SEC = 720; // 12 minutes
    public const CACHE_FILE = 'data/weather-cache.json';

    private string $projectRoot;

    /** @var callable(string): (?string)|null */
    private $httpGet;

    /**
     * @param callable(string): (?string)|null $httpGet
     */
    public function __construct(string $projectRoot, ?callable $httpGet = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->httpGet = $httpGet;
    }

    /**
     * Public Home payload. Missing coords or failed fetch → ok false (UI hides chip).
     *
     * @return array{ok: bool, icon?: string, label?: string, temp_c?: float|null, is_day?: bool, at?: int, error?: string}
     */
    public function forCoords(?float $lat, ?float $lon): array
    {
        if ($lat === null || $lon === null || !is_finite($lat) || !is_finite($lon)) {
            return ['ok' => false, 'error' => 'no_coords'];
        }
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return ['ok' => false, 'error' => 'bad_coords'];
        }

        $cached = $this->readCache();
        if ($this->cacheFresh($cached, $lat, $lon)) {
            return $this->publicFromCache($cached);
        }

        $fetched = $this->fetchOpenMeteo($lat, $lon);
        if ($fetched === null) {
            if (is_array($cached) && isset($cached['icon'], $cached['temp_c'])) {
                // Stale but usable when the network is down.
                $out = $this->publicFromCache($cached);
                $out['stale'] = true;

                return $out;
            }

            return ['ok' => false, 'error' => 'fetch_failed'];
        }

        $this->writeCache($fetched);

        return $this->publicFromCache($fetched);
    }

    /**
     * Map WMO weather code (+ day/night) to a stable icon key and short label.
     *
     * @return array{icon: string, label: string}
     */
    public static function mapWmo(int $code, bool $isDay = true): array
    {
        return match (true) {
            $code === 0 => [
                'icon' => $isDay ? 'clear' : 'clear_night',
                'label' => $isDay ? 'Clear' : 'Clear night',
            ],
            $code === 1 => [
                'icon' => $isDay ? 'mainly_clear' : 'clear_night',
                'label' => $isDay ? 'Mostly clear' : 'Mostly clear',
            ],
            $code === 2 => [
                'icon' => 'partly_cloudy',
                'label' => 'Partly cloudy',
            ],
            $code === 3 => [
                'icon' => 'cloudy',
                'label' => 'Overcast',
            ],
            in_array($code, [45, 48], true) => [
                'icon' => 'fog',
                'label' => 'Fog',
            ],
            in_array($code, [51, 53, 55, 56, 57], true) => [
                'icon' => 'drizzle',
                'label' => in_array($code, [56, 57], true) ? 'Freezing drizzle' : 'Drizzle',
            ],
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => [
                'icon' => 'rain',
                'label' => match ($code) {
                    66, 67 => 'Freezing rain',
                    80, 81, 82 => 'Rain showers',
                    63, 65 => 'Rain',
                    default => 'Light rain',
                },
            ],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => [
                'icon' => 'snow',
                'label' => in_array($code, [85, 86], true) ? 'Snow showers' : 'Snow',
            ],
            in_array($code, [95, 96, 99], true) => [
                'icon' => 'thunder',
                'label' => in_array($code, [96, 99], true) ? 'Thunderstorm with hail' : 'Thunderstorm',
            ],
            default => [
                'icon' => 'cloudy',
                'label' => 'Clouds',
            ],
        };
    }

    /**
     * @param array<string, mixed>|null $cached
     */
    private function cacheFresh(?array $cached, float $lat, float $lon): bool
    {
        if ($cached === null) {
            return false;
        }
        $at = (int) ($cached['fetched_at'] ?? 0);
        if ($at <= 0 || (time() - $at) > self::CACHE_TTL_SEC) {
            return false;
        }
        $cLat = isset($cached['latitude']) ? (float) $cached['latitude'] : null;
        $cLon = isset($cached['longitude']) ? (float) $cached['longitude'] : null;
        if ($cLat === null || $cLon === null) {
            return false;
        }
        // ~1 km tolerance so tiny GPS jitter does not bust the cache.
        if (abs($cLat - $lat) > 0.01 || abs($cLon - $lon) > 0.01) {
            return false;
        }

        return isset($cached['icon'], $cached['temp_c']);
    }

    /**
     * @param array<string, mixed> $cached
     * @return array{ok: bool, icon: string, label: string, temp_c: float|null, is_day: bool, at: int}
     */
    private function publicFromCache(array $cached): array
    {
        $temp = $cached['temp_c'] ?? null;
        $tempOut = is_numeric($temp) ? round((float) $temp, 1) : null;

        return [
            'ok' => true,
            'icon' => (string) ($cached['icon'] ?? 'cloudy'),
            'label' => (string) ($cached['label'] ?? ''),
            'temp_c' => $tempOut,
            'is_day' => !empty($cached['is_day']),
            'at' => (int) ($cached['fetched_at'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOpenMeteo(float $lat, float $lon): ?array
    {
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%.5f&longitude=%.5f&current=temperature_2m,weather_code,is_day',
            $lat,
            $lon
        );
        $raw = $this->httpGet($url);
        if ($raw === null || $raw === '') {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        $current = $json['current'] ?? null;
        if (!is_array($current)) {
            return null;
        }
        $code = (int) ($current['weather_code'] ?? -1);
        if ($code < 0) {
            return null;
        }
        $isDay = ((int) ($current['is_day'] ?? 1)) === 1;
        $mapped = self::mapWmo($code, $isDay);
        $temp = $current['temperature_2m'] ?? null;

        return [
            'latitude' => $lat,
            'longitude' => $lon,
            'temp_c' => is_numeric($temp) ? (float) $temp : null,
            'weather_code' => $code,
            'is_day' => $isDay,
            'icon' => $mapped['icon'],
            'label' => $mapped['label'],
            'fetched_at' => time(),
            'source' => 'open-meteo',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = $this->projectRoot . '/' . self::CACHE_FILE;
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(array $data): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $this->projectRoot . '/' . self::CACHE_FILE;
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    private function httpGet(string $url): ?string
    {
        if ($this->httpGet !== null) {
            $fn = $this->httpGet;

            return $fn($url);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'YarboControlPanel/weather',
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
                return null;
            }

            return $raw;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: YarboControlPanel/weather\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }
}
