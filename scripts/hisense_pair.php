#!/usr/bin/env php
<?php

/**
 * Hold one Hisense MQTT session from Show code until the code is sent.
 * A second connection is a different login, and firmware 9 ignores the code there.
 */

declare(strict_types=1);

set_time_limit(0);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "hisense_pair: vendor/autoload.php missing\n");
    exit(1);
}

require $autoload;

use Yarbo\YarboHisense;

$id = '';
foreach ($argv as $arg) {
    if (is_string($arg) && str_starts_with($arg, 'hisense:')) {
        $id = $arg;
    }
}
if ($id === '') {
    fwrite(STDERR, "hisense_pair: missing tv id\n");
    exit(1);
}

$result = (new YarboHisense($root))->finishHeldPin($id);
exit(($result['ok'] ?? false) === true ? 0 : 1);
