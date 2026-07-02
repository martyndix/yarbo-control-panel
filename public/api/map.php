<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboMap;

$commands = [
    'get_map',
    'read_clean_area',
    'read_all_plan',
    'read_recharge_point',
];

try {
    $client = yarbo_client($config);
    $client->connect();

    $responses = [];
    foreach ($commands as $cmd) {
        $responses[$cmd] = $client->requestDataFeedback($cmd, [], 6.0, true);
    }

    $client->disconnect();

    $normalized = YarboMap::normalize($responses);
    json_response([
        'ok' => true,
        'status' => $normalized['status'],
        'source' => $normalized['source'],
        'warnings' => $normalized['warnings'],
        'probes' => $normalized['probes'],
        'geojson' => $normalized['feature_collection'],
        'updated_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}

