<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboHub;
use Yarbo\YarboUnifi;

$projectRoot = dirname(__DIR__, 2);
$hub = new YarboHub($projectRoot);
$unifi = new YarboUnifi($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
set_time_limit(20);

if ($method === 'GET' && $action === 'snapshot') {
    if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
        json_response(['ok' => false, 'error' => 'Turn on the UniFi module in Settings.'], 403);
    }
    try {
        $jpeg = $unifi->snapshotJpeg((string) ($_GET['id'] ?? ''));
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=20');
        header('Content-Length: ' . (string) strlen($jpeg));
        echo $jpeg;
        exit;
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($method === 'GET' && ($action === '' || $action === 'status')) {
    json_response(['ok' => true] + $unifi->dashboardPayload());
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    $input = $_POST;
}
$action = (string) ($input['action'] ?? $action);

if ($action === 'save') {
    if (!$unifi->save($input)) {
        json_response(['ok' => false, 'error' => 'Could not write UniFi settings.'], 500);
    }
    json_response(['ok' => true, 'config' => $unifi->publicView()]);
}

if ($action === 'probe' || $action === 'test') {
    $unifi->save($input);
    json_response($unifi->probe() + ['config' => $unifi->publicView()]);
}

if (!$hub->enabled(YarboHub::MODULE_UNIFI) && !in_array($action, ['save', 'probe', 'test'], true)) {
    json_response(['ok' => false, 'error' => 'Turn on the UniFi module in Settings.'], 403);
}

if ($action === 'show_on_home') {
    $id = (string) ($input['id'] ?? '');
    $show = YarboHub::asBool($input['show'] ?? $input['on'] ?? true);
    if (!$unifi->setShowOnHome($id, $show)) {
        json_response(['ok' => false, 'error' => 'Could not update Home list'], 400);
    }
    json_response(['ok' => true, 'show_on_home' => $unifi->load()['show_on_home']]);
}

if ($action === 'command' || $action === 'unlock' || $action === 'light') {
    json_response($unifi->command($input));
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
