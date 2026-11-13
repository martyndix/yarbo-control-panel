<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

$vb = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
$unifi = (string) file_get_contents(__DIR__ . '/../src/YarboUnifi.php');
$watch = (string) file_get_contents(__DIR__ . '/../scripts/vestaboard_watch.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
$docs = (string) file_get_contents(__DIR__ . '/../docs/vestaboard.md');

assert_true(str_contains($unifi, 'refreshNightContactsIfStale'), 'UniFi night stale refresh');
assert_true(str_contains($unifi, 'refreshProtectSensors($timeout, false)'), 'night skips Protect lights');
assert_true(str_contains($unifi, 'CURLOPT_TIMEOUT_MS'), 'sub-2s UniFi curl budgets');
assert_true(str_contains($vb, 'refreshNightContactsIfStale(1.2, 2)'), 'night contacts use stale skip');
assert_true(str_contains($vb, 'boardFetchStale'), 'overnight board GET throttle helper');
assert_true(str_contains($vb, 'boardFetchStale($config, 60)'), 'overnight board GET at most ~60s');
assert_true(str_contains($watch, 'isNightHours() ? 1 : 8'), '1s night idle');
assert_true(str_contains($change, '## [4.0.285]'), 'changelog 4.0.285');
assert_true(str_contains($docs, 'about every **1 second**'), 'docs 1s poll');

echo "test_vestaboard_night_fast.php ok\n";
