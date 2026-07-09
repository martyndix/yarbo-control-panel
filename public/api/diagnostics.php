<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboErrors;
use Yarbo\YarboMqtt;

$projectRoot = dirname(__DIR__, 2);
$dataDir = $projectRoot . '/data';
$cloudSettings = new YarboCloudSettings($dataDir);
$cloud = new YarboCloud($cloudSettings, $projectRoot);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];

if ($method === 'POST') {
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
} elseif ($method !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$host = trim((string) ($input['broker_host'] ?? $config['broker_host'] ?? ''));
$port = (int) ($input['broker_port'] ?? $config['broker_port'] ?? 1883);
$serial = trim((string) ($input['serial'] ?? $config['serial'] ?? ''));

if ($host === '') {
    json_response(['ok' => false, 'error' => 'broker_host is required'], 400);
}
if ($serial === '') {
    json_response(['ok' => false, 'error' => 'serial is required'], 400);
}

$steps = [];

$tcp = YarboMqtt::probeTcp($host, $port, 3.0);
$tcpMessage = "Port {$port} is reachable at {$host}.";
if (!$tcp['ok']) {
    $detail = strtolower((string) ($tcp['error'] ?? ''));
    $errno = (int) ($tcp['errno'] ?? 0);
    if (str_contains($detail, 'connection refused') || $errno === 111) {
        $tcpMessage = YarboErrors::MSG_REFUSED;
    } elseif (str_contains($detail, 'no route to host') || $errno === 113) {
        $tcpMessage = YarboErrors::MSG_NO_ROUTE;
    } elseif (str_contains($detail, 'network is unreachable') || $errno === 101) {
        $tcpMessage = YarboErrors::MSG_UNREACHABLE;
    } elseif (str_contains($detail, 'timed out') || $errno === 60 || $errno === 110) {
        $tcpMessage = 'Cannot reach the Yarbo robot at the configured IP address (connection timed out). '
            . 'Check the broker IP in Settings, confirm the robot is powered on, and that this device is on the same home network.';
    } else {
        $tcpMessage = YarboErrors::friendly((string) ($tcp['error'] ?? 'TCP connection failed'));
    }
}

$steps['tcp'] = [
    'ok' => $tcp['ok'],
    'label' => "TCP {$host}:{$port}",
    'message' => $tcpMessage,
    'detail' => $tcp['error'] ?? null,
    'errno' => $tcp['errno'] ?? null,
];

$mqttConnectOk = false;
$mqttConnectMessage = 'Skipped because TCP probe failed.';
if ($tcp['ok']) {
    try {
        $client = new YarboMqtt($host, $port, $serial);
        $client->connect();
        $mqttConnectOk = true;
        $mqttConnectMessage = 'MQTT broker accepted the connection.';
        $client->disconnect();
    } catch (Throwable $e) {
        $mqttConnectMessage = YarboErrors::friendly($e->getMessage());
    }
}

$steps['mqtt_connect'] = [
    'ok' => $mqttConnectOk,
    'label' => 'MQTT connect',
    'message' => $mqttConnectMessage,
];

$telemetryOk = false;
$telemetryMessage = 'Skipped because MQTT connect failed.';
if ($mqttConnectOk) {
    try {
        $client = new YarboMqtt($host, $port, $serial);
        $client->connect();
        $raw = $client->requestTelemetry(8);
        $client->disconnect();
        if ($raw !== null) {
            $telemetryOk = true;
            $telemetryMessage = 'Robot replied to get_device_msg.';
        } else {
            $telemetryMessage = YarboErrors::MSG_TELEMETRY_TIMEOUT;
        }
    } catch (Throwable $e) {
        $telemetryMessage = YarboErrors::friendly($e->getMessage());
    }
}

$steps['telemetry'] = [
    'ok' => $telemetryOk,
    'label' => 'Robot telemetry',
    'message' => $telemetryMessage,
];

$cloudStatus = $cloud->status();
$cloudSdkOk = (bool) ($cloudStatus['sdk_installed'] ?? false);
$cloudMessage = $cloudSdkOk
    ? 'Python SDK is installed.'
    : (string) ($cloudStatus['sdk_path_hint'] ?? 'Python SDK not installed.');

if ($cloudSdkOk && ($cloudStatus['configured'] ?? false)) {
    $login = $cloud->testLogin();
    if ($login['ok'] ?? false) {
        $deviceCount = (int) ($login['login']['device_count'] ?? 0);
        $cloudMessage = $deviceCount > 0
            ? "Cloud login OK ({$deviceCount} robot" . ($deviceCount === 1 ? '' : 's') . ' in account).'
            : 'Cloud login OK.';
    } else {
        $cloudMessage = (string) ($login['error'] ?? 'Cloud login failed');
    }
    $cloudSdkOk = (bool) ($login['ok'] ?? false);
}

$steps['cloud_sdk'] = [
    'ok' => $cloudSdkOk,
    'label' => 'Cloud SDK',
    'message' => $cloudMessage,
    'status' => $cloudStatus,
];

$allOk = $steps['tcp']['ok'] && $steps['mqtt_connect']['ok'] && $steps['telemetry']['ok'];

json_response([
    'ok' => $allOk,
    'broker_host' => $host,
    'broker_port' => $port,
    'serial' => $serial,
    'steps' => $steps,
    'message' => $allOk
        ? 'Local MQTT connection successful.'
        : ($steps['telemetry']['ok'] === false && $steps['mqtt_connect']['ok']
            ? YarboErrors::MSG_TELEMETRY_TIMEOUT
            : ($steps['tcp']['ok'] === false
                ? $steps['tcp']['message']
                : $steps['mqtt_connect']['message'])),
]);
