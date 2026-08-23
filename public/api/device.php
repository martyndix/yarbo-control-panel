<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCommands;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboPaperDevice;

$devices = new YarboPaperDevice(dirname(__DIR__, 2));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

function device_token_from_request(): string
{
    $header = $_SERVER['HTTP_X_PAPERMONO_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return trim($header);
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return trim((string) ($_GET['token'] ?? ''));
}

function device_json_input(): array
{
    $input = $_POST;
    if ($input !== []) {
        return $input;
    }
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        return [];
    }
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

if ($method === 'GET' && ($action === 'dashboard' || $action === '')) {
    json_response(['ok' => true] + $devices->dashboard());
}

if ($method === 'GET' && $action === 'ports') {
    json_response($devices->listSerialPorts());
}

if ($method === 'GET' && $action === 'compact') {
    $device = $devices->findByToken(device_token_from_request());
    if ($device === null) {
        json_response(['ok' => false, 'error' => 'Invalid PaperMono token'], 401);
    }
    $devices->touch((string) $device['id'], isset($_GET['fw']) ? (string) $_GET['fw'] : null);
    json_response($devices->compactStatus());
}

if ($method === 'GET' && $action === 'firmware') {
    $device = $devices->findByToken(device_token_from_request());
    if ($device === null) {
        json_response(['ok' => false, 'error' => 'Invalid PaperMono token'], 401);
    }
    $path = $devices->firmwarePath();
    if (!$devices->firmwareAvailable()) {
        json_response([
            'ok' => false,
            'error' => 'Firmware binary is not built yet. Use Settings → PaperMono to flash after building, or run pio in firmware/papermono.',
        ], 404);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="papermono-' . YarboPaperDevice::FIRMWARE_VERSION . '.bin"');
    header('X-PaperMono-Version: ' . YarboPaperDevice::FIRMWARE_VERSION);
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Unknown action'], 400);
}

$input = device_json_input();
$action = (string) ($input['action'] ?? $action);

if ($action === 'register') {
    json_response(['ok' => true, 'device' => $devices->register($input)]);
}

if ($action === 'revoke') {
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '' || !$devices->revoke($id)) {
        json_response(['ok' => false, 'error' => 'Device not found'], 404);
    }
    json_response(['ok' => true]);
}

if ($action === 'flash') {
    json_response($devices->flash($input));
}

if ($action === 'configure_usb') {
    json_response($devices->configureUsb($input));
}

if ($action === 'command') {
    $device = $devices->findByToken((string) ($input['token'] ?? device_token_from_request()));
    if ($device === null) {
        json_response(['ok' => false, 'error' => 'Invalid PaperMono token'], 401);
    }
    $devices->touch((string) $device['id']);
    $cmd = (string) ($input['command'] ?? '');
    $allowed = [
        'stop' => 'stop',
        'return_to_dock' => 'return_to_dock',
        'pause' => 'pause',
        'resume' => 'resume',
        'lights_on' => 'lights_on',
        'lights_off' => 'lights_off',
        'buzzer' => 'buzzer',
        'controller_on' => 'controller_on',
    ];
    if (!isset($allowed[$cmd])) {
        json_response(['ok' => false, 'error' => 'Unsupported command'], 400);
    }

    try {
        $agent = YarboMqttAgentClient::requireRunning();
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }

    $mapped = $allowed[$cmd];
    if ($mapped === 'controller_on') {
        $result = $agent->controller(true);
    } elseif ($mapped === 'lights_on' || $mapped === 'lights_off') {
        $result = $agent->lights($mapped === 'lights_on');
    } elseif ($mapped === 'buzzer') {
        $result = $agent->buzzer();
    } elseif ($mapped === 'return_to_dock') {
        $result = $agent->returnToDock();
    } elseif ($mapped === 'stop') {
        $result = $agent->stop();
    } elseif ($mapped === 'pause') {
        $result = $agent->publishVariants(YarboCommands::pauseVariants(), 'work');
    } else {
        $result = $agent->publishVariants([['cmd' => 'resume', 'payload' => []]], 'work');
    }

    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Command failed'),
        ], 500);
    }

    json_response(['ok' => true, 'command' => $cmd, 'via' => 'agent']);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
