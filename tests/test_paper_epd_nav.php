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

assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.61"'), 'firmware 0.1.61');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.61';"), 'panel firmware pin 0.1.61');
assert_true(str_contains($fw, 'void waitEpdReady()'), 'wait pumps input during BUSY');
assert_true(str_contains($fw, 'void serviceTouchQueue()'), 'touch handled as a queue');
assert_true(str_contains($fw, 'serviceTouchQueue();'), 'draw waits still take taps');
$finish = 'void finishEpdFrame()
{
    M5.Display.endWrite();
    M5.Display.display();
}';
assert_true(str_contains($fw, $finish), 'finishEpdFrame must not wait for BUSY');
assert_true(str_contains($fw, 'drawScreen(false);'), 'navigation uses fast e-paper update');
assert_true(!preg_match('/void showPage\([\s\S]*?drawScreen\(true\)/', $fw), 'showPage must not force a full refresh');
assert_true(str_contains($docs, 'tap or A/B is taken while the panel is still refreshing'), 'docs mention interrupt taps');
assert_true(str_contains($change, '## [4.0.60]'), 'changelog 4.0.60');

echo "test_paper_epd_nav.php ok\n";
