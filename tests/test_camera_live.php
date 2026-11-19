<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboCamera;
use Yarbo\YarboCameraLive;
use Yarbo\YarboTelemetry;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_true(YarboTelemetry::linkIsCellular(['net_type' => '1']) === true, 'net_type 1 is 4G');
assert_true(YarboTelemetry::linkIsCellular(['net_type' => 'wifi']) === false, 'wifi is not 4G');
assert_true(YarboTelemetry::linkIsCellular(['halow_status' => 1, 'net_type' => '1']) === false, 'HaLow wins over 4G');
assert_true(YarboTelemetry::linkIsCellular(['route_priority' => ['wwan0' => 10, 'wlan0' => 100]]) === true, 'wwan0 is 4G');
assert_true(YarboTelemetry::linkIsCellular(['route_priority' => ['wlan0' => 10]]) === false, 'wlan0 is not 4G');
assert_true(YarboTelemetry::linkIsCellular([]) === false, 'unknown link is not 4G');
assert_true(YarboTelemetry::linkIsCellular(['halow_status' => ['not' => 'a number']]) === false, 'unreadable link is not 4G');

assert_true(YarboCameraLive::viewFor('front') === 'middle', 'front is middle');
assert_true(YarboCameraLive::viewFor('rear') === 'back', 'rear is back');
assert_true(YarboCameraLive::viewFor('left') === 'left', 'left stays left');

$tiles = YarboCamera::tiles(['cameras_enabled' => false]);
assert_true(count($tiles) === 4, 'four camera tiles even when RTSP is off');
assert_true(($tiles['front']['view'] ?? '') === 'middle', 'front tile view is middle');

$root = dirname(__DIR__);
$update = (string) file_get_contents($root . '/scripts/update.sh');
assert_true(str_contains($update, '--rollback'), 'update.sh can restore the previous version');
assert_true(str_contains($update, 'v4.0.388'), 'update.sh knows the 4.0.388 restore tag');
assert_true(str_contains($update, 'install_agora_camera_sdk'), 'panel update installs the camera library');

$agent = (string) file_get_contents($root . '/scripts/mqtt_agent.py');
$vision = strpos($agent, 'if session_mode(req) == "vision":');
assert_true($vision !== false, 'vision session exists');
$control = strpos($agent, 'await apply_command_session', $vision);
assert_true($control !== false && $control > $vision, 'control session still follows vision');
$slice = substr($agent, $vision, $control - $vision);
assert_true(str_contains($slice, 'publish_command'), 'vision publishes the command');
assert_true(!str_contains($slice, 'get_controller'), 'vision does not take the controller');
assert_true(!str_contains($slice, 'apply_command_session'), 'vision does not use the control session');

$camera = (string) file_get_contents($root . '/scripts/yarbo_camera_agent.py');
assert_true(str_contains($camera, 'getAgoraToken'), 'camera agent requests an Agora token');
assert_true(!str_contains($camera, 'set_working_state", {"state": 0'), 'stop does not idle a job');
assert_true(str_contains($camera, '"state": 0'), 'stop tells the robot to stop the cameras');

$py = $root . '/.venv/bin/python3';
if (!is_executable($py)) {
    $py = trim((string) shell_exec('command -v python3'));
}
assert_true($py !== '', 'python3 available');
$code = 1;
passthru(escapeshellarg($py) . ' ' . escapeshellarg($root . '/scripts/yarbo_camera_agent.py') . ' --self-test', $code);
assert_true($code === 0, 'camera agent self-test');

echo "ok\n";
