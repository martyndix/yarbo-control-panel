#!/usr/bin/env php
<?php

/**
 * Sample temperature, humidity, and plug watts / volts / amps every ~30 minutes.
 * Started by scripts/panel.sh. Use --once for a single pass (panel update).
 *
 * Wakes often. The last run time lives in YarboSensorHistory::lastRunAt(), so a
 * restart or a page-triggered sample inside SAMPLE_MIN_GAP_S does not start a
 * second run.
 */

declare(strict_types=1);

set_time_limit(0);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "sensor_history: vendor/autoload.php missing\n");
    exit(1);
}

require $autoload;

use Yarbo\YarboSensorHistory;

$once = in_array('--once', $argv, true);
$history = new YarboSensorHistory($root);

$startedAt = time();
$watchFiles = [
    __FILE__,
    $root . '/src/YarboSensorHistory.php',
    $root . '/src/YarboUnifi.php',
    $root . '/src/YarboHome.php',
    $root . '/src/YarboMatterFabric.php',
];

function sensor_history_sources_changed(array $paths, int $startedAt): bool
{
    foreach ($paths as $path) {
        if (is_file($path) && filemtime($path) > $startedAt) {
            return true;
        }
    }

    return false;
}

function sensor_history_run(YarboSensorHistory $history): void
{
    try {
        $history->sampleOutside();
        $result = $history->sampleOnce();
        $sampled = (int) ($result['sampled'] ?? 0);
        $line = sprintf(
            'sensor_history: sampled=%d skipped=%d sources=%d',
            $sampled,
            (int) ($result['skipped'] ?? 0),
            (int) ($result['sources'] ?? 0)
        );
        fwrite(STDOUT, $line . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'sensor_history: ' . $e->getMessage() . "\n");
    }
}

if ($once) {
    sensor_history_run($history);
    exit(0);
}

$wakeEvery = 30;
$dueAfter = YarboSensorHistory::SAMPLE_INTERVAL_S;

while (true) {
    if (sensor_history_sources_changed($watchFiles, $startedAt)) {
        exit(0);
    }
    $now = time();
    $last = $history->lastRunAt();
    if ($last <= 0 || ($now - $last) >= $dueAfter) {
        sensor_history_run($history);
    }
    for ($i = 0; $i < $wakeEvery; $i++) {
        if (sensor_history_sources_changed($watchFiles, $startedAt)) {
            exit(0);
        }
        sleep(1);
    }
}
