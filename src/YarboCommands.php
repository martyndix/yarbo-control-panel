<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Command payload helpers aligned with python-yarbo / home-assistant-yarbo MQTT usage.
 *
 * @see https://github.com/markus-lassfolk/python-yarbo
 * @see https://github.com/markus-lassfolk/home-assistant-yarbo
 */
final class YarboCommands
{
    /**
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function pauseVariants(): array
    {
        return [
            ['cmd' => 'planning_paused', 'payload' => []],
        ];
    }

    /**
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function stopVariants(): array
    {
        return [
            ['cmd' => 'dstop', 'payload' => []],
        ];
    }

    /**
     * Official Data SDK: disable wireless charge, then cmd_recharge with cmd=2.
     * One cmd_recharge publish only (empty {} is ignored on some firmware).
     *
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function returnToDockVariants(): array
    {
        return [
            ['cmd' => 'wireless_charging_cmd', 'payload' => ['cmd' => 0]],
            ['cmd' => 'cmd_recharge', 'payload' => ['cmd' => 2]],
        ];
    }

    /**
     * Single start_plan payload: python-yarbo uses planId, official SDK uses id + percent.
     *
     * @param int|string $planId
     * @return array<string, mixed>
     */
    public static function startPlanPayload(int|string $planId, int $percent): array
    {
        $id = is_numeric($planId) ? (int) $planId : (string) $planId;
        $percent = max(0, min(100, $percent));

        return [
            'planId' => $id,
            'id' => $id,
            'percent' => $percent,
        ];
    }

    /**
     * @param int|string $planId
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function startPlanVariants(int|string $planId, int $percent): array
    {
        return [
            [
                'cmd' => 'start_plan',
                'payload' => self::startPlanPayload($planId, $percent),
            ],
        ];
    }
}
