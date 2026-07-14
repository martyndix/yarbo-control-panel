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
     * @return array<int, array{cmd: string, payload: array<string, mixed>}>
     */
    public static function returnToDockVariants(): array
    {
        return [
            ['cmd' => 'cmd_recharge', 'payload' => []],
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
