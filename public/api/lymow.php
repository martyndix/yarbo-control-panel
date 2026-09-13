<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboLymow;

$projectRoot = dirname(__DIR__, 2);
$lymow = new YarboLymow($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
set_time_limit(240);

if ($method === 'GET' && in_array($action, ['snapshot', 'live', 'stream'], true)) {
    try {
        $jpeg = $action === 'snapshot' ? $lymow->snapshotJpeg() : $lymow->liveJpeg();
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: ' . (string) strlen($jpeg));
        echo $jpeg;
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
    if ($action === 'login') {
        json_response($lymow->loginCloud() + ['config' => $lymow->publicView()]);
    }
    if ($action === 'refresh_cloud' || $action === 'refresh') {
        $lymow->refreshCloudIfStale();
        json_response(['ok' => true] + $lymow->dashboardPayload() + ['config' => $lymow->publicView()]);
    }
    if ($action === 'start_live') {
        json_response($lymow->startLive() + ['config' => $lymow->publicView()]);
    }
    if ($action === 'stop_live') {
        $lymow->stopLive();
        json_response(['ok' => true]);
    }
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
