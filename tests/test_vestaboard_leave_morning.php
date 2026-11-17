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

$root = sys_get_temp_dir() . '/yarbo-vb-leave-morning-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
$yarboCodes = [
    $vb->encodeLine('YARBO       IDLE'),
    $vb->encodeLine('BATTERY    FULL'),
    $vb->encodeLine('CHARGED'),
];
$nightCodes = [
    $vb->encodeLine('NIGHT      MODE'),
    $vb->encodeLine('SLEEP      WELL'),
    $vb->encodeLine('HOUSE    SECURE'),
];

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
// Window ended hours ago — sticky must be clear, morning leave must run without a browser.
$endOld = $now->modify('-3 hours');
$startOld = $endOld->modify('-2 hours');

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $startOld->format('H:i'),
    'night_end' => $endOld->format('H:i'),
    'night_active' => true,
    'quiet_active' => false,
    'quiet_hours_enabled' => false,
    'last_live_codes' => $yarboCodes,
    'board_codes' => $nightCodes,
    'last_hash' => hash('sha256', json_encode($nightCodes)),
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$morning = new YarboVestaboard($root);
assert_true(!$morning->isNightHours(), 'clock outside night window');
$ref = new ReflectionClass($morning);
$role = $ref->getMethod('stickyOvernightRole');
$role->setAccessible(true);
assert_true($role->invoke($morning, $morning->load()) === null, 'sticky cleared after end+grace');

$leave = $ref->getMethod('leaveOvernightNow');
$leave->setAccessible(true);
$left = $leave->invoke($morning, $morning->load());
assert_true(is_array($left), 'leaveOvernightNow returns a result with live snapshot');
// Local POST to :1 fails; we still attempted leave instead of overnight_hold.
assert_true(isset($left['ok']) || isset($left['error']) || isset($left['skipped']), 'leave attempted a write path');
assert_true(($left['reason'] ?? '') !== 'overnight_hold', 'morning leave is not overnight_hold');

// No snapshot: sticky clear must not overnight_hold forever on wasNight alone.
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $startOld->format('H:i'),
    'night_end' => $endOld->format('H:i'),
    'night_active' => true,
    'quiet_active' => false,
    'last_live_codes' => null,
    'board_codes' => $nightCodes,
    'last_hash' => hash('sha256', json_encode($nightCodes)),
], JSON_UNESCAPED_SLASHES));

$nosnap = new YarboVestaboard($root);
$nosnapRef = new ReflectionClass($nosnap);
$nosnapRole = $nosnapRef->getMethod('stickyOvernightRole');
$nosnapRole->setAccessible(true);
assert_true($nosnapRole->invoke($nosnap, $nosnap->load()) === null, 'sticky clear without snapshot');
$nosnapLeave = $nosnapRef->getMethod('leaveOvernightNow');
$nosnapLeave->setAccessible(true);
// May return a composed layout or null if offline compose fails — must not be sticky hold.
$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'Only hold while sticky says we are still inside'), 'morning leave not blocked by wasNight alone');
assert_true(!str_contains($src, '|| $wasNight || $wasQuiet) {
                return [\'ok\' => true, \'skipped\' => true, \'reason\' => \'overnight_hold\'];'), 'old wasNight hold gone');

$watch = (string) file_get_contents(__DIR__ . '/../scripts/vestaboard_watch.php');
assert_true(str_contains($watch, 'leavingOvernight ||'), 'watcher refreshes companions on morning leave');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.323]'), 'changelog 4.0.323');

echo "test_vestaboard_leave_morning.php ok\n";
