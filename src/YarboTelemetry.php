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

    public static function parse(array $raw): array
    {
        $battery = $raw['BatteryMSG']['capacity'] ?? null;
        $batteryMsg = is_array($raw['BatteryMSG'] ?? null) ? $raw['BatteryMSG'] : [];
        $stateMsg = is_array($raw['StateMSG'] ?? null) ? $raw['StateMSG'] : [];
        $workingState = $raw['StateMSG']['working_state'] ?? null;
        $chargingStatus = $raw['StateMSG']['charging_status'] ?? 0;
        $errorCode = $raw['StateMSG']['error_code'] ?? 0;
        $heading = $raw['RTKMSG']['heading'] ?? null;
        $rtkMsg = is_array($raw['RTKMSG'] ?? null) ? $raw['RTKMSG'] : [];
        $netMsg = is_array($raw['NetMSG'] ?? null) ? $raw['NetMSG'] : [];
        $headType = $raw['HeadMsg']['head_type'] ?? null;
        $roverGngga = (string) ($raw['rtk_base_data']['rover']['gngga'] ?? '');
        [$latitude, $longitude, $altitude, $fixQuality] = self::parseGngga($roverGngga);
        $netTypeRaw = $raw['net_type'] ?? ($netMsg['net_type'] ?? null);
        $halowStatusRaw = $raw['halow_status'] ?? ($netMsg['halow_status'] ?? null);
        $connectionType = self::connectionTypeName($netTypeRaw, $halowStatusRaw);
        $connectionStatus = self::connectionStatusName($netTypeRaw, $halowStatusRaw, $raw['net_module_status'] ?? null);
        $batteryTemp = self::firstNumeric(
            $batteryMsg['temperature'] ?? null,
            $batteryMsg['temp'] ?? null,
            $batteryMsg['temp_c'] ?? null,
            $batteryMsg['battery_temp'] ?? null,
            self::averageNumeric(
                $batteryMsg['temperature1'] ?? null,
                $batteryMsg['temperature2'] ?? null,
                $batteryMsg['temperature3'] ?? null,
                $batteryMsg['temperature4'] ?? null,
                $batteryMsg['temperature5'] ?? null,
                $batteryMsg['temperature6'] ?? null
            )
        );
        $batteryTempSource = self::firstNumeric(
            $batteryMsg['temperature'] ?? null,
            $batteryMsg['temp'] ?? null,
            $batteryMsg['temp_c'] ?? null,
            $batteryMsg['battery_temp'] ?? null
        ) !== null ? 'direct' : (self::averageNumeric(
            $batteryMsg['temperature1'] ?? null,
            $batteryMsg['temperature2'] ?? null,
            $batteryMsg['temperature3'] ?? null,
            $batteryMsg['temperature4'] ?? null,
            $batteryMsg['temperature5'] ?? null,
            $batteryMsg['temperature6'] ?? null
        ) !== null ? 'avg_cells' : null);
        $wirelessChargeVoltage = self::firstNumeric(
            $batteryMsg['wireless_charge_voltage'] ?? null,
            $raw['wireless_charge_voltage'] ?? null
        );
        $wirelessChargeCurrent = self::firstNumeric(
            $batteryMsg['wireless_charge_current'] ?? null,
            $raw['wireless_charge_current'] ?? null
        );

        return [
            'battery'             => $battery !== null ? (int) $battery : null,
            'state'               => $workingState === 1 ? 'active' : 'idle',
            'working_state'       => $workingState !== null ? (int) $workingState : null,
            'charging'            => (int) $chargingStatus > 0,
            'charging_status'     => (int) $chargingStatus,
            'error_code'          => $errorCode,
            'heading'             => $heading !== null ? round((float) $heading, 1) : null,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'altitude'            => $altitude,
            'fix_quality'         => $fixQuality,
            'gps_valid'           => $fixQuality > 0 && $latitude !== null && $longitude !== null,
            'position'            => [
                'x' => isset($raw['CombinedOdom']['x']) ? round((float) $raw['CombinedOdom']['x'], 3) : null,
                'y' => isset($raw['CombinedOdom']['y']) ? round((float) $raw['CombinedOdom']['y'], 3) : null,
            ],
            'head_type'           => $headType !== null ? (int) $headType : null,
            'head_type_name'      => self::HEAD_TYPES[(int) $headType] ?? 'Unknown',
            'planning_paused'     => (bool) ($raw['StateMSG']['planning_paused'] ?? 0),
            'returning_to_dock'   => (bool) ($raw['StateMSG']['on_going_recharging'] ?? 0),
            'plan_running'        => (bool) ($raw['StateMSG']['on_going_planning'] ?? 0),
            'camera_state'        => $raw['camera_state'] ?? null,
            'connection_type'     => $connectionType,
            'connection_status'   => $connectionStatus,
            'network'             => [
                'net_type_raw'      => $netTypeRaw,
                'halow_status'      => $halowStatusRaw,
                'net_module_status' => $raw['net_module_status'] ?? ($netMsg['net_module_status'] ?? null),
                'route_priority'    => $raw['route_priority'] ?? ($netMsg['route_priority'] ?? null),
                'rtcm_age'          => $raw['rtcm_age'] ?? ($rtkMsg['rtcm_age'] ?? null),
            ],
            'battery_diagnostics' => [
                'temperature_c'          => $batteryTemp !== null ? round($batteryTemp, 1) : null,
                'temperature_source'     => $batteryTempSource,
                'wireless_charge_voltage' => $wirelessChargeVoltage !== null ? round($wirelessChargeVoltage, 2) : null,
                'wireless_charge_current' => $wirelessChargeCurrent !== null ? round($wirelessChargeCurrent, 2) : null,
            ],
            'rtk_diagnostics' => [
                'rtk_status'  => $rtkMsg['status'] ?? ($raw['rtk_status'] ?? null),
                'heading_dop' => isset($rtkMsg['heading_dop']) ? round((float) $rtkMsg['heading_dop'], 2) : null,
                'fix_quality' => $fixQuality,
                'gps_valid'   => $fixQuality > 0 && $latitude !== null && $longitude !== null,
            ],
            'updated_at'          => gmdate('c'),
        ];
    }

    private static function connectionTypeName(mixed $netTypeRaw, mixed $halowStatusRaw): string
    {
        if ((int) ($halowStatusRaw ?? 0) > 0) {
            return 'HaLow';
        }

        $value = strtolower((string) $netTypeRaw);
        return match ($value) {
            '0', 'wifi', 'wlan' => 'WiFi',
            '1', '4g', 'lte', 'cellular' => '4G',
            '2', 'halow', 'ha_low' => 'HaLow',
            default => 'Unknown',
        };
    }

    private static function connectionStatusName(mixed $netTypeRaw, mixed $halowStatusRaw, mixed $moduleStatusRaw): string
    {
        $module = (int) ($moduleStatusRaw ?? 0);
        if ($module > 0) {
            return 'Connected';
        }
        if ((int) ($halowStatusRaw ?? 0) > 0) {
            return 'Connected';
        }
        if ($netTypeRaw !== null && $netTypeRaw !== '') {
            return 'Degraded';
        }
        return 'Unknown';
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
