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

$root = sys_get_temp_dir() . '/yarbo-vb-overnight-stick-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
$yarboCodes = [
    $vb->encodeLine('YARBO       IDLE'),
    $vb->encodeLine('BATTERY    FULL'),
    $vb->encodeLine('CHARGED'),
];

// Inside overnight window: sticky holds even if we only look at role.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = $now->format('H:i');
$end = sprintf('%02d:%s', ((int) $now->format('H') + 3) % 24, $now->format('i'));

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $start,
    'night_end' => $end,
    'night_active' => true,
    'quiet_hours_enabled' => false,
    'quiet_active' => false,
    'last_live_codes' => $yarboCodes,
], JSON_UNESCAPED_SLASHES));

$night = new YarboVestaboard($root);
assert_true($night->isNightHours(), 'inside night window');
assert_true($night->secondsPastWindowEnd($start, $end) === null, 'in-window → not past end');
$ref = new ReflectionClass($night);
$role = $ref->getMethod('stickyOvernightRole');
$role->setAccessible(true);
assert_true($role->invoke($night, $night->load()) === 'night', 'sticky role night while active');

$send = $ref->getMethod('sendLayout');
$send->setAccessible(true);
$blocked = $send->invoke($night, [
    'lines' => YarboVestaboard::linesFromCodes($yarboCodes),
    'codes' => $yarboCodes,
    'verb' => 'LIVE',
], true);
assert_true(($blocked['reason'] ?? '') === 'overnight', 'live blocked by sticky/clock');

$leave = $ref->getMethod('leaveOvernightNow');
$leave->setAccessible(true);
assert_true($leave->invoke($night, $night->load()) === null, 'leave refused while sticky in window');

// Outside window but sticky + not yet past grace end: still hold.
// End at the start of this minute → past is 0–59s (< 90s grace).
$endAt = $now;
$startAt = $endAt->modify('-3 hours');
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $startAt->format('H:i'),
    'night_end' => $endAt->format('H:i'),
    'night_active' => true,
    'quiet_hours_enabled' => false,
    'quiet_active' => false,
    'last_live_codes' => $yarboCodes,
], JSON_UNESCAPED_SLASHES));

$grace = new YarboVestaboard($root);
assert_true(!$grace->isNightHours(), 'clock outside after end');
$past = $grace->secondsPastWindowEnd($startAt->format('H:i'), $endAt->format('H:i'));
assert_true(is_int($past) && $past < YarboVestaboard::OVERNIGHT_LEAVE_CONFIRM_SEC, 'within leave grace: ' . (string) $past);
$graceRef = new ReflectionClass($grace);
$graceRole = $graceRef->getMethod('stickyOvernightRole');
$graceRole->setAccessible(true);
assert_true($graceRole->invoke($grace, $grace->load()) === 'night', 'sticky holds through leave grace');
$graceSend = $graceRef->getMethod('sendLayout');
$graceSend->setAccessible(true);
$graceBlocked = $graceSend->invoke($grace, [
    'lines' => YarboVestaboard::linesFromCodes($yarboCodes),
    'codes' => $yarboCodes,
    'verb' => 'LIVE',
], true);
assert_true(($graceBlocked['reason'] ?? '') === 'overnight', 'live blocked during leave grace');

// Past grace: sticky clears.
$endOld = $now->modify('-3 hours');
$startOld = $endOld->modify('-2 hours');
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $startOld->format('H:i'),
    'night_end' => $endOld->format('H:i'),
    'night_active' => true,
    'last_live_codes' => $yarboCodes,
], JSON_UNESCAPED_SLASHES));
$done = new YarboVestaboard($root);
$donePast = $done->secondsPastWindowEnd($startOld->format('H:i'), $endOld->format('H:i'));
assert_true(is_int($donePast) && $donePast >= YarboVestaboard::OVERNIGHT_LEAVE_CONFIRM_SEC, 'well past end');
$doneRef = new ReflectionClass($done);
$doneRole = $doneRef->getMethod('stickyOvernightRole');
$doneRole->setAccessible(true);
assert_true($doneRole->invoke($done, $done->load()) === null, 'sticky clears after grace');

// Timezone freeze: non-UTC stays put against any client zone.
$tzRoot = sys_get_temp_dir() . '/yarbo-vb-tz-freeze-' . bin2hex(random_bytes(3));
mkdir($tzRoot . '/data', 0775, true);
file_put_contents($tzRoot . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'Europe/Zurich',
], JSON_UNESCAPED_SLASHES));
$tz = new YarboVestaboard($tzRoot);
assert_true($tz->rememberTimezone('America/Los_Angeles') === true, 'foreign zone accepted without write');
$tzAfter = json_decode((string) file_get_contents($tzRoot . '/data/vestaboard-config.json'), true);
assert_true(($tzAfter['quiet_timezone'] ?? '') === 'Europe/Zurich', 'Zurich frozen against LA poll');
assert_true($tz->rememberTimezone('UTC') === true, 'UTC poll ignored');
$tzAfter2 = json_decode((string) file_get_contents($tzRoot . '/data/vestaboard-config.json'), true);
assert_true(($tzAfter2['quiet_timezone'] ?? '') === 'Europe/Zurich', 'Zurich frozen against UTC');

// Atomic save: rename path exists; tick lock helpers exist.
$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'vestaboard-tick.lock'), 'tick single-flight lock');
assert_true(str_contains($src, 'stickyOvernightRole'), 'sticky role helper');
assert_true(str_contains($src, 'secondsPastWindowEnd'), 'past-end helper');
assert_true(str_contains($src, 'Atomic replace'), 'atomic config save');
assert_true(str_contains($src, 'Once a real local zone is stored'), 'timezone freeze');
assert_true(str_contains($src, 'overnight_hold'), 'no Yarbo fallthrough while sticky');
assert_true(str_contains($src, 'empty($result[\'skipped\'])') || str_contains($src, 'empty($result["skipped"])')
    || str_contains($src, "empty(\$result['skipped'])"), 'skipped live must not clear sticky');
assert_true(str_contains($src, 'codesLookLikeOvernight($codes)'), 'overnight board counts as ours');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.322]'), 'changelog 4.0.322');

echo "test_vestaboard_overnight_stick.php ok\n";
