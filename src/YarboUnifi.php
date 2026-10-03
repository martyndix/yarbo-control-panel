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
     *   access_auth: string,
     *   access_path: string,
     *   access_use_protect_key: bool,
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
            'access_auth' => '',
            'access_path' => '',
            'access_use_protect_key' => false,
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
            'access_auth' => in_array((string) ($decoded['access_auth'] ?? ''), ['bearer', 'x-api-key'], true)
                ? (string) $decoded['access_auth']
                : '',
            'access_path' => in_array((string) ($decoded['access_path'] ?? ''), ['api', 'integration'], true)
                ? (string) $decoded['access_path']
                : '',
            'access_use_protect_key' => (bool) ($decoded['access_use_protect_key'] ?? false),
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
                $current['access_auth'] = '';
                $current['access_path'] = '';
                $current['access_use_protect_key'] = false;
            }
        }
        $port = $current['access_port'];
        if (array_key_exists('unifi_access_port', $input) || array_key_exists('access_port', $input)) {
            $port = max(0, (int) ($input['unifi_access_port'] ?? $input['access_port'] ?? 0));
        } elseif (array_key_exists('unifi_access_standalone', $input)) {
            $port = YarboHub::asBool($input['unifi_access_standalone']) ? 12445 : 0;
        }
        $auth = $current['access_auth'];
        if (array_key_exists('access_auth', $input)) {
            $auth = in_array((string) $input['access_auth'], ['bearer', 'x-api-key'], true)
                ? (string) $input['access_auth']
                : '';
        }
        $accessPath = $current['access_path'];
        if (array_key_exists('access_path', $input)) {
            $accessPath = in_array((string) $input['access_path'], ['api', 'integration'], true)
                ? (string) $input['access_path']
                : '';
        }
        $useProtect = $current['access_use_protect_key'];
        if (array_key_exists('access_use_protect_key', $input)) {
            $useProtect = YarboHub::asBool($input['access_use_protect_key']);
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
            'access_auth' => $auth,
            'access_path' => $accessPath,
            'access_use_protect_key' => $useProtect,
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
        if ($ok && $counts['doors'] === 0 && $counts['hubs'] === 0) {
            $message .= ' ' . $this->accessPermissionHint($error, $config);
        }
        $lastLight = $this->readInventory()['last_light'] ?? null;
        if (is_array($lastLight) && (int) ($lastLight['status'] ?? 0) >= 400) {
            $hint = trim((string) ($lastLight['error'] !== '' ? $lastLight['error'] : ($lastLight['body'] ?? '')));
            if ($hint !== '') {
                $message .= ' Last floodlight command: ' . (strlen($hint) > 120 ? substr($hint, 0, 117) . '…' : $hint);
            }
        }

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
        $pendingCmds = $inventory['light_commands'] ?? [];
        if (is_array($pendingCmds) && $pendingCmds !== []) {
            $inventory['light_commands'] = $this->applyPendingLightCommands($inventory['lights'], $pendingCmds);
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
        $this->kickAccessDoorRefresh();
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
            $companionOf = (string) ($device['companion_of'] ?? '');
            if ($id !== '' && (isset($wanted[$id]) || ($companionOf !== '' && isset($wanted[$companionOf])))) {
                $rows[] = $device;
            }
        }

        return $rows;
    }

    /**
     * Every Protect/Access row, including devices not ticked Show on Home.
     *
     * @return list<array<string, mixed>>
     */
    public function allRows(): array
    {
        $this->kickAccessDoorRefresh();
        $rows = [];
        foreach ($this->dashboardPayload(false)['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id !== '') {
                $rows[] = $device;
            }
        }

        return $rows;
    }

    /**
     * Re-read Access doors, Protect sensors, and Protect lights so Home stays live.
     * Called from a background PHP process — never from Home GET.
     */
    public function refreshAccessDoors(): void
    {
        try {
            $this->refreshAccessDoorStatusInner(2.5, true);
            $this->refreshProtectSensors(2.5);
            $this->refreshProtectLights(2.5);
            $inventory = $this->readInventory();
            $inventory['home_live_at'] = time();
            $this->writeInventory($inventory);
        } finally {
            $lock = $this->projectRoot . '/data/unifi-dps.lock';
            if (is_file($lock)) {
                @unlink($lock);
            }
        }
    }

    public function refreshProtectSensorsNow(float $timeout = 1.5): void
    {
        $this->refreshProtectSensors($timeout);
    }

    public function refreshAccessDoorsNow(float $timeout = 1.5): void
    {
        $this->refreshAccessDoorStatusInner($timeout, true);
    }

    public function refreshProtectLightsNow(float $timeout = 1.5): void
    {
        $this->refreshProtectLights($timeout);
    }

    /**
     * Start a background GET /doors, /sensors, and /lights so Home can follow
     * Protect and Access without occupying the single-threaded panel.
     */
    private function kickAccessDoorRefresh(): void
    {
        if ($this->transport !== null) {
            return;
        }
        $config = $this->load();
        if ($config['host'] === '' || ($config['access_token'] === '' && $config['protect_api_key'] === '')) {
            return;
        }
        $inventory = $this->readInventory();
        $at = max(
            (int) ($inventory['home_live_at'] ?? 0),
            (int) ($inventory['access_status_at'] ?? 0),
            (int) ($inventory['protect_sensors_at'] ?? 0),
            (int) ($inventory['protect_lights_at'] ?? 0)
        );
        if ($at > 0 && (time() - $at) < 4) {
            return;
        }
        $lock = $this->projectRoot . '/data/unifi-dps.lock';
        if (is_file($lock) && (time() - (int) @filemtime($lock)) < 10) {
            return;
        }
        $script = $this->projectRoot . '/scripts/unifi_dps_refresh.php';
        if (!is_file($script)) {
            return;
        }
        if (!is_dir($this->projectRoot . '/data')) {
            @mkdir($this->projectRoot . '/data', 0775, true);
        }
        @file_put_contents($lock, (string) time());
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $log = $this->projectRoot . '/data/unifi-dps.log';
        $command = sprintf(
            'cd %s && %s %s %s >> %s 2>&1 < /dev/null &',
            escapeshellarg($this->projectRoot),
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($this->projectRoot),
            escapeshellarg($log)
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $process = proc_open(
            ['bash', '-c', $command],
            $descriptorSpec,
            $pipes,
            $this->projectRoot
        );
        if (is_resource($process)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            proc_close($process);
        }
    }

    private function refreshAccessDoorStatusInner(float $timeout, bool $force): void
    {
        $config = $this->load();
        if ($config['host'] === '' || ($config['access_token'] === '' && $config['protect_api_key'] === '')) {
            return;
        }
        $inventory = $this->readInventory();
        $at = (int) ($inventory['access_status_at'] ?? 0);
        if (!$force && $at > 0 && (time() - $at) < 3) {
            return;
        }
        $doorsRes = $this->accessJson($config, '/doors', $timeout);
        if (!($doorsRes['ok'] ?? false)) {
            return;
        }
        $rows = [];
        $dpsByDoor = [];
        foreach (self::flattenAccessItems($doorsRes['items']) as $item) {
            $mapped = $this->mapDoor($item);
            if ($mapped === null) {
                continue;
            }
            $rows[] = $mapped;
            $dps = $this->mapDoorPositionSensor($item);
            if ($dps !== null) {
                $dpsByDoor[(string) ($mapped['native_id'] ?? '')] = $dps;
            }
        }
        if ($rows === []) {
            return;
        }
        $inventory['doors'] = $rows;
        $sensors = [];
        foreach ($inventory['sensors'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $native = (string) ($row['native_id'] ?? '');
            if (str_starts_with($native, 'dps-')) {
                continue;
            }
            $sensors[] = $row;
        }
        foreach ($dpsByDoor as $dps) {
            $sensors[] = $dps;
        }
        $inventory['sensors'] = $sensors;
        $byId = [];
        foreach ($rows as $door) {
            $byId[(string) ($door['native_id'] ?? '')] = $door;
        }
        foreach ($inventory['hubs'] as $i => $hub) {
            if (!is_array($hub)) {
                continue;
            }
            $doorId = (string) ($hub['door_id'] ?? '');
            if ($doorId !== '' && isset($byId[$doorId])) {
                $inventory['hubs'][$i] = $this->hubWithDoorStatus($hub, $byId[$doorId]);
            }
        }
        $inventory['access_status_at'] = time();
        $this->writeInventory($inventory);
    }

    /**
     * Re-read Protect UP Sense so Open/Closed, temperature, and humidity stay live.
     */
    private function refreshProtectSensors(float $timeout): void
    {
        $config = $this->load();
        if ($config['host'] === '' || $config['protect_api_key'] === '') {
            return;
        }
        $res = $this->protectJson($config, '/sensors', $timeout);
        if (!($res['ok'] ?? false)) {
            return;
        }
        $mapped = [];
        foreach ($res['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->mapSensor($item);
            if ($row !== null) {
                $mapped[] = $row;
            }
        }
        $inventory = $this->readInventory();
        $dps = [];
        $keptPir = [];
        foreach ($inventory['sensors'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (str_starts_with((string) ($row['native_id'] ?? ''), 'dps-')) {
                $dps[] = $row;
            } elseif ($this->isLightPirSensor($row)) {
                $keptPir[] = $row;
            }
        }
        $pir = $keptPir;
        $lights = $this->protectJson($config, '/lights', $timeout);
        if (($lights['ok'] ?? false) && is_array($lights['items'] ?? null)) {
            $freshPir = $this->mapLightPirSensors($lights['items']);
            if ($freshPir !== [] || ($inventory['lights'] ?? []) === []) {
                $pir = $freshPir;
            }
        }
        $inventory['sensors'] = array_merge($mapped, $dps, $pir);
        $inventory['protect_sensors_at'] = time();
        $this->writeInventory($inventory);
    }

    /**
     * Re-read Protect floodlights so On/Off from the UniFi app (or another client)
     * reaches Home without a blocking GET on the panel request.
     */
    private function refreshProtectLights(float $timeout): void
    {
        $config = $this->load();
        if ($config['host'] === '' || $config['protect_api_key'] === '') {
            return;
        }
        $res = $this->protectJson($config, '/lights', $timeout);
        if (!($res['ok'] ?? false)) {
            return;
        }
        $byId = [];
        foreach ($res['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $this->nativeId($item);
            if ($id === '') {
                continue;
            }
            $byId[$id] = $item;
        }
        foreach ($this->protectPrivateLightRows($config, $timeout) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $this->nativeId($item);
            if ($id === '') {
                continue;
            }
            $byId[$id] = array_merge($byId[$id] ?? [], $item);
        }
        $mapped = [];
        foreach ($byId as $item) {
            $row = $this->mapLight($item);
            if ($row !== null) {
                $mapped[] = $row;
            }
        }
        if ($mapped === []) {
            return;
        }
        $inventory = $this->readInventory();
        $inventory['lights'] = $mapped;
        $keep = [];
        foreach ($inventory['sensors'] ?? [] as $row) {
            if (is_array($row) && !$this->isLightPirSensor($row)) {
                $keep[] = $row;
            }
        }
        $inventory['sensors'] = array_merge($keep, $this->mapLightPirSensors(array_values($byId)));
        $inventory['protect_lights_at'] = time();
        $this->writeInventory($inventory);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function protectPrivateLightRows(array $config, float $timeout): array
    {
        if ($config['protect_username'] === '' || $config['protect_password'] === '') {
            return [];
        }
        $session = $this->protectOsLogin($config);
        if (!($session['ok'] ?? false)) {
            return [];
        }
        $url = $this->protectPrivateUrl($config, '/lights');
        $res = $this->request('GET', $url, $this->protectSessionHeaders($config, $session), null, $timeout, false);
        if ((int) ($res['status'] ?? 0) === 401) {
            $this->clearOsSession();
            $session = $this->protectOsLogin($config, true);
            if (!($session['ok'] ?? false)) {
                return [];
            }
            $res = $this->request('GET', $url, $this->protectSessionHeaders($config, $session), null, $timeout, false);
        }
        $ctype = strtolower((string) ($res['content_type'] ?? ''));
        $body = trim((string) ($res['body'] ?? ''));
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || str_contains($ctype, 'html') || str_starts_with($body, '<')) {
            return [];
        }
        $rows = [];
        foreach ($this->listFromJson($body) as $item) {
            if (is_array($item)) {
                $rows[] = $item;
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
            if (in_array($action, ['open', 'close', 'stop'], true)) {
                return $this->unlockDoor($doorId, $action);
            }
            if ($action === 'unlock' || $action === 'lock' || $action === 'on' || $action === 'off' || $action === '') {
                $gate = in_array($cmd, ['open', 'close', 'stop'], true) ? $cmd : null;

                return $this->unlockDoor($doorId, $gate);
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
        $private = $this->protectPrivateUrl($config, '/lights/' . rawurlencode($id));
        $public = $this->protectUrl($config, '/lights/' . rawurlencode($id));
        $headers = $this->protectHeaders($config);
        $last = ['ok' => false, 'error' => 'Protect light failed'];
        $accepted = false;
        $hasCreds = $config['protect_username'] !== '' && $config['protect_password'] !== '';

        $publicPayloads = $on
            ? [
                ['isLightForceEnabled' => true, 'lightDeviceSettings' => ['ledLevel' => 6]],
                ['isLightForceEnabled' => true],
            ]
            : [
                ['isLightForceEnabled' => false],
            ];
        foreach ($publicPayloads as $payload) {
            $res = $this->patchProtectLight($public, $headers, $payload, 4.0);
            $this->rememberLightCommand($id, $on, $public, $res);
            if (!$this->protectLightPatchOk($res, $on)) {
                $last = ['ok' => false, 'error' => $res['error'] ?? ('Protect light HTTP ' . ($res['status'] ?? 0))];
                continue;
            }
            $accepted = true;
            break;
        }

        if ($hasCreds) {
            $session = $this->protectOsLogin($config);
            if (!($session['ok'] ?? false)) {
                $last = ['ok' => false, 'error' => (string) ($session['error'] ?? 'UniFi OS login failed')];
                $this->rememberLightCommand($id, $on, 'https://' . $config['host'] . '/api/auth/login', [
                    'status' => (int) ($session['status'] ?? 0),
                    'body' => $last['error'],
                    'content_type' => 'text/plain',
                    'error' => $last['error'],
                ]);
            } else {
                $sessionHeaders = $this->protectSessionHeaders($config, $session);
                $privatePayloads = $on
                    ? [
                        ['lightOnSettings' => ['isLedForceOn' => true], 'lightDeviceSettings' => ['ledLevel' => 6]],
                        ['lightOnSettings' => ['isLedForceOn' => true]],
                    ]
                    : [
                        ['lightOnSettings' => ['isLedForceOn' => false]],
                    ];
                foreach ($privatePayloads as $payload) {
                    $res = $this->patchProtectLight($private, $sessionHeaders, $payload, 4.0);
                    if ((int) ($res['status'] ?? 0) === 401) {
                        $this->clearOsSession();
                        $session = $this->protectOsLogin($config, true);
                        if ($session['ok'] ?? false) {
                            $sessionHeaders = $this->protectSessionHeaders($config, $session);
                            $res = $this->patchProtectLight($private, $sessionHeaders, $payload, 4.0);
                        }
                    }
                    $this->rememberLightCommand($id, $on, $private, $res);
                    if (!$this->protectLightPatchOk($res, $on)) {
                        $last = ['ok' => false, 'error' => $res['error'] ?? ('Protect light HTTP ' . ($res['status'] ?? 0))];
                        continue;
                    }
                    $this->finishLightCommand($id, $on);

                    return ['ok' => true, 'on' => $on];
                }
            }
        }

        if ($accepted) {
            $this->finishLightCommand($id, $on);

            return ['ok' => true, 'on' => $on];
        }

        $apiKeyPayloads = $on
            ? [
                ['lightOnSettings' => ['isLedForceOn' => true], 'lightDeviceSettings' => ['ledLevel' => 6]],
                ['lightOnSettings' => ['isLedForceOn' => true]],
            ]
            : [
                ['lightOnSettings' => ['isLedForceOn' => false]],
            ];
        foreach ($apiKeyPayloads as $payload) {
            $res = $this->patchProtectLight($private, $headers, $payload, 2.5);
            $this->rememberLightCommand($id, $on, $private, $res);
            if (!$this->protectLightPatchOk($res, $on)) {
                $last = ['ok' => false, 'error' => $res['error'] ?? ('Protect light HTTP ' . ($res['status'] ?? 0))];
                continue;
            }
            $this->finishLightCommand($id, $on);

            return ['ok' => true, 'on' => $on];
        }

        if (!$hasCreds) {
            return [
                'ok' => false,
                'error' => 'Floodlight LED did not switch. Add a local UniFi OS admin under Settings → UniFi — the API key cannot log into the private light API.',
            ];
        }

        return $last;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, content_type: string, error?: string, response_headers?: array<string, list<string>>}
     */
    private function patchProtectLight(string $url, array $headers, array $payload, float $timeout): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $res = $this->request('PATCH', $url, $headers, $body, $timeout, false);
        if ($res['status'] === 404 || $res['status'] === 405) {
            $res = $this->request('PUT', $url, $headers, $body, $timeout, false);
        }

        return $res;
    }

    /**
     * @param array{status?: int, body?: string, content_type?: string, error?: string} $res
     */
    private function protectLightPatchOk(array $res, bool $on): bool
    {
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return false;
        }
        $ctype = strtolower((string) ($res['content_type'] ?? ''));
        $body = trim((string) ($res['body'] ?? ''));
        if (str_contains($ctype, 'html') || str_starts_with($body, '<')) {
            return false;
        }
        if ($body === '') {
            return $status === 204;
        }
        $decoded = $this->decodeJson($body);
        if (!is_array($decoded)) {
            return false;
        }
        if (isset($decoded['code']) && !isset($decoded['id']) && !isset($decoded['isLightForceEnabled'])) {
            return false;
        }
        if (array_key_exists('isLightForceEnabled', $decoded) && $this->truthy($decoded['isLightForceEnabled']) !== $on) {
            return false;
        }
        $privateForce = is_array($decoded['lightOnSettings'] ?? null)
            ? ($decoded['lightOnSettings']['isLedForceOn'] ?? null)
            : null;
        if ($privateForce !== null && $this->truthy($privateForce) !== $on) {
            return false;
        }

        return true;
    }

    private function finishLightCommand(string $id, bool $on): void
    {
        $this->patchInventoryOn(self::KIND_LIGHT, $id, $on);
    }

    /**
     * @param array{status?: int, body?: string, content_type?: string, error?: string} $res
     */
    private function rememberLightCommand(string $id, bool $on, string $url, array $res): void
    {
        $inventory = $this->readInventory();
        $body = trim((string) ($res['body'] ?? ''));
        if (strlen($body) > 180) {
            $body = substr($body, 0, 177) . '…';
        }
        $inventory['last_light'] = [
            'id' => $id,
            'on' => $on,
            'url' => $url,
            'status' => (int) ($res['status'] ?? 0),
            'body' => $body,
            'error' => (string) ($res['error'] ?? ''),
            'at' => time(),
        ];
        $this->writeInventory($inventory);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ok: bool, cookie?: string, csrf?: string, error?: string, status?: int}
     */
    private function protectOsLogin(array $config, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->readOsSession();
            if ($cached !== null) {
                return $cached;
            }
        }
        $host = (string) $config['host'];
        $username = (string) $config['protect_username'];
        $password = (string) $config['protect_password'];
        if ($host === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Add a local UniFi OS admin under Settings → UniFi', 'status' => 0];
        }
        $url = 'https://' . $host . '/api/auth/login';
        $body = json_encode([
            'username' => $username,
            'password' => $password,
            'rememberMe' => true,
        ], JSON_THROW_ON_ERROR);
        $res = $this->request('POST', $url, ['Accept' => 'application/json'], $body, 6.0, false);
        $status = (int) ($res['status'] ?? 0);
        $ctype = strtolower((string) ($res['content_type'] ?? ''));
        $raw = trim((string) ($res['body'] ?? ''));
        if (str_contains($ctype, 'html') || str_starts_with($raw, '<')) {
            $this->clearOsSession();

            return ['ok' => false, 'error' => 'UniFi OS login returned the web login page', 'status' => $status];
        }
        if ($status < 200 || $status >= 300) {
            $this->clearOsSession();

            return ['ok' => false, 'error' => $res['error'] ?? ('UniFi OS login HTTP ' . $status), 'status' => $status];
        }
        $cookie = $this->cookieHeaderFromResponse($res);
        $csrf = $this->csrfFromResponse($res);
        foreach ($res['response_headers']['set-cookie'] ?? [] as $line) {
            $pair = strtolower(trim(explode(';', (string) $line, 2)[0]));
            if (str_starts_with($pair, 'csrf_token=') || str_starts_with($pair, 'csrf=') || str_starts_with($pair, 'x-csrf-token=')) {
                $csrf = trim(explode('=', explode(';', (string) $line, 2)[0], 2)[1] ?? $csrf);
            }
        }
        if ($cookie === '') {
            $this->clearOsSession();

            return ['ok' => false, 'error' => 'UniFi OS login did not return a session cookie', 'status' => $status];
        }
        $session = ['ok' => true, 'cookie' => $cookie, 'csrf' => $csrf];
        $this->writeOsSession($session);

        return $session;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{ok: bool, cookie?: string, csrf?: string} $session
     * @return array<string, string>
     */
    private function protectSessionHeaders(array $config, array $session): array
    {
        $host = (string) ($config['host'] ?? '');
        $headers = [
            'Accept' => 'application/json',
            'Cookie' => (string) ($session['cookie'] ?? ''),
        ];
        if ($host !== '') {
            $headers['Origin'] = 'https://' . $host;
            $headers['Referer'] = 'https://' . $host . '/protect';
        }
        $csrf = trim((string) ($session['csrf'] ?? ''));
        if ($csrf !== '') {
            $headers['X-CSRF-Token'] = $csrf;
            $headers['X-CSRF-TOKEN'] = $csrf;
        }

        return $headers;
    }

    /**
     * @return array{ok: bool, cookie: string, csrf: string}|null
     */
    private function readOsSession(): ?array
    {
        $path = $this->osSessionPath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $at = (int) ($decoded['at'] ?? 0);
        $cookie = trim((string) ($decoded['cookie'] ?? ''));
        if ($cookie === '' || $at <= 0 || (time() - $at) > 480) {
            return null;
        }

        return [
            'ok' => true,
            'cookie' => $cookie,
            'csrf' => trim((string) ($decoded['csrf'] ?? '')),
        ];
    }

    /**
     * @param array{cookie?: string, csrf?: string} $session
     */
    private function writeOsSession(array $session): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode([
            'cookie' => (string) ($session['cookie'] ?? ''),
            'csrf' => (string) ($session['csrf'] ?? ''),
            'at' => time(),
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->osSessionPath(), $json . "\n", LOCK_EX);
        @chmod($this->osSessionPath(), 0600);
    }

    private function clearOsSession(): void
    {
        $path = $this->osSessionPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function osSessionPath(): string
    {
        return $this->projectRoot . '/data/unifi-os-session.json';
    }

    /**
     * @param array{response_headers?: array<string, list<string>>, body?: string} $res
     */
    private function cookieHeaderFromResponse(array $res): string
    {
        $parts = [];
        foreach ($res['response_headers']['set-cookie'] ?? [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $pair = trim(explode(';', $line, 2)[0]);
            if ($pair !== '' && str_contains($pair, '=')) {
                $parts[] = $pair;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @param array{response_headers?: array<string, list<string>>, body?: string} $res
     */
    private function csrfFromResponse(array $res): string
    {
        foreach (['x-csrf-token'] as $key) {
            $values = $res['response_headers'][$key] ?? [];
            if (is_array($values) && isset($values[0]) && trim((string) $values[0]) !== '') {
                return trim((string) $values[0]);
            }
        }
        $decoded = $this->decodeJson((string) ($res['body'] ?? ''));
        if (is_array($decoded)) {
            foreach (['csrfToken', 'csrf_token', 'x_csrf_token'] as $key) {
                $value = trim((string) ($decoded[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
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
        $this->patchInventoryOn(self::KIND_RELAY, $id, $on);

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
        $headers = [];
        $last = ['ok' => false, 'error' => 'Access unlock failed'];
        foreach ($this->accessAttempts($config, $path) as $attempt) {
            $url = (string) $attempt['url'];
            $headers = $attempt['headers'];
            $res = $this->request('PUT', $url, $headers, '{}', 8.0, false);
            if ($res['status'] === 404 || $res['status'] === 405) {
                $res = $this->request('POST', $url, $headers, '{}', 8.0, false);
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                $last = ['ok' => false, 'error' => $res['error'] ?? ('Access unlock HTTP ' . $res['status'])];
                continue;
            }
            $decoded = $this->decodeJson($res['body']);
            if (is_array($decoded) && isset($decoded['code']) && strtoupper((string) $decoded['code']) !== 'SUCCESS') {
                $last = ['ok' => false, 'error' => (string) ($decoded['msg'] ?? $decoded['message'] ?? 'Access unlock failed')];
                continue;
            }
            $this->rememberAccessAttempt($config, $attempt);

            return ['ok' => true, 'on' => false, 'unlocked' => true];
        }

        return $last;
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
        $pirSensors = [];
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
                    if ($key === 'lights') {
                        $pir = $this->mapLightPirSensor($item);
                        if ($pir !== null) {
                            $pirSensors[] = $pir;
                        }
                    }
                }
                $out[$key] = $rows;
            }
        }
        $out['sensors'] = array_merge($out['sensors'], $pirSensors);
        if ($config['access_token'] !== '' || $config['protect_api_key'] !== '') {
            $this->appendAccessInventory($out, $config, $timeout);
        }
        $out['errors'] = array_values(array_unique(array_filter($out['errors'])));
        $pending = $this->readInventory();
        $out['light_commands'] = $this->applyPendingLightCommands($out['lights'], is_array($pending['light_commands'] ?? null) ? $pending['light_commands'] : []);
        foreach (['last_light', 'access_status_at', 'protect_sensors_at', 'protect_lights_at', 'home_live_at'] as $key) {
            if (isset($pending[$key])) {
                $out[$key] = $pending[$key];
            }
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
        // isLightOn is the LED. mode=always is only the Protect schedule (often "when dark").
        $on = $force || $this->truthy($row['isLightOn'] ?? $row['is_light_on'] ?? false);

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
     * Floodlight PIR as its own sensor, so Automations can use motion without
     * mixing it up with the light's On/Off Then.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapLightPirSensor(array $row): ?array
    {
        $id = $this->nativeId($row);
        if ($id === '') {
            return null;
        }
        $motion = $this->sensorMotionNow($row);
        $motionAt = $this->sensorMotionAt($row);
        $name = $this->displayName($row, 'Light') . ' motion';

        return [
            'id' => self::homeId(self::KIND_SENSOR, 'pir-' . $id),
            'native_id' => 'pir-' . $id,
            'name' => $name,
            'kind' => self::KIND_SENSOR,
            'source' => self::SOURCE,
            'product' => 'Floodlight motion',
            'companion_of' => self::homeId(self::KIND_LIGHT, $id),
            'available' => true,
            'on' => $motion,
            'dimmable' => false,
            'colorable' => false,
            'status' => $motion ? 'Motion' : 'OK',
            'open' => null,
            'motion' => $motion,
            'has_open' => false,
            'has_motion' => true,
            'motion_at' => $motionAt,
            'temperature' => null,
            'humidity' => null,
        ];
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function mapLightPirSensors(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->mapLightPirSensor($item);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isLightPirSensor(array $row): bool
    {
        return str_starts_with((string) ($row['native_id'] ?? ''), 'pir-')
            || str_starts_with((string) ($row['id'] ?? ''), 'unifi:sensor:pir-');
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
        $open = $this->sensorOpened($row);
        $motion = $this->sensorMotionNow($row);
        $motionAt = $this->sensorMotionAt($row);
        $leak = $this->truthy($row['leakDetected'] ?? $row['is_leaking'] ?? false);
        $temp = $this->nestedNumber($row, ['stats', 'temperature', 'value'])
            ?? $this->nestedNumber($row, ['stats', 'temperature'])
            ?? $this->nestedNumber($row, ['temperature', 'value'])
            ?? $this->nestedNumber($row, ['temperature']);
        $humidity = $this->nestedNumber($row, ['stats', 'humidity', 'value'])
            ?? $this->nestedNumber($row, ['stats', 'humidity'])
            ?? $this->nestedNumber($row, ['humidity', 'value'])
            ?? $this->nestedNumber($row, ['relativeHumidity'])
            ?? $this->nestedNumber($row, ['humidity']);
        $name = $this->displayName($row, 'Sensor');
        $product = (string) ($row['type'] ?? $row['model'] ?? 'Protect sensor');
        $caps = $this->sensorCapabilityFlags($row, $name, $product, $open);
        $parts = [];
        if ($caps['has_open'] && $open === true) {
            $parts[] = 'Open';
        } elseif ($caps['has_open'] && $open === false) {
            $parts[] = 'Closed';
        }
        if ($caps['has_motion'] && $motion) {
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
            'name' => $name,
            'kind' => self::KIND_SENSOR,
            'source' => self::SOURCE,
            'product' => $product,
            'available' => true,
            'on' => $open === true || $motion || $leak,
            'dimmable' => false,
            'colorable' => false,
            'status' => $status,
            'open' => $caps['has_open'] ? $open : null,
            'motion' => $caps['has_motion'] ? $motion : null,
            'has_open' => $caps['has_open'],
            'has_motion' => $caps['has_motion'],
            'motion_at' => $caps['has_motion'] ? $motionAt : 0,
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
        $dps = $this->doorPositionRaw($row) ?? '';
        $dpsLabel = $this->dpsLabel($dps);
        $gate = $this->truthy($row['is_bind_gate'] ?? $row['gate'] ?? false)
            || str_contains(strtolower((string) ($row['type'] ?? $row['device_type'] ?? '')), 'gate');
        $parts = [];
        $parts[] = $locked ? 'Locked' : 'Unlocked';
        if ($dpsLabel !== '') {
            $parts[] = $dpsLabel;
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
            'dps_label' => $dpsLabel,
            'open' => $dpsLabel === 'Open' ? true : ($dpsLabel === 'Closed' ? false : null),
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

        $devRes = $this->accessJson($config, '/devices?refresh=true', $timeout);
        if (!($devRes['ok'] ?? false)) {
            $out['errors'][] = (string) ($devRes['error'] ?? 'Access devices failed');

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
        $gate = str_contains(strtolower($type . ' ' . (string) ($row['full_name'] ?? '')), 'gate')
            || (is_array($door) && !empty($door['gate']));
        $online = $this->truthy($row['online'] ?? $row['isOnline'] ?? true);
        $bound = $doorId !== '';
        $hub = [
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
            'status' => $online ? 'Online' : 'Offline',
            'gate' => $gate,
            'locked' => is_array($door) ? !empty($door['locked']) : true,
        ];
        if (is_array($door)) {
            $hub = $this->hubWithDoorStatus($hub, $door);
        }
        if (!$online) {
            $rest = preg_replace('/^Online/', 'Offline', (string) $hub['status'], 1);
            $hub['status'] = is_string($rest) && $rest !== '' ? $rest : 'Offline';
        } elseif (!$bound) {
            $hub['status'] = 'Online · Not bound';
        }

        return $hub;
    }

    /**
     * Copy lock + door-position onto a hub so the controller tile can show Open/Closed.
     *
     * @param array<string, mixed> $hub
     * @param array<string, mixed> $door
     * @return array<string, mixed>
     */
    private function hubWithDoorStatus(array $hub, array $door): array
    {
        $hub['on'] = !empty($door['on']);
        $hub['locked'] = !empty($door['locked']);
        $dpsLabel = trim((string) ($door['dps_label'] ?? $this->dpsLabel((string) ($door['dps'] ?? ''))));
        if (!empty($door['has_dps']) || $dpsLabel !== '') {
            $hub['has_dps'] = true;
            $hub['dps'] = (string) ($door['dps'] ?? '');
            $hub['dps_label'] = $dpsLabel;
            $hub['open'] = array_key_exists('open', $door) ? $door['open'] : ($dpsLabel === 'Open');
        }
        $parts = [str_starts_with((string) ($hub['status'] ?? ''), 'Offline') ? 'Offline' : 'Online'];
        if (trim((string) ($hub['door_id'] ?? '')) === '') {
            $parts[] = 'Not bound';
        } else {
            $parts[] = !empty($hub['locked']) ? 'Locked' : 'Unlocked';
            if ($dpsLabel !== '') {
                $parts[] = $dpsLabel;
            }
        }
        $hub['status'] = implode(' · ', $parts);

        return $hub;
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
        $dps = $this->doorPositionRaw($row) ?? '';
        $dpsLabel = $this->dpsLabel($dps);
        $open = $dpsLabel === 'Open';
        $closed = $dpsLabel === 'Closed';
        $status = $dpsLabel !== '' ? $dpsLabel : 'Unknown';
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
            'dps' => $dps,
            'dps_label' => $dpsLabel !== '' ? $dpsLabel : $status,
            'has_dps' => true,
            'open' => $open ? true : ($closed ? false : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function doorHasPositionSensor(array $row): bool
    {
        return $this->doorPositionRaw($row) !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function doorPositionRaw(array $row): ?string
    {
        foreach (['door_position_status', 'doorPositionStatus', 'dps_status', 'dpsStatus', 'position_status', 'is_opened', 'isOpened'] as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $normalized = $this->normalizeDoorPosition($row[$key]);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        foreach (['extras', 'extra', 'status', 'state'] as $nest) {
            if (!isset($row[$nest]) || !is_array($row[$nest]) || array_is_list($row[$nest])) {
                continue;
            }
            foreach (['door_position_status', 'doorPositionStatus', 'dps_status', 'dpsStatus', 'is_opened', 'isOpened'] as $key) {
                if (!array_key_exists($key, $row[$nest]) || $row[$nest][$key] === null) {
                    continue;
                }
                $normalized = $this->normalizeDoorPosition($row[$nest][$key]);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function normalizeDoorPosition(mixed $raw): ?string
    {
        if (is_bool($raw)) {
            return $raw ? 'open' : 'close';
        }
        if (is_int($raw) || is_float($raw)) {
            if ((int) $raw === 1) {
                return 'open';
            }
            if ((int) $raw === 0) {
                return 'close';
            }
        }
        $value = strtolower(trim((string) $raw));
        if ($value === '' || $value === 'null' || $value === 'none' || $value === 'n/a' || $value === 'unknown') {
            return null;
        }

        return $value;
    }

    private function dpsLabel(string $dps): string
    {
        $dps = strtolower(trim($dps));
        if (in_array($dps, ['open', 'opened', 'opening', '1', 'true', 'on', 'yes'], true)) {
            return 'Open';
        }
        if (in_array($dps, ['close', 'closed', 'closing', '0', 'false', 'off', 'no'], true)) {
            return 'Closed';
        }

        return '';
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
        if (preg_match('/uah|ua-hub|ua_hub|ua hub|gate.?hub|ua-ultra|ua_ultra|uah-door/', $type . ' ' . $name) === 1) {
            return true;
        }
        if (str_contains($type, 'hub') || str_contains($name, 'hub')) {
            return !str_contains($type, 'reader') && !str_contains($name, 'reader');
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
     */
    private function sensorOpened(array $row): ?bool
    {
        $candidates = [
            $row['isOpened'] ?? null,
            $row['is_opened'] ?? null,
            $row['open'] ?? null,
            $row['opened'] ?? null,
            $row['isOpen'] ?? null,
            $row['openStatus'] ?? null,
            $row['open_status'] ?? null,
        ];
        $stats = $row['stats'] ?? null;
        if (is_array($stats)) {
            $candidates[] = $stats['isOpened'] ?? null;
            $candidates[] = $stats['is_opened'] ?? null;
            $candidates[] = $stats['open'] ?? null;
            if (is_array($stats['open'] ?? null)) {
                $candidates[] = $stats['open']['value'] ?? null;
            }
        }
        foreach ($candidates as $value) {
            $parsed = $this->openClosedValue($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Protect often sends isOpened: false on motion-only sensors. Name wins so
     * Automations do not offer open/closed for a bathroom motion detector.
     *
     * @param array<string, mixed> $row
     * @return array{has_open: bool, has_motion: bool}
     */
    private function sensorCapabilityFlags(array $row, string $name, string $product, ?bool $open): array
    {
        $blob = trim($product . ' ' . $name);
        $motionName = preg_match('/motion|occupancy|presence|pir/i', $blob) === 1;
        $contactName = preg_match('/contact|door|window|magnet|leak/i', $blob) === 1;
        $hasMotion = $this->sensorReportsMotion($row) || $motionName;
        $hasOpen = $open !== null;
        if ($motionName && !$contactName) {
            $hasOpen = false;
        }

        return ['has_open' => $hasOpen, 'has_motion' => $hasMotion];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sensorMotionNow(array $row): bool
    {
        foreach (['isMotionDetected', 'is_motion_detected', 'isPirMotionDetected', 'is_pir_motion_detected', 'motionDetected', 'motion'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $this->truthy($row[$key]);
            }
        }
        $stats = $row['stats'] ?? null;
        if (!is_array($stats)) {
            return false;
        }
        foreach (['isMotionDetected', 'is_motion_detected', 'isPirMotionDetected', 'is_pir_motion_detected', 'motionDetected', 'motion'] as $key) {
            if (array_key_exists($key, $stats) && $stats[$key] !== null && $stats[$key] !== '') {
                $value = $stats[$key];
                if (is_array($value) && array_key_exists('value', $value)) {
                    $value = $value['value'];
                }

                return $this->truthy($value);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sensorMotionAt(array $row): int
    {
        $candidates = [
            $row['motionDetectedAt'] ?? null,
            $row['motion_detected_at'] ?? null,
            $row['lastMotion'] ?? null,
            $row['last_motion'] ?? null,
            $row['pirMotionDetectedAt'] ?? null,
            $row['lastPirMotion'] ?? null,
            $row['last_pir_motion'] ?? null,
        ];
        $stats = $row['stats'] ?? null;
        if (is_array($stats)) {
            $candidates[] = $stats['motionDetectedAt'] ?? null;
            $candidates[] = $stats['motion_detected_at'] ?? null;
        }
        foreach ($candidates as $value) {
            $sec = self::epochSeconds($value);
            if ($sec > 0) {
                return $sec;
            }
        }

        return 0;
    }

    private static function epochSeconds(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $n = (int) $value;
        if ($n > 1_000_000_000_000) {
            return intdiv($n, 1000);
        }

        return $n > 1_000_000_000 ? $n : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sensorReportsMotion(array $row): bool
    {
        foreach (['isMotionDetected', 'is_motion_detected', 'isPirMotionDetected', 'is_pir_motion_detected', 'motionDetected', 'motion'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return true;
            }
        }
        $stats = $row['stats'] ?? null;
        if (!is_array($stats)) {
            return $this->sensorMotionAt($row) > 0;
        }
        foreach (['isMotionDetected', 'is_motion_detected', 'isPirMotionDetected', 'is_pir_motion_detected', 'motionDetected', 'motion'] as $key) {
            if (array_key_exists($key, $stats) && $stats[$key] !== null && $stats[$key] !== '') {
                return true;
            }
        }

        return $this->sensorMotionAt($row) > 0;
    }

    private function openClosedValue(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            $s = strtolower(trim($value));
            if (in_array($s, ['1', 'true', 'yes', 'on', 'open', 'opened'], true)) {
                return true;
            }
            if (in_array($s, ['0', 'false', 'no', 'off', 'close', 'closed'], true)) {
                return false;
            }
        }

        return $this->nullableBool($value);
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

    private function patchInventoryOn(string $kind, string $nativeId, bool $on): void
    {
        $group = $kind === self::KIND_RELAY ? 'relays' : 'lights';
        $inventory = $this->readInventory();
        $homeId = self::homeId($kind, $nativeId);
        $found = false;
        foreach ($inventory[$group] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $nid = (string) ($row['native_id'] ?? '');
            if ($id !== $homeId && $nid !== $nativeId && $id !== $nativeId) {
                continue;
            }
            $inventory[$group][$i]['on'] = $on;
            $inventory[$group][$i]['status'] = $on ? 'On' : 'Off';
            $found = true;
        }
        if ($kind === self::KIND_LIGHT) {
            $commands = $inventory['light_commands'] ?? [];
            if (!is_array($commands)) {
                $commands = [];
            }
            $commands[$nativeId] = ['on' => $on, 'at' => time()];
            $inventory['light_commands'] = $commands;
            $found = true;
        }
        if ($found) {
            $this->writeInventory($inventory);
        }
    }

    /**
     * Keep a website On/Off for a few seconds while Protect GET still reports the old LED.
     *
     * @param list<array<string, mixed>> $lights
     * @param array<string, mixed> $commands
     * @return array<string, array{on: bool, at: int}>
     */
    private function applyPendingLightCommands(array &$lights, array $commands): array
    {
        $now = time();
        $keep = [];
        foreach ($commands as $nid => $cmd) {
            $nid = (string) $nid;
            if ($nid === '' || !is_array($cmd)) {
                continue;
            }
            $at = (int) ($cmd['at'] ?? 0);
            if ($at <= 0 || ($now - $at) > 12) {
                continue;
            }
            $keep[$nid] = ['on' => (bool) ($cmd['on'] ?? false), 'at' => $at];
        }
        foreach ($lights as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $nid = (string) ($row['native_id'] ?? '');
            if ($nid === '' || !isset($keep[$nid])) {
                continue;
            }
            $want = $keep[$nid]['on'];
            if ((bool) ($row['on'] ?? false) === $want) {
                unset($keep[$nid]);
                continue;
            }
            $lights[$i]['on'] = $want;
            $lights[$i]['status'] = $want ? 'On' : 'Off';
        }

        return $keep;
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
        $last = ['ok' => false, 'items' => [], 'error' => 'Access request failed'];
        $emptyOk = null;
        foreach ($this->accessAttempts($config, $path) as $attempt) {
            $url = (string) $attempt['url'];
            $res = $this->request('GET', $url, $attempt['headers'], null, $timeout, false);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                $last = ['ok' => false, 'items' => [], 'error' => $res['error'] ?? ('Access HTTP ' . $res['status'])];
                continue;
            }
            $decoded = $this->decodeJson($res['body']);
            if (!is_array($decoded)) {
                $last = ['ok' => false, 'items' => [], 'error' => $res['error'] ?? ('Access HTTP ' . $res['status'])];
                continue;
            }
            $code = strtoupper((string) ($decoded['code'] ?? $decoded['codeS'] ?? ''));
            $errText = trim((string) ($decoded['error'] ?? $decoded['msg'] ?? $decoded['message'] ?? ''));
            if (($code !== '' && $code !== 'SUCCESS') || $this->isAccessWrongKeyError($errText)) {
                $last = [
                    'ok' => false,
                    'items' => [],
                    'error' => $errText !== '' ? $errText : 'Access error',
                ];
                continue;
            }
            $items = $this->listFromJson($res['body']);
            $this->rememberAccessAttempt($config, $attempt);
            if ($items === []) {
                $emptyOk = ['ok' => true, 'items' => []];
                continue;
            }

            return ['ok' => true, 'items' => $items];
        }

        return $emptyOk ?? $last;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{url: string, headers: array<string, string>, auth: string, path: string, port: int, use_protect: bool}>
     */
    private function accessAttempts(array $config, string $path): array
    {
        $path = '/' . ltrim($path, '/');
        $saved = $this->load();
        $host = (string) ($config['host'] ?? $saved['host']);
        $hostname = explode(':', $host, 2)[0];
        $access = trim((string) ($config['access_token'] ?? $saved['access_token']));
        $protect = trim((string) ($config['protect_api_key'] ?? $saved['protect_api_key']));
        $bases = [
            [
                'url' => 'https://' . $hostname . ':12445/api/v1/developer' . $path,
                'path' => 'api',
                'port' => 12445,
            ],
            [
                'url' => 'https://' . $host . '/proxy/access/integration/v1/developer' . $path,
                'path' => 'integration',
                'port' => 0,
            ],
            [
                'url' => 'https://' . $host . '/proxy/access/api/v1/developer' . $path,
                'path' => 'api',
                'port' => 0,
            ],
            [
                'url' => 'https://' . $host . '/proxy/access/integration/v1' . $path,
                'path' => 'integration',
                'port' => 0,
            ],
        ];
        $preferredPort = (int) ($saved['access_port'] ?: ($config['access_port'] ?? 0));
        $preferredPath = (string) ($saved['access_path'] ?: ($config['access_path'] ?? ''));
        $preferredAuth = (string) ($saved['access_auth'] ?: ($config['access_auth'] ?? ''));
        $preferProtect = (bool) ($saved['access_use_protect_key'] ?? false);
        $sameKey = $this->accessTokenLooksLikeProtectKey([
            'access_token' => $access,
            'protect_api_key' => $protect,
        ]);
        if ($sameKey || $preferProtect) {
            $bases = [$bases[1], $bases[3], $bases[2], $bases[0]];
        } elseif ($preferredPath === 'integration') {
            $bases = [$bases[1], $bases[3], $bases[2], $bases[0]];
        } elseif ($preferredPath === 'api' && $preferredPort !== 12445) {
            $bases = [$bases[2], $bases[1], $bases[3], $bases[0]];
        }
        $tokens = [];
        if ($access !== '') {
            $tokens[] = ['token' => $access, 'use_protect' => false];
        }
        if ($protect !== '' && $protect !== $access) {
            $tokens[] = ['token' => $protect, 'use_protect' => true];
        }
        if ($preferProtect) {
            usort($tokens, static fn (array $a, array $b): int => (int) $b['use_protect'] <=> (int) $a['use_protect']);
        }
        $auths = ($sameKey || $preferProtect || $preferredAuth === 'x-api-key')
            ? ['x-api-key', 'bearer']
            : ['bearer', 'x-api-key'];
        $attempts = [];
        $seen = [];
        foreach ($bases as $base) {
            foreach ($tokens as $token) {
                foreach ($auths as $auth) {
                    $headers = $auth === 'x-api-key'
                        ? $this->accessKeyHeaders($token['token'])
                        : $this->accessBearerHeaders($token['token']);
                    $key = $base['url'] . '|' . $auth . '|' . ($token['use_protect'] ? 'p' : 'a');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $attempts[] = [
                        'url' => $base['url'],
                        'headers' => $headers,
                        'auth' => $auth,
                        'path' => $base['path'],
                        'port' => $base['port'],
                        'use_protect' => $token['use_protect'],
                    ];
                }
            }
        }

        return $attempts;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{url: string, headers: array<string, string>, auth?: string, path?: string, port?: int, use_protect?: bool} $attempt
     */
    private function rememberAccessAttempt(array $config, array $attempt): void
    {
        $port = (int) ($attempt['port'] ?? 0);
        $auth = (string) ($attempt['auth'] ?? '');
        $path = (string) ($attempt['path'] ?? '');
        $useProtect = (bool) ($attempt['use_protect'] ?? false);
        $changed = $port !== (int) ($config['access_port'] ?? 0)
            || $auth !== (string) ($config['access_auth'] ?? '')
            || $path !== (string) ($config['access_path'] ?? '')
            || $useProtect !== (bool) ($config['access_use_protect_key'] ?? false);
        if (!$changed) {
            return;
        }
        $this->save([
            'unifi_access_standalone' => $port === 12445,
            'access_auth' => $auth,
            'access_path' => $path,
            'access_use_protect_key' => $useProtect,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function accessUrls(array $config, string $path): array
    {
        $urls = [];
        foreach ($this->accessAttempts($config, $path) as $attempt) {
            $urls[] = $attempt['url'];
        }

        return array_values(array_unique($urls));
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
     * Unofficial Protect API used by Home Assistant / uiprotect for floodlight LEDs.
     *
     * @param array<string, mixed> $config
     */
    private function protectPrivateUrl(array $config, string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return 'https://' . $config['host'] . '/proxy/protect/api' . $path;
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
        $token = (string) $config['access_token'];
        if (($config['access_auth'] ?? '') === 'x-api-key' || ($config['access_use_protect_key'] ?? false)) {
            if (($config['access_use_protect_key'] ?? false) && (string) $config['protect_api_key'] !== '') {
                $token = (string) $config['protect_api_key'];
            }

            return $this->accessKeyHeaders($token);
        }

        return $this->accessBearerHeaders($token);
    }

    /**
     * @return array<string, string>
     */
    private function accessBearerHeaders(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function accessKeyHeaders(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-KEY' => $token,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function accessTokenLooksLikeProtectKey(array $config): bool
    {
        $access = trim((string) ($config['access_token'] ?? ''));
        $protect = trim((string) ($config['protect_api_key'] ?? ''));

        return $access !== '' && $protect !== '' && hash_equals($protect, $access);
    }

    private function isAccessWrongKeyError(string $error): bool
    {
        $lower = strtolower($error);

        return str_contains($lower, 'no-man')
            || str_contains($lower, 'no man zone')
            || str_contains($lower, 'code_not_found')
            || str_contains($lower, 'api was not found')
            || str_contains($lower, 'associated with unifi protect');
    }

    private function accessWrongKeyHint(): string
    {
        return 'Access: UniFi OS Control Plane → Integrations is the same page for Protect and Access — that key only works for Protect cameras and lights. Door controllers need a token created inside the Access app: open Access (not Control Plane) → Settings → General → Advanced → API Token, tick view:space and view:device, and paste that into Access API token. Keep the Control Plane key in Protect API key.';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function accessPermissionHint(string $error, array $config = []): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        $missing = trim((string) ($config['access_token'] ?? '')) === '';
        if ($missing || $this->accessTokenLooksLikeProtectKey($config) || $this->isAccessWrongKeyError($error)) {
            return $this->accessWrongKeyHint();
        }
        $lower = strtolower($error);
        if (str_contains($lower, 'permission') || str_contains($lower, 'unauthorized') || str_contains($lower, 'not allowed')) {
            return 'Access: that token is not allowed to list doors (CODE_UNAUTHORIZED). Create a new token in UniFi Access → Settings → General → Advanced → API Token and enable view:space (doors) and view:device (hubs). A Protect Integration key cannot list Access controllers.';
        }

        return $error === '' ? 'Access request failed.' : 'Access: ' . $error;
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
     * @return array{status: int, body: string, content_type: string, error?: string, response_headers?: array<string, list<string>>}
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
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) floor($timeout)),
            CURLOPT_TIMEOUT => max(2, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $responseHeaders[$name][] = trim($parts[1]);
                }

                return $len;
            },
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
            return ['status' => $status, 'body' => '', 'content_type' => $ctype, 'error' => $err !== '' ? $err : 'UniFi request failed', 'response_headers' => $responseHeaders];
        }
        if ($status >= 400) {
            $hint = $binary ? ('HTTP ' . $status) : $this->httpErrorHint((string) $raw, $status);

            return ['status' => $status, 'body' => (string) $raw, 'content_type' => $ctype, 'error' => $hint, 'response_headers' => $responseHeaders];
        }

        return ['status' => $status, 'body' => (string) $raw, 'content_type' => $ctype, 'response_headers' => $responseHeaders];
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
