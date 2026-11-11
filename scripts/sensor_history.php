#!/usr/bin/env php
<?php

/**
 * Sample temperature / humidity every ~30 minutes into data/sensor-history/.
 * Started by scripts/panel.sh. Use --once for a single pass (panel update).
 *
 * Wakes often and only records a successful run when at least one sample is
 * written, so a restart during SAMPLE_MIN_GAP does not burn another full 30 min.
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
$lastRunPath = $history->dir() . '/.last-run';

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

function sensor_history_last_run(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $raw = trim((string) file_get_contents($path));
    if ($raw !== '' && ctype_digit($raw)) {
        return (int) $raw;
    }
    $mtime = @filemtime($path);

    return is_int($mtime) ? $mtime : 0;
}

function sensor_history_mark_run(string $path, int $now): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, (string) $now . "\n", LOCK_EX);
}

function sensor_history_run(YarboSensorHistory $history, string $lastRunPath): void
{
    try {
        $result = $history->sampleOnce();
        $sampled = (int) ($result['sampled'] ?? 0);
        if ($sampled > 0) {
            sensor_history_mark_run($lastRunPath, time());
        }
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
    sensor_history_run($history, $lastRunPath);
    exit(0);
}

$wakeEvery = 30;
$dueAfter = YarboSensorHistory::SAMPLE_INTERVAL_S;

while (true) {
    if (sensor_history_sources_changed($watchFiles, $startedAt)) {
        exit(0);
    }
    $now = time();
    $last = sensor_history_last_run($lastRunPath);
    if ($last <= 0 || ($now - $last) >= $dueAfter) {
        sensor_history_run($history, $lastRunPath);
    }
    for ($i = 0; $i < $wakeEvery; $i++) {
        if (sensor_history_sources_changed($watchFiles, $startedAt)) {
            exit(0);
        }
        sleep(1);
    }
}
