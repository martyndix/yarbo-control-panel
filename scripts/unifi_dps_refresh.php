#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background Access door-position refresh so Home Open/Closed can update
 * without blocking the single-threaded panel on GET /doors.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = $argv[1] ?? dirname(__DIR__);
if (!is_dir($root)) {
    fwrite(STDERR, "unifi_dps_refresh: bad root\n");
    exit(1);
}

(new Yarbo\YarboUnifi($root))->refreshAccessDoors();
