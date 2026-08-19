#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Persistent MQTT agent for Yarbo — keeps one live MQTT connection.
 *
 * Fallback when python-yarbo is missing. Supports the same ops as mqtt_agent.py:
 * ping, telemetry, controller, lights, buzzer, drive, publish, publish_variants.
 *
 * Usage: php scripts/mqtt_agent.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCodec;
use Yarbo\YarboMqtt;

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(1);
}

/** @var array{broker_host: string, broker_port: int, serial: string} $config */
$config = require $configPath;

$host = (string) ($config['broker_host'] ?? '');
$port = (int) ($config['broker_port'] ?? 1883);
$serial = (string) ($config['serial'] ?? '');
$agentPort = (int) (getenv('YARBO_MQTT_AGENT_PORT') ?: 8765);

if ($host === '' || $serial === '') {
    fwrite(STDERR, "broker_host and serial must be set in config.php\n");
    exit(1);
}

const LIGHTS_ON = [
    'led_head' => 255,
    'led_left_w' => 255,
    'led_right_w' => 255,
    'body_left_r' => 255,
    'body_right_r' => 255,
    'tail_left_r' => 255,
    'tail_right_r' => 255,
];
const LIGHTS_OFF = [
    'led_head' => 0,
    'led_left_w' => 0,
    'led_right_w' => 0,
    'body_left_r' => 0,
    'body_right_r' => 0,
    'tail_left_r' => 0,
    'tail_right_r' => 0,
];

/** @var MqttClient|null $mqtt */
$mqtt = null;
$loopStartedAt = microtime(true);
/** @var array<string, mixed>|null $lastRaw */
$lastRaw = null;
$state = [
    'controllerOk' => false,
    'controlHold' => false,
    'lightsOn' => false,
    'workUntil' => 0.0,
    'lastWake' => 0.0,
    'lastBatteryCells' => null,
    'batteryCellsAt' => 0.0,
];

function log_line(string $msg): void
{
    fwrite(STDERR, '[' . gmdate('H:i:s') . "] {$msg}\n");
}

function topic(string $serial, string $direction, string $leaf): string
{
    return sprintf('snowbot/%s/%s/%s', $serial, $direction, $leaf);
}

function pump(MqttClient $client, float $loopStartedAt, float $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $client->loopOnce($loopStartedAt, true, 20000);
    }
}

function battery_cells_have_temp(mixed $data): bool
{
    $skip = ['temp_err' => true, 'timestamp' => true, 'timeStamp' => true, 'state' => true, 'topic' => true, 'status' => true, 'msg' => true];
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($key) && isset($skip[$key])) {
                continue;
            }
            if (is_string($key) && str_contains(strtolower($key), 'temp') && is_numeric($value)) {
                $n = (float) $value;
                if ($n >= -40.0 && $n <= 120.0) {
                    return true;
                }
            }
            if (battery_cells_have_temp($value)) {
                return true;
            }
        }
        if (array_is_list($data)) {
            foreach ($data as $value) {
                if (is_numeric($value)) {
                    $n = (float) $value;
                    if ($n >= -40.0 && $n <= 120.0) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed>|null $lastRaw
 * @param array<string, mixed> $state
 */
function subscribe_telemetry(MqttClient $client, string $serial, ?array &$lastRaw, array &$state): void
{
    $handler = static function (string $topic, string $message) use (&$lastRaw, &$state): void {
        try {
            $decoded = YarboCodec::decode($message);
        } catch (Throwable) {
            return;
        }
        if (($decoded['topic'] ?? '') === 'battery_cell_temp_msg') {
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
            if (battery_cells_have_temp($data)) {
                $state['lastBatteryCells'] = $data;
                $state['batteryCellsAt'] = microtime(true);
            }
            return;
        }
        $telemetry = YarboMqtt::extractTelemetry($decoded);
        if ($telemetry !== null) {
            $lastRaw = $telemetry;
        }
    };
    $client->subscribe(topic($serial, 'device', 'data_feedback'), $handler, 0);
    $client->subscribe(topic($serial, 'device', 'DeviceMSG'), $handler, 0);
}

/**
 * @param array<string, mixed>|null $lastRaw
 */
function mqtt_connect(string $host, int $port, string $serial, ?array &$lastRaw, array &$state): MqttClient
{
    $client = new MqttClient($host, $port, 'yarbo-agent-' . bin2hex(random_bytes(4)));
    $settings = (new ConnectionSettings())
        ->setKeepAliveInterval(15)
        ->setConnectTimeout(5)
        ->setSocketTimeout(1)
        ->setResendTimeout(5);
    $client->connect($settings, true);
    subscribe_telemetry($client, $serial, $lastRaw, $state);

    return $client;
}

function mqtt_disconnect(?MqttClient &$client): void
{
    if ($client === null) {
        return;
    }
    try {
        if ($client->isConnected()) {
            $client->disconnect();
        }
    } catch (Throwable) {
        // ignore
    }
    $client = null;
}

/**
 * @param array<string, mixed>|null $lastRaw
 * @return array{ok: bool, error?: string}
 */
function ensure_connected(
    ?MqttClient &$mqtt,
    float &$loopStartedAt,
    string $host,
    int $port,
    string $serial,
    ?array &$lastRaw,
    array &$state,
): array {
    if ($mqtt !== null && $mqtt->isConnected()) {
        return ['ok' => true];
    }

    mqtt_disconnect($mqtt);
    try {
        $mqtt = mqtt_connect($host, $port, $serial, $lastRaw, $state);
        $loopStartedAt = microtime(true);
        log_line("MQTT connected to {$host}:{$port}");

        return ['ok' => true];
    } catch (Throwable $e) {
        $mqtt = null;

        return ['ok' => false, 'error' => 'MQTT connect failed: ' . $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, error?: string}
 */
function acquire_controller(MqttClient $client, float $loopStartedAt, string $serial, float $timeout = 4.0): array
{
    $ack = false;
    $client->subscribe(
        topic($serial, 'device', 'data_feedback'),
        static function (string $topic, string $message) use (&$ack): void {
            try {
                $decoded = YarboCodec::decode($message);
            } catch (Throwable) {
                return;
            }
            if (($decoded['topic'] ?? '') === 'get_controller' && (int) ($decoded['state'] ?? 1) === 0) {
                $ack = true;
            }
        },
        0
    );

    pump($client, $loopStartedAt, 0.25);
    $client->publish(topic($serial, 'app', 'get_controller'), YarboCodec::encode([]), 0);

    $deadline = microtime(true) + $timeout;
    while (!$ack && microtime(true) < $deadline) {
        $client->loopOnce($loopStartedAt, true, 20000);
    }

    if (!$ack) {
        return [
            'ok' => false,
            'error' => 'Robot did not grant controller role. Close the Yarbo mobile app and try again.',
        ];
    }

    pump($client, $loopStartedAt, 0.5);

    return ['ok' => true];
}

function publish(MqttClient $client, string $serial, string $cmd, array $payload): void
{
    $client->publish(topic($serial, 'app', $cmd), YarboCodec::encode($payload), 0);
}

function work_needs_startup(string $cmd): bool
{
    return in_array($cmd, ['start_plan', 'cmd_recharge', 'start_way_point', 'resume'], true);
}

function wake_for_work(MqttClient $client, float $loopStartedAt, string $serial): void
{
    publish($client, $serial, 'set_working_state', ['state' => 1, 'source' => 'smart_home']);
    pump($client, $loopStartedAt, 0.2);
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function state_flags(array $state): array
{
    return [
        'hold_controller' => ((bool) $state['controlHold']) || ((bool) $state['controllerOk']),
        'lights_on' => (bool) $state['lightsOn'],
        'controller_acquired' => (bool) $state['controllerOk'],
    ];
}

/**
 * @param array<string, mixed> $state
 */
function ok_resp(array $base, array $state): array
{
    return array_merge($base, state_flags($state));
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed>|null $lastRaw
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 */
function handle_request(
    ?MqttClient &$mqtt,
    float &$loopStartedAt,
    array &$state,
    ?array &$lastRaw,
    string $host,
    int $port,
    string $serial,
    array $req,
): array {
    $op = (string) ($req['op'] ?? '');

    if ($op === 'ping') {
        return ok_resp([
            'ok' => true,
            'controller' => (bool) $state['controllerOk'],
            'connected' => $mqtt !== null && $mqtt->isConnected(),
            'engine' => 'php',
            'telemetry' => true,
        ], $state);
    }

    $connected = ensure_connected($mqtt, $loopStartedAt, $host, $port, $serial, $lastRaw, $state);
    if (!($connected['ok'] ?? false)) {
        return $connected;
    }
    assert($mqtt instanceof MqttClient);

    try {
        if ($op === 'telemetry') {
            $timeout = (float) ($req['timeout'] ?? 4.0);
            if (!is_array($lastRaw)) {
                publish($mqtt, $serial, 'get_device_msg', []);
                $deadline = microtime(true) + $timeout;
                while (!is_array($lastRaw) && microtime(true) < $deadline) {
                    $mqtt->loopOnce($loopStartedAt, true, 20000);
                }
            }
            if (!is_array($lastRaw)) {
                return ['ok' => false, 'error' => 'telemetry timeout', 'transient' => true];
            }

            $now = microtime(true);
            $cells = is_array($state['lastBatteryCells'] ?? null) ? $state['lastBatteryCells'] : null;
            $cellsAge = $now - (float) ($state['batteryCellsAt'] ?? 0);
            if ($cells === null || $cellsAge > 30.0) {
                publish($mqtt, $serial, 'battery_cell_temp_msg', []);
                $deadline = microtime(true) + 1.2;
                while (microtime(true) < $deadline) {
                    $mqtt->loopOnce($loopStartedAt, true, 20000);
                    $fresh = $state['lastBatteryCells'] ?? null;
                    if (is_array($fresh) && (float) ($state['batteryCellsAt'] ?? 0) > $now) {
                        $cells = $fresh;
                        break;
                    }
                }
                if (battery_cells_have_temp($state['lastBatteryCells'] ?? null)) {
                    $cells = $state['lastBatteryCells'];
                }
            }

            return ok_resp([
                'ok' => true,
                'op' => 'telemetry',
                'raw' => $lastRaw,
                'battery_cells' => battery_cells_have_temp($cells) ? $cells : null,
            ], $state);
        }

        $needsController = in_array($op, ['controller', 'lights', 'buzzer', 'drive', 'publish', 'publish_variants', 'start_plan', 'return_to_dock'], true);
        if (!$needsController) {
            return ['ok' => false, 'error' => 'Unknown op. Valid: ping, telemetry, controller, lights, buzzer, drive, publish, publish_variants, start_plan, return_to_dock'];
        }

        if ($op === 'controller' && !((bool) ($req['on'] ?? false))) {
            if ($state['lightsOn']) {
                publish($mqtt, $serial, 'light_ctrl', LIGHTS_OFF);
                $state['lightsOn'] = false;
            }
            if ($state['controlHold'] || $state['controllerOk']) {
                publish($mqtt, $serial, 'set_working_state', ['state' => 0]);
                pump($mqtt, $loopStartedAt, 0.15);
            }
            $state['controllerOk'] = false;
            $state['controlHold'] = false;
            $state['workUntil'] = 0.0;
            log_line('Controller HOLD off');

            return ok_resp(['ok' => true, 'op' => 'controller', 'on' => false], $state);
        }

        if (!$state['controllerOk']) {
            $got = acquire_controller($mqtt, $loopStartedAt, $serial, 4.0);
            if (!($got['ok'] ?? false)) {
                return $got;
            }
            $state['controllerOk'] = true;
            subscribe_telemetry($mqtt, $serial, $lastRaw, $state);
            log_line('Controller acquired');
        }

        $session = (string) ($req['session'] ?? 'control');
        if ($session === 'work') {
            wake_for_work($mqtt, $loopStartedAt, $serial);
            $state['lastWake'] = microtime(true);
        }

        if ($op === 'controller') {
            wake_for_work($mqtt, $loopStartedAt, $serial);
            $state['controlHold'] = true;
            $state['lastWake'] = microtime(true);
            log_line('Controller HOLD on');

            return ok_resp(['ok' => true, 'op' => 'controller', 'on' => true], $state);
        }

        if ($op === 'lights') {
            $on = (bool) ($req['on'] ?? false);
            wake_for_work($mqtt, $loopStartedAt, $serial);
            if ($on) {
                publish($mqtt, $serial, 'light_ctrl', LIGHTS_ON);
                $state['lightsOn'] = true;
                $state['controlHold'] = true;
                log_line('Lights ON');
            } else {
                publish($mqtt, $serial, 'light_ctrl', LIGHTS_OFF);
                $state['lightsOn'] = false;
                log_line('Lights OFF');
            }
            $state['lastWake'] = microtime(true);
            pump($mqtt, $loopStartedAt, 0.15);

            return ok_resp(['ok' => true, 'op' => 'lights', 'on' => $on], $state);
        }

        if ($op === 'buzzer') {
            wake_for_work($mqtt, $loopStartedAt, $serial);
            publish($mqtt, $serial, 'cmd_buzzer', ['state' => 1, 'timeStamp' => (int) round(microtime(true) * 1000)]);
            pump($mqtt, $loopStartedAt, 2.0);
            publish($mqtt, $serial, 'cmd_buzzer', ['state' => 0, 'timeStamp' => (int) round(microtime(true) * 1000)]);
            publish($mqtt, $serial, 'song_cmd', ['song_name' => 'find yarbo']);
            pump($mqtt, $loopStartedAt, 0.3);
            $state['lastWake'] = microtime(true);

            return ok_resp(['ok' => true, 'op' => 'buzzer', 'cmd' => 'cmd_buzzer'], $state);
        }

        if ($op === 'drive') {
            $linear = (float) ($req['linear'] ?? 0);
            $angular = (float) ($req['angular'] ?? 0);
            if (abs($linear) > 1e-6 || abs($angular) > 1e-6) {
                wake_for_work($mqtt, $loopStartedAt, $serial);
                publish($mqtt, $serial, 'emergency_unlock', []);
                pump($mqtt, $loopStartedAt, 0.1);
            }
            publish($mqtt, $serial, 'cmd_vel', ['vel' => $linear, 'rev' => $angular]);
            pump($mqtt, $loopStartedAt, 0.12);
            $state['lastWake'] = microtime(true);

            return ok_resp(['ok' => true, 'op' => 'drive'], $state);
        }

        if ($op === 'start_plan') {
            $planId = $req['plan_id'] ?? $req['planId'] ?? null;
            if ($planId === null || $planId === '') {
                return ['ok' => false, 'error' => 'plan_id is required'];
            }
            $percent = max(0, min(100, (int) ($req['percent'] ?? 0)));
            $id = is_numeric($planId) ? (int) $planId : (string) $planId;
            publish($mqtt, $serial, 'cmd_vel', ['vel' => 0.0, 'rev' => 0.0]);
            if (!$state['controlHold']) {
                wake_for_work($mqtt, $loopStartedAt, $serial);
            }
            $state['workUntil'] = 0.0;
            $state['lastWake'] = microtime(true);
            publish($mqtt, $serial, 'start_plan', ['planId' => $id, 'id' => $id, 'percent' => $percent]);
            pump($mqtt, $loopStartedAt, 0.5);
            log_line("start_plan planId={$id} percent={$percent}");

            return ok_resp([
                'ok' => true,
                'op' => 'start_plan',
                'cmd' => 'start_plan',
                'plan_id' => $id,
                'percent' => $percent,
                'via' => 'official_payload',
            ], $state);
        }

        if ($op === 'return_to_dock') {
            publish($mqtt, $serial, 'cmd_vel', ['vel' => 0.0, 'rev' => 0.0]);
            if (!$state['controlHold']) {
                wake_for_work($mqtt, $loopStartedAt, $serial);
            }
            $state['workUntil'] = 0.0;
            $state['lastWake'] = microtime(true);
            publish($mqtt, $serial, 'wireless_charging_cmd', ['cmd' => 0]);
            pump($mqtt, $loopStartedAt, 0.2);
            publish($mqtt, $serial, 'cmd_recharge', ['cmd' => 2]);
            pump($mqtt, $loopStartedAt, 0.5);
            log_line('return_to_dock wireless_charging_cmd 0 + cmd_recharge cmd=2');

            return ok_resp([
                'ok' => true,
                'op' => 'return_to_dock',
                'cmd' => 'cmd_recharge',
                'via' => 'official_payload',
            ], $state);
        }

        if ($op === 'publish') {
            $cmd = (string) ($req['cmd'] ?? '');
            if ($cmd === '') {
                return ['ok' => false, 'error' => 'cmd required'];
            }
            $payload = is_array($req['payload'] ?? null) ? $req['payload'] : [];
            publish($mqtt, $serial, $cmd, $payload);
            pump($mqtt, $loopStartedAt, 0.35);
            if ($session === 'work' && work_needs_startup($cmd)) {
                $state['workUntil'] = microtime(true) + 25.0;
                $state['lastWake'] = microtime(true);
            }

            return ok_resp(['ok' => true, 'op' => 'publish', 'cmd' => $cmd], $state);
        }

        if ($op === 'publish_variants') {
            $variants = $req['variants'] ?? null;
            if (!is_array($variants) || $variants === []) {
                return ['ok' => false, 'error' => 'variants required'];
            }
            $lastCmd = '';
            foreach ($variants as $variant) {
                if (!is_array($variant) || !isset($variant['cmd'])) {
                    continue;
                }
                $cmd = (string) $variant['cmd'];
                $payload = is_array($variant['payload'] ?? null) ? $variant['payload'] : [];
                publish($mqtt, $serial, $cmd, $payload);
                $lastCmd = $cmd;
            }
            pump($mqtt, $loopStartedAt, 0.35);
            if ($session === 'work' && work_needs_startup($lastCmd)) {
                $state['workUntil'] = microtime(true) + 25.0;
                $state['lastWake'] = microtime(true);
                wake_for_work($mqtt, $loopStartedAt, $serial);
            }

            return ok_resp(['ok' => true, 'op' => 'publish_variants', 'cmd' => $lastCmd], $state);
        }

        return ['ok' => false, 'error' => 'Unknown op. Valid: ping, telemetry, controller, lights, buzzer, drive, publish, publish_variants, start_plan, return_to_dock'];
    } catch (Throwable $e) {
        log_line('Command error: ' . $e->getMessage());
        $state['controllerOk'] = false;
        mqtt_disconnect($mqtt);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

$server = @stream_socket_server("tcp://127.0.0.1:{$agentPort}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Could not bind 127.0.0.1:{$agentPort}: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);
log_line("Agent listening on 127.0.0.1:{$agentPort} (MQTT connecting {$host}:{$port} SN={$serial})");

/** @var array<int, resource> $clients */
$clients = [];
/** @var array<int, string> $buffers */
$buffers = [];

while (true) {
    if ($mqtt !== null && $mqtt->isConnected()) {
        try {
            $mqtt->loopOnce($loopStartedAt, true, 20000);
        } catch (Throwable $e) {
            log_line('MQTT loop error (will reconnect): ' . $e->getMessage());
            $state['controllerOk'] = false;
            mqtt_disconnect($mqtt);
        }
    } else {
        $result = ensure_connected($mqtt, $loopStartedAt, $host, $port, $serial, $lastRaw, $state);
        if (!($result['ok'] ?? false)) {
            usleep(400000);
        }
    }

    $now = microtime(true);
    $wantWake = $mqtt !== null && $mqtt->isConnected()
        && ($state['lightsOn'] || $state['controlHold'] || $now < (float) $state['workUntil']);
    if ($wantWake) {
        $gap = ($now < (float) $state['workUntil'] && !$state['lightsOn'] && !$state['controlHold']) ? 1.5 : 15.0;
        if ($now - (float) $state['lastWake'] >= $gap) {
            try {
                wake_for_work($mqtt, $loopStartedAt, $serial);
                if ($state['lightsOn']) {
                    publish($mqtt, $serial, 'light_ctrl', LIGHTS_ON);
                }
                $state['lastWake'] = $now;
                log_line('Keepalive wake' . ($state['lightsOn'] ? ' + lights' : ''));
            } catch (Throwable $e) {
                log_line('Keepalive failed: ' . $e->getMessage());
            }
        }
    }

    $read = array_values(array_filter(array_merge([$server], $clients), static fn ($r) => is_resource($r)));
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 30000) > 0) {
        if (in_array($server, $read, true)) {
            $conn = @stream_socket_accept($server, 0);
            if (is_resource($conn)) {
                stream_set_blocking($conn, false);
                $id = (int) $conn;
                $clients[$id] = $conn;
                $buffers[$id] = '';
            }
        }

        foreach ($clients as $id => $conn) {
            if (!in_array($conn, $read, true)) {
                continue;
            }
            $chunk = @fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                if (is_resource($conn)) {
                    fclose($conn);
                }
                unset($clients[$id], $buffers[$id]);
                continue;
            }
            $buffers[$id] .= $chunk;
            while (($pos = strpos($buffers[$id], "\n")) !== false) {
                $line = trim(substr($buffers[$id], 0, $pos));
                $buffers[$id] = substr($buffers[$id], $pos + 1);
                if ($line === '') {
                    continue;
                }
                $req = json_decode($line, true);
                $idOut = is_array($req) ? ($req['id'] ?? null) : null;
                try {
                    if (!is_array($req)) {
                        $resp = ['ok' => false, 'error' => 'Invalid JSON'];
                    } else {
                        $resp = handle_request(
                            $mqtt,
                            $loopStartedAt,
                            $state,
                            $lastRaw,
                            $host,
                            $port,
                            $serial,
                            $req
                        );
                    }
                } catch (Throwable $e) {
                    $state['controllerOk'] = false;
                    mqtt_disconnect($mqtt);
                    $resp = ['ok' => false, 'error' => $e->getMessage()];
                }
                if ($idOut !== null) {
                    $resp['id'] = $idOut;
                }
                if (is_resource($conn)) {
                    @fwrite($conn, json_encode($resp, JSON_UNESCAPED_SLASHES) . "\n");
                }
            }
        }
    }

    foreach ($clients as $id => $conn) {
        if (!is_resource($conn) || feof($conn)) {
            if (is_resource($conn)) {
                fclose($conn);
            }
            unset($clients[$id], $buffers[$id]);
        }
    }
}
