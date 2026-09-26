<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMapBackup
{
    public const LIST_CMD = 'get_all_map_backup';
    public const FETCH_CMD = 'get_map_buckup_from_id';
    public const RECOVERY_CMD = 'map_recovery';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function lastPath(): string
    {
        return $this->projectRoot . '/data/map-backup-last.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function listAndFetch(YarboMqtt $client, mixed $backupId = null, ?YarboCloud $cloud = null, string $serial = ''): array
    {
        $listEnvelope = $client->requestDataFeedback(self::LIST_CMD, [], 8.0, false);
        $via = 'local';
        $cloudError = null;
        if ($listEnvelope === null && $cloud !== null && $serial !== '') {
            $raw = $cloud->command(self::LIST_CMD, $serial, [], 25.0);
            $listEnvelope = $this->envelopeFromCloud($raw, self::LIST_CMD);
            if ($listEnvelope !== null) {
                $via = 'cloud';
            } else {
                $cloudError = (string) ($raw['error'] ?? '');
            }
        }
        if ($listEnvelope === null) {
            $hint = $cloudError !== null && $cloudError !== ''
                ? $cloudError
                : 'Map backup/restore uses Yarbo cloud MQTT, not the LAN broker. Enable Settings → cloud fallback with the same account as the app, then try again.';
            return ['ok' => false, 'error' => $hint];
        }

        $listData = self::envelopeData($listEnvelope);
        $entries = self::extractBackupEntries($listData);
        $chosenId = $backupId !== null && $backupId !== '' ? $backupId : ($entries[0]['id'] ?? null);
        $fromList = self::locateMapFromList($listData, $chosenId);

        $fetched = $fromList['map'] !== null ? $fromList : null;
        $fetchEnvelope = $fromList['map'] !== null ? $listEnvelope : null;
        $fetchPayloadUsed = null;
        $usedFetch = false;
        if ($chosenId !== null) {
            foreach (self::fetchPayloads($chosenId) as $payload) {
                $candidate = $via === 'cloud' && $cloud !== null && $serial !== ''
                    ? $this->envelopeFromCloud($cloud->command(self::FETCH_CMD, $serial, $payload, 25.0), self::FETCH_CMD)
                    : $client->requestDataFeedback(self::FETCH_CMD, $payload, 20.0, false);
                if ($candidate === null) {
                    continue;
                }
                $located = self::locateMap(self::envelopeData($candidate));
                if ($located['map'] !== null) {
                    $fetched = $located;
                    $fetchEnvelope = $candidate;
                    $fetchPayloadUsed = $payload;
                    $usedFetch = true;
                    break;
                }
            }
        }

        $compatible = is_array($fetched) && YarboMap::isAppMap($fetched['map'] ?? []);
        $featureCollection = null;
        if ($compatible) {
            $normalized = YarboMap::normalize(['get_map' => ['data' => $fetched['map']]]);
            $featureCollection = $normalized['feature_collection'] ?? null;
            $this->persist([
                'saved_at' => gmdate('c'),
                'backup_id' => $chosenId,
                'map' => $fetched['map'],
                'map_path' => $usedFetch ? $fetched['path'] : null,
                'recovery_payload' => $usedFetch
                    ? self::envelopeData($fetchEnvelope)
                    : $fetched['map'],
            ]);
        }

        return [
            'ok' => true,
            'command' => self::LIST_CMD,
            'list_keys' => array_keys($listData),
            'list_state' => $listEnvelope['state'] ?? null,
            'backups' => $entries,
            'selected_id' => $chosenId,
            'fetch_command' => self::FETCH_CMD,
            'fetch_payload' => $fetchPayloadUsed,
            'fetch_state' => is_array($fetchEnvelope) ? ($fetchEnvelope['state'] ?? null) : null,
            'via' => $via,
            'compatible' => $compatible,
            'summary' => $compatible ? self::publicSummary($fetched['map']) : null,
            'geojson' => $featureCollection,
            'message' => $compatible
                ? sprintf(
                    'Backup extracted via %s (%s). Edit these zones, then Save to robot while docked.',
                    $via,
                    self::formatCounts(self::publicSummary($fetched['map'])['counts'])
                )
                : 'Backup list came back, but no drawable get_map blob was inside it. Save stays off.',
        ];
    }

    /**
     * @param array{type?: string, features?: array<int, mixed>} $collection
     * @return array<string, mixed>
     */
    public function restoreDraft(
        YarboMqtt $client,
        array $collection,
        bool $confirm,
        ?YarboCloud $cloud = null,
        string $serial = '',
    ): array
    {
        if (!$confirm) {
            return ['ok' => false, 'error' => 'confirm=true is required'];
        }

        $stored = $this->loadPersisted();
        if ($stored === null || !YarboMap::isAppMap($stored['map'] ?? [])) {
            return ['ok' => false, 'error' => 'Load map backups first (the extracted blob must match get_map).'];
        }

        $safety = $this->safetyCheck($client);
        if (($safety['ok'] ?? false) !== true) {
            return $safety;
        }

        $encoded = YarboMap::encodeDraft($stored['map'], $collection);
        if (!($encoded['ok'] ?? false) || !isset($encoded['map'])) {
            $live = $this->loadLiveMap();
            if (is_array($live)) {
                $retry = YarboMap::encodeDraft($live, $collection);
                if (($retry['ok'] ?? false) && isset($retry['map'])) {
                    $encoded = $retry;
                }
            }
        }
        if (!($encoded['ok'] ?? false) || !isset($encoded['map'])) {
            return [
                'ok' => false,
                'error' => self::encodeErrorMessage(
                    $encoded['errors'] ?? ['Could not encode the draft'],
                    $stored['map'],
                    $collection
                ),
            ];
        }

        $payload = $this->payloadWithMap($stored, $encoded['map']);
        $restoreFile = $this->writeRestoreCopy($stored);

        $ack = $client->requestDataFeedback(self::RECOVERY_CMD, $payload, 12.0, false);
        $via = 'local';
        if ($ack === null && $cloud !== null && $serial !== '') {
            $ack = $this->envelopeFromCloud(
                $cloud->command(self::RECOVERY_CMD, $serial, $payload, 40.0),
                self::RECOVERY_CMD
            );
            if ($ack !== null) {
                $via = 'cloud';
            }
        }
        if ($ack === null) {
            return [
                'ok' => false,
                'error' => 'map_recovery did not reply on LAN or cloud. Original backup is on the Pi: ' . basename($restoreFile),
                'restore_file' => basename($restoreFile),
            ];
        }

        $readback = $client->requestDataFeedback('get_map', [], 25.0, false);
        $readMap = $readback !== null ? self::locateMap(self::envelopeData($readback))['map'] : null;
        $delta = is_array($readMap) ? YarboMap::maxRangeDelta($encoded['map'], $readMap) : null;
        $verified = $delta !== null && $delta <= 0.05;

        if (!$verified) {
            if ($via === 'cloud' && $cloud !== null && $serial !== '') {
                $cloud->command(self::RECOVERY_CMD, $serial, $stored['recovery_payload'], 40.0);
            } else {
                $client->requestDataFeedback(self::RECOVERY_CMD, $stored['recovery_payload'], 40.0, false);
            }
        }

        return [
            'ok' => $verified,
            'via' => $via,
            'recovery_state' => $ack['state'] ?? null,
            'encode_delta_m' => $encoded['max_delta_m'] ?? null,
            'readback_delta_m' => $delta,
            'verified' => $verified,
            'restore_file' => basename($restoreFile),
            'message' => $verified
                ? 'Robot map matches the draft (within 5 cm). Check it in the official app.'
                : 'Read-back did not match. Restored the original backup from the Pi copy.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastSummary(): array
    {
        $stored = $this->loadPersisted();
        if ($stored === null) {
            return ['ok' => true, 'loaded' => false, 'compatible' => false];
        }

        return [
            'ok' => true,
            'loaded' => true,
            'compatible' => YarboMap::isAppMap($stored['map'] ?? []),
            'backup_id' => $stored['backup_id'] ?? null,
            'saved_at' => $stored['saved_at'] ?? null,
            'summary' => self::publicSummary($stored['map'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function envelopeData(array $envelope): array
    {
        $data = YarboCodec::decodePayloadField($envelope['data'] ?? null);
        if ($data === []) {
            $data = YarboCodec::decodePayloadField($envelope);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function envelopeFromCloud(array $raw, string $cmd): ?array
    {
        if (isset($raw['topic']) || isset($raw['data'])) {
            return $raw;
        }
        if (($raw['ok'] ?? false) === true) {
            return [
                'topic' => $cmd,
                'state' => 0,
                'data' => $raw,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{map: ?array<string, mixed>, path: string|list<int|string>|null}
     */
    public static function locateMap(array $data): array
    {
        $best = ['map' => null, 'path' => null, 'score' => 0];
        self::walkForMap($data, [], $best);
        if ($best['map'] === null) {
            return ['map' => null, 'path' => null];
        }
        $path = $best['path'];
        if (is_array($path) && count($path) === 1) {
            $path = $path[0];
        } elseif (is_array($path) && $path === []) {
            $path = null;
        }

        return ['map' => $best['map'], 'path' => $path];
    }

    /**
     * Prefer a map on the list envelope itself, then the chosen backup entry.
     * Does not pick the richest map from a different backup id.
     *
     * @param array<string, mixed> $listData
     * @return array{map: ?array<string, mixed>, path: string|list<int|string>|null}
     */
    public static function locateMapFromList(array $listData, mixed $chosenId): array
    {
        if (YarboMap::appGeometryCount($listData) > 0) {
            return ['map' => $listData, 'path' => null];
        }

        $best = ['map' => null, 'path' => null];
        foreach (['backups', 'list', 'map_backup', 'map_backups', 'all_map_backup', 'backup_list'] as $key) {
            $list = $listData[$key] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = $item['id'] ?? $item['backup_id'] ?? $item['backupId'] ?? $item['map_id'] ?? $item['mapId'] ?? null;
                $located = self::locateMap($item);
                if ($located['map'] === null) {
                    continue;
                }
                if ($chosenId !== null && $id !== null && (string) $id === (string) $chosenId) {
                    return $located;
                }
                if ($best['map'] === null) {
                    $best = $located;
                }
            }
        }

        return $best['map'] !== null ? $best : self::locateMap($listData);
    }

    /**
     * @param list<int|string> $path
     * @param array{map: ?array<string, mixed>, path: list<int|string>|null, score: int} $best
     */
    private static function walkForMap(mixed $node, array $path, array &$best, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }
        if (is_string($node)) {
            $decoded = YarboCodec::decodePayloadField($node);
            if ($decoded !== []) {
                self::walkForMap($decoded, $path, $best, $depth + 1);
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }

        $score = YarboMap::appGeometryCount($node);
        if ($score > $best['score']) {
            $best = ['map' => $node, 'path' => $path, 'score' => $score];
        }

        foreach ($node as $key => $value) {
            if ($key === 'range' || $key === 'chargingPoint' || $key === 'charging_point') {
                continue;
            }
            self::walkForMap($value, array_merge($path, [$key]), $best, $depth + 1);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{id: mixed, name: mixed, keys: list<string>}>
     */
    public static function extractBackupEntries(array $data): array
    {
        $lists = [];
        foreach (['backups', 'list', 'map_backup', 'map_backups', 'all_map_backup', 'backup_list'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $lists[] = $data[$key];
            }
        }
        if ($lists === [] && self::isList($data)) {
            $lists[] = $data;
        }

        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = $item['id'] ?? $item['backup_id'] ?? $item['backupId'] ?? $item['map_id'] ?? $item['mapId'] ?? $index;
                $out[] = [
                    'id' => $id,
                    'name' => $item['name'] ?? $item['title'] ?? $item['time'] ?? $item['created_at'] ?? null,
                    'keys' => array_map('strval', array_keys($item)),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $map
     * @return array{keys: list<string>, counts: array<string, int>}
     */
    public static function publicSummary(array $map): array
    {
        $counts = [];
        foreach (['areas', 'pathways', 'nogozones', 'novisionzones', 'elec_fence', 'sidewalks', 'deadends'] as $key) {
            $counts[$key] = is_array($map[$key] ?? null) ? count($map[$key]) : 0;
        }

        return [
            'keys' => array_map('strval', array_keys($map)),
            'counts' => $counts,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchPayloads(mixed $id): array
    {
        $payloads = [['id' => $id], ['backup_id' => $id], ['map_id' => $id]];
        if (is_numeric($id)) {
            $intId = (int) $id;
            array_unshift($payloads, ['id' => $intId], ['backup_id' => $intId]);
        }

        $unique = [];
        foreach ($payloads as $payload) {
            $unique[json_encode($payload)] = $payload;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function isList(array $raw): bool
    {
        if ($raw === []) {
            return false;
        }

        return array_keys($raw) === range(0, count($raw) - 1);
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function payloadWithMap(array $stored, array $map): array
    {
        $payload = is_array($stored['recovery_payload'] ?? null) ? $stored['recovery_payload'] : $map;
        $path = $stored['map_path'] ?? null;
        if ($path === null || $path === '' || $path === []) {
            return $map + (is_array($payload) ? $payload : []);
        }
        $keys = is_array($path) ? $path : [$path];
        $ref = &$payload;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $ref[$key] = $map;
                break;
            }
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        unset($ref);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLiveMap(): ?array
    {
        $path = $this->projectRoot . '/data/map-last.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return null;
        }
        $map = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;

        return YarboMap::isAppMap($map) ? $map : null;
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $storedMap
     * @param array{type?: string, features?: array<int, mixed>} $collection
     */
    private static function encodeErrorMessage(array $errors, array $storedMap, array $collection): string
    {
        $draftCount = is_array($collection['features'] ?? null) ? count($collection['features']) : 0;
        $backupCount = YarboMap::appGeometryCount($storedMap);
        if ($backupCount === 0 && $draftCount > 0) {
            return 'The stored backup has no drawable zones, so the draft cannot be written into it. Load map backups again (it should draw the backup on the map), then edit that copy.';
        }
        $first = $errors[0] ?? 'Could not encode the draft';
        $extra = count($errors) > 1 ? sprintf(' (%d more mismatches)', count($errors) - 1) : '';

        return $first . $extra;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function formatCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $n) {
            if ($n > 0) {
                $parts[] = $key . ' ' . $n;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : 'no zones';
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function writeRestoreCopy(array $stored): string
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/map-restore-' . gmdate('Ymd_His') . '.json';
        file_put_contents(
            $path,
            json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persist(array $payload): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->lastPath() . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $this->lastPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPersisted(): ?array
    {
        $path = $this->lastPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function safetyCheck(YarboMqtt $client): array
    {
        $raw = $client->requestTelemetry(6);
        if (!is_array($raw)) {
            return ['ok' => false, 'error' => 'Could not read robot status. Dock it and try again.'];
        }
        $parsed = YarboTelemetry::parseForPanel($raw, null, $this->projectRoot);
        if (!empty($parsed['plan_running'])) {
            return ['ok' => false, 'error' => 'Stop the current plan before restoring a map.'];
        }
        if (empty($parsed['on_charge_pad'])) {
            return ['ok' => false, 'error' => 'Dock the robot before restoring a map.'];
        }

        return ['ok' => true];
    }
}
