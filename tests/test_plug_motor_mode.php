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

$home = new YarboHome($root);
assert_true($home->motorModeWatts($plugId) === 0, 'default motor mode off');
assert_true($home->setMotorMode($plugId, 200), 'enable motor mode');
assert_true($home->motorModeWatts($plugId) === 200, 'stores watts');
assert_true(!$home->setMotorMode('unifi:relay:1:0', 100), 'rejects UniFi ids');

$block = $home->motorModeOffBlock($plugId, 'off', 'plug');
assert_true($block !== null && str_contains((string) $block, '450'), 'blocks off while drawing: ' . (string) $block);
assert_true($home->motorModeOffBlock($plugId, 'on', 'plug') === null, 'on never blocked');

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
$home2 = new YarboHome($root);
assert_true($home2->motorModeOffBlock($plugId, 'off', 'plug') === null, 'allows off under limit');

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

$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($api, "'motor_mode'"), 'API motor_mode');
assert_true(str_contains($js, 'homePlugMotorModeHtml'), 'JS motor mode UI');
assert_true(str_contains($js, 'power_above'), 'JS watts above When');
assert_true(str_contains($js, "action: 'motor_mode'"), 'JS saves motor mode');
assert_true(str_contains($agent, 'maybe_motor_mode_reon'), 'agent re-On for Apple Home');
assert_true(str_contains($agent, 'motor_mode_w'), 'agent reads motor_mode_w');
assert_true(str_contains($change, '## [4.0.250]'), 'changelog 4.0.250');

echo "ok: plug motor mode + watts automations\n";
