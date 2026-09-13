<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboLymow;

$projectRoot = dirname(__DIR__, 2);
$lymow = new YarboLymow($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'snapshot') {
    try {
        $jpeg = $lymow->snapshotJpeg();
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-cache');
        echo $jpeg;
        exit;
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($method === 'GET' && $action === 'stream') {
    try {
        $lymow->streamMjpeg();
        exit;
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($method === 'GET' && ($action === '' || $action === 'status')) {
    json_response(['ok' => true] + $lymow->dashboardPayload() + ['config' => $lymow->publicView()]);
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
        if (!$lymow->save($input)) {
            json_response(['ok' => false, 'error' => 'Could not write Lymow settings.'], 500);
        }
        json_response(['ok' => true, 'config' => $lymow->publicView()]);
    }
    if ($action === 'probe') {
        json_response($lymow->probe() + ['config' => $lymow->publicView()]);
    }
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
