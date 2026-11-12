<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/public/api/plans.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

assert_true(str_contains($api, 'Yarbo rain detected — start plan blocked'), 'plans API rain block');
assert_true(str_contains($api, 'YarboHomeAutomations::yarboRainFromData'), 'plans API uses shared rain helper');
assert_true(str_contains($api, '409'), 'plans API returns 409 on rain');
assert_true(str_contains($js, "lastStatusData?.rain_detected"), 'UI blocks Start when Status rain');
assert_true(str_contains($change, '## [4.0.278]'), 'changelog 4.0.278');

echo "ALL PASS\n";
