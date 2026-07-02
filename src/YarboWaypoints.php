<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboWaypoints
{
    private const STORE_FILENAME = 'waypoints.json';

    /**
     * @return array{waypoints: list<array{id: string, name: string, index: int, updated_at: string}>, updated_at: ?string}
     */
    public static function load(): array
    {
        $path = self::storePath();
        if (!is_file($path)) {
            return ['waypoints' => [], 'updated_at' => null];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['waypoints' => [], 'updated_at' => null];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['waypoints' => [], 'updated_at' => null];
        }

        $rows = [];
        foreach ($decoded['waypoints'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = self::normalizeRow($row);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'waypoints'  => $rows,
            'updated_at' => isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : null,
        ];
    }

    /**
     * @return array{id: string, name: string, index: int, updated_at: string}
     */
    public static function add(string $name, int $index): array
    {
        $store = self::load();
        $entry = [
            'id'         => bin2hex(random_bytes(8)),
            'name'       => trim($name),
            'index'      => $index,
            'updated_at' => gmdate('c'),
        ];
        $store['waypoints'][] = $entry;
        self::write($store['waypoints']);

        return $entry;
    }

    /**
     * @return array{id: string, name: string, index: int, updated_at: string}|null
     */
    public static function update(string $id, string $name, int $index): ?array
    {
        $store = self::load();
        $updated = null;

        foreach ($store['waypoints'] as &$row) {
            if ($row['id'] !== $id) {
                continue;
            }
            $row['name'] = trim($name);
            $row['index'] = $index;
            $row['updated_at'] = gmdate('c');
            $updated = $row;
            break;
        }
        unset($row);

        if ($updated === null) {
            return null;
        }

        self::write($store['waypoints']);
        return $updated;
    }

    public static function delete(string $id): bool
    {
        $store = self::load();
        $before = count($store['waypoints']);
        $store['waypoints'] = array_values(array_filter(
            $store['waypoints'],
            static fn (array $row): bool => $row['id'] !== $id
        ));

        if (count($store['waypoints']) === $before) {
            return false;
        }

        self::write($store['waypoints']);
        return true;
    }

    public static function storePath(): string
    {
        return dirname(__DIR__) . '/data/' . self::STORE_FILENAME;
    }

    /**
     * @param list<array{id: string, name: string, index: int, updated_at: string}> $waypoints
     */
    private static function write(array $waypoints): void
    {
        $path = self::storePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'waypoints'  => $waypoints,
            'updated_at' => gmdate('c'),
        ];

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, index: int, updated_at: string}|null
     */
    private static function normalizeRow(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $index = $row['index'] ?? null;

        if ($id === '' || $name === '' || !is_numeric($index)) {
            return null;
        }

        $index = (int) $index;
        if ($index < 0 || $index > 9999) {
            return null;
        }

        return [
            'id'         => $id,
            'name'       => $name,
            'index'      => $index,
            'updated_at' => (string) ($row['updated_at'] ?? gmdate('c')),
        ];
    }
}
