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
assert_true(str_contains($camera, 'or 8767'), 'live view does not bind the Matter agent port');
assert_true(!str_contains($camera, 'or 8766'), 'live view default port is not 8766');
assert_true(str_contains($camera, 'execute-api.us-east-1.amazonaws.com/prod'), 'camera tokens come from /prod');
assert_true(!str_contains($camera, 'amazonaws.com/Stage'), 'camera tokens do not use /Stage');
$sensor = strpos($camera, 'publish_vision("set_manul_camera_check"');
$toggle = strpos($camera, 'publish_vision("camera_toggle"');
assert_true($sensor !== false && $toggle !== false && $sensor < $toggle, 'sensor check is sent before camera toggle');
$subscribe = strpos($camera, 'local_user.subscribe_all_video(options)');
$connect = strpos($camera, 'conn.connect(grant["token"]');
assert_true($subscribe !== false && $connect !== false && $subscribe < $connect, 'video is subscribed before connect');
assert_true(str_contains($camera, 'annexb_has_idr'), 'keyframes are detected from the IDR NAL');
assert_true(str_contains($camera, 'getAgoraToken'), 'camera agent requests an Agora token');
assert_true(str_contains($camera, 'DEFAULT_AREA_CODE = 2'), 'joins the robot video region');
assert_true(!str_contains($camera, 'set_working_state'), 'live view does not change the job');
assert_true(str_contains($camera, '"state": 0'), 'stop tells the robot to stop the cameras');
$camerasApi = (string) file_get_contents($root . '/public/api/cameras.php');
assert_true(!str_contains($camerasApi, 'camera-tunnel.sh'), 'the camera card does not ask for the old tunnel');

$py = $root . '/.venv/bin/python3';
if (!is_executable($py)) {
    $py = trim((string) shell_exec('command -v python3'));
}
assert_true($py !== '', 'python3 available');
$code = 1;
passthru(escapeshellarg($py) . ' ' . escapeshellarg($root . '/scripts/yarbo_camera_agent.py') . ' --self-test', $code);
assert_true($code === 0, 'camera agent self-test');

echo "ok\n";
