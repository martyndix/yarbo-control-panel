<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboWeather;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$clear = YarboWeather::mapWmo(0, true);
assert_true($clear['icon'] === 'clear', 'WMO 0 day → clear');
assert_true($clear['label'] === 'Clear', 'WMO 0 day label');

$night = YarboWeather::mapWmo(0, false);
assert_true($night['icon'] === 'clear_night', 'WMO 0 night → clear_night');

$partly = YarboWeather::mapWmo(2, true);
assert_true($partly['icon'] === 'partly_cloudy', 'WMO 2 → partly_cloudy');

$fog = YarboWeather::mapWmo(45, true);
assert_true($fog['icon'] === 'fog', 'WMO 45 → fog');

$drizzle = YarboWeather::mapWmo(51, true);
assert_true($drizzle['icon'] === 'drizzle', 'WMO 51 → drizzle');

$rain = YarboWeather::mapWmo(61, true);
assert_true($rain['icon'] === 'rain' && $rain['label'] === 'Light rain', 'WMO 61 → light rain');

$snow = YarboWeather::mapWmo(71, true);
assert_true($snow['icon'] === 'snow', 'WMO 71 → snow');

$thunder = YarboWeather::mapWmo(95, true);
assert_true($thunder['icon'] === 'thunder', 'WMO 95 → thunder');

$hail = YarboWeather::mapWmo(96, true);
assert_true($hail['icon'] === 'thunder' && str_contains($hail['label'], 'hail'), 'WMO 96 hail');

$root = sys_get_temp_dir() . '/yarbo-weather-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$fetchCount = 0;
$http = static function (string $url) use (&$fetchCount): ?string {
    $fetchCount++;
    assert_true(str_contains($url, 'api.open-meteo.com'), 'Open-Meteo URL');
    assert_true(str_contains($url, 'current=temperature_2m,weather_code,is_day'), 'current fields');

    return json_encode([
        'current' => [
            'temperature_2m' => 12.6,
            'weather_code' => 61,
            'is_day' => 1,
        ],
    ], JSON_UNESCAPED_SLASHES);
};

$weather = new YarboWeather($root, $http);
$miss = $weather->forCoords(null, null);
assert_true($miss['ok'] === false && ($miss['error'] ?? '') === 'no_coords', 'no coords hides weather');

$first = $weather->forCoords(47.37, 8.54);
assert_true($first['ok'] === true, 'fetch ok');
assert_true($first['icon'] === 'rain', 'mapped icon rain');
assert_true(($first['label'] ?? '') === 'Light rain', 'label Light rain');
assert_true(isset($first['temp_c']) && abs((float) $first['temp_c'] - 12.6) < 0.01, 'temp 12.6');
assert_true($fetchCount === 1, 'one network call on miss');

$cachePath = $root . '/' . YarboWeather::CACHE_FILE;
assert_true(is_file($cachePath), 'cache written');

$second = $weather->forCoords(47.37, 8.54);
assert_true($second['ok'] === true && $second['icon'] === 'rain', 'cache hit returns weather');
assert_true($fetchCount === 1, 'cache hit skips network');

$staleWeather = new YarboWeather($root, static function (): ?string {
    return null;
});
$cached = json_decode((string) file_get_contents($cachePath), true);
$cached['fetched_at'] = time() - YarboWeather::CACHE_TTL_SEC - 30;
file_put_contents($cachePath, json_encode($cached, JSON_UNESCAPED_SLASHES));
$stale = $staleWeather->forCoords(47.37, 8.54);
assert_true($stale['ok'] === true && !empty($stale['stale']), 'stale cache used when fetch fails');

file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-automations.json', json_encode([
    'timezone' => 'Europe/Zurich',
    'latitude' => 47.37,
    'longitude' => 8.54,
    'automations' => [],
], JSON_UNESCAPED_SLASHES));
// Fresh cache so dashboard does not hit the network.
file_put_contents($cachePath, json_encode([
    'latitude' => 47.37,
    'longitude' => 8.54,
    'temp_c' => 9.0,
    'weather_code' => 0,
    'is_day' => true,
    'icon' => 'clear',
    'label' => 'Clear',
    'fetched_at' => time(),
    'source' => 'open-meteo',
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$dash = $home->dashboard();
assert_true(isset($dash['weather']) && is_array($dash['weather']), 'dashboard includes weather');
assert_true(!empty($dash['weather']['ok']), 'dashboard weather ok with coords');
assert_true(($dash['weather']['icon'] ?? '') === 'clear', 'dashboard weather icon from cache');

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/style.css');
$statusPhp = (string) file_get_contents(__DIR__ . '/../public/api/status.php');
assert_true(str_contains($index, 'id="panel-weather"'), 'panel weather chip next to title');
assert_true(str_contains($index, 'app-header-brand'), 'brand row holds title + weather');
assert_true(!str_contains($index, 'id="home-weather"'), 'weather not on Home card');
assert_true(str_contains($js, 'renderPanelWeather'), 'JS renders panel weather chip');
assert_true(str_contains($js, 'homeWeatherSvg'), 'JS weather SVGs');
assert_true(str_contains($js, 'data?.weather') || str_contains($js, 'data.weather'), 'status applies weather');
assert_true(str_contains($css, '.panel-weather') || str_contains($css, '.home-weather'), 'weather chip CSS');
assert_true(str_contains($statusPhp, 'YarboWeather'), 'status payload includes weather');
assert_true(str_contains((string) file_get_contents(__DIR__ . '/../CHANGELOG.md'), '## [4.0.320]'), 'changelog 4.0.320');

echo "ok\n";

