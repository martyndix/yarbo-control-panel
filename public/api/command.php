<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCommands;
use Yarbo\YarboMqttAgentClient;

const AGENT_REQUIRED_ERROR = 'MQTT agent is not running. Start the panel with ./scripts/dev.sh (or run .venv/bin/python scripts/mqtt_agent.py). Connect–disconnect control would flash lights off.';

/** @var array<string, array{kind: string, variants?: callable(): array<int, array{cmd: string, payload: array<string, mixed>}>, lights?: bool}> $ACTIONS */
$ACTIONS = [
    // Explicit app-controller hold (robot speaks "app controller connected").
    'controller_on' => [
        'kind' => 'controller',
        'controller' => true,
    ],
    'controller_off' => [
        'kind' => 'controller',
        'controller' => false,
    ],
    // Agent holds light_ctrl while on (robot otherwise drops lights after ~0.5s).
    'lights_on' => [
        'kind' => 'lights',
        'lights' => true,
    ],
    'lights_off' => [
        'kind' => 'lights',
        'lights' => false,
    ],
    'buzzer' => [
        'kind' => 'buzzer',
    ],
    'return_to_dock' => [
        'kind' => 'variants',
        'variants' => static fn (): array => YarboCommands::returnToDockVariants(),
    ],
    'pause' => [
        'kind' => 'variants',
        'variants' => static fn (): array => YarboCommands::pauseVariants(),
    ],
    'resume' => [
        'kind' => 'variants',
        'variants' => static fn (): array => [['cmd' => 'resume', 'payload' => []]],
    ],
    'stop' => [
        'kind' => 'variants',
        'variants' => static fn (): array => YarboCommands::stopVariants(),
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

$def = $ACTIONS[$action];

try {
    $agent = YarboMqttAgentClient::requireRunning();

    if (($def['kind'] ?? '') === 'controller') {
        $on = (bool) ($def['controller'] ?? false);
        $result = $agent->controller($on);
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Controller command failed'),
                'via' => 'agent',
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => $action,
            'cmd' => 'get_controller',
            'via' => 'agent',
            'hold_controller' => (bool) ($result['hold_controller'] ?? $on),
            'controller_acquired' => (bool) ($result['controller_acquired'] ?? false),
            'car_controller' => (bool) ($result['car_controller'] ?? false),
            'control_awake' => (bool) ($result['control_awake'] ?? false),
            'working_state' => $result['working_state'] ?? null,
            'ack_msg' => $result['ack_msg'] ?? null,
            'warning' => $result['warning'] ?? null,
        ]);
    }

    if (($def['kind'] ?? '') === 'buzzer') {
        $result = $agent->buzzer();
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Buzzer command failed'),
                'via' => 'agent',
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => $action,
            'cmd' => $result['cmd'] ?? 'cmd_buzzer',
            'via' => 'agent',
            'hold_controller' => (bool) ($result['hold_controller'] ?? true),
            'note' => $result['note'] ?? null,
        ]);
    }

    if (($def['kind'] ?? '') === 'lights') {
        $on = (bool) ($def['lights'] ?? false);
        $result = $agent->lights($on);
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Lights command failed'),
                'via' => 'agent',
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => $action,
            'cmd' => 'light_ctrl',
            'via' => 'agent',
            'lights_on' => $on,
            'hold_controller' => (bool) ($result['hold_controller'] ?? false),
            'charging' => (bool) ($result['charging'] ?? false),
            'charging_status' => $result['charging_status'] ?? null,
        ]);
    }

    $variants = ($def['variants'] ?? static fn (): array => [])();
    $result = $agent->publishVariants($variants);
    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Command failed'),
            'via' => 'agent',
        ], 500);
    }

    json_response([
        'ok' => true,
        'action' => $action,
        'cmd' => $result['cmd'] ?? ($variants[count($variants) - 1]['cmd'] ?? null),
        'via' => 'agent',
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => AGENT_REQUIRED_ERROR,
        'detail' => $e->getMessage(),
        'via' => 'none',
    ], 503);
}
