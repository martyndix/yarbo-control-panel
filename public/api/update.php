<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboPanelHttps;
use Yarbo\YarboUpdate;

set_time_limit(120);

$projectRoot = dirname(__DIR__, 2);
$update = new YarboUpdate($projectRoot);
$https = new YarboPanelHttps($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'status');
    if ($action === 'progress') {
        json_response($update->readProgress());
    }

    if ($action === 'release-notes') {
        json_response($update->releaseNotes());
    }

    if ($action === 'https-status') {
        json_response($https->status());
    }

    json_response($update->status(true));
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

$action = (string) ($input['action'] ?? '');

if ($action === 'check') {
    json_response($update->status(true));
}

if ($action === 'update') {
    if (!($input['confirm'] ?? false)) {
        json_response(['ok' => false, 'error' => 'confirm=true is required'], 400);
    }

    json_response($update->runUpdateAsync());
}

if ($action === 'restart') {
    if (!($input['confirm'] ?? false)) {
        json_response(['ok' => false, 'error' => 'confirm=true is required'], 400);
    }

    json_response($update->restartPanelAsync());
}

if ($action === 'https_ensure' || $action === 'https-ensure') {
    set_time_limit(30);
    json_response($https->ensure());
}

json_response(['ok' => false, 'error' => 'Unknown action. Valid: check, update, restart, https_ensure'], 400);
