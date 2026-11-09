<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPaperDevice;
use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-paper-vb-hold-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true, 'vestaboard' => true],
    'vestaboard_live' => 'powerwall',
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
$appCodes = [
    $vb->encodeLine(' A SUN-BURNED  '),
    $vb->encodeLine('POLAR BEAR IS A'),
    $vb->encodeLine('  SOLAR BEAR   '),
];
$liveCodes = [
    $vb->encodeLine('POWERWALL  88% '),
    $vb->encodeLine('SOLAR     1.2KW'),
    $vb->encodeLine('HOME      0.8KW'),
];

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'last_live_codes' => $liveCodes,
    'board_codes' => $appCodes,
    'external_hold' => true,
    'external_hold_until' => gmdate('c', time() + 3600),
], JSON_UNESCAPED_SLASHES));

$compact = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compact->setAccessible(true);

$paper = new YarboPaperDevice($root);
$held = $compact->invoke($paper, null, false, null);
$heldLines = implode("\n", is_array($held['vestaboard_lines'] ?? null) ? $held['vestaboard_lines'] : []);
assert_true(str_contains($heldLines, 'POLAR BEAR'), 'paper hold lines: ' . $heldLines);
assert_true(str_contains($heldLines, 'SOLAR BEAR'), 'paper hold solar: ' . $heldLines);
assert_true(!str_contains($heldLines, 'POWERWALL'), 'hold must not keep live Powerwall: ' . $heldLines);
assert_true(($held['vestaboard_codes'] ?? null) === $appCodes, 'paper hold codes match the Vestaboard app');

$dash = $vb->dashboardPayload(null, false);
assert_true(($dash['codes'] ?? null) === $appCodes, 'dashboard hold codes match paper');
assert_true(($dash['verb'] ?? '') === 'APP', 'dashboard verb APP');

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'last_live_codes' => $liveCodes,
    'board_codes' => $appCodes,
    'external_hold' => true,
    'external_hold_until' => gmdate('c', time() - 60),
], JSON_UNESCAPED_SLASHES));

$expired = $compact->invoke(new YarboPaperDevice($root), null, false, null);
$expiredLines = implode("\n", is_array($expired['vestaboard_lines'] ?? null) ? $expired['vestaboard_lines'] : []);
assert_true(!str_contains($expiredLines, 'POLAR BEAR'), 'expired hold must leave the app message: ' . $expiredLines);

$nightCodes = [
    $vb->encodeLine('NIGHT   MODE   '),
    $vb->encodeLine('SLEEP   WELL   '),
    $vb->encodeLine('HOUSE   SECURE '),
];
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_active' => true,
    'quiet_active' => false,
    'last_live_codes' => $liveCodes,
    'board_codes' => $nightCodes,
    'external_hold' => false,
], JSON_UNESCAPED_SLASHES));
$night = $compact->invoke(new YarboPaperDevice($root), null, false, null);
$nightLines = implode("\n", is_array($night['vestaboard_lines'] ?? null) ? $night['vestaboard_lines'] : []);
assert_true(str_contains($nightLines, 'NIGHT'), 'night compact shows night board: ' . $nightLines);
assert_true(str_contains($nightLines, 'HOUSE'), 'night compact shows secure line: ' . $nightLines);
assert_true(!str_contains($nightLines, 'POWERWALL'), 'night must not recompose Powerwall: ' . $nightLines);
assert_true(($night['vestaboard_mode'] ?? '') === 'night', 'vestaboard_mode night');
assert_true(!empty($night['vestaboard_night']), 'vestaboard_night flag');

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_active' => false,
    'quiet_active' => true,
    'last_live_codes' => $liveCodes,
    'board_codes' => [
        $vb->encodeLine('GOOD     NIGHT '),
        $vb->encodeLine('SEE YOU  SOON  '),
        $vb->encodeLine('               '),
    ],
    'external_hold' => false,
], JSON_UNESCAPED_SLASHES));
$quiet = $compact->invoke(new YarboPaperDevice($root), null, false, null);
assert_true(($quiet['vestaboard_mode'] ?? '') === 'quiet', 'vestaboard_mode quiet');
assert_true(!empty($quiet['vestaboard_quiet']), 'vestaboard_quiet flag');
$quietLines = implode("\n", is_array($quiet['vestaboard_lines'] ?? null) ? $quiet['vestaboard_lines'] : []);
assert_true(str_contains($quietLines, 'GOOD'), 'quiet compact shows board: ' . $quietLines);
assert_true(!str_contains($quietLines, 'POWERWALL'), 'quiet must not recompose Powerwall: ' . $quietLines);

$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
assert_true(str_contains($php, 'isExternalHoldActive($vb)'), 'paper compact checks Vestaboard app hold');
assert_true(str_contains($php, "vestaboard_mode"), 'compact exposes vestaboard_mode');
assert_true(str_contains($php, 'night_active'), 'compact checks night_active');
assert_true(str_contains($php, 'isNightHours()'), 'compact uses night clock');
assert_true(str_contains($php, 'isQuietHours()'), 'compact uses quiet clock');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');
assert_true(str_contains($fw, 'NIGHT MODE'), 'Note page labels night mode');
assert_true(str_contains($fw, 'QUIET MODE'), 'Note page labels quiet mode');
assert_true(str_contains($fw, 'PAPERMONO_EPD_MIN_GAP_MS'), 'e-paper min gap');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');
assert_true(str_contains($ver, 'PAPERMONO_EPD_MIN_GAP_MS'), 'version.h defines EPD gap');
assert_true(str_contains($ver, 'PAPERMONO_LOCK_POLL_MS 20000UL'), 'lock poll 20s');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.105]'), 'changelog 4.0.105');
assert_true(str_contains($change, '## [4.0.244]'), 'changelog 4.0.244');
assert_true(str_contains($change, '## [4.0.246]'), 'changelog 4.0.246');

// Clock-only night (watcher has not set night_active yet): still night mode,
// and prefer composed night layout over leftover live board_codes.
$clockNightCodes = [
    $vb->encodeLine('NIGHT   CLOCK  '),
    $vb->encodeLine('BEFORE  POST   '),
    $vb->encodeLine('HOUSE   SECURE '),
];
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_hours_enabled' => true,
    'night_start' => '00:00',
    'night_end' => '23:59',
    'night_active' => false,
    'quiet_active' => false,
    'quiet_timezone' => 'UTC',
    'last_live_codes' => $liveCodes,
    'board_codes' => $liveCodes,
    'external_hold' => false,
], JSON_UNESCAPED_SLASHES));
// nightLayout needs UniFi contacts available — stub inventory so nightHoursAvailable.
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true, 'vestaboard' => true, 'unifi' => true, 'home' => true],
    'vestaboard_live' => 'powerwall',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => 'x',
    'show_on_home' => ['unifi:sensor:front'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => [[
        'id' => 'unifi:sensor:front',
        'native_id' => 'front',
        'name' => 'Front Door',
        'kind' => 'sensor',
        'source' => 'unifi',
        'has_open' => true,
        'open' => false,
        'status' => 'Closed',
        'on' => false,
    ]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode(['hidden' => [], 'last_devices' => []], JSON_UNESCAPED_SLASHES));
$clockNight = $compact->invoke(new YarboPaperDevice($root), null, false, null);
assert_true(($clockNight['vestaboard_mode'] ?? '') === 'night', 'clock night sets mode: ' . json_encode($clockNight['vestaboard_mode'] ?? null));
assert_true(!empty($clockNight['vestaboard_night']), 'clock night sets flag');
$clockLines = implode("\n", is_array($clockNight['vestaboard_lines'] ?? null) ? $clockNight['vestaboard_lines'] : []);
assert_true(!str_contains($clockLines, 'POWERWALL'), 'clock night must not keep live Powerwall: ' . $clockLines);

echo "test_paper_vestaboard_hold.php ok\n";
