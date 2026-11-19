<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCameraLive;

$root = dirname(__DIR__, 2);
$live = new YarboCameraLive($root);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === '') {
    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $action = (string) ($decoded['action'] ?? '');
        }
    }
}

if ($method === 'POST') {
    $input = $_POST;
    if ($input === []) {
        $body = file_get_contents('php://input');
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
    }
    $action = (string) ($input['action'] ?? $action);
}

if ($action === 'frames') {
    $live->proxyFrames();
    exit;
}

if ($action === 'start') {
    json_response($live->start());
}

if ($action === 'stop') {
    json_response($live->stop());
}

json_response($live->status());
