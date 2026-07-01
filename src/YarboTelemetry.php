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
        $workingState = $raw['StateMSG']['working_state'] ?? null;
        $chargingStatus = $raw['StateMSG']['charging_status'] ?? 0;
        $errorCode = $raw['StateMSG']['error_code'] ?? 0;
        $heading = $raw['RTKMSG']['heading'] ?? null;
        $headType = $raw['HeadMsg']['head_type'] ?? null;

        return [
            'battery'             => $battery !== null ? (int) $battery : null,
            'state'               => $workingState === 1 ? 'active' : 'idle',
            'working_state'       => $workingState !== null ? (int) $workingState : null,
            'charging'            => (int) $chargingStatus > 0,
            'charging_status'     => (int) $chargingStatus,
            'error_code'          => $errorCode,
            'heading'             => $heading !== null ? round((float) $heading, 1) : null,
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
            'updated_at'          => gmdate('c'),
        ];
    }
}
