#!/usr/bin/env php
<?php

/**
 * Flash a Home light for automations Pulse, then restore the previous look.
 * Started by YarboHomeAutomations; does not run inside the 1 Hz tick.
 */

declare(strict_types=1);

set_time_limit(120);

$root = dirname(__DIR__);
$jobPath = '';
foreach ($argv as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--root=')) {
        $candidate = substr($arg, 7);
        if ($candidate !== '' && is_dir($candidate)) {
            $root = $candidate;
        }
    }
    if (str_starts_with($arg, '--job=')) {
        $jobPath = substr($arg, 6);
    }
}

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "home_pulse: vendor/autoload.php missing\n");
    exit(1);
}
require $autoload;

if ($jobPath === '' || !is_file($jobPath)) {
    fwrite(STDERR, "home_pulse: job file missing\n");
    exit(1);
}

$decoded = json_decode((string) file_get_contents($jobPath), true);
@unlink($jobPath);
if (!is_array($decoded)) {
    fwrite(STDERR, "home_pulse: invalid job\n");
    exit(1);
}

use Yarbo\YarboHome;

$home = new YarboHome($root);
$result = $home->executePulse($decoded);
if (!($result['ok'] ?? false)) {
    fwrite(STDERR, 'home_pulse: ' . (string) ($result['error'] ?? 'failed') . "\n");
    exit(1);
}

exit(0);
