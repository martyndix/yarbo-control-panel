<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Read a python-matter-server storage folder without talking to Docker or the agent.
 */
final class YarboMatterFabric
{
    private const ON_OFF = 6;
    private const LEVEL_CONTROL = 8;
    private const COLOR_CONTROL = 0x0300;
    private const DESCRIPTOR = 29;
    private const BASIC_INFO = 40;
    private const BRIDGED_BASIC = 57;
    private const FIXED_LABEL = 64;
    private const USER_LABEL = 65;
    private const ATTR_ON_OFF = 0;
    private const ATTR_CURRENT_LEVEL = 0;
    private const ATTR_DEVICE_TYPES = 0;
    private const ATTR_SERVER_LIST = 1;
    private const ATTR_VENDOR_NAME = 1;
    private const ATTR_PRODUCT_NAME = 3;
    private const ATTR_NODE_LABEL = 5;
    private const DEVTYPE_AGGREGATOR = 0x000E;
    private const DEVTYPE_ONOFF_LIGHT = 0x0100;
    private const DEVTYPE_DIMMABLE_LIGHT = 0x0101;
    private const DEVTYPE_COLOR_LIGHT = 0x0102;
    private const DEVTYPE_CT_LIGHT = 0x010C;
    private const DEVTYPE_EXTENDED_COLOR_LIGHT = 0x010D;

    /**
     * @return list<array<string, mixed>>
     */
    public static function devicesFromDirectory(string $dir): array
    {
        return self::flatten(self::nodesFromDisk($dir));
    }

    /**
     * @return list<string>
     */
    public static function storageFileNames(string $dir): array
    {
        return array_map(static fn (string $path): string => basename($path), self::storageFiles($dir));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function nodesFromDisk(string $dir): array
    {
        $found = [];
        foreach (self::storageFiles($dir) as $path) {
            $name = strtolower(basename($path));
            if ($name === 'chip.json' || $name === 'chip.json.backup') {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            foreach (self::nodesFromResult($data) as $row) {
                $nodeId = (int) ($row['node_id'] ?? $row['nodeId'] ?? 0);
                if ($nodeId <= 0) {
                    continue;
                }
                $prev = $found[$nodeId] ?? null;
                $prevAttrs = is_array($prev) && is_array($prev['attributes'] ?? null) ? $prev['attributes'] : [];
                $newAttrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
                if ($prev === null || count($newAttrs) > count($prevAttrs)) {
                    $found[$nodeId] = $row;
                }
            }
        }
        ksort($found, SORT_NUMERIC);

        return array_values($found);
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    public static function nodesFromResult(mixed $raw): array
    {
        if (is_array($raw) && array_is_list($raw)) {
            $out = [];
            foreach ($raw as $node) {
                if (is_array($node)) {
                    $out[] = $node;
                }
            }

            return $out;
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach (['nodes', 'result', 'data'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $nested = $raw[$key];
            if (is_array($nested) && array_is_list($nested)) {
                return self::nodesFromResult($nested);
            }
            if (is_array($nested)) {
                $out = [];
                foreach ($nested as $node) {
                    if (is_array($node)) {
                        $out[] = $node;
                    }
                }

                return $out;
            }
        }
        if (isset($raw['node_id']) || isset($raw['nodeId']) || isset($raw['attributes'])) {
            return [$raw];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $nodes): array
    {
        $devices = [];
        foreach ($nodes as $node) {
            $nodeId = (int) ($node['node_id'] ?? $node['nodeId'] ?? 0);
            $available = (bool) ($node['available'] ?? true);
            $isBridge = (bool) ($node['is_bridge'] ?? $node['isBridge'] ?? false);
            $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            $vendor = self::attrStr($attributes, 0, self::BASIC_INFO, self::ATTR_VENDOR_NAME);
            $product = self::attrStr($attributes, 0, self::BASIC_INFO, self::ATTR_PRODUCT_NAME);
            $nodeName = self::attrStr($attributes, 0, self::BASIC_INFO, self::ATTR_NODE_LABEL);
            $source = $nodeName !== '' ? $nodeName : ($product !== '' ? $product : ($vendor !== '' ? $vendor : ('Matter node ' . $nodeId)));
            $epIds = self::endpointIds($attributes);
            $before = count($devices);
            $sorted = $epIds;
            sort($sorted, SORT_NUMERIC);
            foreach ($sorted as $endpoint) {
                if ($endpoint === 0) {
                    continue;
                }
                $onVal = self::attrRaw($attributes, $endpoint, self::ON_OFF, self::ATTR_ON_OFF);
                if ($onVal === null && !self::endpointHasCluster($attributes, $endpoint, self::ON_OFF)) {
                    continue;
                }
                $types = self::attrRaw($attributes, $endpoint, self::DESCRIPTOR, self::ATTR_DEVICE_TYPES);
                $typeIds = self::deviceTypeIds($types);
                $kind = self::deviceKind($types);
                if ($kind === 'other') {
                    if (in_array(self::DEVTYPE_AGGREGATOR, $typeIds, true)
                        && !self::endpointHasCluster($attributes, $endpoint, self::ON_OFF)) {
                        continue;
                    }
                    $kind = self::fallbackKind($attributes, $endpoint);
                }
                $level = self::attrNum($attributes, $endpoint, self::LEVEL_CONTROL, self::ATTR_CURRENT_LEVEL);
                $brightness = null;
                if ($level !== null && $level >= 0) {
                    $brightness = (int) round($level * 100 / 254);
                }
                $devices[] = [
                    'id' => $nodeId . ':' . $endpoint,
                    'node_id' => $nodeId,
                    'endpoint' => $endpoint,
                    'name' => self::endpointName($attributes, $endpoint, $vendor, $product),
                    'kind' => $kind,
                    'vendor' => $vendor,
                    'product' => $product,
                    'source' => $source,
                    'bridge' => $isBridge || count($epIds) > 3,
                    'on' => (bool) $onVal,
                    'brightness' => $brightness,
                    'dimmable' => self::attrRaw($attributes, $endpoint, self::LEVEL_CONTROL, self::ATTR_CURRENT_LEVEL) !== null,
                    'available' => $available,
                ] + self::colorPayload($attributes, $endpoint, $typeIds, $kind);
            }
            if (count($devices) === $before && $nodeId > 0) {
                $devices[] = [
                    'id' => $nodeId . ':1',
                    'node_id' => $nodeId,
                    'endpoint' => 1,
                    'name' => $source,
                    'kind' => 'light',
                    'vendor' => $vendor,
                    'product' => $product,
                    'source' => $source,
                    'bridge' => true,
                    'on' => false,
                    'brightness' => null,
                    'dimmable' => false,
                    'available' => $available,
                    'colorable' => true,
                    'color_hs' => true,
                    'color_xy' => true,
                    'color_ct' => true,
                    'color_hex' => '',
                ];
            }
        }
        usort(
            $devices,
            static function (array $a, array $b): int {
                $source = strcasecmp((string) ($a['source'] ?? ''), (string) ($b['source'] ?? ''));
                if ($source !== 0) {
                    return $source;
                }
                $name = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                if ($name !== 0) {
                    return $name;
                }

                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }
        );

        return $devices;
    }

    /**
     * @return list<string>
     */
    private static function storageFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $handle = opendir($dir);
        if ($handle === false) {
            return [];
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $lower = strtolower($name);
            if (str_ends_with($lower, '.json') || str_ends_with($lower, '.json.backup')) {
                $files[] = $path;
            }
        }
        closedir($handle);
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<int>
     */
    private static function endpointIds(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $_) {
            $parsed = self::parseAttrPath($key);
            if ($parsed !== null) {
                $out[$parsed[0]] = true;
                continue;
            }
            $first = explode('/', (string) $key, 2)[0];
            if (is_numeric($first)) {
                $out[(int) $first] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    /**
     * @param mixed $types
     * @return list<int>
     */
    private static function deviceTypeIds(mixed $types): array
    {
        if (is_array($types) && !array_is_list($types)) {
            $types = $types['value'] ?? $types['0'] ?? [$types];
        }
        if (is_int($types) || is_float($types)) {
            $types = [(int) $types];
        }
        if (!is_array($types)) {
            return [];
        }
        $ids = [];
        foreach ($types as $item) {
            $raw = null;
            if (is_int($item) || is_float($item)) {
                $raw = $item;
            } elseif (is_array($item)) {
                $raw = $item['0'] ?? $item[0] ?? $item['deviceType'] ?? $item['device_type'] ?? $item['DeviceType'] ?? $item['type'] ?? null;
            }
            if ($raw !== null && is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $types
     */
    private static function deviceKind(mixed $types): string
    {
        $ids = self::deviceTypeIds($types);
        foreach ($ids as $id) {
            if ($id === 0x0301) {
                return 'heater';
            }
        }
        foreach ($ids as $id) {
            if ($id === 0x010A || $id === 0x010B) {
                return 'plug';
            }
        }
        foreach ($ids as $id) {
            if ($id === 0x0103 || $id === 0x010F) {
                return 'switch';
            }
        }
        foreach ($ids as $id) {
            if (in_array($id, [
                self::DEVTYPE_ONOFF_LIGHT,
                self::DEVTYPE_DIMMABLE_LIGHT,
                self::DEVTYPE_COLOR_LIGHT,
                self::DEVTYPE_CT_LIGHT,
                self::DEVTYPE_EXTENDED_COLOR_LIGHT,
            ], true)) {
                return 'light';
            }
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function fallbackKind(array $attributes, int $endpoint): string
    {
        if (self::endpointHasCluster($attributes, $endpoint, self::COLOR_CONTROL)
            || self::endpointHasCluster($attributes, $endpoint, self::LEVEL_CONTROL)) {
            return 'light';
        }

        return 'switch';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function attrStr(array $attributes, int $endpoint, int $cluster, int $attr): string
    {
        $val = $attributes[self::attrKey($endpoint, $cluster, $attr)] ?? null;
        if (is_string($val) && trim($val) !== '') {
            return trim($val);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function attrRaw(array $attributes, int $endpoint, int $cluster, int $attr): mixed
    {
        $key = self::attrKey($endpoint, $cluster, $attr);
        if (array_key_exists($key, $attributes)) {
            return $attributes[$key];
        }
        foreach ($attributes as $stored => $val) {
            if (self::parseAttrPath($stored) === [$endpoint, $cluster, $attr]) {
                return $val;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function attrNum(array $attributes, int $endpoint, int $cluster, int $attr): ?float
    {
        $val = self::attrRaw($attributes, $endpoint, $cluster, $attr);
        if ($val === null || is_bool($val)) {
            return null;
        }
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }
        if (is_array($val)) {
            $inner = $val['value'] ?? $val['0'] ?? null;
            if (is_int($inner) || is_float($inner)) {
                return (float) $inner;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function endpointHasCluster(array $attributes, int $endpoint, int $cluster): bool
    {
        foreach ($attributes as $key => $_) {
            $parsed = self::parseAttrPath($key);
            if ($parsed !== null && $parsed[0] === $endpoint && $parsed[1] === $cluster) {
                return true;
            }
        }

        return in_array($cluster, self::listInts(self::attrRaw($attributes, $endpoint, self::DESCRIPTOR, self::ATTR_SERVER_LIST)), true);
    }

    /**
     * @param mixed $val
     * @return list<int>
     */
    private static function listInts(mixed $val): array
    {
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $item) {
            $raw = null;
            if (is_int($item) || is_float($item)) {
                $raw = $item;
            } elseif (is_array($item)) {
                $raw = $item['0'] ?? $item[0] ?? $item['value'] ?? $item['clusterId'] ?? null;
            }
            if ($raw !== null && is_numeric($raw)) {
                $out[] = (int) $raw;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function endpointName(array $attributes, int $endpoint, string $vendor, string $product): string
    {
        foreach ([self::BRIDGED_BASIC, self::BASIC_INFO] as $cluster) {
            $label = self::attrStr($attributes, $endpoint, $cluster, self::ATTR_NODE_LABEL);
            if ($label !== '') {
                return substr($label, 0, 48);
            }
            $productEp = self::attrStr($attributes, $endpoint, $cluster, self::ATTR_PRODUCT_NAME);
            if ($productEp !== '') {
                return substr($productEp, 0, 48);
            }
        }
        foreach ([self::FIXED_LABEL, self::USER_LABEL] as $cluster) {
            $listed = self::labelListText(self::attrRaw($attributes, $endpoint, $cluster, 0));
            if ($listed !== '') {
                return substr($listed, 0, 48);
            }
        }
        $base = $product !== '' ? $product : ($vendor !== '' ? $vendor : 'Device');

        return substr($base . ' ' . $endpoint, 0, 48);
    }

    /**
     * @param mixed $val
     */
    private static function labelListText(mixed $val): string
    {
        if (!is_array($val)) {
            return '';
        }
        $parts = [];
        foreach ($val as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = $item['1'] ?? $item['value'] ?? $item['0'] ?? $item['label'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<int> $typeIds
     * @return array<string, mixed>
     */
    private static function colorPayload(array $attributes, int $endpoint, array $typeIds, string $kind): array
    {
        $hasCc = self::endpointHasCluster($attributes, $endpoint, self::COLOR_CONTROL);
        $isLight = $kind === 'light' || array_intersect($typeIds, [
            self::DEVTYPE_ONOFF_LIGHT,
            self::DEVTYPE_DIMMABLE_LIGHT,
            self::DEVTYPE_COLOR_LIGHT,
            self::DEVTYPE_CT_LIGHT,
            self::DEVTYPE_EXTENDED_COLOR_LIGHT,
        ]) !== [];
        $colorHs = $hasCc || $isLight;
        $colorXy = $hasCc || $isLight;
        $colorCt = $hasCc || $isLight;

        return [
            'colorable' => $colorHs || $colorXy,
            'color_hs' => $colorHs,
            'color_xy' => $colorXy,
            'color_ct' => $colorCt,
            'color_hex' => '',
        ];
    }

    private static function attrKey(int $endpoint, int $cluster, int $attr): string
    {
        return $endpoint . '/' . $cluster . '/' . $attr;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function parseAttrPath(mixed $key): ?array
    {
        $parts = explode('/', str_replace(' ', '', (string) $key));
        if (count($parts) < 3) {
            return null;
        }
        if (!is_numeric($parts[0]) || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }
}
