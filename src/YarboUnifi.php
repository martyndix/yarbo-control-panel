<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboUnifi
{
    public const SOURCE = 'unifi';
    public const KIND_CAMERA = 'camera';
    public const KIND_LIGHT = 'light';
    public const KIND_SENSOR = 'sensor';
    public const KIND_DOOR = 'door';
    public const KIND_RELAY = 'relay';
    public const KIND_HUB = 'hub';

    /** @var callable|null */
    private $transport = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/unifi-config.json';
    }

    public function inventoryPath(): string
    {
        return $this->projectRoot . '/data/unifi-inventory.json';
    }

    /**
     * @param callable(string,string,array<string,string>,?string,float,bool): array{status:int,body:string,content_type:string,error?:string} $handler
     */
    public function setTransport(callable $handler): void
    {
        $this->transport = $handler;
    }

    public static function homeId(string $kind, string $nativeId): string
    {
        return self::SOURCE . ':' . $kind . ':' . $nativeId;
    }

    /**
     * @return array{kind: string, native_id: string}|null
     */
    public static function parseHomeId(string $id): ?array
    {
        $id = trim($id);
        if (!str_starts_with($id, self::SOURCE . ':')) {
            return null;
        }
        $rest = substr($id, strlen(self::SOURCE) + 1);
        $parts = explode(':', $rest, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $kind = strtolower(trim($parts[0]));
        $native = trim($parts[1]);
        if ($kind === '' || $native === '') {
            return null;
        }

        return ['kind' => $kind, 'native_id' => $native];
    }

    public static function isHomeId(string $id): bool
    {
        return self::parseHomeId($id) !== null;
    }

    public static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];
        $value = trim($value);

        return $value;
    }

    /**
     * @return array{
     *   host: string,
     *   verify_tls: bool,
     *   protect_api_key: string,
     *   protect_username: string,
     *   protect_password: string,
     *   access_token: string,
     *   access_port: int,
     *   show_on_home: list<string>,
     *   last_ok: bool,
     *   last_error: string,
     *   last_check: ?string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'host' => '',
            'verify_tls' => false,
            'protect_api_key' => '',
            'protect_username' => '',
            'protect_password' => '',
            'access_token' => '',
            'access_port' => 0,
            'show_on_home' => [],
            'last_ok' => false,
            'last_error' => '',
            'last_check' => null,
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }

        return [
            'host' => self::normalizeHost((string) ($decoded['host'] ?? '')),
            'verify_tls' => (bool) ($decoded['verify_tls'] ?? false),
            'protect_api_key' => (string) ($decoded['protect_api_key'] ?? ''),
            'protect_username' => trim((string) ($decoded['protect_username'] ?? '')),
            'protect_password' => (string) ($decoded['protect_password'] ?? ''),
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'access_port' => max(0, (int) ($decoded['access_port'] ?? 0)),
            'show_on_home' => $this->normalizeIdList($decoded['show_on_home'] ?? []),
            'last_ok' => (bool) ($decoded['last_ok'] ?? false),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
            'last_check' => isset($decoded['last_check']) && is_string($decoded['last_check'])
                ? $decoded['last_check']
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $current = $this->load();
        $host = array_key_exists('unifi_host', $input) || array_key_exists('host', $input)
            ? self::normalizeHost((string) ($input['unifi_host'] ?? $input['host'] ?? ''))
            : $current['host'];
        $verify = array_key_exists('unifi_verify_tls', $input) || array_key_exists('verify_tls', $input)
            ? YarboHub::asBool($input['unifi_verify_tls'] ?? $input['verify_tls'] ?? false)
            : $current['verify_tls'];
        $protectKey = $current['protect_api_key'];
        if (array_key_exists('unifi_protect_api_key', $input) || array_key_exists('protect_api_key', $input)) {
            $next = trim((string) ($input['unifi_protect_api_key'] ?? $input['protect_api_key'] ?? ''));
            if ($next !== '') {
                $protectKey = $next;
            }
        }
        $username = array_key_exists('unifi_protect_username', $input) || array_key_exists('protect_username', $input)
            ? trim((string) ($input['unifi_protect_username'] ?? $input['protect_username'] ?? ''))
            : $current['protect_username'];
        $password = $current['protect_password'];
        if (array_key_exists('unifi_protect_password', $input) || array_key_exists('protect_password', $input)) {
            $next = (string) ($input['unifi_protect_password'] ?? $input['protect_password'] ?? '');
            if ($next !== '') {
                $password = $next;
            }
        }
        $access = $current['access_token'];
        if (array_key_exists('unifi_access_token', $input) || array_key_exists('access_token', $input)) {
            $next = trim((string) ($input['unifi_access_token'] ?? $input['access_token'] ?? ''));
            if ($next !== '') {
                $access = $next;
            }
        }
        $port = $current['access_port'];
        if (array_key_exists('unifi_access_port', $input) || array_key_exists('access_port', $input)) {
            $port = max(0, (int) ($input['unifi_access_port'] ?? $input['access_port'] ?? 0));
        } elseif (array_key_exists('unifi_access_standalone', $input)) {
            $port = YarboHub::asBool($input['unifi_access_standalone']) ? 12445 : 0;
        }
        $show = array_key_exists('unifi_show_on_home', $input) || array_key_exists('show_on_home', $input)
            ? $this->normalizeIdList($input['unifi_show_on_home'] ?? $input['show_on_home'] ?? [])
            : $current['show_on_home'];
        $next = [
            'host' => $host,
            'verify_tls' => $verify,
            'protect_api_key' => $protectKey,
            'protect_username' => $username,
            'protect_password' => $password,
            'access_token' => $access,
            'access_port' => $port,
            'show_on_home' => $show,
            'last_ok' => array_key_exists('last_ok', $input) ? (bool) $input['last_ok'] : $current['last_ok'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
            'last_check' => $input['last_check'] ?? $current['last_check'],
        ];
        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
    }

    public function setShowOnHome(string $homeId, bool $show): bool
    {
        $homeId = trim($homeId);
        if ($homeId === '' || !self::isHomeId($homeId)) {
            return false;
        }
        $config = $this->load();
        $list = $config['show_on_home'];
        $has = in_array($homeId, $list, true);
        if ($show && !$has) {
            $list[] = $homeId;
        } elseif (!$show && $has) {
            $list = array_values(array_filter($list, static fn (string $id): bool => $id !== $homeId));
        } else {
            return true;
        }

        return $this->save(['show_on_home' => $list]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $config = $this->load();
        $inventory = $this->readInventory();

        return [
            'host' => $config['host'],
            'verify_tls' => $config['verify_tls'],
            'protect_api_key_set' => $config['protect_api_key'] !== '',
            'protect_username' => $config['protect_username'],
            'protect_password_set' => $config['protect_password'] !== '',
            'access_token_set' => $config['access_token'] !== '',
            'access_port' => $config['access_port'],
            'access_standalone' => $config['access_port'] === 12445,
            'show_on_home' => $config['show_on_home'],
            'last_ok' => $config['last_ok'],
            'last_error' => $config['last_error'] !== '' ? $config['last_error'] : null,
            'last_check' => $config['last_check'],
            'devices' => $this->catalogFromInventory($inventory, $config['show_on_home']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        $config = $this->load();
        if ($config['host'] === '') {
            return ['ok' => false, 'error' => 'Enter the UniFi console IP or hostname.'];
        }
        if ($config['protect_api_key'] === '' && $config['access_token'] === '') {
            return ['ok' => false, 'error' => 'Add a Protect API key, an Access token, or both.'];
        }
        $inventory = $this->fetchInventory($config, 4.0);
        $errors = $inventory['errors'];
        $counts = [
            'cameras' => count($inventory['cameras']),
            'lights' => count($inventory['lights']),
            'sensors' => count($inventory['sensors']),
            'relays' => count($inventory['relays']),
            'doors' => count($inventory['doors']),
            'hubs' => count($inventory['hubs'] ?? []),
        ];
        $ok = array_sum($counts) > 0 || $errors === [];
        $error = $ok ? '' : implode(' ', $errors);
        if ($errors !== [] && array_sum($counts) > 0) {
            $error = implode(' ', $errors);
            $ok = true;
        }
        $this->writeInventory($inventory);
        $this->save([
            'last_ok' => $ok,
            'last_error' => $error,
            'last_check' => gmdate('c'),
        ]);
        $message = $ok
            ? sprintf(
                'Connected. %d camera%s, %d light%s, %d relay%s, %d sensor%s, %d door%s, %d controller%s.',
                $counts['cameras'],
                $counts['cameras'] === 1 ? '' : 's',
                $counts['lights'],
                $counts['lights'] === 1 ? '' : 's',
                $counts['relays'],
                $counts['relays'] === 1 ? '' : 's',
                $counts['sensors'],
                $counts['sensors'] === 1 ? '' : 's',
                $counts['doors'],
                $counts['doors'] === 1 ? '' : 's',
                $counts['hubs'],
                $counts['hubs'] === 1 ? '' : 's'
            )
            : ($error !== '' ? $error : 'Could not reach UniFi.');

        return [
            'ok' => $ok,
            'message' => $message,
            'error' => $ok ? null : $message,
            'counts' => $counts,
            'devices' => $this->catalogFromInventory($inventory, $this->load()['show_on_home']),
        ] + $this->publicView();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(bool $refresh = true): array
    {
        $config = $this->load();
        $inventory = $this->readInventory();
        if ($refresh && $config['host'] !== '' && ($config['protect_api_key'] !== '' || $config['access_token'] !== '')) {
            try {
                $live = $this->fetchInventory($config, 3.5);
                if ($this->inventoryCount($live) > 0 || ($live['errors'] === [] && $this->inventoryCount($inventory) === 0)) {
                    $inventory = $live;
                    $this->writeInventory($live);
                }
            } catch (\Throwable) {
                // Keep last inventory.
            }
        }
        $show = $config['show_on_home'];

        return [
            'ok' => $config['last_ok'] || $this->inventoryCount($inventory) > 0,
            'online' => $config['last_ok'],
            'error' => $config['last_error'] !== '' ? $config['last_error'] : null,
            'last_check' => $config['last_check'],
            'config' => $this->publicView(),
            'cameras' => $inventory['cameras'],
            'lights' => $inventory['lights'],
            'sensors' => $inventory['sensors'],
            'relays' => $inventory['relays'],
            'doors' => $inventory['doors'],
            'hubs' => $inventory['hubs'] ?? [],
            'devices' => $this->catalogFromInventory($inventory, $show),
        ];
    }

    /**
     * Rows for the Home grid (selected devices only).
     *
     * @return list<array<string, mixed>>
     */
    public function homeRows(): array
    {
        $config = $this->load();
        if ($config['show_on_home'] === []) {
            return [];
        }
        $wanted = array_fill_keys($config['show_on_home'], true);
        $rows = [];
        foreach ($this->dashboardPayload(false)['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '' && isset($wanted[$id])) {
                $rows[] = $device;
            }
        }

        return $rows;
    }

    public function snapshotJpeg(string $cameraId): string
    {
        $cameraId = trim($cameraId);
        $parsed = self::parseHomeId($cameraId);
        if ($parsed !== null) {
            $cameraId = $parsed['native_id'];
        }
        if ($cameraId === '') {
            throw new \RuntimeException('Pick a camera');
        }
        $config = $this->load();
        if ($config['protect_api_key'] === '') {
            throw new \RuntimeException('Add a Protect API key in Settings → UniFi.');
        }
        $cacheDir = $this->projectRoot . '/data';
        $cache = $cacheDir . '/unifi-snap-' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $cameraId) . '.jpg';
        if (is_file($cache) && (time() - (int) filemtime($cache)) < 20) {
            $cached = file_get_contents($cache);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $url = $this->protectUrl($config, '/cameras/' . rawurlencode($cameraId) . '/snapshot?highQuality=false');
        $res = $this->request('GET', $url, $this->protectHeaders($config), null, 6.0, true);
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            throw new \RuntimeException($res['error'] ?? ('Protect snapshot failed HTTP ' . $res['status']));
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cache, $res['body']);

        return $res['body'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function command(array $input): array
    {
        $id = trim((string) ($input['id'] ?? ''));
        $parsed = self::parseHomeId($id);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Not a UniFi device'];
        }
        $action = strtolower(trim((string) ($input['command'] ?? $input['home_action'] ?? $input['action'] ?? '')));
        $kind = $parsed['kind'];
        $native = $parsed['native_id'];
        if ($kind === self::KIND_LIGHT) {
            if ($action === 'toggle') {
                $on = $this->lightIsOn($native);
                $action = $on ? 'off' : 'on';
            }
            if (!in_array($action, ['on', 'off'], true)) {
                return ['ok' => false, 'error' => 'UniFi lights support On and Off'];
            }

            return $this->setLight($native, $action === 'on');
        }
        if ($kind === self::KIND_RELAY) {
            if ($action === 'toggle') {
                $on = $this->relayIsOn($native);
                $action = $on ? 'off' : 'on';
            }
            if (!in_array($action, ['on', 'off'], true)) {
                return ['ok' => false, 'error' => 'Protect relays support On and Off'];
            }

            return $this->setRelay($native, $action === 'on');
        }
        if ($kind === self::KIND_DOOR || $kind === self::KIND_HUB) {
            $doorId = $native;
            if ($kind === self::KIND_HUB) {
                $linked = $this->hubDoorId($native);
                if ($linked === '') {
                    return ['ok' => false, 'error' => 'This hub is not bound to a door'];
                }
                $doorId = $linked;
            }
            $cmd = strtolower(trim((string) ($input['control_cmd'] ?? '')));
            if ($action === 'unlock' || $action === 'on' || $action === 'open' || $action === '') {
                return $this->unlockDoor($doorId, $cmd !== '' ? $cmd : null);
            }
            if (in_array($action, ['open', 'close', 'stop'], true)) {
                return $this->unlockDoor($doorId, $action);
            }

            return ['ok' => false, 'error' => 'Doors unlock; they are not Matter toggles'];
        }

        return ['ok' => false, 'error' => 'That UniFi device is read-only on Home'];
    }

    /**
     * @return array<string, mixed>
     */
    public function setLight(string $id, bool $on): array
    {
        $config = $this->load();
        if ($config['protect_api_key'] === '') {
            return ['ok' => false, 'error' => 'Add a Protect API key first'];
        }
        $url = $this->protectUrl($config, '/lights/' . rawurlencode($id));
        $body = json_encode(['isLightForceEnabled' => $on], JSON_THROW_ON_ERROR);
        $res = $this->request('PATCH', $url, $this->protectHeaders($config), $body, 6.0, false);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['error'] ?? ('Protect light HTTP ' . $res['status'])];
        }

        return ['ok' => true, 'on' => $on];
    }

    /**
     * Protect UL-Relay (and similar) outputs — not Access doors.
     *
     * @return array<string, mixed>
     */
    public function setRelay(string $id, bool $on): array
    {
        $config = $this->load();
        if ($config['protect_api_key'] === '') {
            return ['ok' => false, 'error' => 'Add a Protect API key first'];
        }
        $relayId = $id;
        $outputId = 1;
        if (str_contains($id, ':')) {
            [$relayId, $out] = explode(':', $id, 2);
            $outputId = (int) $out;
            if ($outputId < 1) {
                $outputId = 1;
            }
        }
        if ($relayId === '') {
            return ['ok' => false, 'error' => 'Pick a relay'];
        }
        $url = $this->protectUrl($config, '/relays/' . rawurlencode($relayId) . '/outputs/' . $outputId . '/activate');
        $body = json_encode(['state' => $on ? 'on' : 'off'], JSON_THROW_ON_ERROR);
        $res = $this->request('POST', $url, $this->protectHeaders($config), $body, 6.0, false);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['error'] ?? ('Protect relay HTTP ' . $res['status'])];
        }

        return ['ok' => true, 'on' => $on];
    }

    /**
     * @return array<string, mixed>
     */
    public function unlockDoor(string $id, ?string $controlCmd = null): array
    {
        $config = $this->load();
        if ($config['access_token'] === '') {
            return ['ok' => false, 'error' => 'Add an Access API token first'];
        }
        $path = '/doors/' . rawurlencode($id) . '/unlock';
        if ($controlCmd !== null && $controlCmd !== '') {
            $cmd = strtolower($controlCmd);
            if (!in_array($cmd, ['open', 'close', 'stop'], true)) {
                return ['ok' => false, 'error' => 'Gate command must be open, close, or stop'];
            }
            $path .= '?control_cmd=' . rawurlencode($cmd);
        }
        $url = $this->accessUrl($config, $path);
        $headers = $this->accessHeaders($config);
        $res = $this->request('PUT', $url, $headers, '{}', 8.0, false);
        if ($res['status'] === 404 || $res['status'] === 405) {
            $res = $this->request('POST', $url, $headers, '{}', 8.0, false);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $res['error'] ?? ('Access unlock HTTP ' . $res['status'])];
        }
        $decoded = $this->decodeJson($res['body']);
        if (is_array($decoded) && isset($decoded['code']) && strtoupper((string) $decoded['code']) !== 'SUCCESS') {
            $msg = (string) ($decoded['msg'] ?? $decoded['message'] ?? 'Access unlock failed');

            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true, 'on' => false, 'unlocked' => true];
    }

    public static function unlockPath(string $doorId, ?string $controlCmd = null): string
    {
        $path = '/doors/' . rawurlencode($doorId) . '/unlock';
        if ($controlCmd !== null && $controlCmd !== '') {
            $path .= '?control_cmd=' . rawurlencode($controlCmd);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   cameras: list<array<string, mixed>>,
     *   lights: list<array<string, mixed>>,
     *   sensors: list<array<string, mixed>>,
     *   relays: list<array<string, mixed>>,
     *   doors: list<array<string, mixed>>,
     *   hubs: list<array<string, mixed>>,
     *   errors: list<string>
     * }
     */
    public function fetchInventory(array $config, float $timeout): array
    {
        $out = [
            'cameras' => [],
            'lights' => [],
            'sensors' => [],
            'relays' => [],
            'doors' => [],
            'hubs' => [],
            'errors' => [],
        ];
        if ($config['protect_api_key'] !== '') {
            foreach (['cameras' => '/cameras', 'lights' => '/lights', 'sensors' => '/sensors', 'relays' => '/relays'] as $key => $path) {
                $res = $this->protectJson($config, $path, $timeout);
                if (!($res['ok'] ?? false)) {
                    if ($key === 'cameras' || $key === 'lights') {
                        $out['errors'][] = (string) ($res['error'] ?? ('Protect ' . $key . ' failed'));
                    }
                    continue;
                }
                $rows = [];
                foreach ($res['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if ($key === 'relays') {
                        foreach ($this->mapRelays($item) as $mapped) {
                            $rows[] = $mapped;
                        }
                        continue;
                    }
                    $mapped = match ($key) {
                        'cameras' => $this->mapCamera($item),
                        'lights' => $this->mapLight($item),
                        default => $this->mapSensor($item),
                    };
                    if ($mapped !== null) {
                        $rows[] = $mapped;
                    }
                }
                $out[$key] = $rows;
            }
        }
        if ($config['access_token'] !== '') {
            $this->appendAccessInventory($out, $config, $timeout);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $inventory
     * @param list<string> $showOnHome
     * @return list<array<string, mixed>>
     */
    public function catalogFromInventory(array $inventory, array $showOnHome): array
    {
        $show = array_fill_keys($showOnHome, true);
        $out = [];
        foreach (['cameras', 'lights', 'sensors', 'relays', 'doors', 'hubs'] as $group) {
            foreach ($inventory[$group] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[] = $row + ['show_on_home' => isset($show[$id])];
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeIdList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $id = trim((string) $item);
            if ($id === '' || isset($seen[$id]) || !self::isHomeId($id)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapCamera(array $row): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $connected = $this->truthy($row['isConnected'] ?? $row['is_connected'] ?? null)
            || strtoupper((string) ($row['state'] ?? '')) === 'CONNECTED';

        return [
            'id' => self::homeId(self::KIND_CAMERA, $id),
            'native_id' => $id,
            'name' => $this->displayName($row, 'Camera'),
            'kind' => self::KIND_CAMERA,
            'source' => self::SOURCE,
            'product' => (string) ($row['type'] ?? $row['model'] ?? 'Protect camera'),
            'available' => $connected || $this->truthy($row['isAdopted'] ?? true),
            'on' => $connected,
            'dimmable' => false,
            'colorable' => false,
            'status' => $connected ? 'Online' : 'Offline',
            'snapshot' => '/api/unifi.php?action=snapshot&id=' . rawurlencode($id),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapLight(array $row): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $force = $this->truthy($row['isLightForceEnabled'] ?? $row['is_light_force_enabled'] ?? false);
        if (!$force && isset($row['lightOnSettings']) && is_array($row['lightOnSettings'])) {
            $force = $this->truthy($row['lightOnSettings']['isLedForceOn'] ?? false);
        }
        $on = $force
            || $this->truthy($row['isLightOn'] ?? $row['is_light_on'] ?? false);
        if (!$on) {
            $mode = strtolower((string) ($row['lightMode'] ?? $row['light_mode'] ?? ''));
            if ($mode === '' && isset($row['lightModeSettings']) && is_array($row['lightModeSettings'])) {
                $mode = strtolower((string) ($row['lightModeSettings']['mode'] ?? ''));
            }
            $on = $mode === 'on';
        }

        return [
            'id' => self::homeId(self::KIND_LIGHT, $id),
            'native_id' => $id,
            'name' => $this->displayName($row, 'Light'),
            'kind' => self::KIND_LIGHT,
            'source' => self::SOURCE,
            'product' => (string) ($row['type'] ?? $row['model'] ?? 'Protect light'),
            'available' => true,
            'on' => $on,
            'dimmable' => false,
            'colorable' => false,
            'status' => $on ? 'On' : 'Off',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapSensor(array $row): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $open = $this->nullableBool($row['isOpened'] ?? $row['is_opened'] ?? $row['open'] ?? null);
        $motion = $this->truthy($row['isMotionDetected'] ?? $row['is_motion_detected'] ?? $row['motionDetected'] ?? false);
        $leak = $this->truthy($row['leakDetected'] ?? $row['is_leaking'] ?? false);
        $temp = $this->nestedNumber($row, ['stats', 'temperature', 'value'])
            ?? $this->nestedNumber($row, ['temperature']);
        $humidity = $this->nestedNumber($row, ['stats', 'humidity', 'value'])
            ?? $this->nestedNumber($row, ['humidity']);
        $parts = [];
        if ($open === true) {
            $parts[] = 'Open';
        } elseif ($open === false) {
            $parts[] = 'Closed';
        }
        if ($motion) {
            $parts[] = 'Motion';
        }
        if ($leak) {
            $parts[] = 'Leak';
        }
        if ($temp !== null) {
            $parts[] = round($temp, 1) . '°';
        }
        if ($humidity !== null) {
            $parts[] = round($humidity) . '% RH';
        }
        $status = $parts !== [] ? implode(' · ', $parts) : 'OK';

        return [
            'id' => self::homeId(self::KIND_SENSOR, $id),
            'native_id' => $id,
            'name' => $this->displayName($row, 'Sensor'),
            'kind' => self::KIND_SENSOR,
            'source' => self::SOURCE,
            'product' => (string) ($row['type'] ?? $row['model'] ?? 'Protect sensor'),
            'available' => true,
            'on' => $open === true || $motion || $leak,
            'dimmable' => false,
            'colorable' => false,
            'status' => $status,
            'open' => $open,
            'motion' => $motion,
            'temperature' => $temp,
            'humidity' => $humidity,
        ];
    }

    /**
     * Protect relays (UL-Relay). Access door controllers are hubs, not these.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function mapRelays(array $row): array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return [];
        }
        $outputs = $row['outputs'] ?? [];
        if (!is_array($outputs) || $outputs === []) {
            $on = $this->truthy($row['relayState'] ?? $row['isOn'] ?? $row['on'] ?? false);
            $native = $id . ':1';

            return [[
                'id' => self::homeId(self::KIND_RELAY, $native),
                'native_id' => $native,
                'relay_id' => $id,
                'output_id' => 1,
                'name' => $this->displayName($row, 'Relay'),
                'kind' => self::KIND_RELAY,
                'source' => self::SOURCE,
                'product' => (string) ($row['type'] ?? $row['model'] ?? 'Protect relay'),
                'available' => $this->relayConnected($row),
                'on' => $on,
                'dimmable' => false,
                'colorable' => false,
                'status' => $on ? 'On' : 'Off',
            ]];
        }
        $deviceName = $this->displayName($row, 'Relay');
        $rows = [];
        foreach ($outputs as $output) {
            if (!is_array($output)) {
                continue;
            }
            $oid = $output['id'] ?? $output['outputId'] ?? $output['output_id'] ?? 0;
            $state = strtolower((string) ($output['state'] ?? ''));
            $on = $state === 'on' || $this->truthy($output['state'] ?? false);
            $outName = YarboHub::normalizeDisplayName((string) ($output['name'] ?? ''), 48);
            $name = $outName !== '' ? $outName : $deviceName;
            $native = $id . ':' . (string) $oid;
            $rows[] = [
                'id' => self::homeId(self::KIND_RELAY, $native),
                'native_id' => $native,
                'relay_id' => $id,
                'output_id' => (int) $oid,
                'name' => $name,
                'kind' => self::KIND_RELAY,
                'source' => self::SOURCE,
                'product' => (string) ($row['type'] ?? $row['model'] ?? 'Protect relay'),
                'available' => $this->relayConnected($row),
                'on' => $on,
                'dimmable' => false,
                'colorable' => false,
                'status' => $on ? 'On' : 'Off',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function relayConnected(array $row): bool
    {
        $state = strtoupper((string) ($row['state'] ?? ''));

        return $state === '' || $state === 'CONNECTED' || $this->truthy($row['isConnected'] ?? true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapDoor(array $row): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $lock = strtolower((string) ($row['door_lock_relay_status'] ?? $row['lock_status'] ?? $row['lockState'] ?? ''));
        $locked = $lock === 'lock' || $lock === 'locked' || $this->truthy($row['isLocked'] ?? $row['is_locked'] ?? null);
        if ($lock === 'unlock' || $lock === 'unlocked') {
            $locked = false;
        }
        $dps = strtolower((string) ($row['door_position_status'] ?? $row['doorPositionStatus'] ?? ''));
        $gate = $this->truthy($row['is_bind_gate'] ?? $row['gate'] ?? false)
            || str_contains(strtolower((string) ($row['type'] ?? $row['device_type'] ?? '')), 'gate');
        $parts = [];
        $parts[] = $locked ? 'Locked' : 'Unlocked';
        if ($dps === 'open') {
            $parts[] = 'Open';
        } elseif ($dps === 'close' || $dps === 'closed') {
            $parts[] = 'Closed';
        }

        return [
            'id' => self::homeId(self::KIND_DOOR, $id),
            'native_id' => $id,
            'name' => $this->displayName($row, 'Door'),
            'kind' => self::KIND_DOOR,
            'source' => self::SOURCE,
            'product' => (string) ($row['full_name'] ?? $row['type'] ?? 'Access door'),
            'available' => $this->truthy($row['is_bind_hub'] ?? $row['isOnline'] ?? true),
            'on' => !$locked,
            'dimmable' => false,
            'colorable' => false,
            'status' => implode(' · ', $parts),
            'locked' => $locked,
            'gate' => $gate,
            'has_dps' => $this->doorHasPositionSensor($row),
            'dps' => $dps,
        ];
    }

    /**
     * Access /devices is a list of per-door groups (hub + readers). Flatten those
     * and emit hubs plus a door-position row when a DPS is wired to the hub.
     *
     * @param array<string, mixed> $out
     * @param array<string, mixed> $config
     */
    private function appendAccessInventory(array &$out, array $config, float $timeout): void
    {
        $doorsRes = $this->accessJson($config, '/doors', $timeout);
        if (!($doorsRes['ok'] ?? false)) {
            $out['errors'][] = (string) ($doorsRes['error'] ?? 'Access doors failed');
        } else {
            $rows = [];
            $sensors = is_array($out['sensors']) ? $out['sensors'] : [];
            foreach (self::flattenAccessItems($doorsRes['items']) as $item) {
                $mapped = $this->mapDoor($item);
                if ($mapped === null) {
                    continue;
                }
                $rows[] = $mapped;
                $dps = $this->mapDoorPositionSensor($item);
                if ($dps !== null) {
                    $sensors[] = $dps;
                }
            }
            $out['doors'] = $rows;
            $out['sensors'] = $sensors;
        }

        $devRes = $this->accessJson($config, '/devices', $timeout);
        if (!($devRes['ok'] ?? false)) {
            return;
        }
        $doorsById = [];
        $doorsByName = [];
        foreach ($out['doors'] as $door) {
            if (!is_array($door)) {
                continue;
            }
            $doorsById[(string) ($door['native_id'] ?? '')] = $door;
            $name = strtolower((string) ($door['name'] ?? ''));
            if ($name !== '') {
                $doorsByName[$name] = $door;
            }
        }
        $hubs = [];
        foreach ($this->accessDeviceGroups($devRes['items']) as $group) {
            $groupDoorId = '';
            foreach ($group as $item) {
                $loc = trim((string) ($item['location_id'] ?? $item['door_id'] ?? ''));
                if ($loc !== '' && isset($doorsById[$loc])) {
                    $groupDoorId = $loc;
                    break;
                }
            }
            foreach ($group as $item) {
                if (!$this->isAccessHub($item)) {
                    continue;
                }
                $mapped = $this->mapHub($item, $groupDoorId, $doorsById, $doorsByName);
                if ($mapped !== null) {
                    $hubs[] = $mapped;
                }
            }
        }
        $out['hubs'] = $hubs;
    }

    /**
     * @param list<mixed> $items
     * @return list<list<array<string, mixed>>>
     */
    private function accessDeviceGroups(array $items): array
    {
        $groups = [];
        $looksGrouped = false;
        foreach ($items as $item) {
            if (is_array($item) && array_is_list($item)) {
                $looksGrouped = true;
                $group = [];
                foreach (self::flattenAccessItems($item) as $row) {
                    $group[] = $row;
                }
                if ($group !== []) {
                    $groups[] = $group;
                }
            }
        }
        if ($looksGrouped) {
            return $groups;
        }
        $flat = [];
        foreach (self::flattenAccessItems($items) as $row) {
            $flat[] = [$row];
        }

        return $flat;
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    public static function flattenAccessItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $assoc = !array_is_list($item);
            if ($assoc && (isset($item['id']) || isset($item['name']) || isset($item['type']))) {
                $out[] = $item;
                continue;
            }
            foreach (self::flattenAccessItems($item) as $inner) {
                $out[] = $inner;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $doorsById
     * @param array<string, array<string, mixed>> $doorsByName
     * @return array<string, mixed>|null
     */
    private function mapHub(array $row, string $groupDoorId, array $doorsById, array $doorsByName): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $doorId = trim((string) ($row['location_id'] ?? $row['door_id'] ?? ''));
        if ($doorId === '' || !isset($doorsById[$doorId])) {
            $doorId = $groupDoorId;
        }
        if ($doorId === '' || !isset($doorsById[$doorId])) {
            $hay = strtolower((string) ($row['full_name'] ?? '') . ' ' . (string) ($row['name'] ?? ''));
            foreach ($doorsByName as $name => $door) {
                if ($name !== '' && str_contains($hay, $name)) {
                    $doorId = (string) ($door['native_id'] ?? '');
                    break;
                }
            }
        }
        $door = $doorsById[$doorId] ?? null;
        $type = (string) ($row['type'] ?? $row['device_type'] ?? 'Access hub');
        $gate = str_contains(strtolower($type), 'gate')
            || (is_array($door) && !empty($door['gate']));
        $online = $this->truthy($row['online'] ?? $row['isOnline'] ?? true);
        $bound = $doorId !== '';
        $parts = [];
        $parts[] = $online ? 'Online' : 'Offline';
        if (!$bound) {
            $parts[] = 'Not bound';
        } elseif (is_array($door) && ($door['status'] ?? '') !== '') {
            $parts[] = (string) $door['status'];
        }

        return [
            'id' => self::homeId(self::KIND_HUB, $id),
            'native_id' => $id,
            'door_id' => $doorId,
            'name' => $this->displayName($row, 'Door hub'),
            'kind' => self::KIND_HUB,
            'source' => self::SOURCE,
            'product' => (string) ($row['full_name'] ?? $type),
            'available' => $online && $bound,
            'on' => is_array($door) ? !empty($door['on']) : false,
            'dimmable' => false,
            'colorable' => false,
            'status' => implode(' · ', $parts),
            'gate' => $gate,
            'locked' => is_array($door) ? !empty($door['locked']) : true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapDoorPositionSensor(array $row): ?array
    {
        if (!$this->doorHasPositionSensor($row)) {
            return null;
        }
        $doorId = $this->nativeId($row);
        if ($doorId === '') {
            return null;
        }
        $dps = strtolower(trim((string) ($row['door_position_status'] ?? $row['doorPositionStatus'] ?? '')));
        $open = $dps === 'open';
        $closed = $dps === 'close' || $dps === 'closed';
        $status = $open ? 'Open' : ($closed ? 'Closed' : 'Unknown');
        $doorName = $this->displayName($row, 'Door');

        return [
            'id' => self::homeId(self::KIND_SENSOR, 'dps-' . $doorId),
            'native_id' => 'dps-' . $doorId,
            'door_id' => $doorId,
            'name' => $doorName . ' position',
            'kind' => self::KIND_SENSOR,
            'source' => self::SOURCE,
            'product' => 'Door position sensor',
            'available' => true,
            'on' => $open,
            'dimmable' => false,
            'colorable' => false,
            'status' => $status,
            'open' => $open ? true : ($closed ? false : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function doorHasPositionSensor(array $row): bool
    {
        if (!array_key_exists('door_position_status', $row) && !array_key_exists('doorPositionStatus', $row)) {
            return false;
        }
        $raw = $row['door_position_status'] ?? $row['doorPositionStatus'];
        if ($raw === null) {
            return false;
        }
        $value = strtolower(trim((string) $raw));

        return $value !== '' && $value !== 'null' && $value !== 'none';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isAccessHub(array $row): bool
    {
        $type = strtolower((string) ($row['type'] ?? $row['device_type'] ?? ''));
        $name = strtolower((string) ($row['name'] ?? ''));
        if ($type === '' && $name === '') {
            return false;
        }
        if (preg_match('/^uah($|-|_)/', $type) === 1 || $type === 'uah') {
            return true;
        }
        if (str_contains($type, 'hub')) {
            return true;
        }

        return str_contains($name, 'ua-hub') || str_contains($name, 'uah-');
    }

    private function hubDoorId(string $hubId): string
    {
        foreach ($this->readInventory()['hubs'] ?? [] as $row) {
            if (is_array($row) && (string) ($row['native_id'] ?? '') === $hubId) {
                return trim((string) ($row['door_id'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nativeId(array $row): string
    {
        foreach (['id', 'door_id', 'device_id', 'uuid', 'mac'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function displayName(array $row, string $fallback): string
    {
        foreach (['name', 'full_name', 'alias', 'display_name'] as $key) {
            $name = YarboHub::normalizeDisplayName((string) ($row[$key] ?? ''), 48);
            if ($name !== '') {
                return $name;
            }
        }

        return $fallback;
    }

    private function truthy(mixed $value): bool
    {
        return YarboHub::asBool($value, false);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return YarboHub::asBool($value, false);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $path
     */
    private function nestedNumber(array $row, array $path): ?float
    {
        $cur = $row;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }
        if (is_array($cur) && array_key_exists('value', $cur)) {
            $cur = $cur['value'];
        }
        if (!is_numeric($cur)) {
            return null;
        }

        return (float) $cur;
    }

    private function lightIsOn(string $nativeId): bool
    {
        foreach ($this->readInventory()['lights'] as $row) {
            if (is_array($row) && (string) ($row['native_id'] ?? '') === $nativeId) {
                return (bool) ($row['on'] ?? false);
            }
        }

        return false;
    }

    private function relayIsOn(string $nativeId): bool
    {
        foreach ($this->readInventory()['relays'] as $row) {
            if (is_array($row) && (string) ($row['native_id'] ?? '') === $nativeId) {
                return (bool) ($row['on'] ?? false);
            }
        }

        return false;
    }

    /**
     * @return array{
     *   cameras: list<array<string, mixed>>,
     *   lights: list<array<string, mixed>>,
     *   sensors: list<array<string, mixed>>,
     *   relays: list<array<string, mixed>>,
     *   doors: list<array<string, mixed>>,
     *   hubs: list<array<string, mixed>>,
     *   errors: list<string>
     * }
     */
    private function readInventory(): array
    {
        $empty = [
            'cameras' => [],
            'lights' => [],
            'sensors' => [],
            'relays' => [],
            'doors' => [],
            'hubs' => [],
            'errors' => [],
        ];
        if (!is_file($this->inventoryPath())) {
            return $empty;
        }
        $decoded = json_decode((string) file_get_contents($this->inventoryPath()), true);
        if (!is_array($decoded)) {
            return $empty;
        }
        foreach ($empty as $key => $_) {
            if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                $decoded[$key] = [];
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $inventory
     */
    private function writeInventory(array $inventory): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->inventoryPath(), $json . "\n", LOCK_EX);
        }
    }

    /**
     * @param array<string, mixed> $inventory
     */
    private function inventoryCount(array $inventory): int
    {
        $n = 0;
        foreach (['cameras', 'lights', 'sensors', 'relays', 'doors', 'hubs'] as $key) {
            $n += is_array($inventory[$key] ?? null) ? count($inventory[$key]) : 0;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ok: bool, items: list<mixed>, error?: string}
     */
    private function protectJson(array $config, string $path, float $timeout): array
    {
        $url = $this->protectUrl($config, $path);
        $res = $this->request('GET', $url, $this->protectHeaders($config), null, $timeout, false);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'items' => [], 'error' => $res['error'] ?? ('Protect HTTP ' . $res['status'])];
        }

        return ['ok' => true, 'items' => $this->listFromJson($res['body'])];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ok: bool, items: list<mixed>, error?: string}
     */
    private function accessJson(array $config, string $path, float $timeout): array
    {
        $url = $this->accessUrl($config, $path);
        $res = $this->request('GET', $url, $this->accessHeaders($config), null, $timeout, false);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'items' => [], 'error' => $res['error'] ?? ('Access HTTP ' . $res['status'])];
        }
        $decoded = $this->decodeJson($res['body']);
        if (is_array($decoded) && isset($decoded['code']) && strtoupper((string) $decoded['code']) !== 'SUCCESS') {
            return [
                'ok' => false,
                'items' => [],
                'error' => (string) ($decoded['msg'] ?? $decoded['message'] ?? 'Access error'),
            ];
        }

        return ['ok' => true, 'items' => $this->listFromJson($res['body'])];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function protectUrl(array $config, string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return 'https://' . $config['host'] . '/proxy/protect/integration/v1' . $path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function accessUrl(array $config, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $host = (string) $config['host'];
        $port = (int) ($config['access_port'] ?? 0);
        if ($port === 12445) {
            $hostname = explode(':', $host, 2)[0];

            return 'https://' . $hostname . ':12445/api/v1/developer' . $path;
        }

        return 'https://' . $host . '/proxy/access/api/v1/developer' . $path;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function protectHeaders(array $config): array
    {
        return [
            'Accept' => 'application/json',
            'X-API-KEY' => (string) $config['protect_api_key'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function accessHeaders(array $config): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $config['access_token'],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function listFromJson(string $body): array
    {
        $decoded = $this->decodeJson($body);
        if (!is_array($decoded)) {
            return [];
        }
        if (array_is_list($decoded)) {
            return $decoded;
        }
        foreach (['data', 'items', 'list', 'result'] as $key) {
            if (!isset($decoded[$key])) {
                continue;
            }
            $inner = $decoded[$key];
            if (is_array($inner) && array_is_list($inner)) {
                return $inner;
            }
            if (is_array($inner)) {
                foreach (['items', 'list', 'data', 'rows'] as $innerKey) {
                    if (isset($inner[$innerKey]) && is_array($inner[$innerKey]) && array_is_list($inner[$innerKey])) {
                        return $inner[$innerKey];
                    }
                }
                if (isset($inner['id'])) {
                    return [$inner];
                }
            }
        }

        return isset($decoded['id']) ? [$decoded] : [];
    }

    /**
     * @return mixed
     */
    private function decodeJson(string $body): mixed
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, content_type: string, error?: string}
     */
    private function request(string $method, string $url, array $headers, ?string $body, float $timeout, bool $binary): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $timeout, $binary);
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'PHP curl is not installed'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'Could not start HTTP request'];
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($body !== null && $body !== '') {
            $headerLines[] = 'Content-Type: application/json';
        }
        $verify = $this->load()['verify_tls'];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) floor($timeout)),
            CURLOPT_TIMEOUT => max(2, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => $status, 'body' => '', 'content_type' => $ctype, 'error' => $err !== '' ? $err : 'UniFi request failed'];
        }
        if ($status >= 400) {
            $hint = $binary ? ('HTTP ' . $status) : $this->httpErrorHint((string) $raw, $status);

            return ['status' => $status, 'body' => (string) $raw, 'content_type' => $ctype, 'error' => $hint];
        }

        return ['status' => $status, 'body' => (string) $raw, 'content_type' => $ctype];
    }

    private function httpErrorHint(string $raw, int $status): string
    {
        $decoded = $this->decodeJson($raw);
        if (is_array($decoded)) {
            $name = (string) ($decoded['name'] ?? '');
            $msg = (string) ($decoded['error'] ?? $decoded['message'] ?? $decoded['msg'] ?? '');
            if ($name === 'AJV_PARSE_ERROR' || str_contains($msg, 'AJV_PARSE_ERROR') || str_contains($msg, 'additional properties')) {
                return 'Protect rejected that command';
            }
            $msg = trim(preg_replace('/\s+/', ' ', $msg) ?? $msg);
            if ($msg !== '') {
                return strlen($msg) > 140 ? substr($msg, 0, 137) . '…' : $msg;
            }
        }
        $plain = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($plain !== '' && $plain[0] !== '{' && $plain[0] !== '[') {
            return strlen($plain) > 140 ? substr($plain, 0, 137) . '…' : $plain;
        }

        return 'HTTP ' . $status;
    }
}
