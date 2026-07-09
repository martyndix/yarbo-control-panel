<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCamera;

$cameraId = $_GET['camera'] ?? '';
if ($cameraId === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'camera parameter required']);
    exit;
}

try {
    if (!($config['cameras_enabled'] ?? true)) {
        throw new RuntimeException('Cameras are disabled in config');
    }

    set_time_limit(0);
    YarboCamera::streamMjpeg($config, $cameraId, (int) ($config['camera_fps'] ?? 5));
} catch (Throwable $e) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => friendly_error($e)]);
}
