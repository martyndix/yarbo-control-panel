<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboCodec;
use Yarbo\YarboMap;

$commands = [
    'get_map',
    'read_gps_ref',
];

set_time_limit(40);

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
    $batch = [
        'get_map' => 10.0,
        'read_gps_ref' => 5.0,
    ];
    $responses = $client->requestDataFeedbackBatch($batch, false);

    return ['responses' => $responses, 'via' => 'local'];
}

function load_map_cloud(YarboCloud $cloud, string $serial): array
{
    $mapData = $cloud->fetch('get_map', $serial, 12.0);

    if ($mapData !== null && ($mapData['ok'] ?? true) === false) {
        return [
            'responses' => [],
            'via' => 'cloud',
            'error' => (string) ($mapData['error'] ?? 'Cloud map read failed'),
        ];
    }

    $responses = [];
    if (is_array($mapData) && ($mapData['ok'] ?? true) !== false) {
        $payload = is_array($mapData['data'] ?? null) ? $mapData['data'] : $mapData;
        $responses['get_map'] = wrap_cloud_feedback('get_map', is_array($payload) ? $payload : null);
    }

    return ['responses' => $responses, 'via' => 'cloud'];
}

/**
 * Last successful live get_map, used when the robot does not reply after an app edit.
 *
 * @return array<string, mixed>|null
 */
function load_map_cache(string $projectRoot): ?array
{
    $path = $projectRoot . '/data/map-last.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return null;
    }
    $map = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
    if (!YarboMap::isAppMap($map)) {
        return null;
    }

    return [
        'responses' => [
            'get_map' => [
                'topic' => 'get_map',
                'state' => 0,
                'data' => $map,
            ],
        ],
        'via' => 'cache',
        'note' => 'Live get_map did not reply. Showing the last loaded map. Wait until the robot is idle after the app edit, then load again.',
    ];
}

/**
 * Cache the last decoded get_map payload for encode/round-trip. Never sent to the robot.
 *
 * @param array<string, mixed> $responses
 */
function persist_last_map(string $projectRoot, array $responses): void
{
    $envelope = $responses['get_map'] ?? null;
    if (!is_array($envelope)) {
        return;
    }

    $data = YarboCodec::decodePayloadField($envelope['data'] ?? null);
    if ($data === []) {
        $data = YarboCodec::decodePayloadField($envelope);
    }
    if ($data === []) {
        return;
    }

    $dir = $projectRoot . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $payload = json_encode([
        'saved_at' => gmdate('c'),
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = $dir . '/map-last.json.tmp';
    if (file_put_contents($tmp, $payload) === false) {
        return;
    }
    rename($tmp, $dir . '/map-last.json');
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
    if (($normalized['status'] ?? '') !== 'ready') {
        $cached = load_map_cache($projectRoot);
        if (is_array($cached)) {
            $result = $cached;
            $responses = $cached['responses'];
            $gpsRef = $responses['read_gps_ref'] ?? null;
            $normalized = YarboMap::normalize($responses, is_array($gpsRef) ? $gpsRef : null);
            if (($normalized['status'] ?? '') === 'ready') {
                $note = (string) ($cached['note'] ?? $note);
            }
        }
    }
    if (($normalized['status'] ?? '') === 'ready' && ($result['via'] ?? '') !== 'cache') {
        try {
            persist_last_map($projectRoot, $responses);
        } catch (Throwable) {
            // Cache is optional; map load still succeeds.
        }
    }

    if (($normalized['status'] ?? '') !== 'ready') {
        json_response([
            'ok' => false,
            'error' => ($note !== null && $note !== '')
                ? $note
                : 'Live get_map did not reply. The robot may still be applying an app edit. Wait until it is idle, then try again.',
            'status' => $normalized['status'] ?? 'empty',
            'data_via' => $result['via'] ?? 'local',
        ], 504);
    }

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
        'error' => friendly_error($e),
    ], 500);
}
