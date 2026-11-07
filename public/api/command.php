<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCommands;
use Yarbo\YarboMqttAgentClient;

const AGENT_REQUIRED_ERROR = 'Yarbo cloud agent is not running. Set account email/password in Settings → Yarbo, install yarbo-data-sdk, then start with ./scripts/dev.sh (or .venv/bin/python scripts/mqtt_agent.py).';

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
        'kind' => 'return_to_dock',
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
        'kind' => 'stop',
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

/**
 * After a robot swap, the agent may still be bound to the old IP/serial until
 * Settings Save restarts it. One reconnect retry covers that case.
 *
 * @param callable(YarboMqttAgentClient): array<string, mixed> $fn
 * @return array{0: YarboMqttAgentClient, 1: array<string, mixed>}
 */
$runWithMqttRetry = static function (callable $fn): array {
    $agent = YarboMqttAgentClient::requireRunning();
    $result = $fn($agent);
    if (($result['ok'] ?? false)) {
        return [$agent, $result];
    }
    $error = strtolower((string) ($result['error'] ?? ''));
    if (!str_contains($error, 'mqtt not connected') && !str_contains($error, 'mqtt connect failed')) {
        return [$agent, $result];
    }
    // Re-read config.php (new broker/serial) and try once more.
    if (!$agent->restart(true)) {
        return [$agent, $result];
    }
    $agent = YarboMqttAgentClient::requireRunning();
    $retry = $fn($agent);
    if (!($retry['ok'] ?? false) && ($retry['error'] ?? '') === '') {
        $retry['error'] = (string) ($result['error'] ?? 'MQTT not connected');
    }
    $retry['mqtt_agent_restarted'] = true;

    return [$agent, $retry];
};

try {
    if (($def['kind'] ?? '') === 'controller') {
        $on = (bool) ($def['controller'] ?? false);
        [, $result] = $runWithMqttRetry(static fn (YarboMqttAgentClient $agent): array => $agent->controller($on));
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Controller command failed'),
                'via' => 'agent',
                'mqtt_agent_restarted' => (bool) ($result['mqtt_agent_restarted'] ?? false),
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
            'mqtt_agent_restarted' => (bool) ($result['mqtt_agent_restarted'] ?? false),
        ]);
    }

    if (($def['kind'] ?? '') === 'buzzer') {
        [, $result] = $runWithMqttRetry(static fn (YarboMqttAgentClient $agent): array => $agent->buzzer());
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
        [, $result] = $runWithMqttRetry(static fn (YarboMqttAgentClient $agent): array => $agent->lights($on));
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

    if (($def['kind'] ?? '') === 'stop') {
        [, $result] = $runWithMqttRetry(static fn (YarboMqttAgentClient $agent): array => $agent->stop());
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Stop failed'),
                'via' => 'agent',
                'ack_msg' => $result['ack_msg'] ?? null,
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => $action,
            'cmd' => $result['cmd'] ?? 'stop',
            'via' => $result['via'] ?? 'agent',
            'ack_msg' => $result['ack_msg'] ?? null,
            'hold_controller' => (bool) ($result['hold_controller'] ?? true),
        ]);
    }

    if (($def['kind'] ?? '') === 'return_to_dock') {
        [, $result] = $runWithMqttRetry(static fn (YarboMqttAgentClient $agent): array => $agent->returnToDock());
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Return to dock failed'),
                'via' => 'agent',
                'ack_msg' => $result['ack_msg'] ?? null,
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => $action,
            'cmd' => $result['cmd'] ?? 'cmd_recharge',
            'via' => $result['via'] ?? 'agent',
            'ack_msg' => $result['ack_msg'] ?? null,
            'hold_controller' => (bool) ($result['hold_controller'] ?? true),
        ]);
    }

    $variants = ($def['variants'] ?? static fn (): array => [])();
    [, $result] = $runWithMqttRetry(
        static fn (YarboMqttAgentClient $agent): array => $agent->publishVariants($variants, 'work')
    );
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
        'hold_controller' => (bool) ($result['hold_controller'] ?? true),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => AGENT_REQUIRED_ERROR,
        'detail' => $e->getMessage(),
        'via' => 'none',
    ], 503);
}
