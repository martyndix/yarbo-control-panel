<?php

declare(strict_types=1);

/**
 * Persistent MQTT agent for Yarbo — keeps one live connection and controller role.
 *
 * Usage: php scripts/mqtt_agent.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCodec;

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

/** @var MqttClient|null $mqtt */
$mqtt = null;
$loopStartedAt = microtime(true);
$controllerOk = false;

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

/**
 * @return MqttClient
 */
function mqtt_connect(string $host, int $port, string $serial): MqttClient
{
    $client = new MqttClient($host, $port, 'yarbo-agent-' . bin2hex(random_bytes(4)));
    $settings = (new ConnectionSettings())
        ->setKeepAliveInterval(15)
        ->setConnectTimeout(5)
        ->setSocketTimeout(1)
        ->setResendTimeout(5);
    $client->connect($settings, true);
    $client->subscribe(topic($serial, 'device', 'data_feedback'), static function (): void {
    }, 0);

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
 * @return array{ok: bool, error?: string}
 */
function ensure_connected(
    ?MqttClient &$mqtt,
    float &$loopStartedAt,
    string $host,
    int $port,
    string $serial,
): array {
    if ($mqtt !== null && $mqtt->isConnected()) {
        return ['ok' => true];
    }

    mqtt_disconnect($mqtt);
    try {
        $mqtt = mqtt_connect($host, $port, $serial);
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

    // Match python-yarbo _ensure_controller settle time before action commands.
    pump($client, $loopStartedAt, 0.5);

    return ['ok' => true];
}

function publish(MqttClient $client, string $serial, string $cmd, array $payload): void
{
    $client->publish(topic($serial, 'app', $cmd), YarboCodec::encode($payload), 0);
}

/**
 * @param array<string, mixed> $req
 * @return array<string, mixed>
 */
function handle_request(
    ?MqttClient &$mqtt,
    float &$loopStartedAt,
    bool &$controllerOk,
    string $host,
    int $port,
    string $serial,
    array $req,
): array {
    $op = (string) ($req['op'] ?? '');

    $connected = ensure_connected($mqtt, $loopStartedAt, $host, $port, $serial);
    if (!($connected['ok'] ?? false)) {
        return $connected;
    }
    assert($mqtt instanceof MqttClient);

    if ($op === 'ping') {
        return [
            'ok' => true,
            'controller' => $controllerOk,
            'connected' => $mqtt->isConnected(),
        ];
    }

    try {
        $got = acquire_controller($mqtt, $loopStartedAt, $serial, 4.0);
        if (!($got['ok'] ?? false)) {
            $controllerOk = false;

            return $got;
        }
        $controllerOk = true;

        if ($op === 'drive') {
            $enterManual = (bool) ($req['enter_manual'] ?? false);
            $linear = (float) ($req['linear'] ?? 0);
            $angular = (float) ($req['angular'] ?? 0);
            if ($enterManual) {
                publish($mqtt, $serial, 'set_working_state', ['state' => 'manual']);
                pump($mqtt, $loopStartedAt, 0.15);
            }
            publish($mqtt, $serial, 'cmd_vel', ['vel' => $linear, 'rev' => $angular]);
            pump($mqtt, $loopStartedAt, 0.12);

            return ['ok' => true, 'op' => 'drive'];
        }

        if ($op === 'publish') {
            $cmd = (string) ($req['cmd'] ?? '');
            if ($cmd === '') {
                return ['ok' => false, 'error' => 'cmd required'];
            }
            $payload = is_array($req['payload'] ?? null) ? $req['payload'] : [];
            publish($mqtt, $serial, $cmd, $payload);
            pump($mqtt, $loopStartedAt, 0.4);

            return ['ok' => true, 'op' => 'publish', 'cmd' => $cmd];
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
            pump($mqtt, $loopStartedAt, 0.4);

            return ['ok' => true, 'op' => 'publish_variants', 'cmd' => $lastCmd];
        }

        return ['ok' => false, 'error' => 'Unknown op. Valid: ping, drive, publish, publish_variants'];
    } catch (Throwable $e) {
        log_line('Command error: ' . $e->getMessage());
        $controllerOk = false;
        mqtt_disconnect($mqtt);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

log_line("Connecting MQTT {$host}:{$port} SN={$serial}");
$connected = ensure_connected($mqtt, $loopStartedAt, $host, $port, $serial);
if (!($connected['ok'] ?? false)) {
    fwrite(STDERR, ($connected['error'] ?? 'connect failed') . "\n");
    exit(1);
}

$got = acquire_controller($mqtt, $loopStartedAt, $serial, 5.0);
if ($got['ok'] ?? false) {
    $controllerOk = true;
    log_line('Controller acquired');
} else {
    log_line('WARNING: ' . ($got['error'] ?? 'get_controller failed') . ' — will retry on first command');
}

$server = @stream_socket_server("tcp://127.0.0.1:{$agentPort}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Could not bind 127.0.0.1:{$agentPort}: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);
log_line("Agent listening on 127.0.0.1:{$agentPort}");

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
            $controllerOk = false;
            mqtt_disconnect($mqtt);
        }
    } else {
        $result = ensure_connected($mqtt, $loopStartedAt, $host, $port, $serial);
        if ($result['ok'] ?? false) {
            $controllerOk = false;
        } else {
            usleep(500000);
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
                        $resp = handle_request($mqtt, $loopStartedAt, $controllerOk, $host, $port, $serial, $req);
                    }
                } catch (Throwable $e) {
                    $controllerOk = false;
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
