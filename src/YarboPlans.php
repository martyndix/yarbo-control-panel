<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboPlans
{
    /**
     * @return list<array{id: string|int, name: string, area_ids: list<string|int>}>
     */
    public static function parseList(?array $response): array
    {
        if ($response === null) {
            return [];
        }

        $data = $response['data'] ?? null;
        $rows = self::extractPlanRows($data, $response);
        $plans = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = self::normalizePlan($row);
            if ($normalized !== null) {
                $plans[] = $normalized;
            }
        }

        return $plans;
    }

    /**
     * @return array{id: string|int, name: string, area_ids: list<string|int>}|null
     */
    public static function normalizePlan(array $raw): ?array
    {
        $id = $raw['id'] ?? $raw['planId'] ?? $raw['plan_id'] ?? null;
        $name = $raw['name'] ?? $raw['planName'] ?? null;
        if ($id === null || $name === null || $name === '') {
            return null;
        }

        $areaIdsRaw = $raw['areaIds'] ?? $raw['area_ids'] ?? [];
        $areaIds = [];
        if (is_array($areaIdsRaw)) {
            foreach ($areaIdsRaw as $areaId) {
                if ($areaId !== null && $areaId !== '') {
                    $areaIds[] = $areaId;
                }
            }
        } elseif ($areaIdsRaw !== null && $areaIdsRaw !== '') {
            $areaIds[] = $areaIdsRaw;
        }

        return [
            'id'       => is_numeric($id) ? (int) $id : (string) $id,
            'name'     => (string) $name,
            'area_ids' => $areaIds,
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function extractPlanRows(mixed $data, array $response): array
    {
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        if (is_array($data)) {
            foreach (['planList', 'plans', 'plan_list', 'data'] as $key) {
                $raw = $data[$key] ?? null;
                if (is_array($raw) && $raw !== []) {
                    return array_is_list($raw) ? $raw : [$raw];
                }
            }
        }

        $top = $response['data'] ?? null;
        if (is_array($top) && array_is_list($top)) {
            return $top;
        }

        return [];
    }
}
