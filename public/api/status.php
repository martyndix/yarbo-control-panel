<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboErrors;
use Yarbo\YarboHub;
use Yarbo\YarboLymow;
use Yarbo\YarboUnifi;
use Yarbo\YarboMqttAgentClient;
use Yarbo\YarboPowerwall;
use Yarbo\YarboRobotName;
use Yarbo\YarboTelemetry;
use Yarbo\YarboVestaboard;
use Yarbo\YarboWifi;

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
    $unifi = null;
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

    try {
        $unifi = $hub->enabled(YarboHub::MODULE_UNIFI)
            ? (new YarboUnifi($root))->dashboardPayload(false)
            : null;
    } catch (Throwable $e) {
        $unifi = ['ok' => false, 'online' => false, 'error' => $e->getMessage()];
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
        'unifi' => $unifi,
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

// Cloud MQTT agent only — local LAN :1883 is no longer used for status/control.
$agent = YarboMqttAgentClient::fromEnv();
$ping = $agent->ping();
$engine = (string) ($ping['engine'] ?? '');
$isCloudAgent = $engine === 'yarbo-cloud' || ($ping['transport'] ?? '') === 'cloud';
if (!$isCloudAgent || !($ping['ok'] ?? false)) {
    try {
        $agent = YarboMqttAgentClient::requireRunning();
        $ping = $agent->ping();
        $engine = (string) ($ping['engine'] ?? '');
        $isCloudAgent = $engine === 'yarbo-cloud' || ($ping['transport'] ?? '') === 'cloud';
    } catch (Throwable $e) {
        status_failure(YarboErrors::friendly($e->getMessage()), 'agent', 503, [
            'via' => 'agent',
        ]);
    }
}

if (!($ping['ok'] ?? false) || !$isCloudAgent) {
    status_failure(
        'Yarbo cloud agent is not running. Set account email/password in Settings → Yarbo, then Save.',
        'agent',
        503,
        ['via' => 'agent'],
    );
}

if (empty($ping['connected'])) {
    status_failure(
        YarboErrors::MSG_MQTT_FAILED,
        'mqtt',
        503,
        [
            'via' => 'agent',
            'transient' => true,
        ],
    );
}

try {
    $result = $agent->telemetry(4.0, true);
    status_from_agent($result);

    $error = (string) ($result['error'] ?? 'telemetry timeout');
    $lower = strtolower($error);
    $transient = (bool) ($result['transient'] ?? false)
        || str_contains($lower, 'data_feedback')
        || str_contains($lower, 'get_device_msg')
        || str_contains($lower, 'telemetry timeout')
        || str_contains($lower, 'timeout');
    status_failure(
        YarboErrors::friendly($error),
        'telemetry',
        504,
        [
            'via' => 'agent',
            'transient' => $transient,
        ],
    );
} catch (Throwable $e) {
    status_failure(friendly_error($e), 'telemetry', 504, [
        'via' => 'agent',
        'transient' => false,
    ]);
}
