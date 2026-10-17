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

assert_true(YarboHome::automationTypeLabel([
    'id' => 'unifi:light:flood1',
    'kind' => 'light',
    'source' => 'unifi',
    'product' => 'Protect light',
    'name' => 'Light',
]) === 'UniFi Floodlight', 'UniFi light type');

assert_true(YarboHome::automationTypeLabel([
    'id' => 'unifi:sensor:s1',
    'kind' => 'sensor',
    'source' => 'unifi',
    'product' => 'UFP-SENSE',
    'name' => 'Kitchen',
]) === 'USP Sensor', 'UFP-SENSE type');

assert_true(YarboHome::automationTypeLabel([
    'id' => 'unifi:sensor:s2',
    'kind' => 'sensor',
    'source' => 'unifi',
    'product' => 'UP Sense',
    'name' => 'Hall',
]) === 'USP Sensor', 'UP Sense type');

assert_true(YarboHome::automationTypeLabel([
    'id' => 'unifi:sensor:pir-light1',
    'kind' => 'sensor',
    'source' => 'unifi',
    'product' => 'Floodlight motion',
    'name' => 'Porch motion',
]) === 'Floodlight motion', 'floodlight PIR type');

assert_true(YarboHome::automationTypeLabel([
    'id' => '31:1',
    'kind' => 'heater',
    'product' => 'Mill Wi-Fi Panel Heater Gen4',
    'name' => 'Boiler',
]) === 'Mill Wi-Fi Panel Heater Gen4', 'Mill product type');

assert_true(YarboHome::automationTypeLabel([
    'id' => '1:2',
    'kind' => 'light',
    'product' => 'Hue color lamp',
    'name' => 'Light',
]) === 'Hue color lamp', 'Hue product type');

$root = sys_get_temp_dir() . '/yarbo-auto-labels-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [
        '1:2' => 'Light',
        'unifi:sensor:s1' => 'Sensor',
        'unifi:light:porch' => 'Light',
    ],
    'room_defs' => [
        ['id' => 'r-kitchen', 'name' => 'Kitchen'],
        ['id' => 'r-porch', 'name' => 'Porch'],
    ],
    'rooms' => [
        '1:2' => 'r-kitchen',
        'unifi:sensor:s1' => 'r-kitchen',
        'unifi:light:porch' => 'r-porch',
    ],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '1:2', 'name' => 'Light', 'kind' => 'light', 'product' => 'Hue color lamp', 'on' => false],
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:sensor:s1', 'unifi:light:porch'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [[
        'id' => 'unifi:light:porch',
        'native_id' => 'porch',
        'name' => 'Porch',
        'kind' => 'light',
        'source' => 'unifi',
        'product' => 'UFLXT',
        'on' => false,
    ]],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => [[
        'id' => 'unifi:sensor:s1',
        'native_id' => 's1',
        'name' => 'Kitchen',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'UP Sense',
        'on' => false,
        'has_motion' => true,
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));

$dash = (new YarboHome($root))->dashboard();
$byId = [];
foreach ($dash['automation_devices'] ?? [] as $row) {
    if (is_array($row) && isset($row['id'])) {
        $byId[$row['id']] = $row;
    }
}

$lamp = $byId['1:2'] ?? [];
assert_true(($lamp['name'] ?? '') === 'Light', 'lamp keeps short name');
assert_true(($lamp['room'] ?? '') === 'Kitchen', 'lamp room: ' . json_encode($lamp));
assert_true(($lamp['type_label'] ?? '') === 'Hue color lamp', 'lamp type: ' . json_encode($lamp));

$sensor = $byId['unifi:sensor:s1'] ?? [];
assert_true(($sensor['room'] ?? '') === 'Kitchen', 'sensor room: ' . json_encode($sensor));
assert_true(($sensor['type_label'] ?? '') === 'USP Sensor', 'sensor type: ' . json_encode($sensor));

$flood = $byId['unifi:light:porch'] ?? [];
assert_true(($flood['room'] ?? '') === 'Porch', 'flood room: ' . json_encode($flood));
assert_true(($flood['type_label'] ?? '') === 'UniFi Floodlight', 'flood type: ' . json_encode($flood));

$css = (string) file_get_contents(__DIR__ . '/../public/assets/style.css');
assert_true(str_contains($css, '.auto-chip-meta'), 'css chip meta');
assert_true(str_contains($css, '.auto-tray-room'), 'css tray room');
assert_true(str_contains($css, '.auto-tray-filter'), 'css tray search');
assert_true(str_contains($css, 'max-height: min(28rem, 50vh)'), 'tray scrolls instead of growing');
assert_true(str_contains($css, '.auto-chip.is-in'), 'chips already in the rule are marked');
assert_true(str_contains($css, '.auto-if-device-filter'), 'css Only-if device search');

echo "test_home_auto_labels.php ok\n";
