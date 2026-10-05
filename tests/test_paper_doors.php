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

$root = sys_get_temp_dir() . '/yarbo-paper-doors-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:sensor:s1'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [
        [
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
        ],
        [
            'id' => 'unifi:door:gates',
            'native_id' => 'gates',
            'name' => 'Gates',
            'kind' => 'door',
            'source' => 'unifi',
            'on' => true,
            'locked' => false,
            'has_dps' => true,
            'dps' => 'close',
            'dps_label' => 'Closed',
            'open' => false,
        ],
        [
            'id' => 'unifi:door:utility',
            'native_id' => 'utility',
            'name' => 'Utility',
            'kind' => 'door',
            'source' => 'unifi',
            'on' => false,
            'locked' => true,
            'has_dps' => false,
            'dps' => '',
            'dps_label' => '',
            'open' => null,
        ],
        [
            'id' => 'unifi:door:spare',
            'native_id' => 'spare',
            'name' => 'Spare',
            'kind' => 'door',
            'source' => 'unifi',
            'on' => true,
            'open' => true,
        ],
    ],
    'hubs' => [[
        'id' => 'unifi:hub:h1',
        'native_id' => 'h1',
        'door_id' => 'garage',
        'name' => 'Garage hub',
        'kind' => 'hub',
        'source' => 'unifi',
        'on' => false,
        'locked' => true,
        'has_dps' => true,
        'dps_label' => 'Open',
        'open' => true,
    ]],
    'sensors' => [[
        'id' => 'unifi:sensor:s1',
        'native_id' => 's1',
        'name' => 'Toilet',
        'kind' => 'sensor',
        'source' => 'unifi',
        'on' => false,
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [
        '1:1' => 'Fountain',
        'unifi:door:garage' => 'Garage',
        'unifi:door:gates' => 'Gates',
        'unifi:door:utility' => 'Utility',
        'unifi:hub:h1' => 'Garage hub',
    ],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [
        'martyn' => [
            '1:1',
            'unifi:door:garage',
            'unifi:door:gates',
            'unifi:door:utility',
            'unifi:hub:h1',
        ],
    ],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '1:1', 'name' => 'Fountain', 'kind' => 'plug', 'on' => true],
    ],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$items = $home->paperItems('martyn');
$byId = [];
foreach ($items as $row) {
    $byId[$row['id']] = $row;
}

assert_true(isset($byId['unifi:door:garage']), 'assigned open door must be on HOUSE: ' . json_encode($items));
assert_true(isset($byId['unifi:door:gates']), 'assigned closed door must be on HOUSE');
assert_true(isset($byId['unifi:door:utility']), 'assigned door with unknown DPS must be on HOUSE');
assert_true(isset($byId['unifi:hub:h1']), 'assigned Access hub must be on HOUSE');
assert_true(!isset($byId['unifi:door:spare']), 'unassigned door must stay off HOUSE even if it is in inventory');
assert_true(!isset($byId['unifi:sensor:s1']), 'sensors shown on web Home must not appear as HOUSE tiles unless assigned');
assert_true(($byId['unifi:door:garage']['kind'] ?? '') === 'door', 'garage kind');
assert_true(($byId['unifi:hub:h1']['kind'] ?? '') === 'hub', 'hub kind');
assert_true(($byId['unifi:door:garage']['on'] ?? false) === true, 'open DPS must invert the tile even when the lock is locked');
assert_true(($byId['unifi:door:gates']['on'] ?? true) === false, 'closed DPS must not invert even when the lock is unlocked');
assert_true(($byId['unifi:door:utility']['on'] ?? true) === false, 'unknown DPS must not invert');
assert_true(($byId['unifi:hub:h1']['on'] ?? false) === true, 'hub invert must follow the linked door DPS');
assert_true(($byId['1:1']['on'] ?? false) === true, 'Matter plug invert must still follow on');

$ids = array_column($items, 'id');
assert_true($ids === [
    '1:1',
    'unifi:door:garage',
    'unifi:door:gates',
    'unifi:door:utility',
    'unifi:hub:h1',
], 'HOUSE order must follow assignment: ' . json_encode($ids));

$unlock = $home->paperCommand('unifi:door:garage');
assert_true(($unlock['ok'] ?? true) === false, 'unlock without Access token should fail cleanly');
assert_true(
    str_contains((string) ($unlock['error'] ?? ''), 'Access API token'),
    'HOUSE door tap must unlock, not Matter-toggle: ' . json_encode($unlock)
);

$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$homePhp = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$docs = (string) file_get_contents(__DIR__ . '/../docs/papermono.md');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($fw, 'void drawDoorGlyph'), 'firmware door glyph');
assert_true(str_contains($fw, 'homeKinds[i] == "door" || homeKinds[i] == "hub"'), 'HOUSE tiles mark doors');
assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.64"'), 'firmware 0.1.64');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.64';"), 'panel firmware pin 0.1.64');
assert_true(str_contains($homePhp, 'mergeUnifiDevices($live[\'devices\'], true)'), 'paperItems must include UniFi doors not on web Home');
assert_true(str_contains($homePhp, "(\$device['open'] ?? null) === true"), 'door invert follows DPS open');
assert_true(str_contains($docs, 'door glyph'), 'docs mention door glyph');
assert_true(str_contains($change, '## [4.0.57]'), 'changelog 4.0.57');

echo "test_paper_doors.php ok\n";
