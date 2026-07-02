<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboTelemetry;
use Yarbo\YarboWifi;

try {
    $client = yarbo_client($config);
    $client->connect();

    $raw = $client->requestTelemetry(3);
    $wifiResponse = $client->requestDataFeedback('get_connect_wifi_name', [], 2.5, false);
    $client->disconnect();

    if ($raw === null) {
        json_response(['ok' => false, 'error' => 'No telemetry received within timeout. Check broker IP and serial number.'], 504);
    }

    json_response(array_merge(
        ['ok' => true],
        YarboTelemetry::parse($raw),
        ['wifi' => YarboWifi::parse($wifiResponse)],
    ));
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
