<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCommands;

const LED_CHANNELS = [
    'led_head',
    'led_left_w',
    'led_right_w',
    'body_left_r',
    'body_right_r',
    'tail_left_r',
    'tail_right_r',
];

/** @var array<string, array{variants: callable(): array<int, array{cmd: string, payload: array<string, mixed>}>, acquire: bool}> $ACTIONS */
$ACTIONS = [
    'lights_on' => [
        'variants' => static fn (): array => [['cmd' => 'light_ctrl', 'payload' => array_fill_keys(LED_CHANNELS, 255)]],
        'acquire' => true,
    ],
    'lights_off' => [
        'variants' => static fn (): array => [['cmd' => 'light_ctrl', 'payload' => array_fill_keys(LED_CHANNELS, 0)]],
        'acquire' => true,
    ],
    'buzzer' => [
        'variants' => static fn (): array => [['cmd' => 'cmd_buzzer', 'payload' => ['state' => 1, 'timeStamp' => time()]]],
        'acquire' => true,
    ],
    'return_to_dock' => [
        'variants' => static fn (): array => YarboCommands::returnToDockVariants(),
        'acquire' => true,
    ],
    'pause' => [
        'variants' => static fn (): array => YarboCommands::pauseVariants(),
        'acquire' => true,
    ],
    'resume' => [
        'variants' => static fn (): array => [['cmd' => 'resume', 'payload' => []]],
        'acquire' => true,
    ],
    'stop' => [
        'variants' => static fn (): array => YarboCommands::stopVariants(),
        'acquire' => true,
    ],
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
    $cmd = $client->sendCommandVariants($def['variants'](), $def['acquire']);
    $client->disconnect();

    json_response(['ok' => true, 'action' => $action, 'cmd' => $cmd]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
