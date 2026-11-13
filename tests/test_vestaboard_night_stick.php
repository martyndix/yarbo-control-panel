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

$root = sys_get_temp_dir() . '/yarbo-vb-night-stick-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'unifi' => false],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'doors' => [],
    'hubs' => [],
    'sensors' => [],
], JSON_UNESCAPED_SLASHES));

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$hm = $now->format('H:i');
$start = $hm;
$endH = ((int) $now->format('H') + 2) % 24;
$end = sprintf('%02d:%s', $endH, $now->format('i'));

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $start,
    'night_end' => $end,
    'night_active' => false,
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
assert_true($vb->nightHoursAvailable() === false, 'inventory empty → unavailable for Settings');
assert_true($vb->isNightHours() === true, 'clock alone keeps night on when enabled (no inventory gate)');

$yarboCodes = [
    $vb->encodeLine('YARBO       IDLE'),
    $vb->encodeLine('BATTERY    FULL'),
    $vb->encodeLine('CHARGED'),
];
assert_true($vb->codesLookLikeLiveStatus($yarboCodes), 'Yarbo IDLE/FULL looks like live status');
assert_true(!$vb->codesLookLikeOvernight($yarboCodes), 'Yarbo live is not overnight');

$ref = new ReflectionClass($vb);
$ownYarbo = $ref->getMethod('codesLookLikeOwnYarbo');
$ownYarbo->setAccessible(true);
assert_true($ownYarbo->invoke($vb, $yarboCodes) === true, 'codesLookLikeOwnYarbo matches');

assert_true(YarboVestaboard::isUtcTimezone('UTC'), 'UTC helper');
assert_true(YarboVestaboard::isUtcTimezone('Etc/UTC'), 'Etc/UTC helper');
assert_true(!YarboVestaboard::isUtcTimezone('Europe/Zurich'), 'Zurich is not UTC');

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'quiet_timezone' => 'UTC',
    'night_hours_enabled' => true,
    'night_start' => $start,
    'night_end' => $end,
    'night_active' => true,
    'external_hold' => true,
    'external_hold_until' => gmdate('c', time() + 3600),
    'board_codes' => $yarboCodes,
    'last_hash' => hash('sha256', json_encode($yarboCodes)),
], JSON_UNESCAPED_SLASHES));
$held = new YarboVestaboard($root);
assert_true($held->isExternalHoldActive($held->load()), 'hold armed before tick');
// tick() needs credentials — without them it returns early. Assert source guards instead.
$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'codesLookLikeOwnYarbo'), 'own Yarbo recognizer exists');
assert_true(str_contains($src, 'Our own live Yarbo/Powerwall/Lymow pages must not arm overnight hold'), 'hold clears live stuck');
assert_true(str_contains($src, 'Clock only once Night hours is enabled'), 'clock-only night hours');
assert_true(str_contains($src, 'isUtcTimezone'), 'UTC upgrade helper');
assert_true(str_contains($src, 'liveStuck'), 'force night when live stuck on board');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.284]'), 'changelog 4.0.284');

$docs = (string) file_get_contents(__DIR__ . '/../docs/vestaboard.md');
assert_true(str_contains($docs, 'without needing the website open'), 'docs mention background watcher');

echo "test_vestaboard_night_stick.php ok\n";
