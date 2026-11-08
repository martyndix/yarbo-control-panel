<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboLymow;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);

$proto = (string) file_get_contents($root . '/scripts/lib/lymow_protocol.py');
assert_true(str_contains($proto, 'USER_CTRL_CLEAN = 1'), 'protocol CLEAN=1');
assert_true(str_contains($proto, 'USER_CTRL_PAUSE = 3'), 'protocol PAUSE=3');
assert_true(str_contains($proto, 'USER_CTRL_RESUME = 4'), 'protocol RESUME=4');
assert_true(str_contains($proto, 'USER_CTRL_RECHARGE_DOCK = 33'), 'protocol DOCK=33 keep progress');
assert_true(str_contains($proto, 'def encode_action'), 'protocol encode_action');

$bridge = (string) file_get_contents($root . '/scripts/lymow_bridge.py');
assert_true(str_contains($bridge, 'mqtt_publish_command'), 'bridge mqtt_publish_command');
assert_true(str_contains($bridge, 'cmd_command'), 'bridge cmd_command');
assert_true(str_contains($bridge, '"command"') || str_contains($bridge, "'command'"), 'bridge command CLI');

$lymowPhp = (string) file_get_contents($root . '/src/YarboLymow.php');
assert_true(str_contains($lymowPhp, 'function sendCommand'), 'YarboLymow::sendCommand');
assert_true(str_contains($lymowPhp, "['start', 'pause', 'resume', 'dock']"), 'COMMANDS list');

$api = (string) file_get_contents($root . '/public/api/lymow.php');
assert_true(str_contains($api, "sendCommand"), 'API calls sendCommand');
assert_true(str_contains($api, "'start', 'pause', 'resume', 'dock'"), 'API accepts control actions');

$index = (string) file_get_contents($root . '/public/index.php');
assert_true(str_contains($index, 'data-lymow-cmd="start"'), 'Lymow Start button');
assert_true(str_contains($index, 'data-lymow-cmd="pause"'), 'Lymow Pause button');
assert_true(str_contains($index, 'data-lymow-cmd="resume"'), 'Lymow Resume button');
assert_true(str_contains($index, 'data-lymow-cmd="dock"'), 'Lymow Dock button');
assert_true(!str_contains($index, 'This panel does not send start, dock, or pause'), 'settings hint updated');

$js = (string) file_get_contents($root . '/public/assets/app.js');
assert_true(str_contains($js, 'function sendLymowCommand'), 'JS sendLymowCommand');
assert_true(str_contains($js, 'function homeAutoLymowAction'), 'JS homeAutoLymowAction');
assert_true(str_contains($js, "homeAutoTrayChipSpec('lymow', 'start'"), 'tray Start Then chip');
assert_true(str_contains($js, "kind: 'lymow'"), 'lymow Then kind');
assert_true(!str_contains($js, 'This panel does not send Lymow start'), 'old Then toast removed');

$autoPhp = (string) file_get_contents($root . '/src/YarboHomeAutomations.php');
assert_true(str_contains($autoPhp, 'LYMOW_COMMANDS'), 'automations LYMOW_COMMANDS');
assert_true(str_contains($autoPhp, 'function runLymowAction'), 'automations runLymowAction');

$tmp = sys_get_temp_dir() . '/yarbo-lymow-ctrl-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
$auto = new YarboHomeAutomations($tmp);
$saved = $auto->save([
    'id' => 'a-lymow-dock',
    'name' => 'Dock Lymow',
    'trigger' => ['type' => 'time', 'at' => '21:00'],
    'actions' => [['kind' => 'lymow', 'command' => 'dock']],
]);
assert_true(!empty($saved['ok']), 'save lymow Then action: ' . json_encode($saved));
$store = $auto->load();
$rule = null;
foreach ($store['automations'] as $row) {
    if (($row['id'] ?? '') === 'a-lymow-dock') {
        $rule = $row;
        break;
    }
}
assert_true(is_array($rule), 'saved rule present');
assert_true(($rule['actions'][0]['kind'] ?? '') === 'lymow', 'normalized kind lymow');
assert_true(($rule['actions'][0]['command'] ?? '') === 'dock', 'normalized command dock');
assert_true(
    YarboHomeAutomations::thenPhrase(['kind' => 'lymow', 'command' => 'start']) === 'Lymow Start',
    'thenPhrase start'
);

$bad = $auto->save([
    'id' => 'a-lymow-bad',
    'name' => 'Bad',
    'trigger' => ['type' => 'time', 'at' => '22:00'],
    'actions' => [['kind' => 'lymow', 'command' => 'cancel']],
]);
assert_true(empty($bad['ok']), 'reject unknown lymow command: ' . json_encode($bad));

assert_true(YarboLymow::COMMANDS === ['start', 'pause', 'resume', 'dock'], 'COMMANDS const');

$docs = (string) file_get_contents($root . '/docs/lymow.md');
assert_true(str_contains($docs, 'Start'), 'docs mention Start');
assert_true(!str_contains($docs, 'Start, dock, and pause are not sent'), 'docs no longer say controls disabled');

$change = (string) file_get_contents($root . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.222]'), 'changelog 4.0.222');
assert_true(str_contains($change, 'Lymow'), 'changelog mentions Lymow');

fwrite(STDOUT, "test_lymow_controls.php ok\n");
