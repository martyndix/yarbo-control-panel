<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Home When / Then rules. Times use a saved timezone, then the panel OS zone.
 * The runner (scripts/home_automations.php) calls tick(); Home GET only reads disk.
 */
final class YarboHomeAutomations
{
    private const DURATION_EVENTS = ['stays_on', 'stays_off', 'stays_open', 'stays_closed', 'no_motion'];
    private const EDGE_EVENTS = ['turns_on', 'turns_off', 'opens', 'closes', 'motion'];
    private const DEVICE_EVENTS = [
        'turns_on', 'turns_off', 'opens', 'closes', 'motion',
        'stays_on', 'stays_off', 'stays_open', 'stays_closed', 'no_motion',
    ];
    private const DEVICE_COMMANDS = [
        'on', 'off', 'toggle', 'unlock', 'lock', 'open', 'close', 'stop',
        'brightness', 'color', 'color_temp', 'setpoint',
    ];

    /** @var callable|null */
    private $commandHandler;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function storePath(): string
    {
        return $this->projectRoot . '/data/home-automations.json';
    }

    public function statePath(): string
    {
        return $this->projectRoot . '/data/home-automations-state.json';
    }

    public function setCommandHandler(?callable $handler): void
    {
        $this->commandHandler = $handler;
    }

    public function timezoneName(): string
    {
        $saved = self::usableTimezone((string) ($this->load()['timezone'] ?? ''));
        if ($saved !== '') {
            return $saved;
        }
        $os = YarboVestaboard::osTimezone();
        if ($os !== '') {
            return $os;
        }
        $php = date_default_timezone_get();

        return is_string($php) && $php !== '' ? $php : 'UTC';
    }

    /**
     * @return array{name: string, source: string, clock: string, saved: bool}
     */
    public function timezonePublic(): array
    {
        $name = $this->timezoneName();
        $saved = self::usableTimezone((string) ($this->load()['timezone'] ?? '')) !== '';

        return [
            'name' => $name,
            'source' => $saved ? 'saved' : 'os',
            'clock' => $this->clockHm(),
            'saved' => $saved,
        ];
    }

    /**
     * When the panel clock is still UTC, store the browser zone from Home GET.
     */
    public function adoptClientTimezone(?string $raw = null): bool
    {
        if (!self::isUtcZone($this->timezoneName())) {
            return false;
        }
        $raw = $raw ?? (string) ($_SERVER['HTTP_X_CLIENT_TIMEZONE'] ?? '');
        $zone = self::usableTimezone($raw);
        if ($zone === '') {
            return false;
        }
        $store = $this->load();
        $store['timezone'] = $zone;

        return $this->write($store);
    }

    public function clockHm(?int $now = null): string
    {
        $now ??= time();
        try {
            $tz = new \DateTimeZone($this->timezoneName());
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('H:i');
    }

    /**
     * @return array{at: int, age_sec: int|null, running: bool, last_error: string, last_fired: list<string>, last_fire_hm: array<string, string>, then_error: array<string, string>, clock_hm: string, timezone: string}
     */
    public function runnerPublic(): array
    {
        $state = $this->loadState();
        $at = (int) ($state['clock'] ?? 0);
        $age = $at > 0 ? max(0, time() - $at) : null;
        $result = is_array($state['last_result'] ?? null) ? $state['last_result'] : [];
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $fired = [];
        foreach (is_array($result['fired'] ?? null) ? $result['fired'] : [] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $fired[] = $id;
            }
        }

        $lastFireHm = [];
        try {
            $tz = new \DateTimeZone($this->timezoneName());
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        foreach (is_array($state['last_fire'] ?? null) ? $state['last_fire'] : [] as $id => $ts) {
            $id = trim((string) $id);
            $ts = (int) $ts;
            if ($id === '' || $ts <= 0) {
                continue;
            }
            $lastFireHm[$id] = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i');
        }

        $thenError = [];
        foreach (is_array($state['then_error'] ?? null) ? $state['then_error'] : [] as $id => $err) {
            $id = trim((string) $id);
            $err = trim((string) $err);
            if ($id !== '' && $err !== '') {
                $thenError[$id] = $err;
            }
        }
        $stickyError = $thenError !== [] ? (string) array_values($thenError)[0] : '';

        return [
            'at' => $at,
            'age_sec' => $age,
            'running' => $at > 0 && $age !== null && $age < 20,
            'last_error' => $stickyError !== '' ? $stickyError : ($errors !== [] ? (string) $errors[0] : (string) ($state['unifi_error'] ?? '')),
            'last_fired' => $fired,
            'last_fire_hm' => $lastFireHm,
            'then_error' => $thenError,
            'clock_hm' => $this->clockHm(),
            'timezone' => $this->timezoneName(),
        ];
    }

    public function pidPath(): string
    {
        return $this->projectRoot . '/data/home-automations.pid';
    }

    public static function pidIsRunning(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }

    public function acquireRunnerLock(): bool
    {
        $path = $this->pidPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $pid = is_file($path) ? (int) trim((string) file_get_contents($path)) : 0;
        if ($pid > 1 && $pid !== getmypid() && self::pidIsRunning($pid)) {
            return false;
        }

        return file_put_contents($path, (string) getmypid() . "\n", LOCK_EX) !== false;
    }

    /**
     * Start scripts/home_automations.php if the loop is not already alive.
     * Home GET is single-threaded; this only spawns a background process.
     */
    public function kickRunner(): void
    {
        $pub = $this->runnerPublic();
        if (!empty($pub['running'])) {
            return;
        }
        $pidPath = $this->pidPath();
        $pid = is_file($pidPath) ? (int) trim((string) file_get_contents($pidPath)) : 0;
        if ($pid > 1 && self::pidIsRunning($pid)) {
            return;
        }
        if (is_file($pidPath) && (time() - (int) @filemtime($pidPath)) < 8) {
            return;
        }
        $script = $this->projectRoot . '/scripts/home_automations.php';
        if (!is_file($script)) {
            return;
        }
        $dir = dirname($pidPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($pidPath, "0\n");
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $log = $this->projectRoot . '/data/home-automations.log';
        $command = sprintf(
            'cd %s && %s %s --root=%s >> %s 2>&1 < /dev/null &',
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
        $process = @proc_open(
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

    /**
     * @return array{latitude: ?float, longitude: ?float, timezone: string, automations: list<array<string, mixed>>}
     */
    public function load(): array
    {
        $defaults = [
            'latitude' => null,
            'longitude' => null,
            'timezone' => '',
            'automations' => [],
        ];
        if (!is_file($this->storePath())) {
            return $defaults;
        }
        $decoded = json_decode((string) file_get_contents($this->storePath()), true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $lat = self::optionalFloat($decoded['latitude'] ?? null);
        $lon = self::optionalFloat($decoded['longitude'] ?? null);
        $list = [];
        foreach (is_array($decoded['automations'] ?? null) ? $decoded['automations'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rule = $this->normalizeRule($row, false);
            if ($rule !== null) {
                $list[] = $rule;
            }
        }

        return [
            'latitude' => $lat,
            'longitude' => $lon,
            'timezone' => YarboVestaboard::normalizeTimezone((string) ($decoded['timezone'] ?? '')),
            'automations' => $list,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicList(): array
    {
        return $this->load()['automations'];
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, source: ?string, needs_coords: bool}
     */
    public function coordsPublic(): array
    {
        $resolved = $this->resolveCoords();

        return [
            'latitude' => $resolved['latitude'],
            'longitude' => $resolved['longitude'],
            'source' => $resolved['source'],
            'needs_coords' => $resolved['latitude'] === null || $resolved['longitude'] === null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $store = $this->load();
        if (array_key_exists('timezone', $input)) {
            $store['timezone'] = YarboVestaboard::normalizeTimezone((string) $input['timezone']);
        }
        if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
            if (array_key_exists('latitude', $input)) {
                $store['latitude'] = self::optionalFloat($input['latitude']);
            }
            if (array_key_exists('longitude', $input)) {
                $store['longitude'] = self::optionalFloat($input['longitude']);
            }
        }
        $meta = [
            'automations' => $store['automations'],
            'sun_coords' => $this->coordsPublic(),
            'server_timezone' => $this->timezoneName(),
            'timezone' => $this->timezonePublic(),
            'runner' => $this->runnerPublic(),
        ];
        $hasRule = isset($input['trigger']) || isset($input['triggers']) || isset($input['actions']) || isset($input['name']) || isset($input['id']);
        if ($hasRule && !isset($input['trigger']) && !isset($input['triggers']) && !isset($input['actions'])) {
            if (!$this->write($store)) {
                return ['ok' => false, 'error' => 'Could not save'];
            }

            return ['ok' => true] + $meta;
        }
        if (!$hasRule) {
            if (!$this->write($store)) {
                return ['ok' => false, 'error' => 'Could not save'];
            }

            return ['ok' => true] + $meta;
        }
        $rule = $this->normalizeRule($input, true);
        if ($rule === null) {
            return ['ok' => false, 'error' => 'An automation needs a When and at least one Then'];
        }
        $found = false;
        foreach ($store['automations'] as $i => $existing) {
            if (($existing['id'] ?? '') === $rule['id']) {
                $store['automations'][$i] = $rule;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $store['automations'][] = $rule;
        }
        if (!$this->write($store)) {
            return ['ok' => false, 'error' => 'Could not save'];
        }
        $state = $this->loadState();
        unset($state['day_slot'][$rule['id']], $state['last_fire'][$rule['id']], $state['then_error'][$rule['id']]);
        $this->writeState($state);
        $this->syncDelayedForRule($rule);
        $meta['automations'] = $store['automations'];
        $meta['runner'] = $this->runnerPublic();

        return ['ok' => true, 'automation' => $rule] + $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'Pick an automation'];
        }
        $store = $this->load();
        $store['automations'] = array_values(array_filter(
            $store['automations'],
            static fn (array $row): bool => ($row['id'] ?? '') !== $id
        ));
        if (!$this->write($store)) {
            return ['ok' => false, 'error' => 'Could not save'];
        }
        $state = $this->loadState();
        unset($state['held'][$id], $state['last_fire'][$id], $state['day_slot'][$id], $state['delayed'][$id], $state['then_error'][$id]);
        $this->writeState($state);

        return ['ok' => true, 'automations' => $store['automations']];
    }

    /**
     * @return array<string, mixed>
     */
    public function setEnabled(string $id, bool $enabled): array
    {
        $id = trim($id);
        $store = $this->load();
        $found = false;
        foreach ($store['automations'] as $i => $row) {
            if (($row['id'] ?? '') === $id) {
                $store['automations'][$i]['enabled'] = $enabled;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['ok' => false, 'error' => 'Unknown automation'];
        }
        if (!$this->write($store)) {
            return ['ok' => false, 'error' => 'Could not save'];
        }
        if (!$enabled) {
            $state = $this->loadState();
            unset($state['delayed'][$id]);
            $this->writeState($state);
        }

        return ['ok' => true, 'automations' => $store['automations']];
    }

    /**
     * One evaluation pass. Pass $snapshot in tests; the sidecar omits it.
     *
     * @param list<array<string, mixed>>|null $snapshot
     * @return array{ok: bool, fired: list<string>, turned_off: list<string>, errors: list<string>}
     */
    public function tick(?array $snapshot = null, ?int $now = null): array
    {
        $now ??= time();
        $hub = new YarboHub($this->projectRoot);
        if (!$hub->enabled(YarboHub::MODULE_HOME)) {
            $state = $this->loadState();
            $state['clock'] = $now;
            $this->writeState($state);

            return ['ok' => true, 'fired' => [], 'turned_off' => [], 'errors' => []];
        }
        $store = $this->load();
        $enabled = array_values(array_filter(
            $store['automations'],
            static fn (array $row): bool => !empty($row['enabled'])
        ));
        if ($enabled === []) {
            $state = $this->loadState();
            $state['delayed'] = [];
            $state['clock'] = $now;
            $this->writeState($state);

            return ['ok' => true, 'fired' => [], 'turned_off' => [], 'errors' => []];
        }
        $rows = $snapshot ?? (new YarboHome($this->projectRoot))->automationDevices();
        $curr = $this->indexSnapshot($rows);
        $state = $this->loadState();
        $prev = is_array($state['prev'] ?? null) ? $state['prev'] : [];
        $fired = [];
        $errors = [];
        $tzName = $this->timezoneName();
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $today = $local->format('Y-m-d');
        $hm = $local->format('H:i');
        $dow = (int) $local->format('w');
        $coords = $this->resolveCoords();
        $enabledById = [];
        foreach ($enabled as $rule) {
            $enabledById[(string) $rule['id']] = $rule;
        }

        foreach ($enabled as $rule) {
            $id = (string) $rule['id'];
            $cooldown = (int) ($rule['cooldown_sec'] ?? 30);
            $lastFire = (int) (($state['last_fire'][$id] ?? 0));
            $inCooldown = $cooldown > 0 && $lastFire > 0 && ($now - $lastFire) < $cooldown;
            $eval = $this->evaluateTriggers($rule, $prev, $curr, $state, $now, $local, $today, $hm, $dow, $coords);
            $this->updateHeld($state, $id, $rule, $curr, $now);
            if (!$eval['should']) {
                continue;
            }
            if (!$this->conditionsPass($rule['conditions'] ?? [], $curr, $hm, $dow)) {
                continue;
            }
            if ($inCooldown) {
                if ($this->offAfterJobs($rule, $now) !== []) {
                    $this->queueOffAfter($state, $rule, $now);
                }
                continue;
            }
            $result = $this->runActions($rule['actions'] ?? []);
            if (!($result['ok'] ?? false)) {
                $err = (string) ($result['error'] ?? 'failed');
                $errors[] = $err;
                $state['last_fire'][$id] = $now;
                if (!isset($state['then_error']) || !is_array($state['then_error'])) {
                    $state['then_error'] = [];
                }
                $state['then_error'][$id] = $err;
                continue;
            }
            unset($state['then_error'][$id]);
            $fired[] = $id;
            $state['last_fire'][$id] = $now;
            $this->markFiredSlots($state, $id, $rule, $eval['matched'], $today, $hm);
            $this->markDurationFired($state, $id, $rule, $eval['matched']);
            $this->queueOffAfter($state, $rule, $now);
            $curr = $this->applyActionSnapshot($curr, $rule['actions'] ?? []);
        }

        $turnedOff = $this->flushDelayed($state, $now, $enabledById, $curr, $errors);
        $state['prev'] = $curr;
        $state['clock'] = $now;
        $state['last_result'] = [
            'at' => $now,
            'hm' => $hm,
            'timezone' => $tzName,
            'fired' => $fired,
            'errors' => $errors,
        ];
        $this->writeState($state);

        return [
            'ok' => $errors === [],
            'fired' => $fired,
            'turned_off' => $turnedOff,
            'errors' => $errors,
            'hm' => $hm,
            'timezone' => $tzName,
        ];
    }

    public function refreshUnifiIfDue(int $now, int $everySec = 5): void
    {
        $hub = new YarboHub($this->projectRoot);
        if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
            return;
        }
        $state = $this->loadState();
        $unifi = new YarboUnifi($this->projectRoot);
        $errors = [];
        $sensorsAt = (int) ($state['sensors_at'] ?? 0);
        $doorsAt = (int) ($state['doors_at'] ?? 0);
        $lightsAt = (int) ($state['lights_at'] ?? 0);
        if ($sensorsAt <= 0 || ($now - $sensorsAt) >= 2) {
            try {
                $unifi->refreshProtectSensorsNow(1.5);
                $state['sensors_at'] = $now;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($doorsAt <= 0 || ($now - $doorsAt) >= $everySec) {
            try {
                $unifi->refreshAccessDoorsNow(1.5);
                $state['doors_at'] = $now;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($lightsAt <= 0 || ($now - $lightsAt) >= 15) {
            try {
                $unifi->refreshProtectLightsNow(1.5);
                $state['lights_at'] = $now;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $state['unifi_at'] = $now;
        $state['unifi_error'] = $errors !== [] ? $errors[0] : '';
        $this->writeState($state);
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function sentence(array $rule, array $names = []): string
    {
        $when = self::whenJoinPhrase($rule, $names);
        $thenParts = [];
        foreach (is_array($rule['actions'] ?? null) ? $rule['actions'] : [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $thenParts[] = self::thenPhrase($action, $names);
        }
        $then = $thenParts !== [] ? implode(', ', $thenParts) : '…';
        $text = $when . ' → ' . $then;
        $actionOff = false;
        foreach (is_array($rule['actions'] ?? null) ? $rule['actions'] : [] as $action) {
            if (is_array($action) && (int) ($action['off_after_sec'] ?? 0) > 0) {
                $actionOff = true;
                break;
            }
        }
        $offAfter = (int) ($rule['off_after_sec'] ?? 0);
        if (!$actionOff && $offAfter > 0) {
            $text .= ', off after ' . self::formatDuration($offAfter);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, string> $names
     */
    public static function whenJoinPhrase(array $rule, array $names = []): string
    {
        $parts = [];
        foreach (self::triggersOf($rule) as $trigger) {
            $parts[] = self::whenPhrase($trigger, $names);
        }
        if ($parts === []) {
            return 'When';
        }
        $join = self::whenMatch($rule) === 'all' ? ' and ' : ' or ';

        return implode($join, $parts);
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<array<string, mixed>>
     */
    public static function triggersOf(array $rule): array
    {
        $out = [];
        foreach (is_array($rule['triggers'] ?? null) ? $rule['triggers'] : [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        if ($out === [] && is_array($rule['trigger'] ?? null)) {
            $out[] = $rule['trigger'];
        }

        return $out;
    }

    public static function whenMatch(array $rule): string
    {
        return strtolower(trim((string) ($rule['when_match'] ?? 'any'))) === 'all' ? 'all' : 'any';
    }

    /**
     * @param array<string, mixed>|null $trigger
     * @param array<string, string> $names
     */
    public static function whenPhrase(?array $trigger, array $names = []): string
    {
        if (!is_array($trigger)) {
            return 'When';
        }
        $type = (string) ($trigger['type'] ?? '');
        if ($type === 'time') {
            $at = (string) ($trigger['at'] ?? '');

            return $at !== '' ? 'At ' . $at : 'At a time';
        }
        if ($type === 'sun') {
            $event = (string) ($trigger['event'] ?? 'sunset');
            $offset = (int) ($trigger['offset_min'] ?? 0);
            $label = $event === 'sunrise' ? 'Sunrise' : 'Sunset';
            if ($offset === 0) {
                return $label;
            }
            $abs = abs($offset);

            return $label . ' ' . ($offset < 0 ? '-' : '+') . $abs . ' min';
        }
        if ($type === 'threshold') {
            $name = $names[(string) ($trigger['id'] ?? '')] ?? 'Sensor';
            $metric = (string) ($trigger['metric'] ?? 'temperature');
            $op = (string) ($trigger['op'] ?? 'above');
            $value = $trigger['value'] ?? '';
            $unit = $metric === 'humidity' ? '%' : '°';

            return $name . ' ' . $metric . ' ' . $op . ' ' . $value . $unit;
        }
        $name = $names[(string) ($trigger['id'] ?? '')] ?? 'Device';
        $event = (string) ($trigger['event'] ?? 'turns_on');
        $for = (int) ($trigger['for_sec'] ?? 0);
        $labels = [
            'turns_on' => 'turns on',
            'turns_off' => 'turns off',
            'opens' => 'opens',
            'closes' => 'closes',
            'motion' => 'motion',
            'stays_on' => 'on',
            'stays_off' => 'off',
            'stays_open' => 'open',
            'stays_closed' => 'closed',
            'no_motion' => 'no motion',
        ];
        $phrase = $labels[$event] ?? $event;
        if (in_array($event, self::DURATION_EVENTS, true) && $for > 0) {
            return $name . ' ' . $phrase . ' ' . self::formatDuration($for);
        }

        return $name . ' ' . $phrase;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, string> $names
     */
    public static function thenPhrase(array $action, array $names = []): string
    {
        if (($action['kind'] ?? '') === 'scene') {
            $name = $names['scene:' . ($action['id'] ?? '')] ?? $names[(string) ($action['id'] ?? '')] ?? 'Scene';
            $cmd = (string) ($action['command'] ?? 'run');
            $text = $cmd === 'stop' ? $name . ' off' : $name;
        } else {
            $name = $names[(string) ($action['id'] ?? '')] ?? 'Device';
            $cmd = (string) ($action['command'] ?? 'on');
            if ($cmd === 'off') {
                $text = $name . ' off';
            } elseif ($cmd === 'unlock') {
                $text = $name . ' unlock';
            } elseif (isset($action['celsius']) || isset($action['heating_setpoint'])) {
                $deg = $action['celsius'] ?? $action['heating_setpoint'];
                $text = $name . ' ' . rtrim(rtrim(number_format((float) $deg, 1, '.', ''), '0'), '.') . '°';
            } elseif (isset($action['brightness'])) {
                $text = $name . ' ' . (int) $action['brightness'] . '%';
            } else {
                $text = $name . ' on';
            }
        }
        $off = (int) ($action['off_after_sec'] ?? 0);
        if ($off > 0) {
            $text .= ', off after ' . self::formatDuration($off);
        }

        return $text;
    }

    public static function formatDuration(int $sec): string
    {
        if ($sec % 3600 === 0 && $sec >= 3600) {
            $n = intdiv($sec, 3600);

            return $n . ' hr';
        }
        if ($sec % 60 === 0 && $sec >= 60) {
            $n = intdiv($sec, 60);

            return $n . ' min';
        }

        return $sec . ' sec';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function normalizeRule(array $row, bool $requireComplete): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            $id = 'a' . bin2hex(random_bytes(4));
        }
        $triggers = [];
        foreach (is_array($row['triggers'] ?? null) ? $row['triggers'] : [] as $one) {
            if (!is_array($one)) {
                continue;
            }
            $norm = $this->normalizeTrigger($one);
            if ($norm !== null) {
                $triggers[] = $norm;
            }
        }
        if ($triggers === []) {
            $one = $this->normalizeTrigger(is_array($row['trigger'] ?? null) ? $row['trigger'] : null);
            if ($one !== null) {
                $triggers[] = $one;
            }
        }
        $trigger = $triggers[0] ?? null;
        $whenMatch = strtolower(trim((string) ($row['when_match'] ?? 'any'))) === 'all' ? 'all' : 'any';
        $actions = [];
        foreach (is_array($row['actions'] ?? null) ? $row['actions'] : [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $norm = $this->normalizeAction($action);
            if ($norm !== null) {
                $actions[] = $norm;
            }
        }
        $conditions = [];
        foreach (is_array($row['conditions'] ?? null) ? $row['conditions'] : [] as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            $norm = $this->normalizeCondition($cond);
            if ($norm !== null) {
                $conditions[] = $norm;
            }
        }
        if ($requireComplete && ($trigger === null || $actions === [])) {
            return null;
        }
        if ($trigger === null && $actions === [] && $requireComplete) {
            return null;
        }
        $names = is_array($row['names'] ?? null) ? $row['names'] : [];
        $offAfter = (int) ($row['off_after_sec'] ?? 0);
        if ($offAfter < 0) {
            $offAfter = 0;
        }
        if ($offAfter > 86400) {
            $offAfter = 86400;
        }
        $name = YarboHub::normalizeDisplayName((string) ($row['name'] ?? ''), 64);
        if ($name === '' && $trigger !== null) {
            $name = YarboHub::normalizeDisplayName(self::sentence([
                'trigger' => $trigger,
                'triggers' => $triggers,
                'when_match' => $whenMatch,
                'actions' => $actions,
                'off_after_sec' => $offAfter,
            ], $names), 64);
        }
        if ($name === '') {
            $name = 'Automation';
        }
        $cooldown = (int) ($row['cooldown_sec'] ?? 30);
        if ($cooldown < 0) {
            $cooldown = 0;
        }
        if ($cooldown > 86400) {
            $cooldown = 86400;
        }

        return [
            'id' => $id,
            'name' => $name,
            'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
            'trigger' => $trigger,
            'triggers' => $triggers,
            'when_match' => $whenMatch,
            'conditions' => $conditions,
            'actions' => $actions,
            'off_after_sec' => $offAfter,
            'cooldown_sec' => $cooldown,
        ];
    }

    /**
     * @param array<string, mixed>|null $trigger
     * @return array<string, mixed>|null
     */
    private function normalizeTrigger(?array $trigger): ?array
    {
        if ($trigger === null) {
            return null;
        }
        $type = strtolower(trim((string) ($trigger['type'] ?? '')));
        if ($type === 'time') {
            $at = self::normalizeHm((string) ($trigger['at'] ?? ''));
            if ($at === null) {
                return null;
            }
            $out = ['type' => 'time', 'at' => $at];
            $days = self::normalizeDays($trigger['days'] ?? null);
            if ($days !== []) {
                $out['days'] = $days;
            }

            return $out;
        }
        if ($type === 'sun') {
            $event = strtolower(trim((string) ($trigger['event'] ?? 'sunset')));
            if ($event !== 'sunrise' && $event !== 'sunset') {
                $event = 'sunset';
            }
            $offset = (int) ($trigger['offset_min'] ?? 0);
            $offset = max(-180, min(180, $offset));

            return ['type' => 'sun', 'event' => $event, 'offset_min' => $offset];
        }
        if ($type === 'threshold') {
            $id = trim((string) ($trigger['id'] ?? ''));
            $metric = strtolower(trim((string) ($trigger['metric'] ?? 'temperature')));
            if ($metric !== 'temperature' && $metric !== 'humidity') {
                $metric = 'temperature';
            }
            $op = strtolower(trim((string) ($trigger['op'] ?? 'above')));
            if ($op !== 'above' && $op !== 'below') {
                $op = 'above';
            }
            if ($id === '' || !is_numeric($trigger['value'] ?? null)) {
                return null;
            }

            return [
                'type' => 'threshold',
                'id' => $id,
                'metric' => $metric,
                'op' => $op,
                'value' => (float) $trigger['value'],
            ];
        }
        if ($type !== 'device') {
            return null;
        }
        $id = trim((string) ($trigger['id'] ?? ''));
        $event = strtolower(trim((string) ($trigger['event'] ?? 'turns_on')));
        if ($id === '' || !in_array($event, self::DEVICE_EVENTS, true)) {
            return null;
        }
        $out = ['type' => 'device', 'id' => $id, 'event' => $event];
        if (in_array($event, self::DURATION_EVENTS, true)) {
            $sec = (int) ($trigger['for_sec'] ?? 300);
            $out['for_sec'] = max(1, min(86400, $sec));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function normalizeAction(array $action): ?array
    {
        $kind = strtolower(trim((string) ($action['kind'] ?? 'device')));
        if ($kind === 'scene') {
            $id = trim((string) ($action['id'] ?? ''));
            if ($id === '') {
                return null;
            }
            $cmd = strtolower(trim((string) ($action['command'] ?? 'run')));
            if ($cmd !== 'run' && $cmd !== 'stop') {
                $cmd = 'run';
            }

            return ['kind' => 'scene', 'id' => $id, 'command' => $cmd] + self::actionOffAfter($action);
        }
        $id = trim((string) ($action['id'] ?? ''));
        $cmd = strtolower(trim((string) ($action['command'] ?? 'on')));
        if ($cmd === 'colour') {
            $cmd = 'color';
        }
        if ($cmd === 'kelvin') {
            $cmd = 'color_temp';
        }
        if ($id === '' || !in_array($cmd, self::DEVICE_COMMANDS, true)) {
            return null;
        }
        $deviceKind = strtolower(trim((string) ($action['device_kind'] ?? '')));
        if (!in_array($deviceKind, ['light', 'heater', 'plug', 'switch', 'vacuum', 'relay'], true)) {
            $deviceKind = '';
        }
        $accept = $cmd === 'lock' ? 'unlock' : $cmd;
        if (!YarboHome::deviceAcceptsCommand($id, $accept, $deviceKind !== '' ? $deviceKind : null)) {
            return null;
        }
        $out = ['kind' => 'device', 'id' => $id, 'command' => $cmd];
        if ($deviceKind !== '') {
            $out['device_kind'] = $deviceKind;
        }
        if (in_array($cmd, ['on', 'brightness', 'color', 'color_temp', 'setpoint'], true)) {
            if (array_key_exists('brightness', $action) && $action['brightness'] !== null && $action['brightness'] !== '') {
                $out['brightness'] = max(1, min(100, (int) $action['brightness']));
            }
            $hex = trim((string) ($action['hex'] ?? $action['color_hex'] ?? ''));
            if ($hex !== '' && $hex[0] !== '#') {
                $hex = '#' . $hex;
            }
            if (preg_match('/^#([0-9a-fA-F]{3})$/', $hex, $short)) {
                $out['hex'] = '#' . strtolower($short[1][0] . $short[1][0] . $short[1][1] . $short[1][1] . $short[1][2] . $short[1][2]);
            } elseif (preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $full)) {
                $out['hex'] = '#' . strtolower($full[1]);
            }
            if (array_key_exists('kelvin', $action) && $action['kelvin'] !== null && $action['kelvin'] !== '') {
                $out['kelvin'] = max(1500, min(8000, (int) $action['kelvin']));
            } elseif (array_key_exists('color_temp', $action) && $action['color_temp'] !== null && $action['color_temp'] !== '') {
                $out['kelvin'] = max(1500, min(8000, (int) $action['color_temp']));
            }
            $celsius = $action['celsius'] ?? $action['heating_setpoint'] ?? $action['setpoint'] ?? null;
            if ($celsius !== null && $celsius !== '') {
                $out['celsius'] = max(5.0, min(35.0, (float) $celsius));
            }
        }

        return $out + self::actionOffAfter($action);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, int>
     */
    private static function actionOffAfter(array $action): array
    {
        $sec = (int) ($action['off_after_sec'] ?? 0);
        if ($sec < 0) {
            $sec = 0;
        }
        if ($sec > 86400) {
            $sec = 86400;
        }
        if ($sec <= 0) {
            return [];
        }

        return ['off_after_sec' => $sec];
    }

    /**
     * @param array<string, mixed> $cond
     * @return array<string, mixed>|null
     */
    private function normalizeCondition(array $cond): ?array
    {
        $type = strtolower(trim((string) ($cond['type'] ?? '')));
        if ($type === 'time_window') {
            $start = self::normalizeHm((string) ($cond['start'] ?? ''));
            $end = self::normalizeHm((string) ($cond['end'] ?? ''));
            if ($start === null || $end === null) {
                return null;
            }
            $out = ['type' => 'time_window', 'start' => $start, 'end' => $end];
            $days = self::normalizeDays($cond['days'] ?? null);
            if ($days !== []) {
                $out['days'] = $days;
            }

            return $out;
        }
        if ($type !== 'device') {
            return null;
        }
        $id = trim((string) ($cond['id'] ?? ''));
        $state = strtolower(trim((string) ($cond['state'] ?? '')));
        if ($id === '' || !in_array($state, ['on', 'off', 'open', 'closed', 'motion', 'no_motion'], true)) {
            return null;
        }

        return ['type' => 'device', 'id' => $id, 'state' => $state];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexSnapshot(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'on' => array_key_exists('on', $row) ? (bool) $row['on'] : null,
                'open' => array_key_exists('open', $row) ? ($row['open'] === null ? null : (bool) $row['open']) : null,
                'motion' => array_key_exists('motion', $row) ? ($row['motion'] === null ? null : (bool) $row['motion']) : null,
                'motion_at' => isset($row['motion_at']) && is_numeric($row['motion_at']) ? (int) $row['motion_at'] : 0,
                'temperature' => isset($row['temperature']) && is_numeric($row['temperature']) ? (float) $row['temperature'] : null,
                'humidity' => isset($row['humidity']) && is_numeric($row['humidity']) ? (float) $row['humidity'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $curr
     * @param array<string, mixed> $state
     * @param array{latitude: ?float, longitude: ?float, source: ?string} $coords
     * @return array{should: bool, matched: list<int>}
     */
    private function evaluateTriggers(
        array $rule,
        array $prev,
        array $curr,
        array $state,
        int $now,
        \DateTimeImmutable $local,
        string $today,
        string $hm,
        int $dow,
        array $coords
    ): array {
        $triggers = self::triggersOf($rule);
        if ($triggers === []) {
            return ['should' => false, 'matched' => []];
        }
        $id = (string) ($rule['id'] ?? '');
        $matched = [];
        $true = [];
        foreach ($triggers as $i => $trigger) {
            if ($this->triggerMatches($trigger, $id, $i, $prev, $curr, $state, $now, $local, $today, $hm, $dow, $coords)) {
                $matched[] = $i;
            }
            if ($this->triggerCurrentlyTrue($trigger, $id, $i, $prev, $curr, $state, $now, $local, $today, $hm, $dow, $coords)) {
                $true[] = $i;
            }
        }
        if (self::whenMatch($rule) === 'all') {
            return [
                'should' => count($true) === count($triggers) && $matched !== [],
                'matched' => $matched,
            ];
        }

        return ['should' => $matched !== [], 'matched' => $matched];
    }

    /**
     * @param array<string, mixed> $trigger
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $curr
     * @param array<string, mixed> $state
     * @param array{latitude: ?float, longitude: ?float, source: ?string} $coords
     */
    private function triggerMatches(
        array $trigger,
        string $id,
        int $index,
        array $prev,
        array $curr,
        array $state,
        int $now,
        \DateTimeImmutable $local,
        string $today,
        string $hm,
        int $dow,
        array $coords
    ): bool {
        $type = (string) ($trigger['type'] ?? '');
        if ($type === 'time') {
            if ($this->daySlotUsed($state, $id, $this->slotKeyForTrigger($trigger, $today, $hm))) {
                return false;
            }
            $days = is_array($trigger['days'] ?? null) ? $trigger['days'] : [];
            if ($days !== [] && !in_array($dow, $days, true)) {
                return false;
            }
            $at = (string) $trigger['at'];

            return $this->clockReached($at, $hm, $today, $state, $now)
                || $this->failedTimeRetry($id, $at, $hm, $today, $state);
        }
        if ($type === 'sun') {
            if ($this->daySlotUsed($state, $id, $this->slotKeyForTrigger($trigger, $today, $hm))) {
                return false;
            }
            if ($coords['latitude'] === null || $coords['longitude'] === null) {
                return false;
            }
            $eventHm = $this->sunEventHm(
                (string) $trigger['event'],
                $now,
                (float) $coords['latitude'],
                (float) $coords['longitude'],
                (int) ($trigger['offset_min'] ?? 0),
                $local->getTimezone()
            );

            return $eventHm !== null && (
                $this->clockReached($eventHm, $hm, $today, $state, $now)
                || $this->failedTimeRetry($id, $eventHm, $hm, $today, $state)
            );
        }
        if ($type === 'threshold') {
            $deviceId = (string) ($trigger['id'] ?? '');
            $metric = (string) ($trigger['metric'] ?? 'temperature');
            $op = (string) ($trigger['op'] ?? 'above');
            $value = (float) ($trigger['value'] ?? 0);
            $currVal = $curr[$deviceId][$metric] ?? null;
            $prevVal = $prev[$deviceId][$metric] ?? null;
            if (!is_float($currVal) && !is_int($currVal)) {
                return false;
            }
            $currVal = (float) $currVal;
            $nowMatch = $op === 'below' ? $currVal < $value : $currVal > $value;
            if (!$nowMatch) {
                return false;
            }
            if (!is_float($prevVal) && !is_int($prevVal)) {
                return false;
            }
            $prevVal = (float) $prevVal;
            $wasMatch = $op === 'below' ? $prevVal < $value : $prevVal > $value;

            return !$wasMatch;
        }
        $deviceId = (string) ($trigger['id'] ?? '');
        $event = (string) ($trigger['event'] ?? '');
        if (in_array($event, self::DURATION_EVENTS, true)) {
            $held = $this->heldBuckets($state, $id)[$index] ?? null;
            if ($held === null || !empty($held['fired'])) {
                return false;
            }
            $since = (int) ($held['since'] ?? 0);
            $for = (int) ($trigger['for_sec'] ?? 300);

            return $since > 0 && ($now - $since) >= $for && $this->durationActive($event, $curr[$deviceId] ?? null);
        }
        if (!in_array($event, self::EDGE_EVENTS, true)) {
            return false;
        }
        if (!isset($prev[$deviceId]) || !isset($curr[$deviceId])) {
            return false;
        }

        return $this->edgeFired($event, $prev[$deviceId], $curr[$deviceId]);
    }

    /**
     * State of a When for AND: currently true, not only the rising edge.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $curr
     * @param array<string, mixed> $state
     * @param array{latitude: ?float, longitude: ?float, source: ?string} $coords
     */
    private function triggerCurrentlyTrue(
        array $trigger,
        string $id,
        int $index,
        array $prev,
        array $curr,
        array $state,
        int $now,
        \DateTimeImmutable $local,
        string $today,
        string $hm,
        int $dow,
        array $coords
    ): bool {
        $type = (string) ($trigger['type'] ?? '');
        if ($type === 'time') {
            $days = is_array($trigger['days'] ?? null) ? $trigger['days'] : [];
            if ($days !== [] && !in_array($dow, $days, true)) {
                return false;
            }

            return $this->clockReached((string) $trigger['at'], $hm, $today, $state, $now);
        }
        if ($type === 'sun') {
            if ($coords['latitude'] === null || $coords['longitude'] === null) {
                return false;
            }
            $eventHm = $this->sunEventHm(
                (string) $trigger['event'],
                $now,
                (float) $coords['latitude'],
                (float) $coords['longitude'],
                (int) ($trigger['offset_min'] ?? 0),
                $local->getTimezone()
            );

            return $eventHm !== null && $this->clockReached($eventHm, $hm, $today, $state, $now);
        }
        if ($type === 'threshold') {
            $deviceId = (string) ($trigger['id'] ?? '');
            $metric = (string) ($trigger['metric'] ?? 'temperature');
            $op = (string) ($trigger['op'] ?? 'above');
            $value = (float) ($trigger['value'] ?? 0);
            $currVal = $curr[$deviceId][$metric] ?? null;
            if (!is_float($currVal) && !is_int($currVal)) {
                return false;
            }
            $currVal = (float) $currVal;

            return $op === 'below' ? $currVal < $value : $currVal > $value;
        }
        $deviceId = (string) ($trigger['id'] ?? '');
        $event = (string) ($trigger['event'] ?? '');
        $row = $curr[$deviceId] ?? null;
        if (in_array($event, self::DURATION_EVENTS, true)) {
            return $this->triggerMatches($trigger, $id, $index, $prev, $curr, $state, $now, $local, $today, $hm, $dow, $coords);
        }
        if ($row === null) {
            return false;
        }

        return match ($event) {
            'turns_on' => $row['on'] === true,
            'turns_off' => $row['on'] === false,
            'opens' => $row['open'] === true,
            'closes' => $row['open'] === false,
            'motion' => $row['motion'] === true
                || ((int) ($row['motion_at'] ?? 0) > 0 && (int) ($row['motion_at'] ?? 0) > (int) ($prev[$deviceId]['motion_at'] ?? 0)),
            default => false,
        };
    }

    /**
     * Exact minute, or catch-up when the sidecar skipped past it on the same local day.
     *
     * @param array<string, mixed> $state
     */
    private function clockReached(string $at, string $hm, string $today, array $state, int $now): bool
    {
        $at = self::normalizeHm($at) ?? $at;
        if ($hm === $at) {
            return true;
        }
        $last = (int) ($state['clock'] ?? 0);
        if ($last <= 0 || $last >= $now) {
            return false;
        }
        try {
            $tz = new \DateTimeZone($this->timezoneName());
        } catch (\Exception) {
            return false;
        }
        $prev = (new \DateTimeImmutable('@' . $last))->setTimezone($tz);
        if ($prev->format('Y-m-d') !== $today) {
            return false;
        }
        $prevHm = $prev->format('H:i');

        return $prevHm < $at && $hm > $at;
    }

    /**
     * One fire per local day per time-of-day (or sun event). Older date-only
     * day_slot values do not match, so a stuck “already ran today” can retry.
     *
     * @param array<string, mixed> $trigger
     */
    private function slotKeyForTrigger(array $trigger, string $today, string $hm): string
    {
        $type = (string) ($trigger['type'] ?? '');
        if ($type === 'time') {
            $at = self::normalizeHm((string) ($trigger['at'] ?? '')) ?? $hm;

            return $today . 'T' . $at;
        }
        if ($type === 'sun') {
            $event = (string) ($trigger['event'] ?? 'sunset');
            $offset = (int) ($trigger['offset_min'] ?? 0);

            return $today . 'Tsun-' . $event . '-' . $offset;
        }

        return $today;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function daySlotSet(array $state, string $id): array
    {
        $raw = $state['day_slot'][$id] ?? null;
        if (is_string($raw) && $raw !== '') {
            return [$raw => true];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && $key !== '' && $value) {
                $out[$key] = true;
            } elseif (is_string($value) && $value !== '') {
                $out[$value] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function daySlotUsed(array $state, string $id, string $key): bool
    {
        return isset($this->daySlotSet($state, $id)[$key]);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $rule
     * @param list<int> $matched
     */
    private function markFiredSlots(
        array &$state,
        string $id,
        array $rule,
        array $matched,
        string $today,
        string $hm
    ): void {
        $triggers = self::triggersOf($rule);
        $slots = $this->daySlotSet($state, $id);
        $wrote = false;
        foreach ($matched as $i) {
            $trigger = $triggers[$i] ?? null;
            if (!is_array($trigger)) {
                continue;
            }
            $type = (string) ($trigger['type'] ?? '');
            if ($type !== 'time' && $type !== 'sun') {
                continue;
            }
            $slots[$this->slotKeyForTrigger($trigger, $today, $hm)] = true;
            $wrote = true;
        }
        if ($wrote) {
            $state['day_slot'][$id] = $slots;
        }
    }

    /**
     * Then failed at this slot (no day_slot) — retry after cooldown even once the minute has passed.
     *
     * @param array<string, mixed> $state
     */
    private function failedTimeRetry(string $id, string $at, string $hm, string $today, array $state): bool
    {
        $at = self::normalizeHm($at) ?? $at;
        if ($hm < $at) {
            return false;
        }
        $lastFire = (int) ($state['last_fire'][$id] ?? 0);
        if ($lastFire <= 0) {
            return false;
        }
        try {
            $tz = new \DateTimeZone($this->timezoneName());
        } catch (\Exception) {
            return false;
        }
        $firedAt = (new \DateTimeImmutable('@' . $lastFire))->setTimezone($tz);
        if ($firedAt->format('Y-m-d') !== $today) {
            return false;
        }

        return $firedAt->format('H:i') >= $at;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function heldBuckets(array $state, string $id): array
    {
        $raw = $state['held'][$id] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw['since'])) {
            return [0 => $raw];
        }
        $out = [];
        foreach ($raw as $key => $row) {
            if (is_array($row)) {
                $out[(int) $key] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $rule
     * @param array<string, array<string, mixed>> $curr
     */
    private function updateHeld(array &$state, string $id, array $rule, array $curr, int $now): void
    {
        $buckets = $this->heldBuckets($state, $id);
        $keep = [];
        foreach (self::triggersOf($rule) as $i => $trigger) {
            $event = (string) ($trigger['event'] ?? '');
            if (!in_array($event, self::DURATION_EVENTS, true)) {
                continue;
            }
            $deviceId = (string) ($trigger['id'] ?? '');
            if (!$this->durationActive($event, $curr[$deviceId] ?? null)) {
                continue;
            }
            $prev = $buckets[$i] ?? null;
            $keep[$i] = is_array($prev) ? $prev : ['since' => $now, 'fired' => false];
        }
        if ($keep === []) {
            unset($state['held'][$id]);
        } else {
            $state['held'][$id] = $keep;
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $rule
     * @param list<int> $matched
     */
    private function markDurationFired(array &$state, string $id, array $rule, array $matched): void
    {
        $triggers = self::triggersOf($rule);
        $buckets = $this->heldBuckets($state, $id);
        $wrote = false;
        foreach ($matched as $i) {
            $event = (string) (($triggers[$i]['event'] ?? ''));
            if (!in_array($event, self::DURATION_EVENTS, true)) {
                continue;
            }
            if (!isset($buckets[$i]) || !is_array($buckets[$i])) {
                $buckets[$i] = ['since' => time(), 'fired' => true];
            } else {
                $buckets[$i]['fired'] = true;
            }
            $wrote = true;
        }
        if ($wrote) {
            $state['held'][$id] = $buckets;
        }
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function durationActive(string $event, ?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        return match ($event) {
            'stays_on' => $row['on'] === true,
            'stays_off' => $row['on'] === false,
            'stays_open' => $row['open'] === true,
            'stays_closed' => $row['open'] === false,
            'no_motion' => $row['motion'] === false,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $curr
     */
    private function edgeFired(string $event, array $prev, array $curr): bool
    {
        return match ($event) {
            'turns_on' => $prev['on'] === false && $curr['on'] === true,
            'turns_off' => $prev['on'] === true && $curr['on'] === false,
            'opens' => $prev['open'] === false && $curr['open'] === true,
            'closes' => $prev['open'] === true && $curr['open'] === false,
            'motion' => ($prev['motion'] === false && $curr['motion'] === true)
                || ((int) ($curr['motion_at'] ?? 0) > 0 && (int) ($curr['motion_at'] ?? 0) > (int) ($prev['motion_at'] ?? 0)),
            default => false,
        };
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, array<string, mixed>> $curr
     */
    private function conditionsPass(array $conditions, array $curr, string $hm, int $dow): bool
    {
        foreach ($conditions as $cond) {
            $type = (string) ($cond['type'] ?? '');
            if ($type === 'time_window') {
                $days = is_array($cond['days'] ?? null) ? $cond['days'] : [];
                if ($days !== [] && !in_array($dow, $days, true)) {
                    return false;
                }
                if (!self::hmInWindow($hm, (string) $cond['start'], (string) $cond['end'])) {
                    return false;
                }
                continue;
            }
            if ($type === 'device') {
                $row = $curr[(string) ($cond['id'] ?? '')] ?? null;
                if ($row === null) {
                    return false;
                }
                $want = (string) ($cond['state'] ?? '');
                $ok = match ($want) {
                    'on' => $row['on'] === true,
                    'off' => $row['on'] === false,
                    'open' => $row['open'] === true,
                    'closed' => $row['open'] === false,
                    'motion' => $row['motion'] === true,
                    'no_motion' => $row['motion'] === false,
                    default => false,
                };
                if (!$ok) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    private function runActions(array $actions): array
    {
        $home = new YarboHome($this->projectRoot);
        $errors = [];
        foreach ($actions as $action) {
            $result = $this->runOneAction($home, $action);
            if (!($result['ok'] ?? false)) {
                $errors[] = (string) ($result['error'] ?? 'failed');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => $errors[0]];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function syncDelayedForRule(array $rule): void
    {
        $id = (string) ($rule['id'] ?? '');
        if ($id === '') {
            return;
        }
        $state = $this->loadState();
        if (!isset($state['delayed'][$id]) || !is_array($state['delayed'][$id])) {
            return;
        }
        $jobs = $this->offAfterJobs($rule, 0);
        if (empty($rule['enabled']) || $jobs === []) {
            unset($state['delayed'][$id]);
            $this->writeState($state);

            return;
        }
        $existing = $state['delayed'][$id];
        $list = isset($existing['at']) ? [$existing] : (array_is_list($existing) ? $existing : [$existing]);
        foreach ($jobs as $i => $job) {
            if (isset($list[$i]['at'])) {
                $jobs[$i]['at'] = (int) $list[$i]['at'];
            } else {
                $jobs[$i]['at'] = time() + (int) ($job['at'] ?? 0);
            }
        }
        $state['delayed'][$id] = $jobs;
        $this->writeState($state);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $rule
     */
    private function queueOffAfter(array &$state, array $rule, int $now): void
    {
        $id = (string) ($rule['id'] ?? '');
        $jobs = $this->offAfterJobs($rule, $now);
        if ($id === '' || $jobs === []) {
            unset($state['delayed'][$id]);

            return;
        }
        if (!isset($state['delayed']) || !is_array($state['delayed'])) {
            $state['delayed'] = [];
        }
        $state['delayed'][$id] = $jobs;
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<array{at: int, actions: list<array<string, mixed>>}>
     */
    private function offAfterJobs(array $rule, int $now): array
    {
        $ruleSec = (int) ($rule['off_after_sec'] ?? 0);
        $jobs = [];
        foreach (is_array($rule['actions'] ?? null) ? $rule['actions'] : [] as $action) {
            if (!is_array($action)) {
                continue;
            }
            $sec = (int) ($action['off_after_sec'] ?? 0);
            if ($sec <= 0) {
                $sec = $ruleSec;
            }
            $offs = $this->offActions([$action]);
            if ($sec <= 0 || $offs === []) {
                continue;
            }
            $jobs[] = ['at' => $now + $sec, 'actions' => $offs];
        }

        return $jobs;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return list<array<string, mixed>>
     */
    private function offActions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            if (($action['kind'] ?? '') === 'scene') {
                $cmd = (string) ($action['command'] ?? 'run');
                if ($cmd === 'stop') {
                    continue;
                }
                $out[] = ['kind' => 'scene', 'id' => (string) $action['id'], 'command' => 'stop'];
                continue;
            }
            $cmd = (string) ($action['command'] ?? 'on');
            if (in_array($cmd, ['off', 'unlock', 'lock', 'open', 'close', 'stop'], true)) {
                continue;
            }
            $id = (string) ($action['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = ['kind' => 'device', 'id' => $id, 'command' => 'off'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array<string, mixed>> $enabledById
     * @param array<string, array<string, mixed>> $curr
     * @param list<string> $errors
     * @return list<string>
     */
    private function flushDelayed(array &$state, int $now, array $enabledById, array &$curr, array &$errors): array
    {
        $turnedOff = [];
        if (!isset($state['delayed']) || !is_array($state['delayed'])) {
            $state['delayed'] = [];

            return $turnedOff;
        }
        foreach ($state['delayed'] as $id => $job) {
            $id = (string) $id;
            if (!is_array($job) || !isset($enabledById[$id])) {
                unset($state['delayed'][$id]);
                continue;
            }
            $jobs = isset($job['at']) ? [$job] : (array_is_list($job) ? $job : [$job]);
            $keep = [];
            $did = false;
            foreach ($jobs as $one) {
                if (!is_array($one)) {
                    continue;
                }
                $at = (int) ($one['at'] ?? 0);
                if ($at <= 0 || $now < $at) {
                    $keep[] = $one;
                    continue;
                }
                $actions = is_array($one['actions'] ?? null) ? $one['actions'] : [];
                $result = $this->runActions($actions);
                if (!($result['ok'] ?? false)) {
                    $errors[] = (string) ($result['error'] ?? 'failed');
                }
                $curr = $this->applyActionSnapshot($curr, $actions);
                $did = true;
            }
            if ($did) {
                $turnedOff[] = $id;
            }
            if ($keep === []) {
                unset($state['delayed'][$id]);
            } else {
                $state['delayed'][$id] = $keep;
            }
        }

        return $turnedOff;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function runOneAction(YarboHome $home, array $action): array
    {
        if ($this->commandHandler !== null) {
            return ($this->commandHandler)($action);
        }
        if (($action['kind'] ?? '') === 'scene') {
            $cmd = (string) ($action['command'] ?? 'run');

            return $cmd === 'stop'
                ? $home->stopScene((string) $action['id'])
                : $home->runScene((string) $action['id']);
        }
        $cmd = (string) ($action['command'] ?? 'on');
        if ($cmd === 'lock') {
            $cmd = 'unlock';
        }
        if (in_array($cmd, ['on', 'brightness', 'color', 'color_temp', 'setpoint'], true)) {
            return $this->runDeviceLook($home, $action);
        }
        $payload = ['id' => (string) $action['id'], 'command' => $cmd];

        return $home->command($payload);
    }

    /**
     * On, plus brightness / colour / setpoint when the Then chip stored them.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function runDeviceLook(YarboHome $home, array $action): array
    {
        $id = (string) ($action['id'] ?? '');
        $kind = strtolower(trim((string) ($action['device_kind'] ?? '')));
        $isHeater = $kind === 'heater';
        $errors = [];
        $last = ['ok' => true];
        $celsius = $action['celsius'] ?? $action['heating_setpoint'] ?? null;
        if ($isHeater) {
            if ($celsius !== null && $celsius !== '') {
                $last = $home->command([
                    'id' => $id,
                    'command' => 'setpoint',
                    'celsius' => (float) $celsius,
                ]);
            } else {
                $last = $home->command(['id' => $id, 'command' => 'on']);
            }
            if (!($last['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string) ($last['error'] ?? 'failed')];
            }

            return $last;
        }
        $acceptKind = $kind !== '' ? $kind : null;
        $sent = false;
        if (isset($action['brightness']) && YarboHome::deviceAcceptsCommand($id, 'brightness', $acceptKind)) {
            $last = $home->command([
                'id' => $id,
                'command' => 'brightness',
                'brightness' => (int) $action['brightness'],
            ]);
            $sent = true;
            if (!($last['ok'] ?? false)) {
                $errors[] = (string) ($last['error'] ?? 'failed');
            }
        }
        $hex = trim((string) ($action['hex'] ?? $action['color_hex'] ?? ''));
        $kelvin = 0;
        if (isset($action['kelvin']) && $action['kelvin'] !== '') {
            $kelvin = (int) $action['kelvin'];
        } elseif (isset($action['color_temp']) && $action['color_temp'] !== '') {
            $kelvin = (int) $action['color_temp'];
        }
        if ($hex !== '' && YarboHome::deviceAcceptsCommand($id, 'color', $acceptKind)) {
            if (!$sent) {
                $last = $home->command(['id' => $id, 'command' => 'on']);
                $sent = true;
                if (!($last['ok'] ?? false)) {
                    $errors[] = (string) ($last['error'] ?? 'failed');
                }
            }
            $last = $home->command(['id' => $id, 'command' => 'color', 'hex' => $hex]);
            $sent = true;
            if (!($last['ok'] ?? false)) {
                $errors[] = (string) ($last['error'] ?? 'failed');
            }
        } elseif ($kelvin > 0 && YarboHome::deviceAcceptsCommand($id, 'color_temp', $acceptKind)) {
            if (!$sent) {
                $last = $home->command(['id' => $id, 'command' => 'on']);
                $sent = true;
                if (!($last['ok'] ?? false)) {
                    $errors[] = (string) ($last['error'] ?? 'failed');
                }
            }
            $last = $home->command(['id' => $id, 'command' => 'color_temp', 'kelvin' => $kelvin]);
            $sent = true;
            if (!($last['ok'] ?? false)) {
                $errors[] = (string) ($last['error'] ?? 'failed');
            }
        }
        if (!$sent) {
            $last = $home->command(['id' => $id, 'command' => 'on']);
            if (!($last['ok'] ?? false)) {
                $errors[] = (string) ($last['error'] ?? 'failed');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => $errors[0]];
        }

        return $last;
    }

    /**
     * @param array<string, array<string, mixed>> $curr
     * @param list<array<string, mixed>> $actions
     * @return array<string, array<string, mixed>>
     */
    private function applyActionSnapshot(array $curr, array $actions): array
    {
        foreach ($actions as $action) {
            if (($action['kind'] ?? '') === 'scene') {
                continue;
            }
            $id = (string) ($action['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($curr[$id])) {
                $curr[$id] = ['on' => null, 'open' => null, 'motion' => null, 'temperature' => null, 'humidity' => null];
            }
            $cmd = (string) ($action['command'] ?? '');
            if (in_array($cmd, ['on', 'brightness', 'color', 'color_temp', 'setpoint'], true)) {
                $curr[$id]['on'] = true;
            } elseif ($cmd === 'off') {
                $curr[$id]['on'] = false;
            } elseif ($cmd === 'unlock' || $cmd === 'open') {
                $curr[$id]['open'] = true;
            } elseif ($cmd === 'close') {
                $curr[$id]['open'] = false;
            }
        }

        return $curr;
    }

    public function sunEventHm(
        string $event,
        int $now,
        float $lat,
        float $lon,
        int $offsetMin,
        \DateTimeZone $tz
    ): ?string {
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $noon = $local->setTime(12, 0)->getTimestamp();
        $info = @date_sun_info($noon, $lat, $lon);
        if (!is_array($info)) {
            return null;
        }
        $key = $event === 'sunrise' ? 'sunrise' : 'sunset';
        $ts = $info[$key] ?? false;
        if (!is_int($ts) || $ts <= 0) {
            return null;
        }
        $ts += $offsetMin * 60;

        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i');
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, source: ?string}
     */
    public function resolveCoords(): array
    {
        $store = $this->load();
        if ($store['latitude'] !== null && $store['longitude'] !== null) {
            return [
                'latitude' => $store['latitude'],
                'longitude' => $store['longitude'],
                'source' => 'saved',
            ];
        }
        $gps = $this->gpsFromMap();
        if ($gps !== null) {
            return $gps + ['source' => 'gps'];
        }

        return ['latitude' => null, 'longitude' => null, 'source' => null];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function gpsFromMap(): ?array
    {
        foreach (['/data/map-last.json', '/data/map-backup-last.json'] as $rel) {
            $path = $this->projectRoot . $rel;
            if (!is_file($path)) {
                continue;
            }
            $raw = json_decode((string) file_get_contents($path), true);
            if (!is_array($raw)) {
                continue;
            }
            $ref = $raw['gps_ref'] ?? null;
            if (!is_array($ref) && is_array($raw['data'] ?? null)) {
                $ref = $raw['data']['gps_ref'] ?? null;
            }
            if (is_array($ref)) {
                $extracted = YarboGeo::extractGpsRef($ref);
                if ($extracted !== null) {
                    return $extracted;
                }
            }
            $direct = YarboGeo::extractGpsRef($raw);
            if ($direct !== null) {
                return $direct;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        $defaults = [
            'prev' => [],
            'held' => [],
            'last_fire' => [],
            'then_error' => [],
            'day_slot' => [],
            'delayed' => [],
            'clock' => 0,
            'unifi_at' => 0,
            'sensors_at' => 0,
            'doors_at' => 0,
            'lights_at' => 0,
            'unifi_error' => '',
            'last_result' => ['at' => 0, 'fired' => [], 'errors' => []],
        ];
        if (!is_file($this->statePath())) {
            return $defaults;
        }
        $decoded = json_decode((string) file_get_contents($this->statePath()), true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return [
            'prev' => is_array($decoded['prev'] ?? null) ? $decoded['prev'] : [],
            'held' => is_array($decoded['held'] ?? null) ? $decoded['held'] : [],
            'last_fire' => is_array($decoded['last_fire'] ?? null) ? $decoded['last_fire'] : [],
            'then_error' => is_array($decoded['then_error'] ?? null) ? $decoded['then_error'] : [],
            'day_slot' => is_array($decoded['day_slot'] ?? null) ? $decoded['day_slot'] : [],
            'delayed' => is_array($decoded['delayed'] ?? null) ? $decoded['delayed'] : [],
            'clock' => (int) ($decoded['clock'] ?? 0),
            'unifi_at' => (int) ($decoded['unifi_at'] ?? 0),
            'sensors_at' => (int) ($decoded['sensors_at'] ?? 0),
            'doors_at' => (int) ($decoded['doors_at'] ?? 0),
            'lights_at' => (int) ($decoded['lights_at'] ?? 0),
            'unifi_error' => (string) ($decoded['unifi_error'] ?? ''),
            'last_result' => is_array($decoded['last_result'] ?? null) ? $decoded['last_result'] : $defaults['last_result'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->statePath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->statePath(), $json . "\n", LOCK_EX);
        }
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
        $json = json_encode([
            'latitude' => $store['latitude'] ?? null,
            'longitude' => $store['longitude'] ?? null,
            'timezone' => YarboVestaboard::normalizeTimezone((string) ($store['timezone'] ?? '')),
            'automations' => $store['automations'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false && file_put_contents($this->storePath(), $json . "\n", LOCK_EX) !== false;
    }

    private static function optionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if ($n < -180 || $n > 180) {
            return null;
        }

        return $n;
    }

    private static function normalizeHm(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    private static function isUtcZone(string $zone): bool
    {
        return strcasecmp($zone, 'UTC') === 0 || strcasecmp($zone, 'Etc/UTC') === 0;
    }

    private static function usableTimezone(string $value): string
    {
        $zone = YarboVestaboard::normalizeTimezone($value);
        if ($zone === '' || self::isUtcZone($zone)) {
            return '';
        }

        return $zone;
    }

    /**
     * @return list<int>
     */
    private static function normalizeDays(mixed $days): array
    {
        if (!is_array($days)) {
            return [];
        }
        $out = [];
        foreach ($days as $day) {
            $n = (int) $day;
            if ($n >= 0 && $n <= 6) {
                $out[$n] = $n;
            }
        }
        sort($out);

        return array_values($out);
    }

    public static function hmInWindow(string $hm, string $start, string $end): bool
    {
        if ($start === $end) {
            return true;
        }
        if ($start < $end) {
            return $hm >= $start && $hm < $end;
        }

        return $hm >= $start || $hm < $end;
    }
}
