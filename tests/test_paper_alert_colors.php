<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-paper-alerts-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => true,
        'home' => true,
        'unifi' => true,
        'vestaboard' => true,
    ],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'night_active' => true,
    'quiet_timezone' => 'UTC',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => 'x',
    'show_on_home' => ['unifi:door:garage'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'contact_kind' => [],
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
        'has_dps' => true,
        'dps' => 'open',
        'dps_label' => 'Open',
        'open' => true,
        'locked' => true,
    ]],
    'hubs' => [],
    'sensors' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1']],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$paper = new YarboPaperDevice($root);
$defaults = $paper->publicPrefs();
assert_true(($defaults['alert_message_color'] ?? '') === 'cyan', 'default message colour cyan');
assert_true(($defaults['alert_yarbo_color'] ?? '') === 'red', 'default yarbo colour red');
assert_true(($defaults['alert_message_flash'] ?? true) === false, 'message solid by default');
assert_true(($defaults['alert_yarbo_flash'] ?? false) === true, 'yarbo flashes by default');
assert_true(($defaults['alert_night_open'] ?? false) === true, 'night open alert on by default');
assert_true(($defaults['alert_message_light'] ?? false) === true, 'message backlight on by default');
assert_true(($defaults['alert_message_brightness'] ?? 0) === 100, 'message brightness 100');
assert_true(($defaults['alert_message_page'] ?? '') === 'mail', 'message opens mail');
assert_true(($defaults['alert_night_open_page'] ?? '') === 'note', 'night open opens note');
assert_true(YarboPaperDevice::normalizeAlertColor('purple') === 'magenta', 'purple → magenta');
assert_true(YarboPaperDevice::normalizeAlertColor('nope', 'blue') === 'blue', 'bad colour falls back');
assert_true(YarboPaperDevice::normalizeAlertPage('lock') === 'lock', 'lock page allowed');
assert_true(YarboPaperDevice::normalizeAlertPage('home') === 'yarbo', 'home → yarbo');
assert_true(YarboPaperDevice::normalizeAlertPage('nope', 'note') === 'note', 'bad page falls back');

$saved = $paper->savePrefs([
    'alert_message' => true,
    'alert_message_color' => 'yellow',
    'alert_message_flash' => true,
    'alert_message_light' => true,
    'alert_message_brightness' => 80,
    'alert_message_page' => 'lock',
    'alert_yarbo_color' => 'green',
    'alert_yarbo_flash' => false,
    'alert_yarbo_light' => false,
    'alert_yarbo_brightness' => 40,
    'alert_yarbo_page' => 'none',
    'alert_lymow_color' => 'blue',
    'alert_powerwall_color' => 'white',
    'alert_night_open' => true,
    'alert_night_open_color' => 'magenta',
    'alert_night_open_flash' => false,
    'alert_night_open_light' => true,
    'alert_night_open_brightness' => 100,
    'alert_night_open_page' => 'access',
]);
assert_true(!empty($saved['ok']), 'save alert colour prefs');

$prefs = $paper->publicPrefs();
assert_true(($prefs['alert_message_color'] ?? '') === 'yellow', 'saved message colour');
assert_true(($prefs['alert_message_flash'] ?? false) === true, 'saved message flash');
assert_true(($prefs['alert_message_page'] ?? '') === 'lock', 'saved message page lock');
assert_true(($prefs['alert_message_brightness'] ?? 0) === 80, 'saved message brightness');
assert_true(($prefs['alert_yarbo_color'] ?? '') === 'green', 'saved yarbo colour');
assert_true(($prefs['alert_yarbo_flash'] ?? true) === false, 'saved yarbo solid');
assert_true(($prefs['alert_yarbo_light'] ?? true) === false, 'saved yarbo no backlight');
assert_true(($prefs['alert_night_open_color'] ?? '') === 'magenta', 'saved night colour');
assert_true(($prefs['alert_night_open_page'] ?? '') === 'access', 'saved night page access');

$compactMethod = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compactMethod->setAccessible(true);
$compact = $compactMethod->invoke(
    $paper,
    ['error_code' => 0, 'power_fault' => 0],
    true,
    ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono']
);
assert_true(($compact['alert_message_color'] ?? '') === 'yellow', 'compact has message colour');
assert_true(($compact['alert_yarbo_color'] ?? '') === 'green', 'compact has yarbo colour');
assert_true(($compact['alert_night_open'] ?? false) === true, 'compact has night open pref');
assert_true(($compact['alert_night_open_color'] ?? '') === 'magenta', 'compact has night colour');
assert_true(($compact['alert_message_page'] ?? '') === 'lock', 'compact has message page');
assert_true(($compact['alert_night_open_page'] ?? '') === 'access', 'compact has night page');
assert_true(($compact['alert_yarbo_light'] ?? true) === false, 'compact has yarbo light off');
assert_true(($compact['vestaboard_night'] ?? false) === true, 'compact night active');
assert_true(($compact['vestaboard_night_open'] ?? false) === true, 'compact night door open');

// Closed door while still in night hours → night_open false.
$inv = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
$inv['doors'][0]['open'] = false;
$inv['doors'][0]['dps'] = 'close';
$inv['doors'][0]['dps_label'] = 'Closed';
file_put_contents($root . '/data/unifi-inventory.json', json_encode($inv, JSON_UNESCAPED_SLASHES));
$closed = $compactMethod->invoke(
    new YarboPaperDevice($root),
    ['error_code' => 0],
    true,
    ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono']
);
assert_true(($closed['vestaboard_night'] ?? false) === true, 'still night when closed');
assert_true(($closed['vestaboard_night_open'] ?? true) === false, 'night open clears when closed');

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($index, 'papermono-alert-message-color'), 'settings has message colour');
assert_true(str_contains($index, 'papermono-alert-night-open'), 'settings has night open alert');
assert_true(str_contains($index, 'papermono-alert-yarbo-flash'), 'settings has flash checkbox');
assert_true(str_contains($index, "papermono_alert_goto_controls('night_open'"), 'settings has night page picker');
assert_true(str_contains($index, "papermono_alert_goto_controls('message'"), 'settings has message backlight/page');
assert_true(str_contains($index, "papermono_alert_goto_controls('yarbo'"), 'settings has yarbo backlight/page');
assert_true(str_contains($index, 'data-alert-page='), 'settings renders page select attrs');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'alert_night_open_color'), 'JS saves night colour');
assert_true(str_contains($js, 'alert_message_flash'), 'JS saves message flash');
assert_true(str_contains($js, 'paperAlertGotoPayload'), 'JS saves backlight/page prefs');

$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
assert_true(str_contains($fw, 'alert_night_open'), 'firmware reads night open pref');
assert_true(str_contains($fw, 'vestaboard_night_open'), 'firmware reads night open flag');
assert_true(str_contains($fw, 'alertSetMessageFlash'), 'firmware applies message flash');
assert_true(str_contains($fw, 'updateAlertPresentation'), 'firmware updates alert presentation');
assert_true(str_contains($fw, 'applyAlertPage'), 'firmware navigates alert page');
assert_true(str_contains($fw, 'alertHoldBrightness'), 'firmware holds alert backlight');

$hw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/paper_hw.cpp');
assert_true(str_contains($hw, 'parseAlertColor'), 'firmware parses colours');
assert_true(str_contains($hw, 'msgFlash'), 'firmware message flash state');

$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
assert_true(str_contains($ver, '0.1.90'), 'firmware bumped to 0.1.90');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.258]'), 'changelog 4.0.258');

echo "ok\n";
