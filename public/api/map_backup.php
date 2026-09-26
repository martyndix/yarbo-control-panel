<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboMapBackup;

set_time_limit(70);

$projectRoot = dirname(__DIR__, 2);
$backups = new YarboMapBackup($projectRoot);
$cloudSettings = new YarboCloudSettings($projectRoot . '/data');
$cloud = new YarboCloud($cloudSettings, $projectRoot);
$serial = (string) ($config['serial'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_response($backups->lastSummary());
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

$action = (string) ($input['action'] ?? 'list');
$client = null;

try {
    $client = yarbo_client($config);
    $client->connect();

    if ($action === 'list') {
        json_response($backups->listAndFetch($client, $input['backup_id'] ?? null, $cloud, $serial));
    }

    if ($action === 'restore') {
        $collection = $input['geojson'] ?? null;
        if (!is_array($collection)) {
            json_response(['ok' => false, 'error' => 'geojson draft is required'], 400);
        }
        json_response($backups->restoreDraft(
            $client,
            $collection,
            (bool) ($input['confirm'] ?? false),
            $cloud,
            $serial
        ));
    }

    json_response(['ok' => false, 'error' => 'Unknown action. Valid: list, restore'], 400);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => friendly_error($e),
    ], 500);
} finally {
    if ($client !== null) {
        try {
            $client->disconnect();
        } catch (Throwable) {
        }
    }
}
