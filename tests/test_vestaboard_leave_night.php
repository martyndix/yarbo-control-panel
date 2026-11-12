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

$root = sys_get_temp_dir() . '/yarbo-vb-leave-night-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true, 'home' => true, 'unifi' => true],
    'vestaboard_live' => 'powerwall',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/powerwall-config.json', json_encode([
    'host' => '',
    'email' => '',
    'password' => '',
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
$liveCodes = [
    $vb->encodeLine('POWERWALL  53%'),
    $vb->encodeLine('GRID     570W'),
    $vb->encodeLine('DRAW     571W'),
];
$nightCodes = [
    $vb->encodeLine('NIGHT      MODE'),
    $vb->encodeLine('SLEEP      WELL'),
    $vb->encodeLine('HOUSE    SECURE'),
];
$nightHash = hash('sha256', json_encode($nightCodes));

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'host' => '127.0.0.1',
    'port' => 1,
    'api_key' => 'test-key',
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    // Equal start/end disables the clock window; sticky night_active alone is not enough.
    'night_start' => '07:00',
    'night_end' => '07:00',
    'night_active' => true,
    'quiet_active' => false,
    'last_live_codes' => $liveCodes,
    'board_codes' => $nightCodes,
    'last_hash' => $nightHash,
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$leave = new YarboVestaboard($root);
assert_true(!$leave->isNightHours(), 'clock is outside night window');
assert_true($leave->codesLookLikeOvernight($nightCodes), 'night codes look overnight');
assert_true($leave->codesLookLikeLiveStatus($liveCodes), 'powerwall codes look live');

$ref = new ReflectionClass($leave);
$lastLive = $ref->getMethod('lastLiveLayout');
$lastLive->setAccessible(true);
$cached = $lastLive->invoke($leave, $leave->load());
assert_true(is_array($cached), 'valid live snapshot is resumed');
assert_true(($cached['verb'] ?? '') === 'LIVE', 'resume verb is LIVE');

// Corrupted snapshot (night flaps saved as last_live) must be ignored.
$corrupted = $lastLive->invoke($leave, ['last_live_codes' => $nightCodes]);
assert_true($corrupted === null, 'overnight last_live snapshot is ignored');

$send = $ref->getMethod('sendLayout');
$send->setAccessible(true);
// Hash-skip night write must not overwrite the live snapshot (regression from 4.0.268 era).
$skip = $send->invoke($leave, [
    'lines' => YarboVestaboard::linesFromCodes($nightCodes),
    'codes' => $nightCodes,
    'verb' => 'NIGHT',
], false);
assert_true(!empty($skip['skipped']), 'matching night hash skips write');
$afterSkip = json_decode((string) file_get_contents($root . '/data/vestaboard-config.json'), true);
assert_true(
    json_encode($afterSkip['last_live_codes'] ?? null) === json_encode($liveCodes),
    'night skip must preserve last_live_codes'
);

$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'isOvernightLayoutVerb'), 'overnight verb helper exists');
assert_true(str_contains($src, 'Preserve the pre-overnight live snapshot'), 'sendLayout documents live snapshot guard');
assert_true(str_contains($src, 'Still leave overnight flaps even when companions are offline'), 'leave-night sends offline live page');

$watch = (string) file_get_contents(__DIR__ . '/../scripts/vestaboard_watch.php');
assert_true(str_contains($watch, 'isNightHours()'), 'watcher still ticks overnight without browser');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.269]'), 'changelog 4.0.269');

echo "ok\n";
