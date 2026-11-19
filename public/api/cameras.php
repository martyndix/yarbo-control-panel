<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCamera;

try {
    $enabled = (bool) ($config['cameras_enabled'] ?? true);
    $tiles = YarboCamera::tiles($config);
    $cameraState = null;
    $probe = ['host' => (string) ($config['broker_host'] ?? ''), 'ports' => []];
    if ($enabled) {
        $probe = YarboCamera::probePorts($config);
        $client = yarbo_client($config);
        $client->connect();
        $raw = $client->requestTelemetry(5);
        $client->disconnect();
        $cameraState = is_array($raw) ? ($raw['camera_state'] ?? null) : null;
    }

    $listed = $enabled
        ? YarboCamera::list($config, is_array($cameraState) ? $cameraState : null)
        : [];
    $cameras = [];
    foreach ($tiles as $id => $tile) {
        $row = $listed[$id] ?? $tile;
        $row['view'] = $tile['view'];
        $cameras[] = $row;
    }

    $anyPortOpen = in_array(true, $probe['ports'], true);

    json_response([
        'ok'       => true,
        'enabled'  => $enabled,
        'host'     => $probe['host'],
        'ports_open' => $anyPortOpen,
        'port_probe' => $probe['ports'],
        'cameras'  => $cameras,
        'message'  => $anyPortOpen
            ? null
            : 'Press Start live view. The pictures travel through Yarbo\'s video service, then back to this panel on your home network. Live view stays off while the robot is on 4G.',
        'setup'    => $enabled ? [
            'step1' => 'Get SSH access from Yarbo support (owner credentials are not published). Then: SSH_TARGET="root@192.168.1.223" ./scripts/camera-tunnel.sh',
            'step2' => 'Click "Prepare cameras" then "Recheck streams" below',
            'step3' => 'Test: ffplay -rtsp_transport tcp rtsp://127.0.0.1:19201/live/chn0',
        ] : null,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
