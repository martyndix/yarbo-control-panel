<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboMqttAgentClient;

const MAX_LINEAR = 0.5;
const MAX_ANGULAR = 0.8;
const AGENT_REQUIRED_ERROR = 'MQTT agent is not running. Start the panel with ./scripts/dev.sh (or run .venv/bin/python scripts/mqtt_agent.py). Connect–disconnect drive is unreliable.';

/**
 * Where a direction spent its time between the click and this page.
 * Click, send and fetch times come from the browser's clock; the proxy and
 * PHP times from this machine's.
 *
 * @param array<string, mixed> $input
 */
function drive_log_timing(array $input, string $why): void
{
    $ms = static fn ($value): ?float => is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    $click = $ms($input['ct'] ?? null);
    $send = $ms($input['st'] ?? null);
    $fetch = $ms($input['ft'] ?? null) ?? $send;
    $proxyHeader = $_SERVER['HTTP_X_PANEL_PROXY_AT'] ?? null;
    $proxy = is_numeric($proxyHeader) ? (float) $proxyHeader * 1000 : null;
    $php = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) * 1000;
    $span = static fn (?float $from, ?float $to): string => $from === null || $to === null
        ? '-'
        : (string) (int) round($to - $from) . 'ms';
    $parts = [
        'click→send ' . $span($click, $send),
        'queued ' . $span($send, $fetch),
        'send→proxy ' . $span($fetch, $proxy),
        'proxy→php ' . $span($proxy, $php),
        'php→now ' . $span($php, microtime(true) * 1000),
    ];
    $line = sprintf("[%s] Drive timing why=%s: %s\n", gmdate('H:i:s'), $why !== '' ? $why : '-', implode(', ', $parts));
    @file_put_contents(dirname(__DIR__, 2) . '/data/mqtt-agent.log', $line, FILE_APPEND | LOCK_EX);
}

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
$why = substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) ($input['why'] ?? '')) ?: '', 0, 40);
$pointer = substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) ($input['pointer'] ?? '')) ?: '', 0, 16);

drive_log_timing($input, $why);

$linear = max(-MAX_LINEAR, min(MAX_LINEAR, $linear));
$angular = max(-MAX_ANGULAR, min(MAX_ANGULAR, $angular));

try {
    $agent = YarboMqttAgentClient::fromEnv();
    $result = $agent->drive($linear, $angular, $enterManual, $why, $pointer);
    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Drive command failed'),
            'needs_controller' => (bool) ($result['needs_controller'] ?? false),
            'hold_controller' => (bool) ($result['hold_controller'] ?? false),
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
