<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboPlans;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function plans_input(): array
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

try {
    $client = yarbo_client($config);
    $client->connect();

    if ($method === 'GET') {
        $response = $client->requestDataFeedback('read_all_plan', [], 10.0, true);
        $plans = YarboPlans::parseList($response);

        $client->disconnect();

        json_response([
            'ok' => true,
            'plans' => $plans,
            'count' => count($plans),
            'responded' => $response !== null,
            'note' => $response === null
                ? 'No response to read_all_plan. Some robots only reply when active or while a plan is running.'
                : ($plans === [] ? 'Robot responded but returned no saved plans.' : null),
            'updated_at' => gmdate('c'),
        ]);
    }

    if ($method !== 'POST') {
        $client->disconnect();
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = plans_input();
    $action = (string) ($input['action'] ?? '');

    if ($action === 'start') {
        $planId = $input['plan_id'] ?? $input['planId'] ?? null;
        if ($planId === null || $planId === '') {
            $client->disconnect();
            json_response(['ok' => false, 'error' => 'plan_id is required'], 400);
        }

        $percent = isset($input['percent']) ? (int) $input['percent'] : 0;
        $percent = max(0, min(100, $percent));

        $payload = [
            'planId' => is_numeric($planId) ? (int) $planId : (string) $planId,
            'percent' => $percent,
        ];

        $client->sendCommand('start_plan', $payload, true);
        $client->disconnect();

        json_response([
            'ok' => true,
            'action' => 'start',
            'plan_id' => $planId,
            'percent' => $percent,
        ]);
    }

    if ($action === 'delete') {
        $planId = $input['plan_id'] ?? $input['planId'] ?? null;
        if ($planId === null || $planId === '') {
            $client->disconnect();
            json_response(['ok' => false, 'error' => 'plan_id is required'], 400);
        }
        if (!($input['confirm'] ?? false)) {
            $client->disconnect();
            json_response(['ok' => false, 'error' => 'confirm=true is required to delete a plan'], 400);
        }

        $payload = [
            'planId' => is_numeric($planId) ? (int) $planId : (string) $planId,
        ];
        $client->sendCommand('del_plan', $payload, true);
        $client->disconnect();

        json_response(['ok' => true, 'action' => 'delete', 'plan_id' => $planId]);
    }

    if ($action === 'delete_all') {
        if (!($input['confirm'] ?? false)) {
            $client->disconnect();
            json_response(['ok' => false, 'error' => 'confirm=true is required to delete all plans'], 400);
        }

        $client->sendCommand('del_all_plan', [], true);
        $client->disconnect();

        json_response(['ok' => true, 'action' => 'delete_all']);
    }

    $client->disconnect();
    json_response([
        'ok' => false,
        'error' => 'Unknown action. Valid: start, delete, delete_all',
    ], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
