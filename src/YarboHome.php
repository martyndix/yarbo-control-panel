<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboHome
{
    public const KIND_LIGHT = 'light';
    public const PULSE_COUNT_MIN = 1;
    public const PULSE_COUNT_MAX = 10;
    public const PULSE_COUNT_DEFAULT = 3;
    public const PULSE_HEX_DEFAULT = '#ff3b30';
    public const PULSE_DWELL_US = 400000;
    public const KIND_PLUG = 'plug';
    public const KIND_SWITCH = 'switch';
    public const KIND_HEATER = 'heater';
    public const KIND_VACUUM = 'vacuum';
    public const KIND_SCENE = 'scene';
    public const CONTACT_DOOR = 'door';
    public const CONTACT_WINDOW = 'window';
    public const PAPER_MAX = 12;
    public const PAPER_ACCESS_MAX = 16;
    /** Compact JSON names: long enough to fill a 480px PaperMono HOUSE row. */
    public const PAPER_NAME_MAX = 40;
    public const PAPER_ROBOT_AREA_MAX = 8;
    /** Warm night / cool midday for Time of day (Apple Adaptive Lighting-style). */
    public const ADAPTIVE_NIGHT_K = 2200;
    public const ADAPTIVE_SUNRISE_K = 3000;
    public const ADAPTIVE_NOON_K = 5000;
    public const ADAPTIVE_SUNSET_K = 2700;
    public const ADAPTIVE_STEP_K = 50;
    public const ADAPTIVE_MIN_INTERVAL_SEC = 60;
    public const BACKUP_KIND = 'yarbo-home-backup';
    public const BACKUP_VERSION = 1;

    /** Set when load() puts rooms/scenes back from a backup. */
    private string $restoredHomeFrom = '';

    /** @var array<string, mixed>|null */
    private ?array $loadedStore = null;

    private int $loadedMtime = -1;

    private int $loadedSize = -1;

    /** @var array{via: string, name: string}|null */
    private ?array $commandTrigger = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function storePath(): string
    {
        return $this->projectRoot . '/data/home.json';
    }

    /**
     * @return array{
     *   names: array<string, string>,
     *   room_defs: list<array{id: string, name: string}>,
     *   rooms: array<string, string>,
     *   group_defs: list<array{id: string, name: string, room_id: string}>,
     *   groups: array<string, string>,
     *   scenes: list<array<string, mixed>>,
     *   paper: array<string, list<string>>,
     *   hidden: list<string>,
     *   device_order: list<string>
     * }
     */
    public function load(): array
    {
        $path = $this->storePath();
        $stat = is_file($path) ? @stat($path) : false;
        $mtime = is_array($stat) ? (int) $stat['mtime'] : 0;
        $size = is_array($stat) ? (int) $stat['size'] : 0;
        if ($this->loadedStore !== null && $mtime === $this->loadedMtime && $size === $this->loadedSize) {
            return $this->loadedStore;
        }
        $defaults = [
            'names' => [],
            'room_defs' => [],
            'rooms' => [],
            'group_defs' => [],
            'groups' => [],
            'scenes' => [],
            'paper' => [],
            'hidden' => [],
            'device_order' => [],
            'active_scene_id' => '',
            'last_devices' => [],
            'last_trigger' => [],
            'contact_kind' => [],
            'adaptive' => ['extra' => [], 'paused' => [], 'last' => []],
        ];
        if (!is_file($path)) {
            $recovered = $this->recoverUserMeta($defaults);
            if ($recovered === null) {
                return $this->rememberLoaded($defaults, 0, 0);
            }
            $this->writeRaw($recovered);
            return $recovered;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            if (is_string($raw) && trim($raw) !== '') {
                @file_put_contents($this->storePath() . '.corrupt', $raw);
            }
            $recovered = $this->recoverUserMeta($defaults);
            if ($recovered === null) {
                return $this->rememberLoaded($defaults, $mtime, $size);
            }
            $this->writeRaw($recovered);
            return $recovered;
        }
        $names = [];
        foreach (is_array($decoded['names'] ?? null) ? $decoded['names'] : [] as $id => $name) {
            $id = trim((string) $id);
            $name = YarboHub::normalizeDisplayName((string) $name, 48);
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }
        $roomDefs = [];
        $defIds = [];
        foreach (is_array($decoded['room_defs'] ?? null) ? $decoded['room_defs'] : [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $id = trim((string) ($def['id'] ?? ''));
            $name = YarboHub::normalizeDisplayName((string) ($def['name'] ?? ''), 32);
            if ($id === '' || $name === '' || isset($defIds[$id])) {
                continue;
            }
            $roomDefs[] = ['id' => $id, 'name' => $name];
            $defIds[$id] = true;
        }
        $rooms = [];
        $migrated = false;
        foreach (is_array($decoded['rooms'] ?? null) ? $decoded['rooms'] : [] as $deviceId => $value) {
            $deviceId = trim((string) $deviceId);
            $value = trim((string) $value);
            if ($deviceId === '' || $value === '') {
                continue;
            }
            if (isset($defIds[$value])) {
                $rooms[$deviceId] = $value;
                continue;
            }
            $name = YarboHub::normalizeDisplayName($value, 32);
            if ($name === '') {
                continue;
            }
            $matchId = null;
            foreach ($roomDefs as $def) {
                if (strcasecmp($def['name'], $name) === 0) {
                    $matchId = $def['id'];
                    break;
                }
            }
            if ($matchId === null) {
                $matchId = 'r' . bin2hex(random_bytes(3));
                $roomDefs[] = ['id' => $matchId, 'name' => $name];
                $defIds[$matchId] = true;
                $migrated = true;
            }
            $rooms[$deviceId] = $matchId;
        }
        $scenes = [];
        foreach (is_array($decoded['scenes'] ?? null) ? $decoded['scenes'] : [] as $scene) {
            if (is_array($scene)) {
                $normalized = $this->normalizeScene($scene);
                if ($normalized !== null) {
                    $scenes[] = $normalized;
                }
            }
        }
        $paper = [];
        foreach (is_array($decoded['paper'] ?? null) ? $decoded['paper'] : [] as $deviceId => $ids) {
            $deviceId = trim((string) $deviceId);
            if ($deviceId === '' || !is_array($ids)) {
                continue;
            }
            $paper[$deviceId] = $this->normalizeIdList($ids);
        }
        $hidden = $this->normalizeIdList(is_array($decoded['hidden'] ?? null) ? $decoded['hidden'] : []);
        $groupDefs = [];
        $groupIds = [];
        foreach (is_array($decoded['group_defs'] ?? null) ? $decoded['group_defs'] : [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $id = trim((string) ($def['id'] ?? ''));
            $name = YarboHub::normalizeDisplayName((string) ($def['name'] ?? ''), 32);
            $roomId = trim((string) ($def['room_id'] ?? ''));
            if ($id === '' || $name === '' || $roomId === '' || !isset($defIds[$roomId]) || isset($groupIds[$id])) {
                continue;
            }
            $groupDefs[] = ['id' => $id, 'name' => $name, 'room_id' => $roomId];
            $groupIds[$id] = $roomId;
        }
        $groups = [];
        foreach (is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [] as $deviceId => $groupId) {
            $deviceId = trim((string) $deviceId);
            $groupId = trim((string) $groupId);
            if ($deviceId === '' || $groupId === '' || !isset($groupIds[$groupId])) {
                continue;
            }
            $groups[$deviceId] = $groupId;
            $rooms[$deviceId] = $groupIds[$groupId];
        }

        $store = [
            'names' => $names,
            'room_defs' => $roomDefs,
            'rooms' => $rooms,
            'group_defs' => $groupDefs,
            'groups' => $groups,
            'scenes' => $scenes,
            'paper' => $paper,
            'hidden' => $hidden,
            'device_order' => $this->normalizeIdList(is_array($decoded['device_order'] ?? null) ? $decoded['device_order'] : []),
            'active_scene_id' => trim((string) ($decoded['active_scene_id'] ?? '')),
            'last_devices' => $this->normalizeLastDevices(
                is_array($decoded['last_devices'] ?? null) ? $decoded['last_devices'] : []
            ),
            'last_trigger' => $this->normalizeLastTrigger($decoded['last_trigger'] ?? null),
            'contact_kind' => $this->normalizeContactKind($decoded['contact_kind'] ?? null),
            'adaptive' => $this->normalizeAdaptiveState($decoded['adaptive'] ?? null),
        ];
        $recovered = $this->recoverUserMeta($store);
        if ($recovered !== null) {
            $store = $recovered;
            $this->writeRaw($store);
        } elseif ($migrated) {
            $this->write($store);
        } elseif ($store['last_devices'] === []) {
            $cached = $this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json');
            if ($cached !== []) {
                $store['last_devices'] = $this->normalizeLastDevices($cached);
                $this->write($store);
            }
        }
        if ($this->storeMetaScore($store) >= 4) {
            $this->snapshotStore($store);
        }

        $saved = is_file($path) ? @stat($path) : false;

        return $this->rememberLoaded(
            $store,
            is_array($saved) ? (int) $saved['mtime'] : $mtime,
            is_array($saved) ? (int) $saved['size'] : $size
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveMeta(array $input): bool
    {
        $store = $this->load();
        if (isset($input['names']) && is_array($input['names'])) {
            foreach ($input['names'] as $id => $name) {
                $id = trim((string) $id);
                $name = YarboHub::normalizeDisplayName((string) $name, 48);
                if ($id === '') {
                    continue;
                }
                if ($name === '') {
                    unset($store['names'][$id]);
                } else {
                    $store['names'][$id] = $name;
                }
            }
        }
        if (isset($input['rooms']) && is_array($input['rooms'])) {
            $known = [];
            foreach ($store['room_defs'] as $def) {
                $known[(string) ($def['id'] ?? '')] = true;
            }
            foreach ($input['rooms'] as $id => $room) {
                $id = trim((string) $id);
                $room = trim((string) $room);
                if ($id === '') {
                    continue;
                }
                if ($room === '' || !isset($known[$room])) {
                    unset($store['rooms'][$id]);
                    unset($store['groups'][$id]);
                } else {
                    $store['rooms'][$id] = $room;
                    $gid = (string) ($store['groups'][$id] ?? '');
                    if ($gid !== '' && ($this->groupRoomId($store, $gid) !== $room)) {
                        unset($store['groups'][$id]);
                    }
                }
            }
        }
        if (isset($input['contact_kind']) && is_array($input['contact_kind'])) {
            if (!isset($store['contact_kind']) || !is_array($store['contact_kind'])) {
                $store['contact_kind'] = [];
            }
            foreach ($input['contact_kind'] as $id => $role) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                $store['contact_kind'][$id] = self::normalizeContactRole($role);
            }
        }

        return $this->write($store);
    }

    /**
     * Names, rooms, groups, scenes, HOUSE buttons, and automations for a local file.
     *
     * @return array<string, mixed>
     */
    public function exportBackup(): array
    {
        $store = $this->load();
        $backup = [
            'kind' => self::BACKUP_KIND,
            'version' => self::BACKUP_VERSION,
            'saved_at' => gmdate('c'),
            'home' => [
                'names' => $store['names'],
                'room_defs' => $store['room_defs'],
                'rooms' => $store['rooms'],
                'group_defs' => $store['group_defs'],
                'groups' => $store['groups'],
                'scenes' => $store['scenes'],
                'paper' => $store['paper'],
                'hidden' => $store['hidden'],
                'device_order' => $store['device_order'],
                'last_devices' => $store['last_devices'],
                'contact_kind' => $store['contact_kind'] ?? [],
            ],
            'automations' => (new YarboHomeAutomations($this->projectRoot))->exportStore(),
        ];

        return [
            'ok' => true,
            'backup' => $backup,
            'summary' => self::backupSummary($backup),
        ];
    }

    /**
     * Replace rooms/scenes/names/HOUSE and/or automations from a downloaded backup.
     * Matter pairing is not changed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function importBackup(array $payload): array
    {
        $backup = self::normalizeBackupPayload($payload);
        if ($backup === null) {
            return ['ok' => false, 'error' => 'That file is not a Home backup'];
        }
        $homeIn = is_array($backup['home'] ?? null) ? $backup['home'] : null;
        $autoIn = is_array($backup['automations'] ?? null) ? $backup['automations'] : null;
        if ($homeIn === [] || $homeIn === null) {
            $homeIn = null;
        }
        if ($autoIn === [] || $autoIn === null) {
            $autoIn = null;
        }
        if ($homeIn === null && $autoIn === null) {
            return ['ok' => false, 'error' => 'That backup has no rooms, scenes, or automations'];
        }
        $current = $this->load();
        if ($homeIn !== null) {
            $this->snapshotStore($current);
            foreach (['names', 'room_defs', 'rooms', 'group_defs', 'groups', 'scenes', 'paper', 'hidden', 'device_order', 'contact_kind'] as $key) {
                if (isset($homeIn[$key]) && is_array($homeIn[$key])) {
                    $current[$key] = $homeIn[$key];
                }
            }
            if (isset($homeIn['last_devices']) && is_array($homeIn['last_devices'])) {
                $remembered = $this->normalizeLastDevices($homeIn['last_devices']);
                if ($remembered !== []) {
                    $current['last_devices'] = $remembered;
                }
            }
            if (!$this->writeRaw($current)) {
                return ['ok' => false, 'error' => 'Could not save rooms and scenes'];
            }
        }
        if ($autoIn !== null) {
            $autoResult = (new YarboHomeAutomations($this->projectRoot))->importStore($autoIn);
            if (!($autoResult['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string) ($autoResult['error'] ?? 'Could not save automations')];
            }
        }
        $fresh = $this->load();

        return [
            'ok' => true,
            'summary' => [
                'rooms' => count($fresh['room_defs']),
                'named' => count($fresh['names']),
                'assigned' => count($fresh['rooms']),
                'scenes' => count($fresh['scenes']),
                'automations' => count((new YarboHomeAutomations($this->projectRoot))->publicList()),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{kind: string, home: ?array<string, mixed>, automations: ?array<string, mixed>}|null
     */
    public static function normalizeBackupPayload(array $payload): ?array
    {
        $data = $payload;
        for ($i = 0; $i < 3; $i++) {
            if (($data['kind'] ?? '') === self::BACKUP_KIND) {
                break;
            }
            if (isset($data['backup']) && is_array($data['backup'])) {
                $data = $data['backup'];
                continue;
            }
            break;
        }
        $autoWrap = static function (mixed $auto): ?array {
            if (!is_array($auto)) {
                return null;
            }
            if (array_is_list($auto)) {
                return ['automations' => $auto];
            }
            if (isset($auto['automations']) && is_array($auto['automations'])) {
                return $auto;
            }

            return null;
        };
        if (($data['kind'] ?? '') === self::BACKUP_KIND) {
            $home = is_array($data['home'] ?? null) ? $data['home'] : null;
            $auto = $autoWrap($data['automations'] ?? null);

            return ($home === null && $auto === null)
                ? null
                : ['kind' => self::BACKUP_KIND, 'home' => $home, 'automations' => $auto];
        }
        if (isset($data['room_defs']) || isset($data['scenes']) || isset($data['names']) || isset($data['rooms'])) {
            return [
                'kind' => self::BACKUP_KIND,
                'home' => $data,
                'automations' => $autoWrap($data['automations'] ?? null),
            ];
        }
        if (isset($data['automations']) && is_array($data['automations'])) {
            return [
                'kind' => self::BACKUP_KIND,
                'home' => null,
                'automations' => $autoWrap($data),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $backup
     * @return array{rooms: int, named: int, assigned: int, scenes: int, automations: int}
     */
    public static function backupSummary(array $backup): array
    {
        $home = is_array($backup['home'] ?? null) ? $backup['home'] : [];
        $auto = is_array($backup['automations'] ?? null) ? $backup['automations'] : [];
        $rules = is_array($auto['automations'] ?? null) ? $auto['automations'] : [];

        return [
            'rooms' => count(is_array($home['room_defs'] ?? null) ? $home['room_defs'] : []),
            'named' => count(is_array($home['names'] ?? null) ? $home['names'] : []),
            'assigned' => count(is_array($home['rooms'] ?? null) ? $home['rooms'] : []),
            'scenes' => count(is_array($home['scenes'] ?? null) ? $home['scenes'] : []),
            'automations' => count($rules),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $hub = new YarboHub($this->projectRoot);
        $setup = $this->setupStatus();
        if (!$hub->enabled(YarboHub::MODULE_HOME)) {
            return [
                'ok' => true,
                'enabled' => false,
                'server' => ['ok' => false, 'error' => 'Home module is off'],
                'devices' => [],
                'hidden_devices' => [],
                'rooms' => [],
                'scenes' => [],
                'paper_devices' => [],
                'setup' => $setup,
            ];
        }
        $store = $this->load();
        // Live On/Off comes from the Matter agent when it answers quickly.
        // Device names and rooms still come from disk so a hung agent cannot freeze the panel.
        $live = $this->devicesWithLiveState();
        $live['devices'] = $this->mergeUnifiDevices($live['devices']);
        $store = $this->adoptLoneHeaterIds($this->load(), $live['devices']);
        $hidden = array_fill_keys($store['hidden'], true);
        $adaptiveIds = array_fill_keys($this->adaptiveDeviceIds(), true);
        $triggers = is_array($store['last_trigger'] ?? null) ? $store['last_trigger'] : [];
        $devices = [];
        $hiddenDevices = [];
        $seenIds = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '' || isset($seenIds[$id]) || self::looksLikeLeftoverHueEndpoint($device)) {
                continue;
            }
            $seenIds[$id] = true;
            $isUnifi = YarboUnifi::isHomeId($id) || ((string) ($device['source'] ?? '')) === YarboUnifi::SOURCE;
            if (!$isUnifi) {
                $device = YarboMatterFabric::reclassifyRow($device);
            }
            $defaultName = (string) ($device['name'] ?? $id);
            $name = $store['names'][$id] ?? $defaultName;
            $roomId = (string) ($store['rooms'][$id] ?? '');
            $roomName = '';
            if ($roomId !== '') {
                foreach ($store['room_defs'] as $def) {
                    if (($def['id'] ?? '') === $roomId) {
                        $roomName = (string) ($def['name'] ?? '');
                        break;
                    }
                }
            }
            $isLight = self::isRoomLight($device);
            $colorable = $isLight && !$isUnifi && (
                (bool) ($device['colorable'] ?? false)
                || (bool) ($device['color_hs'] ?? false)
                || (bool) ($device['color_xy'] ?? false)
            );
            $row = [
                'id' => $id,
                'node_id' => (int) ($device['node_id'] ?? 0),
                'endpoint' => (int) ($device['endpoint'] ?? 0),
                'name' => $name,
                'default_name' => $defaultName,
                'kind' => (string) ($device['kind'] ?? self::KIND_LIGHT),
                'vendor' => (string) ($device['vendor'] ?? ''),
                'product' => (string) ($device['product'] ?? ''),
                'source' => $isUnifi ? YarboUnifi::SOURCE : (string) ($device['source'] ?? ''),
                'bridge' => (bool) ($device['bridge'] ?? false),
                'on' => ((string) ($device['kind'] ?? '')) === 'camera'
                    ? false
                    : YarboMatterFabric::attrBool($device['on'] ?? false),
                'brightness' => $isLight && !$isUnifi && isset($device['brightness']) ? (int) $device['brightness'] : null,
                'dimmable' => $isLight && !$isUnifi && (bool) ($device['dimmable'] ?? false),
                'colorable' => $colorable,
                'color_hs' => !$isUnifi && (bool) ($device['color_hs'] ?? false),
                'color_xy' => !$isUnifi && (bool) ($device['color_xy'] ?? false),
                'color_ct' => !$isUnifi && (bool) ($device['color_ct'] ?? false),
                'color_hex' => $this->normalizeHex((string) ($device['color_hex'] ?? '')),
                'hue' => isset($device['hue']) ? (int) $device['hue'] : null,
                'saturation' => isset($device['saturation']) ? (int) $device['saturation'] : null,
                'color_temp' => isset($device['color_temp']) ? (int) $device['color_temp'] : null,
                'color_temp_min' => isset($device['color_temp_min']) ? (int) $device['color_temp_min'] : null,
                'color_temp_max' => isset($device['color_temp_max']) ? (int) $device['color_temp_max'] : null,
                'available' => (bool) ($device['available'] ?? true),
                'has_on_off' => array_key_exists('has_on_off', $device) ? (bool) $device['has_on_off'] : null,
                'unready' => !$isUnifi && self::looksLikeUnreadyHueLight($device),
                'adaptive' => !$isUnifi && $isLight && isset($adaptiveIds[$id]),
                'room_id' => $roomName !== '' ? $roomId : '',
                'room' => $roomName,
                'group_id' => $this->deviceGroupId($store, $id, $roomId),
                'hidden' => isset($hidden[$id]),
                'status' => (string) ($device['status'] ?? ''),
                'snapshot' => (string) ($device['snapshot'] ?? ''),
                'gate' => (bool) ($device['gate'] ?? false),
                'locked' => array_key_exists('locked', $device) ? (bool) $device['locked'] : null,
            ];
            if (($row['kind'] ?? '') === self::KIND_HEATER) {
                $row['has_thermostat'] = (bool) ($device['has_thermostat'] ?? false)
                    || isset($device['local_temperature'])
                    || isset($device['heating_setpoint']);
                $row['local_temperature'] = self::optionalCelsius($device['local_temperature'] ?? null);
                $row['heating_setpoint'] = self::optionalCelsius($device['heating_setpoint'] ?? null);
                $row['heating_min'] = self::optionalCelsius($device['heating_min'] ?? 5) ?? 5.0;
                $row['heating_max'] = self::optionalCelsius($device['heating_max'] ?? 35) ?? 35.0;
            }
            if (($row['kind'] ?? '') === self::KIND_VACUUM) {
                $row['vacuum_status'] = (string) ($device['vacuum_status'] ?? '');
                $row['vacuum_clean'] = (string) ($device['vacuum_clean'] ?? 'vacuum');
                $battery = self::optionalPercent($device['vacuum_battery'] ?? null);
                if ($battery !== null && $battery > 0) {
                    $row['vacuum_battery'] = $battery;
                }
                $row['can_vacuum'] = array_key_exists('can_vacuum', $device) ? (bool) $device['can_vacuum'] : true;
                $row['can_mop'] = !empty($device['can_mop']);
                $row['can_vacuum_mop'] = !empty($device['can_vacuum_mop']);
                $row['can_pause'] = !empty($device['can_pause']);
                $row['areas'] = self::normalizeVacuumAreas($device['areas'] ?? []);
                $row['selected_areas'] = self::normalizeVacuumAreaIds($device['selected_areas'] ?? []);
            }
            if ($isUnifi) {
                foreach ([
                    'native_id',
                    'door_id',
                    'dps',
                    'dps_label',
                    'has_dps',
                    'open',
                    'temperature',
                    'humidity',
                    'motion',
                    'has_open',
                    'has_motion',
                    'motion_at',
                    'companion_of',
                ] as $key) {
                    if (array_key_exists($key, $device)) {
                        $row[$key] = $device[$key];
                    }
                }
            }
            if (self::isLockableDoor($row) || self::isOpenCloseSensor($row)) {
                $row['contact_kind'] = $this->contactRoleFor($row, $store);
                $row['can_assign_contact'] = self::isOpenCloseSensor($row);
            }
            $trig = $this->publicLastTrigger(
                is_array($triggers[$id] ?? null) ? $triggers[$id] : null,
                (bool) $row['on'],
                (string) ($row['kind'] ?? '')
            );
            if ($trig !== null) {
                $row['last_trigger'] = $trig;
            }
            if ($row['hidden']) {
                $hiddenDevices[] = $row;
            } else {
                $devices[] = $row;
            }
        }
        $devices = self::dropUnavailableNameDuplicates($devices);
        $devices = $this->applyIdOrder($devices, $store['device_order'] ?? []);
        $auto = new YarboHomeAutomations($this->projectRoot);
        $auto->adoptClientTimezone();

        return [
            'ok' => true,
            'enabled' => true,
            'server' => [
                'ok' => (bool) $live['ok'],
                'error' => (string) $live['error'],
            ],
            'devices' => $devices,
            'hidden_devices' => $hiddenDevices,
            'rooms' => $this->roomsPayload($store, $devices),
            'scenes' => $this->scenesPayload($store['scenes'], $devices),
            'paper_devices' => $this->paperDeviceList($store),
            'setup' => $this->setupStatus(),
            'fabric' => is_array($live['fabric'] ?? null) ? $live['fabric'] : [],
            'automations' => $auto->publicList(),
            'automation_devices' => $this->namedAutomationDevices($store),
            'server_timezone' => $auto->timezoneName(),
            'timezone' => $auto->timezonePublic(),
            'runner' => $auto->runnerPublic(),
            'sun_coords' => $auto->coordsPublic(),
            'restored_home' => $this->restoredHomeFrom,
            'doors_tile' => $this->unifiDoorsTileConfigured(),
            'doors_tile_sensors' => $this->unifiDoorSensorsTileConfigured(),
            'unifi_doors' => $this->unifiDoorsPayload($store),
        ];
    }

    /**
     * @param array{paper?: array<string, list<string>>} $store
     * @return list<array{id: string, name: string, assigned: list<string>}>
     */
    private function paperDeviceList(array $store): array
    {
        $paper = [];
        foreach ((new YarboPaperDevice($this->projectRoot))->publicDevices() as $tablet) {
            if (($tablet['kind'] ?? '') !== YarboPaperDevice::KIND_MONO) {
                continue;
            }
            $tid = (string) ($tablet['id'] ?? '');
            if ($tid === '') {
                continue;
            }
            $paper[] = [
                'id' => $tid,
                'name' => (string) ($tablet['name'] ?? 'PaperMono'),
                'assigned' => $store['paper'][$tid] ?? [],
            ];
        }

        return $paper;
    }

    /**
     * @return array{ok: bool, state: string, message: ?string, error: ?string, ready: bool, updated_at: ?string}
     */
    public function setupStatus(): array
    {
        $ready = $this->matterPortUp();
        $path = $this->projectRoot . '/data/matter-setup.json';
        $state = $ready ? 'done' : 'idle';
        $message = $ready ? 'Matter server is running' : null;
        $error = null;
        $updatedAt = null;
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $fileState = (string) ($decoded['state'] ?? '');
                $updatedAt = isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : null;
                if ($ready) {
                    $state = 'done';
                    $message = (string) ($decoded['message'] ?? $message);
                    $error = null;
                } else {
                    $state = $fileState !== '' ? $fileState : 'idle';
                    $message = isset($decoded['message']) ? (string) $decoded['message'] : null;
                    $error = isset($decoded['error']) ? (string) $decoded['error'] : null;
                    if ($state === 'running' && $updatedAt !== '') {
                        $stamp = strtotime($updatedAt) ?: 0;
                        if ($stamp > 0 && (time() - $stamp) > 240) {
                            $state = 'failed';
                            $error = $error !== null && $error !== ''
                                ? $error
                                : 'Matter setup took too long. Tap Set up Matter server to try again.';
                        }
                    }
                }
            }
        }

        return [
            'ok' => $ready || $state !== 'failed',
            'state' => $state,
            'message' => $message !== '' ? $message : null,
            'error' => $error !== '' ? $error : null,
            'ready' => $ready,
            'updated_at' => $updatedAt !== '' ? $updatedAt : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startSetup(): array
    {
        $status = $this->setupStatus();
        if (($status['ready'] ?? false) === true) {
            return [
                'ok' => true,
                'started' => false,
                'message' => 'Matter server is already running',
                'setup' => $status,
            ];
        }
        if (($status['state'] ?? '') === 'running') {
            return [
                'ok' => true,
                'started' => false,
                'message' => (string) ($status['message'] ?? 'Setup is already running'),
                'setup' => $status,
            ];
        }
        $script = $this->projectRoot . '/scripts/matter_setup.sh';
        $fallback = $this->projectRoot . '/scripts/lib/matter_server.sh';
        if (!is_file($script) && !is_file($fallback)) {
            return ['ok' => false, 'error' => 'Matter setup script is missing. Update the panel first.'];
        }
        $dataDir = $this->projectRoot . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            return ['ok' => false, 'error' => 'Could not create data directory'];
        }
        @file_put_contents($dataDir . '/matter-setup.json', json_encode([
            'state' => 'running',
            'message' => 'Setting up the Matter server. First time can take a few minutes.',
            'error' => null,
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        $log = $dataDir . '/matter-setup.log';
        $cmd = is_file($script)
            ? sprintf(
                'nohup bash %s >> %s 2>&1 < /dev/null &',
                escapeshellarg($script),
                escapeshellarg($log)
            )
            : sprintf(
                'nohup bash %s %s >> %s 2>&1 < /dev/null &',
                escapeshellarg($fallback),
                escapeshellarg($this->projectRoot),
                escapeshellarg($log)
            );
        if (!$this->spawnBackground($cmd, $log)) {
            @file_put_contents($dataDir . '/matter-setup.json', json_encode([
                'state' => 'failed',
                'message' => null,
                'error' => 'Could not start Matter setup',
                'updated_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES) . "\n");

            return ['ok' => false, 'error' => 'Could not start Matter setup'];
        }
        $status = $this->setupStatus();
        $status['state'] = 'running';
        $status['ready'] = false;
        $status['message'] = 'Setting up the Matter server. First time can take a few minutes.';

        return [
            'ok' => true,
            'started' => true,
            'message' => $status['message'],
            'setup' => $status,
        ];
    }

    private function spawnBackground(string $command, string $logFile): bool
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $home = getenv('HOME') ?: $this->projectRoot;
        $process = proc_open(
            ['bash', '-c', $command],
            $descriptorSpec,
            $pipes,
            $this->projectRoot,
            [
                'HOME' => $home,
                'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ]
        );
        if (!is_resource($process)) {
            return false;
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        proc_close($process);

        return true;
    }

    private function matterPortUp(): bool
    {
        $fp = @fsockopen('127.0.0.1', 5580, $errno, $errstr, 0.15);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function commission(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'error' => 'Paste a Matter pairing code or QR text'];
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $result = $agent->request(['op' => 'commission', 'code' => $code], 105.0);
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Pairing failed. Check the code is still valid and the device is on the LAN.'),
            ];
        }

        $incoming = is_array($result['devices'] ?? null) ? $result['devices'] : [];
        if ($incoming === []) {
            $states = $agent->request(['op' => 'states'], 2.5, false);
            $incoming = is_array($states['devices'] ?? null) ? $states['devices'] : [];
        }
        $cachePath = $this->projectRoot . '/data/home-nodes-cache.json';
        if ($incoming !== []) {
            $merged = self::unionDeviceLists($this->load()['last_devices'] ?? [], $incoming);
            $this->writeDeviceCache(
                $cachePath,
                $merged,
                YarboMatterFabric::storageMtime($this->projectRoot . '/data/matter-server')
            );
            $this->rememberDevices($merged);
        } else {
            @unlink($cachePath);
        }

        return ['ok' => true, 'message' => 'Device added.'];
    }

    /**
     * Re-interview paired bridges so Hue lights added in the Hue / Apple Home app appear.
     *
     * @return array<string, mixed>
     */
    public function scanDevices(): array
    {
        $agent = YarboMatterAgentClient::fromEnv();
        $result = $agent->request(['op' => 'scan'], 130.0);
        if (!($result['ok'] ?? false) && YarboMatterAgentClient::isUnknownCommandError($result)) {
            $result = $agent->request(['op' => 'nodes', 'quick' => false], 130.0);
        }
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Could not look for devices'),
            ];
        }

        $incoming = [];
        foreach (is_array($result['devices'] ?? null) ? $result['devices'] : [] as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row) || self::looksLikeUnreadyHueLight($row)) {
                continue;
            }
            $incoming[] = $row;
        }
        $store = $this->load();
        $previous = is_array($store['last_devices'] ?? null) ? $store['last_devices'] : [];
        $before = [];
        foreach ($previous as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $before[$id] = true;
            }
        }
        $hueNodes = [];
        foreach (is_array($result['interviewed'] ?? null) ? $result['interviewed'] : [] as $nid) {
            $nid = (int) $nid;
            if ($nid > 0) {
                $hueNodes[$nid] = true;
            }
        }
        foreach ($incoming as $row) {
            if (self::rowLooksHueBridge($row)) {
                $nid = self::rowNodeId($row);
                if ($nid > 0) {
                    $hueNodes[$nid] = true;
                }
            }
        }
        $merged = $incoming === []
            ? $previous
            : self::replaceHueNodeDevices($previous, $incoming, $hueNodes);
        if ($incoming !== []) {
            $merged = self::overlayDeviceStates($merged, $incoming);
        }
        $added = 0;
        foreach ($merged as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && !isset($before[$id])) {
                $added++;
            }
        }
        if ($incoming !== []) {
            $store = self::adoptHueEndpointAliases($store, $previous, $merged);
            $this->write($store);
        }
        $cachePath = $this->projectRoot . '/data/home-nodes-cache.json';
        if ($merged !== []) {
            $mtime = YarboMatterFabric::storageMtime($this->projectRoot . '/data/matter-server');
            $known = $this->readDeviceCacheBundle($cachePath)['disk_ids'];
            foreach ($incoming as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $known[] = $id;
                }
            }
            $this->writeDeviceCache($cachePath, $merged, $mtime, array_values(array_unique($known)));
            $this->rememberDevices($merged);
        }
        $message = $added === 0
            ? 'No new devices. Check Hidden, then tap Look for devices again. Do not pair the Hue Bridge a second time.'
            : ($added === 1 ? 'Found 1 new device.' : 'Found ' . $added . ' new devices.');

        return ['ok' => true, 'message' => $message, 'added' => $added];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function command(array $input): array
    {
        $id = trim((string) ($input['id'] ?? ''));
        $action = strtolower(trim((string) ($input['command'] ?? $input['home_action'] ?? '')));
        if ($id !== '' && !YarboUnifi::isHomeId($id)) {
            $cached = $this->cachedDeviceRow($id);
            if (is_array($cached) && self::looksLikeLeftoverHueEndpoint($cached)) {
                return ['ok' => false, 'error' => 'That row is not a real light. Hide or Remove it.'];
            }
            if (is_array($cached) && self::looksLikeUnreadyHueLight($cached)) {
                return ['ok' => false, 'error' => 'That light is still being read from the Hue Bridge. Tap Look for devices.'];
            }
            if (is_array($cached) && (YarboMatterFabric::reclassifyRow($cached)['kind'] ?? '') === 'switch') {
                return ['ok' => false, 'error' => 'That is a Hue switch, not a light.'];
            }
        }
        if ($action === 'colour' || $action === 'set_color' || $action === 'set_colour') {
            $action = 'color';
        }
        if ($action === 'colour_temp' || $action === 'kelvin') {
            $action = 'color_temp';
        }
        if ($id === '' || $action === '') {
            return ['ok' => false, 'error' => 'Device and action are required'];
        }
        if (!YarboUnifi::isHomeId($id) && $action === 'adaptive') {
            return $this->commandAdaptiveFollow($id, $input);
        }
        if (!YarboUnifi::isHomeId($id)) {
            if (in_array($action, ['color', 'color_temp', 'kelvin'], true) && empty($input['adaptive'])) {
                $this->pauseAdaptiveDevice($id);
            }
        }
        if (YarboUnifi::isHomeId($id)) {
            $result = (new YarboUnifi($this->projectRoot))->command($input);
            if ($result['ok'] ?? false) {
                $this->rememberCommandTrigger($id, $action, $input, $result);
            }

            return $result;
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $body = ['op' => 'command', 'id' => $id, 'action' => $action];
        $kind = strtolower(trim((string) ($input['kind'] ?? $input['device_kind'] ?? '')));
        if ($kind === '') {
            $kind = $this->deviceKindMap()[$id] ?? '';
        }
        if ($kind !== '') {
            $body['kind'] = $kind;
        }
        if ($action === 'brightness' && array_key_exists('brightness', $input)) {
            $body['brightness'] = (int) $input['brightness'];
        }
        if ($kind === self::KIND_VACUUM) {
            $clean = strtolower(trim((string) ($input['clean_mode'] ?? $input['mode'] ?? '')));
            if (in_array($clean, ['vacuum', 'mop', 'vacuum_mop'], true)) {
                $body['clean_mode'] = $clean;
            }
            if (array_key_exists('areas', $input)) {
                $body['areas'] = $input['areas'] === 'all'
                    ? 'all'
                    : self::normalizeVacuumAreaIds($input['areas']);
            }
        }
        if ($action === 'color') {
            $hex = $this->normalizeHex((string) ($input['hex'] ?? $input['color_hex'] ?? ''));
            if ($hex !== null) {
                $body['hex'] = $hex;
            }
            if (array_key_exists('hue', $input) && $input['hue'] !== null && $input['hue'] !== '') {
                $body['hue'] = max(0, min(360, (int) $input['hue']));
            }
            if (array_key_exists('saturation', $input) && $input['saturation'] !== null && $input['saturation'] !== '') {
                $body['saturation'] = max(0, min(100, (int) $input['saturation']));
            }
            if (!isset($body['hex']) && !isset($body['hue'])) {
                return ['ok' => false, 'error' => 'Colour needs a hex value'];
            }
        }
        if (($action === 'color_temp' || $action === 'kelvin') && array_key_exists('kelvin', $input)) {
            $body['kelvin'] = max(1500, min(8000, (int) $input['kelvin']));
        }
        if (in_array($action, ['setpoint', 'temperature', 'heating_setpoint'], true)) {
            $celsius = $input['celsius'] ?? $input['setpoint'] ?? $input['temperature'] ?? null;
            if ($celsius === null || $celsius === '') {
                return ['ok' => false, 'error' => 'Set a heating temperature'];
            }
            $body['celsius'] = max(5.0, min(35.0, (float) $celsius));
        }
        if (in_array($action, ['on', 'off', 'toggle'], true) && $kind === self::KIND_HEATER) {
            $celsius = $input['celsius'] ?? $input['setpoint'] ?? $input['temperature'] ?? null;
            if ($celsius === null || $celsius === '') {
                $celsius = $this->cachedDeviceSetpoint($id);
            }
            if ($celsius !== null) {
                $body['celsius'] = max(5.0, min(35.0, (float) $celsius));
            }
            if ($action === 'on' || $action === 'toggle') {
                $body['mode'] = 'heat';
            }
        }
        if (in_array($action, ['setpoint', 'temperature', 'heating_setpoint'], true)) {
            $body['mode'] = 'heat';
        }
        $timeout = $kind === self::KIND_HEATER ? 80.0 : 60.0;
        $result = $agent->request($body, $timeout);
        if (!($result['ok'] ?? false) && YarboMatterAgentClient::isUnknownCommandError($result)) {
            $agent->forceRestart();
            $result = $agent->request($body, $timeout);
        }
        if ($action === 'brightness' && !($result['ok'] ?? false)
            && YarboMatterFabric::isUnsupportedCluster((string) ($result['error'] ?? ''))) {
            $pct = (int) ($body['brightness'] ?? 0);
            $action = $pct > 0 ? 'on' : 'off';
            $body = ['op' => 'command', 'id' => $id, 'action' => $action];
            $result = $agent->request($body, 60.0);
        }
        $dropColor = [];
        if (in_array($action, ['color_temp', 'kelvin'], true) && !($result['ok'] ?? false)
            && YarboMatterFabric::isUnsupportedCluster((string) ($result['error'] ?? ''))) {
            $hex = self::kelvinToHex((int) ($body['kelvin'] ?? $this->adaptiveKelvinForDevice($id)));
            $action = 'color';
            $body = ['op' => 'command', 'id' => $id, 'action' => 'color', 'hex' => $hex];
            $result = $agent->request($body, 60.0);
        }
        if (in_array($action, ['color', 'color_temp', 'kelvin'], true) && !($result['ok'] ?? false)
            && YarboMatterFabric::isUnsupportedCluster((string) ($result['error'] ?? ''))) {
            $dropColor = $action === 'color'
                ? ['colorable' => false, 'color_hs' => false, 'color_xy' => false]
                : ['color_ct' => false];
            $action = 'on';
            $body = ['op' => 'command', 'id' => $id, 'action' => 'on'];
            $result = $agent->request($body, 60.0);
        }
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $this->friendlyMatterError((string) ($result['error'] ?? 'Command failed'))];
        }
        $patch = $this->commandStatePatch($id, $action, $body, $result);
        if ($dropColor !== []) {
            $patch = $dropColor + $patch;
        }
        if ($patch !== []) {
            $this->patchCachedDeviceState($id, $patch);
        }
        $out = ['ok' => true] + $patch;
        if (in_array($action, ['on', 'toggle'], true) && empty($input['adaptive'])) {
            $follow = $this->followAdaptiveAfterOn($id);
            if ($follow !== []) {
                $out = $follow + $out;
                $out['ok'] = true;
                $out['adaptive'] = true;
            }
        }
        $this->rememberCommandTrigger($id, $action, $input, $out);

        return $out;
    }

    public function friendlyMatterError(string $error): string
    {
        if (YarboMatterFabric::isUnsupportedCluster($error)) {
            return 'This device does not support that control';
        }
        if (!preg_match('/Node (\d+) is not \(yet\) available/i', $error, $matches)) {
            return $error;
        }
        $node = (int) $matches[1];
        $names = [];
        foreach ($this->load()['names'] as $id => $name) {
            if (!str_starts_with((string) $id, $node . ':')) {
                continue;
            }
            $label = trim((string) $name);
            if ($label !== '' && !in_array($label, $names, true)) {
                $names[] = $label;
            }
        }
        if ($names === []) {
            $who = 'Matter node ' . $node;
        } elseif (count($names) === 1) {
            $who = $names[0];
        } else {
            $who = implode(', ', array_slice($names, 0, 3));
            if (count($names) > 3) {
                $who .= ' and others';
            }
        }

        return $who . ' is not available yet (Matter node ' . $node
            . '). That number is the Hue Bridge or other Matter device the light sits on, not a room name.';
    }

    /**
     * @return array<string, mixed>
     */
    public function forgetNode(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return ['ok' => false, 'error' => 'Pick a device to remove'];
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $result = $agent->request(['op' => 'remove_node', 'node_id' => $nodeId], 25.0);
        @unlink($this->projectRoot . '/data/home-nodes-cache.json');
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Could not remove that Matter device'),
            ];
        }
        $store = $this->load();
        $prefix = $nodeId . ':';
        foreach (array_keys($store['names']) as $id) {
            if (str_starts_with((string) $id, $prefix)) {
                unset($store['names'][$id], $store['rooms'][$id]);
            }
        }
        $store['hidden'] = array_values(array_filter(
            $store['hidden'],
            static fn (string $id): bool => !str_starts_with($id, $prefix)
        ));
        $this->write($store);

        return ['ok' => true, 'message' => 'Removed from this panel'];
    }

    /**
     * @return array<string, mixed>
     */
    public function hideDevice(string $id, bool $hidden = true): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        // Relay outputs and PIR/rin companions stay on the UniFi Show on Home
        // tick (one port lists the whole USL-Relay). Hide them on this panel
        // instead of unticking, or homeRows expansion puts them straight back.
        if (YarboUnifi::isHomeId($id) && !YarboUnifi::isCompanionSensorId($id) && !YarboUnifi::isRelayHomeId($id)) {
            $ok = (new YarboUnifi($this->projectRoot))->setShowOnHome($id, !$hidden);
            if (!$ok) {
                return ['ok' => false, 'error' => 'Could not update the UniFi Home list'];
            }

            return [
                'ok' => true,
                'hidden' => $hidden,
                'message' => $hidden
                    ? 'Removed from Home. The UniFi device stays on your console.'
                    : 'Shown on the Home dashboard again',
            ];
        }
        $store = $this->load();
        $list = $store['hidden'];
        if ($hidden) {
            if (!in_array($id, $list, true)) {
                $list[] = $id;
            }
            foreach ($store['paper'] as $tid => $ids) {
                $store['paper'][$tid] = array_values(array_filter(
                    $ids,
                    static fn (string $item): bool => $item !== $id
                ));
            }
        } else {
            $list = array_values(array_filter($list, static fn (string $item): bool => $item !== $id));
        }
        $store['hidden'] = $this->normalizeIdList($list);
        $this->write($store);

        return [
            'ok' => true,
            'hidden' => $hidden,
            'message' => $hidden ? 'Hidden on this panel' : 'Shown on the Home dashboard again',
        ];
    }

    /**
     * Unpair a standalone Matter node, or hide one light on a Hue Bridge.
     *
     * @return array<string, mixed>
     */
    public function removeDevice(string $id, bool $wholeNode = false): array
    {
        $id = trim($id);
        if (YarboUnifi::isHomeId($id)) {
            return $this->hideDevice($id, true);
        }
        if ($id === '' || !str_contains($id, ':')) {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        [$nodeS] = explode(':', $id, 2);
        $nodeId = (int) $nodeS;
        if ($nodeId <= 0) {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        $live = $this->liveDevices(8.0);
        $onNode = 0;
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            if ((int) ($device['node_id'] ?? 0) === $nodeId) {
                $onNode++;
            }
        }
        if ($wholeNode || $onNode <= 1) {
            return $this->forgetNode($nodeId);
        }

        return [
            'ok' => false,
            'needs_hide' => true,
            'node_id' => $nodeId,
            'error' => 'This light is on a Hue Bridge (or similar). Matter cannot unpair one bulb. Hide it on this panel, or remove the whole bridge.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveScene(array $input): array
    {
        $store = $this->load();
        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(4));
        }
        $scene = $this->normalizeScene([
            'id' => $id,
            'name' => $input['name'] ?? '',
            'actions' => $input['actions'] ?? [],
        ]);
        if ($scene === null) {
            return ['ok' => false, 'error' => 'A scene needs a name and at least one device'];
        }
        $found = false;
        foreach ($store['scenes'] as $i => $existing) {
            if (($existing['id'] ?? '') === $id) {
                $store['scenes'][$i] = $scene;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $store['scenes'][] = $scene;
        }
        $this->write($store);

        return ['ok' => true, 'scene' => $scene];
    }

    public function deleteScene(string $id): array
    {
        $id = trim($id);
        $store = $this->load();
        $store['scenes'] = array_values(array_filter(
            $store['scenes'],
            static fn (array $scene): bool => ($scene['id'] ?? '') !== $id
        ));
        foreach ($store['paper'] as $tid => $ids) {
            $store['paper'][$tid] = array_values(array_filter(
                $ids,
                static fn (string $assigned): bool => $assigned !== 'scene:' . $id
            ));
        }
        if (($store['active_scene_id'] ?? '') === $id) {
            $store['active_scene_id'] = '';
        }
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function runScene(string $id): array
    {
        $scene = $this->findScene($id);
        if ($scene === null) {
            return ['ok' => false, 'error' => 'Scene not found'];
        }
        return $this->withTrigger('scene', (string) ($scene['name'] ?? ''), function () use ($scene, $id) {
        $errors = [];
        $kinds = $this->deviceKindMap();
        foreach ($scene['actions'] as $action) {
            $deviceId = (string) ($action['id'] ?? '');
            if ($deviceId === '') {
                continue;
            }
            $kind = $kinds[$deviceId] ?? '';
            $isHeater = $kind === self::KIND_HEATER;
            if (YarboUnifi::isHomeId($deviceId)) {
                $result = $this->command([
                    'id' => $deviceId,
                    'command' => empty($action['on']) ? 'off' : 'on',
                ]);
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
                continue;
            }
            if (empty($action['on'])) {
                $result = $this->command(['id' => $deviceId, 'command' => 'off']);
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
                continue;
            }
            if ($isHeater) {
                $setpoint = self::optionalCelsius($action['heating_setpoint'] ?? $action['celsius'] ?? null);
                if ($setpoint !== null) {
                    $result = $this->command([
                        'id' => $deviceId,
                        'command' => 'setpoint',
                        'celsius' => $setpoint,
                    ]);
                } else {
                    $result = $this->command(['id' => $deviceId, 'command' => 'on']);
                }
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
                continue;
            }
            $sent = false;
            if (array_key_exists('brightness', $action) && $action['brightness'] !== null) {
                $result = $this->command([
                    'id' => $deviceId,
                    'command' => 'brightness',
                    'brightness' => (int) $action['brightness'],
                ]);
                $sent = true;
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
            }
            $hex = $this->normalizeHex((string) ($action['color_hex'] ?? ''));
            $adaptive = !empty($action['adaptive']);
            $kelvin = null;
            if ($adaptive) {
                $hex = null;
                $this->unpauseAdaptiveDevice($deviceId);
                $kelvin = $this->adaptiveKelvinForDevice($deviceId);
            } elseif (!empty($action['color_temp'])) {
                $kelvin = (int) $action['color_temp'];
            }
            $caps = $this->deviceControlCaps($deviceId);
            if ($hex !== null && !$caps['colorable']) {
                $hex = null;
            }
            if ($kelvin && !$caps['color_ct'] && !$adaptive) {
                $kelvin = null;
            }
            if (($hex !== null || $kelvin) && !$sent) {
                $result = $this->command(['id' => $deviceId, 'command' => 'on']);
                $sent = true;
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
            }
            if ($hex !== null) {
                $result = $this->command(['id' => $deviceId, 'command' => 'color', 'hex' => $hex]);
                $sent = true;
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
            } elseif ($kelvin) {
                $result = $this->commandAdaptiveColor($deviceId, (int) $kelvin, $adaptive, $caps);
                $sent = true;
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                } elseif ($adaptive) {
                    $this->rememberAdaptiveApplied([$deviceId => (int) $kelvin]);
                }
            }
            if (!$sent) {
                $result = $this->command(['id' => $deviceId, 'command' => 'on']);
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => 'Scene partly failed: ' . $errors[0]];
        }
        $this->setActiveSceneId($id);

        return ['ok' => true, 'on' => true];
        });
    }

    /**
     * Turn off lights this scene turns on. Lights the scene leaves off stay off.
     *
     * @return array<string, mixed>
     */
    public function stopScene(string $id): array
    {
        $scene = $this->findScene($id);
        if ($scene === null) {
            return ['ok' => false, 'error' => 'Scene not found'];
        }

        return $this->withTrigger('scene', (string) ($scene['name'] ?? ''), function () use ($scene, $id) {
        $errors = [];
        foreach ($scene['actions'] as $action) {
            $deviceId = (string) ($action['id'] ?? '');
            if ($deviceId === '' || empty($action['on'])) {
                continue;
            }
            $result = $this->command(['id' => $deviceId, 'command' => 'off']);
            if (!($result['ok'] ?? false)) {
                $errors[] = (string) ($result['error'] ?? 'failed');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => 'Scene partly failed: ' . $errors[0]];
        }
        if ($this->load()['active_scene_id'] === $id) {
            $this->setActiveSceneId('');
        }

        return ['ok' => true, 'on' => false];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleScene(string $id): array
    {
        $scene = $this->findScene($id);
        if ($scene === null) {
            return ['ok' => false, 'error' => 'Scene not found'];
        }
        $live = $this->liveDevices(6.0);
        $byId = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $deviceId = (string) ($device['id'] ?? '');
            if ($deviceId !== '') {
                $byId[$deviceId] = $device;
            }
        }
        $tracked = ($this->load()['active_scene_id'] ?? '') === $id;
        if ($this->sceneIsActive($scene, $byId) || $tracked) {
            return $this->stopScene($id);
        }

        return $this->runScene($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveRoom(array $input): array
    {
        $store = $this->load();
        $id = trim((string) ($input['id'] ?? ''));
        $name = YarboHub::normalizeDisplayName((string) ($input['name'] ?? ''), 32);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Room needs a name'];
        }
        if ($id === '') {
            $id = 'r' . bin2hex(random_bytes(3));
            $store['room_defs'][] = ['id' => $id, 'name' => $name];
        } else {
            $found = false;
            foreach ($store['room_defs'] as $i => $def) {
                if (($def['id'] ?? '') === $id) {
                    $store['room_defs'][$i]['name'] = $name;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['ok' => false, 'error' => 'Unknown room'];
            }
        }
        $this->write($store);

        return ['ok' => true, 'room' => ['id' => $id, 'name' => $name]];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRoom(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'Pick a room'];
        }
        $store = $this->load();
        $store['room_defs'] = array_values(array_filter(
            $store['room_defs'],
            static fn (array $def): bool => ($def['id'] ?? '') !== $id
        ));
        foreach ($store['rooms'] as $deviceId => $roomId) {
            if ($roomId === $id) {
                unset($store['rooms'][$deviceId]);
                unset($store['groups'][$deviceId]);
            }
        }
        $store['group_defs'] = array_values(array_filter(
            $store['group_defs'],
            static fn (array $def): bool => ($def['room_id'] ?? '') !== $id
        ));
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignDeviceRoom(string $deviceId, string $roomId): array
    {
        $deviceId = trim($deviceId);
        $roomId = trim($roomId);
        if ($deviceId === '') {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        $store = $this->load();
        if ($roomId === '') {
            unset($store['rooms'][$deviceId]);
            unset($store['groups'][$deviceId]);
        } else {
            $known = false;
            foreach ($store['room_defs'] as $def) {
                if (($def['id'] ?? '') === $roomId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                return ['ok' => false, 'error' => 'Unknown room'];
            }
            $store['rooms'][$deviceId] = $roomId;
            $gid = (string) ($store['groups'][$deviceId] ?? '');
            if ($gid !== '' && $this->groupRoomId($store, $gid) !== $roomId) {
                unset($store['groups'][$deviceId]);
            }
        }
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function commandRoom(array $input): array
    {
        $roomId = trim((string) ($input['room_id'] ?? $input['id'] ?? ''));
        $action = strtolower(trim((string) ($input['command'] ?? $input['home_action'] ?? '')));
        if ($roomId === '' || $action === '') {
            return ['ok' => false, 'error' => 'Room and action are required'];
        }
        $known = false;
        $roomName = '';
        foreach ($this->load()['room_defs'] as $def) {
            if (($def['id'] ?? '') === $roomId) {
                $known = true;
                $roomName = (string) ($def['name'] ?? '');
                break;
            }
        }
        if (!$known) {
            return ['ok' => false, 'error' => 'Unknown room'];
        }
        $ids = $this->visibleDeviceIdsInRoom($roomId);
        if ($ids === []) {
            return ['ok' => false, 'error' => 'No devices in that room'];
        }
        $ids = $this->lightIdsAmong($ids);
        if ($action === 'brightness') {
            $ids = $this->dimmableIdsAmong($ids);
        }
        if ($action === 'adaptive') {
            $ids = $this->adaptiveIdsAmong($ids);
        }

        return $this->withTrigger('room', $roomName, function () use ($ids, $action, $input) {
            return $this->commandIdList($ids, $action, $input, 'No controllable devices', 'Room partly failed: ');
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveGroup(array $input): array
    {
        $store = $this->load();
        $id = trim((string) ($input['id'] ?? ''));
        $roomId = trim((string) ($input['room_id'] ?? ''));
        $name = YarboHub::normalizeDisplayName((string) ($input['name'] ?? ''), 32);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Group needs a name'];
        }
        if ($id === '') {
            $knownRoom = false;
            foreach ($store['room_defs'] as $def) {
                if (($def['id'] ?? '') === $roomId) {
                    $knownRoom = true;
                    break;
                }
            }
            if (!$knownRoom) {
                return ['ok' => false, 'error' => 'Pick a room first'];
            }
            $id = 'g' . bin2hex(random_bytes(3));
            $store['group_defs'][] = ['id' => $id, 'name' => $name, 'room_id' => $roomId];
        } else {
            $found = false;
            foreach ($store['group_defs'] as $i => $def) {
                if (($def['id'] ?? '') === $id) {
                    $store['group_defs'][$i]['name'] = $name;
                    $found = true;
                    $roomId = (string) ($def['room_id'] ?? $roomId);
                    break;
                }
            }
            if (!$found) {
                return ['ok' => false, 'error' => 'Unknown group'];
            }
        }
        $this->write($store);

        return ['ok' => true, 'group' => ['id' => $id, 'name' => $name, 'room_id' => $roomId]];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteGroup(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'Pick a group'];
        }
        $store = $this->load();
        $store['group_defs'] = array_values(array_filter(
            $store['group_defs'],
            static fn (array $def): bool => ($def['id'] ?? '') !== $id
        ));
        foreach ($store['groups'] as $deviceId => $groupId) {
            if ($groupId === $id) {
                unset($store['groups'][$deviceId]);
            }
        }
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignDeviceGroup(string $deviceId, string $groupId): array
    {
        $deviceId = trim($deviceId);
        $groupId = trim($groupId);
        if ($deviceId === '') {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        $store = $this->load();
        if ($groupId === '') {
            unset($store['groups'][$deviceId]);
            $this->write($store);

            return ['ok' => true];
        }
        $roomId = $this->groupRoomId($store, $groupId);
        if ($roomId === '') {
            return ['ok' => false, 'error' => 'Unknown group'];
        }
        $store['groups'][$deviceId] = $groupId;
        $store['rooms'][$deviceId] = $roomId;
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function commandGroup(array $input): array
    {
        $groupId = trim((string) ($input['group_id'] ?? $input['id'] ?? ''));
        $action = strtolower(trim((string) ($input['command'] ?? $input['home_action'] ?? '')));
        if ($groupId === '' || $action === '') {
            return ['ok' => false, 'error' => 'Group and action are required'];
        }
        $groupName = '';
        foreach ($this->load()['group_defs'] as $def) {
            if (($def['id'] ?? '') === $groupId) {
                $groupName = (string) ($def['name'] ?? '');
                break;
            }
        }
        if ($groupName === '' || $this->groupRoomId($this->load(), $groupId) === '') {
            return ['ok' => false, 'error' => 'Unknown group'];
        }
        $ids = $this->visibleDeviceIdsInGroup($groupId);
        if ($ids === []) {
            return ['ok' => false, 'error' => 'No devices in that group'];
        }
        $ids = $this->lightIdsAmong($ids);
        if ($action === 'brightness') {
            $ids = $this->dimmableIdsAmong($ids);
        }
        if ($action === 'adaptive') {
            $ids = $this->adaptiveIdsAmong($ids);
        }

        return $this->withTrigger('group', $groupName, function () use ($ids, $action, $input) {
            return $this->commandIdList($ids, $action, $input, 'No controllable devices', 'Group partly failed: ');
        });
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function commandIdList(array $ids, string $action, array $input, string $emptyError, string $partialPrefix): array
    {
        if ($ids === []) {
            return ['ok' => false, 'error' => $emptyError];
        }
        $ids = $this->filterCommandIds($ids, $action);
        if ($ids === []) {
            return ['ok' => false, 'error' => 'No controllable devices'];
        }
        $errors = [];
        foreach ($ids as $id) {
            $payload = ['id' => $id, 'command' => $action];
            if ($action === 'brightness' && array_key_exists('brightness', $input)) {
                $payload['brightness'] = (int) $input['brightness'];
            }
            if ($action === 'adaptive' && array_key_exists('adaptive', $input)) {
                $payload['adaptive'] = !empty($input['adaptive']);
            }
            if ($action === 'color' || $action === 'colour') {
                if (array_key_exists('hex', $input)) {
                    $payload['hex'] = $input['hex'];
                }
                if (array_key_exists('color_hex', $input)) {
                    $payload['color_hex'] = $input['color_hex'];
                }
            }
            if (($action === 'color_temp' || $action === 'kelvin') && array_key_exists('kelvin', $input)) {
                $payload['kelvin'] = (int) $input['kelvin'];
            }
            $result = $this->command($payload);
            if (!($result['ok'] ?? false)) {
                $errors[] = (string) ($result['error'] ?? 'failed');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => $partialPrefix . $errors[0]];
        }

        return ['ok' => true];
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withTrigger(string $via, string $name, callable $fn): mixed
    {
        $prev = $this->commandTrigger;
        $this->commandTrigger = ['via' => $via, 'name' => $name];
        try {
            return $fn();
        } finally {
            $this->commandTrigger = $prev;
        }
    }

    public function recordLastTrigger(string $id, bool $on, string $via = 'home', string $name = '', ?int $at = null): void
    {
        $id = trim($id);
        if ($id === '' || !$this->recordsLastTrigger($id)) {
            return;
        }
        $via = strtolower(trim($via));
        if (!in_array($via, ['home', 'room', 'group', 'scene', 'automation', 'elsewhere'], true)) {
            $via = 'home';
        }
        $name = YarboHub::normalizeDisplayName($name, 48);
        $at = $at ?? time();
        $store = $this->load();
        $all = is_array($store['last_trigger'] ?? null) ? $store['last_trigger'] : [];
        $all[$id] = [
            'on' => $on,
            'at' => $at,
            'via' => $via,
            'name' => $name,
            'text' => self::lastTriggerText($on, $via, $name, $at, $this->triggerTimezone()),
        ];
        if (count($all) > 300) {
            uasort($all, static fn (array $a, array $b): int => ((int) ($a['at'] ?? 0)) <=> ((int) ($b['at'] ?? 0)));
            $all = array_slice($all, -250, null, true);
        }
        $store['last_trigger'] = $all;
        $this->write($store);
    }

    public static function lastTriggerText(bool $on, string $via, string $name, int $at, string $timezone = 'Europe/Zurich'): string
    {
        $state = $on ? 'On' : 'Off';
        if ($via === 'elsewhere') {
            return $state . ' by something else (Apple Home or a switch)';
        }
        $who = match ($via) {
            'scene' => $name !== '' ? 'scene ' . $name : 'a scene',
            'automation' => $name !== '' ? 'automation ' . $name : 'an automation',
            'room' => $name !== '' ? 'room ' . $name : 'the room',
            'group' => $name !== '' ? 'group ' . $name : 'the group',
            default => 'Home',
        };
        $when = self::lastTriggerClock($at, $timezone);

        return $state . ' by ' . $who . ($when !== '' ? ' · ' . $when : '');
    }

    /**
     * @param array<string, mixed>|null $trig
     * @return array{on: bool, at: int, via: string, name: string, text: string}|null
     */
    private function publicLastTrigger(?array $trig, bool $on, string $kind): ?array
    {
        $kind = strtolower(trim($kind));
        if (in_array($kind, ['sensor', 'camera', 'door', 'hub', 'switch'], true)) {
            return null;
        }
        if ($trig === null) {
            return null;
        }
        $via = strtolower(trim((string) ($trig['via'] ?? 'home')));
        $name = (string) ($trig['name'] ?? '');
        $at = (int) ($trig['at'] ?? 0);
        if ((bool) ($trig['on'] ?? false) !== $on) {
            return [
                'on' => $on,
                'at' => 0,
                'via' => 'elsewhere',
                'name' => '',
                'text' => self::lastTriggerText($on, 'elsewhere', '', 0, $this->triggerTimezone()),
            ];
        }
        $text = trim((string) ($trig['text'] ?? ''));
        if ($text === '') {
            $text = self::lastTriggerText($on, $via, $name, $at, $this->triggerTimezone());
        }

        return [
            'on' => $on,
            'at' => $at,
            'via' => $via,
            'name' => $name,
            'text' => $text,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     */
    private function rememberCommandTrigger(string $id, string $action, array $input, array $result): void
    {
        if (!$this->recordsLastTrigger($id) || !empty($input['adaptive'])) {
            return;
        }
        $on = $this->triggerOnFromCommand($action, $input, $result);
        if ($on === null) {
            return;
        }
        $via = trim((string) ($input['trigger_via'] ?? $this->commandTrigger['via'] ?? 'home'));
        $name = trim((string) ($input['trigger_name'] ?? $this->commandTrigger['name'] ?? ''));
        $this->recordLastTrigger($id, $on, $via !== '' ? $via : 'home', $name);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     */
    private function triggerOnFromCommand(string $action, array $input, array $result): ?bool
    {
        if (array_key_exists('on', $result)) {
            if (in_array($action, ['on', 'off', 'toggle', 'brightness'], true)) {
                return (bool) $result['on'];
            }

            return null;
        }
        if ($action === 'on') {
            return true;
        }
        if ($action === 'off') {
            return false;
        }
        if ($action === 'brightness') {
            $pct = (int) ($input['brightness'] ?? $result['brightness'] ?? -1);

            return $pct < 0 ? null : $pct > 0;
        }

        return null;
    }

    private function recordsLastTrigger(string $id): bool
    {
        $parsed = YarboUnifi::parseHomeId($id);
        if ($parsed !== null) {
            $kind = (string) ($parsed['kind'] ?? '');

            return $kind === YarboUnifi::KIND_LIGHT || $kind === YarboUnifi::KIND_RELAY;
        }
        $kind = strtolower(trim((string) ($this->deviceKindMap()[$id] ?? self::KIND_LIGHT)));

        return !in_array($kind, ['sensor', 'camera', 'door', 'hub', 'switch'], true);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeContactKind(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $id => $role) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $out[$id] = self::normalizeContactRole($role);
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, array{on: bool, at: int, via: string, name: string, text: string}>
     */
    private function normalizeLastTrigger(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $tz = $this->triggerTimezone();
        $out = [];
        foreach ($raw as $id => $row) {
            $id = trim((string) $id);
            if ($id === '' || !is_array($row)) {
                continue;
            }
            $on = !empty($row['on']);
            $via = strtolower(trim((string) ($row['via'] ?? 'home')));
            if (!in_array($via, ['home', 'room', 'group', 'scene', 'automation', 'elsewhere'], true)) {
                $via = 'home';
            }
            $name = YarboHub::normalizeDisplayName((string) ($row['name'] ?? ''), 48);
            $at = isset($row['at']) && is_numeric($row['at']) ? (int) $row['at'] : 0;
            $out[$id] = [
                'on' => $on,
                'at' => $at,
                'via' => $via,
                'name' => $name,
                'text' => self::lastTriggerText($on, $via, $name, $at, $tz),
            ];
        }

        return $out;
    }

    private function triggerTimezone(): string
    {
        try {
            $name = (new YarboHomeAutomations($this->projectRoot))->timezoneName();
        } catch (\Throwable) {
            $name = 'Europe/Zurich';
        }

        return $name !== '' ? $name : 'Europe/Zurich';
    }

    public static function lastTriggerClock(int $at, string $timezone = 'Europe/Zurich'): string
    {
        if ($at <= 0) {
            return '';
        }
        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : 'Europe/Zurich');
        } catch (\Exception) {
            $tz = new \DateTimeZone('Europe/Zurich');
        }
        $when = (new \DateTimeImmutable('@' . $at))->setTimezone($tz);
        $now = new \DateTimeImmutable('now', $tz);
        if ($when->format('Y-m-d') === $now->format('Y-m-d')) {
            return $when->format('H:i');
        }

        return $when->format('j M H:i');
    }

    /**
     * Room and group On/Off only switch lights (Hue, Matter bulbs, UniFi floodlights).
     * Cameras, sensors, heaters, vacuums, relays, plugs, and switches are not lights.
     *
     * @param array<string, mixed> $device
     */
    public static function isRoomLight(array $device): bool
    {
        $id = trim((string) ($device['id'] ?? ''));
        $kind = strtolower(trim((string) ($device['kind'] ?? '')));
        $parsed = $id !== '' ? YarboUnifi::parseHomeId($id) : null;
        $isUnifi = $parsed !== null || strtolower((string) ($device['source'] ?? '')) === YarboUnifi::SOURCE;
        if ($isUnifi) {
            if ($parsed !== null) {
                $kind = strtolower((string) ($parsed['kind'] ?? $kind));
            }

            return $kind === self::KIND_LIGHT;
        }

        return $kind === '' || $kind === self::KIND_LIGHT;
    }

    /**
     * Room headers stay green only when a light is on.
     *
     * @param array<string, mixed> $device
     */
    public static function countsTowardRoomOn(array $device): bool
    {
        return self::isRoomLight($device);
    }

    /**
     * Match the website: sensors/cameras are not On/Off, and Access doors only unlock.
     */
    public static function deviceAcceptsCommand(string $id, string $action, ?string $kind = null): bool
    {
        $action = strtolower(trim($action));
        if ($action === 'colour') {
            $action = 'color';
        }
        if ($action === 'kelvin') {
            $action = 'color_temp';
        }
        $parsed = YarboUnifi::parseHomeId($id);
        if ($parsed !== null) {
            $kind = $parsed['kind'];
            if ($kind === YarboUnifi::KIND_SENSOR || $kind === YarboUnifi::KIND_CAMERA) {
                return false;
            }
            if ($kind === YarboUnifi::KIND_DOOR || $kind === YarboUnifi::KIND_HUB) {
                return in_array($action, ['unlock', 'lock', 'open', 'close', 'stop'], true);
            }
            if ($kind === YarboUnifi::KIND_LIGHT || $kind === YarboUnifi::KIND_RELAY) {
                return in_array($action, ['on', 'off', 'toggle'], true);
            }

            return false;
        }
        $kind = strtolower(trim((string) $kind));
        if (in_array($action, ['start', 'dock', 'pause', 'resume', 'mop', 'vacuum', 'select_areas'], true)) {
            return $kind === '' || $kind === self::KIND_VACUUM;
        }
        if ($kind === '') {
            $kind = self::KIND_LIGHT;
        }
        if (in_array($kind, ['camera', 'sensor', 'door', 'hub', 'switch'], true)) {
            return false;
        }
        if ($kind === self::KIND_HEATER) {
            return in_array($action, ['on', 'off', 'toggle', 'setpoint', 'temperature', 'heating_setpoint'], true);
        }
        if ($kind === self::KIND_VACUUM) {
            return in_array($action, [
                'on', 'off', 'toggle', 'start', 'stop', 'pause', 'resume', 'dock',
                'mop', 'vacuum', 'select_areas',
            ], true);
        }
        if ($kind === self::KIND_LIGHT) {
            return in_array($action, ['on', 'off', 'toggle', 'brightness', 'color', 'color_temp', 'pulse', 'adaptive'], true);
        }

        return in_array($action, ['on', 'off', 'toggle', 'brightness', 'color', 'color_temp'], true);
    }

    /**
     * Live dim/colour flags from the last Matter snapshot. Missing rows look like a plain on/off light.
     *
     * @return array{kind: string, dimmable: bool, colorable: bool, color_ct: bool}
     */
    public function deviceControlCaps(string $id): array
    {
        $id = trim($id);
        $row = [];
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $one) {
            if (is_array($one) && trim((string) ($one['id'] ?? '')) === $id) {
                $row = $one;
                break;
            }
        }
        if ($row === []) {
            foreach ($this->load()['last_devices'] ?? [] as $one) {
                if (is_array($one) && trim((string) ($one['id'] ?? '')) === $id) {
                    $row = $one;
                    break;
                }
            }
        }
        if ($row !== []) {
            $row = YarboMatterFabric::reclassifyRow($row);
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? self::KIND_LIGHT)));
        if ($kind === '') {
            $kind = self::KIND_LIGHT;
        }

        return [
            'kind' => $kind,
            'dimmable' => !empty($row['dimmable']),
            'colorable' => !empty($row['colorable']) || !empty($row['color_hs']) || !empty($row['color_xy']),
            'color_ct' => !empty($row['color_ct']),
        ];
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    public function filterCommandIds(array $ids, string $action): array
    {
        $kinds = $this->deviceKindMap();
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $kind = $kinds[$id] ?? null;
            if (self::deviceAcceptsCommand($id, $action, $kind)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Cache-only device rows for the automations runner (no agent wait).
     *
     * @return list<array<string, mixed>>
     */
    public function automationDevices(): array
    {
        $local = $this->localHomeDevices();
        $cache = $this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json');
        $devices = $cache !== []
            ? self::overlayDeviceStates($local['devices'], $cache)
            : $local['devices'];
        $devices = $this->mergeUnifiDevices($devices, true);
        $out = [];
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = trim((string) ($device['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => (string) ($device['name'] ?? $id),
                'kind' => (string) ($device['kind'] ?? self::KIND_LIGHT),
                'source' => (string) ($device['source'] ?? ''),
                'vendor' => (string) ($device['vendor'] ?? ''),
                'product' => (string) ($device['product'] ?? ''),
                'on' => YarboMatterFabric::attrBool($device['on'] ?? false),
                'open' => array_key_exists('open', $device) && $device['open'] !== null ? (bool) $device['open'] : null,
                'motion' => array_key_exists('motion', $device) && $device['motion'] !== null ? (bool) $device['motion'] : null,
                'has_open' => !empty($device['has_open']) || ((string) ($device['kind'] ?? '') === 'door')
                    || ((string) ($device['kind'] ?? '') === 'hub'),
                'has_motion' => !empty($device['has_motion']),
                'has_dps' => !empty($device['has_dps']),
                'motion_at' => isset($device['motion_at']) && is_numeric($device['motion_at'])
                    ? (int) $device['motion_at']
                    : 0,
                'temperature' => isset($device['temperature']) && is_numeric($device['temperature'])
                    ? (float) $device['temperature']
                    : null,
                'humidity' => isset($device['humidity']) && is_numeric($device['humidity'])
                    ? (float) $device['humidity']
                    : null,
                'companion_of' => (string) ($device['companion_of'] ?? ''),
                'dimmable' => !empty($device['dimmable']),
                'colorable' => !empty($device['colorable']),
                'color_hs' => !empty($device['color_hs']),
                'color_xy' => !empty($device['color_xy']),
                'color_ct' => !empty($device['color_ct']),
                'color_hex' => (string) ($device['color_hex'] ?? ''),
                'brightness' => isset($device['brightness']) && is_numeric($device['brightness'])
                    ? (int) $device['brightness']
                    : null,
                'color_temp' => isset($device['color_temp']) && is_numeric($device['color_temp'])
                    ? (int) $device['color_temp']
                    : null,
                'color_temp_min' => isset($device['color_temp_min']) && is_numeric($device['color_temp_min'])
                    ? (int) $device['color_temp_min']
                    : null,
                'color_temp_max' => isset($device['color_temp_max']) && is_numeric($device['color_temp_max'])
                    ? (int) $device['color_temp_max']
                    : null,
                'has_thermostat' => !empty($device['has_thermostat']),
                'heating_setpoint' => isset($device['heating_setpoint']) && is_numeric($device['heating_setpoint'])
                    ? (float) $device['heating_setpoint']
                    : null,
                'heating_min' => isset($device['heating_min']) && is_numeric($device['heating_min'])
                    ? (float) $device['heating_min']
                    : null,
                'heating_max' => isset($device['heating_max']) && is_numeric($device['heating_max'])
                    ? (float) $device['heating_max']
                    : null,
            ];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Full device list for Automations (includes UniFi not shown on Home).
     *
     * @param array<string, mixed> $store
     * @return list<array<string, mixed>>
     */
    private function namedAutomationDevices(array $store): array
    {
        $roomsById = [];
        foreach ($store['room_defs'] ?? [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $roomId = (string) ($def['id'] ?? '');
            $roomName = (string) ($def['name'] ?? '');
            if ($roomId !== '' && $roomName !== '') {
                $roomsById[$roomId] = $roomName;
            }
        }
        $out = [];
        foreach ($this->automationDevices() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row['name'] = (string) ($store['names'][$id] ?? $row['name'] ?? $id);
            $roomId = (string) ($store['rooms'][$id] ?? '');
            $roomName = $roomsById[$roomId] ?? '';
            $row['room_id'] = $roomName !== '' ? $roomId : '';
            $row['room'] = $roomName;
            $row['type_label'] = self::automationTypeLabel($row);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Product / model line for Automations (not the custom name).
     *
     * @param array<string, mixed> $row
     */
    public static function automationTypeLabel(array $row): string
    {
        $id = (string) ($row['id'] ?? '');
        $source = strtolower((string) ($row['source'] ?? ''));
        $product = trim((string) ($row['product'] ?? ''));
        $vendor = trim((string) ($row['vendor'] ?? ''));
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));
        $name = trim((string) ($row['name'] ?? ''));
        $isUnifi = YarboUnifi::isHomeId($id) || $source === YarboUnifi::SOURCE;
        if ($isUnifi) {
            return self::unifiAutomationTypeLabel($kind, $product, $id);
        }
        if ($product !== '' && strcasecmp($product, $name) !== 0) {
            return $product;
        }
        if ($vendor !== '' && strcasecmp($vendor, $name) !== 0) {
            return $vendor;
        }

        return match ($kind) {
            self::KIND_HEATER => 'Heater',
            self::KIND_VACUUM => 'Vacuum',
            self::KIND_PLUG => 'Plug',
            self::KIND_SWITCH => 'Switch',
            default => '',
        };
    }

    private static function unifiAutomationTypeLabel(string $kind, string $product, string $id): string
    {
        $blob = strtolower($product . ' ' . $id);
        if (str_contains($blob, 'floodlight motion') || str_contains($blob, ':pir-') || str_contains($id, 'pir-')) {
            return 'Floodlight motion';
        }
        if (str_contains($id, 'rin-') || str_contains($blob, 'relay input')) {
            return 'UniFi Relay input';
        }
        if ($product === 'Door position sensor' || str_contains($blob, ':dps-') || str_contains($id, 'dps-')) {
            return 'Door sensor';
        }
        if (preg_match('/\b(usp|ufp-?sense|up\s*sense|up-sense)\b/i', $product) === 1) {
            return 'USP Sensor';
        }
        if ($kind === 'light' || preg_match('/flood|ufl/i', $product) === 1) {
            return 'UniFi Floodlight';
        }
        if ($kind === 'relay' || preg_match('/\b(usl|ul)[-_ ]?relay\b/i', $product) === 1) {
            return 'UniFi Relay';
        }
        if ($kind === 'hub' || preg_match('/ua-?hub|gate.?hub/i', $product) === 1) {
            return 'UniFi Hub';
        }
        if ($kind === 'door') {
            return 'UniFi Door';
        }
        if ($kind === 'camera') {
            if ($product !== '' && preg_match('/protect camera/i', $product) !== 1) {
                return 'UniFi ' . $product;
            }

            return 'UniFi Camera';
        }
        if ($kind === 'sensor') {
            if ($product !== '' && preg_match('/protect sensor/i', $product) !== 1) {
                return $product;
            }

            return 'UniFi Sensor';
        }

        return $product !== '' ? $product : 'UniFi';
    }

    /**
     * @return array<string, string>
     */
    private function deviceKindMap(): array
    {
        $map = [];
        foreach ($this->load()['last_devices'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $row = YarboMatterFabric::reclassifyRow($row);
                $map[$id] = (string) ($row['kind'] ?? self::KIND_LIGHT);
            }
        }
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $row = YarboMatterFabric::reclassifyRow($row);
                $map[$id] = (string) ($row['kind'] ?? $map[$id] ?? self::KIND_LIGHT);
            }
        }

        return $map;
    }

    /**
     * @param list<mixed> $ids
     * @return array<string, mixed>
     */
    public function assignPaper(string $tabletId, array $ids): array
    {
        $tabletId = trim($tabletId);
        if ($tabletId === '') {
            return ['ok' => false, 'error' => 'Tablet is required'];
        }
        $store = $this->load();
        $store['paper'][$tabletId] = array_slice($this->normalizeIdList($ids), 0, self::PAPER_MAX);
        if (!$this->write($store)) {
            return ['ok' => false, 'error' => 'Could not save assignment'];
        }

        return ['ok' => true, 'assigned' => $store['paper'][$tabletId]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $this->normalizeIdList(is_array($input['ids'] ?? null) ? $input['ids'] : []);
        $kind = strtolower(trim((string) ($input['kind'] ?? $input['what'] ?? '')));
        if ($ids === [] && $kind !== 'paper') {
            return ['ok' => false, 'error' => 'Nothing to reorder'];
        }
        $store = $this->load();
        if ($kind === 'rooms') {
            $store['room_defs'] = $this->reorderById($store['room_defs'], $ids);
        } elseif ($kind === 'groups') {
            $store['group_defs'] = $this->reorderGroupsInRoom(
                $store['group_defs'],
                $ids,
                trim((string) ($input['room_id'] ?? ''))
            );
        } elseif ($kind === 'scenes') {
            $store['scenes'] = $this->reorderById($store['scenes'], $ids);
        } elseif ($kind === 'devices') {
            $store['device_order'] = $ids;
        } elseif ($kind === 'paper') {
            return $this->assignPaper((string) ($input['tablet_id'] ?? ''), $ids);
        } else {
            return ['ok' => false, 'error' => 'Unknown reorder'];
        }
        if (!$this->write($store)) {
            return ['ok' => false, 'error' => 'Could not save order'];
        }

        return ['ok' => true];
    }

    /**
     * Lights from Matter storage / last_devices. Does not contact the agent.
     *
     * @return array{ok: bool, error: string, devices: list<array<string, mixed>>, fabric: array<string, mixed>}
     */
    public function localHomeDevices(): array
    {
        $cachePath = $this->projectRoot . '/data/home-nodes-cache.json';
        $storageDir = $this->projectRoot . '/data/matter-server';
        $store = $this->load();
        $bundle = $this->readDeviceCacheBundle($cachePath);
        $remembered = $bundle['devices'] !== [] ? $bundle['devices'] : ($store['last_devices'] ?? []);
        $fromMeta = $this->devicesFromStoreHints($store);
        $fabricMtime = YarboMatterFabric::storageMtime($storageDir);
        $cacheFresh = $remembered !== []
            && !self::looksLikeUninterviewedStub($remembered)
            && $bundle['fabric_mtime'] > 0
            && $bundle['fabric_mtime'] >= $fabricMtime;
        if ($cacheFresh) {
            $devices = [];
            foreach ($remembered as $row) {
                if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $devices[] = YarboMatterFabric::reclassifyRow($row);
            }

            return [
                'ok' => true,
                'error' => '',
                'devices' => $devices,
                'fabric' => [
                    'source' => 'cache',
                    'storage_files' => YarboMatterFabric::storageFileNames($storageDir),
                    'storage_nodes' => 0,
                    'unreadable_files' => [],
                    'hint' => '',
                ],
            ];
        }
        $nodes = YarboMatterFabric::nodesFromDisk($storageDir);
        $fromDisk = YarboMatterFabric::flatten($nodes);
        $unreadable = YarboMatterFabric::unreadableStorageFiles($storageDir);
        $diskReady = $fromDisk !== [] && !self::looksLikeUninterviewedStub($fromDisk);
        if ($diskReady) {
            $devices = $remembered !== []
                ? self::overlayDeviceStates($fromDisk, $remembered, false)
                : $fromDisk;
            $devices = self::dropLeftoverHueEndpoints($devices);
            $source = 'disk';
            $this->writeDeviceCache($cachePath, $devices, $fabricMtime, self::deviceIdList($fromDisk));
            $this->rememberDevices($devices);
        } else {
            $devices = self::preferLiveOrRemembered($fromDisk, $remembered);
            $devices = self::preferLiveOrRemembered($devices, $fromMeta);
            $source = $remembered !== [] ? 'cache' : ($fromMeta !== [] ? 'meta' : '');
            if ($devices !== [] && !self::looksLikeUninterviewedStub($devices)) {
                $this->rememberDevices($devices);
            }
        }
        $ready = $this->matterPortUp();
        $ok = $devices !== [] || $ready;
        $hint = '';
        if ($devices === [] && $unreadable !== []) {
            $hint = 'Matter lights are saved on this Pi but the panel cannot read them (Docker wrote root-only files). Run: docker exec yarbo-matter-server sh -c \'chmod a+r /data/*.json /data/*.json.backup\' then refresh.';
        } elseif ($devices === [] && YarboMatterFabric::storageFileNames($storageDir) !== []) {
            $hint = 'A Matter fabric file is on disk but no lights were found in it. Do not pair the Hue Bridge again — paste the fabric dump from docs/home.md.';
        }

        return [
            'ok' => $ok,
            'error' => $hint !== '' ? $hint : ($ok ? '' : 'Matter server is not running yet'),
            'devices' => $devices,
            'fabric' => [
                'source' => $source,
                'storage_files' => YarboMatterFabric::storageFileNames($storageDir),
                'storage_nodes' => count($nodes),
                'unreadable_files' => $unreadable,
                'hint' => $hint,
            ],
        ];
    }

    private function unifiDoorsTileConfigured(): bool
    {
        try {
            return (new YarboUnifi($this->projectRoot))->doorsTileEnabled();
        } catch (\Throwable) {
            return true;
        }
    }

    private function unifiDoorSensorsTileConfigured(): bool
    {
        try {
            return (new YarboUnifi($this->projectRoot))->doorSensorsTileEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Compact Access door/hub rows for the Home strip under Vestaboard.
     * Independent of Show on Home — every lockable door is listed.
     *
     * @param array<string, mixed> $store
     * @return list<array<string, mixed>>
     */
    private function unifiDoorsPayload(array $store): array
    {
        if (!(new YarboHub($this->projectRoot))->enabled(YarboHub::MODULE_UNIFI)) {
            return [];
        }
        if (!$this->unifiDoorsTileConfigured()) {
            return [];
        }
        try {
            $rows = (new YarboUnifi($this->projectRoot))->doorsTileRows();
        } catch (\Throwable) {
            return [];
        }
        $names = is_array($store['names'] ?? null) ? $store['names'] : [];
        $out = [];
        foreach ($rows as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $defaultName = (string) ($device['name'] ?? $id);
            $row = [
                'id' => $id,
                'name' => is_string($names[$id] ?? null) && $names[$id] !== '' ? $names[$id] : $defaultName,
                'kind' => (string) ($device['kind'] ?? YarboUnifi::KIND_DOOR),
                'source' => YarboUnifi::SOURCE,
                'gate' => (bool) ($device['gate'] ?? false),
                'locked' => array_key_exists('locked', $device) ? (bool) $device['locked'] : null,
                'status' => (string) ($device['status'] ?? ''),
            ];
            foreach (['native_id', 'door_id', 'dps', 'dps_label', 'has_dps', 'has_open', 'open', 'product'] as $key) {
                if (array_key_exists($key, $device)) {
                    $row[$key] = $device[$key];
                }
            }
            $row['contact_kind'] = $this->contactRoleFor($row + $device, $store);
            $row['can_assign_contact'] = self::isOpenCloseSensor($row + $device);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Selected UniFi devices appear on Home like Matter rows.
     *
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    private function mergeUnifiDevices(array $devices, bool $all = false): array
    {
        if (!(new YarboHub($this->projectRoot))->enabled(YarboHub::MODULE_UNIFI)) {
            return $devices;
        }
        try {
            $unifi = new YarboUnifi($this->projectRoot);
            $extra = $all ? $unifi->allRows() : $unifi->homeRows();
        } catch (\Throwable) {
            return $devices;
        }
        if ($extra === []) {
            return $devices;
        }
        $byId = [];
        foreach ($extra as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $row;
        }
        $out = [];
        foreach ($devices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && isset($byId[$id])) {
                $out[] = $byId[$id];
                unset($byId[$id]);
                continue;
            }
            $out[] = $row;
        }
        foreach ($byId as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Disk topology plus the latest On/Off the agent has, without waiting on get_nodes.
     *
     * @return array{ok: bool, error: string, devices: list<array<string, mixed>>, fabric: array<string, mixed>}
     */
    private function devicesWithLiveState(): array
    {
        $local = $this->localHomeDevices();
        $states = $this->agentDeviceStates();
        // Empty live state must not overwrite the On/Off cache (stale agent / listen still starting).
        if ($states === []) {
            return $local;
        }
        $devices = self::overlayDeviceStates($local['devices'], $states);
        $this->writeDeviceCache($this->projectRoot . '/data/home-nodes-cache.json', $devices);
        $local['devices'] = $devices;

        return $local;
    }

    /**
     * Fast in-memory On/Off from the Matter agent. Never starts Docker.
     *
     * @return list<array<string, mixed>>
     */
    private function agentDeviceStates(): array
    {
        $agent = YarboMatterAgentClient::fromEnv();
        $result = $agent->request(['op' => 'states'], 2.5, false);
        if (($result['ok'] ?? false) !== true) {
            return [];
        }
        $devices = is_array($result['devices'] ?? null) ? $result['devices'] : [];
        if ($devices === [] || self::looksLikeUninterviewedStub($devices)) {
            return [];
        }

        return $devices;
    }

    /**
     * Copy live on/brightness/colour onto the saved device list. Missing ids are left unchanged.
     *
     * @param list<array<string, mixed>> $devices
     * @param list<array<string, mixed>> $states
     * @return list<array<string, mixed>>
     */
    public static function overlayDeviceStates(array $devices, array $states, bool $appendMissing = true): array
    {
        $byId = [];
        foreach ($states as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        if ($byId === []) {
            return $devices;
        }
        $heatersByNode = [];
        foreach ($byId as $liveId => $live) {
            if (!self::liveRowLooksHeater($live)) {
                continue;
            }
            $nid = self::rowNodeId($live, $liveId);
            if ($nid > 0) {
                $heatersByNode[$nid][] = $liveId;
            }
        }
        $used = [];
        $out = [];
        foreach ($devices as $device) {
            if (!is_array($device) || self::looksLikeLeftoverHueEndpoint($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '' && isset($byId[$id])) {
                $out[] = self::copyLiveDeviceFields($device, $byId[$id]);
                $used[$id] = true;
                continue;
            }
            $nid = self::rowNodeId($device, $id);
            $candidates = $heatersByNode[$nid] ?? [];
            $unused = [];
            foreach ($candidates as $liveId) {
                if (!isset($used[$liveId])) {
                    $unused[] = $liveId;
                }
            }
            if (self::cachedRowLooksHeater($device) && $nid > 0 && count($candidates) === 1 && $unused !== []) {
                $liveId = $unused[0];
                $used[$liveId] = true;
                $out[] = $byId[$liveId];
                continue;
            }
            $out[] = $device;
        }
        foreach ($byId as $liveId => $live) {
            if (!$appendMissing || isset($used[$liveId]) || self::looksLikeLeftoverHueEndpoint($live)) {
                continue;
            }
            if (self::liveRowLooksHeater($live)) {
                $nid = self::rowNodeId($live, $liveId);
                $already = false;
                foreach ($out as $existing) {
                    if (!is_array($existing) || !self::liveRowLooksHeater($existing)) {
                        continue;
                    }
                    if ($nid > 0 && self::rowNodeId($existing) === $nid) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $out[] = $live;
                }
                continue;
            }
            if (self::liveRowLooksAddableDevice($live)) {
                $out[] = $live;
            }
        }

        return $out;
    }

    /**
     * An old Matter node that is offline but still named "Spot 5" next to the live Hue Spot 5.
     *
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    public static function dropUnavailableNameDuplicates(array $devices): array
    {
        $liveNodesByName = [];
        foreach ($devices as $row) {
            if (!is_array($row) || ($row['available'] ?? true) === false) {
                continue;
            }
            if (YarboUnifi::isHomeId((string) ($row['id'] ?? ''))) {
                continue;
            }
            $name = self::duplicateNameKey($row);
            $nid = self::rowNodeId($row);
            if ($name !== '' && $nid > 0) {
                $liveNodesByName[$name][$nid] = true;
            }
        }
        $out = [];
        foreach ($devices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (($row['available'] ?? true) === false && !YarboUnifi::isHomeId($id)) {
                $name = self::duplicateNameKey($row);
                $nid = self::rowNodeId($row);
                $liveNodes = $liveNodesByName[$name] ?? [];
                $otherNode = false;
                foreach ($liveNodes as $liveNid => $_) {
                    if ((int) $liveNid !== $nid) {
                        $otherNode = true;
                        break;
                    }
                }
                if ($name !== '' && $otherNode) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function duplicateNameKey(array $row): string
    {
        $name = strtolower(trim((string) ($row['name'] ?? '')));
        if ($name === '' || str_contains($name, ':') || str_starts_with($name, 'matter node ')) {
            return '';
        }

        return $name;
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    public static function dropLeftoverHueEndpoints(array $devices): array
    {
        $out = [];
        $seen = [];
        foreach ($devices as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return list<string>
     */
    public static function deviceIdList(array $devices): array
    {
        $ids = [];
        foreach ($devices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedDeviceRow(string $id): ?array
    {
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        foreach ($this->load()['last_devices'] ?? [] as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Hue Bridged Node that only has a name so far — no On/Off, brightness, or colour yet.
     *
     * @param array<string, mixed> $row
     */
    public static function looksLikeUnreadyHueLight(array $row): bool
    {
        if (YarboUnifi::isHomeId((string) ($row['id'] ?? ''))) {
            return false;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? 'light')));
        if ($kind !== '' && $kind !== 'light') {
            return false;
        }
        if (array_key_exists('has_on_off', $row)) {
            return $row['has_on_off'] === false;
        }
        $blob = strtolower(trim(implode(' ', [
            (string) ($row['product'] ?? ''),
            (string) ($row['vendor'] ?? ''),
            (string) ($row['source'] ?? ''),
        ])));
        $hueish = !empty($row['bridge'])
            || str_contains($blob, 'hue')
            || str_contains($blob, 'signify');
        if (!$hueish) {
            return false;
        }

        return true;
    }

    /**
     * Dummy Hue Bridge endpoints named like "BSB003 96" (product + endpoint, no bulb label).
     *
     * @param array<string, mixed> $row
     */
    public static function looksLikeLeftoverHueEndpoint(array $row): bool
    {
        $name = trim((string) ($row['name'] ?? ''));
        $endpoint = (int) ($row['endpoint'] ?? 0);
        if ($endpoint <= 0) {
            $id = trim((string) ($row['id'] ?? ''));
            if (str_contains($id, ':')) {
                $endpoint = (int) explode(':', $id, 2)[1];
            }
        }
        if ($name === '' || $endpoint <= 0) {
            return false;
        }
        if (preg_match('/^[A-Z]{2,}[A-Z0-9]*[0-9][A-Z0-9]*\s+' . preg_quote((string) $endpoint, '/') . '$/i', $name)) {
            return true;
        }
        $suffix = ' ' . $endpoint;
        foreach ([(string) ($row['product'] ?? ''), (string) ($row['source'] ?? '')] as $base) {
            $base = trim($base);
            if ($base === '' || strcasecmp($name, $base . $suffix) !== 0) {
                continue;
            }
            $low = strtolower($base);
            if (str_contains($low, 'bridge') || preg_match('/^[a-z]{2,}[a-z0-9]*[0-9][a-z0-9]*$/', $low)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extra live lights/plugs/switches the cache has not stored yet (new Hue bulbs on the Bridge).
     *
     * @param array<string, mixed> $row
     */
    public static function liveRowLooksAddableDevice(array $row): bool
    {
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));

        return in_array($kind, [self::KIND_LIGHT, self::KIND_PLUG, self::KIND_SWITCH], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function liveRowLooksHeater(array $row): bool
    {
        if (strtolower(trim((string) ($row['kind'] ?? ''))) === self::KIND_HEATER) {
            return true;
        }
        if (!empty($row['has_thermostat'])) {
            return true;
        }

        return self::optionalCelsius($row['heating_setpoint'] ?? null) !== null
            || self::optionalCelsius($row['local_temperature'] ?? null) !== null
            || (isset($row['system_mode']) && $row['system_mode'] !== null && $row['system_mode'] !== '');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function cachedRowLooksHeater(array $row): bool
    {
        if (self::liveRowLooksHeater($row)) {
            return true;
        }
        $labels = strtolower(trim(implode(' ', [
            (string) ($row['name'] ?? ''),
            (string) ($row['product'] ?? ''),
            (string) ($row['vendor'] ?? ''),
            (string) ($row['source'] ?? ''),
        ])));

        return YarboMatterFabric::nameLooksHeater($labels);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function rowNodeId(array $row, string $id = ''): int
    {
        $nid = (int) ($row['node_id'] ?? 0);
        if ($nid > 0) {
            return $nid;
        }
        $id = $id !== '' ? $id : trim((string) ($row['id'] ?? ''));
        if (!str_contains($id, ':')) {
            return 0;
        }

        return (int) explode(':', $id, 2)[0];
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $live
     * @return array<string, mixed>
     */
    public static function copyLiveDeviceFields(array $device, array $live): array
    {
        if (array_key_exists('on', $live)) {
            $device['on'] = YarboMatterFabric::attrBool($live['on']);
        }
        if (array_key_exists('brightness', $live)) {
            $device['brightness'] = $live['brightness'] === null || $live['brightness'] === ''
                ? null
                : (int) $live['brightness'];
        }
        if (array_key_exists('available', $live)) {
            $device['available'] = (bool) $live['available'];
        }
        foreach (['hue', 'saturation', 'color_temp'] as $key) {
            if (array_key_exists($key, $live) && $live[$key] !== null && $live[$key] !== '') {
                $device[$key] = $live[$key];
            }
        }
        if (array_key_exists('color_hex', $live)) {
            $hex = trim((string) ($live['color_hex'] ?? ''));
            if ($hex !== '') {
                $device['color_hex'] = $hex;
            }
        }
        foreach (['dimmable', 'colorable', 'color_hs', 'color_xy', 'color_ct', 'has_on_off'] as $flag) {
            if (array_key_exists($flag, $live)) {
                $device[$flag] = (bool) $live[$flag];
            }
        }
        foreach (['local_temperature', 'heating_setpoint', 'heating_min', 'heating_max'] as $key) {
            if (!array_key_exists($key, $live) || $live[$key] === null || $live[$key] === '') {
                continue;
            }
            $celsius = self::optionalCelsius($live[$key]);
            if ($celsius !== null) {
                $device[$key] = $celsius;
            }
        }
        if (array_key_exists('has_thermostat', $live)) {
            $device['has_thermostat'] = (bool) $live['has_thermostat'];
        }
        if (array_key_exists('system_mode', $live) && $live['system_mode'] !== null && $live['system_mode'] !== '') {
            $device['system_mode'] = (int) $live['system_mode'];
        }
        if (array_key_exists('kind', $live) && trim((string) $live['kind']) !== '') {
            $device['kind'] = (string) $live['kind'];
        }
        if (array_key_exists('endpoint', $live) && $live['endpoint'] !== null && $live['endpoint'] !== '') {
            $device['endpoint'] = (int) $live['endpoint'];
        }
        if (array_key_exists('node_id', $live) && (int) $live['node_id'] > 0) {
            $device['node_id'] = (int) $live['node_id'];
        }
        foreach (['vacuum_status', 'vacuum_clean'] as $key) {
            if (array_key_exists($key, $live) && $live[$key] !== null && $live[$key] !== '') {
                $device[$key] = (string) $live[$key];
            }
        }
        if (array_key_exists('vacuum_battery', $live)) {
            $percent = self::optionalPercent($live['vacuum_battery']);
            if ($percent !== null && $percent > 0) {
                $device['vacuum_battery'] = $percent;
            }
        }
        foreach (['can_vacuum', 'can_mop', 'can_vacuum_mop', 'can_pause'] as $flag) {
            if (array_key_exists($flag, $live)) {
                $device[$flag] = (bool) $live[$flag];
            }
        }
        if (!empty($live['areas'])) {
            $device['areas'] = self::normalizeVacuumAreas($live['areas']);
        }
        if (!empty($live['selected_areas'])) {
            $device['selected_areas'] = self::normalizeVacuumAreaIds($live['selected_areas']);
        }

        return $device;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function commandStatePatch(string $id, string $action, array $body, array $result): array
    {
        $patch = [];
        if (array_key_exists('on', $result)) {
            $patch['on'] = YarboMatterFabric::attrBool($result['on']);
        } elseif ($action === 'on') {
            $patch['on'] = true;
        } elseif ($action === 'off') {
            $patch['on'] = false;
        } elseif ($action === 'toggle') {
            $current = $this->cachedDeviceOn($id);
            if ($current !== null) {
                $patch['on'] = !$current;
            }
        }
        if ($action === 'brightness' && array_key_exists('brightness', $body)) {
            $patch['brightness'] = max(0, min(100, (int) $body['brightness']));
            $patch['on'] = $patch['brightness'] > 0;
        } elseif ($action === 'color' || $action === 'color_temp' || $action === 'kelvin') {
            $patch['on'] = true;
            if ($action === 'color') {
                $hex = $this->normalizeHex((string) ($result['color_hex'] ?? $body['hex'] ?? ''));
                if ($hex !== null) {
                    $patch['color_hex'] = $hex;
                }
            }
            if (($action === 'color_temp' || $action === 'kelvin') && isset($body['kelvin'])) {
                $patch['color_temp'] = (int) $body['kelvin'];
            }
        }
        if (in_array($action, ['setpoint', 'temperature', 'heating_setpoint'], true)) {
            $celsius = self::optionalCelsius($result['heating_setpoint'] ?? $body['celsius'] ?? null);
            if ($celsius !== null) {
                $patch['heating_setpoint'] = $celsius;
            }
            $patch['on'] = true;
        } elseif ($action === 'on') {
            $celsius = self::optionalCelsius($result['heating_setpoint'] ?? $body['celsius'] ?? null);
            if ($celsius !== null) {
                $patch['heating_setpoint'] = $celsius;
            }
        }
        if (array_key_exists('system_mode', $result) && $result['system_mode'] !== null && $result['system_mode'] !== '') {
            $patch['system_mode'] = (int) $result['system_mode'];
        }
        if (!empty($result['vacuum_status'])) {
            $patch['vacuum_status'] = (string) $result['vacuum_status'];
        }
        if (!empty($result['vacuum_clean'])) {
            $patch['vacuum_clean'] = (string) $result['vacuum_clean'];
        }
        $battery = self::optionalPercent($result['vacuum_battery'] ?? null);
        if ($battery !== null && $battery > 0) {
            $patch['vacuum_battery'] = $battery;
        }
        if (array_key_exists('selected_areas', $result)) {
            $patch['selected_areas'] = self::normalizeVacuumAreaIds($result['selected_areas']);
        }

        return $patch;
    }

    private function cachedDeviceOn(string $id): ?bool
    {
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id && array_key_exists('on', $row)) {
                return YarboMatterFabric::attrBool($row['on']);
            }
        }
        foreach ($this->load()['last_devices'] ?? [] as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id && array_key_exists('on', $row)) {
                return YarboMatterFabric::attrBool($row['on']);
            }
        }

        return null;
    }

    private function cachedDeviceSetpoint(string $id): ?float
    {
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $id) {
                continue;
            }
            $celsius = self::optionalCelsius($row['heating_setpoint'] ?? null);
            if ($celsius !== null) {
                return $celsius;
            }
        }
        foreach ($this->load()['last_devices'] ?? [] as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $id) {
                continue;
            }
            $celsius = self::optionalCelsius($row['heating_setpoint'] ?? null);
            if ($celsius !== null) {
                return $celsius;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function patchCachedDeviceState(string $id, array $fields): void
    {
        $path = $this->projectRoot . '/data/home-nodes-cache.json';
        $devices = $this->readDeviceCache($path);
        if ($devices === []) {
            $devices = $this->load()['last_devices'] ?? [];
        }
        $found = false;
        foreach ($devices as $i => $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $id) {
                continue;
            }
            $devices[$i] = $row + [];
            foreach ($fields as $key => $value) {
                $devices[$i][$key] = $value;
            }
            $found = true;
            break;
        }
        if (!$found && $id !== '') {
            $devices[] = ['id' => $id] + $fields;
        }
        if ($devices !== []) {
            $this->writeDeviceCache($path, $devices);
        }
    }

    /**
     * @return array{ok: bool, error: string, devices: list<array<string, mixed>>, fabric?: array<string, mixed>}
     */
    private function liveDevices(float $timeout): array
    {
        $local = $this->devicesWithLiveState();
        if ($timeout <= 0 || $local['devices'] !== []) {
            return $local;
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $nodes = $agent->request(['op' => 'nodes', 'quick' => true], max(8.0, $timeout), false);
        $liveOk = ($nodes['ok'] ?? false) === true;
        $devices = is_array($nodes['devices'] ?? null) ? $nodes['devices'] : [];
        $classified = [];
        foreach ($devices as $row) {
            $classified[] = is_array($row) ? YarboMatterFabric::reclassifyRow($row) : $row;
        }
        $devices = $classified;
        $fabric = is_array($nodes['fabric'] ?? null) ? $nodes['fabric'] : ($local['fabric'] ?? []);
        $error = (string) ($nodes['error'] ?? $local['error']);
        if ($liveOk && $devices !== [] && !self::looksLikeUninterviewedStub($devices)) {
            $cachePath = $this->projectRoot . '/data/home-nodes-cache.json';
            $this->writeDeviceCache($cachePath, $devices);
            $this->rememberDevices($devices);

            return [
                'ok' => true,
                'error' => '',
                'devices' => $devices,
                'fabric' => $fabric,
            ];
        }

        $devices = self::preferLiveOrRemembered($devices, $local['devices']);

        return [
            'ok' => $liveOk || $devices !== [],
            'error' => ($liveOk || $devices !== []) ? '' : $error,
            'devices' => $devices,
            'fabric' => $fabric,
        ];
    }

    /**
     * Add newly paired rows onto the remembered list without dropping existing devices.
     *
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $row
     */
    public static function rowLooksHueBridge(array $row): bool
    {
        if (!empty($row['bridge'])) {
            return true;
        }
        $blob = strtolower(trim(implode(' ', [
            (string) ($row['product'] ?? ''),
            (string) ($row['vendor'] ?? ''),
            (string) ($row['source'] ?? ''),
        ])));

        return str_contains($blob, 'hue') || str_contains($blob, 'signify');
    }

    /**
     * Drop remembered Hue ids on interviewed nodes that the live flatten no longer has.
     *
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $incoming
     * @param array<int, true> $hueNodeIds
     * @return list<array<string, mixed>>
     */
    public static function replaceHueNodeDevices(array $base, array $incoming, array $hueNodeIds): array
    {
        if ($incoming === [] || $hueNodeIds === []) {
            return self::unionDeviceLists($base, $incoming);
        }
        $byId = [];
        foreach ($base as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $nid = self::rowNodeId($row, $id);
            if ($nid > 0 && isset($hueNodeIds[$nid])) {
                continue;
            }
            $byId[$id] = $row;
        }
        foreach ($incoming as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            $byId[$id] = self::copyLiveDeviceFields($byId[$id], $row);
        }

        return array_values($byId);
    }

    /**
     * Copy room/group/name from a dropped Hue parent id onto the live child with the same name.
     *
     * @param array<string, mixed> $store
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $live
     * @return array<string, mixed>
     */
    public static function adoptHueEndpointAliases(array $store, array $previous, array $live): array
    {
        $liveIds = [];
        $liveByNodeName = [];
        foreach ($live as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $liveIds[$id] = true;
            $nid = self::rowNodeId($row, $id);
            $name = self::duplicateNameKey($row);
            if ($name === '' && isset($store['names'][$id])) {
                $name = strtolower(trim((string) $store['names'][$id]));
            }
            if ($nid > 0 && $name !== '') {
                $liveByNodeName[$nid][$name][] = $id;
            }
        }
        foreach ($previous as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldId = trim((string) ($row['id'] ?? ''));
            if ($oldId === '' || isset($liveIds[$oldId])) {
                continue;
            }
            $nid = self::rowNodeId($row, $oldId);
            $name = self::duplicateNameKey($row);
            if ($name === '' && isset($store['names'][$oldId])) {
                $name = strtolower(trim((string) $store['names'][$oldId]));
            }
            $candidates = $liveByNodeName[$nid][$name] ?? [];
            if ($nid <= 0 || $name === '' || count($candidates) !== 1) {
                continue;
            }
            $newId = $candidates[0];
            foreach (['rooms', 'groups', 'names', 'contact_kind'] as $mapKey) {
                $mapping = is_array($store[$mapKey] ?? null) ? $store[$mapKey] : [];
                if (!isset($mapping[$newId]) && isset($mapping[$oldId])) {
                    $mapping[$newId] = $mapping[$oldId];
                }
                unset($mapping[$oldId]);
                $store[$mapKey] = $mapping;
            }
            if (is_array($store['hidden'] ?? null)) {
                $hidden = [];
                foreach ($store['hidden'] as $item) {
                    $hid = trim((string) $item);
                    $hidden[] = $hid === $oldId ? $newId : $item;
                }
                $store['hidden'] = array_values(array_unique($hidden));
            }
            if (is_array($store['device_order'] ?? null)) {
                $store['device_order'] = array_map(
                    static fn ($item) => trim((string) $item) === $oldId ? $newId : $item,
                    $store['device_order']
                );
            }
            if (is_array($store['paper'] ?? null)) {
                foreach ($store['paper'] as $tablet => $ids) {
                    if (!is_array($ids)) {
                        continue;
                    }
                    $store['paper'][$tablet] = array_map(
                        static fn ($item) => trim((string) $item) === $oldId ? $newId : $item,
                        $ids
                    );
                }
            }
            foreach ($store['scenes'] ?? [] as $i => $scene) {
                if (!is_array($scene) || !is_array($scene['actions'] ?? null)) {
                    continue;
                }
                foreach ($scene['actions'] as $j => $action) {
                    if (is_array($action) && trim((string) ($action['id'] ?? '')) === $oldId) {
                        $store['scenes'][$i]['actions'][$j]['id'] = $newId;
                    }
                }
            }
        }

        return $store;
    }

    /**
     * Add newly paired rows onto the remembered list without dropping existing devices.
     *
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    public static function unionDeviceLists(array $base, array $incoming): array
    {
        $byId = [];
        foreach ($base as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        foreach ($incoming as $row) {
            if (!is_array($row) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            $merged = self::copyLiveDeviceFields($byId[$id], $row);
            foreach (['name', 'vendor', 'product', 'source'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $merged[$key] = $value;
                }
            }
            $byId[$id] = $merged;
        }

        return array_values($byId);
    }

    /**
     * Keep the last non-empty Matter list. An empty live reply must not wipe devices.
     *
     * @param list<array<string, mixed>> $live
     * @param list<array<string, mixed>> $remembered
     * @return list<array<string, mixed>>
     */
    public static function preferLiveOrRemembered(array $live, array $remembered): array
    {
        if ($live === [] || (self::looksLikeUninterviewedStub($live) && $remembered !== [])) {
            return $remembered;
        }

        return $live;
    }

    /**
     * An empty Matter node stub (no Hue endpoints yet) must not hide saved lights.
     *
     * @param list<array<string, mixed>> $devices
     */
    public static function looksLikeUninterviewedStub(array $devices): bool
    {
        if ($devices === []) {
            return false;
        }
        foreach ($devices as $row) {
            if (!is_array($row)) {
                return false;
            }
            $name = (string) ($row['name'] ?? '');
            $vendor = trim((string) ($row['vendor'] ?? ''));
            $product = trim((string) ($row['product'] ?? ''));
            if (!str_starts_with($name, 'Matter node ') || $vendor !== '' || $product !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeLastDevices(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || YarboUnifi::isHomeId($id) || self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $slim = [
                'id' => $id,
                'node_id' => (int) ($row['node_id'] ?? 0),
                'endpoint' => (int) ($row['endpoint'] ?? 0),
                'name' => (string) ($row['name'] ?? $id),
                'kind' => (string) ($row['kind'] ?? self::KIND_LIGHT),
                'vendor' => (string) ($row['vendor'] ?? ''),
                'product' => (string) ($row['product'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'bridge' => (bool) ($row['bridge'] ?? false),
                'on' => YarboMatterFabric::attrBool($row['on'] ?? false),
                'brightness' => isset($row['brightness']) ? (int) $row['brightness'] : null,
                'dimmable' => (bool) ($row['dimmable'] ?? false),
                'colorable' => (bool) ($row['colorable'] ?? false),
                'color_hs' => (bool) ($row['color_hs'] ?? false),
                'color_xy' => (bool) ($row['color_xy'] ?? false),
                'color_ct' => (bool) ($row['color_ct'] ?? false),
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'available' => (bool) ($row['available'] ?? true),
            ];
            $kind = strtolower((string) ($row['kind'] ?? ''));
            if ($kind === self::KIND_VACUUM || YarboMatterFabric::nameLooksVacuum((string) ($row['name'] ?? '') . ' ' . (string) ($row['product'] ?? ''))) {
                $slim['vacuum_status'] = (string) ($row['vacuum_status'] ?? '');
                $slim['vacuum_clean'] = (string) ($row['vacuum_clean'] ?? 'vacuum');
                $battery = self::optionalPercent($row['vacuum_battery'] ?? null);
                if ($battery !== null && $battery > 0) {
                    $slim['vacuum_battery'] = $battery;
                }
                $slim['can_vacuum'] = array_key_exists('can_vacuum', $row) ? (bool) $row['can_vacuum'] : true;
                $slim['can_mop'] = !empty($row['can_mop']);
                $slim['can_vacuum_mop'] = !empty($row['can_vacuum_mop']);
                $slim['can_pause'] = !empty($row['can_pause']);
                $slim['areas'] = self::normalizeVacuumAreas($row['areas'] ?? []);
                $slim['selected_areas'] = self::normalizeVacuumAreaIds($row['selected_areas'] ?? []);
            }
            $out[] = YarboMatterFabric::reclassifyRow($slim);
        }

        return $out;
    }

    /**
     * @return array{devices: list<array<string, mixed>>, fabric_mtime: int, disk_ids: list<string>}
     */
    private function readDeviceCacheBundle(string $path): array
    {
        $empty = ['devices' => [], 'fabric_mtime' => 0, 'disk_ids' => []];
        if (!is_file($path)) {
            return $empty;
        }
        $cached = json_decode((string) file_get_contents($path), true);
        if (!is_array($cached) || (int) ($cached['v'] ?? 0) < 5 || !is_array($cached['devices'] ?? null)) {
            return $empty;
        }
        $devices = [];
        foreach ($cached['devices'] as $row) {
            if (is_array($row) && trim((string) ($row['id'] ?? '')) !== '') {
                $devices[] = $row;
            }
        }
        $diskIds = [];
        foreach (is_array($cached['disk_ids'] ?? null) ? $cached['disk_ids'] : [] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $diskIds[] = $id;
            }
        }

        return [
            'devices' => $devices,
            'fabric_mtime' => (int) ($cached['fabric_mtime'] ?? 0),
            'disk_ids' => $diskIds,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readDeviceCache(string $path): array
    {
        return $this->readDeviceCacheBundle($path)['devices'];
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @param list<string>|null $diskIds
     */
    private function writeDeviceCache(string $path, array $devices, ?int $fabricMtime = null, ?array $diskIds = null): void
    {
        $prev = $this->readDeviceCacheBundle($path);
        $mtime = $fabricMtime ?? $prev['fabric_mtime'];
        $ids = $diskIds ?? $prev['disk_ids'];
        @file_put_contents($path, json_encode([
            'v' => 6,
            'saved_at' => time(),
            'fabric_mtime' => $mtime,
            'disk_ids' => array_values($ids),
            'devices' => self::dropLeftoverHueEndpoints($devices),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rebuild light rows from names, rooms, groups, and scene members when live/disk lists are empty.
     *
     * @param array<string, mixed> $store
     * @return list<array<string, mixed>>
     */
    private function devicesFromStoreHints(array $store): array
    {
        $ids = [];
        foreach (array_keys($store['names'] ?? []) as $id) {
            $ids[(string) $id] = true;
        }
        foreach (array_keys($store['rooms'] ?? []) as $id) {
            $ids[(string) $id] = true;
        }
        foreach (array_keys($store['groups'] ?? []) as $id) {
            $ids[(string) $id] = true;
        }
        foreach ($store['device_order'] ?? [] as $id) {
            $ids[(string) $id] = true;
        }
        foreach ($store['scenes'] ?? [] as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            foreach ($scene['actions'] ?? [] as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $id = trim((string) ($action['id'] ?? ''));
                if ($id !== '' && !str_starts_with($id, 'scene:')) {
                    $ids[$id] = true;
                }
            }
        }
        foreach ($store['paper'] ?? [] as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $id) {
                $id = trim((string) $id);
                if ($id !== '' && !str_starts_with($id, 'scene:')) {
                    $ids[$id] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys($ids) as $id) {
            if (!str_contains($id, ':')) {
                continue;
            }
            [$nodeS, $epS] = explode(':', $id, 2);
            $nodeId = (int) $nodeS;
            $endpoint = (int) $epS;
            if ($nodeId <= 0 || $endpoint < 0) {
                continue;
            }
            $row = YarboMatterFabric::reclassifyRow([
                'id' => $id,
                'node_id' => $nodeId,
                'endpoint' => $endpoint,
                'name' => (string) ($store['names'][$id] ?? $id),
                'kind' => self::KIND_LIGHT,
                'vendor' => '',
                'product' => '',
                'source' => 'saved',
                'bridge' => true,
                'on' => false,
                'brightness' => null,
                'dimmable' => false,
                'colorable' => false,
                'color_hs' => false,
                'color_xy' => false,
                'color_ct' => false,
                'color_hex' => '',
                'available' => false,
            ]);
            if (self::looksLikeLeftoverHueEndpoint($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Mill split nodes change id from node:1 (OnOff) to node:2 (thermostat).
     * Copy name/room/group/hidden from the old endpoint when this node has one heater.
     *
     * @param array<string, mixed> $store
     * @param list<array<string, mixed>> $devices
     * @return array<string, mixed>
     */
    public function adoptLoneHeaterIds(array $store, array $devices): array
    {
        $byNode = [];
        foreach ($devices as $device) {
            if (!is_array($device) || !self::liveRowLooksHeater($device)) {
                continue;
            }
            $id = trim((string) ($device['id'] ?? ''));
            $nid = self::rowNodeId($device, $id);
            if ($id === '' || $nid <= 0) {
                continue;
            }
            $byNode[$nid][] = $id;
        }
        $changed = false;
        foreach ($byNode as $nid => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) !== 1) {
                continue;
            }
            $newId = $ids[0];
            $prefix = $nid . ':';
            foreach (['names', 'rooms', 'groups', 'contact_kind'] as $key) {
                $map = is_array($store[$key] ?? null) ? $store[$key] : [];
                if (isset($map[$newId])) {
                    continue;
                }
                foreach ($map as $oldId => $val) {
                    if (is_string($oldId) && str_starts_with($oldId, $prefix) && $oldId !== $newId) {
                        $store[$key][$newId] = $val;
                        $changed = true;
                        break;
                    }
                }
            }
            $hidden = is_array($store['hidden'] ?? null) ? $store['hidden'] : [];
            if (!in_array($newId, $hidden, true)) {
                foreach ($hidden as $i => $oldId) {
                    if (is_string($oldId) && str_starts_with($oldId, $prefix) && $oldId !== $newId) {
                        $store['hidden'][$i] = $newId;
                        $changed = true;
                        break;
                    }
                }
            }
            $order = is_array($store['device_order'] ?? null) ? $store['device_order'] : [];
            if (!in_array($newId, $order, true)) {
                foreach ($order as $i => $oldId) {
                    if (is_string($oldId) && str_starts_with($oldId, $prefix) && $oldId !== $newId) {
                        $store['device_order'][$i] = $newId;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        if ($changed) {
            $store['hidden'] = $this->normalizeIdList($store['hidden'] ?? []);
            $this->write($store);
        }

        return $store;
    }

    private function rememberDevices(array $devices): void
    {
        $slim = $this->normalizeLastDevices($devices);
        if ($slim === []) {
            return;
        }
        $store = $this->load();
        $existing = $store['last_devices'] ?? [];
        if ($existing === $slim || self::lastDevicesTopologyKey($existing) === self::lastDevicesTopologyKey($slim)) {
            return;
        }
        $store['last_devices'] = $slim;
        $this->write($store);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function lastDevicesTopologyKey(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $parts[] = $id
                . ':' . (string) ($row['kind'] ?? '')
                . ':' . (int) ($row['node_id'] ?? 0)
                . ':' . (int) ($row['endpoint'] ?? 0);
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rememberedOrCached(string $cachePath): array
    {
        $cached = $this->readDeviceCache($cachePath);
        if ($cached !== []) {
            return $cached;
        }

        return $this->load()['last_devices'] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    private function markDevicesUnavailable(array $devices): array
    {
        $out = [];
        foreach ($devices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['available'] = false;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name: string, kind: string, on: bool}>
     */
    public function paperItems(?string $tabletId): array
    {
        if ($tabletId === null || $tabletId === '') {
            return [];
        }
        $store = $this->load();
        $live = $this->devicesWithLiveState();
        $live['devices'] = $this->mergeUnifiDevices($live['devices'], true);
        $byId = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '' || in_array($id, $store['hidden'], true)) {
                continue;
            }
            $byId[$id] = $this->paperItemFromDevice($device, $store);
        }
        foreach ($store['scenes'] as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $sid = 'scene:' . (string) ($scene['id'] ?? '');
            $byId[$sid] = [
                'id' => $sid,
                'name' => (string) ($scene['name'] ?? 'Scene'),
                'kind' => self::KIND_SCENE,
                'on' => $this->sceneIsActive($scene, $byId),
            ];
        }
        $assigned = $store['paper'][$tabletId] ?? [];
        $out = [];
        foreach ($assigned as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $item = $byId[$id];
            $out[] = [
                'id' => (string) $item['id'],
                'name' => YarboHub::normalizeDisplayName((string) $item['name'], self::PAPER_NAME_MAX) ?: (string) $item['id'],
                'kind' => (string) ($item['kind'] ?? self::KIND_LIGHT),
                'on' => YarboMatterFabric::attrBool($item['on'] ?? false),
            ];
            if (count($out) >= self::PAPER_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * Matter vacuums for the PaperMono ROBOTS page. Not tablet HOUSE assignment.
     *
     * @return list<array<string, mixed>>
     */
    public function paperRobotVacuums(): array
    {
        $store = $this->load();
        $hidden = array_fill_keys($store['hidden'], true);
        $live = $this->devicesWithLiveState();
        $out = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = trim((string) ($device['id'] ?? ''));
            if ($id === '' || isset($hidden[$id])) {
                continue;
            }
            $kind = strtolower(trim((string) ($device['kind'] ?? '')));
            $looksVac = $kind === self::KIND_VACUUM
                || YarboMatterFabric::nameLooksVacuum((string) ($device['name'] ?? '') . ' ' . (string) ($device['product'] ?? ''));
            if (!$looksVac) {
                continue;
            }
            $areas = array_slice(self::normalizeVacuumAreas($device['areas'] ?? []), 0, self::PAPER_ROBOT_AREA_MAX);
            $areaIds = [];
            foreach ($areas as $area) {
                $areaIds[] = (int) $area['id'];
            }
            $selected = [];
            foreach (self::normalizeVacuumAreaIds($device['selected_areas'] ?? []) as $areaId) {
                if (in_array($areaId, $areaIds, true)) {
                    $selected[] = $areaId;
                }
            }
            $status = trim((string) ($device['vacuum_status'] ?? ''));
            if ($status === '') {
                $status = !empty($device['on']) ? 'Cleaning' : 'Idle';
            }
            if ($status === 'Charging') {
                $status = !empty($device['on']) ? 'Cleaning' : 'Docked';
            }
            $battery = self::optionalPercent($device['vacuum_battery'] ?? null);
            $label = $status;
            if ($battery !== null && $battery > 0) {
                $label .= ' · ' . $battery . '%';
            }
            $clean = strtolower(trim((string) ($device['vacuum_clean'] ?? 'vacuum')));
            if (!in_array($clean, ['vacuum', 'mop', 'vacuum_mop'], true)) {
                $clean = 'vacuum';
            }
            $row = [
                'id' => $id,
                'kind' => 'vacuum',
                'name' => YarboHub::normalizeDisplayName(
                    (string) ($store['names'][$id] ?? $device['name'] ?? $id),
                    self::PAPER_NAME_MAX,
                ) ?: $id,
                'status' => $label,
                'vacuum_clean' => $clean,
                'can_mop' => !empty($device['can_mop']),
                'can_vacuum_mop' => !empty($device['can_vacuum_mop']),
                'can_pause' => array_key_exists('can_pause', $device) ? !empty($device['can_pause']) : true,
                'areas' => $areas,
                'selected_areas' => $selected,
            ];
            $roomId = trim((string) ($store['rooms'][$id] ?? ''));
            if ($roomId !== '') {
                foreach ($store['room_defs'] as $def) {
                    if ((string) ($def['id'] ?? '') === $roomId) {
                        $roomName = YarboHub::normalizeDisplayName((string) ($def['name'] ?? ''), 24);
                        if ($roomName !== '') {
                            $row['room'] = $roomName;
                        }
                        break;
                    }
                }
            }
            $out[] = $row;
            if (count($out) >= self::PAPER_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * Lockable Access doors plus open/close sensors for the PaperMono ACCESS page.
     *
     * @return list<array{id: string, name: string, kind: string, role: string, on: bool, unlock: bool}>
     */
    public function paperAccessItems(): array
    {
        $store = $this->load();
        $hidden = array_fill_keys($store['hidden'], true);
        $live = $this->devicesWithLiveState();
        // Home rows only: UniFi Hide unticks Show on Home, which allRows() ignores.
        $live['devices'] = $this->mergeUnifiDevices($live['devices']);
        $visible = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = trim((string) ($device['id'] ?? ''));
            if ($id === '' || isset($hidden[$id])) {
                continue;
            }
            $visible[] = $device;
        }
        $lockable = [];
        $sensors = [];
        $covered = [];
        foreach ($visible as $device) {
            $kind = strtolower(trim((string) ($device['kind'] ?? '')));
            if ($kind !== 'door') {
                continue;
            }
            $lockable[] = $device;
            self::coverAccessDoor($device, $covered);
        }
        foreach ($visible as $device) {
            $kind = strtolower(trim((string) ($device['kind'] ?? '')));
            if ($kind !== 'hub') {
                continue;
            }
            $doorId = trim((string) ($device['door_id'] ?? ''));
            if ($doorId === '' || isset($covered[$doorId])) {
                continue;
            }
            $lockable[] = $device;
            self::coverAccessDoor($device, $covered);
        }
        foreach ($visible as $device) {
            if (!self::isOpenCloseSensor($device)) {
                continue;
            }
            $doorId = trim((string) ($device['door_id'] ?? ''));
            if ($doorId !== '' && isset($covered[$doorId])) {
                continue;
            }
            $sensors[] = $device;
        }
        $out = [];
        foreach (array_merge($lockable, $sensors) as $device) {
            $id = trim((string) ($device['id'] ?? ''));
            $kind = strtolower(trim((string) ($device['kind'] ?? 'sensor')));
            if ($kind === '') {
                $kind = 'sensor';
            }
            $out[] = [
                'id' => $id,
                'name' => YarboHub::normalizeDisplayName(
                    (string) ($store['names'][$id] ?? $device['name'] ?? $id),
                    self::PAPER_NAME_MAX,
                ) ?: $id,
                'kind' => $kind,
                'role' => $this->contactRoleFor($device, $store),
                'on' => self::contactIsOpen($device),
                'unlock' => self::isLockableDoor($device),
            ];
            if (count($out) >= self::PAPER_ACCESS_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, true> $covered
     */
    private static function coverAccessDoor(array $device, array &$covered): void
    {
        $native = trim((string) ($device['native_id'] ?? ''));
        if ($native !== '') {
            $covered[$native] = true;
        }
        $doorId = trim((string) ($device['door_id'] ?? ''));
        if ($doorId !== '') {
            $covered[$doorId] = true;
        }
    }

    public static function isLockableDoor(array $row): bool
    {
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));

        return $kind === 'door' || $kind === 'hub';
    }

    /**
     * Open/close contacts that are not lockable Access doors.
     *
     * @param array<string, mixed> $row
     */
    public static function isOpenCloseSensor(array $row): bool
    {
        if (self::isLockableDoor($row)) {
            return false;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));
        $native = (string) ($row['native_id'] ?? $row['id'] ?? '');
        $product = (string) ($row['product'] ?? '');
        $name = (string) ($row['name'] ?? '');
        if (str_starts_with($native, 'pir-') || str_contains($native, ':pir-')) {
            return false;
        }
        if (str_starts_with($native, 'rin-') || str_contains($native, ':rin-')) {
            return false;
        }
        if ($product === 'Door position sensor' || str_starts_with($native, 'dps-') || str_contains($native, ':dps-')) {
            return true;
        }
        if (!empty($row['has_open']) || !empty($row['has_dps'])) {
            return $kind === 'sensor' || $kind === '';
        }
        if ($kind !== 'sensor') {
            return false;
        }
        $blob = $name . ' ' . $product;
        if (preg_match('/motion|occupancy|presence|pir/i', $blob) === 1
            && preg_match('/contact|door|window|magnet/i', $blob) !== 1) {
            return false;
        }

        return preg_match('/contact|door|window|magnet/i', $blob) === 1;
    }

    public static function looksLikeWindowName(string $blob): bool
    {
        return preg_match('/window/i', $blob) === 1;
    }

    public static function normalizeContactRole(mixed $value): string
    {
        return strtolower(trim((string) $value)) === self::CONTACT_WINDOW
            ? self::CONTACT_WINDOW
            : self::CONTACT_DOOR;
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed>|null $store
     */
    public function contactRoleFor(array $device, ?array $store = null): string
    {
        if (self::isLockableDoor($device)) {
            return self::CONTACT_DOOR;
        }
        $id = trim((string) ($device['id'] ?? ''));
        $store ??= $this->load();
        $map = is_array($store['contact_kind'] ?? null) ? $store['contact_kind'] : [];
        $assigned = strtolower(trim((string) ($map[$id] ?? '')));
        if ($assigned === self::CONTACT_WINDOW || $assigned === self::CONTACT_DOOR) {
            return $assigned;
        }
        $blob = (string) ($store['names'][$id] ?? $device['name'] ?? '') . ' ' . (string) ($device['product'] ?? '');
        if (self::looksLikeWindowName($blob)) {
            return self::CONTACT_WINDOW;
        }

        return self::CONTACT_DOOR;
    }

    /**
     * @param array<string, mixed> $device
     */
    public static function contactIsOpen(array $device): bool
    {
        if (($device['open'] ?? null) === true) {
            return true;
        }
        $dps = strtolower(trim((string) ($device['dps'] ?? '')));
        if ($dps === 'open' || $dps === 'opened') {
            return true;
        }
        $label = (string) ($device['dps_label'] ?? $device['status'] ?? '');

        return preg_match('/^Open\b/i', $label) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function assignContactKind(string $deviceId, string $role): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return ['ok' => false, 'error' => 'Pick a device'];
        }
        $store = $this->load();
        if (!isset($store['contact_kind']) || !is_array($store['contact_kind'])) {
            $store['contact_kind'] = [];
        }
        $store['contact_kind'][$deviceId] = self::normalizeContactRole($role);
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function paperVacuumCommand(string $id, string $command, mixed $areas = null, ?string $mode = null): array
    {
        $input = [
            'id' => $id,
            'command' => $command,
            'kind' => self::KIND_VACUUM,
        ];
        if ($areas !== null) {
            $input['areas'] = $areas;
        }
        if ($mode !== null && $mode !== '') {
            $input['clean_mode'] = $mode;
        }

        return $this->command($input);
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $store
     * @return array{id: string, name: string, kind: string, on: bool}
     */
    private function paperItemFromDevice(array $device, array $store): array
    {
        $id = (string) ($device['id'] ?? '');
        $kind = strtolower(trim((string) ($device['kind'] ?? self::KIND_LIGHT)));
        if ($kind === '') {
            $kind = self::KIND_LIGHT;
        }
        $on = YarboMatterFabric::attrBool($device['on'] ?? false);
        if ($kind === 'door' || $kind === 'hub') {
            $on = ($device['open'] ?? null) === true;
        }

        return [
            'id' => $id,
            'name' => $store['names'][$id] ?? (string) ($device['name'] ?? $id),
            'kind' => $kind,
            'on' => $on,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paperCommand(string $id): array
    {
        $id = trim($id);
        if (str_starts_with($id, 'scene:')) {
            return $this->toggleScene(substr($id, 6));
        }
        $parsed = YarboUnifi::parseHomeId($id);
        if ($parsed !== null
            && ($parsed['kind'] === YarboUnifi::KIND_DOOR || $parsed['kind'] === YarboUnifi::KIND_HUB)) {
            return $this->command(['id' => $id, 'command' => 'unlock']);
        }

        return $this->command(['id' => $id, 'command' => 'toggle']);
    }

    /**
     * @param array<string, mixed> $scene
     * @return array{id: string, name: string, actions: list<array{id: string, on: bool, brightness: ?int, color_hex: ?string, color_temp: ?int, heating_setpoint: ?float, adaptive: bool}>}|null
     */
    private function normalizeScene(array $scene): ?array
    {
        $id = trim((string) ($scene['id'] ?? ''));
        $name = YarboHub::normalizeDisplayName((string) ($scene['name'] ?? ''), 32);
        $actions = [];
        foreach (is_array($scene['actions'] ?? null) ? $scene['actions'] : [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $deviceId = trim((string) ($action['id'] ?? ''));
            if ($deviceId === '') {
                continue;
            }
            $brightness = array_key_exists('brightness', $action) && $action['brightness'] !== null && $action['brightness'] !== ''
                ? max(0, min(100, (int) $action['brightness']))
                : null;
            $on = array_key_exists('on', $action)
                ? !empty($action['on'])
                : ($brightness !== null && $brightness > 0);
            $hex = $this->normalizeHex((string) ($action['color_hex'] ?? $action['hex'] ?? ''));
            $kelvin = array_key_exists('color_temp', $action) && $action['color_temp'] !== null && $action['color_temp'] !== ''
                ? max(1500, min(8000, (int) $action['color_temp']))
                : null;
            $setpoint = self::optionalCelsius($action['heating_setpoint'] ?? $action['celsius'] ?? null);
            if ($setpoint !== null) {
                $setpoint = max(5.0, min(35.0, $setpoint));
            }
            $adaptive = !empty($action['adaptive']) && $on && !YarboUnifi::isHomeId($deviceId);
            if (YarboUnifi::isHomeId($deviceId)) {
                $brightness = null;
                $hex = null;
                $kelvin = null;
                $setpoint = null;
                $adaptive = false;
            }
            if ($adaptive) {
                $hex = null;
                $kelvin = null;
            }
            if (!$on) {
                $brightness = null;
                $hex = null;
                $kelvin = null;
                $setpoint = null;
                $adaptive = false;
            }
            $actions[] = [
                'id' => $deviceId,
                'on' => $on,
                'brightness' => $brightness,
                'color_hex' => $hex,
                'color_temp' => $kelvin,
                'heating_setpoint' => $setpoint,
                'adaptive' => $adaptive,
            ];
        }
        if ($id === '' || $name === '' || $actions === []) {
            return null;
        }

        return ['id' => $id, 'name' => $name, 'actions' => $actions];
    }

    /**
     * @param list<mixed> $scenes
     * @param list<array<string, mixed>> $devices
     * @return list<array<string, mixed>>
     */
    private function scenesPayload(array $scenes, array $devices): array
    {
        $byId = [];
        foreach ($devices as $device) {
            $id = (string) ($device['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $device;
            }
        }
        $out = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $normalized = $this->normalizeScene($scene);
            if ($normalized === null) {
                continue;
            }
            $out[] = [
                'id' => $normalized['id'],
                'name' => $normalized['name'],
                'actions' => $normalized['actions'],
                'count' => count($normalized['actions']),
                'on' => $this->sceneIsActive($normalized, $byId),
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, name: string, actions: list<array<string, mixed>>}|null
     */
    private function findScene(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach ($this->load()['scenes'] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? '') === $id) {
                return $this->normalizeScene($candidate) ?? $candidate;
            }
        }

        return null;
    }

    /**
     * @param array{actions?: list<array<string, mixed>>} $scene
     * @param array<string, array<string, mixed>> $liveById
     */
    private function sceneIsActive(array $scene, array $liveById): bool
    {
        $actions = is_array($scene['actions'] ?? null) ? $scene['actions'] : [];
        if ($actions === []) {
            return false;
        }
        $anyOn = false;
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $id = (string) ($action['id'] ?? '');
            if ($id === '' || !isset($liveById[$id])) {
                continue;
            }
            $live = $liveById[$id];
            $wantOn = !empty($action['on']);
            $isOn = !empty($live['on']);
            if ($wantOn) {
                $anyOn = true;
                if (!$isOn) {
                    return false;
                }
                if (isset($action['brightness'], $live['brightness'])
                    && $action['brightness'] !== null
                    && $live['brightness'] !== null
                    && abs((int) $live['brightness'] - (int) $action['brightness']) > 15) {
                    return false;
                }
            } elseif ($isOn) {
                return false;
            }
        }

        return $anyOn;
    }

    /**
     * @param mixed $raw
     * @return list<array{id: int, name: string}>
     */
    public static function normalizeVacuumAreas(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? $item['areaID'] ?? $item['area_id'] ?? null;
            if (!is_numeric($id)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                $name = 'Room ' . (int) $id;
            }
            $out[] = ['id' => (int) $id, 'name' => substr($name, 0, 40)];
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    public static function normalizeVacuumAreaIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? $item['areaID'] ?? null;
            }
            if (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }

    private static function optionalCelsius(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    private static function optionalPercent(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    /**
     * Snapshot on/brightness/colour so a pulse can restore a scene look.
     *
     * @param array<string, mixed> $row
     * @return array{on: bool, brightness?: int, hex?: string, kelvin?: int}
     */
    public static function pulseLookFromDevice(array $row): array
    {
        $on = !empty($row['on']);
        $look = ['on' => $on];
        if (!$on) {
            return $look;
        }
        if (isset($row['brightness']) && is_numeric($row['brightness'])) {
            $bright = max(0, min(100, (int) $row['brightness']));
            if ($bright > 0) {
                $look['brightness'] = $bright;
            }
        }
        $hex = self::normalizeHexValue((string) ($row['color_hex'] ?? $row['hex'] ?? ''));
        if ($hex !== null) {
            $look['hex'] = $hex;
        }
        $kelvin = $row['kelvin'] ?? $row['color_temp'] ?? null;
        if ($kelvin !== null && $kelvin !== '' && is_numeric($kelvin)) {
            $look['kelvin'] = max(1500, min(8000, (int) $kelvin));
        }

        return $look;
    }

    public static function normalizePulseCount(mixed $raw): int
    {
        $n = (int) $raw;
        if ($n < self::PULSE_COUNT_MIN) {
            $n = self::PULSE_COUNT_DEFAULT;
        }
        if ($n > self::PULSE_COUNT_MAX) {
            $n = self::PULSE_COUNT_MAX;
        }

        return $n;
    }

    /**
     * On-colour / off steps, then restore. Used by automations Pulse.
     *
     * @param array{on: bool, brightness?: int, hex?: string, kelvin?: int} $restore
     * @return list<array<string, mixed>>
     */
    public static function pulsePlan(
        string $id,
        ?string $hex,
        int $count,
        array $restore,
        bool $dimmable = false
    ): array {
        $id = trim($id);
        $count = self::normalizePulseCount($count);
        $hex = $hex !== null ? self::normalizeHexValue($hex) : null;
        $steps = [];
        for ($i = 0; $i < $count; $i++) {
            if ($dimmable) {
                $steps[] = ['id' => $id, 'command' => 'brightness', 'brightness' => 100];
            }
            if ($hex !== null) {
                $steps[] = ['id' => $id, 'command' => 'color', 'hex' => $hex];
            } elseif (!$dimmable) {
                $steps[] = ['id' => $id, 'command' => 'on'];
            }
            $steps[] = ['id' => $id, 'command' => 'off'];
        }
        if (empty($restore['on'])) {
            return $steps;
        }
        $sent = false;
        if (isset($restore['brightness']) && is_numeric($restore['brightness'])) {
            $steps[] = [
                'id' => $id,
                'command' => 'brightness',
                'brightness' => max(1, min(100, (int) $restore['brightness'])),
            ];
            $sent = true;
        }
        $restoreHex = isset($restore['hex']) ? self::normalizeHexValue((string) $restore['hex']) : null;
        if ($restoreHex !== null) {
            $steps[] = ['id' => $id, 'command' => 'color', 'hex' => $restoreHex];
            $sent = true;
        } elseif (isset($restore['kelvin']) && is_numeric($restore['kelvin'])) {
            $steps[] = [
                'id' => $id,
                'command' => 'color_temp',
                'kelvin' => max(1500, min(8000, (int) $restore['kelvin'])),
            ];
            $sent = true;
        }
        if (!$sent) {
            $steps[] = ['id' => $id, 'command' => 'on'];
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function executePulse(array $job, int $dwellUs = self::PULSE_DWELL_US): array
    {
        $id = trim((string) ($job['id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'Pick a light'];
        }
        $hex = self::normalizeHexValue((string) ($job['hex'] ?? $job['color_hex'] ?? self::PULSE_HEX_DEFAULT));
        $count = self::normalizePulseCount($job['pulse_count'] ?? $job['count'] ?? self::PULSE_COUNT_DEFAULT);
        $restore = is_array($job['restore'] ?? null)
            ? $job['restore']
            : $this->capturePulseLook($id);
        $caps = $this->deviceControlCaps($id);
        $steps = self::pulsePlan($id, $hex, $count, $restore, !empty($caps['dimmable']));
        $errors = [];
        $last = ['ok' => true];
        foreach ($steps as $i => $step) {
            $last = $this->command($step);
            if (!($last['ok'] ?? false)) {
                $errors[] = (string) ($last['error'] ?? 'failed');
            }
            $cmd = (string) ($step['command'] ?? '');
            if ($dwellUs > 0 && ($cmd === 'color' || $cmd === 'on' || $cmd === 'brightness' || $cmd === 'off')) {
                $next = $steps[$i + 1] ?? null;
                $nextCmd = is_array($next) ? (string) ($next['command'] ?? '') : '';
                if ($nextCmd === 'off' || $cmd === 'off') {
                    usleep($dwellUs);
                }
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => $errors[0]];
        }

        return $last;
    }

    /**
     * @return array{on: bool, brightness?: int, hex?: string, kelvin?: int}
     */
    public function capturePulseLook(string $id): array
    {
        $id = trim($id);
        foreach ($this->automationDevices() as $row) {
            if (trim((string) ($row['id'] ?? '')) === $id) {
                return self::pulseLookFromDevice($row);
            }
        }

        return ['on' => false];
    }

    private function normalizeHex(string $value): ?string
    {
        return self::normalizeHexValue($value);
    }

    public static function normalizeHexValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $short)) {
            $hex = $short[1];

            return '#' . strtolower($hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]);
        }
        if (!preg_match('/^#([0-9a-fA-F]{6})$/', $value, $full)) {
            return null;
        }

        return '#' . strtolower($full[1]);
    }

    /**
     * @param list<mixed> $ids
     * @return list<string>
     */
    private function normalizeIdList(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function applyIdOrder(array $items, array $ids): array
    {
        $byId = [];
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $item;
            }
        }
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && !isset($seen[$id])) {
                $out[] = $byId[$id];
                $seen[$id] = true;
            }
        }
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '' && !isset($seen[$id])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function reorderById(array $items, array $ids): array
    {
        return $this->applyIdOrder($items, $ids);
    }

    /**
     * @param list<array{id?: string, name?: string, room_id?: string}> $groupDefs
     * @param list<string> $ids
     * @return list<array{id?: string, name?: string, room_id?: string}>
     */
    private function reorderGroupsInRoom(array $groupDefs, array $ids, string $roomId): array
    {
        if ($roomId === '') {
            return $this->reorderById($groupDefs, $ids);
        }
        $byId = [];
        foreach ($groupDefs as $def) {
            $id = (string) ($def['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $def;
            }
        }
        $wanted = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && (string) ($byId[$id]['room_id'] ?? '') === $roomId && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }
        $out = [];
        $queue = $wanted;
        $placed = [];
        foreach ($groupDefs as $def) {
            if ((string) ($def['room_id'] ?? '') !== $roomId) {
                $out[] = $def;
                continue;
            }
            while ($queue !== [] && isset($placed[$queue[0]])) {
                array_shift($queue);
            }
            if ($queue === []) {
                continue;
            }
            $next = array_shift($queue);
            $out[] = $byId[$next];
            $placed[$next] = true;
        }
        foreach ($queue as $id) {
            if (!isset($placed[$id]) && isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param array{room_defs?: list<array{id: string, name: string}>, group_defs?: list<array{id: string, name: string, room_id: string}>} $store
     * @param list<array<string, mixed>> $visibleDevices
     * @return list<array{id: string, name: string, on: bool, brightness: ?int, dimmable: bool, switchable: bool, count: int, groups: list<array<string, mixed>>}>
     */
    private function roomsPayload(array $store, array $visibleDevices): array
    {
        $rooms = [];
        foreach ($store['room_defs'] ?? [] as $def) {
            $id = (string) ($def['id'] ?? '');
            $name = (string) ($def['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $inRoom = array_values(array_filter(
                $visibleDevices,
                static fn (array $device): bool => (string) ($device['room_id'] ?? '') === $id
            ));
            $stats = $this->aggregateDeviceStats($inRoom);
            $rooms[] = [
                'id' => $id,
                'name' => $name,
                'on' => $stats['on'],
                'brightness' => $stats['brightness'],
                'dimmable' => $stats['dimmable'],
                'switchable' => $stats['switchable'],
                'count' => $stats['count'],
                'groups' => $this->groupsPayload($store, $id, $inRoom),
            ];
        }

        return $rooms;
    }

    /**
     * @param array{group_defs?: list<array{id: string, name: string, room_id: string}>} $store
     * @param list<array<string, mixed>> $inRoom
     * @return list<array{id: string, name: string, room_id: string, on: bool, brightness: ?int, dimmable: bool, switchable: bool, count: int}>
     */
    private function groupsPayload(array $store, string $roomId, array $inRoom): array
    {
        $out = [];
        foreach ($store['group_defs'] ?? [] as $def) {
            $id = (string) ($def['id'] ?? '');
            $name = (string) ($def['name'] ?? '');
            if ($id === '' || $name === '' || (string) ($def['room_id'] ?? '') !== $roomId) {
                continue;
            }
            $members = array_values(array_filter(
                $inRoom,
                static fn (array $device): bool => (string) ($device['group_id'] ?? '') === $id
            ));
            $stats = $this->aggregateDeviceStats($members);
            $out[] = [
                'id' => $id,
                'name' => $name,
                'room_id' => $roomId,
                'on' => $stats['on'],
                'brightness' => $stats['brightness'],
                'dimmable' => $stats['dimmable'],
                'switchable' => $stats['switchable'],
                'count' => $stats['count'],
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return array{on: bool, dimmable: bool, switchable: bool, brightness: ?int, count: int}
     */
    private function aggregateDeviceStats(array $devices): array
    {
        $on = false;
        $dimmable = false;
        $switchable = false;
        $brightSum = 0;
        $brightN = 0;
        foreach ($devices as $device) {
            if (!self::isRoomLight($device)) {
                continue;
            }
            $switchable = true;
            if (!empty($device['on'])) {
                $on = true;
            }
            if (!empty($device['dimmable']) || (isset($device['brightness']) && is_numeric($device['brightness']))) {
                $dimmable = true;
                $value = isset($device['brightness']) ? (int) $device['brightness'] : (!empty($device['on']) ? 100 : 0);
                $brightSum += $value;
                $brightN++;
            }
        }

        return [
            'on' => $on,
            'dimmable' => $dimmable,
            'switchable' => $switchable,
            'brightness' => $brightN > 0 ? (int) round($brightSum / $brightN) : null,
            'count' => count($devices),
        ];
    }

    /**
     * @param array{groups?: array<string, string>, group_defs?: list<array{id: string, room_id: string}>} $store
     */
    private function deviceGroupId(array $store, string $deviceId, string $roomId): string
    {
        $groupId = (string) ($store['groups'][$deviceId] ?? '');
        if ($groupId === '' || $roomId === '') {
            return '';
        }

        return $this->groupRoomId($store, $groupId) === $roomId ? $groupId : '';
    }

    /**
     * @param array{group_defs?: list<array{id: string, room_id: string}>} $store
     */
    private function groupRoomId(array $store, string $groupId): string
    {
        foreach ($store['group_defs'] ?? [] as $def) {
            if ((string) ($def['id'] ?? '') === $groupId) {
                return (string) ($def['room_id'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function lightIdsAmong(array $ids): array
    {
        $kinds = $this->deviceKindMap();
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            if (self::isRoomLight(['id' => $id, 'kind' => $kinds[$id] ?? ''])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function dimmableIdsAmong(array $ids): array
    {
        $dimmable = [];
        $live = $this->localHomeDevices();
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '' && in_array($id, $ids, true)
                && (!empty($device['dimmable']) || (isset($device['brightness']) && is_numeric($device['brightness'])))) {
                $dimmable[] = $id;
            }
        }

        return $dimmable;
    }

    /**
     * Colour lights that can follow Time of day (CT or RGB white).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function adaptiveIdsAmong(array $ids): array
    {
        $adaptive = [];
        $live = $this->localHomeDevices();
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '' || !in_array($id, $ids, true)) {
                continue;
            }
            if (YarboUnifi::isHomeId($id) || strtolower((string) ($device['source'] ?? '')) === YarboUnifi::SOURCE) {
                continue;
            }
            $kind = strtolower(trim((string) ($device['kind'] ?? self::KIND_LIGHT)));
            if ($kind !== '' && $kind !== self::KIND_LIGHT) {
                continue;
            }
            if (!empty($device['color_ct']) || !empty($device['colorable'])
                || !empty($device['color_hs']) || !empty($device['color_xy'])) {
                $adaptive[] = $id;
            }
        }

        return $adaptive;
    }

    /**
     * @return list<string>
     */
    private function visibleDeviceIdsInGroup(string $groupId): array
    {
        $store = $this->load();
        $hidden = array_fill_keys($store['hidden'], true);
        $ids = [];
        foreach ($store['groups'] as $deviceId => $assigned) {
            if ($assigned !== $groupId || isset($hidden[$deviceId])) {
                continue;
            }
            $ids[] = (string) $deviceId;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function visibleDeviceIdsInRoom(string $roomId): array
    {
        $store = $this->load();
        $hidden = array_fill_keys($store['hidden'], true);
        $ids = [];
        foreach ($store['rooms'] as $deviceId => $assigned) {
            if ($assigned !== $roomId || isset($hidden[$deviceId])) {
                continue;
            }
            $ids[] = (string) $deviceId;
        }

        return $ids;
    }

    /**
     * Approximate RGB white for colour lights that have no Colour Temperature cluster.
     * Tanner Helland's Kelvin-to-RGB curve, clamped to sRGB.
     */
    public static function kelvinToHex(int $kelvin): string
    {
        $k = max(1000, min(40000, $kelvin)) / 100.0;
        if ($k <= 66) {
            $r = 255.0;
            $g = 99.4708025861 * log($k) - 161.1195681661;
        } else {
            $r = 329.698727446 * (($k - 60.0) ** -0.1332047592);
            $g = 288.1221695283 * (($k - 60.0) ** -0.0755148492);
        }
        if ($k >= 66) {
            $b = 255.0;
        } elseif ($k <= 19) {
            $b = 0.0;
        } else {
            $b = 138.5177312231 * log($k - 10.0) - 305.0447927307;
        }
        $clamp = static fn (float $n): int => max(0, min(255, (int) round($n)));

        return sprintf('#%02x%02x%02x', $clamp($r), $clamp($g), $clamp($b));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function commandAdaptiveFollow(string $id, array $input): array
    {
        $enable = array_key_exists('adaptive', $input) ? !empty($input['adaptive']) : true;
        if (!$enable) {
            $this->clearAdaptiveDevice($id);

            return ['ok' => true, 'adaptive' => false];
        }
        $this->addAdaptiveExtra($id);
        $cached = $this->cachedDeviceRow($id);
        if (is_array($cached) && array_key_exists('on', $cached) && empty($cached['on'])) {
            return ['ok' => true, 'adaptive' => true];
        }
        $kelvin = $this->adaptiveKelvinForDevice($id);
        $result = $this->commandAdaptiveColor($id, $kelvin, true, $this->deviceControlCaps($id));
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        return ['ok' => true, 'adaptive' => true] + $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function followAdaptiveAfterOn(string $id): array
    {
        if (!in_array($id, $this->adaptiveDeviceIds(), true)) {
            return [];
        }
        $kelvin = $this->adaptiveKelvinForDevice($id);
        $result = $this->commandAdaptiveColor($id, $kelvin, true, $this->deviceControlCaps($id));
        if (!($result['ok'] ?? false)) {
            return [];
        }
        $this->rememberAdaptiveApplied([$id => $kelvin]);

        return $result;
    }

    /**
     * @param array{kind?: string, dimmable?: bool, colorable?: bool, color_ct?: bool} $caps
     * @return array<string, mixed>
     */
    public function commandAdaptiveColor(string $id, int $kelvin, bool $adaptive = true, ?array $caps = null): array
    {
        $caps ??= $this->deviceControlCaps($id);
        $kelvin = max(1500, min(8000, $kelvin));
        if (!empty($caps['color_ct'])) {
            $payload = [
                'id' => $id,
                'command' => 'color_temp',
                'kelvin' => $kelvin,
            ];
            if ($adaptive) {
                $payload['adaptive'] = true;
            }

            return $this->command($payload);
        }
        if (!empty($caps['colorable'])) {
            $payload = [
                'id' => $id,
                'command' => 'color',
                'hex' => self::kelvinToHex($kelvin),
            ];
            if ($adaptive) {
                $payload['adaptive'] = true;
            }

            return $this->command($payload);
        }

        return ['ok' => false, 'error' => 'This device does not support that control'];
    }

    /**
     * Colour temperature (K) for Time of day at $now.
     * Uses sunrise/sunset when lat/lon are set, otherwise a clock curve.
     */
    public static function adaptiveKelvinAt(int $now, ?float $lat, ?float $lon, string $timezone = 'UTC'): int
    {
        $raw = self::adaptiveKelvinRaw($now, $lat, $lon, $timezone);

        return (int) (round($raw / self::ADAPTIVE_STEP_K) * self::ADAPTIVE_STEP_K);
    }

    /**
     * Devices that should follow Time of day right now, and the kelvin to write.
     *
     * @return list<array{id: string, kelvin: int}>
     */
    public function adaptiveUpdates(int $now, bool $force = false): array
    {
        $ids = $this->adaptiveDeviceIds();
        if ($ids === []) {
            return [];
        }
        $store = $this->load();
        $last = is_array($store['adaptive']['last'] ?? null) ? $store['adaptive']['last'] : [];
        $byId = [];
        foreach ($store['last_devices'] as $row) {
            if (is_array($row) && ($row['id'] ?? '') !== '') {
                $byId[(string) $row['id']] = $row;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? [];
            if ($row !== [] && array_key_exists('on', $row) && empty($row['on'])) {
                continue;
            }
            $kelvin = $this->adaptiveKelvinForDevice($id, $now);
            $prev = is_array($last[$id] ?? null) ? $last[$id] : [];
            $prevK = isset($prev['kelvin']) ? (int) $prev['kelvin'] : null;
            $prevAt = isset($prev['at']) ? (int) $prev['at'] : 0;
            if (!$force && $prevK !== null && $prevK === $kelvin) {
                continue;
            }
            if (!$force && $prevAt > 0 && ($now - $prevAt) < self::ADAPTIVE_MIN_INTERVAL_SEC) {
                continue;
            }
            $out[] = ['id' => $id, 'kelvin' => $kelvin];
        }

        return $out;
    }

    public function addAdaptiveExtra(string $id): void
    {
        $id = trim($id);
        if ($id === '' || YarboUnifi::isHomeId($id)) {
            return;
        }
        $store = $this->load();
        $extra = $store['adaptive']['extra'] ?? [];
        if (!is_array($extra)) {
            $extra = [];
        }
        if (!in_array($id, $extra, true)) {
            $extra[] = $id;
        }
        $store['adaptive']['extra'] = array_values($extra);
        unset($store['adaptive']['paused'][$id]);
        $this->write($store);
    }

    /**
     * @param array<string, int> $applied id => kelvin
     */
    public function rememberAdaptiveApplied(array $applied, ?int $now = null): void
    {
        if ($applied === []) {
            return;
        }
        $now ??= time();
        $store = $this->load();
        $last = is_array($store['adaptive']['last'] ?? null) ? $store['adaptive']['last'] : [];
        foreach ($applied as $id => $kelvin) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $last[$id] = ['kelvin' => (int) $kelvin, 'at' => $now];
        }
        $store['adaptive']['last'] = $last;
        $this->write($store);
    }

    /**
     * @return list<string>
     */
    public function adaptiveDeviceIds(): array
    {
        $store = $this->load();
        $paused = is_array($store['adaptive']['paused'] ?? null) ? $store['adaptive']['paused'] : [];
        $ids = [];
        foreach (is_array($store['adaptive']['extra'] ?? null) ? $store['adaptive']['extra'] : [] as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !isset($paused[$id])) {
                $ids[$id] = true;
            }
        }
        $sceneId = (string) ($store['active_scene_id'] ?? '');
        if ($sceneId !== '') {
            foreach ($store['scenes'] as $scene) {
                if (!is_array($scene) || (string) ($scene['id'] ?? '') !== $sceneId) {
                    continue;
                }
                foreach (is_array($scene['actions'] ?? null) ? $scene['actions'] : [] as $action) {
                    if (!is_array($action) || empty($action['adaptive']) || empty($action['on'])) {
                        continue;
                    }
                    $id = trim((string) ($action['id'] ?? ''));
                    if ($id !== '' && !isset($paused[$id])) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    public function adaptiveKelvinForDevice(string $id, ?int $now = null): int
    {
        $now ??= time();
        $auto = new YarboHomeAutomations($this->projectRoot);
        $coords = $auto->resolveCoords();
        $kelvin = self::adaptiveKelvinAt(
            $now,
            $coords['latitude'],
            $coords['longitude'],
            $auto->timezoneName()
        );
        $row = [];
        foreach ($this->load()['last_devices'] as $one) {
            if (is_array($one) && trim((string) ($one['id'] ?? '')) === $id) {
                $row = $one;
                break;
            }
        }
        $min = isset($row['color_temp_min']) && is_numeric($row['color_temp_min'])
            ? (int) $row['color_temp_min']
            : 2000;
        $max = isset($row['color_temp_max']) && is_numeric($row['color_temp_max'])
            ? (int) $row['color_temp_max']
            : 6500;
        if ($min > $max) {
            return $kelvin;
        }

        return max($min, min($max, $kelvin));
    }

    private function pauseAdaptiveDevice(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $store = $this->load();
        if (!isset($store['adaptive']['paused']) || !is_array($store['adaptive']['paused'])) {
            $store['adaptive']['paused'] = [];
        }
        $store['adaptive']['paused'][$id] = time();
        $this->write($store);
    }

    private function unpauseAdaptiveDevice(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $store = $this->load();
        if (isset($store['adaptive']['paused']) && is_array($store['adaptive']['paused'])) {
            unset($store['adaptive']['paused'][$id]);
            $this->write($store);
        }
    }

    private function clearAdaptiveDevice(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $store = $this->load();
        $extra = [];
        foreach (is_array($store['adaptive']['extra'] ?? null) ? $store['adaptive']['extra'] : [] as $one) {
            $one = trim((string) $one);
            if ($one !== '' && $one !== $id) {
                $extra[] = $one;
            }
        }
        $store['adaptive']['extra'] = $extra;
        unset($store['adaptive']['paused'][$id], $store['adaptive']['last'][$id]);
        $this->write($store);
    }

    /**
     * @param mixed $raw
     * @return array{extra: list<string>, paused: array<string, int>, last: array<string, array{kelvin: int, at: int}>}
     */
    private function normalizeAdaptiveState(mixed $raw): array
    {
        $out = ['extra' => [], 'paused' => [], 'last' => []];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (is_array($raw['extra'] ?? null) ? $raw['extra'] : [] as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !YarboUnifi::isHomeId($id)) {
                $out['extra'][] = $id;
            }
        }
        $out['extra'] = array_values(array_unique($out['extra']));
        foreach (is_array($raw['paused'] ?? null) ? $raw['paused'] : [] as $id => $at) {
            $id = trim((string) $id);
            if ($id !== '') {
                $out['paused'][$id] = (int) $at;
            }
        }
        foreach (is_array($raw['last'] ?? null) ? $raw['last'] : [] as $id => $row) {
            $id = trim((string) $id);
            if ($id === '' || !is_array($row)) {
                continue;
            }
            $kelvin = isset($row['kelvin']) ? (int) $row['kelvin'] : 0;
            $at = isset($row['at']) ? (int) $row['at'] : 0;
            if ($kelvin > 0) {
                $out['last'][$id] = ['kelvin' => $kelvin, 'at' => $at];
            }
        }

        return $out;
    }

    /**
     * @return array{dawn: int, sunrise: int, noon: int, sunset: int, dusk: int}|null
     */
    private static function adaptiveSunAnchors(int $now, float $lat, float $lon): ?array
    {
        $info = @date_sun_info($now, $lat, $lon);
        if (!is_array($info)) {
            return null;
        }
        $sunrise = $info['sunrise'] ?? null;
        $sunset = $info['sunset'] ?? null;
        $noon = $info['transit'] ?? null;
        if (!is_int($sunrise) || !is_int($sunset) || !is_int($noon) || $sunrise <= 0 || $sunset <= 0 || $noon <= 0) {
            return null;
        }
        $dawn = $info['civil_twilight_begin'] ?? null;
        $dusk = $info['civil_twilight_end'] ?? null;
        if (!is_int($dawn) || $dawn <= 0) {
            $dawn = $sunrise - 3600;
        }
        if (!is_int($dusk) || $dusk <= 0) {
            $dusk = $sunset + 3600;
        }
        if (!($dawn < $sunrise && $sunrise < $noon && $noon < $sunset && $sunset < $dusk)) {
            return null;
        }

        return [
            'dawn' => $dawn,
            'sunrise' => $sunrise,
            'noon' => $noon,
            'sunset' => $sunset,
            'dusk' => $dusk,
        ];
    }

    private static function adaptiveKelvinRaw(int $now, ?float $lat, ?float $lon, string $timezone): float
    {
        if ($lat !== null && $lon !== null && $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0) {
            $sun = self::adaptiveSunAnchors($now, $lat, $lon);
            if ($sun !== null) {
                return self::adaptiveKelvinFromAnchors($now, $sun);
            }
        }
        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $sec = ((int) $local->format('G')) * 3600 + ((int) $local->format('i')) * 60 + (int) $local->format('s');

        return self::adaptiveKelvinFromAnchors($sec, [
            'dawn' => 5 * 3600 + 30 * 60,
            'sunrise' => 7 * 3600,
            'noon' => 12 * 3600,
            'sunset' => 18 * 3600,
            'dusk' => 21 * 3600,
        ]);
    }

    /**
     * @param array{dawn: int, sunrise: int, noon: int, sunset: int, dusk: int} $anchors
     */
    private static function adaptiveKelvinFromAnchors(int $t, array $anchors): float
    {
        if ($t < $anchors['dawn'] || $t >= $anchors['dusk']) {
            return (float) self::ADAPTIVE_NIGHT_K;
        }
        if ($t < $anchors['sunrise']) {
            return self::lerpKelvin($t, $anchors['dawn'], $anchors['sunrise'], self::ADAPTIVE_NIGHT_K, self::ADAPTIVE_SUNRISE_K);
        }
        if ($t < $anchors['noon']) {
            return self::lerpKelvin($t, $anchors['sunrise'], $anchors['noon'], self::ADAPTIVE_SUNRISE_K, self::ADAPTIVE_NOON_K);
        }
        if ($t < $anchors['sunset']) {
            return self::lerpKelvin($t, $anchors['noon'], $anchors['sunset'], self::ADAPTIVE_NOON_K, self::ADAPTIVE_SUNSET_K);
        }

        return self::lerpKelvin($t, $anchors['sunset'], $anchors['dusk'], self::ADAPTIVE_SUNSET_K, self::ADAPTIVE_NIGHT_K);
    }

    private static function lerpKelvin(int $t, int $t0, int $t1, int $k0, int $k1): float
    {
        if ($t1 === $t0) {
            return (float) $k1;
        }
        $u = ($t - $t0) / ($t1 - $t0);
        if ($u < 0.0) {
            $u = 0.0;
        }
        if ($u > 1.0) {
            $u = 1.0;
        }

        return $k0 + ($k1 - $k0) * $u;
    }

    private function setActiveSceneId(string $id): void
    {
        $store = $this->load();
        $store['active_scene_id'] = trim($id);
        $this->write($store);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function write(array $store): bool
    {
        $path = $this->storePath();
        $existing = $this->readDecodedFile($path);
        if (is_array($existing)) {
            $store = $this->preserveUserMeta($store, $existing);
            $keep = $this->storeMetaScore($existing) >= $this->storeMetaScore($store) ? $existing : $store;
            $this->snapshotStore($keep);
        } elseif (is_file($path)) {
            $raw = (string) file_get_contents($path);
            if (trim($raw) !== '' && $this->storeMetaScore($store) < 4) {
                @file_put_contents($path . '.corrupt', $raw);
                $backup = $this->bestBackupStore();
                if (!is_array($backup) || $this->storeMetaScore($backup) < 4) {
                    return false;
                }
                $store = $this->preserveUserMeta($store, $backup);
            }
        }

        return $this->writeRaw($store);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function writeRaw(array $store): bool
    {
        $dir = dirname($this->storePath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        if ($this->storeMetaScore($store) >= 4) {
            $this->snapshotStore($store);
        }
        $path = $this->storePath();
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            return false;
        }
        $this->rememberLoaded($store, (int) @filemtime($path), strlen($json) + 1);

        return true;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function rememberLoaded(array $store, int $mtime, int $size): array
    {
        $this->loadedStore = $store;
        $this->loadedMtime = $mtime;
        $this->loadedSize = $size;

        return $store;
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function preserveUserMeta(array $incoming, array $existing): array
    {
        $incomingEmpty = ($incoming['room_defs'] ?? []) === [] && ($incoming['scenes'] ?? []) === [];
        $existingRich = ($existing['room_defs'] ?? []) !== [] || ($existing['scenes'] ?? []) !== [];
        if (!$incomingEmpty || !$existingRich) {
            return $incoming;
        }
        foreach (['names', 'room_defs', 'rooms', 'group_defs', 'groups', 'scenes', 'paper', 'hidden', 'device_order', 'contact_kind'] as $key) {
            if (($existing[$key] ?? []) !== []) {
                $incoming[$key] = $existing[$key];
            }
        }

        return $incoming;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>|null
     */
    private function recoverUserMeta(array $store): ?array
    {
        $needRooms = ($store['room_defs'] ?? []) === [] && ($store['rooms'] ?? []) === [];
        $tinyCurrent = count($store['room_defs'] ?? []) <= 3
            && count($store['rooms'] ?? []) < 8
            && count($store['scenes'] ?? []) === 0;
        // Empty scenes alone must not scan backups on every Home poll while regrouping.
        if (!$needRooms && !$tinyCurrent) {
            return null;
        }
        $best = $this->bestBackupStore();
        if ($best === null || $this->storeMetaScore($best) <= $this->storeMetaScore($store)) {
            return null;
        }
        $needScenes = ($store['scenes'] ?? []) === [];
        $needNames = ($store['names'] ?? []) === [];
        $needPaper = ($store['paper'] ?? []) === [];
        $richBackup = count($best['room_defs'] ?? []) >= 8
            || count($best['rooms'] ?? []) >= 10
            || count($best['scenes'] ?? []) >= 2;
        if (!$needRooms && !($tinyCurrent && $richBackup)) {
            return null;
        }
        if ($tinyCurrent && $richBackup) {
            $needRooms = true;
            $needScenes = true;
            $needNames = $needNames || count($store['names'] ?? []) < 8;
            $needPaper = true;
        }
        $changed = false;
        if ($needRooms && ($best['room_defs'] ?? []) !== []) {
            $store['room_defs'] = $best['room_defs'];
            $store['rooms'] = is_array($best['rooms'] ?? null) ? $best['rooms'] : [];
            $store['group_defs'] = is_array($best['group_defs'] ?? null) ? $best['group_defs'] : [];
            $store['groups'] = is_array($best['groups'] ?? null) ? $best['groups'] : [];
            $changed = true;
        }
        if ($needScenes && ($best['scenes'] ?? []) !== []) {
            $store['scenes'] = $best['scenes'];
            $changed = true;
        }
        if ($needPaper && ($best['paper'] ?? []) !== []) {
            $store['paper'] = $best['paper'];
            $changed = true;
        }
        if ($needNames && ($best['names'] ?? []) !== []) {
            $store['names'] = $best['names'];
            $changed = true;
        }
        if (!$changed) {
            return null;
        }
        $this->restoredHomeFrom = 'backup';

        return $store;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bestBackupStore(): ?array
    {
        $best = null;
        $bestScore = 0;
        foreach ($this->backupCandidatePaths(false) as $path) {
            $decoded = $this->readDecodedFile($path);
            if (!is_array($decoded)) {
                continue;
            }
            $score = $this->storeMetaScore($decoded);
            if ($score > $bestScore) {
                $best = $decoded;
                $bestScore = $score;
            }
        }
        if ($bestScore < 4) {
            foreach ($this->backupCandidatePaths(true) as $path) {
                $decoded = $this->readDecodedFile($path);
                if (!is_array($decoded)) {
                    continue;
                }
                $score = $this->storeMetaScore($decoded);
                if ($score > $bestScore) {
                    $best = $decoded;
                    $bestScore = $score;
                }
            }
        }

        return $bestScore >= 4 ? $best : null;
    }

    /**
     * @return list<string>
     */
    private function backupCandidatePaths(bool $datedOnly = false): array
    {
        $dir = $this->projectRoot . '/data';
        if ($datedOnly) {
            $backupDir = $dir . '/home-backups';
            if (!is_dir($backupDir)) {
                return [];
            }
            $files = glob($backupDir . '/home-*.json') ?: [];
            rsort($files);

            return $files;
        }

        return [
            $dir . '/home.json.bak',
            $dir . '/home.json.bak.1',
            $dir . '/home.json.bak.2',
            $dir . '/home.json~',
            $dir . '/home.json.corrupt',
            $dir . '/.update-preserve/home.json',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readDecodedFile(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function storeMetaScore(array $store): int
    {
        $rooms = is_array($store['room_defs'] ?? null) ? $store['room_defs'] : [];
        $scenes = is_array($store['scenes'] ?? null) ? $store['scenes'] : [];
        $assigned = is_array($store['rooms'] ?? null) ? $store['rooms'] : [];
        $names = is_array($store['names'] ?? null) ? $store['names'] : [];
        $groups = is_array($store['group_defs'] ?? null) ? $store['group_defs'] : [];
        $paper = is_array($store['paper'] ?? null) ? $store['paper'] : [];

        return (count($rooms) * 100)
            + (count($scenes) * 50)
            + count($assigned)
            + count($names)
            + (count($groups) * 10)
            + count($paper);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function snapshotStore(array $store): void
    {
        if ($this->storeMetaScore($store) < 4) {
            return;
        }
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $dir = $this->projectRoot . '/data';
        $bak = $dir . '/home.json.bak';
        $existing = $this->readDecodedFile($bak);
        $existingScore = is_array($existing) ? $this->storeMetaScore($existing) : 0;
        $score = $this->storeMetaScore($store);
        if ($score <= $existingScore) {
            return;
        }
        if (is_file($bak) && $score > $existingScore) {
            @copy($bak, $dir . '/home.json.bak.1');
        }
        @file_put_contents($bak, $json . "\n", LOCK_EX);
        $stampDir = $dir . '/home-backups';
        if (!is_dir($stampDir) && !mkdir($stampDir, 0755, true) && !is_dir($stampDir)) {
            return;
        }
        $stamp = $stampDir . '/home-' . gmdate('Ymd-His') . '.json';
        if (!is_file($stamp)) {
            @file_put_contents($stamp, $json . "\n", LOCK_EX);
        }
        $stamped = glob($stampDir . '/home-*.json') ?: [];
        rsort($stamped);
        foreach (array_slice($stamped, 12) as $old) {
            @unlink($old);
        }
    }
}
