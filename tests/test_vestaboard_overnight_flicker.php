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

$role = $ref->getMethod('stickyOvernightRole');
$role->setAccessible(true);
assert_true($role->invoke($quiet, $quiet->load()) === 'quiet', 'sticky quiet role while active');

$leave = $ref->getMethod('leaveOvernightNow');
$leave->setAccessible(true);
assert_true($leave->invoke($quiet, $quiet->load()) === null, 'leave refused while sticky quiet');

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
assert_true(str_contains($src, 'stickyOvernightRole'), 'sticky overnight role');
assert_true(str_contains($src, 'Once a real local zone is stored'), 'timezone harden');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.321]'), 'changelog 4.0.321');
assert_true(str_contains($change, '## [4.0.322]'), 'changelog 4.0.322');

echo "test_vestaboard_overnight_flicker.php ok\n";
