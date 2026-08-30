#!/usr/bin/env php
<?php

/**
 * Push Yarbo status to a Vestaboard Note when enabled in Settings.
 * Started by scripts/panel.sh next to the MQTT agent.
 * Exits 0 when source files change so panel.sh can reload new PHP.
 */

declare(strict_types=1);

set_time_limit(0);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vestaboard_watch: vendor/autoload.php missing\n");
    exit(1);
}

require $autoload;

use Yarbo\YarboVestaboard;

$board = new YarboVestaboard($root);
$failStreak = 0;
$startedAt = time();
$watchFiles = [
    __FILE__,
    $root . '/src/YarboVestaboard.php',
];

function vestaboard_watch_sources_changed(array $paths, int $startedAt): bool
{
    foreach ($paths as $path) {
        if (is_file($path) && filemtime($path) > $startedAt) {
            return true;
        }
    }

    return false;
}

while (true) {
    if (vestaboard_watch_sources_changed($watchFiles, $startedAt)) {
        exit(0);
    }

    try {
        $result = $board->tick();
        if (($result['ok'] ?? false)) {
            $failStreak = 0;
        } else {
            $failStreak++;
            if ($failStreak === 1 || $failStreak % 15 === 0) {
                $err = (string) ($result['error'] ?? 'unknown');
                fwrite(STDERR, 'vestaboard_watch: ' . $err . "\n");
            }
        }
    } catch (Throwable $e) {
        $failStreak++;
        if ($failStreak === 1 || $failStreak % 15 === 0) {
            fwrite(STDERR, 'vestaboard_watch: ' . $e->getMessage() . "\n");
        }
    }

    $sleep = $failStreak >= 3 ? 60 : 20;
    for ($i = 0; $i < $sleep; $i++) {
        if (vestaboard_watch_sources_changed($watchFiles, $startedAt)) {
            exit(0);
        }
        sleep(1);
    }
}
