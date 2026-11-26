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
$by = $once ? 'update' : 'background';
foreach ($argv as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--by=') && strlen($arg) > 5) {
        $by = substr($arg, 5);
    }
}
$history = new YarboSensorHistory($root);

$watchFiles = [
    __FILE__,
    $root . '/src/YarboSensorHistory.php',
    $root . '/src/YarboUnifi.php',
    $root . '/src/YarboHome.php',
    $root . '/src/YarboMatterFabric.php',
];

/**
 * @param list<string> $paths
 * @return array<string, int>
 */
function sensor_history_mtimes(array $paths): array
{
    clearstatcache();
    $out = [];
    foreach ($paths as $path) {
        $out[$path] = is_file($path) ? (int) filemtime($path) : 0;
    }

    return $out;
}

/**
 * Compare with the times seen at start, not with the start time. A Pi without
 * a clock battery can start before NTP sets the time, and every file then
 * looks newer than the start, so the loop would reload forever.
 *
 * @param array<string, int> $seen
 */
function sensor_history_sources_changed(array $seen): bool
{
    return sensor_history_mtimes(array_keys($seen)) !== $seen;
}

function sensor_history_run(YarboSensorHistory $history, string $by): void
{
    try {
        $history->sampleOutside();
        $result = $history->sampleOnce(null, $by);
        $line = sprintf(
            'sensor_history: %s sampled=%d skipped=%d sources=%d%s',
            date('c'),
            (int) ($result['sampled'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (int) ($result['sources'] ?? 0),
            ($result['throttled'] ?? false) ? ' (not due)' : ''
        );
        fwrite(STDOUT, $line . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'sensor_history: ' . date('c') . ' ' . $e->getMessage() . "\n");
    }
}

/**
 * Each pass runs as its own short process with a time limit, so a stuck
 * network read or a crash in one pass cannot stop the loop.
 */
function sensor_history_run_child(YarboSensorHistory $history, string $root): void
{
    $cmd = [PHP_BINARY !== '' ? PHP_BINARY : 'php', __FILE__, '--once', '--by=background'];
    if (is_executable('/usr/bin/timeout')) {
        array_unshift($cmd, '/usr/bin/timeout', '150');
    }
    $process = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (!is_resource($process)) {
        sensor_history_run($history, 'background');

        return;
    }
    $code = proc_close($process);
    if ($code === 124) {
        fwrite(STDERR, 'sensor_history: ' . date('c') . " pass took over 150 s and was stopped\n");
    } elseif ($code !== 0) {
        fwrite(STDERR, 'sensor_history: ' . date('c') . " pass exited {$code}\n");
    }
}

if ($once) {
    sensor_history_run($history, $by);
    exit(0);
}

$seen = sensor_history_mtimes($watchFiles);
$wakeEvery = 30;
$dueAfter = YarboSensorHistory::SAMPLE_INTERVAL_S;
fwrite(STDOUT, 'sensor_history: ' . date('c') . " background loop started\n");

while (true) {
    if (sensor_history_sources_changed($seen)) {
        exit(0);
    }
    @touch($history->beatPath());
    $now = time();
    $last = $history->lastRunAt();
    if ($last <= 0 || ($now - $last) >= $dueAfter) {
        sensor_history_run_child($history, $root);
    }
    for ($i = 0; $i < $wakeEvery; $i++) {
        if (($i % 5) === 0 && sensor_history_sources_changed($seen)) {
            exit(0);
        }
        sleep(1);
    }
}
