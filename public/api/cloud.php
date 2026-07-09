<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;

$projectRoot = dirname(__DIR__, 2);
$dataDir = $projectRoot . '/data';
$cloudSettings = new YarboCloudSettings($dataDir);
$cloud = new YarboCloud($cloudSettings, $projectRoot);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'status' => $cloud->status(),
        'settings' => $cloudSettings->publicView(),
    ]);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

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

$action = (string) ($input['action'] ?? 'test');
if ($action === 'test') {
    $email = trim((string) ($input['cloud_email'] ?? ''));
    $password = (string) ($input['cloud_password'] ?? '');
    if ($email !== '' && $password !== '') {
        $cloudSettings->save([
            'cloud_enabled' => (bool) ($input['cloud_enabled'] ?? true),
            'cloud_email' => $email,
            'cloud_password' => $password,
            'data_source' => (string) ($input['data_source'] ?? YarboCloudSettings::DATA_SOURCE_AUTO),
        ]);
    }

    $status = $cloud->status();
    $sdkReady = (bool) ($status['sdk_installed'] ?? false);
    $configured = (bool) ($status['configured'] ?? false);

    if (!$sdkReady) {
        json_response([
            'ok' => false,
            'status' => $status,
            'message' => 'Python SDK not installed. Run ./scripts/install.sh (or sudo ./scripts/install.sh --deps on a fresh Pi).',
        ]);
    }

    if (!$configured) {
        json_response([
            'ok' => false,
            'status' => $status,
            'message' => 'Enter Yarbo email and password, then test again (password is required on first save).',
        ]);
    }

    $login = $cloud->testLogin();
    if (!($login['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'status' => $status,
            'message' => (string) ($login['error'] ?? 'Cloud login failed'),
            'login' => $login,
        ]);
    }

    $deviceCount = (int) ($login['login']['device_count'] ?? 0);
    json_response([
        'ok' => true,
        'status' => $status,
        'login' => $login,
        'message' => $deviceCount > 0
            ? "Cloud login successful ({$deviceCount} robot" . ($deviceCount === 1 ? '' : 's') . ' in account).'
            : 'Cloud login successful.',
    ]);
}

json_response(['ok' => false, 'error' => 'Unknown action. Valid: test'], 400);
