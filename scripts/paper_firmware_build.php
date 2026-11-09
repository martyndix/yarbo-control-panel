#!/usr/bin/env php
<?php

/**
 * Build PaperMono / Paper Colour firmware when the binary is missing or stale.
 * Started by scripts/panel.sh and by Settings (queueAutoBuild). Safe to run often —
 * exits quickly when both binaries are current.
 */

declare(strict_types=1);

set_time_limit(0);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "paper_firmware_build: vendor/autoload.php missing\n");
    exit(1);
}

require $autoload;

use Yarbo\YarboPaperDevice;

$once = in_array('--once', $argv, true) || !in_array('--loop', $argv, true);
$paper = new YarboPaperDevice($root);

function paper_firmware_build_tick(YarboPaperDevice $paper): int
{
    try {
        $result = $paper->runAutoBuild();
        if (!($result['ok'] ?? false)) {
            $err = (string) ($result['error'] ?? 'build failed');
            fwrite(STDERR, 'paper_firmware_build: ' . $err . "\n");

            return 1;
        }
        if (!empty($result['built']) && is_array($result['built']) && $result['built'] !== []) {
            fwrite(STDOUT, 'paper_firmware_build: ' . (string) ($result['message'] ?? 'built') . "\n");
        }

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'paper_firmware_build: ' . $e->getMessage() . "\n");

        return 1;
    }
}

if ($once) {
    exit(paper_firmware_build_tick($paper));
}

$startedAt = time();
$watchFiles = [
    __FILE__,
    $root . '/src/YarboPaperDevice.php',
    $root . '/firmware/papermono/src/version.h',
    $root . '/firmware/papercolor/src/version.h',
];

while (true) {
    paper_firmware_build_tick($paper);
    foreach ($watchFiles as $path) {
        if (is_file($path) && filemtime($path) > $startedAt) {
            fwrite(STDOUT, "paper_firmware_build: sources changed, exiting for reload\n");
            exit(0);
        }
    }
    sleep(120);
}
