<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboHub;
use Yarbo\YarboUnifi;
use Yarbo\YarboUnifiRadio;

$projectRoot = dirname(__DIR__, 2);
$hub = new YarboHub($projectRoot);
$unifi = new YarboUnifi($projectRoot);
$radio = static fn (): YarboUnifiRadio => new YarboUnifiRadio($projectRoot, $unifi);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
set_time_limit(20);

if ($method === 'GET' && $action === 'snapshot') {
    if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
        json_response(['ok' => false, 'error' => 'Turn on the UniFi module in Settings.'], 403);
    }
    try {
        $fresh = YarboHub::asBool($_GET['fresh'] ?? false);
        $jpeg = $unifi->snapshotJpeg((string) ($_GET['id'] ?? ''), $fresh);
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . ($fresh ? 'no-store, no-cache, must-revalidate' : 'private, max-age=4'));
        header('Content-Length: ' . (string) strlen($jpeg));
        echo $jpeg;
        exit;
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($method === 'GET' && $action === 'stream') {
    if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
        json_response(['ok' => false, 'error' => 'Turn on the UniFi module in Settings.'], 403);
    }
    try {
        ignore_user_abort(true);
        set_time_limit(0);
        $unifi->streamMjpeg((string) ($_GET['id'] ?? ''));
        exit;
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($method === 'GET' && ($action === '' || $action === 'status')) {
    try {
        json_response(['ok' => true] + $unifi->dashboardPayload());
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
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

if ($action === 'doors_tile') {
    $show = YarboHub::asBool($input['show'] ?? $input['on'] ?? $input['doors_tile'] ?? true);
    if (!$unifi->setDoorsTile($show)) {
        json_response(['ok' => false, 'error' => 'Could not update doors tile'], 400);
    }
    json_response(['ok' => true, 'doors_tile' => $unifi->doorsTileEnabled()]);
}

if ($action === 'doors_tile_sensors') {
    $show = YarboHub::asBool($input['show'] ?? $input['on'] ?? $input['doors_tile_sensors'] ?? false);
    if (!$unifi->setDoorSensorsTile($show)) {
        json_response(['ok' => false, 'error' => 'Could not update door sensors on the tile'], 400);
    }
    json_response(['ok' => true, 'doors_tile_sensors' => $unifi->doorSensorsTileEnabled()]);
}

if ($action === 'speakers_enabled') {
    $show = YarboHub::asBool($input['show'] ?? $input['on'] ?? $input['speakers_enabled'] ?? true);
    if (!$unifi->setSpeakersEnabled($show)) {
        json_response(['ok' => false, 'error' => 'Could not update Speakers setting'], 400);
    }
    json_response(['ok' => true, 'speakers_enabled' => $unifi->speakersEnabled()] + $radio()->dashboardSlice());
}

if ($action === 'radio_stations') {
    $stations = $radio()->saveStations(is_array($input['stations'] ?? null) ? $input['stations'] : []);
    json_response(['ok' => true, 'stations' => $stations]);
}

if ($action === 'radio_stations_restore') {
    json_response(['ok' => true, 'stations' => $radio()->restoreDefaultStations()]);
}

if ($action === 'radio_play') {
    set_time_limit(60);
    json_response($radio()->play($input));
}

if ($action === 'radio_stop') {
    json_response($radio()->stop());
}

if ($action === 'radio_volume') {
    json_response($radio()->setVolume($input));
}

if ($action === 'command' || $action === 'unlock' || $action === 'light') {
    json_response($unifi->command($input));
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
