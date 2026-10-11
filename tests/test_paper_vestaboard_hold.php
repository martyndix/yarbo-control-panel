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

$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
assert_true(str_contains($php, 'isExternalHoldActive($vb)'), 'paper compact checks Vestaboard app hold');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.105]'), 'changelog 4.0.105');

echo "test_paper_vestaboard_hold.php ok\n";
