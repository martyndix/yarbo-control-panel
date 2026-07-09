<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCamera;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

try {
    if (!($config['cameras_enabled'] ?? true)) {
        json_response(['ok' => false, 'error' => 'Cameras disabled in config'], 400);
    }

    YarboCamera::prepareCameras($config);
    $probe = YarboCamera::probePorts($config);

    json_response([
        'ok' => true,
        'message' => 'Sent camera_toggle and smart_vision_control via MQTT.',
        'ports_open' => in_array(true, $probe['ports'], true),
        'host' => $probe['host'],
        'localhost_tunnel' => $probe['localhost_tunnel'] ?? false,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
