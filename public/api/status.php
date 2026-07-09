<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboTelemetry;
use Yarbo\YarboWifi;

try {
    $client = yarbo_client($config);
    $client->connect();
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'stage' => 'connect',
        'error' => friendly_error($e),
    ], 500);
}

try {
    $raw = $client->requestTelemetry(6);
    $wifiResponse = $client->requestDataFeedback('get_connect_wifi_name', [], 2.5, false);
    $client->disconnect();

    if ($raw === null) {
        json_response([
            'ok' => false,
            'stage' => 'telemetry',
            'error' => friendly_message('telemetry_timeout: No telemetry received within timeout. Check serial number.'),
        ], 504);
    }

    json_response(array_merge(
        ['ok' => true],
        YarboTelemetry::parse($raw),
        ['wifi' => YarboWifi::parse($wifiResponse)],
    ));
} catch (Throwable $e) {
    $client->disconnect();
    json_response([
        'ok' => false,
        'stage' => 'telemetry',
        'error' => friendly_error($e),
    ], 500);
}
