<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboHome
{
    public const KIND_LIGHT = 'light';
    public const KIND_PLUG = 'plug';
    public const KIND_SWITCH = 'switch';
    public const KIND_HEATER = 'heater';
    public const KIND_SCENE = 'scene';
    public const PAPER_MAX = 8;

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
     *   rooms: array<string, string>,
     *   scenes: list<array<string, mixed>>,
     *   paper: array<string, list<string>>
     * }
     */
    public function load(): array
    {
        $defaults = [
            'names' => [],
            'rooms' => [],
            'scenes' => [],
            'paper' => [],
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
        $rooms = [];
        foreach (is_array($decoded['rooms'] ?? null) ? $decoded['rooms'] : [] as $id => $room) {
            $id = trim((string) $id);
            $room = YarboHub::normalizeDisplayName((string) $room, 32);
            if ($id !== '' && $room !== '') {
                $rooms[$id] = $room;
            }
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

        return [
            'names' => $names,
            'rooms' => $rooms,
            'scenes' => $scenes,
            'paper' => $paper,
        ];
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
            foreach ($input['rooms'] as $id => $room) {
                $id = trim((string) $id);
                $room = YarboHub::normalizeDisplayName((string) $room, 32);
                if ($id === '') {
                    continue;
                }
                if ($room === '') {
                    unset($store['rooms'][$id]);
                } else {
                    $store['rooms'][$id] = $room;
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
                'scenes' => [],
                'paper_devices' => [],
                'setup' => $setup,
            ];
        }
        if (!($setup['ready'] ?? false)) {
            $store = $this->load();

            return [
                'ok' => true,
                'enabled' => true,
                'server' => [
                    'ok' => false,
                    'error' => (string) ($setup['error'] ?? $setup['message'] ?? 'Matter server is not running yet'),
                ],
                'devices' => [],
                'scenes' => $store['scenes'],
                'paper_devices' => $this->paperDeviceList($store),
                'setup' => $setup,
            ];
        }
        $live = $this->liveDevices(8.0);
        $store = $this->load();
        $devices = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = $store['names'][$id] ?? (string) ($device['name'] ?? $id);
            $devices[] = [
                'id' => $id,
                'node_id' => (int) ($device['node_id'] ?? 0),
                'endpoint' => (int) ($device['endpoint'] ?? 0),
                'name' => $name,
                'kind' => (string) ($device['kind'] ?? self::KIND_LIGHT),
                'on' => (bool) ($device['on'] ?? false),
                'brightness' => isset($device['brightness']) ? (int) $device['brightness'] : null,
                'dimmable' => (bool) ($device['dimmable'] ?? false),
                'available' => (bool) ($device['available'] ?? true),
                'room' => $store['rooms'][$id] ?? '',
            ];
        }
        return [
            'ok' => true,
            'enabled' => true,
            'server' => [
                'ok' => (bool) $live['ok'],
                'error' => (string) $live['error'],
            ],
            'devices' => $devices,
            'scenes' => $store['scenes'],
            'paper_devices' => $this->paperDeviceList($store),
            'setup' => $this->setupStatus(),
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
        $fp = @fsockopen('127.0.0.1', 5580, $errno, $errstr, 0.35);
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
        if ($id === '' || $action === '') {
            return ['ok' => false, 'error' => 'Device and action are required'];
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $body = ['op' => 'command', 'id' => $id, 'action' => $action];
        if ($action === 'brightness' && array_key_exists('brightness', $input)) {
            $body['brightness'] = (int) $input['brightness'];
        }
        $result = $agent->request($body, 15.0);
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Command failed')];
        }
        @unlink($this->projectRoot . '/data/home-nodes-cache.json');

        return ['ok' => true];
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
        $this->write($store);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function runScene(string $id): array
    {
        $id = trim($id);
        $scene = null;
        foreach ($this->load()['scenes'] as $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                $scene = $candidate;
                break;
            }
        }
        if ($scene === null) {
            return ['ok' => false, 'error' => 'Scene not found'];
        }
        $errors = [];
        foreach ($scene['actions'] as $action) {
            $deviceId = (string) ($action['id'] ?? '');
            if ($deviceId === '') {
                continue;
            }
            if (array_key_exists('brightness', $action) && $action['brightness'] !== null) {
                $result = $this->command([
                    'id' => $deviceId,
                    'command' => 'brightness',
                    'brightness' => (int) $action['brightness'],
                ]);
            } else {
                $result = $this->command([
                    'id' => $deviceId,
                    'command' => !empty($action['on']) ? 'on' : 'off',
                ]);
            }
            if (!($result['ok'] ?? false)) {
                $errors[] = (string) ($result['error'] ?? 'failed');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => 'Scene partly failed: ' . $errors[0]];
        }
        @unlink($this->projectRoot . '/data/home-nodes-cache.json');

        return ['ok' => true];
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
        $this->write($store);

        return ['ok' => true, 'assigned' => $store['paper'][$tabletId]];
    }

    /**
     * @return array{ok: bool, error: string, devices: list<array<string, mixed>>}
     */
    private function liveDevices(float $timeout): array
    {
        $cachePath = $this->projectRoot . '/data/home-nodes-cache.json';
        $fresh = is_file($cachePath) && (time() - (int) filemtime($cachePath)) < 8;
        if ($fresh) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && is_array($cached['devices'] ?? null)) {
                return [
                    'ok' => true,
                    'error' => '',
                    'devices' => $cached['devices'],
                ];
            }
        }
        $agent = YarboMatterAgentClient::fromEnv();
        $status = $agent->request(['op' => 'status'], min(6.0, $timeout));
        $error = (string) ($status['error'] ?? 'Matter server unavailable');
        $nodes = ['ok' => false, 'devices' => []];
        if (($status['ok'] ?? false) === true || ($status['server'] ?? false) === true) {
            $nodes = $agent->request(['op' => 'nodes'], $timeout);
            $error = (string) ($nodes['error'] ?? $error);
        }
        $devices = is_array($nodes['devices'] ?? null) ? $nodes['devices'] : [];
        if (($nodes['ok'] ?? false) === true) {
            @file_put_contents($cachePath, json_encode([
                'saved_at' => time(),
                'devices' => $devices,
            ], JSON_UNESCAPED_SLASHES));
        } elseif (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && is_array($cached['devices'] ?? null)) {
                $devices = $cached['devices'];
            }
        }

        return [
            'ok' => (bool) ($nodes['ok'] ?? false),
            'error' => ($nodes['ok'] ?? false) ? '' : $error,
            'devices' => $devices,
        ];
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
        $live = $this->liveDevices(6.0);
        $byId = [];
        foreach ($live['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'name' => $store['names'][$id] ?? (string) ($device['name'] ?? $id),
                'kind' => (string) ($device['kind'] ?? self::KIND_LIGHT),
                'on' => (bool) ($device['on'] ?? false),
            ];
        }
        foreach ($store['scenes'] as $scene) {
            $sid = 'scene:' . (string) ($scene['id'] ?? '');
            $byId[$sid] = [
                'id' => $sid,
                'name' => (string) ($scene['name'] ?? 'Scene'),
                'kind' => self::KIND_SCENE,
                'on' => false,
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
                'name' => YarboHub::normalizeDisplayName((string) $item['name'], 18) ?: (string) $item['id'],
                'kind' => (string) ($item['kind'] ?? self::KIND_LIGHT),
                'on' => (bool) ($item['on'] ?? false),
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
            return $this->runScene(substr($id, 6));
        }

        return $this->command(['id' => $id, 'command' => 'toggle']);
    }

    /**
     * @param array<string, mixed> $scene
     * @return array{id: string, name: string, actions: list<array{id: string, on: bool, brightness: ?int}>}|null
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
            $actions[] = [
                'id' => $deviceId,
                'on' => !empty($action['on']) || ($brightness !== null && $brightness > 0),
                'brightness' => $brightness,
            ];
        }
        if ($id === '' || $name === '' || $actions === []) {
            return null;
        }

        return ['id' => $id, 'name' => $name, 'actions' => $actions];
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
