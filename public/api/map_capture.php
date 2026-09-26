<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboMapCapture;

$projectRoot = dirname(__DIR__, 2);
$capture = new YarboMapCapture($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_response($capture->status());
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
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

$action = (string) ($input['action'] ?? 'start');
if ($action === 'start') {
    json_response($capture->start());
}
if ($action === 'stop') {
    json_response($capture->stop());
}

json_response(['ok' => false, 'error' => 'Unknown action. Valid: start, stop'], 400);
