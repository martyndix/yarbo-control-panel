#!/usr/bin/env php
<?php

/**
 * Sample temperature / humidity every ~30 minutes into data/sensor-history/.
 * Started by scripts/panel.sh. Use --once for a single pass (panel update).
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
        $result = $history->sampleOnce();
        $line = sprintf(
            'sensor_history: sampled=%d skipped=%d sources=%d',
            (int) ($result['sampled'] ?? 0),
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

// First sample soon after boot, then every 30 minutes.
sensor_history_run($history);

while (true) {
    for ($i = 0; $i < YarboSensorHistory::SAMPLE_INTERVAL_S; $i++) {
        if (sensor_history_sources_changed($watchFiles, $startedAt)) {
            exit(0);
        }
        sleep(1);
    }
    sensor_history_run($history);
}
