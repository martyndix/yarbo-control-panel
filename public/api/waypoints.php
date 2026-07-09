<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboWaypoints;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function waypoints_input(): array
{
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

    return $input;
}

function parse_waypoint_index(mixed $value): ?int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    $index = (int) $value;
    if ($index < 0 || $index > 9999) {
        return null;
    }

    return $index;
}

function send_robot_to_waypoint(array $config, int $index): void
{
    $client = yarbo_client($config);
    $client->connect();
    $client->sendCommand('start_way_point', ['index' => $index], true);
    $client->disconnect();
}

if ($method === 'GET') {
    $store = YarboWaypoints::load();
    json_response([
        'ok' => true,
        'waypoints' => $store['waypoints'],
        'count' => count($store['waypoints']),
        'robot_list_available' => false,
        'note' => 'Named waypoints are saved in this panel (data/waypoints.json). The robot does not expose a documented MQTT command to list stored waypoint names.',
        'updated_at' => $store['updated_at'],
    ]);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = waypoints_input();
$action = (string) ($input['action'] ?? 'go');

if ($action === 'go' || !isset($input['action'])) {
    $index = parse_waypoint_index($input['index'] ?? null);
    if ($index === null) {
        json_response(['ok' => false, 'error' => 'index is required (0-9999)'], 400);
    }

    try {
        send_robot_to_waypoint($config, $index);
        json_response([
            'ok' => true,
            'action' => 'go',
            'index' => $index,
            'command' => 'start_way_point',
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => friendly_error($e)], 500);
    }
}

if ($action === 'save') {
    $name = trim((string) ($input['name'] ?? ''));
    $index = parse_waypoint_index($input['index'] ?? null);
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'name is required'], 400);
    }
    if ($index === null) {
        json_response(['ok' => false, 'error' => 'index is required (0-9999)'], 400);
    }

    $entry = YarboWaypoints::add($name, $index);
    json_response([
        'ok' => true,
        'action' => 'save',
        'waypoint' => $entry,
        'waypoints' => YarboWaypoints::load()['waypoints'],
    ]);
}

if ($action === 'update') {
    $id = trim((string) ($input['id'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $index = parse_waypoint_index($input['index'] ?? null);
    if ($id === '') {
        json_response(['ok' => false, 'error' => 'id is required'], 400);
    }
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'name is required'], 400);
    }
    if ($index === null) {
        json_response(['ok' => false, 'error' => 'index is required (0-9999)'], 400);
    }

    $entry = YarboWaypoints::update($id, $name, $index);
    if ($entry === null) {
        json_response(['ok' => false, 'error' => 'Waypoint not found'], 404);
    }

    json_response([
        'ok' => true,
        'action' => 'update',
        'waypoint' => $entry,
        'waypoints' => YarboWaypoints::load()['waypoints'],
    ]);
}

if ($action === 'delete') {
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        json_response(['ok' => false, 'error' => 'id is required'], 400);
    }
    if (!YarboWaypoints::delete($id)) {
        json_response(['ok' => false, 'error' => 'Waypoint not found'], 404);
    }

    json_response([
        'ok' => true,
        'action' => 'delete',
        'waypoints' => YarboWaypoints::load()['waypoints'],
    ]);
}

json_response([
    'ok' => false,
    'error' => 'Unknown action. Valid: go, save, update, delete',
], 400);
