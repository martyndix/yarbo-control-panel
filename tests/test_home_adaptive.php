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

$offStore = json_decode((string) file_get_contents($root . '/data/home.json'), true);
$offStore['last_devices'][0]['on'] = false;
file_put_contents($root . '/data/home.json', json_encode($offStore, JSON_UNESCAPED_SLASHES));
@unlink($root . '/data/home-nodes-cache.json');
$home = new YarboHome($root);
$home->addAdaptiveExtra('1:2');
$home->rememberAdaptiveApplied(['1:2' => 2200], $noon - 120);
assert_true($home->adaptiveUpdates($noon, true) === [], 'Time of day does not nudge a light that is off');
$offStore['last_devices'][0]['on'] = true;
file_put_contents($root . '/data/home.json', json_encode($offStore, JSON_UNESCAPED_SLASHES));
$home = new YarboHome($root);

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
assert_true(str_contains($change, '## [4.0.126]'), 'changelog 4.0.126');
assert_true(str_contains($change, '## [4.0.127]'), 'changelog 4.0.127');

$homePhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');
$autoPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHomeAutomations.php');
assert_true(str_contains($homePhp, 'function adaptiveKelvinAt'), 'kelvin curve is on Home');
assert_true(str_contains($autoPhp, 'function applyAdaptiveLighting'), 'runner keeps updating Time of day');
assert_true(str_contains($autoPhp, 'commandAdaptiveColor'), 'Time of day tick sends colour temp or RGB white, not On');
assert_true(!str_contains($autoPhp, "\$hex = '';\n        \$kelvin = 0;"), 'Then Time of day must keep kelvin');
assert_true(str_contains($homePhp, "'adaptive' => true"), 'Time of day commands must not pause themselves');
assert_true(str_contains($homePhp, "\$body['adaptive'] = true"), 'Time of day forwards adaptive to the Matter agent');
assert_true(str_contains($homePhp, 'Time of day must not fall back to On'), 'failed colour temp must not become On');
assert_true(str_contains($homePhp, '!$caps[\'color_ct\'] && !$adaptive'), 'scene Time of day still sends kelvin');
assert_true(str_contains($homePhp, 'function kelvinToHex'), 'colour lights get a white from kelvin');
assert_true(str_contains($homePhp, 'function commandAdaptiveFollow'), 'light tiles can set Time of day');
assert_true(str_contains($homePhp, 'function adaptiveIdsAmong'), 'group Time of day only hits colour lights');
assert_true(str_contains($homePhp, "\$payload['adaptive'] = !empty(\$input['adaptive'])"), 'group command forwards Time of day');
assert_true(!preg_match('/\$action === \'off\'\) \{\s*\$this->clearAdaptiveDevice/', $homePhp), 'Off keeps Time of day so it can resume');
assert_true(str_contains($js, 'function homeCanAdaptive'), 'colour lights can use Time of day');
assert_true(str_contains($js, 'data-home-adaptive'), 'light tile Time of day checkbox');
assert_true(str_contains($js, 'function homeMembersDimmable'), 'group brightness uses dimmable members');
assert_true(str_contains($js, 'function homeClusterAdaptiveHtml'), 'group headings can tick Time of day');
assert_true(str_contains($js, 'data-home-group-adaptive'), 'group heading Time of day checkbox');
assert_true(str_contains($js, 'data-home-room-adaptive'), 'room heading Time of day checkbox');
assert_true(str_contains($js, "command: 'adaptive'"), 'group Time of day posts adaptive');
assert_true(str_contains($js, 'function homeBrightControlHtml'), 'brightness slider wraps a percent');
assert_true(str_contains($js, 'home-bright-pct'), 'brightness percent label');
assert_true(str_contains($js, 'function syncHomeBrightPct'), 'percent updates while dragging');

$warmHex = YarboHome::kelvinToHex(2200);
$coolHex = YarboHome::kelvinToHex(5000);
assert_true(preg_match('/^#[0-9a-f]{6}$/', $warmHex) === 1, 'warm white is a hex colour');
assert_true(preg_match('/^#[0-9a-f]{6}$/', $coolHex) === 1, 'cool white is a hex colour');
$warmB = hexdec(substr($warmHex, 5, 2));
$coolB = hexdec(substr($coolHex, 5, 2));
assert_true($coolB > $warmB, 'daytime white is cooler than night: ' . $warmHex . ' vs ' . $coolHex);

$home->addAdaptiveExtra('1:2');
assert_true(in_array('1:2', $home->adaptiveDeviceIds(), true), 'tile Time of day stays in extra');
$colorScene = $home->saveScene([
    'name' => 'Play',
    'actions' => [[
        'id' => '1:2',
        'on' => true,
        'adaptive' => true,
        'color_hex' => '#00ff00',
    ]],
]);
assert_true(!empty($colorScene['scene']['actions'][0]['adaptive']), 'colour lights can save Time of day in a scene');

$groupRoot = sys_get_temp_dir() . '/yarbo-group-tod-' . bin2hex(random_bytes(3));
mkdir($groupRoot . '/data', 0775, true);
file_put_contents($groupRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($groupRoot . '/data/home.json', json_encode([
    'names' => [
        '1:2' => 'Lamp 2',
        '1:3' => 'Lamp 3',
        '1:4' => 'Lamp',
        '1:5' => 'On only',
    ],
    'room_defs' => [['id' => 'r1', 'name' => 'Lounge']],
    'rooms' => ['1:2' => 'r1', '1:3' => 'r1', '1:4' => 'r1', '1:5' => 'r1'],
    'group_defs' => [['id' => 'g-lamps', 'name' => 'Lamps', 'room_id' => 'r1']],
    'groups' => ['1:2' => 'g-lamps', '1:3' => 'g-lamps', '1:4' => 'g-lamps', '1:5' => 'g-lamps'],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '1:2', 'name' => 'Lamp 2', 'kind' => 'light', 'on' => false, 'color_ct' => true, 'dimmable' => true, 'brightness' => 40],
        ['id' => '1:3', 'name' => 'Lamp 3', 'kind' => 'light', 'on' => false, 'colorable' => true, 'dimmable' => true, 'brightness' => 40],
        ['id' => '1:4', 'name' => 'Lamp', 'kind' => 'plug', 'on' => true],
        ['id' => '1:5', 'name' => 'On only', 'kind' => 'light', 'on' => false],
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($groupRoot . '/data/home-automations.json', json_encode([
    'timezone' => 'Europe/Zurich',
    'latitude' => 47.37,
    'longitude' => 8.54,
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$groupHome = new YarboHome($groupRoot);
$grouped = $groupHome->commandGroup(['group_id' => 'g-lamps', 'command' => 'adaptive', 'adaptive' => true]);
assert_true(($grouped['ok'] ?? false) === true, 'group Time of day succeeds: ' . json_encode($grouped));
$following = $groupHome->adaptiveDeviceIds();
sort($following);
assert_true($following === ['1:2', '1:3'], 'group Time of day hits colour lamps only: ' . json_encode($following));

$cleared = $groupHome->commandGroup(['group_id' => 'g-lamps', 'command' => 'adaptive', 'adaptive' => false]);
assert_true(($cleared['ok'] ?? false) === true, 'group can clear Time of day: ' . json_encode($cleared));
assert_true($groupHome->adaptiveDeviceIds() === [], 'unticking the group clears Time of day');

$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '.home-bright-pct'), 'brightness percent has styles');
assert_true(str_contains($change, '## [4.0.146]'), 'changelog 4.0.146');
assert_true(str_contains($change, '## [4.0.156]'), 'changelog 4.0.156');
$agentPy = (string) file_get_contents(dirname(__DIR__) . '/scripts/matter_agent.py');
assert_true(str_contains($agentPy, 'def color_command_should_turn_on'), 'Matter skips On for Time of day');
assert_true(str_contains($agentPy, 'AGENT_VERSION = 57'), 'Matter agent version 57');

echo "ok\n";
