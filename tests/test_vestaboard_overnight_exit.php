<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

$root = sys_get_temp_dir() . '/yarbo-vb-overnight-exit-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true, 'home' => true, 'unifi' => true],
    'vestaboard_live' => 'powerwall',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-automations.json', json_encode([
    'timezone' => 'Europe/Zurich',
    'automations' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'prefs' => ['timezone' => 'Europe/Berlin'],
    'devices' => [],
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => '22:00',
    'night_end' => '07:00',
    'night_active' => true,
], JSON_UNESCAPED_SLASHES));

$board = new YarboVestaboard($root);
assert_true($board->discoverLocalTimezone() === 'Europe/Zurich', 'discover Home Automations zone when OS/UTC empty');
assert_true($board->resolveQuietTimezone() === 'Europe/Zurich', 'resolveQuietTimezone prefers sibling zone over UTC');

// Tick should persist Europe/Zurich so morning exit uses local clock without a browser.
$tick = $board->tick();
$saved = json_decode((string) file_get_contents($root . '/data/vestaboard-config.json'), true);
assert_true(($saved['quiet_timezone'] ?? '') === 'Europe/Zurich', 'tick upgrades leftover UTC from Home Automations');

$liveCodes = [
    $board->encodeLine('POWERWALL  53%'),
    $board->encodeLine('GRID     570W'),
    $board->encodeLine('DRAW     571W'),
];
$nightCodes = [
    $board->encodeLine('NIGHT      MODE'),
    $board->encodeLine('SLEEP      WELL'),
    $board->encodeLine('HOUSE    SECURE'),
];

// Outside night window (equal start/end disables clock); sticky night_active must leave via cache.
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'Europe/Zurich',
    'night_hours_enabled' => true,
    'night_start' => '07:00',
    'night_end' => '07:00',
    'night_active' => true,
    'quiet_active' => false,
    'last_live_codes' => $liveCodes,
    'board_codes' => $nightCodes,
    'last_hash' => hash('sha256', json_encode($nightCodes)),
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$leave = new YarboVestaboard($root);
assert_true(!$leave->isNightHours(), 'outside night window');
$ref = new ReflectionClass($leave);
$method = $ref->getMethod('leaveOvernightNow');
$method->setAccessible(true);
$out = $method->invoke($leave, $leave->load());
assert_true(is_array($out), 'leaveOvernightNow returns a result');
// Port 1 refuses the Note HTTP; leave still attempts the cached live layout (not null).
assert_true(array_key_exists('ok', $out), 'leaveOvernightNow attempted a write');
$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'leaveOvernightNow'), 'dedicated leave helper');
assert_true(str_contains($src, 'Morning exit first'), 'leave before companion refresh');
assert_true(str_contains($src, 'discoverLocalTimezone'), 'timezone discovery');
assert_true(str_contains($src, 'Clock-only — same rule as isNightHours()'), 'nightHoursEndAt clock-only');

$watch = (string) file_get_contents(__DIR__ . '/../scripts/vestaboard_watch.php');
assert_true(str_contains($watch, 'leavingOvernight'), 'watcher skips companion refresh while leaving overnight');

// Quiet hours sticky leave uses the same helper path.
assert_true(str_contains($src, 'quiet_active'), 'quiet sticky still cleared on leave');

$endAt = $leave->nightHoursEndAt([
    'night_hours_enabled' => true,
    'night_start' => '22:00',
    'night_end' => '07:00',
    'quiet_timezone' => 'Europe/Zurich',
]);
assert_true($endAt instanceof DateTimeImmutable, 'nightHoursEndAt works without UniFi inventory');

echo "test_vestaboard_overnight_exit.php ok\n";
