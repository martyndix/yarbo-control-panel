<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboSensorHistory;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-sensor-hist-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'unifi' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => ['unifi:sensor:living'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'sensors' => [[
        'id' => 'unifi:sensor:living',
        'native_id' => 'living',
        'name' => 'Living Room',
        'kind' => 'sensor',
        'source' => 'unifi',
        'temperature' => 18.5,
        'humidity' => 47,
        'status' => 'Closed · 18.5° · 47% RH',
        'on' => false,
    ], [
        'id' => 'unifi:sensor:dps-only',
        'native_id' => 'dps-1',
        'name' => 'Garage DPS',
        'kind' => 'sensor',
        'source' => 'unifi',
        'status' => 'Closed',
        'on' => false,
    ]],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'hidden' => [],
    'last_devices' => [[
        'id' => 'matter:42',
        'name' => 'Office Mill',
        'kind' => 'heater',
        'local_temperature' => 21.2,
        'heating_setpoint' => 20,
        'on' => true,
    ]],
], JSON_UNESCAPED_SLASHES));

$hist = new YarboSensorHistory($root);
$sources = $hist->listSources();
$ids = array_map(static fn ($s) => $s['id'], $sources);
assert_true(in_array('unifi:sensor:living', $ids, true), 'UP Sense listed');
assert_true(in_array('matter:42', $ids, true), 'heater listed');
assert_true(!in_array('unifi:sensor:dps-only', $ids, true), 'contact-only skipped');

$now = 1_700_000_000;
assert_true($hist->appendSample('unifi:sensor:living', 'Living Room', 'sensor', 18.5, 47.0, $now), 'first sample');
assert_true(!$hist->appendSample('unifi:sensor:living', 'Living Room', 'sensor', 18.6, 48.0, $now + 60), 'gap blocks early sample');
assert_true($hist->appendSample('unifi:sensor:living', 'Living Room', 'sensor', 19.0, 50.0, $now + 1600), 'second sample after gap');
assert_true($hist->appendSample('matter:42', 'Office Mill', 'heater', 21.2, null, $now), 'heater temp-only sample');

$old = $now - (YarboSensorHistory::RETENTION_S + 86400);
$store = $hist->load('unifi:sensor:living');
$store['samples'][] = [$old, 10.0, 20.0];
$pruned = $hist->pruneSamples($store['samples'], $now + 2000);
foreach ($pruned as $row) {
    assert_true((int) $row[0] >= ($now + 2000 - YarboSensorHistory::RETENTION_S), 'old sample pruned');
}

$series = $hist->series('unifi:sensor:living', $now - 3600, $now + 2000, '30m');
assert_true($series['ok'] === true, 'series ok');
assert_true($series['has_humidity'] === true, 'humidity present');
assert_true(count($series['points']) >= 2, 'points present');
assert_true(($series['trends']['temperature']['latest'] ?? null) === 19.0, 'latest temp');

$heater = $hist->series('matter:42', $now - 3600, $now + 2000, '30m');
assert_true($heater['has_humidity'] === false, 'heater has no humidity');
assert_true(($heater['trends']['humidity'] ?? null) === null, 'heater humidity trend null');

$daily = $hist->series('unifi:sensor:living', $now - 86400, $now + 2000, '1d');
assert_true($daily['bucket'] === '1d', 'daily bucket');
assert_true(count($daily['points']) >= 1, 'daily points');
assert_true(array_key_exists('temp_min', $daily['points'][0]), 'daily has temp_min');

$php = (string) file_get_contents(__DIR__ . '/../src/YarboSensorHistory.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$panel = (string) file_get_contents(__DIR__ . '/../scripts/panel.sh');
$update = (string) file_get_contents(__DIR__ . '/../scripts/update.sh');
$ensure = (string) file_get_contents(__DIR__ . '/../scripts/lib/chart_js_ensure.sh');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($php, 'RETENTION_S = 365 * 86400'), '1 year retention');
assert_true(str_contains($api, "\$getAction === 'sensor_history'"), 'API sensor_history');
assert_true(str_contains($api, 'sensor_history_sample'), 'API sample action');
assert_true(str_contains($js, 'openSensorHistoryModal'), 'UI opens modal');
assert_true(str_contains($js, 'data-sensor-history'), 'history click hooks');
assert_true(str_contains($html, 'sensor-history-modal'), 'modal markup');
assert_true(str_contains($html, '/vendor/chart.umd.min.js'), 'vendored Chart.js');
assert_true(str_contains($panel, 'sensor_history.php'), 'panel starts sampler');
assert_true(str_contains($update, 'yarbo_ensure_chart_js'), 'update installs Chart.js');
assert_true(str_contains($ensure, 'chart.js@'), 'chart ensure URL');
assert_true(is_file(__DIR__ . '/../public/vendor/chart.umd.min.js'), 'chart.umd.min.js present');
assert_true(str_contains($change, '## [4.0.247]'), 'changelog 4.0.247');

echo "ok: sensor history\n";
