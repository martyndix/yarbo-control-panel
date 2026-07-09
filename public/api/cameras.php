<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCamera;

try {
    $cameraState = null;
    $probe = YarboCamera::probePorts($config);
    if (!empty($config['cameras_enabled'])) {
        $client = yarbo_client($config);
        $client->connect();
        $raw = $client->requestTelemetry(5);
        $client->disconnect();
        $cameraState = is_array($raw) ? ($raw['camera_state'] ?? null) : null;
    }

    $anyPortOpen = in_array(true, $probe['ports'], true);

    json_response([
        'ok'       => true,
        'enabled'  => (bool) ($config['cameras_enabled'] ?? true),
        'host'     => $probe['host'],
        'ports_open' => $anyPortOpen,
        'port_probe' => $probe['ports'],
        'cameras'  => array_values(YarboCamera::list($config, is_array($cameraState) ? $cameraState : null)),
        'message'  => $anyPortOpen
            ? null
            : 'The Yarbo app uses cloud video. This panel needs local RTSP on ports 19201–19204. Run scripts/camera-tunnel.sh in a separate terminal, then click Recheck streams.',
        'setup'    => [
            'step1' => 'Get SSH access from Yarbo support (owner credentials are not published). Then: SSH_TARGET="root@192.168.1.223" ./scripts/camera-tunnel.sh',
            'step2' => 'Click "Prepare cameras" then "Recheck streams" below',
            'step3' => 'Test: ffplay -rtsp_transport tcp rtsp://127.0.0.1:19201/live/chn0',
        ],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
