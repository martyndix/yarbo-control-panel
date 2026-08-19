<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboCommands;
use Yarbo\YarboGeo;
use Yarbo\YarboMap;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboPlans;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$projectRoot = dirname(__DIR__, 2);
$cloudSettings = new YarboCloudSettings($projectRoot . '/data');
$cloud = new YarboCloud($cloudSettings, $projectRoot);
$cloudConfig = $cloudSettings->load();
$dataSource = (string) ($_GET['source'] ?? $cloudConfig['data_source'] ?? YarboCloudSettings::DATA_SOURCE_AUTO);

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

function load_plans_local(\Yarbo\YarboMqtt $client): array
{
    $response = $client->requestDataFeedback('read_all_plan', [], 10.0, false);

    return [
        'plans' => YarboPlans::parseList($response),
        'responded' => $response !== null,
        'raw' => $response,
        'via' => 'local',
    ];
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

        if ($dataSource === YarboCloudSettings::DATA_SOURCE_CLOUD) {
            $result = load_plans_cloud($cloud, $serial);
            if (($result['error'] ?? null) !== null) {
                $note = (string) $result['error'];
            }
        } else {
            $client = yarbo_client($config);
            $client->connect();
            $result = load_plans_local($client);
            $client->disconnect();

            if (
                $dataSource === YarboCloudSettings::DATA_SOURCE_AUTO
                && (!$result['responded'] || $result['plans'] === [])
                && $cloudConfig['enabled']
            ) {
                $cloudResult = load_plans_cloud($cloud, $serial);
                if ($cloudResult['responded'] && $cloudResult['plans'] !== []) {
                    $result = $cloudResult;
                    $note = 'Loaded via Yarbo cloud (local MQTT returned no plans).';
                }
            }
        }

        $plans = $result['plans'] ?? [];
        $responded = (bool) ($result['responded'] ?? false);
        if ($note === null) {
            $note = !$responded
                ? 'No response to read_all_plan. Try cloud fallback in Settings or load while the robot is active.'
                : ($plans === [] ? 'Robot responded but returned no saved plans.' : null);
        }

        json_response([
            'ok' => true,
            'plans' => $plans,
            'count' => count($plans),
            'responded' => $responded,
            'source' => $result['via'] ?? 'local',
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
        $result = $agent->publishVariants(YarboCommands::startPlanVariants($planId, $percent), 'work');
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Start failed'),
                'via' => 'agent',
                'hold_controller' => (bool) ($result['hold_controller'] ?? false),
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => 'start',
            'plan_id' => $planId,
            'percent' => $percent,
            'cmd' => $result['cmd'] ?? 'start_plan',
            'via' => 'agent',
            'hold_controller' => (bool) ($result['hold_controller'] ?? true),
        ]);
    }

    if ($action === 'delete') {
        $planId = $input['plan_id'] ?? $input['planId'] ?? null;
        if ($planId === null || $planId === '') {
            json_response(['ok' => false, 'error' => 'plan_id is required'], 400);
        }
        if (!($input['confirm'] ?? false)) {
            json_response(['ok' => false, 'error' => 'confirm=true is required to delete a plan'], 400);
        }

        $payload = [
            'planId' => is_numeric($planId) ? (int) $planId : (string) $planId,
        ];
        $result = $agent->publish('del_plan', $payload, 'work');
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Delete failed'),
                'via' => 'agent',
            ], 500);
        }

        json_response([
            'ok' => true,
            'action' => 'delete',
            'plan_id' => $planId,
            'via' => 'agent',
        ]);
    }

    if ($action === 'delete_all') {
        if (!($input['confirm'] ?? false)) {
            json_response(['ok' => false, 'error' => 'confirm=true is required to delete all plans'], 400);
        }

        $result = $agent->publish('del_all_plan', [], 'work');
        if (!($result['ok'] ?? false)) {
            json_response([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Delete all failed'),
                'via' => 'agent',
            ], 500);
        }

        json_response(['ok' => true, 'action' => 'delete_all', 'via' => 'agent']);
    }

    json_response([
        'ok' => false,
        'error' => 'Unknown action. Valid: start, delete, delete_all',
    ], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
