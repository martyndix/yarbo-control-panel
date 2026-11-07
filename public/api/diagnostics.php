<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboErrors;
use Yarbo\YarboMqttAgentClient;

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

$serial = trim((string) ($input['serial'] ?? $config['serial'] ?? ''));
$cloudView = $cloudSettings->publicView();
$email = trim((string) ($input['cloud_email'] ?? $cloudView['cloud_email'] ?? ''));

if ($serial === '') {
    json_response(['ok' => false, 'error' => 'serial is required'], 400);
}
if ($email === '' || empty($cloudView['cloud_password_set'])) {
    // Allow password in this request for a one-shot test before Save.
    $password = (string) ($input['cloud_password'] ?? '');
    if ($email === '' || $password === '') {
        json_response([
            'ok' => false,
            'error' => 'Yarbo account email and password are required for cloud MQTT',
        ], 400);
    }
}

$steps = [];

$bridge = $cloud->status();
$steps['sdk'] = [
    'ok' => !empty($bridge['sdk_installed']),
    'label' => 'yarbo-data-sdk',
    'message' => !empty($bridge['sdk_installed'])
        ? 'Official Yarbo SDK is installed.'
        : (string) ($bridge['sdk_path_hint'] ?? 'Install yarbo-data-sdk via ./scripts/install.sh'),
];

$login = $cloud->testLogin();
$steps['login'] = [
    'ok' => (bool) ($login['ok'] ?? false),
    'label' => 'Cloud login',
    'message' => (string) ($login['ok'] ?? false
        ? ('Signed in' . (isset($login['login']['device_count']) ? (' · ' . (int) $login['login']['device_count'] . ' device(s)') : ''))
        : YarboErrors::friendly((string) ($login['error'] ?? 'Cloud login failed'))),
];

$agentOk = false;
$agentMessage = 'Skipped because cloud login failed.';
$connected = false;
if ($steps['login']['ok']) {
    try {
        $agent = YarboMqttAgentClient::fromEnv();
        $agent->restart(true);
        $ping = $agent->ping(2.0);
        $agentOk = (bool) ($ping['ok'] ?? false);
        $connected = !empty($ping['connected']);
        if ($agentOk && $connected) {
            $agentMessage = 'Cloud MQTT agent connected.';
        } elseif ($agentOk) {
            $agentMessage = 'Agent is up but cloud MQTT is not connected yet — wait a few seconds and retry.';
        } else {
            $agentMessage = YarboErrors::friendly((string) ($ping['error'] ?? 'Cloud agent did not respond'));
        }
    } catch (Throwable $e) {
        $agentMessage = YarboErrors::friendly($e->getMessage());
    }
}
$steps['agent'] = [
    'ok' => $agentOk && $connected,
    'label' => 'Cloud MQTT agent',
    'message' => $agentMessage,
];

$telemetryOk = false;
$telemetryMessage = 'Skipped because cloud MQTT is not connected.';
if ($steps['agent']['ok']) {
    try {
        $agent = YarboMqttAgentClient::fromEnv();
        $tel = $agent->telemetry(8.0, true, false);
        if (($tel['ok'] ?? false) === true && is_array($tel['raw'] ?? null)) {
            $telemetryOk = true;
            $telemetryMessage = 'Robot replied with DeviceMSG over cloud MQTT.';
        } else {
            $telemetryMessage = YarboErrors::friendly((string) ($tel['error'] ?? YarboErrors::MSG_TELEMETRY_TIMEOUT));
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

$ok = $steps['sdk']['ok'] && $steps['login']['ok'] && $steps['agent']['ok'];
json_response([
    'ok' => $ok,
    'message' => $ok
        ? 'Cloud MQTT connection successful.'
        : 'Cloud MQTT connection failed — see steps.',
    'steps' => $steps,
    'transport' => 'cloud',
]);
