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

function paperRoot(array $hub, array $vb, array $ly = []): string
{
    $root = sys_get_temp_dir() . '/yarbo-paper-leds-' . bin2hex(random_bytes(3));
    mkdir($root . '/data', 0775, true);
    file_put_contents($root . '/data/hub-config.json', json_encode($hub, JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/data/vestaboard-config.json', json_encode($vb, JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/data/papermono-devices.json', json_encode([
        'devices' => [['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papercolor', 'token' => 'tok1']],
        'prefs' => [],
        'messages' => [],
    ], JSON_UNESCAPED_SLASHES));
    if ($ly !== []) {
        file_put_contents($root . '/data/lymow-state.json', json_encode($ly, JSON_UNESCAPED_SLASHES));
    }

    return $root;
}

function compactFlags(string $root, ?array $parsed, bool $online): array
{
    $method = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
    $method->setAccessible(true);
    $out = $method->invoke(
        new YarboPaperDevice($root),
        $parsed,
        $online,
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papercolor']
    );

    return [
        'error' => (bool) ($out['vestaboard_error'] ?? false),
        'rain' => (bool) ($out['vestaboard_rain'] ?? false),
    ];
}

$enabledVb = ['enabled' => true];
$yarboHub = [
    'modules' => ['yarbo' => true, 'powerwall' => false, 'lymow' => false, 'home' => false],
    'vestaboard_live' => 'yarbo',
];

$rain = compactFlags(paperRoot($yarboHub, $enabledVb), [
    'error_code' => 0,
    'power_fault' => 0,
    'rain_detected' => true,
], true);
assert_true($rain['rain'] === true && $rain['error'] === false, 'Yarbo rain lights blue: ' . json_encode($rain));

$err = compactFlags(paperRoot($yarboHub, $enabledVb), [
    'error_code' => 12,
    'power_fault' => 0,
    'rain_detected' => true,
], true);
assert_true($err['error'] === true && $err['rain'] === false, 'Yarbo error trumps rain: ' . json_encode($err));

$off = compactFlags(paperRoot($yarboHub, ['enabled' => false]), [
    'error_code' => 12,
    'rain_detected' => true,
], true);
assert_true($off['error'] === false && $off['rain'] === false, 'Note off keeps LEDs dark: ' . json_encode($off));

$holdRoot = paperRoot($yarboHub, [
    'enabled' => true,
    'external_hold' => true,
    'external_hold_until' => gmdate('c', time() + 3600),
    'board_codes' => [
        array_fill(0, 15, 1),
        array_fill(0, 15, 1),
        array_fill(0, 15, 1),
    ],
]);
$hold = compactFlags($holdRoot, ['error_code' => 12, 'rain_detected' => true], true);
assert_true($hold['error'] === false && $hold['rain'] === false, 'Vestaboard app hold is not a device error: ' . json_encode($hold));

$lyHub = [
    'modules' => ['yarbo' => false, 'powerwall' => false, 'lymow' => true, 'home' => false],
    'vestaboard_live' => 'lymow',
];
$lyErr = compactFlags(paperRoot($lyHub, $enabledVb, [
    'ok' => true,
    'online' => true,
    'battery' => 80,
    'work_status' => 7,
    'robot_state' => 7,
    'error_code' => 77,
]), ['error_code' => 0, 'rain_detected' => true], true);
assert_true($lyErr['error'] === true && $lyErr['rain'] === false, 'Lymow Note error is red, not Yarbo rain: ' . json_encode($lyErr));

$allHub = [
    'modules' => ['yarbo' => true, 'powerwall' => true, 'lymow' => true, 'home' => false],
    'vestaboard_live' => 'batteries',
];
$allErr = compactFlags(paperRoot($allHub, $enabledVb, [
    'ok' => true,
    'online' => true,
    'battery' => 80,
    'work_status' => 7,
    'robot_state' => 7,
]), ['error_code' => 0, 'rain_detected' => true], true);
assert_true($allErr['error'] === true && $allErr['rain'] === false, 'ALL view red if a shown robot is in error: ' . json_encode($allErr));

$allRain = compactFlags(paperRoot($allHub, $enabledVb), [
    'error_code' => 0,
    'rain_detected' => true,
], true);
assert_true($allRain['error'] === false && $allRain['rain'] === false, 'ALL view does not show rain so LEDs stay off: ' . json_encode($allRain));

$color = (string) file_get_contents(__DIR__ . '/../firmware/papercolor/src/main.cpp');
$ver = (string) file_get_contents(__DIR__ . '/../firmware/papercolor/src/version.h');
$php = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($ver, '0.2.25-colour'), 'firmware 0.2.25-colour');
assert_true(str_contains($php, 'function vestaboardLedFlags'), 'compact computes Vestaboard LED flags');
assert_true(str_contains($color, 'void applyStatusLeds()'), 'Paper Colour drives RGB LEDs');
assert_true(str_contains($color, 'M5.Led.setAllColor(255, 0, 0)'), 'error is red');
assert_true(str_contains($color, 'M5.Led.setAllColor(0, 0, 255)'), 'rain is blue');
assert_true(str_contains($color, 'if (vestaboardError)'), 'red trumps blue');
assert_true(str_contains($change, '## [4.0.127]'), 'changelog 4.0.127');

echo "test_papercolor_leds.php ok\n";
