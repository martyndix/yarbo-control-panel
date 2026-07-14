<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboMqttAgentClient;

const MAX_LINEAR = 0.5;
const MAX_ANGULAR = 0.8;
const AGENT_REQUIRED_ERROR = 'MQTT agent is not running. Start the panel with ./scripts/dev.sh (or run .venv/bin/python scripts/mqtt_agent.py). Connect–disconnect drive is unreliable.';

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

if (!isset($input['linear'], $input['angular'])) {
    json_response(['ok' => false, 'error' => 'linear and angular required'], 400);
}

$linear = (float) $input['linear'];
$angular = (float) $input['angular'];
$enterManual = filter_var($input['enter_manual'] ?? false, FILTER_VALIDATE_BOOLEAN);

$linear = max(-MAX_LINEAR, min(MAX_LINEAR, $linear));
$angular = max(-MAX_ANGULAR, min(MAX_ANGULAR, $angular));

try {
    $agent = YarboMqttAgentClient::requireRunning();
    $result = $agent->drive($linear, $angular, $enterManual);
    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Drive command failed'),
            'via' => 'agent',
        ], 500);
    }

    json_response([
        'ok' => true,
        'linear' => $linear,
        'angular' => $angular,
        'enter_manual' => $enterManual,
        'via' => 'agent',
        'warning' => $result['warning'] ?? null,
        'hold_controller' => (bool) ($result['hold_controller'] ?? false),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => AGENT_REQUIRED_ERROR,
        'detail' => $e->getMessage(),
        'via' => 'none',
    ], 503);
}
