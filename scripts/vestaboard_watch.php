#!/usr/bin/env php
<?php

/**
 * Push Yarbo status to a Vestaboard Note when enabled in Settings.
 * Started by scripts/panel.sh next to the MQTT agent.
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

while (true) {
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
    sleep($sleep);
}
