<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

$mono = (string) file_get_contents($root . '/firmware/papermono/src/main.cpp');
$monoVer = (string) file_get_contents($root . '/firmware/papermono/src/version.h');
$colour = (string) file_get_contents($root . '/firmware/papercolor/src/main.cpp');
$colourVer = (string) file_get_contents($root . '/firmware/papercolor/src/version.h');
$paper = (string) file_get_contents($root . '/src/YarboPaperDevice.php');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($monoVer, '0.1.96'), 'PaperMono firmware 0.1.96');
assert_true(str_contains($monoVer, 'PAPERMONO_PLAN_VISIBLE 14'), '14 plans visible');
assert_true(str_contains($monoVer, 'PAPERMONO_LYMOW_ZONE_VISIBLE 12'), '12 Lymow zones visible');
assert_true(str_contains($mono, 'Rain detected — cannot start'), 'PaperMono rain note');
assert_true(str_contains($mono, 'rainDetected'), 'PaperMono rainDetected flag');
assert_true(str_contains($mono, 'statusLabel'), 'PaperMono statusLabel');
assert_true(str_contains($mono, 'plansGeom'), 'PaperMono plansGeom');
assert_true(str_contains($mono, 'drawButton(cx, cmdY, cw, cmdH, "DOCK"'), 'PaperMono Plans DOCK');
assert_true(str_contains($mono, 'drawButton(cx, cmdY, cw, cmdH, "STOP"'), 'PaperMono Plans STOP');
assert_true(str_contains($mono, 'NEXT "'), 'PaperMono PREV/NEXT');
assert_true(str_contains($mono, 'if (rainDetected)'), 'PaperMono blocks Start when raining');

assert_true(str_contains($colourVer, '0.2.30-colour'), 'Paper Colour firmware 0.2.29');
assert_true(str_contains($colour, 'Rain detected — cannot start a plan'), 'Paper Colour rain note');
assert_true(str_contains($colour, 'rainDetected'), 'Paper Colour rainDetected');

assert_true(str_contains($paper, "'rain_detected'"), 'companion sends rain_detected');
assert_true(str_contains($change, '## [4.0.280]'), 'changelog 4.0.280');
assert_true(str_contains($change, '## [4.0.282]'), 'changelog 4.0.282');
assert_true(str_contains($mono, 'bottom - listY - navH'), 'Plans geom fills panel height');
assert_true(str_contains($mono, 'bandRows'), 'Lymow geom fills panel height');

echo "ALL PASS\n";
