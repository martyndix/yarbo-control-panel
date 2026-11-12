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
assert_true(str_contains($ver, '0.1.95'), 'firmware 0.1.95');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.257]'), 'changelog 4.0.257');
assert_true(str_contains($change, '## [4.0.263]'), 'changelog 4.0.263');

// Night dashboard must mirror the last board / secure layout — no live UniFi scan.
$secureCodes = [
    $quiet->encodeLine('NIGHT      MODE'),
    $quiet->encodeLine('SLEEP      WELL'),
    $quiet->encodeLine('HOUSE    SECURE'),
];
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [[
        'id' => 'unifi:door:garage',
        'native_id' => 'garage',
        'name' => 'Garage',
        'kind' => 'door',
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
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'night_active' => true,
    'night_last_open' => false,
    'board_codes' => $secureCodes,
    'last_hash' => hash('sha256', json_encode($secureCodes)),
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));
$nightDash = new YarboVestaboard($root);
assert_true($nightDash->isNightHours(), 'sticky night_active uses clock without UniFi hang');
$payload = $nightDash->dashboardPayload(null, false);
assert_true(!empty($payload['night_hours']), 'night dashboard flags night hours');
assert_true(empty($payload['night_open']), 'night dashboard uses sticky night_last_open');
assert_true(($payload['lines'][0] ?? '') === 'NIGHT      MODE', 'night dashboard mirrors board row 1');
assert_true(($payload['lines'][2] ?? '') === 'HOUSE    SECURE', 'night dashboard mirrors board row 3');

$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'Browser/Funnel status must not re-scan UniFi'), 'dashboard night path documents no UniFi scan');
assert_true(str_contains($src, 'nightWatchInventoryCount'), 'night availability uses inventory file only');
assert_true(str_contains($src, 'codesLookLikeOvernight'), 'night dashboard rejects leftover live boards');
assert_true(str_contains($src, 'Do not call nightLayoutHash() here'), 'tick skips daytime UniFi nightLayoutHash');
assert_true(str_contains($src, 'nightLayout(null, null, false)'), 'night tick uses inventory after sync refresh');
assert_true(str_contains($src, 'requestTickNudge'), 'nudge API exists');
assert_true(str_contains($src, 'maxAgeSeconds = 90'), 'watcher stale threshold allows long companion probes');

// Sticky night_active with leftover Powerwall board_codes must not show Powerwall.
$pwCodes = [
    $quiet->encodeLine('POWERWALL  53%'),
    $quiet->encodeLine('GRID     570W'),
    $quiet->encodeLine('DRAW     571W'),
];
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'night_active' => true,
    'night_last_open' => false,
    'board_codes' => $pwCodes,
    'last_hash' => hash('sha256', json_encode($pwCodes)),
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));
$staleNight = new YarboVestaboard($root);
assert_true($staleNight->codesLookLikeLiveStatus($pwCodes), 'powerwall codes look live');
assert_true(!$staleNight->codesLookLikeOvernight($pwCodes), 'powerwall codes are not overnight');
$stalePayload = $staleNight->dashboardPayload(null, false);
assert_true(!empty($stalePayload['night_hours']), 'stale night still flags night hours');
assert_true(!str_contains(strtoupper((string) ($stalePayload['lines'][0] ?? '')), 'POWERWALL'), 'stale night must not mirror Powerwall: ' . json_encode($stalePayload['lines'] ?? null));
assert_true(str_contains(strtoupper((string) ($stalePayload['lines'][2] ?? '')), 'SECURE'), 'stale night falls back to secure layout');

echo "ok\n";
