<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-paper-heat-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => false],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
$heaters = [
    [
        'id' => '2:2',
        'name' => 'Kitchen',
        'kind' => 'heater',
        'on' => true,
        'local_temperature' => 19.2,
        'heating_setpoint' => 21.0,
        'heating_min' => 5,
        'heating_max' => 35,
        'has_thermostat' => true,
        'available' => true,
    ],
    [
        'id' => '3:2',
        'name' => 'Living room',
        'kind' => 'heater',
        'on' => false,
        'local_temperature' => 20.0,
        'heating_setpoint' => 20.0,
        'has_thermostat' => true,
        'available' => true,
    ],
    [
        'id' => '4:2',
        'name' => 'Hidden loft',
        'kind' => 'heater',
        'on' => false,
        'local_temperature' => 16.0,
        'heating_setpoint' => 18.0,
        'has_thermostat' => true,
        'available' => true,
    ],
    [
        'id' => '10:1',
        'name' => 'Hall light',
        'kind' => 'light',
        'on' => true,
        'available' => true,
    ],
];
file_put_contents($root . '/data/home.json', json_encode([
    'hidden' => ['4:2'],
    'last_devices' => $heaters,
    'device_order' => ['3:2', '2:2', '10:1'],
    'names' => [],
    'rooms' => [],
    'room_defs' => [],
    'groups' => [],
    'scenes' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 6,
    'saved_at' => time(),
    'fabric_mtime' => time() + 100000,
    'disk_ids' => ['2:2', '3:2', '4:2', '10:1'],
    'devices' => $heaters,
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$items = $home->paperHeatItems();
$ids = array_column($items, 'id');
assert_true($ids === ['3:2', '2:2'], 'HEAT order follows Home, skips hidden and lights: ' . json_encode($ids));
assert_true(($items[1]['on'] ?? false) === true, 'Kitchen is on');
assert_true(($items[1]['temp'] ?? null) === 19.2, 'Kitchen now temp');
assert_true(($items[1]['set'] ?? null) === 21.0, 'Kitchen set temp');
assert_true(($items[0]['on'] ?? true) === false, 'Living room is off');
assert_true(($items[0]['name'] ?? '') === 'Living room', 'uses Matter name when no room: ' . json_encode($items[0]));
assert_true(($items[1]['name'] ?? '') === 'Kitchen', 'uses Matter name when no room: ' . json_encode($items[1]));

$roomRoot = sys_get_temp_dir() . '/yarbo-paper-heat-rooms-' . bin2hex(random_bytes(3));
mkdir($roomRoot . '/data', 0775, true);
file_put_contents($roomRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => false],
], JSON_UNESCAPED_SLASHES));
$stockHeaters = [
    [
        'id' => '5:2',
        'name' => 'Heater',
        'kind' => 'heater',
        'on' => true,
        'local_temperature' => 21.0,
        'heating_setpoint' => 23.0,
        'has_thermostat' => true,
        'available' => true,
    ],
    [
        'id' => '6:2',
        'name' => 'Mill Wi-Fi Panel Heater Gen4',
        'kind' => 'heater',
        'on' => false,
        'local_temperature' => 20.0,
        'heating_setpoint' => 19.0,
        'has_thermostat' => true,
        'available' => true,
    ],
    [
        'id' => '7:2',
        'name' => 'Heater',
        'kind' => 'heater',
        'on' => false,
        'local_temperature' => 18.0,
        'heating_setpoint' => 18.0,
        'has_thermostat' => true,
        'available' => true,
    ],
];
file_put_contents($roomRoot . '/data/home.json', json_encode([
    'hidden' => [],
    'last_devices' => $stockHeaters,
    'device_order' => ['5:2', '6:2', '7:2'],
    'names' => ['7:2' => 'Towel rail'],
    'rooms' => [
        '5:2' => 'r-kit',
        '6:2' => 'r-liv',
        '7:2' => 'r-bath',
    ],
    'room_defs' => [
        ['id' => 'r-kit', 'name' => 'Kitchen'],
        ['id' => 'r-liv', 'name' => 'Living room'],
        ['id' => 'r-bath', 'name' => 'Bathroom'],
    ],
    'groups' => [],
    'scenes' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($roomRoot . '/data/home-nodes-cache.json', json_encode([
    'v' => 6,
    'saved_at' => time(),
    'fabric_mtime' => time() + 100000,
    'disk_ids' => ['5:2', '6:2', '7:2'],
    'devices' => $stockHeaters,
], JSON_UNESCAPED_SLASHES));
$roomHome = new YarboHome($roomRoot);
$roomItems = $roomHome->paperHeatItems();
$roomNames = array_column($roomItems, 'name', 'id');
assert_true(($roomNames['5:2'] ?? '') === 'Kitchen', 'stock Heater uses room: ' . json_encode($roomNames));
assert_true(($roomNames['6:2'] ?? '') === 'Living room', 'Mill product name uses room: ' . json_encode($roomNames));
assert_true(($roomNames['7:2'] ?? '') === 'Towel rail', 'custom Home name wins over room: ' . json_encode($roomNames));
assert_true(YarboHome::paperHeatLabelIsGeneric('Heater'), 'Heater is generic');
assert_true(YarboHome::paperHeatLabelIsGeneric('Mill Wi-Fi Panel Heater Gen4'), 'Mill product is generic');
assert_true(!YarboHome::paperHeatLabelIsGeneric('Towel rail'), 'custom label is not generic');
assert_true(!YarboHome::paperHeatLabelIsGeneric('Kitchen'), 'room-like name is not generic');

$compact = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compact->setAccessible(true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));
$paper = new YarboPaperDevice($root);
$out = $compact->invoke($paper, null, false, ['id' => 'tab1']);
$heat = $out['heat_items'] ?? [];
assert_true(is_array($heat) && count($heat) === 2, 'compact heat_items: ' . json_encode($heat));
assert_true(($out['menu_visible']['heat'] ?? false) === true, 'HEAT visible when heaters exist');
assert_true(($out['menu_labels']['heat'] ?? '') === 'HEAT', 'default HEAT label');
assert_true(in_array('heat', $out['menu_order'] ?? [], true), 'HEAT is on the unlock menu order');

$offRoot = sys_get_temp_dir() . '/yarbo-paper-heat-off-' . bin2hex(random_bytes(3));
mkdir($offRoot . '/data', 0775, true);
file_put_contents($offRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($offRoot . '/data/home.json', json_encode(['hidden' => [], 'last_devices' => []], JSON_UNESCAPED_SLASHES));
$offPaper = new YarboPaperDevice($offRoot);
$offOut = $compact->invoke($offPaper, null, false, null);
assert_true(($offOut['heat_items'] ?? ['x']) === [], 'no heat_items when empty');
assert_true(($offOut['menu_visible']['heat'] ?? true) !== true, 'HEAT hidden when empty');

$homeOff = sys_get_temp_dir() . '/yarbo-paper-heat-mod-' . bin2hex(random_bytes(3));
mkdir($homeOff . '/data', 0775, true);
file_put_contents($homeOff . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($homeOff . '/data/home.json', json_encode(['hidden' => [], 'last_devices' => $heaters], JSON_UNESCAPED_SLASHES));
$modPaper = new YarboPaperDevice($homeOff);
$modOut = $compact->invoke($modPaper, null, false, null);
assert_true(($modOut['heat_items'] ?? ['x']) === [], 'no heat_items when Home module off');
assert_true(($modOut['menu_visible']['heat'] ?? true) !== true, 'HEAT hidden when Home module off');

$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$homePhp = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/device.php');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($ver, '#define PAPERMONO_PAGE_HEAT 13'), 'heat page id');
assert_true(str_contains($ver, '#define PAPERMONO_PAGE_COUNT 14'), 'page count includes HEAT');
assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.72"'), 'firmware 0.1.72');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.72';"), 'panel pin 0.1.72');
assert_true(str_contains($homePhp, 'function paperHeatItems'), 'paper HEAT helper');
assert_true(str_contains($homePhp, 'public const PAPER_HEAT_MAX = 12;'), 'panel sends up to 12 HEAT items');
assert_true(str_contains($fw, 'void drawHeatPage(bool forceFull)'), 'firmware HEAT page');
assert_true(str_contains($fw, 'void layoutHeatGrid'), 'HEAT rows shrink to fit one screen');
assert_true(str_contains($fw, 'void handleHeatTouch'), 'HEAT tap handler');
assert_true(str_contains($fw, 'httpHomeSetpoint'), 'setpoint command helper');
assert_true(str_contains($fw, 'heatHoldUntil'), 'optimistic hold while Matter writes');
assert_true(str_contains($api, "\$cmd === 'home_setpoint'"), 'device API home_setpoint');
assert_true(str_contains($api, "device_json_ack_continue(['ok' => true, 'command' => \$cmd])"), 'setpoint ACKs before Matter');
assert_true(str_contains($html, 'data-menu-id="heat"'), 'settings HEAT row');
assert_true(str_contains($html, 'PaperMono HEAT page mock'), 'settings HEAT preview');
assert_true(str_contains($change, '## [4.0.155]'), 'changelog 4.0.155');
assert_true(str_contains($homePhp, 'function paperHeatLabel'), 'HEAT room label helper');
assert_true(str_contains($homePhp, 'paperHeatLabelIsGeneric'), 'generic Heater detection');

echo "ok: paper heat\n";
