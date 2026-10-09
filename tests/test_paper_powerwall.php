<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPowerwall;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$mono = (string) file_get_contents($root . '/firmware/papermono/src/main.cpp');
$color = (string) file_get_contents($root . '/firmware/papercolor/src/main.cpp');
$mver = (string) file_get_contents($root . '/firmware/papermono/src/version.h');
$cver = (string) file_get_contents($root . '/firmware/papercolor/src/version.h');
$php = (string) file_get_contents($root . '/src/YarboPaperDevice.php');
$pwPhp = (string) file_get_contents($root . '/src/YarboPowerwall.php');
$html = (string) file_get_contents($root . '/public/index.php');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($mver, '#define PAPERMONO_FW_VERSION "0.1.65"'), 'firmware 0.1.65');
assert_true(str_contains($cver, '#define PAPERMONO_FW_VERSION "0.2.19-colour"'), 'firmware 0.2.19-colour');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.65';"), 'panel firmware pin 0.1.65');
assert_true(str_contains($php, "public const FIRMWARE_VERSION_COLOR = '0.2.19-colour';"), 'panel colour pin 0.2.19-colour');
assert_true(str_contains($php, "'powerwall_grid'"), 'compact sends grid');
assert_true(str_contains($php, "'powerwall_battery'"), 'compact sends battery flow');
assert_true(str_contains($pwPhp, 'function formatFlowLabel'), 'directed watt labels');
assert_true(str_contains($mono, 'drawKv("Grid", powerwallGrid, 300)'), 'PaperMono Grid row');
assert_true(str_contains($mono, 'drawKv("Battery", powerwallBattery, 340)'), 'PaperMono Battery row');
assert_true(str_contains($color, 'drawKv("Grid", powerwallGrid, 300)'), 'Paper Colour Grid row');
assert_true(str_contains($color, 'drawKv("Battery", powerwallBattery, 340)'), 'Paper Colour Battery row');
assert_true(str_contains($mono, 'doc["powerwall_grid"]'), 'PaperMono parses grid');
assert_true(str_contains($color, 'doc["powerwall_battery"]'), 'Paper Colour parses battery flow');
assert_true(str_contains($html, 'Grid       Export 0.8 kW'), 'PaperMono preview Grid');
assert_true(str_contains($html, 'Battery    Charge 0.4 kW'), 'PaperMono preview Battery');
assert_true(str_contains($html, 'Grid      Export 0.8 kW'), 'Paper Colour preview Grid');
assert_true(str_contains($html, 'firmware 0.1.65'), 'settings hint 0.1.65');
assert_true(str_contains($html, '0.2.19-colour'), 'settings hint 0.2.19-colour');
assert_true(str_contains($change, '## [4.0.74]'), 'changelog 4.0.74');

assert_true(YarboPowerwall::formatFlowLabel(-1200, 'Import', 'Export') === 'Export 1200W', 'grid export label');
assert_true(YarboPowerwall::formatFlowLabel(400, 'Import', 'Export') === 'Import 400W', 'grid import label');
assert_true(YarboPowerwall::formatFlowLabel(4, 'Import', 'Export') === 'Idle', 'grid idle');
assert_true(YarboPowerwall::formatFlowLabel(-800, 'Discharge', 'Charge') === 'Charge 800W', 'battery charge label');
assert_true(YarboPowerwall::formatFlowLabel(1500, 'Discharge', 'Charge') === 'Discharge 1500W', 'battery discharge label');

echo "test_paper_powerwall.php ok\n";
