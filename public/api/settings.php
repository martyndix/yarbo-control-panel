<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboConfig;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$configPath = dirname(__DIR__, 2) . '/config.php';

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'broker_host' => (string) ($config['broker_host'] ?? ''),
        'broker_port' => (int) ($config['broker_port'] ?? 1883),
        'serial' => (string) ($config['serial'] ?? ''),
        'writable' => is_writable($configPath),
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

json_response([
    'ok' => true,
    'broker_host' => $host,
    'serial' => $serial,
]);
