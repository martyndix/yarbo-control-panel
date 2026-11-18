<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHub;
use Yarbo\YarboVestaboard;
use Yarbo\YarboWeather;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-vb-weather-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => false,
        'home' => false,
        'unifi' => false,
    ],
    'active_module' => 'yarbo',
    'vestaboard_live' => 'powerwall',
    'house_name' => '',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => false,
    'quiet_timezone' => 'Europe/Zurich',
    'idle_default' => 'powerwall',
    'weather_unit' => 'c',
    'smart_display' => true,
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
$hub = new YarboHub($root);

assert_true($vb->save(['weather_unit' => 'f']) && $vb->load()['weather_unit'] === 'f', 'fahrenheit persists');
assert_true($vb->save(['vestaboard_weather_unit' => 'c']) && $vb->load()['weather_unit'] === 'c', 'celsius persists');
assert_true($vb->save(['idle_default' => 'weather']) && $vb->load()['idle_default'] === 'weather', 'weather idle persists');
assert_true($vb->idleLiveId() === 'weather', 'idle live is weather');
assert_true($vb->save(['idle_default' => 'nope']) && $vb->load()['idle_default'] === 'powerwall', 'unknown idle stays powerwall');
assert_true($vb->idleLiveId() === 'powerwall', 'powerwall idle when the module is on');

$vb->save(['idle_default' => 'weather']);
assert_true($hub->save(['vestaboard_live' => 'not-a-page']), 'invalid live saves');
assert_true($hub->vestaboardLive() === 'weather', 'missing live page uses the weather idle default');

$vb->save(['idle_default' => 'powerwall']);
assert_true($hub->save(['vestaboard_live' => 'not-a-page']), 'invalid live saves again');
assert_true($hub->vestaboardLive() === 'powerwall', 'missing live page uses powerwall when that is the idle default');

assert_true($hub->save(['vestaboard_live' => 'yarbo']), 'back to yarbo');
assert_true($vb->save(['idle_default' => 'weather']), 'choose weather idle');
assert_true($vb->applyIdleDefaultChange('powerwall'), 'saving idle default moves the note');
assert_true($hub->vestaboardLive() === 'weather', 'note is on weather');

$vb->save(['activity_focus' => 'yarbo', 'idle_default' => 'powerwall']);
assert_true($vb->applyIdleDefaultChange('weather') === false, 'smart display keeps the robot page');
assert_true($hub->vestaboardLive() === 'weather', 'covered job does not jump to the new idle page');
$vb->save(['activity_focus' => null, 'live_resume' => null, 'activity_swap_at' => null]);

assert_true($hub->save(['vestaboard_live' => 'weather']), 'show weather');
$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(!empty($r['active']) && $hub->vestaboardLive() === 'yarbo', 'smart display leaves weather for yarbo');
assert_true(($vb->load()['live_resume'] ?? null) === 'weather', 'weather is remembered');
$r = $vb->applyActivityLivePolicy(
    ['mowing' => false, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(empty($r['active']) && $hub->vestaboardLive() === 'weather', 'smart display returns to weather');

file_put_contents($root . '/data/home-automations.json', json_encode([
    'latitude' => 47.37,
    'longitude' => 8.54,
    'timezone' => 'Europe/Zurich',
    'automations' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/' . YarboWeather::CACHE_FILE, json_encode([
    'latitude' => 47.37,
    'longitude' => 8.54,
    'temp_c' => 20.0,
    'weather_code' => 0,
    'is_day' => true,
    'icon' => 'clear',
    'label' => 'Clear',
    'fetched_at' => time(),
], JSON_UNESCAPED_SLASHES));
$vb->save(['weather_unit' => 'c', 'quiet_timezone' => 'Europe/Zurich']);
$hub->save(['vestaboard_live' => 'weather']);
$layout = $vb->layoutForLiveModule();
assert_true(($layout['ok'] ?? false) === true && ($layout['online'] ?? false) === true, 'weather layout online');
$lines = $layout['lines'];
assert_true(str_contains($lines[0], 'WEATHER') && str_contains($lines[0], '20C'), 'celsius on row 1: ' . $lines[0]);
assert_true(str_starts_with(trim($lines[1]), 'CLEAR'), 'clear condition');
$expectDate = strtoupper((new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('D j M'));
assert_true(trim($lines[2]) === $expectDate, 'date on the spare row: ' . $lines[2]);
assert_true(($layout['codes'][1][14] ?? 0) === YarboVestaboard::COLOR_YELLOW, 'clear day is a yellow chip');
assert_true($vb->codesLookLikeLiveStatus($layout['codes']), 'weather page is our own layout');

$vb->save(['weather_unit' => 'f']);
$layout = $vb->weatherLayout();
assert_true(str_contains($layout['lines'][0], '68F'), '20C is 68F: ' . $layout['lines'][0]);

$cache = json_decode((string) file_get_contents($root . '/' . YarboWeather::CACHE_FILE), true);
$cache['icon'] = 'rain';
$cache['label'] = 'Rain';
$cache['is_day'] = true;
file_put_contents($root . '/' . YarboWeather::CACHE_FILE, json_encode($cache));
$layout = $vb->weatherLayout();
assert_true(str_starts_with(trim($layout['lines'][1]), 'RAIN'), 'rain label');
assert_true(($layout['codes'][1][14] ?? 0) === YarboVestaboard::COLOR_BLUE, 'rain is a blue chip');

$cache['icon'] = 'clear_night';
$cache['label'] = 'Clear night';
file_put_contents($root . '/' . YarboWeather::CACHE_FILE, json_encode($cache));
$layout = $vb->weatherLayout();
assert_true(($layout['codes'][1][14] ?? 0) === YarboVestaboard::COLOR_VIOLET, 'clear night is a violet chip');

$cache['temp_c'] = 'bad';
$cache['fetched_at'] = time();
file_put_contents($root . '/' . YarboWeather::CACHE_FILE, json_encode($cache));
$layout = $vb->weatherLayout();
assert_true(($layout['online'] ?? true) === false, 'bad reading is offline');
assert_true(str_contains($layout['lines'][0], 'WEATHER'), 'offline still says weather');
assert_true(str_contains($layout['lines'][1], 'OFFLINE'), 'offline row');
assert_true(str_contains($layout['lines'][2], 'NO FORECAST'), 'no forecast row');
assert_true(($layout['codes'][1][14] ?? 0) === YarboVestaboard::COLOR_RED, 'offline red chip');

$choices = array_column(YarboHub::vestaboardLiveChoices($hub->load()['modules']), 'id');
assert_true(in_array('weather', $choices, true), 'weather is always a live choice');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$docs = (string) file_get_contents(__DIR__ . '/../docs/vestaboard.md');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($html, 'data-vestaboard-live="weather"'), 'note pill');
assert_true(str_contains($html, 'settings-vestaboard-idle'), 'idle default setting');
assert_true(str_contains($html, 'settings-vestaboard-unit'), 'unit setting');
assert_true(str_contains($js, 'vestaboard_idle_default'), 'settings save idle default');
assert_true(str_contains($js, 'vestaboard_weather_unit'), 'settings save unit');
assert_true(str_contains($html, 'data-rotate-view="weather"'), 'rotate includes weather');
assert_true(str_contains($fw, 'return "weather"'), 'paper note weather id');
assert_true(str_contains($fw, 'return "WEATHER"'), 'paper note weather label');
assert_true(str_contains($ver, '0.1.98'), 'paper firmware 0.1.98');
assert_true(str_contains($docs, 'Idle default'), 'docs idle default');
assert_true(str_contains($change, '## [4.0.380]'), 'changelog 4.0.380');

echo "ok: vestaboard weather\n";
