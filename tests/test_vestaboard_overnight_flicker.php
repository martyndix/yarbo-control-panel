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

$root = sys_get_temp_dir() . '/yarbo-vb-overnight-flicker-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'unifi' => false],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = $now->format('H:i');
$endH = ((int) $now->format('H') + 3) % 24;
$end = sprintf('%02d:%s', $endH, $now->format('i'));

$vb = new YarboVestaboard($root);
$yarboCodes = [
    $vb->encodeLine('YARBO       IDLE'),
    $vb->encodeLine('BATTERY    FULL'),
    $vb->encodeLine('CHARGED'),
];
$quietCodes = YarboVestaboard::defaultQuietCodesStatic();
$quietHash = hash('sha256', json_encode($quietCodes));

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'quiet_hours_enabled' => true,
    'quiet_start' => $start,
    'quiet_end' => $end,
    'quiet_codes' => $quietCodes,
    'quiet_active' => true,
    'night_hours_enabled' => false,
    'night_active' => false,
    'last_live_codes' => $yarboCodes,
    'board_codes' => $quietCodes,
    'last_hash' => $quietHash,
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$quiet = new YarboVestaboard($root);
assert_true($quiet->isQuietHours(), 'inside quiet window');
assert_true(!$quiet->isNightHours(), 'night off');

$ref = new ReflectionClass($quiet);
$send = $ref->getMethod('sendLayout');
$send->setAccessible(true);
$blocked = $send->invoke($quiet, [
    'lines' => YarboVestaboard::linesFromCodes($yarboCodes),
    'codes' => $yarboCodes,
    'verb' => 'LIVE',
], true);
assert_true(!empty($blocked['skipped']) && ($blocked['reason'] ?? '') === 'overnight', 'live POST blocked during quiet');
$afterBlock = json_decode((string) file_get_contents($root . '/data/vestaboard-config.json'), true);
assert_true(
    json_encode($afterBlock['board_codes'] ?? null) === json_encode($quietCodes),
    'blocked live write must not change board_codes'
);

$confirm = $ref->getMethod('overnightLeaveConfirmed');
$confirm->setAccessible(true);

// Sticky night + clock outside: first check arms the timer, does not confirm leave.
$outsideStart = $now->modify('-4 hours')->format('H:i');
$outsideEnd = $now->modify('-2 hours')->format('H:i');
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $outsideStart,
    'night_end' => $outsideEnd,
    'night_active' => true,
    'quiet_hours_enabled' => false,
    'quiet_active' => false,
    'overnight_outside_since' => null,
    'last_live_codes' => $yarboCodes,
], JSON_UNESCAPED_SLASHES));
$sticky = new YarboVestaboard($root);
assert_true(!$sticky->isNightHours(), 'clock outside night window');
$stickyRef = new ReflectionClass($sticky);
$stickyConfirm = $stickyRef->getMethod('overnightLeaveConfirmed');
$stickyConfirm->setAccessible(true);
assert_true($stickyConfirm->invoke($sticky, $sticky->load()) === false, 'first outside tick arms leave timer');
$armed = json_decode((string) file_get_contents($root . '/data/vestaboard-config.json'), true);
assert_true(
    is_string($armed['overnight_outside_since'] ?? null) && ($armed['overnight_outside_since'] ?? '') !== '',
    'overnight_outside_since stamped'
);
assert_true($stickyConfirm->invoke($sticky, $sticky->load()) === false, 'still inside confirm window');

$armed['overnight_outside_since'] = gmdate('c', time() - YarboVestaboard::OVERNIGHT_LEAVE_CONFIRM_SEC - 5);
file_put_contents($root . '/data/vestaboard-config.json', json_encode($armed, JSON_UNESCAPED_SLASHES));
$ready = new YarboVestaboard($root);
$readyRef = new ReflectionClass($ready);
$readyConfirm = $readyRef->getMethod('overnightLeaveConfirmed');
$readyConfirm->setAccessible(true);
assert_true($readyConfirm->invoke($ready, $ready->load()) === true, 'sustained outside confirms leave');

$tzRoot = sys_get_temp_dir() . '/yarbo-vb-tz-' . bin2hex(random_bytes(3));
mkdir($tzRoot . '/data', 0775, true);
file_put_contents($tzRoot . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'Europe/Zurich',
], JSON_UNESCAPED_SLASHES));
$tz = new YarboVestaboard($tzRoot);
assert_true($tz->rememberTimezone('UTC') === true, 'UTC remember accepted without overwrite');
$tzAfter = json_decode((string) file_get_contents($tzRoot . '/data/vestaboard-config.json'), true);
assert_true(($tzAfter['quiet_timezone'] ?? '') === 'Europe/Zurich', 'non-UTC zone kept against UTC poll');

$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'OVERNIGHT_LEAVE_CONFIRM_SEC'), 'leave confirm constant');
assert_true(str_contains($src, 'forceQuiet'), 'quiet force only on enter/stuck');
assert_true(str_contains($src, "reason' => 'overnight'"), 'sendLayout overnight hard stop');
assert_true(str_contains($src, 'Never let a browser/status poll replace a real local zone with UTC'), 'timezone harden');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.321]'), 'changelog 4.0.321');

echo "test_vestaboard_overnight_flicker.php ok\n";
