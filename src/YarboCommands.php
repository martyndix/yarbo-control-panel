<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Command payload helpers aligned with community MQTT usage and the official SDK.
 *
 * @see https://github.com/YarboInc/YarboDataSDK
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
            ['cmd' => 'pause', 'payload' => []],
        ];
    }

    /**
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function stopVariants(): array
    {
        return [
            ['cmd' => 'dstop', 'payload' => []],
            ['cmd' => 'stop', 'payload' => []],
        ];
    }

    /**
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function returnToDockVariants(): array
    {
        return [
            ['cmd' => 'cmd_recharge', 'payload' => []],
            ['cmd' => 'cmd_recharge', 'payload' => ['cmd' => 2]],
        ];
    }

    /**
     * @param int|string $planId
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function startPlanVariants(int|string $planId, int $percent): array
    {
        $id = is_numeric($planId) ? (int) $planId : (string) $planId;

        return [
            [
                'cmd' => 'start_plan',
                'payload' => ['planId' => $id, 'percent' => $percent],
            ],
            [
                'cmd' => 'start_plan',
                'payload' => ['id' => $id, 'percent' => $percent],
            ],
        ];
    }
}
