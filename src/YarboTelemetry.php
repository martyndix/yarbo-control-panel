<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboTelemetry
{
    private const HEAD_TYPES = [
        0  => 'None',
        1  => 'Snow Blower',
        2  => 'Leaf Blower',
        3  => 'Lawn Mower',
        4  => 'Smart Cover',
        5  => 'Lawn Mower Pro',
        99 => 'Trimmer',
    ];

    /** White work lights only — body/tail RGB accents stay decorative and mislead the UI. */
    private const WHITE_LED_CHANNELS = [
        'led_head',
        'led_left_w',
        'led_right_w',
    ];

    public static function parse(array $raw, ?array $cellTemps = null): array
    {
        $battery = $raw['BatteryMSG']['capacity'] ?? null;
        $batteryMsg = is_array($raw['BatteryMSG'] ?? null) ? $raw['BatteryMSG'] : [];
        $stateMsg = is_array($raw['StateMSG'] ?? null) ? $raw['StateMSG'] : [];
        $workingState = $raw['StateMSG']['working_state'] ?? null;
        $chargingStatus = $raw['StateMSG']['charging_status'] ?? 0;
        $errorCode = $raw['StateMSG']['error_code'] ?? 0;
        $rtkMsg = is_array($raw['RTKMSG'] ?? null) ? $raw['RTKMSG'] : [];
        $odomMsg = is_array($raw['CombinedOdom'] ?? null) ? $raw['CombinedOdom'] : [];
        $heading = self::resolveCompassHeading($rtkMsg, $odomMsg);
        $netMsg = is_array($raw['NetMSG'] ?? null) ? $raw['NetMSG'] : [];
        $headType = $raw['HeadMsg']['head_type'] ?? null;
        $roverGngga = (string) ($raw['rtk_base_data']['rover']['gngga'] ?? '');
        [$latitude, $longitude, $altitude, $fixQuality] = self::parseGngga($roverGngga);
        $netTypeRaw = $raw['net_type'] ?? ($netMsg['net_type'] ?? null);
        $halowStatusRaw = $raw['halow_status'] ?? ($netMsg['halow_status'] ?? null);
        $routePriority = $raw['route_priority'] ?? ($netMsg['route_priority'] ?? null);
        $moduleStatus = $raw['net_module_status'] ?? ($netMsg['net_module_status'] ?? null);
        $connectionType = self::connectionTypeName($netTypeRaw, $halowStatusRaw, $routePriority);
        $connectionStatus = self::connectionStatusName($netTypeRaw, $halowStatusRaw, $moduleStatus, $connectionType);
        $batteryTempParsed = self::parseBatteryTemperature($batteryMsg, $cellTemps);
        $batteryTemp = $batteryTempParsed['temperature_c'];
        $batteryTempSource = $batteryTempParsed['temperature_source'];
        $batteryCells = $batteryTempParsed['cells'];
        $wirelessChargeVoltage = self::firstNumeric(
            $batteryMsg['wireless_charge_voltage'] ?? null,
            $raw['wireless_charge_voltage'] ?? null
        );
        $wirelessChargeCurrent = self::firstNumeric(
            $batteryMsg['wireless_charge_current'] ?? null,
            $raw['wireless_charge_current'] ?? null
        );
        $bodyMsg = is_array($raw['BodyMsg'] ?? null) ? $raw['BodyMsg'] : [];
        $abnormalMsg = is_array($raw['abnormal_msg'] ?? null) ? $raw['abnormal_msg'] : [];
        $motorInfo = is_array($raw['motor_info'] ?? null) ? $raw['motor_info'] : [];
        $powerFault = self::firstNumeric(
            $bodyMsg['power_fault_state'] ?? null,
            $abnormalMsg['power_fault'] ?? null
        );
        $powerFaultInt = $powerFault !== null ? (int) $powerFault : 0;
        $motionMotor = self::firstNumeric($motorInfo['motion_motor'] ?? null);
        $selfCheck = self::firstNumeric($stateMsg['self_check_status'] ?? null);
        $batteryInt = $battery !== null ? (int) $battery : null;
        $chargingStatusInt = (int) $chargingStatus;
        $batteryStatus = isset($batteryMsg['status']) && is_numeric($batteryMsg['status'])
            ? (int) $batteryMsg['status']
            : null;
        $chargingLabel = self::chargingDisplay($chargingStatusInt, $batteryInt);
        $displayBattery = ($chargingLabel === 'Full' && $batteryInt !== null && $batteryInt >= 95)
            ? 100
            : $batteryInt;
        $planningPaused = self::isPausedFlag($stateMsg['planning_paused'] ?? 0);
        $planRunning = self::isActiveJobFlag($stateMsg['on_going_planning'] ?? 0);
        $returningToDock = self::isActiveJobFlag($stateMsg['on_going_recharging'] ?? 0);
        $rain = self::parseRain($raw, $stateMsg);
        $displayState = self::displayState(
            $workingState !== null ? (int) $workingState : null,
            $chargingLabel,
            $planRunning,
            $planningPaused,
            $returningToDock,
            $rain['rain_detected'],
        );
        $driveBlockedReason = null;
        if ($chargingStatusInt > 0 && $chargingLabel !== 'Full') {
            $driveBlockedReason = 'Robot is charging — unplug / leave the charger before manual drive.';
        } elseif ($powerFaultInt > 0) {
            $driveBlockedReason = 'Robot reports power_fault=' . $powerFaultInt
                . ' — chassis/buzzer may stay locked (check Yarbo app / reboot).';
        }

        return [
            'battery'             => $displayBattery,
            'battery_raw'         => $batteryInt,
            'state'               => $displayState,
            'working_state'       => $workingState !== null ? (int) $workingState : null,
            // HA: charging_status 2 = charging/docked. Do not use BodyMsg.recharge_state —
            // on some firmware it stays non-zero even when not on a pad/cable.
            'charging'            => $chargingStatusInt > 0 && $chargingLabel !== 'Full',
            'charging_label'      => $chargingLabel,
            'charging_status'     => $chargingStatusInt,
            'battery_status'      => $batteryStatus,
            'recharge_state'      => self::parseRechargeState($raw),
            'on_charge_pad'       => false,
            'drive_blocked_reason'=> $driveBlockedReason,
            'power_fault'         => $powerFaultInt,
            'motion_motor'        => $motionMotor !== null ? (int) $motionMotor : null,
            'self_check_status'   => $selfCheck !== null ? (int) $selfCheck : null,
            'speaker_state'       => isset($abnormalMsg['speaker_state'])
                ? (int) $abnormalMsg['speaker_state']
                : null,
            'error_code'          => $errorCode,
            'heading'             => $heading,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'altitude'            => $altitude,
            'fix_quality'         => $fixQuality,
            'gps_valid'           => $fixQuality > 0 && $latitude !== null && $longitude !== null,
            'position'            => [
                'x' => isset($odomMsg['x']) ? round((float) $odomMsg['x'], 3) : null,
                'y' => isset($odomMsg['y']) ? round((float) $odomMsg['y'], 3) : null,
            ],
            'head_type'           => $headType !== null ? (int) $headType : null,
            'head_type_name'      => self::HEAD_TYPES[(int) $headType] ?? 'Unknown',
            'planning_paused'     => $planningPaused,
            // car_controller is often false even when commands work; prefer working_state.
            'car_controller'      => (bool) ($raw['StateMSG']['car_controller'] ?? false),
            'machine_controller'  => isset($raw['StateMSG']['machine_controller'])
                ? (int) $raw['StateMSG']['machine_controller']
                : null,
            'control_awake'       => (int) ($raw['StateMSG']['working_state'] ?? 0) === 1,
            // joy_usb/joy_state are hub health flags — not a plugged-in gamepad.
            'joy_connected'       => false,
            'lights_on'           => self::parseLightsOn($raw),
            'returning_to_dock'   => $returningToDock,
            'plan_running'        => $planRunning,
            'rain_detected'       => $rain['rain_detected'],
            'rain_sensor'         => $rain['rain_sensor'],
            'rain_sensor_data'    => $rain['rain_sensor_data'],
            'rain_fields'         => $rain['rain_fields'],
            'plan_status'         => [
                'plan_id' => $stateMsg['plan_id'] ?? $stateMsg['planId'] ?? null,
                'plan_percent' => isset($stateMsg['plan_percent']) ? (int) $stateMsg['plan_percent'] : (
                    isset($stateMsg['percent']) ? (int) $stateMsg['percent'] : null
                ),
                'plan_name' => $stateMsg['plan_name'] ?? $stateMsg['planName'] ?? null,
                'pause_reason' => $stateMsg['pause_reason'] ?? $stateMsg['pauseReason'] ?? null,
                'error_message' => $stateMsg['error_message'] ?? $stateMsg['errorMessage'] ?? null,
            ],
            'camera_state'        => $raw['camera_state'] ?? null,
            'connection_type'     => $connectionType,
            'connection_status'   => $connectionStatus,
            'network'             => [
                'net_type_raw'      => $netTypeRaw,
                'halow_status'      => $halowStatusRaw,
                'net_module_status' => $moduleStatus,
                'route_priority'    => $routePriority,
                'rtcm_age'          => $raw['rtcm_age'] ?? ($rtkMsg['rtcm_age'] ?? null),
            ],
            'battery_diagnostics' => [
                'temperature_c'          => $batteryTemp !== null ? round($batteryTemp, 1) : null,
                'temperature_source'     => $batteryTempSource,
                'cells'                  => $batteryCells,
                'wireless_charge_voltage' => $wirelessChargeVoltage !== null ? round($wirelessChargeVoltage, 2) : null,
                'wireless_charge_current' => $wirelessChargeCurrent !== null ? round($wirelessChargeCurrent, 2) : null,
            ],
            'rtk_diagnostics' => [
                'rtk_status'  => $rtkMsg['status'] ?? ($raw['rtk_status'] ?? null),
                'heading_dop' => isset($rtkMsg['heading_dop']) ? round((float) $rtkMsg['heading_dop'], 2) : null,
                'heading_status' => isset($rtkMsg['heading_status']) ? (int) $rtkMsg['heading_status'] : null,
                'fix_quality' => $fixQuality,
                'gps_valid'   => $fixQuality > 0 && $latitude !== null && $longitude !== null,
            ],
            'updated_at'          => gmdate('c'),
        ];
    }

    /**
     * Best-effort white-light state from telemetry.
     *
     * Firmware often does not update LedInfoMSG after light_ctrl (HA treats lights as
     * assumed state). Returns null when unknown so the UI must not force Off.
     */
    public static function parseLightsOn(array $raw): ?bool
    {
        $ledInfo = is_array($raw['LedInfoMSG'] ?? null) ? $raw['LedInfoMSG'] : null;
        if ($ledInfo === null) {
            return null;
        }

        $levels = [];
        foreach (self::WHITE_LED_CHANNELS as $channel) {
            if (array_key_exists($channel, $ledInfo) && is_numeric($ledInfo[$channel])) {
                $levels[] = (float) $ledInfo[$channel];
            }
        }

        if ($levels === []) {
            return null;
        }

        // Many firmwares leave white channels at 0 even when lights are on — treat
        // all-zero as unknown rather than definitive Off (UI uses assumed state).
        if (max($levels) > 0) {
            return true;
        }

        return null;
    }

    public static function parseRechargeState(array $raw): ?int
    {
        $body = is_array($raw['BodyMsg'] ?? null) ? $raw['BodyMsg'] : [];
        if (!array_key_exists('recharge_state', $body) || !is_numeric($body['recharge_state'])) {
            return null;
        }

        return (int) $body['recharge_state'];
    }

    /**
     * @return float|null
     */
    private static function findNestedNumeric(array $data, string $key): ?float
    {
        if (array_key_exists($key, $data) && is_numeric($data[$key])) {
            return (float) $data[$key];
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::findNestedNumeric($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $batteryMsg
     * @param array<string, mixed>|null $cellTemps
     * @return array{temperature_c: ?float, temperature_source: ?string, cells: list<array{index: int, label: string, temperature_c: float}>}
     */
    private static function parseBatteryTemperature(array $batteryMsg, ?array $cellTemps): array
    {
        $pool = is_array($cellTemps) ? $cellTemps : [];
        $fromCells = self::temperatureFromMap($pool);
        if ($fromCells['temperature_c'] !== null) {
            return $fromCells;
        }

        return self::temperatureFromMap($batteryMsg);
    }

    /**
     * @param array<string, mixed> $map
     * @return array{temperature_c: ?float, temperature_source: ?string, cells: list<array{index: int, label: string, temperature_c: float}>}
     */
    private static function temperatureFromMap(array $map): array
    {
        if (isset($map['data']) && is_array($map['data'])) {
            $map = $map['data'];
        }

        $cells = self::extractCellTemperatures($map);
        if (self::cellsLookReal($cells)) {
            $avg = self::averageNumeric(...array_column($cells, 'temperature_c'));

            return [
                'temperature_c' => $avg,
                'temperature_source' => 'avg_cells',
                'cells' => $cells,
            ];
        }

        $direct = self::firstNumeric(
            $map['avg'] ?? null,
            $map['avg_temp'] ?? null,
            $map['avg_temp_c'] ?? null,
            $map['temp_avg'] ?? null,
            $map['temperature'] ?? null,
            $map['temp'] ?? null,
            $map['temp_c'] ?? null,
            $map['battery_temp'] ?? null,
            $map['cell_temp'] ?? null
        );
        if ($direct !== null && abs($direct) > 0.5) {
            return ['temperature_c' => $direct, 'temperature_source' => 'direct', 'cells' => []];
        }

        $nested = self::firstNumeric(
            self::findNestedNumeric($map, 'avg_temp'),
            self::findNestedNumeric($map, 'temperature'),
            self::findNestedNumeric($map, 'temp_c'),
            self::findNestedNumeric($map, 'battery_temp'),
            self::findNestedNumeric($map, 'cell_temp')
        );
        if ($nested !== null && abs($nested) > 0.5) {
            return ['temperature_c' => $nested, 'temperature_source' => 'direct', 'cells' => []];
        }

        return ['temperature_c' => null, 'temperature_source' => null, 'cells' => []];
    }

    /**
     * @param array<string, mixed> $map
     * @return list<array{index: int, label: string, temperature_c: float}>
     */
    private static function extractCellTemperatures(array $map): array
    {
        $fromList = self::cellsFromList($map);
        if ($fromList !== []) {
            return $fromList;
        }

        foreach (['temps', 'cell_temps', 'temperature_list', 'battery_cell_temp', 'cells'] as $key) {
            if (isset($map[$key]) && is_array($map[$key])) {
                $fromList = self::cellsFromList($map[$key]);
                if ($fromList !== []) {
                    return $fromList;
                }
            }
        }

        $cells = [];
        for ($i = 1; $i <= 16; $i++) {
            $value = self::firstNumeric(
                $map['temperature' . $i] ?? null,
                $map['temp' . $i] ?? null,
                $map['cell' . $i] ?? null,
                $map['cell_temp' . $i] ?? null,
                $map['t' . $i] ?? null
            );
            if ($value !== null && $value >= -40.0 && $value <= 120.0) {
                $cells[] = [
                    'index' => $i,
                    'label' => 'Cell ' . $i,
                    'temperature_c' => round($value, 1),
                ];
            }
        }

        return $cells;
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<array{index: int, label: string, temperature_c: float}>
     */
    private static function cellsFromList(array $list): array
    {
        if ($list === [] || !array_is_list($list)) {
            return [];
        }

        $cells = [];
        foreach (array_values($list) as $i => $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($n < -40.0 || $n > 120.0) {
                continue;
            }
            $cells[] = [
                'index' => $i + 1,
                'label' => 'Cell ' . ($i + 1),
                'temperature_c' => round($n, 1),
            ];
        }

        return $cells;
    }

    /**
     * @param list<array{index: int, label: string, temperature_c: float}> $cells
     */
    private static function cellsLookReal(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (abs((float) $cell['temperature_c']) > 0.5) {
                return true;
            }
        }

        return false;
    }

    private static function connectionTypeName(mixed $netTypeRaw, mixed $halowStatusRaw, mixed $routePriority = null): string
    {
        if ((int) ($halowStatusRaw ?? 0) > 0) {
            return 'HaLow';
        }

        $value = strtolower((string) $netTypeRaw);
        $named = match ($value) {
            '0', 'wifi', 'wlan' => 'WiFi',
            '1', '4g', 'lte', 'cellular' => '4G',
            '2', 'halow', 'ha_low' => 'HaLow',
            default => null,
        };
        if ($named !== null) {
            return $named;
        }

        return match (self::activeRouteInterface($routePriority)) {
            'hg0' => 'HaLow',
            'wlan0' => 'WiFi',
            'wwan0' => '4G',
            default => 'Unknown',
        };
    }

    private static function connectionStatusName(
        mixed $netTypeRaw,
        mixed $halowStatusRaw,
        mixed $moduleStatusRaw,
        ?string $connectionType = null,
    ): string {
        $module = (int) ($moduleStatusRaw ?? 0);
        if ($module > 0) {
            return 'Connected';
        }
        if ((int) ($halowStatusRaw ?? 0) > 0) {
            return 'Connected';
        }
        if (in_array($connectionType, ['HaLow', 'WiFi', '4G'], true)) {
            return 'Connected';
        }
        if ($netTypeRaw !== null && $netTypeRaw !== '') {
            return 'Degraded';
        }
        return 'Unknown';
    }

    /**
     * Official app shows Fully charged / 100% when docked at high SOC.
     * MQTT capacity often sits around 95% and charging_status stays non-zero on the pad.
     * BatteryMSG.status is not a full-charge flag (it was showing Full at 25%).
     *
     * @return 'No'|'Yes'|'Full'
     */
    private static function chargingDisplay(int $chargingStatus, ?int $capacity): string
    {
        if ($chargingStatus <= 0) {
            return 'No';
        }
        if ($capacity !== null && $capacity >= 95) {
            return 'Full';
        }

        return 'Yes';
    }

    /**
     * working_state 1 is app-awake (lights, leftover after cancel), not mowing.
     * Sit Full on the pad as idle unless rain is detected.
     */
    private static function displayState(
        ?int $workingState,
        string $chargingLabel,
        bool $planRunning,
        bool $paused,
        bool $returning,
        bool $rainDetected,
    ): string {
        if ($rainDetected) {
            return 'rain';
        }

        $offPad = $chargingLabel === 'No';
        if ($returning && $offPad) {
            return 'active';
        }
        if ($planRunning && !$paused && $offPad) {
            return 'active';
        }
        if ($workingState === 1 && $offPad && !$paused) {
            return 'active';
        }

        return 'idle';
    }

    /**
     * True for 1 / true, not leftover WP/REC error strings on on_going_planning.
     */
    private static function isActiveJobFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value > 0;
        }
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            return $trimmed === '1' || $trimmed === 'true' || $trimmed === 'yes' || $trimmed === 'on';
        }

        return false;
    }

    private static function isPausedFlag(mixed $value): bool
    {
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            return $trimmed !== '' && !in_array($trimmed, ['0', 'false', 'no', 'off'], true);
        }

        return self::isActiveJobFlag($value);
    }

    private static function isBinaryLike(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if ($value === 0 || $value === 1 || $value === 0.0 || $value === 1.0) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $stateMsg
     * @return array{
     *     rain_detected: bool,
     *     rain_sensor: ?int,
     *     rain_sensor_data: ?float,
     *     rain_fields: array<string, scalar>
     * }
     */
    private static function parseRain(array $raw, array $stateMsg): array
    {
        $fields = [];
        foreach (self::findRainHints($raw) as $path => $value) {
            if (is_array($value) || $value === null) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $fields[$path] = $value;
            }
        }

        $flag = null;
        $reading = null;
        foreach ($fields as $path => $value) {
            $leaf = strtolower((string) substr((string) strrchr('.' . $path, '.'), 1));
            if (is_numeric($value) && str_contains($leaf, 'data')) {
                $reading = (float) $value;
                continue;
            }
            if (self::isBinaryLike($value)) {
                $on = self::isActiveJobFlag($value);
                if ($flag === null || $on) {
                    $flag = $on ? 1 : 0;
                }
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($reading === null) {
                $reading = $n;
            }
        }

        $detected = $flag === 1 || ($reading !== null && $reading > 0);
        $pauseReason = (string) ($stateMsg['pause_reason'] ?? $stateMsg['pauseReason'] ?? '');
        $errorMessage = (string) ($stateMsg['error_message'] ?? $stateMsg['errorMessage'] ?? '');
        $pausedRaw = $stateMsg['planning_paused'] ?? '';
        $haystack = strtolower($pauseReason . ' ' . $errorMessage . ' ' . (is_string($pausedRaw) ? $pausedRaw : ''));
        if (str_contains($haystack, 'rain')) {
            $detected = true;
        }

        return [
            'rain_detected' => $detected,
            'rain_sensor' => $flag,
            'rain_sensor_data' => $reading,
            'rain_fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function findRainHints(array $data, string $prefix = ''): array
    {
        $found = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (str_contains(strtolower((string) $key), 'rain')) {
                $found[$path] = $value;
            }
            if (is_array($value)) {
                $found += self::findRainHints($value, $path);
            }
        }

        return $found;
    }

    private static function activeRouteInterface(mixed $route): ?string
    {
        if (!is_array($route)) {
            return null;
        }

        $bestIface = null;
        $bestMetric = null;
        foreach ($route as $iface => $metric) {
            if (!is_numeric($metric) || (float) $metric < 0) {
                continue;
            }
            $value = (float) $metric;
            if ($bestMetric === null || $value < $bestMetric) {
                $bestMetric = $value;
                $bestIface = (string) $iface;
            }
        }

        return $bestIface;
    }

    private static function firstNumeric(mixed ...$values): ?float
    {
        foreach ($values as $v) {
            if (is_numeric($v)) {
                return (float) $v;
            }
        }
        return null;
    }

    private static function averageNumeric(mixed ...$values): ?float
    {
        $nums = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $nums[] = (float) $v;
            }
        }
        if ($nums === []) {
            return null;
        }
        return array_sum($nums) / count($nums);
    }

    /**
     * Compass heading for the map line (0° = north, 90° = east).
     *
     * RTKMSG.heading is dual-antenna RTK and often stays at 0 until heading_status
     * is valid. CombinedOdom.phi is the pose yaw and updates as the robot turns.
     *
     * @param array<string, mixed> $rtkMsg
     * @param array<string, mixed> $odomMsg
     */
    private static function resolveCompassHeading(array $rtkMsg, array $odomMsg): ?float
    {
        if (isset($odomMsg['phi']) && is_numeric($odomMsg['phi'])) {
            return round(self::odomPhiToCompassDegrees((float) $odomMsg['phi']), 1);
        }

        if (!isset($rtkMsg['heading']) || !is_numeric($rtkMsg['heading'])) {
            return null;
        }

        $status = $rtkMsg['heading_status'] ?? null;
        $rtkHeading = (float) $rtkMsg['heading'];
        if ($status !== null && (int) $status <= 0 && abs($rtkHeading) < 0.5) {
            return null;
        }

        return round(self::normalizeDegrees($rtkHeading), 1);
    }

    /**
     * CombinedOdom is in the local map frame (X west, Y north).
     * Phi is radians when |phi| ≤ 2π (python-yarbo / SLAM), otherwise degrees.
     * Phi 0 faces +X (west); convert to compass degrees for Leaflet.
     */
    private static function odomPhiToCompassDegrees(float $phi): float
    {
        $phiRad = abs($phi) <= (2 * M_PI + 0.2) ? $phi : deg2rad($phi);

        return self::normalizeDegrees(rad2deg(atan2(-cos($phiRad), sin($phiRad))));
    }

    private static function normalizeDegrees(float $degrees): float
    {
        $degrees = fmod($degrees, 360.0);
        if ($degrees < 0) {
            $degrees += 360.0;
        }
        if ($degrees >= 359.95) {
            return 0.0;
        }

        return $degrees;
    }

    /**
     * Parse NMEA GNGGA/GPGGA sentence into [lat, lon, alt, fixQuality].
     *
     * @return array{0: float|null, 1: float|null, 2: float|null, 3: int}
     */
    private static function parseGngga(string $sentence): array
    {
        if (!str_starts_with($sentence, '$GNGGA') && !str_starts_with($sentence, '$GPGGA')) {
            return [null, null, null, 0];
        }

        $checksumPos = strpos($sentence, '*');
        if ($checksumPos !== false) {
            $sentence = substr($sentence, 0, $checksumPos);
        }

        $parts = explode(',', $sentence);
        if (count($parts) < 10) {
            return [null, null, null, 0];
        }

        $fixQuality = isset($parts[6]) && $parts[6] !== '' ? (int) $parts[6] : 0;
        if ($fixQuality <= 0) {
            return [null, null, null, 0];
        }

        $latitude = null;
        if (($parts[2] ?? '') !== '' && ($parts[3] ?? '') !== '') {
            $rawLat = $parts[2];
            if (strlen($rawLat) >= 4) {
                $latDeg = (float) substr($rawLat, 0, 2);
                $latMin = (float) substr($rawLat, 2);
                $latitude = $latDeg + ($latMin / 60.0);
                if (strtoupper($parts[3]) === 'S') {
                    $latitude = -$latitude;
                }
            }
        }

        $longitude = null;
        if (($parts[4] ?? '') !== '' && ($parts[5] ?? '') !== '') {
            $rawLon = $parts[4];
            if (strlen($rawLon) >= 5) {
                $lonDeg = (float) substr($rawLon, 0, 3);
                $lonMin = (float) substr($rawLon, 3);
                $longitude = $lonDeg + ($lonMin / 60.0);
                if (strtoupper($parts[5]) === 'W') {
                    $longitude = -$longitude;
                }
            }
        }

        $altitude = null;
        if (($parts[9] ?? '') !== '') {
            $altitude = (float) $parts[9];
        }

        if ($latitude === null || $longitude === null) {
            return [null, null, $altitude, $fixQuality];
        }

        return [round($latitude, 7), round($longitude, 7), $altitude, $fixQuality];
    }
}
