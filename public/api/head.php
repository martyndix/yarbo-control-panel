<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboMqttAgentClient;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = $_POST;
if ($input === []) {
    $body = file_get_contents('php://input');
    if ($body !== false && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

$action = (string) ($input['action'] ?? '');
$headType = isset($input['head_type']) ? (int) $input['head_type'] : null;

/** @var array<string, array{head_types: array<int, int>, cmd: string, payload: array<string, mixed>}> $ACTIONS */
$ACTIONS = [
    'mower_blade_height' => [
        'head_types' => [3, 5],
        'cmd' => 'set_blade_height',
        'payload' => ['height' => (int) ($input['value'] ?? 0)],
    ],
    'mower_blade_speed' => [
        'head_types' => [3, 5],
        'cmd' => 'set_blade_speed',
        'payload' => ['speed' => (int) ($input['value'] ?? 0)],
    ],
    'snow_chute_angle' => [
        'head_types' => [1],
        'cmd' => 'set_chute_angle',
        'payload' => ['angle' => (int) ($input['value'] ?? 0)],
    ],
];

if (!isset($ACTIONS[$action])) {
    json_response([
        'ok' => false,
        'error' => 'Unknown action. Valid: mower_blade_height, mower_blade_speed, snow_chute_angle',
    ], 400);
}

$def = $ACTIONS[$action];
if ($headType !== null && !in_array($headType, $def['head_types'], true)) {
    json_response([
        'ok' => false,
        'error' => 'This control is not available for the current head type.',
    ], 400);
}

try {
    $agent = YarboMqttAgentClient::requireRunning();
    $result = $agent->publish($def['cmd'], $def['payload']);
    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Head command failed'),
            'via' => 'agent',
        ], 500);
    }

    json_response(['ok' => true, 'action' => $action, 'cmd' => $def['cmd'], 'via' => 'agent']);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'MQTT agent is not running. Start the panel with ./scripts/dev.sh (or run .venv/bin/python scripts/mqtt_agent.py).',
        'detail' => $e->getMessage(),
        'via' => 'none',
    ], 503);
}
