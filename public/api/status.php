<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboErrors;
use Yarbo\YarboHub;
use Yarbo\YarboLymow;
use Yarbo\YarboMqtt;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboPowerwall;
use Yarbo\YarboRobotName;
use Yarbo\YarboTelemetry;
use Yarbo\YarboVestaboard;
use Yarbo\YarboWifi;

$host = (string) ($config['broker_host'] ?? '');
$port = (int) ($config['broker_port'] ?? 1883);

function vestaboard_board(): YarboVestaboard
{
    static $board = null;
    $board ??= new YarboVestaboard(dirname(__DIR__, 2));

    return $board;
}

function vestaboard_status_payload(?array $parsed, bool $online): array
{
    return vestaboard_board()->dashboardPayload($parsed, $online);
}

vestaboard_board()->rememberClientTimezoneFromRequest();

/**
 * Companion tiles that must still load when the robot is unreachable.
 *
 * @return array<string, mixed>
 */
function hub_status_extras(bool $allowPowerwallRefresh = true, bool $includeVestaboard = false): array
{
    $root = dirname(__DIR__, 2);
    $hub = new YarboHub($root);
    $powerwall = null;
    $lymow = null;
    $home = null;
    $vestaboard = null;

    try {
        $powerwall = $hub->enabled(YarboHub::MODULE_POWERWALL)
            ? (new YarboPowerwall($root))->dashboardPayload($allowPowerwallRefresh)
            : null;
    } catch (Throwable $e) {
        $powerwall = ['ok' => false, 'online' => false, 'error' => $e->getMessage()];
    }

    try {
        $lymow = $hub->enabled(YarboHub::MODULE_LYMOW)
            ? (new YarboLymow($root))->dashboardPayload()
            : null;
    } catch (Throwable $e) {
        $lymow = ['ok' => false, 'online' => false, 'error' => $e->getMessage()];
    }

    try {
        $home = $hub->enabled(YarboHub::MODULE_HOME)
            ? ['enabled' => true]
            : null;
    } catch (Throwable) {
        $home = null;
    }

    if ($includeVestaboard) {
        try {
            $vestaboard = vestaboard_status_payload(null, false);
        } catch (Throwable) {
            $vestaboard = ['enabled' => false];
        }
    }

    $extras = [
        'hub' => $hub->publicView(),
        'powerwall' => $powerwall,
        'lymow' => $lymow,
        'home' => $home,
    ];
    if ($includeVestaboard) {
        $extras['vestaboard'] = $vestaboard;
    }

    return $extras;
}

/**
 * @param array<string, mixed> $extra
 */
function status_failure(string $error, string $stage, int $http = 500, array $extra = []): never
{
    json_response(array_merge(
        [
            'ok' => false,
            'stage' => $stage,
            'error' => $error,
        ],
        hub_status_extras(false, true),
        $extra,
    ), $http);
}

function attach_robot_name(array $parsed): array
{
    global $config;
    $serial = (string) ($config['serial'] ?? '');
    $names = new YarboRobotName(dirname(__DIR__, 2));

    return $names->apply($parsed, $serial);
}

function status_raw_usable(mixed $raw): bool
{
    return is_array($raw) && $raw !== [] && (
        isset($raw['StateMSG'])
        || isset($raw['BatteryMSG'])
        || isset($raw['CombinedOdom'])
        || isset($raw['RTKMSG'])
    );
}

function status_from_agent(array $result): void
{
    $raw = $result['raw'] ?? null;
    if (!($result['ok'] ?? false) || !status_raw_usable($raw)) {
        return;
    }

    $wifiEnvelope = is_array($result['wifi'] ?? null)
        ? ['data' => $result['wifi'], 'topic' => 'get_connect_wifi_name']
        : null;

    $cellTemps = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
    $parsed = YarboTelemetry::parseForPanel($raw, $cellTemps, dirname(__DIR__, 2));
    if (array_key_exists('lights_on', $result)) {
        $parsed['lights_on'] = (bool) $result['lights_on'];
    }
    if (array_key_exists('hold_controller', $result)) {
        $parsed['hold_controller'] = (bool) $result['hold_controller'];
    }
    if (array_key_exists('controller_acquired', $result)) {
        $parsed['controller_acquired'] = (bool) $result['controller_acquired'];
    }

    json_response(array_merge(
        ['ok' => true, 'via' => 'agent', 'cached' => (bool) ($result['cached'] ?? false)],
        attach_robot_name($parsed),
        [
            'wifi' => YarboWifi::parse($wifiEnvelope),
            'vestaboard' => vestaboard_status_payload($parsed, true),
        ],
        hub_status_extras(),
    ));
}

$projectRoot = dirname(__DIR__, 2);
$hub = new YarboHub($projectRoot);
if (!$hub->enabled(YarboHub::MODULE_YARBO)) {
    json_response(array_merge(
        ['ok' => true, 'via' => 'modules', 'yarbo_enabled' => false],
        hub_status_extras(true, true),
    ));
}

// Prefer persistent Python agent so status does not open a competing MQTT session.
$agent = YarboMqttAgentClient::fromEnv();
$ping = $agent->ping();
if (($ping['engine'] ?? '') === 'php' || !($ping['ok'] ?? false)) {
    try {
        $agent = YarboMqttAgentClient::requireRunning();
        $ping = $agent->ping();
    } catch (Throwable) {
        $ping = ['ok' => false];
    }
}

$agentTelemetry = ($ping['ok'] ?? false)
    && (($ping['telemetry'] ?? false) || (($ping['engine'] ?? '') === 'python-yarbo'));

if ($agentTelemetry) {
    try {
        $result = $agent->telemetry(4.0, true);
        status_from_agent($result);

        $error = (string) ($result['error'] ?? 'telemetry timeout');
        $unknown = str_contains(strtolower($error), 'unknown op');
        $disconnected = str_contains(strtolower($error), 'mqtt not connected');
        if ($unknown || $disconnected) {
            // PHP fallback agent, or Python still connecting — use a direct read.
        } else {
            status_failure(
                YarboErrors::friendly($error),
                'telemetry',
                504,
                [
                    'via' => 'agent',
                    'transient' => (bool) ($result['transient'] ?? false),
                ],
            );
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (!str_contains(strtolower($message), 'mqtt agent is not running')) {
            status_failure(friendly_error($e), 'telemetry', 504, [
                'via' => 'agent',
                'transient' => false,
            ]);
        }
    }
}

// Fail fast on unreachable broker so the single-threaded php -S server is not blocked for 30s+.
$tcp = YarboMqtt::probeTcp($host, $port, 2.0);
if (!$tcp['ok']) {
    $detail = strtolower((string) ($tcp['error'] ?? ''));
    $errno = (int) ($tcp['errno'] ?? 0);
    if (str_contains($detail, 'connection refused') || $errno === 111) {
        $message = YarboErrors::MSG_REFUSED;
    } elseif (str_contains($detail, 'no route to host') || $errno === 113) {
        $message = YarboErrors::MSG_NO_ROUTE;
    } elseif (str_contains($detail, 'network is unreachable') || $errno === 101) {
        $message = YarboErrors::MSG_UNREACHABLE;
    } elseif (str_contains($detail, 'timed out') || $errno === 60 || $errno === 110) {
        $message = YarboErrors::MSG_TIMEOUT;
    } else {
        $message = YarboErrors::friendly((string) ($tcp['error'] ?? 'TCP connection failed'));
    }

    status_failure($message, 'tcp');
}

try {
    $client = yarbo_client($config);
    $client->connect();
} catch (Throwable $e) {
    status_failure(friendly_error($e), 'connect');
}

try {
    $raw = $client->requestTelemetry(4);
    $wifiResponse = null;
    $cellTemps = null;
    if ($raw !== null) {
        // WiFi is nice-to-have; keep it short so a slow reply does not stall the panel.
        $wifiResponse = $client->requestDataFeedback('get_connect_wifi_name', [], 1.5, false);
        $cellResponse = $client->requestDataFeedback('battery_cell_temp_msg', [], 1.5, false);
        if (is_array($cellResponse)) {
            $cellTemps = is_array($cellResponse['data'] ?? null) ? $cellResponse['data'] : $cellResponse;
        }
    }
    $client->disconnect();

    if ($raw === null) {
        status_failure(
            friendly_message('telemetry_timeout: No telemetry received within timeout. Check serial number.'),
            'telemetry',
            504,
        );
    }

    $parsed = attach_robot_name(YarboTelemetry::parseForPanel($raw, $cellTemps, dirname(__DIR__, 2)));
    json_response(array_merge(
        ['ok' => true, 'via' => 'direct'],
        $parsed,
        [
            'wifi' => YarboWifi::parse(is_array($wifiResponse) ? $wifiResponse : null),
            'vestaboard' => vestaboard_status_payload($parsed, true),
        ],
        hub_status_extras(),
    ));
} catch (Throwable $e) {
    $client->disconnect();
    status_failure(friendly_error($e), 'telemetry');
}
