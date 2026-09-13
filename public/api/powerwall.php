<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboPowerwall;

$projectRoot = dirname(__DIR__, 2);
$powerwall = new YarboPowerwall($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && ($action === '' || $action === 'status')) {
    json_response(['ok' => true] + $powerwall->dashboardPayload() + ['config' => $powerwall->publicView()]);
}

if ($method === 'POST') {
    $input = $_POST;
    if ($input === []) {
        $body = file_get_contents('php://input');
        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        $input = is_array($decoded) ? $decoded : [];
    }
    $action = (string) ($input['action'] ?? $action);
    if ($action === 'save') {
        if (!$powerwall->save($input)) {
            json_response(['ok' => false, 'error' => 'Could not write Powerwall settings.'], 500);
        }
        json_response(['ok' => true, 'config' => $powerwall->publicView()]);
    }
    if ($action === 'test') {
        json_response($powerwall->testConnection() + ['config' => $powerwall->publicView()]);
    }
    if ($action === 'refresh') {
        json_response($powerwall->refresh() + ['config' => $powerwall->publicView()]);
    }
    if ($action === 'generate_keys') {
        json_response($powerwall->generateFleetKeys() + ['config' => $powerwall->publicView()]);
    }
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
