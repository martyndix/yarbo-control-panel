<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboMap;

$commands = [
    'read_gps_ref',
    'get_map',
    'read_clean_area',
    'read_recharge_point',
];

set_time_limit(120);

$projectRoot = dirname(__DIR__, 2);
$cloudSettings = new YarboCloudSettings($projectRoot . '/data');
$cloud = new YarboCloud($cloudSettings, $projectRoot);
$cloudConfig = $cloudSettings->load();
$dataSource = (string) ($_GET['source'] ?? $cloudConfig['data_source'] ?? YarboCloudSettings::DATA_SOURCE_AUTO);
$serial = (string) ($config['serial'] ?? '');

/**
 * @param array<string, mixed>|null $payload
 * @return array<string, mixed>|null
 */
function wrap_cloud_feedback(string $cmd, ?array $payload): ?array
{
    if ($payload === null) {
        return null;
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        return $payload;
    }

    return [
        'topic' => $cmd,
        'state' => 0,
        'data' => $payload,
    ];
}

function load_map_local(\Yarbo\YarboMqtt $client, array $commands): array
{
    $responses = [];
    foreach ($commands as $cmd) {
        $timeout = in_array($cmd, ['get_map', 'read_gps_ref'], true) ? 10.0 : 5.0;
        $responses[$cmd] = $client->requestDataFeedback($cmd, [], $timeout, true);
    }

    return ['responses' => $responses, 'via' => 'local'];
}

function load_map_cloud(YarboCloud $cloud, string $serial): array
{
    $gpsRef = $cloud->fetch('read_gps_ref', $serial, 15.0);
    $mapData = $cloud->fetch('get_map', $serial, 35.0);

    if ($mapData !== null && ($mapData['ok'] ?? true) === false) {
        return [
            'responses' => [],
            'via' => 'cloud',
            'error' => (string) ($mapData['error'] ?? 'Cloud map read failed'),
        ];
    }

    $responses = [];
    if (is_array($gpsRef) && ($gpsRef['ok'] ?? true) !== false) {
        $payload = is_array($gpsRef['data'] ?? null) ? $gpsRef['data'] : $gpsRef;
        $responses['read_gps_ref'] = wrap_cloud_feedback('read_gps_ref', is_array($payload) ? $payload : null);
    }
    if (is_array($mapData) && ($mapData['ok'] ?? true) !== false) {
        $payload = is_array($mapData['data'] ?? null) ? $mapData['data'] : $mapData;
        $responses['get_map'] = wrap_cloud_feedback('get_map', is_array($payload) ? $payload : null);
    }

    return ['responses' => $responses, 'via' => 'cloud'];
}

try {
    $result = null;
    $note = null;

    if ($dataSource === YarboCloudSettings::DATA_SOURCE_CLOUD) {
        $result = load_map_cloud($cloud, $serial);
        if (($result['error'] ?? null) !== null) {
            $note = (string) $result['error'];
        }
    } else {
        $client = yarbo_client($config);
        $client->connect();
        $result = load_map_local($client, $commands);
        $client->disconnect();

        if (
            $dataSource === YarboCloudSettings::DATA_SOURCE_AUTO
            && $cloudConfig['enabled']
            && $cloudConfig['email'] !== ''
            && $cloudConfig['password'] !== ''
        ) {
            $localNormalized = YarboMap::normalize(
                $result['responses'],
                $result['responses']['read_gps_ref'] ?? null,
            );
            if ($localNormalized['status'] !== 'ready') {
                try {
                    $cloudResult = load_map_cloud($cloud, $serial);
                    if (($cloudResult['error'] ?? null) === null) {
                        $cloudNormalized = YarboMap::normalize(
                            $cloudResult['responses'],
                            $cloudResult['responses']['read_gps_ref'] ?? null,
                        );
                        if ($cloudNormalized['status'] === 'ready') {
                            $result = $cloudResult;
                            $note = 'Loaded via Yarbo cloud (local MQTT returned no drawable map).';
                        }
                    }
                } catch (Throwable $cloudError) {
                    $note = 'Local map empty; cloud fallback failed: ' . $cloudError->getMessage();
                }
            }
        }
    }

    $responses = $result['responses'] ?? [];
    $gpsRef = $responses['read_gps_ref'] ?? null;
    $normalized = YarboMap::normalize($responses, is_array($gpsRef) ? $gpsRef : null);

    json_response([
        'ok' => true,
        'status' => $normalized['status'],
        'source' => $normalized['source'],
        'data_via' => $result['via'] ?? 'local',
        'gps_ref' => $normalized['gps_ref'],
        'warnings' => $normalized['warnings'],
        'probes' => $normalized['probes'],
        'geojson' => $normalized['feature_collection'],
        'note' => $note,
        'updated_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
