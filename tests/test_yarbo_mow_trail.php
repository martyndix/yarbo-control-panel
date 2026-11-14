<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboMowTrail;
use Yarbo\YarboTelemetry;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

$root = sys_get_temp_dir() . '/yarbo-mow-trail-' . bin2hex(random_bytes(4));
mkdir($root . '/data', 0775, true);
$trail = new YarboMowTrail($root);

$baseLat = 51.5;
$baseLon = -0.12;
// ~1.1 m north of base at this latitude
$stepLat = 1.1 / 111320.0;

$idle = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'plan_running' => false,
    'returning_to_dock' => false,
    'on_charge_pad' => false,
]);
assert_true($idle === [], 'no trail while idle off pad');

$p1 = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'plan_running' => true,
    'returning_to_dock' => false,
    'on_charge_pad' => false,
]);
assert_true(count($p1) === 1, 'first mow point stored');

$same = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'plan_running' => true,
    'returning_to_dock' => false,
    'on_charge_pad' => false,
]);
assert_true(count($same) === 1, 'tiny/no move skipped');

$p2 = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat + $stepLat,
    'longitude' => $baseLon,
    'plan_running' => true,
    'returning_to_dock' => false,
    'on_charge_pad' => false,
]);
assert_true(count($p2) === 2, 'second point after ~1m');

$returning = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat + 2 * $stepLat,
    'longitude' => $baseLon,
    'plan_running' => false,
    'returning_to_dock' => true,
    'on_charge_pad' => false,
]);
assert_true(count($returning) === 3, 'trail continues while returning');

$docked = $trail->observe([
    'gps_valid' => true,
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'plan_running' => false,
    'returning_to_dock' => false,
    'on_charge_pad' => true,
]);
assert_true($docked === [], 'cleared on charge pad');
assert_true($trail->read() === [], 'file empty after dock');

$dist = YarboMowTrail::haversineM($baseLat, $baseLon, $baseLat + $stepLat, $baseLon);
assert_true($dist > 0.9 && $dist < 1.4, 'haversine ~1.1m got ' . $dist);

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'updateMowTrailOnMap'), 'map draws shared trail');
assert_true(str_contains($js, 'mow_trail'), 'reads mow_trail from status');

$parsed = YarboTelemetry::parseForPanel([
    'StateMSG' => [
        'working_state' => 0,
        'charging_status' => 0,
        'error_code' => 0,
        'on_going_planning' => 1,
        'planning_paused' => 0,
        'on_going_recharging' => 0,
    ],
    'BatteryMSG' => ['capacity' => 70],
    'rtk_base_data' => [
        'rover' => [
            // Quality 1, lat 51.5 N, lon 0.12 W roughly — enough for gps_valid
            'gngga' => '$GNGGA,120000.00,5130.0000,N,00007.2000,W,1,08,1.0,10.0,M,0.0,M,,*5A',
        ],
    ],
], null, $root);
assert_true(array_key_exists('mow_trail', $parsed), 'parseForPanel includes mow_trail');
assert_true(is_array($parsed['mow_trail']), 'mow_trail is list');

echo "test_yarbo_mow_trail.php ok\n";
