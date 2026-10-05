<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fw = (string) file_get_contents($root . '/firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents($root . '/firmware/papermono/src/version.h');
$php = (string) file_get_contents($root . '/src/YarboPaperDevice.php');
$docs = (string) file_get_contents($root . '/docs/papermono.md');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.64"'), 'firmware 0.1.64');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.64';"), 'panel firmware pin 0.1.64');
assert_true(str_contains($fw, 'void waitEpdReady()'), 'wait for e-paper BUSY');
assert_true(str_contains($fw, 'void serviceTouchQueue()'), 'touch handled as a queue');
if (!preg_match('/void waitEpdReady\(\)\n\{\n(?:.*\n)*?\}\n\n/', $fw, $wait)) {
    fwrite(STDERR, "waitEpdReady body missing\n");
    exit(1);
}
assert_true(str_contains($wait[0], 'applyPendingPages()'), 'A/B still taken during BUSY');
assert_true(!str_contains($wait[0], 'serviceTouchQueue'), 'HOUSE HTTP must not run during BUSY');
assert_true(str_contains($fw, 'while (!M5.Display.displayBusy() && (millis() - t0) < 80)'), 'finishEpdFrame waits for BUSY to assert');
assert_true(str_contains($fw, 'drawScreen(false);'), 'navigation uses fast e-paper update');
assert_true(str_contains($fw, "wifiStartHome();\n        drawScreen(true);"), 'boot paints with a full refresh');
if (!preg_match('/void showPage\(int page, bool loadPlansIfNeeded\)\n\{[\s\S]*?\n\}\n\nvoid nextPage/', $fw, $show)) {
    fwrite(STDERR, "showPage body missing\n");
    exit(1);
}
assert_true(str_contains($show[0], 'drawScreen(false)'), 'showPage uses fast update');
assert_true(!str_contains($show[0], 'drawScreen(true)'), 'showPage must not force a full refresh');
assert_true(str_contains($docs, 'A/B is taken while the panel is still refreshing'), 'docs mention A/B during refresh');
assert_true(str_contains($change, '## [4.0.63]'), 'changelog 4.0.63');

echo "test_paper_epd_nav.php ok\n";
