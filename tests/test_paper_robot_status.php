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

$php = (string) file_get_contents($root . '/src/YarboPaperDevice.php');
$mono = (string) file_get_contents($root . '/firmware/papermono/src/main.cpp');
$monoVer = (string) file_get_contents($root . '/firmware/papermono/src/version.h');
$colourVer = (string) file_get_contents($root . '/firmware/papercolor/src/version.h');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($php, 'yarboCompanionStatus'), 'yarbo status helper');
assert_true(str_contains($php, "'status_label'"), 'companion sends status_label');
assert_true(str_contains($php, 'Heading home'), 'heading home label');
assert_true(str_contains($php, 'On dock · Charging'), 'on dock charging label');
assert_true(str_contains($php, "'MOWING' => 'Mowing'"), 'lymow friendly MOWING');
assert_true(str_contains($php, "'DOCKING' => 'Heading home'"), 'lymow friendly DOCKING');

assert_true(str_contains($monoVer, '0.1.96'), 'PaperMono 0.1.96');
assert_true(str_contains($colourVer, '0.2.30-colour'), 'Paper Colour 0.2.30');
assert_true(str_contains($mono, 'statusLabel'), 'firmware statusLabel');
assert_true(str_contains($mono, 'Charge  "'), 'Plans charge line');
assert_true(str_contains($mono, 'lymowCharging'), 'Lymow charge line');
assert_true(str_contains($php, "FIRMWARE_VERSION = '0.1.96'"), 'panel pin 0.1.96');
assert_true(str_contains($change, '## [4.0.283]'), 'changelog 4.0.283');

// Behavioural: companion status from parsed snapshot.
require $root . '/vendor/autoload.php';
$ref = new ReflectionClass(\Yarbo\YarboPaperDevice::class);
$m = $ref->getMethod('yarboCompanionStatus');
$m->setAccessible(true);
assert_true($m->invoke(null, ['plan_running' => true, 'plan_status' => ['plan_name' => 'Patio']], 'Patio') === 'Mowing · Patio', 'mowing status');
assert_true($m->invoke(null, ['returning_to_dock' => true], '') === 'Heading home', 'docking status');
assert_true($m->invoke(null, ['charging_label' => 'Yes'], '') === 'On dock · Charging', 'charging status');
assert_true($m->invoke(null, ['charging_label' => 'Full'], '') === 'On dock · Full', 'full status');
assert_true($m->invoke(null, ['rain_detected' => true], '') === 'Rain', 'rain status');
assert_true($m->invoke(null, [], '') === 'Idle', 'idle status');

fwrite(STDOUT, "test_paper_robot_status.php ok\n");
