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

$root = sys_get_temp_dir() . '/yarbo-paper-access-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => [
        'unifi:door:garage',
        'unifi:door:front',
        'unifi:hub:mini',
        'unifi:sensor:kitchen-window',
        'unifi:sensor:side-door',
        'unifi:sensor:dps-garage',
        'unifi:sensor:spare-window',
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [[
        'id' => 'unifi:door:garage',
        'native_id' => 'garage',
        'name' => 'Garage',
        'kind' => 'door',
        'source' => 'unifi',
        'on' => false,
        'locked' => true,
        'has_dps' => true,
        'dps' => 'open',
        'dps_label' => 'Open',
        'open' => true,
    ], [
        'id' => 'unifi:door:front',
        'native_id' => 'front',
        'name' => 'Front',
        'kind' => 'door',
        'source' => 'unifi',
        'on' => true,
        'locked' => false,
        'has_dps' => true,
        'dps' => 'close',
        'dps_label' => 'Closed',
        'open' => false,
    ], [
        'id' => 'unifi:door:spare',
        'native_id' => 'spare',
        'name' => 'Spare',
        'kind' => 'door',
        'source' => 'unifi',
        'on' => false,
        'locked' => true,
        'has_dps' => false,
        'open' => false,
    ]],
    'hubs' => [[
        'id' => 'unifi:hub:mini',
        'native_id' => 'mini',
        'door_id' => 'garage',
        'name' => 'UA Hub Door Mini 95aa',
        'kind' => 'hub',
        'source' => 'unifi',
        'on' => false,
        'locked' => true,
        'has_dps' => true,
        'open' => true,
    ], [
        'id' => 'unifi:hub:hidden',
        'native_id' => 'hidden-hub',
        'door_id' => 'front',
        'name' => 'UA Hub Door Mini 952c',
        'kind' => 'hub',
        'source' => 'unifi',
        'on' => false,
        'locked' => true,
        'open' => false,
    ]],
    'sensors' => [[
        'id' => 'unifi:sensor:dps-garage',
        'native_id' => 'dps-garage',
        'door_id' => 'garage',
        'name' => 'Garage DPS',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'Door position sensor',
        'has_open' => true,
        'open' => true,
    ], [
        'id' => 'unifi:sensor:kitchen-window',
        'native_id' => 'usp-kitchen',
        'name' => 'Kitchen window',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'USP Sensor',
        'has_open' => true,
        'open' => true,
    ], [
        'id' => 'unifi:sensor:side-door',
        'native_id' => 'usp-side',
        'name' => 'Side door',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'USP Sensor',
        'has_open' => true,
        'open' => false,
    ], [
        'id' => 'unifi:sensor:pir-drive',
        'native_id' => 'pir-drive',
        'name' => 'Drive motion',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'AI 360',
        'has_motion' => true,
        'motion' => true,
    ], [
        'id' => 'unifi:sensor:spare-window',
        'native_id' => 'usp-spare',
        'name' => 'Spare window',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'USP Sensor',
        'has_open' => true,
        'open' => false,
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [
        'unifi:door:garage' => 'Garage',
        'unifi:sensor:kitchen-window' => 'Kitchen window',
        'unifi:sensor:side-door' => 'Side door',
    ],
    'hidden' => ['unifi:sensor:spare-window'],
    'scenes' => [],
    'paper' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$items = $home->paperAccessItems();
$byId = [];
foreach ($items as $row) {
    $byId[$row['id']] = $row;
}

assert_true(isset($byId['unifi:door:garage']), 'lockable garage on ACCESS: ' . json_encode($items));
assert_true(isset($byId['unifi:door:front']), 'lockable front on ACCESS');
assert_true(isset($byId['unifi:sensor:kitchen-window']), 'window sensor on ACCESS');
assert_true(isset($byId['unifi:sensor:side-door']), 'door sensor on ACCESS');
assert_true(!isset($byId['unifi:sensor:dps-garage']), 'covered DPS is not duplicated: ' . json_encode($items));
assert_true(!isset($byId['unifi:sensor:pir-drive']), 'PIR stays off ACCESS');
assert_true(!isset($byId['unifi:hub:mini']), 'bound hub is not duplicated when the door is listed: ' . json_encode($items));
assert_true(!isset($byId['unifi:hub:hidden']), 'hub hidden from Home (not Show on Home) stays off ACCESS');
assert_true(!isset($byId['unifi:door:spare']), 'door hidden from Home stays off ACCESS');
assert_true(!isset($byId['unifi:sensor:spare-window']), 'sensor on the Home hidden list stays off ACCESS');
assert_true(($byId['unifi:door:garage']['unlock'] ?? false) === true, 'garage is unlockable');
assert_true(($byId['unifi:door:garage']['on'] ?? false) === true, 'open garage inverts');
assert_true(($byId['unifi:door:garage']['role'] ?? '') === 'door', 'lockable stays door');
assert_true(($byId['unifi:sensor:kitchen-window']['unlock'] ?? true) === false, 'window sensor is not tappable');
assert_true(($byId['unifi:sensor:kitchen-window']['role'] ?? '') === 'window', 'window name infers window: ' . json_encode($byId['unifi:sensor:kitchen-window'] ?? null));
assert_true(($byId['unifi:sensor:side-door']['role'] ?? '') === 'door', 'side door infers door');
assert_true(($byId['unifi:sensor:kitchen-window']['on'] ?? false) === true, 'open window inverts');
assert_true(array_keys($byId)[0] === 'unifi:door:garage' || ($items[0]['unlock'] ?? false) === true, 'lockable doors are listed first');

$saved = $home->assignContactKind('unifi:sensor:side-door', 'window');
assert_true(!empty($saved['ok']), 'assign window: ' . json_encode($saved));
$again = $home->paperAccessItems();
$side = null;
foreach ($again as $row) {
    if (($row['id'] ?? '') === 'unifi:sensor:side-door') {
        $side = $row;
        break;
    }
}
assert_true(($side['role'] ?? '') === 'window', 'saved contact_kind wins: ' . json_encode($side));

$store = $home->load();
assert_true(($store['contact_kind']['unifi:sensor:side-door'] ?? '') === 'window', 'contact_kind persisted');

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
$access = $out['access_items'] ?? [];
assert_true(is_array($access) && $access !== [], 'compact access_items: ' . json_encode($access));
assert_true(($out['menu_visible']['access'] ?? false) === true, 'ACCESS visible when items exist');
assert_true(($out['menu_labels']['access'] ?? '') === 'ACCESS', 'default ACCESS label');
assert_true(in_array('access', $out['menu_order'] ?? [], true), 'ACCESS is on the unlock menu order');

$offRoot = sys_get_temp_dir() . '/yarbo-paper-access-off-' . bin2hex(random_bytes(3));
mkdir($offRoot . '/data', 0775, true);
file_put_contents($offRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($offRoot . '/data/home.json', json_encode(['hidden' => [], 'last_devices' => []], JSON_UNESCAPED_SLASHES));
$offPaper = new YarboPaperDevice($offRoot);
$offOut = $compact->invoke($offPaper, null, false, null);
assert_true(($offOut['access_items'] ?? ['x']) === [], 'no access_items when empty');
assert_true(($offOut['menu_visible']['access'] ?? true) !== true, 'ACCESS hidden when empty');

$manyRoot = sys_get_temp_dir() . '/yarbo-paper-access-many-' . bin2hex(random_bytes(3));
mkdir($manyRoot . '/data', 0775, true);
$manyIds = [];
$manySensors = [];
for ($i = 1; $i <= 24; $i++) {
    $id = 'unifi:sensor:w' . $i;
    $manyIds[] = $id;
    $manySensors[] = [
        'id' => $id,
        'native_id' => 'w' . $i,
        'name' => 'Window ' . $i,
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'USP Sensor',
        'has_open' => true,
        'open' => $i % 5 === 0,
    ];
}
file_put_contents($manyRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($manyRoot . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => $manyIds,
], JSON_UNESCAPED_SLASHES));
file_put_contents($manyRoot . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => $manySensors,
], JSON_UNESCAPED_SLASHES));
file_put_contents($manyRoot . '/data/home.json', json_encode(['hidden' => [], 'last_devices' => []], JSON_UNESCAPED_SLASHES));
$manyHome = new YarboHome($manyRoot);
$manyItems = $manyHome->paperAccessItems();
assert_true(count($manyItems) === 24, 'ACCESS payload keeps 24 sensors: ' . count($manyItems));

$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$homePhp = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($ver, '#define PAPERMONO_PAGE_ACCESS 12'), 'access page id');
assert_true(str_contains($ver, '#define PAPERMONO_PAGE_COUNT 14'), 'page count includes HEAT');
assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.72"'), 'firmware 0.1.72');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.72';"), 'panel pin 0.1.72');
assert_true(str_contains($homePhp, 'function paperAccessItems'), 'paper ACCESS helper');
assert_true(str_contains($homePhp, 'function assignContactKind'), 'contact_kind save');
assert_true(str_contains($api, "'contact_kind'"), 'home API contact_kind');
assert_true(str_contains($fw, 'void drawAccessPage(bool forceFull)'), 'firmware ACCESS page');
assert_true(str_contains($fw, 'void layoutAccessGrid'), 'ACCESS grid shrinks to fit one screen');
assert_true(str_contains($fw, 'void drawThickRoundRect'), 'lockable doors use a thick border');
assert_true(!str_contains($fw, 'drawRoundRect(x + 3, y + 3'), 'no double-line door border');
assert_true(str_contains($ver, '#define PAPERMONO_ACCESS_MAX 36'), 'firmware holds 36 access tiles');
assert_true(str_contains($homePhp, 'public const PAPER_ACCESS_MAX = 36;'), 'panel sends up to 36 ACCESS items');
assert_true(str_contains($fw, 'void drawWindowGlyph'), 'window glyph');
assert_true(str_contains($fw, 'void drawLockMark'), 'lockable doors have a lock mark');
assert_true(str_contains($fw, 'void handleAccessTouch'), 'ACCESS tap handler');
assert_true(str_contains($fw, 'accessUnlock[i]'), 'only lockable tiles send unlock');
assert_true(str_contains($fw, 'accessHaveItems'), 'menu can show ACCESS before compact');
assert_true(str_contains($html, 'data-menu-id="access"'), 'settings ACCESS row');
assert_true(str_contains($js, 'data-home-contact-assign'), 'Home ⚙️ Door/Window select');
assert_true(str_contains($js, '🪟'), 'Home uses a window emoji');
assert_true(str_contains($change, '## [4.0.153]'), 'changelog 4.0.153');
assert_true(str_contains($html, 'thick lockable-door borders'), 'settings preview uses a thick door border');
assert_true(str_contains($homePhp, 'Home rows only: UniFi Hide unticks Show on Home'), 'ACCESS uses Home rows, not all UniFi devices');

echo "ok: paper access\n";
