<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const LED_CHANNELS = [
    'led_head',
    'led_left_w',
    'led_right_w',
    'body_left_r',
    'body_right_r',
    'tail_left_r',
    'tail_right_r',
];

/** @var array<string, array{cmd: string, payload: array<string, mixed>}> $ACTIONS */
$ACTIONS = [
    'lights_on'      => ['cmd' => 'light_ctrl', 'payload' => array_fill_keys(LED_CHANNELS, 255)],
    'lights_off'     => ['cmd' => 'light_ctrl', 'payload' => array_fill_keys(LED_CHANNELS, 0)],
    'buzzer'         => ['cmd' => 'cmd_buzzer', 'payload' => ['state' => 1, 'timeStamp' => time()]],
    'return_to_dock' => ['cmd' => 'cmd_recharge', 'payload' => []],
    'pause'          => ['cmd' => 'planning_paused', 'payload' => []],
    'resume'         => ['cmd' => 'resume', 'payload' => []],
    'stop'           => ['cmd' => 'dstop', 'payload' => []],
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = $_POST;
if (empty($input)) {
    $body = file_get_contents('php://input');
    if ($body !== false && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

$action = $input['action'] ?? '';
if (!isset($ACTIONS[$action])) {
    json_response([
        'ok' => false,
        'error' => 'Unknown action. Valid: ' . implode(', ', array_keys($ACTIONS)),
    ], 400);
}

try {
    $client = yarbo_client($config);
    $client->connect();

    $def = $ACTIONS[$action];
    $client->sendCommand($def['cmd'], $def['payload'], true);
    $client->disconnect();

    json_response(['ok' => true, 'action' => $action]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
