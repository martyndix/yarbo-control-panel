<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboHub;
use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-paper-robots-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => [
        'yarbo' => true,
        'powerwall' => false,
        'lymow' => true,
        'home' => true,
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['40:1' => 'Kitchen vac'],
    'hidden' => [],
    'scenes' => [],
    'paper' => [],
    'last_devices' => [[
        'id' => '40:1',
        'name' => 'Kitchen vac',
        'kind' => 'vacuum',
        'vacuum_status' => 'Docked',
        'vacuum_clean' => 'vacuum',
        'vacuum_battery' => 80,
        'can_mop' => true,
        'can_vacuum_mop' => false,
        'can_pause' => true,
        'areas' => [
            ['id' => 1, 'name' => 'Kitchen'],
            ['id' => 2, 'name' => 'Hall'],
        ],
        'selected_areas' => [1],
    ]],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$vacs = $home->paperRobotVacuums();
assert_true(count($vacs) === 1, 'one vacuum: ' . json_encode($vacs));
assert_true(($vacs[0]['id'] ?? '') === '40:1', 'vacuum id');
assert_true(($vacs[0]['kind'] ?? '') === 'vacuum', 'vacuum kind');
assert_true(str_contains((string) ($vacs[0]['status'] ?? ''), 'Docked'), 'vacuum status');
assert_true(($vacs[0]['areas'][0]['name'] ?? '') === 'Kitchen', 'vacuum rooms');

$compact = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compact->setAccessible(true);
$paper = new YarboPaperDevice($root);
$out = $compact->invoke($paper, ['robot_name' => 'Core'], false, ['id' => 'tab1', 'name' => 'Kitchen']);
$items = $out['robot_items'] ?? [];
$kinds = array_column(is_array($items) ? $items : [], 'kind');
assert_true(in_array('yarbo', $kinds, true), 'robot_items includes yarbo: ' . json_encode($items));
assert_true(in_array('lymow', $kinds, true), 'robot_items includes lymow');
assert_true(in_array('vacuum', $kinds, true), 'robot_items includes vacuum');
assert_true(($out['menu_visible']['robots'] ?? false) === true, 'robots visible with modules: ' . json_encode($out['menu_visible'] ?? null));
assert_true(($out['menu_labels']['robots'] ?? '') === 'ROBOTS', 'default robots label');
assert_true(!isset($out['menu_labels']['yarbo']), 'yarbo is not a menu label');

$offRoot = sys_get_temp_dir() . '/yarbo-paper-robots-off-' . bin2hex(random_bytes(3));
mkdir($offRoot . '/data', 0775, true);
file_put_contents($offRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => false, 'lymow' => false, 'home' => true, 'powerwall' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($offRoot . '/data/home.json', json_encode([
    'hidden' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
$offPaper = new YarboPaperDevice($offRoot);
$offOut = $compact->invoke($offPaper, null, false, null);
assert_true(($offOut['robot_items'] ?? ['x']) === [], 'no robot_items when empty');
assert_true(($offOut['menu_visible']['robots'] ?? true) !== true, 'robots hidden when empty');

$vacOnly = YarboHub::menuVisibleForModules(['robots' => true], [
    YarboHub::MODULE_YARBO => false,
    YarboHub::MODULE_LYMOW => false,
    YarboHub::MODULE_HOME => true,
    YarboHub::MODULE_POWERWALL => false,
], true);
assert_true(($vacOnly['robots'] ?? false) === true, 'matter vacuum keeps robots');

$migrated = $paper->savePrefs([
    'menu_order' => ['yarbo', 'note', 'lymow', 'house'],
    'menu_visible' => ['yarbo' => true, 'lymow' => false],
    'menu_labels' => ['yarbo' => 'HOME CONTROL'],
]);
$order = $migrated['prefs']['menu_order'] ?? [];
assert_true(($order[0] ?? '') === 'robots', 'legacy yarbo/lymow collapse to robots: ' . json_encode($order));
assert_true(!in_array('yarbo', $order, true) && !in_array('lymow', $order, true), 'legacy keys gone from order');
assert_true(($migrated['prefs']['menu_visible']['robots'] ?? false) === true, 'robots visible if either legacy was on');
assert_true(($migrated['prefs']['menu_labels']['robots'] ?? '') === 'ROBOTS', 'do not copy old YARBO title onto ROBOTS');

$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$homePhp = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/device.php');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($ver, '#define PAPERMONO_PAGE_ROBOTS 11'), 'robots page id');
assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.68"'), 'firmware 0.1.68');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.68';"), 'panel pin 0.1.68');
assert_true(str_contains($homePhp, 'function paperRobotVacuums'), 'paper vacuums helper');
assert_true(str_contains($homePhp, 'function paperVacuumCommand'), 'paper vacuum command');
assert_true(str_contains($api, "\$cmd === 'home_vacuum'"), 'device API home_vacuum');
assert_true(str_contains($fw, 'void drawRobotsPage(bool forceFull)'), 'firmware robots page');
assert_true(str_contains($fw, 'bool httpHomeVacuum'), 'firmware vacuum POST');
assert_true(str_contains($fw, 'void robotVacGeom'), 'vacuum chip layout');
assert_true(str_contains($fw, 'void robotVacSend'), 'tap redraws before HTTP');
assert_true(str_contains($fw, 'int sizes[4] = {4, 3, 2, 1}'), 'fitted label shrinks before wrapping');
assert_true(str_contains($fw, 'if (line2.length() < 2)'), 'no mid-word leftover wrap');
assert_true(str_contains($fw, 'int twoH = lineH * 2 + gap'), 'two-line label height');
assert_true(str_contains($fw, 'if (roomH < 72)'), 'room chips tall enough for two lines');
assert_true(str_contains($fw, 'if (roomH > 112)'), 'room chips can grow to house-tile height');
assert_true(str_contains($fw, 'robotVacHoldUntil'), 'compact must not snap chips back mid-tap');
assert_true(str_contains($fw, 'void saveMenuLayout()'), 'menu names persist across boots');
assert_true(str_contains($fw, 'robotsHaveItems'), 'holding menu can show ROBOTS before compact');
assert_true(str_contains($fw, 'homeHoldUntil'), 'HOUSE taps invert before Matter');
assert_true(str_contains($api, 'function device_json_ack_continue'), 'paper vacuum ACKs before Matter');
assert_true(str_contains($api, 'device_json_ack_continue([\'ok\' => true, \'command\' => $cmd, \'vacuum_cmd\' => $vacCmd])'), 'home_vacuum uses ACK-first');
assert_true(str_contains($api, "\$cmd === 'home_toggle' || \$cmd === 'home_scene'"), 'paper HOUSE ACKs before Matter');
assert_true(str_contains($change, '## [4.0.108]'), 'changelog 4.0.108');
assert_true(str_contains($change, '## [4.0.107]'), 'changelog 4.0.107');
assert_true(str_contains($html, 'data-menu-id="robots"'), 'settings robots row');
assert_true(!str_contains($html, 'data-menu-id="yarbo"'), 'settings dropped yarbo row');
assert_true(str_contains($js, "id === 'robots'"), 'settings visibility uses robots');
assert_true(!str_contains($js, 'setActiveModule(\'robots\')'), 'website switcher has no robots parent');
assert_true(str_contains($change, '## [4.0.106]'), 'changelog 4.0.106');

echo "ok: paper robots\n";
