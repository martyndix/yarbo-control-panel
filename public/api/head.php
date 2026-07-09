<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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
    $client = yarbo_client($config);
    $client->connect();
    $client->sendCommand($def['cmd'], $def['payload'], true);
    $client->disconnect();

    json_response(['ok' => true, 'action' => $action, 'cmd' => $def['cmd']]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
