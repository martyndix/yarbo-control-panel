<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboGeo;
use Yarbo\YarboMap;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboPlans;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$projectRoot = dirname(__DIR__, 2);
$cloudSettings = new YarboCloudSettings($projectRoot . '/data');
$cloud = new YarboCloud($cloudSettings, $projectRoot);
// Local MQTT is closed on current Yarbo firmware — plans always load via cloud.

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

function load_plans_cloud(YarboCloud $cloud, string $serial): array
{
    $response = $cloud->fetch('read_all_plan', $serial, 15.0);
    if ($response === null) {
        return ['plans' => [], 'responded' => false, 'raw' => null, 'via' => 'cloud', 'error' => 'Cloud not configured'];
    }
    if (($response['ok'] ?? true) === false) {
        return [
            'plans' => [],
            'responded' => false,
            'raw' => $response,
            'via' => 'cloud',
            'error' => (string) ($response['error'] ?? 'Cloud read failed'),
        ];
    }

    $envelope = is_array($response['data'] ?? null) ? $response['data'] : $response;

    return [
        'plans' => YarboPlans::parseList($envelope),
        'responded' => $envelope !== null,
        'raw' => $envelope,
        'via' => 'cloud',
    ];
}

try {
    if ($method === 'GET') {
        $serial = (string) ($config['serial'] ?? '');
        $result = null;
        $note = null;

        $result = load_plans_cloud($cloud, $serial);
        if (($result['error'] ?? null) !== null) {
            $note = (string) $result['error'];
        }

        $plans = $result['plans'] ?? [];
        $responded = (bool) ($result['responded'] ?? false);
        if ($note === null) {
            $note = !$responded
                ? 'No response loading plans from Yarbo cloud. Check Settings → Yarbo account and that the robot is online in the app.'
                : ($plans === [] ? 'Cloud responded but returned no saved plans.' : null);
        }

        json_response([
            'ok' => true,
            'plans' => $plans,
            'count' => count($plans),
            'responded' => $responded,
            'source' => $result['via'] ?? 'cloud',
            'note' => $note,
            'updated_at' => gmdate('c'),
        ]);
    }

    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = plans_input();
    $action = (string) ($input['action'] ?? '');
    $agent = YarboMqttAgentClient::requireRunning();

    if ($action === 'start') {
        $planId = $input['plan_id'] ?? $input['planId'] ?? null;
        if ($planId === null || $planId === '') {
            json_response(['ok' => false, 'error' => 'plan_id is required'], 400);
        }

        $percent = isset($input['percent']) ? (int) $input['percent'] : 0;
        $percent = max(0, min(100, $percent));
        $result = $agent->startPlan($planId, $percent);
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Start failed'),
                'via' => 'agent',
                'ack_msg' => $result['ack_msg'] ?? null,
                'hold_controller' => (bool) ($result['hold_controller'] ?? false),
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => 'start',
            'plan_id' => $planId,
            'percent' => $percent,
            'cmd' => $result['cmd'] ?? 'start_plan',
            'via' => $result['via'] ?? 'agent',
            'ack_msg' => $result['ack_msg'] ?? null,
            'hold_controller' => (bool) ($result['hold_controller'] ?? true),
        ]);
    }

    if ($action === 'delete' || $action === 'delete_all') {
        json_response([
            'ok' => false,
            'error' => 'Plan delete is disabled. Create and delete plans in the Yarbo app.',
            'plan_manage' => false,
        ], 403);
    }

    json_response([
        'ok' => false,
        'error' => 'Unknown action. Valid: start',
    ], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
