#!/usr/bin/env php
<?php

/**
 * Evaluate Home automations in the background.
 * Started by scripts/panel.sh, and kicked from Home GET if that loop is down.
 * Does not run inside the GET itself — php -S is single-threaded.
 * Exits 0 when source files change so panel.sh can reload new PHP.
 * Exits 75 if another runner pid is already alive.
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
$root = dirname(__DIR__);
foreach ($argv as $arg) {
    if (!is_string($arg) || !str_starts_with($arg, '--root=')) {
        continue;
    }
    $candidate = substr($arg, 7);
    if ($candidate !== '' && is_dir($candidate)) {
        $root = $candidate;
    }
}

$engine = new YarboHomeAutomations($root);

function home_automations_tick(YarboHomeAutomations $engine): void
{
    static $failStreak = 0;
    try {
        $result = $engine->tick();
        $engine->refreshUnifiIfDue(time(), 5);
        $engine->refreshPowerwallIfDue(time(), 12);
        $engine->refreshYarboIfDue(time(), 5);
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

if (!$engine->acquireRunnerLock()) {
    fwrite(STDERR, "home_automations: already running\n");
    exit(75);
}

$startedAt = time();
$watchFiles = [
    __FILE__,
    $root . '/src/YarboHomeAutomations.php',
    $root . '/src/YarboHome.php',
    $root . '/scripts/home_pulse.php',
    $root . '/src/YarboMatterAgentClient.php',
    $root . '/src/YarboUnifi.php',
    $root . '/src/YarboPowerwall.php',
    $root . '/src/YarboMqttAgentClient.php',
    $root . '/src/YarboLymow.php',
    $root . '/src/YarboTelemetry.php',
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
