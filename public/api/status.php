<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboErrors;
use Yarbo\YarboMqtt;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboTelemetry;
use Yarbo\YarboWifi;

$host = (string) ($config['broker_host'] ?? '');
$port = (int) ($config['broker_port'] ?? 1883);

// Prefer persistent agent so status does not open a competing MQTT session.
$agent = YarboMqttAgentClient::fromEnv();
$agentUp = $agent->isAvailable();
if (!$agentUp) {
    try {
        $agent = YarboMqttAgentClient::requireRunning();
        $agentUp = true;
    } catch (Throwable) {
        $agentUp = false;
    }
}

if ($agentUp) {
    try {
        $result = $agent->telemetry(4.0, true);
        if (($result['ok'] ?? false) && is_array($result['raw'] ?? null)) {
            $wifiEnvelope = is_array($result['wifi'] ?? null)
                ? ['data' => $result['wifi'], 'topic' => 'get_connect_wifi_name']
                : null;

            $parsed = YarboTelemetry::parse($result['raw']);
            // Agent desired lights/controller state is authoritative (firmware telemetry is unreliable).
            if (array_key_exists('lights_on', $result)) {
                $parsed['lights_on'] = (bool) $result['lights_on'];
            }
            if (array_key_exists('hold_controller', $result)) {
                $parsed['hold_controller'] = (bool) $result['hold_controller'];
            }
            if (array_key_exists('controller_acquired', $result)) {
                $parsed['controller_acquired'] = (bool) $result['controller_acquired'];
            }

            json_response(array_merge(
                ['ok' => true, 'via' => 'agent', 'cached' => (bool) ($result['cached'] ?? false)],
                $parsed,
                ['wifi' => YarboWifi::parse($wifiEnvelope)],
            ));
        }

        // Agent is running — never open a second MQTT client (that fights the command session).
        json_response([
            'ok' => false,
            'via' => 'agent',
            'stage' => 'telemetry',
            'transient' => (bool) ($result['transient'] ?? true),
            'error' => YarboErrors::friendly((string) ($result['error'] ?? 'telemetry timeout')),
        ], 504);
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'via' => 'agent',
            'stage' => 'telemetry',
            'transient' => true,
            'error' => friendly_error($e),
        ], 504);
    }
}

// Fail fast on unreachable broker so the single-threaded php -S server is not blocked for 30s+.
$tcp = YarboMqtt::probeTcp($host, $port, 2.0);
if (!$tcp['ok']) {
    $detail = strtolower((string) ($tcp['error'] ?? ''));
    $errno = (int) ($tcp['errno'] ?? 0);
    if (str_contains($detail, 'connection refused') || $errno === 111) {
        $message = YarboErrors::MSG_REFUSED;
    } elseif (str_contains($detail, 'no route to host') || $errno === 113) {
        $message = YarboErrors::MSG_NO_ROUTE;
    } elseif (str_contains($detail, 'network is unreachable') || $errno === 101) {
        $message = YarboErrors::MSG_UNREACHABLE;
    } elseif (str_contains($detail, 'timed out') || $errno === 60 || $errno === 110) {
        $message = YarboErrors::MSG_TIMEOUT;
    } else {
        $message = YarboErrors::friendly((string) ($tcp['error'] ?? 'TCP connection failed'));
    }

    json_response([
        'ok' => false,
        'stage' => 'tcp',
        'error' => $message,
    ], 500);
}

try {
    $client = yarbo_client($config);
    $client->connect();
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'stage' => 'connect',
        'error' => friendly_error($e),
    ], 500);
}

try {
    $raw = $client->requestTelemetry(4);
    $wifiResponse = null;
    if ($raw !== null) {
        // WiFi is nice-to-have; keep it short so a slow reply does not stall the panel.
        $wifiResponse = $client->requestDataFeedback('get_connect_wifi_name', [], 1.5, false);
    }
    $client->disconnect();

    if ($raw === null) {
        json_response([
            'ok' => false,
            'stage' => 'telemetry',
            'error' => friendly_message('telemetry_timeout: No telemetry received within timeout. Check serial number.'),
        ], 504);
    }

    json_response(array_merge(
        ['ok' => true, 'via' => 'direct'],
        YarboTelemetry::parse($raw),
        ['wifi' => YarboWifi::parse($wifiResponse)],
    ));
} catch (Throwable $e) {
    $client->disconnect();
    json_response([
        'ok' => false,
        'stage' => 'telemetry',
        'error' => friendly_error($e),
    ], 500);
}
