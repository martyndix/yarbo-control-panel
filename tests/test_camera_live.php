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
assert_true(str_contains($camera, 'VIDEO_STREAM_LOW'), 'live view uses the small stream so another device can load');
assert_true(str_contains($camera, 'BYTE_BUDGET_PER_S'), 'pictures stay inside a small share of the network');
assert_true(str_contains($camera, 'RECEIVE_BUDGET_PER_S'), 'the camera download stops when it fills the machine');
assert_true(str_contains($camera, 'unsubscribe_all_video'), 'a full download is paused at the camera library');
assert_true(str_contains($camera, 'sched_setaffinity'), 'pictures keep one core so the page can load');
assert_true(str_contains($camera, 'SCHED_IDLE'), 'camera threads step aside while the page loads');
assert_true(!str_contains($camera, 'def subscribe_one'), 'all four cameras stay subscribed');
$app = (string) file_get_contents($root . '/public/assets/app.js');
assert_true(!str_contains($app, 'Pictures take turns'), 'the page does not switch the cameras off in turn');
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
assert_true(str_contains($proxy, 'if not self._relay_pictures()'), 'pictures use the listener that was showing them');
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
assert_true(str_contains($agent, 'wait=False'), 'a direction does not wait for an acknowledgement');
assert_true(str_contains($agent, 'async def motion_loop'), 'a direction is sent after the page is answered');
assert_true(str_contains($agent, 'def queue_drive'), 'a direction answers before it is published');
assert_true(str_contains($agent, 'motion.put_nowait'), 'a direction does not wait for the robot before answering');
assert_true(!str_contains($agent, 'for _ in range(3):'), 'a direction does not wait out several publishes before answering');
$cloud = (string) file_get_contents($root . '/scripts/yarbo_cloud_control.py');
assert_true(str_contains($cloud, 'def _publish_raw'), 'a direction is published without data_feedback');
assert_true(str_contains($cloud, '"cmd_vel"'), 'cmd_vel does not wait for data_feedback');
$drivePhp = (string) file_get_contents($root . '/public/api/drive.php');
assert_true(!str_contains($drivePhp, 'requireRunning'), 'a direction does not restart the robot session');
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
assert_true(str_contains($appJs, 'new WebSocket(url)'), 'Safari can show pictures without using the panel port');
assert_true(str_contains($proxy, 'Sec-WebSocket-Accept'), 'the picture port accepts the picture socket');
assert_true(str_contains($appJs, 'function suspendPictures'), 'an ordinary picture stream can still step aside');
assert_true(str_contains($appJs, 'function pictureSocketUrl'), 'pictures open on the picture port');
assert_true(str_contains($appJs, 'Number(location.port) + 1'), 'the picture port is the one above the panel');
assert_true(str_contains($appJs, 'leaves the pictures open'), 'a held direction leaves the pictures open');
assert_true(str_contains($appJs, "fetch('/api/drive.php'"), 'a direction is an ordinary panel request');
$sendAt = strpos($appJs, 'async function sendDrive');
$sendEnd = strpos($appJs, 'async function flushDriveQueue', (int) $sendAt);
$sendFn = substr($appJs, (int) $sendAt, (int) $sendEnd - (int) $sendAt);
assert_true(!str_contains($sendFn, 'AbortSignal.timeout'), 'the page does not give up on a direction before the panel answers');
assert_true(!str_contains($sendFn, 'Drive did not answer'), 'a direction is not reported as unanswered while the panel still has it');
$fetchAt = strpos($appJs, 'async function fetchStatus');
$fetchEnd = strpos($appJs, 'function cameraModeUrl', (int) $fetchAt);
$fetchFn = substr($appJs, (int) $fetchAt, (int) $fetchEnd - (int) $fetchAt);
assert_true(!str_contains($fetchFn, 'cameraPicturesOpen()'), 'the status card keeps polling while the pictures are open');
assert_true(!str_contains($appJs, 'Step the pictures aside for this one press'), 'Connect does not close the pictures');
assert_true(str_contains($appJs, 'function drainCameraFrames'), 'picture decoding does not block a press');
assert_true(str_contains($agent, 'drive_busy_until'), 'a held direction is not stuck behind a camera keep-alive');
assert_true(!str_contains($appJs, 'closePicturesForDrive'), 'a press does not close the pictures');
assert_true(!str_contains($appJs, 'pausePictureSocket'), 'a press does not close the pictures');
assert_true(str_contains($appJs, "event.op === 'status'"), 'the status card keeps updating while the pictures are open');
$pictureAt = strpos($proxy, 'def _websocket_session');
$jobsAt = strpos($proxy, 'def _websocket_jobs');
$pictureFn = substr($proxy, (int) $pictureAt, (int) $jobsAt - (int) $pictureAt);
assert_true(!str_contains($pictureFn, 'status.php'), 'a picture stream does not request PHP');
assert_true(!str_contains($pictureFn, '_websocket_jobs'), 'a picture stream does not post Connect or a direction');
assert_true(str_contains($proxy, '--pictures-only'), 'pictures run in their own process');
assert_true(str_contains((string) file_get_contents($root . '/scripts/panel_https_ensure.sh'), '--pictures-only'), 'a panel restart starts the picture process');
$statusPhp = (string) file_get_contents($root . '/public/api/status.php');
assert_true(str_contains($statusPhp, 'function status_card_only'), 'status can answer the robot card without the whole dashboard');
assert_true(str_contains($appJs, 'function applyRobotCard'), 'a camera status refreshes the robot card');
assert_true(str_contains($appJs, 'data.card'), 'the robot card update does not rebuild the whole page');
assert_true(str_contains($appJs, 'Pictures open on their own'), 'pictures do not wait for the quiet connection');
assert_true(!str_contains($appJs, 'attachCommandSocket(() => attachCameraStream())'), 'pictures do not wait for the quiet connection to finish');
assert_true(str_contains($appJs, "fetch('/api/command.php'") || str_contains($appJs, 'postPanelJson'), 'Connect stays on the panel');
assert_true(str_contains($proxy, 'Pictures are on the picture port.'), 'the panel port does not hold a picture stream');
assert_true(str_contains($proxy, 'This connection does not call PHP'), 'pictures keep moving without calling the panel');
assert_true(str_contains($proxy, 'Only one picture sits on this socket'), 'a live view does not keep every picture and fill the machine');
assert_true(!str_contains($pictureFn, 'status.php?card=1'), 'the picture connection does not ask for the robot card');
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
