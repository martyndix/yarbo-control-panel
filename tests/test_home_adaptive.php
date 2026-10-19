<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboHomeAutomations;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$tz = new DateTimeZone('Europe/Zurich');
$night = (new DateTimeImmutable('2026-06-21 02:00:00', $tz))->getTimestamp();
$noon = (new DateTimeImmutable('2026-06-21 13:00:00', $tz))->getTimestamp();
$kNight = YarboHome::adaptiveKelvinAt($night, 47.37, 8.54, 'Europe/Zurich');
$kNoon = YarboHome::adaptiveKelvinAt($noon, 47.37, 8.54, 'Europe/Zurich');
assert_true($kNight <= 2500, 'night kelvin is warm: ' . $kNight);
assert_true($kNoon >= 4000, 'midday kelvin is cooler: ' . $kNoon);
assert_true($kNight < $kNoon, 'day is cooler than night');
assert_true($kNight % 50 === 0 && $kNoon % 50 === 0, 'kelvin steps of 50');

$clockNight = YarboHome::adaptiveKelvinAt($night, null, null, 'Europe/Zurich');
$clockNoon = YarboHome::adaptiveKelvinAt($noon, null, null, 'Europe/Zurich');
assert_true($clockNight <= 2500 && $clockNoon >= 4000, 'clock fallback still warms at night');

$dawnA = (new DateTimeImmutable('2026-06-21 05:45:00', $tz))->getTimestamp();
$dawnB = (new DateTimeImmutable('2026-06-21 06:15:00', $tz))->getTimestamp();
$kDawnA = YarboHome::adaptiveKelvinAt($dawnA, 47.37, 8.54, 'Europe/Zurich');
$kDawnB = YarboHome::adaptiveKelvinAt($dawnB, 47.37, 8.54, 'Europe/Zurich');
assert_true($kDawnB >= $kDawnA, 'dawn cools gradually: ' . $kDawnA . ' -> ' . $kDawnB);

$root = sys_get_temp_dir() . '/yarbo-adaptive-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['1:2' => 'Lamp'],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [[
        'id' => '1:2',
        'name' => 'Lamp',
        'kind' => 'light',
        'on' => true,
        'color_ct' => true,
        'color_temp_min' => 2000,
        'color_temp_max' => 6500,
    ]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-automations.json', json_encode([
    'timezone' => 'Europe/Zurich',
    'latitude' => 47.37,
    'longitude' => 8.54,
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$saved = $home->saveScene([
    'name' => 'Daylight',
    'actions' => [[
        'id' => '1:2',
        'on' => true,
        'adaptive' => true,
        'brightness' => 80,
        'color_hex' => '#ff0000',
        'color_temp' => 2700,
    ]],
]);
assert_true(($saved['ok'] ?? false) === true, 'scene saves Time of day');
$action = $saved['scene']['actions'][0] ?? [];
assert_true(!empty($action['adaptive']), 'scene member stores adaptive');
assert_true(array_key_exists('color_hex', $action) && $action['color_hex'] === null, 'Time of day drops a fixed colour');
assert_true(array_key_exists('color_temp', $action) && $action['color_temp'] === null, 'Time of day drops a fixed kelvin');
assert_true(($action['brightness'] ?? 0) === 80, 'Time of day keeps brightness');

$store = json_decode((string) file_get_contents($root . '/data/home.json'), true);
$store['active_scene_id'] = $saved['scene']['id'];
file_put_contents($root . '/data/home.json', json_encode($store, JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
assert_true($home->adaptiveDeviceIds() === ['1:2'], 'active scene members follow Time of day');
$updates = $home->adaptiveUpdates($noon, true);
assert_true($updates !== [] && ($updates[0]['id'] ?? '') === '1:2', 'adaptive updates the lamp');
assert_true(($updates[0]['kelvin'] ?? 0) >= 4000, 'scene tick uses daytime kelvin');

$home->rememberAdaptiveApplied(['1:2' => (int) $updates[0]['kelvin']], $noon);
$again = $home->adaptiveUpdates($noon);
assert_true($again === [], 'same kelvin is not sent again');

$commands = [];
$auto = new YarboHomeAutomations($root);
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});
$ticked = $auto->applyAdaptiveLighting($noon);
assert_true(($ticked['applied'] ?? []) === [], 'remembered kelvin skips the next tick');

$home->rememberAdaptiveApplied(['1:2' => 2200], $noon - 120);
$commands = [];
$ticked = $auto->applyAdaptiveLighting($noon);
assert_true(in_array('1:2', $ticked['applied'] ?? [], true), 'later tick sends a new kelvin: ' . json_encode($ticked));
assert_true(($commands[0]['command'] ?? '') === 'color_temp', 'tick sends colour temperature');
assert_true(!empty($commands[0]['adaptive']), 'tick does not pause Time of day');
assert_true(($commands[0]['kelvin'] ?? 0) >= 4000, 'tick kelvin is daytime');

$paused = json_decode((string) file_get_contents($root . '/data/home.json'), true);
$paused['adaptive']['paused'] = ['1:2' => $noon];
file_put_contents($root . '/data/home.json', json_encode($paused, JSON_UNESCAPED_SLASHES));
$home = new YarboHome($root);
assert_true($home->adaptiveDeviceIds() === [], 'moving the slider pauses Time of day');
$home->addAdaptiveExtra('1:2');
assert_true(in_array('1:2', $home->adaptiveDeviceIds(), true), 'Then Time of day unpauses that light');

$autoThen = $home->saveScene(['name' => 'Off day', 'actions' => [['id' => '1:2', 'on' => false]]]);
assert_true(empty($autoThen['scene']['actions'][0]['adaptive'] ?? false), 'off members are not Time of day');

$normalized = (new YarboHomeAutomations($root));
$savedAuto = $normalized->save([
    'name' => 'Morning',
    'enabled' => true,
    'triggers' => [['type' => 'time', 'at' => '07:00']],
    'actions' => [['kind' => 'device', 'id' => '1:2', 'command' => 'on', 'adaptive' => true, 'kelvin' => 2700]],
]);
assert_true(!empty($savedAuto['ok']), 'automation saves Time of day Then: ' . json_encode($savedAuto));
$then = $savedAuto['automation']['actions'][0] ?? [];
assert_true(!empty($then['adaptive']), 'Then keeps adaptive');
assert_true(!isset($then['kelvin']), 'Then Time of day drops a fixed kelvin');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$index = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
assert_true(str_contains($js, 'function homeSceneCanAdaptive'), 'scene editor Time of day helper');
assert_true(str_contains($js, 'data-scene-adaptive'), 'scene member Time of day checkbox');
assert_true(str_contains($js, 'function homeAutoThenCanAdaptive'), 'automation Then Time of day helper');
assert_true(str_contains($js, 'data-auto-adaptive'), 'Then Time of day checkbox');
assert_true(str_contains($js, 'time of day'), 'Then phrase mentions time of day');
assert_true(str_contains($index, 'Time of day'), 'scene editor hint mentions Time of day');
assert_true(str_contains($change, '## [4.0.124]'), 'changelog 4.0.124');
assert_true(str_contains($change, '## [4.0.125]'), 'changelog 4.0.125');

$homePhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');
$autoPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHomeAutomations.php');
assert_true(str_contains($homePhp, 'function adaptiveKelvinAt'), 'kelvin curve is on Home');
assert_true(str_contains($autoPhp, 'function applyAdaptiveLighting'), 'runner keeps updating Time of day');
assert_true(str_contains($autoPhp, "\$home->command(\$payload)"), 'Time of day tick sends color_temp to Matter, not On');
assert_true(!preg_match('/adaptive\'\]\)\) \{\s*\$hex = \'\';\s*\$kelvin = 0;/', $autoPhp), 'Then Time of day must keep kelvin');
assert_true(str_contains($autoPhp, "\$ct['adaptive'] = true"), 'Then Time of day must not pause itself');
assert_true(str_contains($homePhp, '!$caps[\'color_ct\'] && !$adaptive'), 'scene Time of day still sends kelvin');

echo "ok\n";
