<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$tz = 'Europe/Zurich';
$noon = (new DateTimeImmutable('2026-10-21 13:04:00', new DateTimeZone($tz)))->getTimestamp();
assert_true(
    YarboHome::lastTriggerText(true, 'home', '', $noon, $tz) === 'On by Home · 13:04',
    'Home tap formats On by Home'
);
assert_true(
    YarboHome::lastTriggerText(false, 'scene', 'Evening', $noon, $tz) === 'Off by scene Evening · 13:04',
    'scene off formats with the name'
);
assert_true(
    YarboHome::lastTriggerText(true, 'automation', 'Porch motion', $noon, $tz) === 'On by automation Porch motion · 13:04',
    'automation on formats with the name'
);
assert_true(
    YarboHome::lastTriggerText(true, 'room', 'Workshop', $noon, $tz) === 'On by room Workshop · 13:04',
    'room on formats with the name'
);
assert_true(
    YarboHome::lastTriggerText(true, 'group', 'Lamps', $noon, $tz) === 'On by group Lamps · 13:04',
    'group on formats with the name'
);
assert_true(
    YarboHome::lastTriggerText(true, 'elsewhere', '', $noon, $tz) === 'On by something else (Apple Home or a switch)',
    'elsewhere has no clock'
);
assert_true(YarboHome::lastTriggerClock($noon, $tz) === '13:04', 'same-day clock is H:i');

$root = sys_get_temp_dir() . '/yarbo-last-trigger-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['1:2' => 'Lamp'],
    'room_defs' => [['id' => 'r1', 'name' => 'Lounge']],
    'rooms' => ['1:2' => 'r1'],
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
        'room_id' => 'r1',
    ]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-automations.json', json_encode([
    'timezone' => 'Europe/Zurich',
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$home->recordLastTrigger('1:2', true, 'scene', 'Evening', $noon);
$store = $home->load();
assert_true(($store['last_trigger']['1:2']['via'] ?? '') === 'scene', 'scene trigger is stored');
assert_true(str_contains((string) ($store['last_trigger']['1:2']['text'] ?? ''), 'scene Evening'), 'stored text names the scene');

$home = new YarboHome($root);
$dash = $home->dashboard();
$lamp = null;
foreach (array_merge($dash['devices'] ?? [], $dash['hidden_devices'] ?? []) as $row) {
    if (($row['id'] ?? '') === '1:2') {
        $lamp = $row;
        break;
    }
}
assert_true(is_array($lamp), 'dashboard still lists the lamp');
assert_true(($lamp['last_trigger']['via'] ?? '') === 'scene', 'dashboard carries the scene trigger: ' . json_encode($lamp['last_trigger'] ?? null));
assert_true(str_contains((string) ($lamp['last_trigger']['text'] ?? ''), 'On by scene Evening'), 'hover text is On by scene');

$home->recordLastTrigger('1:2', false, 'home', '', $noon);
$home = new YarboHome($root);
$dash = $home->dashboard();
$lamp = null;
foreach (array_merge($dash['devices'] ?? [], $dash['hidden_devices'] ?? []) as $row) {
    if (($row['id'] ?? '') === '1:2') {
        $lamp = $row;
        break;
    }
}
assert_true(($lamp['last_trigger']['via'] ?? '') === 'elsewhere', 'live On vs recorded Off is elsewhere');
assert_true(
    ($lamp['last_trigger']['text'] ?? '') === 'On by something else (Apple Home or a switch)',
    'mismatch hover names Apple Home: ' . ($lamp['last_trigger']['text'] ?? '')
);

$home->recordLastTrigger('unifi:sensor:s1', true, 'home', '');
$store = $home->load();
assert_true(!isset($store['last_trigger']['unifi:sensor:s1']), 'sensors are not recorded');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$homePhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');
$autoPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHomeAutomations.php');
assert_true(str_contains($js, 'function homeDeviceHoverTitle'), 'device hover uses last trigger');
assert_true(str_contains($js, 'last_trigger?.text'), 'hover title includes trigger text');
assert_true(str_contains($homePhp, 'function recordLastTrigger'), 'commands store last trigger');
assert_true(str_contains($homePhp, 'function withTrigger'), 'room/group/scene wrap commands');
assert_true(str_contains($autoPhp, "'via' => 'automation'"), 'automations tag Then commands');
assert_true(str_contains($change, '## [4.0.148]'), 'changelog 4.0.148');

echo "ok\n";
