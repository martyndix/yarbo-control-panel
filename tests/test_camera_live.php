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
$listen = strpos($camera, 'register_video_encoded_frame_observer');
assert_true($listen !== false && $connect !== false && $listen < $connect, 'picture listener is registered before connect');
assert_true(!str_contains($camera, 'def rejoin_quiet'), 'live view does not leave the channel to rejoin');
assert_true(str_contains($camera, 'annexb_has_idr'), 'keyframes are detected from the IDR NAL');
assert_true(str_contains($camera, 'getAgoraToken'), 'camera agent requests an Agora token');
assert_true(str_contains($camera, 'DEFAULT_AREA_CODE = 2'), 'joins the robot video region');
assert_true(!str_contains($camera, 'set_working_state'), 'live view does not change the job');
assert_true(str_contains($camera, '"state": 0'), 'stop tells the robot to stop the cameras');
$index = (string) file_get_contents($root . '/public/index.php');
assert_true(str_contains($index, 'id="camera-hub"') || str_contains($index, 'class="camera-hub"'), 'drive pad sits with the cameras');
assert_true(str_contains($index, 'camera-toolbar'), 'start and the controller sit above the cameras');
$toolbarAt = strpos($index, 'camera-toolbar');
$stageAt = strpos($index, 'camera-stage');
assert_true($toolbarAt !== false && $stageAt !== false && $toolbarAt < $stageAt, 'the camera toolbar is above the pictures');
$toolbar = substr($index, (int) $toolbarAt, (int) $stageAt - (int) $toolbarAt);
assert_true(str_contains($toolbar, 'data-control="lights"'), 'lights sit with the camera controls');
assert_true(str_contains($toolbar, 'data-action="buzzer"'), 'buzzer sits with the camera controls');
assert_true(str_contains($toolbar, 'data-action="return_to_dock"'), 'dock sits with the camera controls');
assert_true(str_contains($toolbar, 'data-action="stop"'), 'robot stop sits with the camera controls');
assert_true(!str_contains($index, 'data-panel-id="controls"'), 'the separate controls section is gone');
assert_true(!str_contains($index, 'camera-prepare'), 'prepare cameras button is gone');
assert_true(!str_contains($index, 'camera-recheck'), 'recheck streams button is gone');
$proxy = (string) file_get_contents($root . '/scripts/panel_https_proxy.py');
assert_true(str_contains($proxy, 'def is_camera_frames'), 'HTTPS proxy recognises the picture stream');
assert_true(str_contains($proxy, 'def relay_upstream'), 'picture bytes are forwarded as they arrive');
assert_true(str_contains($proxy, 'GET /frames'), 'pictures come from the camera agent');
assert_true(str_contains($proxy, 'if not self._proxy_camera_frames()'), 'pictures use the listener that was showing them');
assert_true(!str_contains($proxy, 'def _relay_php_camera'), 'the picture stream does not stay inside a page worker');
assert_true(str_contains($proxy, 'retry: 40'), 'Safari asks for the next pictures straight away');
assert_true(str_contains($proxy, 'def read_picture_batch'), 'a picture reply ends so drive is not queued behind it');
$livePhp = (string) file_get_contents($root . '/src/YarboCameraLive.php');
assert_true(str_contains($livePhp, 'set_time_limit(0)'), 'the picture stream is flushed the way it was when the cameras showed');
assert_true(str_contains($livePhp, "header('Content-Type: text/event-stream')"), 'the page sends a picture stream');
assert_true(!str_contains($camera, 'def frame_due'), 'live pictures keep every encoded frame');
assert_true(!str_contains($camera, 'should_forward'), 'live pictures are not thinned before the browser');
assert_true(str_contains($camera, 'LINK_CHECK_S = 30'), 'live view does not ask the robot every second');
assert_true(str_contains($agent, '_control_waiters'), 'dock and lights go ahead of a status read');
assert_true(str_contains($agent, 'async def publish_quick'), 'a direction is sent without waiting out a camera publish');
assert_true(str_contains($agent, '_drive_waiters'), 'a direction goes ahead of a camera publish');
assert_true(str_contains($agent, 'state["dock_release"] = True'), 'dock lets go of the controller so the robot can leave manual mode');
assert_true(!str_contains($agent, 'set_manul_camera_check", {"state": 0'), 'dock does not turn the cameras off');
$appJs = (string) file_get_contents($root . '/public/assets/app.js');
assert_true(!str_contains($appJs, 'cameraFrameAt'), 'the page does not drop pictures the decoder still needs');
assert_true(!str_contains($appJs, 'has not received a picture'), 'the page does not close a picture stream that is still open');
assert_true(str_contains($livePhp, "'already' => true"), 'Start leaves a working picture process running');
assert_true(str_contains($camera, 'b": live\\n\\n"'), 'the picture stream starts the way it did when the cameras showed');
assert_true(str_contains($appJs, 'yarbo-camera-order'), 'camera order is saved in this browser');
assert_true(str_contains($appJs, 'new EventSource(url)'), 'pictures use their own stream');
assert_true(str_contains($appJs, 'function cameraPictureUrl'), 'pictures are not on the same connection as the buttons');
assert_true(str_contains($appJs, 'function cameraControlsOpen'), 'a direction rides on the open picture connection');
assert_true(str_contains($appJs, 'new WebSocket(url)'), 'Safari can drive without closing the pictures');
assert_true(str_contains($proxy, 'Sec-WebSocket-Accept'), 'the picture port carries presses back up the picture connection');
assert_true(str_contains($appJs, 'function suspendPictures'), 'an ordinary picture stream can still step aside');
assert_true(str_contains($appJs, 'location.host'), 'pictures and a held direction share the panel port');
assert_true(str_contains($appJs, 'Step the pictures aside, send the press the ordinary way'), 'Connect and Dock are sent on their own, then the pictures open again');
assert_true(str_contains($appJs, 'the pictures open again when the finger lifts'), 'a direction that the picture connection does not carry still goes out, then the pictures return');
assert_true(str_contains($proxy, 'Keep sending pictures while a direction is handled'), 'pictures keep moving while a direction is handled');
assert_true(str_contains($proxy, 'websocket" in (self.headers.get("Upgrade")'), 'the panel port accepts a press on the picture connection');
assert_true(str_contains($camera, 'session.user_stop = True'), 'only Stop turns the cameras off');
assert_true(str_contains($camera, 'if session.user_stop:'), 'a dropped picture connection does not turn the cameras off');
assert_true(str_contains($proxy, 'class PictureProxyHandler'), 'pictures listen on their own port');
assert_true(str_contains($proxy, 'Access-Control-Allow-Origin'), 'Safari can draw pictures from the picture port');
assert_true(str_contains($appJs, 'cameraStreamLive'), 'drive stays off until a live picture is showing');
assert_true(str_contains($appJs, 'firstPicture'), 'a live picture enables drive once, not on every frame');
assert_true(str_contains($appJs, 'el.disabled !== disabled'), 'drive hold is not cancelled by rewriting disabled');
assert_true(str_contains($appJs, 'stopDriveIfHeld'), 'lights and the controller stop a stuck direction');
assert_true(!str_contains($appJs, 'lightsBusy'), 'lights stay clickable after the first press');
assert_true(str_contains($appJs, "badge.textContent = 'Online'"), 'a live picture is labelled Online');
assert_true(str_contains($appJs, 'markCameraTileOnline'), 'the first picture clears the waiting banner');
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
