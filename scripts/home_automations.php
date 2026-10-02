#!/usr/bin/env php
<?php

/**
 * Evaluate Home automations in the background.
 * Started by scripts/panel.sh next to the Vestaboard watcher.
 * Never run from /api/home.php — php -S is single-threaded.
 * Exits 0 when source files change so panel.sh can reload new PHP.
 */

declare(strict_types=1);

set_time_limit(0);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "home_automations: vendor/autoload.php missing\n");
    exit(1);
}

require $autoload;

use Yarbo\YarboHomeAutomations;

$once = in_array('--once', $argv, true);
$engine = new YarboHomeAutomations($root);

function home_automations_tick(YarboHomeAutomations $engine): void
{
    static $failStreak = 0;
    try {
        $result = $engine->tick();
        $engine->refreshUnifiIfDue(time(), 5);
        if (($result['ok'] ?? false) && ($result['fired'] ?? []) !== []) {
            $failStreak = 0;
            fwrite(STDERR, 'home_automations: fired ' . implode(',', $result['fired'])
                . ' at ' . (string) ($result['hm'] ?? '') . ' ' . (string) ($result['timezone'] ?? '') . "\n");
        } elseif (($result['ok'] ?? false)) {
            $failStreak = 0;
        } else {
            $failStreak++;
            if ($failStreak === 1 || $failStreak % 15 === 0) {
                $err = implode('; ', $result['errors'] ?? ['unknown']);
                fwrite(STDERR, 'home_automations: ' . $err . "\n");
            }
        }
    } catch (Throwable $e) {
        $failStreak++;
        if ($failStreak === 1 || $failStreak % 15 === 0) {
            fwrite(STDERR, 'home_automations: ' . $e->getMessage() . "\n");
        }
    }
}

if ($once) {
    home_automations_tick($engine);
    exit(0);
}

$startedAt = time();
$watchFiles = [
    __FILE__,
    $root . '/src/YarboHomeAutomations.php',
    $root . '/src/YarboHome.php',
    $root . '/src/YarboUnifi.php',
    $root . '/src/YarboHub.php',
    $root . '/src/YarboVestaboard.php',
];

function home_automations_sources_changed(array $paths, int $startedAt): bool
{
    foreach ($paths as $path) {
        if (is_file($path) && filemtime($path) > $startedAt) {
            return true;
        }
    }

    return false;
}

while (true) {
    if (home_automations_sources_changed($watchFiles, $startedAt)) {
        exit(0);
    }

    home_automations_tick($engine);

    if (home_automations_sources_changed($watchFiles, $startedAt)) {
        exit(0);
    }
    sleep(1);
}
