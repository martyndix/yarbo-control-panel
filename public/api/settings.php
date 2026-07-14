<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboConfig;

$projectRoot = dirname(__DIR__, 2);
$configPath = $projectRoot . '/config.php';
$dataDir = $projectRoot . '/data';
$cloudSettings = new YarboCloudSettings($dataDir);
$cloud = new YarboCloud($cloudSettings, $projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $includeCloudStatus = isset($_GET['cloud_status']) && $_GET['cloud_status'] !== '0';
    json_response([
        'ok' => true,
        'broker_host' => (string) ($config['broker_host'] ?? ''),
        'broker_port' => (int) ($config['broker_port'] ?? 1883),
        'serial' => (string) ($config['serial'] ?? ''),
        'writable' => is_writable($configPath),
        'cloud' => $cloudSettings->publicView(),
        // Skip Python SDK probe by default — it can block the single-threaded php -S server.
        'cloud_status' => $includeCloudStatus ? $cloud->status() : null,
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

$host = trim((string) ($input['broker_host'] ?? $input['host'] ?? ''));
$serial = trim((string) ($input['serial'] ?? ''));

if ($host === '') {
    json_response(['ok' => false, 'error' => 'broker_host is required'], 400);
}
if ($serial === '') {
    json_response(['ok' => false, 'error' => 'serial is required'], 400);
}
if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    json_response(['ok' => false, 'error' => 'broker_host must be a valid IPv4 address'], 400);
}

if (!YarboConfig::applySettings($configPath, [
    'broker_host' => $host,
    'serial' => $serial,
])) {
    json_response([
        'ok' => false,
        'error' => 'Could not write config.php. Check file permissions on the server.',
    ], 500);
}

if (!$cloudSettings->save($input)) {
    json_response([
        'ok' => false,
        'error' => 'Could not write cloud settings. Check permissions on the data/ directory.',
    ], 500);
}

// Skip cloud->status() on save — that spawns Python and can block the single-threaded
// php -S server. The client already has status from load; Test cloud connection refreshes it.
json_response([
    'ok' => true,
    'broker_host' => $host,
    'serial' => $serial,
    'cloud' => $cloudSettings->publicView(),
]);
