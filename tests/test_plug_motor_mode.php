<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboHomeAutomations;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-motor-mode-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
$plugId = '12:1';
file_put_contents($root . '/data/home.json', json_encode([
    'hidden' => [],
    'last_devices' => [[
        'id' => $plugId,
        'name' => 'Compressor',
        'kind' => 'plug',
        'on' => true,
        'power_w' => 450.0,
        'available' => true,
    ]],
    'motor_mode_w' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'devices' => [[
        'id' => $plugId,
        'name' => 'Compressor',
        'kind' => 'plug',
        'on' => true,
        'power_w' => 450.0,
        'available' => true,
    ]],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
assert_true($home->motorModeWatts($plugId) === 0, 'default motor mode off');
assert_true($home->setMotorMode($plugId, 200), 'enable motor mode');
assert_true($home->motorModeWatts($plugId) === 200, 'stores watts');
assert_true(!$home->setMotorMode('unifi:relay:1:0', 100), 'rejects UniFi ids');

assert_true($home->motorModeShouldDeferOff($plugId, 'off', 'plug'), 'defers off while drawing');
assert_true(!$home->motorModeShouldDeferOff($plugId, 'on', 'plug'), 'on never deferred');

$home->scheduleMotorPendingOff($plugId);
assert_true($home->hasMotorPendingOff($plugId), 'pending off scheduled');
assert_true(is_file($home->motorPendingPath()), 'pending state file');

// Still high — flush keeps the queue.
assert_true($home->flushMotorPendingOff() === [], 'flush waits while high');
assert_true($home->hasMotorPendingOff($plugId), 'still pending while high');

// Drop load below limit — flush should attempt Off (agent missing → may fail, but queue handling is tested).
file_put_contents($root . '/data/home-nodes-cache.json', json_encode([
    'v' => 5,
    'devices' => [[
        'id' => $plugId,
        'name' => 'Compressor',
        'kind' => 'plug',
        'on' => true,
        'power_w' => 50.0,
        'available' => true,
    ]],
], JSON_UNESCAPED_SLASHES));
$homeLow = new YarboHome($root);
assert_true(!$homeLow->motorModeShouldDeferOff($plugId, 'off', 'plug'), 'allows immediate off under limit');

$auto = new YarboHomeAutomations($root);
$norm = (new ReflectionClass($auto))->getMethod('normalizeThreshold');
$norm->setAccessible(true);
$thresh = $norm->invoke($auto, [
    'type' => 'threshold',
    'id' => $plugId,
    'metric' => 'power',
    'op' => 'above',
    'value' => 100,
]);
assert_true(is_array($thresh) && ($thresh['metric'] ?? '') === 'power', 'normalizes power threshold');
assert_true(YarboHomeAutomations::thresholdUnit('power') === 'W', 'power unit is W');
assert_true(YarboHomeAutomations::thresholdUnit('voltage') === 'V', 'voltage unit');
assert_true(YarboHomeAutomations::thresholdUnit('current') === 'A', 'current unit');

$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$homePhp = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($api, "'motor_mode'"), 'API motor_mode');
assert_true(str_contains($api, 'flushMotorPendingOff'), 'dashboard flushes queued Off');
assert_true(str_contains($js, 'homePlugMotorModeHtml'), 'JS motor mode UI');
assert_true(str_contains($js, 'power_above'), 'JS watts above When');
assert_true(str_contains($js, 'voltage_above'), 'JS volts above When');
assert_true(str_contains($js, 'current_above'), 'JS amps above When');
assert_true(str_contains($js, 'homePlugElectricalMetaHtml'), 'JS shows V/A on plug tile');
assert_true(str_contains($js, 'Cancel off'), 'JS cancel queued Off');
assert_true(str_contains($js, "action: 'motor_mode'"), 'JS saves motor mode');
assert_true(str_contains($agent, 'maybe_motor_mode_reon'), 'agent re-On for Apple Home');
assert_true(str_contains($agent, 'maybe_motor_pending_off'), 'agent completes queued Off');
assert_true(str_contains($agent, 'motor_mode_w'), 'agent reads motor_mode_w');
assert_true(str_contains($agent, 'ATTR_RMS_VOLTAGE'), 'agent reads RMS voltage');
assert_true(str_contains($agent, 'node_electrical_metrics'), 'agent merges sibling electrical endpoint');
assert_true(str_contains($homePhp, 'scheduleMotorPendingOff'), 'Home queues Off');
assert_true(str_contains($change, '## [4.0.250]'), 'changelog 4.0.250');

echo "ok: plug motor mode + watts automations\n";
