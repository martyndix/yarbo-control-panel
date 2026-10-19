<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function viewerRoot(array $hub, array $vb, array $prefs = []): string
{
    $root = sys_get_temp_dir() . '/yarbo-paper-viewer-' . bin2hex(random_bytes(3));
    mkdir($root . '/data', 0775, true);
    file_put_contents($root . '/data/hub-config.json', json_encode($hub, JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/data/vestaboard-config.json', json_encode($vb, JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/data/papermono-devices.json', json_encode([
        'devices' => [['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papercolor', 'token' => 'tok1']],
        'prefs' => $prefs,
        'messages' => [],
    ], JSON_UNESCAPED_SLASHES));

    return $root;
}

function compactOut(string $root): array
{
    $method = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
    $method->setAccessible(true);

    return $method->invoke(
        new YarboPaperDevice($root),
        ['error_code' => 0],
        true,
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papercolor']
    );
}

function usbFields(string $root): array
{
    $method = new ReflectionMethod(YarboPaperDevice::class, 'usbCompanionFields');
    $method->setAccessible(true);

    return $method->invoke(new YarboPaperDevice($root));
}

$hubYarboOff = [
    'modules' => ['yarbo' => false, 'powerwall' => true, 'lymow' => false, 'home' => false],
];
$vbOn = ['enabled' => true];
$vbOff = ['enabled' => false];

$saved = (new YarboPaperDevice(viewerRoot($hubYarboOff, $vbOn)))->savePrefs([
    'vestaboard_viewer' => true,
]);
assert_true(($saved['prefs']['vestaboard_viewer'] ?? false) === true, 'pref stores viewer only');

$on = compactOut(viewerRoot($hubYarboOff, $vbOn, ['vestaboard_viewer' => true]));
assert_true(($on['vestaboard_enabled'] ?? false) === true, 'compact Note on');
assert_true(($on['vestaboard_viewer'] ?? false) === true, 'compact viewer on when Note is on');
assert_true(($on['yarbo_enabled'] ?? true) === false, 'compact keeps Yarbo off');
assert_true(($on['powerwall_enabled'] ?? false) === true, 'compact keeps Powerwall on');

$off = compactOut(viewerRoot($hubYarboOff, $vbOff, ['vestaboard_viewer' => true]));
assert_true(($off['vestaboard_enabled'] ?? true) === false, 'compact Note off');
assert_true(($off['vestaboard_viewer'] ?? true) === false, 'compact ANDs viewer with Note');
$pref = (new YarboPaperDevice(viewerRoot($hubYarboOff, $vbOff, ['vestaboard_viewer' => true])))->publicPrefs();
assert_true(($pref['vestaboard_viewer'] ?? false) === true, 'Note off does not wipe the saved viewer pref');

$usb = usbFields(viewerRoot($hubYarboOff, $vbOn, ['vestaboard_viewer' => true]));
assert_true(($usb['yarbo_enabled'] ?? true) === false, 'USB CFG Yarbo off');
assert_true(($usb['powerwall_enabled'] ?? false) === true, 'USB CFG Powerwall on');
assert_true(($usb['lymow_enabled'] ?? true) === false, 'USB CFG Lymow off');
assert_true(($usb['vestaboard_enabled'] ?? false) === true, 'USB CFG Note on');
assert_true(($usb['vestaboard_viewer'] ?? false) === true, 'USB CFG viewer on');

$usbOff = usbFields(viewerRoot($hubYarboOff, $vbOff, ['vestaboard_viewer' => true]));
assert_true(($usbOff['vestaboard_viewer'] ?? true) === false, 'USB CFG viewer ANDs Note');

$cli = new ReflectionMethod(YarboPaperDevice::class, 'usbCompanionCliArgs');
$cli->setAccessible(true);
$args = $cli->invoke(new YarboPaperDevice(viewerRoot($hubYarboOff, $vbOn, ['vestaboard_viewer' => true])));
$joined = implode(' ', $args);
assert_true(str_contains($joined, '--yarbo 0'), 'flash CLI yarbo 0: ' . $joined);
assert_true(str_contains($joined, '--powerwall 1'), 'flash CLI powerwall 1');
assert_true(str_contains($joined, '--lymow 0'), 'flash CLI lymow 0');
assert_true(str_contains($joined, '--vestaboard 1'), 'flash CLI vestaboard 1');
assert_true(str_contains($joined, '--vestaboard-viewer 1'), 'flash CLI viewer 1');

$color = (string) file_get_contents(__DIR__ . '/../firmware/papercolor/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papercolor/src/version.h');
$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$flash = (string) file_get_contents(__DIR__ . '/../scripts/papermono_flash.py');
$kit = (string) file_get_contents(__DIR__ . '/../scripts/paper_setup_kit.py');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
$mono = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/version.h');

assert_true(str_contains($ver, '0.2.26-colour'), 'firmware 0.2.26-colour');
assert_true(str_contains($php, "public const FIRMWARE_VERSION_COLOR = '0.2.26-colour';"), 'panel colour pin 0.2.26-colour');
assert_true(str_contains($mono, '#define PAPERMONO_FW_VERSION "0.1.69"'), 'PaperMono firmware unchanged');
assert_true(str_contains($color, 'bool viewerOnly()'), 'viewer-only helper');
assert_true(str_contains($color, 'void applyDisplayMode()'), 'landscape display mode');
assert_true(str_contains($color, 'M5.Imu.getAccel'), 'IMU landscape left/right');
assert_true(str_contains($color, 'landscapeRot == 1 ? 3 : 1'), 'A/B/C flip landscape without IMU');
assert_true(str_contains($color, 'jsonFlag(doc, "vestaboard_viewer"'), 'compact/USB parse viewer flag');
assert_true(str_contains($color, 'prefs.putBool("yon", yarboOn)'), 'persist module flags');
assert_true(str_contains($color, 'if (viewerOnly())'), 'pager skips to viewer board');
assert_true(!str_contains($color, 'clockLocal + "|" + String(usingRemote'), 'clock is not in the paged dirty key');
assert_true(str_contains($color, 'String clockHourKey()'), 'viewer clock buckets to the hour');
assert_true(str_contains($color, 'clockLocal.substring(0, colon)'), 'hour key uses HH, not MM');
assert_true(str_contains($color, 'tabletBat / 10'), 'viewer battery buckets to 10%');
assert_true(str_contains($color, 'epd_mode_t::epd_fastest'), 'Spectra 6 uses nearest palette');
assert_true(!str_contains($color, 'lastError + "|" + clockLocal'), 'viewer does not dirty every minute');
assert_true(!str_contains($color, '0x83'), 'Colour firmware does not send undocumented partial-window');
assert_true(str_contains($color, 'int clockBand = 56;'), 'viewer reserves a time band');
assert_true(str_contains($color, 'int glyphSize = (cell - 4) / 8;'), 'flap letters scale with cell size');
assert_true(str_contains($html, 'id="papermono-vestaboard-viewer"'), 'settings viewer checkbox');
assert_true(str_contains($html, '0.2.26-colour'), 'settings hint 0.2.26-colour');
assert_true(str_contains($color, 'int foot = 44;'), 'viewer leaves a footer for battery badge');
assert_true(str_contains($color, 'void drawBatteryBadge(int right, int cy, int pct, bool compact)'), 'PaperMono-style battery badge');
assert_true(str_contains($color, 'void drawChargeBolt(int cx, int cy, int size)'), 'charging bolt');
assert_true(str_contains($color, 'drawBatteryBadge(batRight, batCy, tabletBat, true);'), 'viewer draws compact badge');
assert_true(str_contains($color, 'drawChargeBolt(batRight + 20, batCy, 28);'), 'viewer bolt when plugged in');
assert_true(str_contains($js, 'function applyVestaboardViewerGate()'), 'checkbox gated on Note');
assert_true(str_contains($js, 'vestaboard_viewer: !!els.papermonoVestaboardViewer?.checked'), 'prefs payload includes viewer');
assert_true(str_contains($flash, '--vestaboard-viewer'), 'flash CLI flag');
assert_true(str_contains($kit, 'vestaboard_viewer'), 'setup kit CFG includes viewer');
assert_true(str_contains($change, '## [4.0.132]'), 'changelog 4.0.132');
$docs = (string) file_get_contents(__DIR__ . '/../docs/papercolor.md');
assert_true(str_contains($docs, '## Refresh'), 'docs explain Spectra 6 refresh options');
assert_true(str_contains($docs, '0.2.26-colour'), 'docs pin 0.2.26-colour');

echo "test_papercolor_viewer.php ok\n";
