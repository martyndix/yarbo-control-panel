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

assert_true($hist->recordOutsideTemperature(3.5, $now), 'outside temperature saved');
assert_true(!$hist->recordOutsideTemperature(3.6, $now + 200), 'outside waits ten minutes');
assert_true($hist->recordOutsideTemperature(4.0, $now + 1600), 'outside saved with the next sample');

$series = $hist->series('unifi:sensor:living', $now - 3600, $now + 2000, '30m');
assert_true($series['ok'] === true, 'series ok');
assert_true($series['has_humidity'] === true, 'humidity present');
assert_true(count($series['points']) >= 2, 'points present');
assert_true(($series['trends']['temperature']['latest'] ?? null) === 19.0, 'latest temp');
assert_true(($series['has_outside'] ?? false) === true, 'climate graph includes outside');
assert_true(($series['points'][0]['outside'] ?? null) === 3.5, 'first point uses the outside reading');
assert_true(($series['points'][count($series['points']) - 1]['outside'] ?? null) === 4.0, 'later point uses the later outside reading');
assert_true(($series['trends']['outside']['latest'] ?? null) === 4.0, 'outside trend');

$heater = $hist->series('matter:42', $now - 3600, $now + 2000, '30m');
assert_true($heater['has_humidity'] === false, 'heater has no humidity');
assert_true(($heater['trends']['humidity'] ?? null) === null, 'heater humidity trend null');

file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'devices' => [[
        'id' => '82:1',
        'name' => 'Compressor',
        'kind' => 'plug',
        'on' => true,
        'power_w' => 1043.6,
        'voltage_v' => 229.4,
        'current_a' => 4.56,
    ]],
], JSON_UNESCAPED_SLASHES));
$meterSources = $hist->listSources();
$meterIds = array_map(static fn ($s) => $s['id'], $meterSources);
assert_true(in_array('82:1', $meterIds, true), 'metering plug listed');
assert_true($hist->appendSample('82:1', 'Compressor', 'plug', null, null, $now, 1043.6, 229.4, 4.56), 'plug sample');
assert_true(!$hist->appendSample('82:1', 'Compressor', 'plug', null, null, $now + 60, 10.0, 230.0, 0.1), 'plug gap blocks early sample');
$plug = $hist->series('82:1', $now - 3600, $now + 2000, '30m');
assert_true($plug['has_power'] === true && $plug['has_voltage'] === true && $plug['has_current'] === true, 'plug series flags');
assert_true(($plug['points'][0]['power_w'] ?? null) === 1043.6, 'plug watts');
assert_true(($plug['points'][0]['voltage_v'] ?? null) === 229.4, 'plug volts');
assert_true(abs((float) ($plug['points'][0]['current_a'] ?? 0) - 4.56) < 0.001, 'plug amps');
assert_true($plug['has_humidity'] === false, 'plug is not a climate series');
assert_true(($plug['has_outside'] ?? true) === false, 'power graph does not draw outside temperature');
$keptMeter = $hist->pruneSamples([[$now, null, null, 10.5, 230.0, 0.05]], $now + 10);
assert_true(($keptMeter[0][3] ?? null) === 10.5, 'prune keeps watts');

$daily = $hist->series('unifi:sensor:living', $now - 86400, $now + 2000, '1d');
assert_true($daily['bucket'] === '1d', 'daily bucket');
assert_true(count($daily['points']) >= 1, 'daily points');
assert_true(array_key_exists('temp_min', $daily['points'][0]), 'daily has temp_min');
assert_true(abs((float) ($daily['points'][0]['outside'] ?? 0) - 3.75) < 0.02, 'daily outside is the day’s average');

$hist->pruneOrphans(['unifi:sensor:living']);
assert_true(is_file($hist->pathFor(YarboSensorHistory::OUTSIDE_ID)), 'outside history is kept');

$merged = YarboSensorHistory::applyAgentReadings(
    [
        'matter:7' => ['id' => 'matter:7', 'name' => 'Hall', 'kind' => 'sensor', 'temperature' => 19.0, 'humidity' => 40.0],
        'unifi:sensor:living' => ['id' => 'unifi:sensor:living', 'name' => 'Living', 'kind' => 'sensor', 'temperature' => 18.5, 'humidity' => 47.0],
    ],
    [
        ['id' => 'matter:7', 'name' => 'Hall', 'kind' => 'sensor', 'temperature' => 21.4, 'humidity' => null],
        ['id' => 'matter:9', 'name' => 'Porch', 'kind' => 'sensor', 'temperature' => 3.2, 'humidity' => 80.0],
        ['id' => 'unifi:sensor:living', 'name' => 'Living', 'kind' => 'sensor', 'temperature' => 30.0, 'humidity' => 10.0],
    ],
    ['unifi:sensor:living' => true],
);
assert_true(($merged['matter:7']['temperature'] ?? null) === 21.4, 'agent reading overrides a stale cached temperature');
assert_true(($merged['matter:7']['humidity'] ?? null) === 40.0, 'missing agent humidity keeps the cached value');
assert_true(($merged['matter:9']['temperature'] ?? null) === 3.2, 'agent-only sensor is sampled');
assert_true(($merged['unifi:sensor:living']['temperature'] ?? null) === 18.5, 'UniFi reading is not replaced by the agent');
$plugMerged = YarboSensorHistory::applyAgentReadings(
    [
        'matter:12' => ['id' => 'matter:12', 'name' => 'Heater plug', 'kind' => 'plug', 'temperature' => null, 'humidity' => null, 'power_w' => 5.0, 'voltage_v' => 230.0, 'current_a' => 0.02],
    ],
    [
        ['id' => 'matter:12', 'name' => 'Heater plug', 'kind' => 'plug', 'temperature' => null, 'humidity' => null, 'power_w' => 1043.6, 'voltage_v' => 229.4, 'current_a' => 4.56],
        ['id' => 'matter:13', 'name' => 'Lamp plug', 'kind' => 'plug', 'temperature' => null, 'humidity' => null, 'power_w' => 12.0, 'voltage_v' => 229.0, 'current_a' => 0.05],
    ],
);
assert_true(($plugMerged['matter:12']['power_w'] ?? null) === 1043.6, 'agent watts override cached plug watts');
assert_true(abs((float) ($plugMerged['matter:12']['current_a'] ?? 0) - 4.56) < 0.001, 'agent amps override cached plug amps');
assert_true(($plugMerged['matter:13']['power_w'] ?? null) === 12.0, 'agent-only plug is sampled');

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
assert_true(str_contains($js, 'Power history'), 'plug meter opens history');
assert_true(str_contains($js, 'has_power'), 'chart reads plug watts');
assert_true(str_contains($change, '## [4.0.379]'), 'changelog 4.0.379');
assert_true(str_contains($js, 'Outside °C'), 'chart labels the outside line');
assert_true(str_contains($change, '## [5.0.67]'), 'changelog 5.0.67');

echo "ok: sensor history\n";
