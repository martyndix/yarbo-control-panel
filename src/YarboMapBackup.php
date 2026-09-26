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
    public function listAndFetch(YarboMqtt $client, mixed $backupId = null): array
    {
        $listEnvelope = $client->requestDataFeedback(self::LIST_CMD, [], 20.0, true);
        if ($listEnvelope === null) {
            return [
                'ok' => false,
                'error' => 'No reply to get_all_map_backup. Is the robot on the LAN broker?',
            ];
        }

        $listData = self::envelopeData($listEnvelope);
        $entries = self::extractBackupEntries($listData);
        $fromList = self::locateMap($listData);
        $chosenId = $backupId !== null && $backupId !== '' ? $backupId : ($entries[0]['id'] ?? null);

        $fetched = $fromList['map'] !== null ? $fromList : null;
        $fetchEnvelope = $fromList['map'] !== null ? $listEnvelope : null;
        $fetchPayloadUsed = $fromList['map'] !== null ? [] : null;
        if ($fetched === null && $chosenId !== null) {
            foreach (self::fetchPayloads($chosenId) as $payload) {
                $fetchEnvelope = $client->requestDataFeedback(self::FETCH_CMD, $payload, 20.0, false);
                if ($fetchEnvelope === null) {
                    continue;
                }
                $fetchPayloadUsed = $payload;
                $located = self::locateMap(self::envelopeData($fetchEnvelope));
                if ($located['map'] !== null) {
                    $fetched = $located;
                    break;
                }
            }
        }

        $compatible = is_array($fetched['map'] ?? null) && YarboMap::isAppMap($fetched['map']);
        if ($compatible) {
            $this->persist([
                'saved_at' => gmdate('c'),
                'backup_id' => $chosenId,
                'map' => $fetched['map'],
                'map_path' => $fetched['path'],
                'recovery_payload' => self::envelopeData($fetchEnvelope),
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
            'compatible' => $compatible,
            'summary' => $compatible ? self::publicSummary($fetched['map']) : null,
            'message' => $compatible
                ? 'Backup looks like a get_map blob. You can draft-edit and Save to robot while docked.'
                : 'Backup list came back, but the blob is not the same shape as get_map. Save stays off.',
        ];
    }

    /**
     * @param array{type?: string, features?: array<int, mixed>} $collection
     * @return array<string, mixed>
     */
    public function restoreDraft(YarboMqtt $client, array $collection, bool $confirm): array
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
            return [
                'ok' => false,
                'error' => implode(' ', $encoded['errors'] ?? ['Could not encode the draft']),
            ];
        }

        $payload = $this->payloadWithMap($stored, $encoded['map']);
        $restoreFile = $this->writeRestoreCopy($stored);

        $ack = $client->requestDataFeedback(self::RECOVERY_CMD, $payload, 40.0, true);
        if ($ack === null) {
            return [
                'ok' => false,
                'error' => 'map_recovery did not reply. Original backup is on the Pi: ' . basename($restoreFile),
                'restore_file' => basename($restoreFile),
            ];
        }

        $readback = $client->requestDataFeedback('get_map', [], 25.0, false);
        $readMap = $readback !== null ? self::locateMap(self::envelopeData($readback))['map'] : null;
        $delta = is_array($readMap) ? YarboMap::maxRangeDelta($encoded['map'], $readMap) : null;
        $verified = $delta !== null && $delta <= 0.05;

        if (!$verified) {
            $client->requestDataFeedback(self::RECOVERY_CMD, $stored['recovery_payload'], 40.0, false);
        }

        return [
            'ok' => $verified,
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
     * @param array<string, mixed> $data
     * @return array{map: ?array<string, mixed>, path: ?string}
     */
    public static function locateMap(array $data): array
    {
        if (YarboMap::isAppMap($data)) {
            return ['map' => $data, 'path' => null];
        }
        foreach (['map', 'map_data', 'backup', 'backup_data', 'mapData', 'content'] as $key) {
            $inner = $data[$key] ?? null;
            if (is_string($inner)) {
                $inner = YarboCodec::decodePayloadField($inner);
            }
            if (is_array($inner) && YarboMap::isAppMap($inner)) {
                return ['map' => $inner, 'path' => $key];
            }
        }
        $nested = $data['data'] ?? null;
        if (is_string($nested) || is_array($nested)) {
            $decoded = is_array($nested) ? $nested : YarboCodec::decodePayloadField($nested);
            if (YarboMap::isAppMap($decoded)) {
                return ['map' => $decoded, 'path' => 'data'];
            }
            $again = self::locateMap($decoded);
            if ($again['map'] !== null) {
                return $again;
            }
        }

        return ['map' => null, 'path' => null];
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
        if ($path === null || $path === '') {
            return $map + (is_array($payload) ? $payload : []);
        }
        $payload[$path] = $map;

        return $payload;
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
