#!/usr/bin/env php
<?php

/**
 * Anonymous usage ping. Started by scripts/panel.sh.
 * Never prints secrets. Failures are silent (exit 0).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    exit(0);
}
require $autoload;

use Yarbo\YarboMetrics;

$result = (new YarboMetrics($root))->ping();
if (!($result['ok'] ?? false) && empty($result['skipped'])) {
    fwrite(STDERR, "metrics_ping: " . (string) ($result['error'] ?? 'failed') . "\n");
}

exit(0);
