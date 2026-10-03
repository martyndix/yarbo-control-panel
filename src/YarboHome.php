<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboHome
{
    public const KIND_LIGHT = 'light';
    public const KIND_PLUG = 'plug';
    public const KIND_SWITCH = 'switch';
    public const KIND_HEATER = 'heater';
    public const KIND_VACUUM = 'vacuum';
    public const KIND_SCENE = 'scene';
    public const PAPER_MAX = 12;
    /** Compact JSON names: long enough to fill a 480px PaperMono HOUSE row. */
    public const PAPER_NAME_MAX = 40;

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
        ];
        if (!is_file($this->storePath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->storePath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
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
        ];
        if ($migrated) {
            $this->write($store);
        } elseif ($store['last_devices'] === []) {
            $cached = $this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json');
            if ($cached !== []) {
                $store['last_devices'] = $this->normalizeLastDevices($cached);
                $this->write($store);
            }
        }

        return $store;
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

        return $this->write($store);
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
        $store = $this->load();
        $hidden = array_fill_keys($store['hidden'], true);
        $devices = [];
        $hiddenDevices = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
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
            $isLight = ((string) ($device['kind'] ?? self::KIND_LIGHT)) === self::KIND_LIGHT;
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
                'on' => YarboMatterFabric::attrBool($device['on'] ?? false),
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
            if ($row['hidden']) {
                $hiddenDevices[] = $row;
            } else {
                $devices[] = $row;
            }
        }
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
        $result = $agent->request(['op' => 'commission', 'code' => $code], 95.0);
        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Pairing failed. Check the code is still valid and the device is on the LAN.'),
            ];
        }

        @unlink($this->projectRoot . '/data/home-nodes-cache.json');

        return ['ok' => true, 'message' => 'Device added. It can take a few seconds to appear.'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function command(array $input): array
    {
        $id = trim((string) ($input['id'] ?? ''));
        $action = strtolower(trim((string) ($input['command'] ?? $input['home_action'] ?? '')));
        if ($action === 'colour' || $action === 'set_color' || $action === 'set_colour') {
            $action = 'color';
        }
        if ($action === 'colour_temp') {
            $action = 'color_temp';
        }
        if ($id === '' || $action === '') {
            return ['ok' => false, 'error' => 'Device and action are required'];
        }
        if (YarboUnifi::isHomeId($id)) {
            return (new YarboUnifi($this->projectRoot))->command($input);
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $body = ['op' => 'command', 'id' => $id, 'action' => $action];
        if ($action === 'brightness' && array_key_exists('brightness', $input)) {
            $body['brightness'] = (int) $input['brightness'];
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
        $result = $agent->request($body, 60.0);
        if (!($result['ok'] ?? false) && YarboMatterAgentClient::isUnknownCommandError($result)) {
            $agent->forceRestart();
            $result = $agent->request($body, 60.0);
        }
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $this->friendlyMatterError((string) ($result['error'] ?? 'Command failed'))];
        }
        $patch = $this->commandStatePatch($id, $action, $body, $result);
        if ($patch !== []) {
            $this->patchCachedDeviceState($id, $patch);
        }

        return ['ok' => true] + $patch;
    }

    public function friendlyMatterError(string $error): string
    {
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
        if (YarboUnifi::isHomeId($id) && !YarboUnifi::isCompanionSensorId($id)) {
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
        $errors = [];
        $kinds = $this->deviceKindMap();
        foreach ($scene['actions'] as $action) {
            $deviceId = (string) ($action['id'] ?? '');
            if ($deviceId === '') {
                continue;
            }
            $kind = $kinds[$deviceId] ?? '';
            $isHeater = $kind === self::KIND_HEATER;
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
            $kelvin = !empty($action['color_temp']) ? (int) $action['color_temp'] : null;
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
                $result = $this->command([
                    'id' => $deviceId,
                    'command' => 'color_temp',
                    'kelvin' => $kelvin,
                ]);
                $sent = true;
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
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
        foreach ($this->load()['room_defs'] as $def) {
            if (($def['id'] ?? '') === $roomId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return ['ok' => false, 'error' => 'Unknown room'];
        }
        $ids = $this->visibleDeviceIdsInRoom($roomId);
        if ($action === 'brightness') {
            $ids = $this->dimmableIdsAmong($ids);
        }
        return $this->commandIdList($ids, $action, $input, 'No devices in that room', 'Room partly failed: ');
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
        if ($this->groupRoomId($this->load(), $groupId) === '') {
            return ['ok' => false, 'error' => 'Unknown group'];
        }
        $ids = $this->visibleDeviceIdsInGroup($groupId);
        if ($action === 'brightness') {
            $ids = $this->dimmableIdsAmong($ids);
        }

        return $this->commandIdList($ids, $action, $input, 'No devices in that group', 'Group partly failed: ');
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
        if ($kind === '') {
            $kind = self::KIND_LIGHT;
        }
        if (in_array($kind, ['camera', 'sensor', 'door', 'hub'], true)) {
            return false;
        }
        if ($kind === self::KIND_HEATER) {
            return in_array($action, ['on', 'off', 'toggle', 'setpoint', 'temperature', 'heating_setpoint'], true);
        }

        return in_array($action, ['on', 'off', 'toggle', 'brightness', 'color', 'color_temp'], true);
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
            $out[] = $row;
        }

        return $out;
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
                $map[$id] = (string) ($row['kind'] ?? self::KIND_LIGHT);
            }
        }
        foreach ($this->readDeviceCache($this->projectRoot . '/data/home-nodes-cache.json') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
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
                $devices[] = is_array($row) ? YarboMatterFabric::reclassifyRow($row) : $row;
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
                ? self::overlayDeviceStates($fromDisk, $remembered)
                : $fromDisk;
            $source = 'disk';
            $this->writeDeviceCache($cachePath, $devices, $fabricMtime);
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
        $result = $agent->request(['op' => 'states'], 0.8, false);
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
    public static function overlayDeviceStates(array $devices, array $states): array
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
        $out = [];
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '' && isset($byId[$id])) {
                $live = $byId[$id];
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
            }
            $out[] = $device;
        }

        return $out;
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
            if ($id === '' || YarboUnifi::isHomeId($id)) {
                continue;
            }
            $out[] = YarboMatterFabric::reclassifyRow([
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
            ]);
        }

        return $out;
    }

    /**
     * @return array{devices: list<array<string, mixed>>, fabric_mtime: int}
     */
    private function readDeviceCacheBundle(string $path): array
    {
        $empty = ['devices' => [], 'fabric_mtime' => 0];
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

        return [
            'devices' => $devices,
            'fabric_mtime' => (int) ($cached['fabric_mtime'] ?? 0),
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
     */
    private function writeDeviceCache(string $path, array $devices, ?int $fabricMtime = null): void
    {
        $mtime = $fabricMtime;
        if ($mtime === null) {
            $mtime = $this->readDeviceCacheBundle($path)['fabric_mtime'];
        }
        @file_put_contents($path, json_encode([
            'v' => 5,
            'saved_at' => time(),
            'fabric_mtime' => $mtime,
            'devices' => $devices,
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
            $out[] = YarboMatterFabric::reclassifyRow([
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
                'dimmable' => true,
                'colorable' => true,
                'color_hs' => true,
                'color_xy' => true,
                'color_ct' => true,
                'color_hex' => '',
                'available' => false,
            ]);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $devices
     */
    private function rememberDevices(array $devices): void
    {
        $slim = $this->normalizeLastDevices($devices);
        if ($slim === []) {
            return;
        }
        $store = $this->load();
        if (($store['last_devices'] ?? []) === $slim) {
            return;
        }
        $store['last_devices'] = $slim;
        $this->write($store);
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
        $byId = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '' || in_array($id, $store['hidden'], true)) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'name' => $store['names'][$id] ?? (string) ($device['name'] ?? $id),
                'kind' => (string) ($device['kind'] ?? self::KIND_LIGHT),
                'on' => YarboMatterFabric::attrBool($device['on'] ?? false),
                'brightness' => isset($device['brightness']) ? (int) $device['brightness'] : null,
            ];
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
     * @return array<string, mixed>
     */
    public function paperCommand(string $id): array
    {
        $id = trim($id);
        if (str_starts_with($id, 'scene:')) {
            return $this->toggleScene(substr($id, 6));
        }

        return $this->command(['id' => $id, 'command' => 'toggle']);
    }

    /**
     * @param array<string, mixed> $scene
     * @return array{id: string, name: string, actions: list<array{id: string, on: bool, brightness: ?int, color_hex: ?string, color_temp: ?int, heating_setpoint: ?float}>}|null
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
            if (!$on) {
                $brightness = null;
                $hex = null;
                $kelvin = null;
                $setpoint = null;
            }
            $actions[] = [
                'id' => $deviceId,
                'on' => $on,
                'brightness' => $brightness,
                'color_hex' => $hex,
                'color_temp' => $kelvin,
                'heating_setpoint' => $setpoint,
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

    private function normalizeHex(string $value): ?string
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
     * @return list<array{id: string, name: string, on: bool, brightness: ?int, dimmable: bool, count: int, groups: list<array<string, mixed>>}>
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
                'count' => $stats['count'],
                'groups' => $this->groupsPayload($store, $id, $inRoom),
            ];
        }

        return $rooms;
    }

    /**
     * @param array{group_defs?: list<array{id: string, name: string, room_id: string}>} $store
     * @param list<array<string, mixed>> $inRoom
     * @return list<array{id: string, name: string, room_id: string, on: bool, brightness: ?int, dimmable: bool, count: int}>
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
                'count' => $stats['count'],
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return array{on: bool, dimmable: bool, brightness: ?int, count: int}
     */
    private function aggregateDeviceStats(array $devices): array
    {
        $on = false;
        $dimmable = false;
        $brightSum = 0;
        $brightN = 0;
        foreach ($devices as $device) {
            if (!empty($device['on'])) {
                $on = true;
            }
            if (!empty($device['dimmable'])) {
                $dimmable = true;
                $value = isset($device['brightness']) ? (int) $device['brightness'] : (!empty($device['on']) ? 100 : 0);
                $brightSum += $value;
                $brightN++;
            }
        }

        return [
            'on' => $on,
            'dimmable' => $dimmable,
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
    private function dimmableIdsAmong(array $ids): array
    {
        $dimmable = [];
        $live = $this->localHomeDevices();
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '' && in_array($id, $ids, true) && !empty($device['dimmable'])) {
                $dimmable[] = $id;
            }
        }

        return $dimmable;
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
        $dir = dirname($this->storePath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->storePath(), $json . "\n", LOCK_EX) !== false;
    }
}
