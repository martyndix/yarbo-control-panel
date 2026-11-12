<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

$api = (string) file_get_contents($root . '/public/api/unifi.php');
$php = (string) file_get_contents($root . '/src/YarboUnifi.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$refresh = (string) file_get_contents($root . '/scripts/unifi_dps_refresh.php');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($api, 'dashboardPayload(false)'), 'UniFi GET uses cached inventory');
assert_true(str_contains($api, 'kickInventoryRefresh'), 'UniFi GET kicks background refresh');
assert_true(!preg_match('/json_response\(\[\'ok\' => true\] \+ \$unifi->dashboardPayload\(\)\);/', $api), 'UniFi GET no longer blocks on live refresh');
assert_true(str_contains($php, 'function refreshProtectCameras'), 'camera background refresh exists');
assert_true(str_contains($php, 'refreshProtectCameras(2.5)'), 'cameras included in DPS refresh');
assert_true(str_contains($php, 'protect_cameras_at'), 'camera stamp tracked');
assert_true(str_contains($php, 'needCameras'), 'empty camera list forces refresh');
assert_true(str_contains($js, 'still loading devices'), 'abort UX for empty UniFi load');
assert_true(str_contains($js, 'unifiApi(null, 20000)'), 'UniFi load timeout raised');
assert_true(str_contains($refresh, 'camera'), 'dps refresh docs mention cameras');
assert_true(str_contains($change, '## [4.0.274]'), 'changelog 4.0.274');

echo "ALL PASS\n";
