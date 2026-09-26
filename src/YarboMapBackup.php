<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMapBackup
{
    public const LIST_CMD = 'get_all_map_backup';
    public const FETCH_CMD = 'get_map_buckup_from_id';
    public const FETCH_CMD_ALIAS = 'get_map_backup_from_id';
    public const RECOVERY_CMD = 'map_recovery';
    private const FETCH_TOPICS = [
        'get_map_buckup_from_id',
        'get_map_backup_from_id',
        'get_map_backup',
        'map_backup',
    ];
    /** get_map vs backup-file frames are not millimetre-accurate. */
    /** get_map vs backup-file frames are not millimetre-accurate. */
    private const VERIFY_M = 0.25;
    /** Metadata copied onto a patched backup; never merge original zone lists. */
    private const RECOVERY_META_KEYS = [
        'id',
        'timestamp',
        'name',
        'is_auto_backup',
        'create_time',
        'update_time',
        'backup_id',
    ];

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
        $chosenId = $backupId !== null && $backupId !== '' ? $backupId : self::preferredBackupId($entries);
        $fromList = self::locateMapFromList($listData, $chosenId);

        $fetched = $fromList['map'] !== null ? $fromList : null;
        $fetchEnvelope = $fromList['map'] !== null ? $listEnvelope : null;
        $fetchPayloadUsed = null;
        $usedFetch = false;
        $mapSource = $fetched !== null ? 'list' : null;
        $fetchProbe = null;
        $chosenFields = null;
        foreach ($entries as $entry) {
            if ((string) ($entry['id'] ?? '') === (string) $chosenId) {
                $chosenFields = is_array($entry['fields'] ?? null) ? $entry['fields'] : null;
                break;
            }
        }

        if ($chosenId !== null && ($fetched === null || !YarboMap::isAppMap($fetched['map'] ?? []))) {
            $payload = self::fetchPayloads($chosenId, $chosenFields)[0] ?? ['id' => $chosenId];
            $candidate = $client->requestDataFeedback(
                self::FETCH_CMD,
                $payload,
                30.0,
                false,
                self::FETCH_TOPICS,
                true
            );
            $tryVia = 'local';
            $fetchCmdUsed = self::FETCH_CMD;
            if ($candidate === null) {
                $candidate = $client->requestDataFeedback(
                    self::FETCH_CMD_ALIAS,
                    $payload,
                    20.0,
                    false,
                    self::FETCH_TOPICS,
                    true
                );
                $fetchCmdUsed = self::FETCH_CMD_ALIAS;
            }
            if ($candidate === null && $cloud !== null && $serial !== '') {
                $raw = $cloud->command(self::FETCH_CMD, $serial, $payload, 40.0);
                $candidate = $this->envelopeFromCloud($raw, self::FETCH_CMD);
                $tryVia = 'cloud';
                $fetchCmdUsed = self::FETCH_CMD;
                $attemptError = $candidate === null ? (string) ($raw['error'] ?? 'No cloud reply') : null;
            } else {
                $attemptError = $candidate === null ? 'No LAN reply' : null;
            }
            if ($candidate === null) {
                $fetchProbe = [
                    'payload' => $payload,
                    'command' => $fetchCmdUsed,
                    'via' => $tryVia,
                    'error' => $attemptError,
                ];
            } else {
                $fetchData = self::envelopeData($candidate);
                $fetchProbe = [
                    'payload' => $payload,
                    'command' => $fetchCmdUsed,
                    'via' => $tryVia,
                    'topic' => $candidate['topic'] ?? null,
                    'state' => $candidate['state'] ?? null,
                    'keys' => self::probeKeys($fetchData),
                ];
                $located = self::locateMap($fetchData);
                if ($located['map'] !== null) {
                    $fetched = $located;
                    $fetchEnvelope = $candidate;
                    $fetchPayloadUsed = $payload;
                    $usedFetch = true;
                    $mapSource = 'fetch';
                    $via = $tryVia;
                } elseif ($tryVia === 'local' && $cloud !== null && $serial !== '') {
                    $raw = $cloud->command(self::FETCH_CMD, $serial, $payload, 40.0);
                    $cloudCandidate = $this->envelopeFromCloud($raw, self::FETCH_CMD);
                    if ($cloudCandidate !== null) {
                        $fetchData = self::envelopeData($cloudCandidate);
                        $fetchProbe = [
                            'payload' => $payload,
                            'command' => self::FETCH_CMD,
                            'via' => 'cloud',
                            'topic' => $cloudCandidate['topic'] ?? null,
                            'state' => $cloudCandidate['state'] ?? null,
                            'keys' => self::probeKeys($fetchData),
                            'lan_keys' => $fetchProbe['keys'] ?? null,
                        ];
                        $located = self::locateMap($fetchData);
                        if ($located['map'] !== null) {
                            $fetched = $located;
                            $fetchEnvelope = $cloudCandidate;
                            $fetchPayloadUsed = $payload;
                            $usedFetch = true;
                            $mapSource = 'fetch';
                            $via = 'cloud';
                        }
                    }
                }
            }
        }

        $compatible = is_array($fetched) && YarboMap::isAppMap($fetched['map'] ?? []);
        $liveGeo = null;
        if (!$compatible) {
            $live = $this->fetchLiveMap($client, $cloud, $serial);
            if (is_array($live)) {
                $normalizedLive = YarboMap::normalize(['get_map' => ['data' => $live]]);
                $liveGeo = $normalizedLive['feature_collection'] ?? null;
            }
        }

        $featureCollection = null;
        if ($compatible) {
            $normalized = YarboMap::normalize(['get_map' => ['data' => $fetched['map']]]);
            $featureCollection = $normalized['feature_collection'] ?? null;
            $this->persist([
                'saved_at' => gmdate('c'),
                'backup_id' => $chosenId,
                'map' => $fetched['map'],
                'map_source' => $mapSource,
                'map_path' => $usedFetch ? $fetched['path'] : null,
                'recovery_payload' => $usedFetch
                    ? self::envelopeData($fetchEnvelope)
                    : $fetched['map'],
            ]);
        }

        $this->persistProbe([
            'saved_at' => gmdate('c'),
            'via' => $via,
            'list_keys' => self::probeKeys($listData),
            'entry_keys' => $entries[0]['keys'] ?? [],
            'selected_id' => $chosenId,
            'fetch' => $fetchProbe,
            'map_source' => $mapSource,
            'compatible' => $compatible,
        ]);

        $entryKeys = $entries[0]['keys'] ?? [];
        $idList = implode(', ', array_map(static fn ($row) => (string) ($row['id'] ?? ''), $entries));

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
            'fetch_probe' => $fetchProbe,
            'map_source' => $mapSource,
            'via' => $via,
            'compatible' => $compatible,
            'summary' => $compatible
                ? self::publicSummary($fetched['map'])
                : (is_array($liveGeo) ? ['keys' => [], 'counts' => []] : null),
            'geojson' => $featureCollection ?? $liveGeo,
            'live_map_preview' => $liveGeo !== null && !$compatible,
            'message' => $this->listMessage(
                $compatible,
                $via,
                $mapSource,
                is_array($fetched) ? ($fetched['map'] ?? []) : [],
                $idList,
                $entryKeys,
                $fetchProbe,
                $liveGeo !== null && !$compatible
            ),
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
        if (($stored['map_source'] ?? '') === 'get_map') {
            return [
                'ok' => false,
                'error' => 'This copy is the live map, not a backup file. map_recovery ignores it (the robot map does not change). Load map backups again so the backup file can be fetched.',
            ];
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
        if (isset($stored['backup_id']) && $stored['backup_id'] !== null && $stored['backup_id'] !== '') {
            $payload['id'] = is_numeric($stored['backup_id']) ? (int) $stored['backup_id'] : $stored['backup_id'];
        }
        $sentMap = self::locateMap($payload)['map'] ?? $encoded['map'];
        $encodeDelta = (float) ($encoded['max_delta_m'] ?? 0);
        $blobDelta = YarboMap::maxRangeDelta($stored['map'], $sentMap);
        if ($encodeDelta > self::VERIFY_M && $blobDelta <= self::VERIFY_M) {
            return [
                'ok' => false,
                'error' => 'The draft still includes the original line for a zone you moved, so the backup file was not actually changed. Stop editing, Load map backups again, then click one zone and drag its vertices — do not draw a new polygon on top.',
                'encode_delta_m' => $encodeDelta,
                'blob_delta_m' => $blobDelta,
            ];
        }

        $restoreFile = $this->writeRestoreCopy($stored);

        $sent = $this->sendRecovery($client, $cloud, $serial, $payload, false);
        $ack = $sent['envelope'];
        $via = $sent['via'];
        if ($ack === null) {
            return [
                'ok' => false,
                'error' => 'map_recovery did not reply on LAN or cloud. Original backup is on the Pi: ' . basename($restoreFile),
                'restore_file' => basename($restoreFile),
            ];
        }

        sleep(4);
        $readMap = $this->readCurrentMap($client, $cloud, $serial);
        $delta = is_array($readMap) ? YarboMap::maxRangeDelta($sentMap, $readMap) : null;
        $vsOriginal = is_array($readMap) ? YarboMap::maxRangeDelta($stored['map'], $readMap) : null;
        $robotMoved = $vsOriginal !== null && $vsOriginal > self::VERIFY_M;
        if ($blobDelta > self::VERIFY_M && !$robotMoved && $cloud !== null && $serial !== '') {
            $cloudSent = $this->sendRecovery($client, $cloud, $serial, $payload, true);
            if ($cloudSent['envelope'] !== null) {
                $ack = $cloudSent['envelope'];
                $via = 'cloud';
                sleep(5);
                $retryMap = $this->readCurrentMap($client, $cloud, $serial);
                if (is_array($retryMap)) {
                    $readMap = $retryMap;
                    $delta = YarboMap::maxRangeDelta($sentMap, $readMap);
                    $vsOriginal = YarboMap::maxRangeDelta($stored['map'], $readMap);
                    $robotMoved = $vsOriginal > self::VERIFY_M;
                }
            }
        } elseif ($delta === null || ($delta > self::VERIFY_M && $blobDelta > self::VERIFY_M)) {
            sleep(4);
            $retryMap = $this->readCurrentMap($client, $cloud, $serial);
            if (is_array($retryMap)) {
                $readMap = $retryMap;
                $delta = YarboMap::maxRangeDelta($sentMap, $readMap);
                $vsOriginal = YarboMap::maxRangeDelta($stored['map'], $readMap);
                $robotMoved = $vsOriginal !== null && $vsOriginal > self::VERIFY_M;
            }
        }

        $matchesSent = $delta !== null && $delta <= self::VERIFY_M;
        $verified = $matchesSent && ($blobDelta <= self::VERIFY_M || $robotMoved);
        $unchanged = $blobDelta > self::VERIFY_M && !$robotMoved;
        $hasBackupBlob = ($stored['map_source'] ?? '') !== 'get_map';
        $rolledBack = false;
        if (!$verified && $hasBackupBlob && $robotMoved) {
            $this->sendRecovery(
                $client,
                $cloud,
                $serial,
                is_array($stored['recovery_payload'] ?? null) ? $stored['recovery_payload'] : $stored['map'],
                $via === 'cloud'
            );
            $rolledBack = true;
        }

        $message = $this->restoreMessage(
            $verified,
            $unchanged,
            $rolledBack,
            $delta,
            $blobDelta > self::VERIFY_M ? $blobDelta : $encodeDelta,
            $via,
            (string) ($stored['map_source'] ?? ''),
            $ack['state'] ?? null,
            basename($restoreFile),
            is_array($readMap),
            $vsOriginal
        );

        $this->persistProbe([
            'saved_at' => gmdate('c'),
            'action' => 'restore',
            'via' => $via,
            'map_source' => $stored['map_source'] ?? null,
            'recovery_state' => $ack['state'] ?? null,
            'encode_delta_m' => $blobDelta > self::VERIFY_M ? $blobDelta : $encodeDelta,
            'blob_delta_m' => $blobDelta,
            'readback_delta_m' => $delta,
            'vs_original_m' => $vsOriginal,
            'verified' => $verified,
            'unchanged' => $unchanged,
            'rolled_back' => $rolledBack,
        ]);

        return [
            'ok' => $verified,
            'via' => $via,
            'map_source' => $stored['map_source'] ?? null,
            'recovery_state' => $ack['state'] ?? null,
            'encode_delta_m' => $blobDelta > self::VERIFY_M ? $blobDelta : $encodeDelta,
            'blob_delta_m' => $blobDelta,
            'readback_delta_m' => $delta,
            'vs_original_m' => $vsOriginal,
            'verified' => $verified,
            'unchanged' => $unchanged,
            'rolled_back' => $rolledBack,
            'restore_file' => basename($restoreFile),
            'message' => $message,
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
            'compatible' => YarboMap::isAppMap($stored['map'] ?? []) && ($stored['map_source'] ?? '') !== 'get_map',
            'backup_id' => $stored['backup_id'] ?? null,
            'saved_at' => $stored['saved_at'] ?? null,
            'map_source' => $stored['map_source'] ?? null,
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
     * @return array<string, mixed>|null
     */
    private function fetchLiveMap(YarboMqtt $client, ?YarboCloud $cloud, string $serial): ?array
    {
        $envelope = $client->requestDataFeedback('get_map', [], 20.0, false);
        if ($envelope !== null) {
            $located = self::locateMap(self::envelopeData($envelope));
            if ($located['map'] !== null) {
                return $located['map'];
            }
        }

        $cached = $this->loadLiveMap();
        if (is_array($cached)) {
            return $cached;
        }

        if ($cloud === null || $serial === '') {
            return null;
        }
        $raw = $cloud->fetch('get_map', $serial, 35.0);
        if (!is_array($raw) || ($raw['ok'] ?? true) === false) {
            return null;
        }
        $located = self::locateMap($raw);

        return $located['map'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{envelope: ?array<string, mixed>, via: string}
     */
    private function sendRecovery(
        YarboMqtt $client,
        ?YarboCloud $cloud,
        string $serial,
        array $payload,
        bool $forceCloud = false,
    ): array {
        if (!$forceCloud) {
            $ack = $client->requestDataFeedback(self::RECOVERY_CMD, $payload, 15.0, false);
            $via = 'local';
            if (self::ackLooksOk($ack)) {
                return ['envelope' => $ack, 'via' => $via];
            }
        } else {
            $ack = null;
            $via = 'cloud';
        }
        if ($cloud !== null && $serial !== '') {
            $cloudAck = $this->envelopeFromCloud(
                $cloud->command(self::RECOVERY_CMD, $serial, $payload, 45.0),
                self::RECOVERY_CMD
            );
            if ($cloudAck !== null) {
                return ['envelope' => $cloudAck, 'via' => 'cloud'];
            }
        }

        return ['envelope' => $ack ?? null, 'via' => $via ?? 'local'];
    }

    private static function ackLooksOk(?array $ack): bool
    {
        if (!is_array($ack)) {
            return false;
        }
        if (!array_key_exists('state', $ack) || $ack['state'] === null) {
            return true;
        }

        return (int) $ack['state'] === 0;
    }

    /**
     * Fresh get_map for verify. Does not use the Pi cache.
     *
     * @return array<string, mixed>|null
     */
    private function readCurrentMap(YarboMqtt $client, ?YarboCloud $cloud, string $serial): ?array
    {
        $envelope = $client->requestDataFeedback('get_map', [], 25.0, false);
        if ($envelope !== null) {
            $located = self::locateMap(self::envelopeData($envelope));
            if ($located['map'] !== null) {
                return $located['map'];
            }
        }
        if ($cloud === null || $serial === '') {
            return null;
        }
        $raw = $cloud->fetch('get_map', $serial, 35.0);
        if (!is_array($raw) || ($raw['ok'] ?? true) === false) {
            return null;
        }
        $located = self::locateMap($raw);

        return $located['map'];
    }

    /**
     * @param mixed $state
     */
    private function restoreMessage(
        bool $verified,
        bool $unchanged,
        bool $rolledBack,
        ?float $delta,
        float $encodeDelta,
        string $via,
        string $mapSource,
        mixed $state,
        string $restoreFile,
        bool $readMap,
        ?float $vsOriginal = null,
    ): string {
        $detail = sprintf(
            ' via %s%s, encode %.2f m, read-back %s, vs original %s.',
            $via,
            $mapSource !== '' ? ', source ' . $mapSource : '',
            $encodeDelta,
            $delta === null ? 'unavailable' : sprintf('%.2f m', $delta),
            $vsOriginal === null ? 'unavailable' : sprintf('%.2f m', $vsOriginal)
        );
        if ($state !== null && $state !== '') {
            $detail .= ' recovery state ' . (is_scalar($state) ? (string) $state : json_encode($state)) . '.';
        }
        if ($verified) {
            return 'Robot map matches the draft (within 25 cm). Check it in the official app.' . $detail;
        }
        if ($unchanged) {
            return 'Robot map did not change. map_recovery accepted the command but the live vertices are still the original ones. Original left in place ('
                . $restoreFile . '). Enable Settings → cloud fallback if this was LAN-only.' . $detail;
        }
        if (!$readMap) {
            return 'Restore was sent, but get_map could not be read to verify. Check the official app. Original is on the Pi as '
                . $restoreFile . '. Not auto-reverted.' . $detail;
        }
        if ($rolledBack) {
            return 'Read-back did not match the draft. Restored the original backup from the Pi copy ('
                . $restoreFile . ').' . $detail;
        }

        return 'Read-back did not match the draft. Original is on the Pi as ' . $restoreFile . '. Not auto-reverted.' . $detail;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistProbe(array $payload): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/map-backup-probe.json',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function probeKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[(string) $key] = 'array:' . count($value);
            } elseif (is_string($value)) {
                $out[(string) $key] = 'string:' . strlen($value);
            } else {
                $out[(string) $key] = gettype($value);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, scalar|null>
     */
    private static function scalarFields(array $item): array
    {
        $fields = [];
        foreach ($item as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $fields[(string) $key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $map
     * @param list<string> $entryKeys
     * @param array<string, mixed>|null $fetchProbe
     */
    private function listMessage(
        bool $compatible,
        string $via,
        ?string $mapSource,
        array $map,
        string $idList,
        array $entryKeys,
        ?array $fetchProbe,
        bool $livePreview = false,
    ): string {
        $ids = $idList !== '' ? ' ids ' . $idList : '';
        $keys = $entryKeys !== [] ? ' List entry keys: ' . implode(', ', $entryKeys) . '.' : '';
        $fetchHint = '';
        if (is_array($fetchProbe)) {
            if (isset($fetchProbe['error'])) {
                $fetchHint = ' Fetch: ' . (string) $fetchProbe['error'] . '.';
            } elseif (isset($fetchProbe['keys']) && is_array($fetchProbe['keys'])) {
                $fetchHint = ' Fetch replied with keys ' . implode(', ', array_keys($fetchProbe['keys'])) . '.';
            }
        }
        if ($compatible) {
            return sprintf(
                'Backup file extracted via %s (%s). Edit these zones, then Save to robot while docked.',
                $via,
                self::formatCounts(self::publicSummary($map)['counts'])
            );
        }
        $preview = $livePreview
            ? ' Live map is shown for viewing. Save stays off because map_recovery ignores get_map (the robot map does not change).'
            : ' Save stays off.';

        return 'Backup list is metadata only (id, name, timestamp). Need the backup file from get_map_buckup_from_id.'
            . $ids . $keys . $fetchHint . $preview;
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
                    'fields' => self::scalarFields($item),
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
        foreach (array_keys(YarboMap::canonicalListNames()) as $key) {
            $counts[$key] = count(YarboMap::zoneList($map, $key));
        }

        return [
            'keys' => array_map('strval', array_keys($map)),
            'counts' => $counts,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function preferredBackupId(array $entries): mixed
    {
        foreach ($entries as $entry) {
            $auto = $entry['fields']['is_auto_backup'] ?? null;
            if ($auto === false || $auto === 0 || $auto === '0') {
                return $entry['id'] ?? null;
            }
        }

        return $entries[0]['id'] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchPayloads(mixed $id, ?array $fields = null): array
    {
        $payload = ['id' => is_numeric($id) ? (int) $id : $id];
        if (is_array($fields) && isset($fields['timestamp'])) {
            return [$payload, $payload + ['timestamp' => $fields['timestamp']]];
        }

        return [$payload];
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
        $meta = [];
        $original = is_array($stored['recovery_payload'] ?? null) ? $stored['recovery_payload'] : [];
        foreach (self::RECOVERY_META_KEYS as $key) {
            if (array_key_exists($key, $original)) {
                $meta[$key] = $original[$key];
            }
        }
        $path = $stored['map_path'] ?? null;
        if ($path === null || $path === '' || $path === []) {
            return $map + $meta;
        }
        $payload = $original !== [] ? $original : [];
        foreach (YarboMap::canonicalListNames() as $aliases) {
            foreach ($aliases as $key) {
                unset($payload[$key]);
            }
        }
        unset($payload['chargingData'], $payload['chargingPoint'], $payload['chargingPoints'], $payload['allchargingData']);
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

        return $payload + $meta;
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
