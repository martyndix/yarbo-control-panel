<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-vb-overnight-lock-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true, 'home' => true, 'unifi' => true],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => 'x',
    'show_on_home' => ['unifi:door:garage'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode(['contact_kind' => []], JSON_UNESCAPED_SLASHES));
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
        'dps' => 'close',
        'dps_label' => 'Closed',
        'open' => false,
        'locked' => true,
    ]],
    'hubs' => [],
    'sensors' => [],
], JSON_UNESCAPED_SLASHES));

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'quiet_hours_enabled' => false,
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
assert_true($vb->isNightHours(), 'night hours active for lock test');
assert_true($vb->liveViewsLockedByOvernight(), 'live views locked overnight');

$live = $vb->setLiveModule('powerwall');
assert_true(empty($live['ok']), 'setLiveModule blocked overnight');
assert_true(str_contains((string) ($live['error'] ?? ''), 'night or quiet'), 'blocked message: ' . ($live['error'] ?? ''));

$rotate = $vb->saveRotate([
    'rotate_enabled' => true,
    'rotate_views' => ['yarbo', 'powerwall'],
    'rotate_minutes' => 5,
]);
assert_true(empty($rotate['ok']), 'saveRotate blocked overnight');

// App hold + resume must still be allowed while overnight.
$holdUntil = gmdate('c', time() + 3600);
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'external_hold' => true,
    'external_hold_until' => $holdUntil,
    'board_codes' => [
        $vb->encodeLine('APP     HOLD   '),
        $vb->encodeLine('FROM    PHONE  '),
        $vb->encodeLine('               '),
    ],
], JSON_UNESCAPED_SLASHES));
$held = new YarboVestaboard($root);
assert_true($held->isExternalHoldActive(), 'external hold active overnight');
assert_true($held->liveViewsLockedByOvernight(), 'views still locked during app hold overnight');
$dash = $held->dashboardPayload(null, false);
assert_true(!empty($dash['external_hold']), 'dashboard exposes hold for Resume');
assert_true(!empty($dash['night_hours']), 'dashboard still flags night during hold');
$resume = $held->resumeYarboStatus([]);
assert_true(!empty($resume['ok']) || isset($resume['skipped']) || isset($resume['message']) || isset($resume['error']), 'resume callable overnight: ' . json_encode($resume));
// Resume should not be rejected for overnight lock specifically.
assert_true(!str_contains(strtolower((string) ($resume['error'] ?? '')), 'night or quiet'), 'resume not blocked by overnight lock');

// Quiet hours also locks.
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => false,
    'quiet_hours_enabled' => true,
    'quiet_start' => '00:00',
    'quiet_end' => '23:59',
], JSON_UNESCAPED_SLASHES));
$quiet = new YarboVestaboard($root);
assert_true($quiet->isQuietHours(), 'quiet hours active');
assert_true($quiet->liveViewsLockedByOvernight(), 'live views locked in quiet');

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($index, 'vestaboard-overnight-hint'), 'web has overnight hint');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'is-overnight-locked'), 'JS toggles overnight lock class');
assert_true(str_contains($js, 'applyVestaboardOvernightLock'), 'JS applies overnight lock');

$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
assert_true(str_contains($fw, 'noteViewsOvernightLocked'), 'firmware overnight lock helper');
assert_true(str_contains($fw, 'Views locked'), 'firmware overnight note');
assert_true(str_contains($fw, 'App messages still show'), 'firmware app-message note');

$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
assert_true(str_contains($ver, '0.1.90'), 'firmware 0.1.90');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.257]'), 'changelog 4.0.257');

echo "ok\n";
