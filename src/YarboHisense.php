<?php

declare(strict_types=1);

namespace Yarbo;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Hisense VIDAA TVs on the LAN (MQTT port 36669).
 * Apple Home does not share this television, so the panel talks to the set itself.
 */
final class YarboHisense
{
    public const PORT = 36669;
    public const ID_PREFIX = 'hisense:';

    private const LEGACY_USER = 'hisenseservice';
    private const LEGACY_PASS = 'multimqttservice';
    private const PATTERN = '38D65DC30F45109A369A86FCE866A85B';
    private const VALUE_SUFFIX = 'h!i@s#$v%i^d&a*a';
    private const XOR_CONST = 0x569814772b03a968;

    /** @var callable|null */
    private $transport;

    /** @var callable|null */
    private $portScanner;

    /** @var resource|null */
    private $lockFh = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    public function setPortScanner(?callable $scanner): void
    {
        $this->portScanner = $scanner;
    }

    public function storePath(): string
    {
        return $this->projectRoot . '/data/hisense.json';
    }

    /**
     * @return array{ok: bool, found: list<array<string, mixed>>, scanned: int}
     */
    public function discover(): array
    {
        $prefixes = $this->lanPrefixes();
        $open = [];
        foreach ($prefixes as $prefix) {
            foreach ($this->scanPrefix($prefix) as $host) {
                $open[$host] = true;
            }
        }
        $found = [];
        foreach (array_keys($open) as $host) {
            if (count($found) >= 6) {
                break;
            }
            $found[] = $this->probe((string) $host);
        }

        return [
            'ok' => true,
            'found' => $found,
            'scanned' => count($prefixes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(string $host): array
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Enter the TV IP address'];
        }
        $probe = $this->probe($host);
        if (empty($probe['port_open']) && empty($probe['connected'])) {
            return [
                'ok' => false,
                'error' => (string) ($probe['error'] ?? 'That address did not answer on the TV port'),
                'probe' => $probe,
            ];
        }
        $id = self::ID_PREFIX . $host;
        $store = $this->load();
        $prev = is_array($store['tvs'][$id] ?? null) ? $store['tvs'][$id] : [];
        $row = $prev;
        $row['id'] = $id;
        $row['host'] = $host;
        $row['name'] = (string) ($prev['name'] ?? 'Hisense TV');
        if (!empty($probe['sources'])) {
            $row['sources'] = $probe['sources'];
        }
        $row['needs_pin'] = !empty($probe['needs_pin']);
        $row['connected'] = !empty($probe['connected']);
        $row['mode'] = (string) ($probe['mode'] ?? ($prev['mode'] ?? ''));
        $row['error'] = (string) ($probe['error'] ?? '');
        if (array_key_exists('on', $probe) && $probe['on'] !== null) {
            $row['on'] = $probe['on'];
        }
        if (!empty($probe['source_id'])) {
            $row['source_id'] = (string) $probe['source_id'];
            $row['source_name'] = (string) ($probe['source_name'] ?? '');
        }
        $mac = $this->neighborMac($host);
        if ($mac !== '') {
            $row['mac'] = $mac;
        }
        $row['seen_at'] = time();
        $store['tvs'][$id] = $row;
        $this->save($store);

        return ['ok' => true, 'tv' => $this->publicRow($row), 'probe' => $probe];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitPin(string $id, string $pin): array
    {
        $id = trim($id);
        $pin = preg_replace('/\D+/', '', $pin) ?? '';
        $store = $this->load();
        $row = is_array($store['tvs'][$id] ?? null) ? $store['tvs'][$id] : null;
        if ($row === null) {
            return ['ok' => false, 'error' => 'Add the TV first'];
        }
        if (strlen($pin) < 4) {
            return ['ok' => false, 'error' => 'Enter the code shown on the TV'];
        }
        $host = (string) ($row['host'] ?? '');
        // Firmware 9 compares the code as a number. A quoted code connects and
        // then comes back as "not this code", which used to be reported as accepted.
        $result = $this->exchange($host, [
            ['topic' => 'authenticationcode', 'payload' => json_encode(['authNum' => (int) $pin]) ?: ''],
        ], 12.0);
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'The TV did not accept the code'), 'tv' => $this->publicRow($row)];
        }
        $parsed = self::interpretMessages(is_array($result['messages'] ?? null) ? $result['messages'] : []);
        $row = $this->mergeRead($row, $parsed, (string) ($result['mode'] ?? ''));
        $pinResult = $parsed['pin_result'];
        if ($pinResult !== null && $pinResult !== 1) {
            $row['needs_pin'] = true;
            $row['error'] = 'That code was not accepted';
            $row = $this->persist($id, $row);

            return [
                'ok' => false,
                'error' => 'That code was not accepted. Tap Show code and enter the code on the TV now.',
                'tv' => $this->publicRow($row),
            ];
        }
        if (trim((string) ($row['token'] ?? '')) === '' && $pinResult === 1) {
            $again = $this->exchange($host, [[
                'topic' => 'gettoken',
                'payload' => '{"refreshtoken":""}',
            ]], 6.0);
            if (!empty($again['ok'])) {
                $againParsed = self::interpretMessages(is_array($again['messages'] ?? null) ? $again['messages'] : []);
                $row = $this->mergeRead($row, $againParsed, (string) ($again['mode'] ?? ''));
            }
        }
        if (trim((string) ($row['token'] ?? '')) === '') {
            $row['needs_pin'] = true;
            $row['error'] = 'The TV did not take that code';
            $row = $this->persist($id, $row);

            return [
                'ok' => false,
                'error' => 'The TV did not take that code. Open the gear, tap Show code, and enter the new one.',
                'tv' => $this->publicRow($row),
            ];
        }
        $row['needs_pin'] = false;
        $row['error'] = '';
        $row['connected'] = true;
        $row = $this->persist($id, $row);
        if ($this->lock(true)) {
            try {
                $row = $this->readRow($row, 3.0);
                $row = $this->persist($id, $row);
            } finally {
                $this->unlock();
            }
        }

        return ['ok' => true, 'tv' => $this->publicRow($row)];
    }

    /**
     * Ask the set to show one code. On does not call this: repeating it
     * replaces the code already on the screen.
     *
     * @return array<string, mixed>
     */
    public function requestCode(string $id): array
    {
        $id = trim($id);
        $store = $this->load();
        $row = is_array($store['tvs'][$id] ?? null) ? $store['tvs'][$id] : null;
        if ($row === null) {
            return ['ok' => false, 'error' => 'Add the TV first'];
        }
        if (trim((string) ($row['token'] ?? '')) !== '') {
            return ['ok' => true, 'tv' => $this->publicRow($row)];
        }
        $locked = $this->lock(true);
        try {
            $row = $this->readRow($row, 3.5, true);
            $readOk = !empty($row['_read_ok']);
            if (trim((string) ($row['token'] ?? '')) === '') {
                $row['needs_pin'] = true;
            }
            $row = $this->persist($id, $row);

            return ['ok' => $readOk, 'tv' => $this->publicRow($row)];
        } finally {
            if ($locked) {
                $this->unlock();
            }
        }
    }

    public function remove(string $id): bool
    {
        $id = trim($id);
        $store = $this->load();
        if (!isset($store['tvs'][$id])) {
            return false;
        }
        unset($store['tvs'][$id]);
        $this->save($store);

        return true;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function command(string $id, string $action, array $input = []): array
    {
        $action = strtolower(trim($action));
        $store = $this->load();
        $row = is_array($store['tvs'][$id] ?? null) ? $store['tvs'][$id] : null;
        if ($row === null) {
            return ['ok' => false, 'error' => 'That TV is not added'];
        }
        $host = (string) ($row['host'] ?? '');
        if (!in_array($action, ['on', 'off', 'toggle', 'input'], true)) {
            return ['ok' => false, 'error' => 'That TV command is not supported'];
        }
        $wantOn = $action === 'on' || $action === 'input' || ($action === 'toggle' && empty($row['on']));
        if ($action === 'off') {
            $wantOn = false;
        }
        $inputId = trim((string) ($input['input'] ?? $input['source_id'] ?? ''));
        if ($action === 'input' && $inputId === '') {
            return ['ok' => false, 'error' => 'Pick an input'];
        }
        $locked = $this->lock(true);
        try {
            $row = $this->readRow($row, 3.5);
            if (empty($row['_read_ok'])) {
                $this->wake($row);
                $row = $this->readRow($row, 4.0);
            }
            if (empty($row['_read_ok'])) {
                $row = $this->persist($id, $row);

                return ['ok' => false, 'error' => (string) ($row['error'] ?? 'The TV did not answer'), 'tv' => $this->publicRow($row)];
            }
            $on = $row['_read_on'] ?? null;
            if ($action !== 'input' && $on !== null && $on === $wantOn && $inputId === '') {
                $row = $this->persist($id, $row);

                return ['ok' => true, 'on' => $on, 'tv' => $this->publicRow($row)];
            }
            if (!empty($row['needs_pin'])) {
                $row = $this->persist($id, $row);

                return ['ok' => false, 'error' => 'Enter the code shown on the TV', 'tv' => $this->publicRow($row)];
            }
            if ($on === null && $action !== 'input') {
                $heard = trim((string) ($row['heard'] ?? ''));
                if (trim((string) ($row['token'] ?? '')) === '') {
                    $row['needs_pin'] = true;
                    $row = $this->persist($id, $row);

                    return [
                        'ok' => false,
                        'error' => 'Open the gear, tap Show code, and enter the code from the TV.',
                        'tv' => $this->publicRow($row),
                    ];
                }
                $row = $this->persist($id, $row);
                $error = $heard !== ''
                    ? ('The TV sent: ' . $heard)
                    : 'The TV connected but sent no state.';

                return ['ok' => false, 'error' => $error, 'tv' => $this->publicRow($row)];
            }
            $publishes = [];
            $sentPower = false;
            if ($on !== null && $on !== $wantOn) {
                $publishes[] = ['topic' => 'sendkey', 'payload' => 'KEY_POWER'];
                $sentPower = true;
            }
            if ($inputId !== '' && $wantOn) {
                $name = self::sourceName(is_array($row['sources'] ?? null) ? $row['sources'] : [], $inputId);
                $encoded = json_encode([
                    'sourceid' => $inputId,
                    'sourcename' => $name,
                    'displayname' => $name,
                ], JSON_UNESCAPED_SLASHES);
                $publishes[] = ['topic' => 'changesource', 'payload' => $encoded ?: ''];
                $row['source_id'] = $inputId;
                $row['source_name'] = $name;
            }
            if ($publishes !== []) {
                $sentPrefer = trim((string) ($row['token'] ?? '')) !== '' ? 'token' : trim((string) ($row['mode'] ?? ''));
                $sent = $this->exchange($host, $publishes, 2.5, $sentPrefer);
                if (!($sent['ok'] ?? false)) {
                    $row = $this->persist($id, $row);

                    return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'The TV did not take the command'), 'tv' => $this->publicRow($row)];
                }
                $again = self::interpretMessages(is_array($sent['messages'] ?? null) ? $sent['messages'] : []);
                if ($again['sources'] !== []) {
                    $row['sources'] = $again['sources'];
                }
                if ($sentPower) {
                    // The retained broadcast is still the old picture until the set wakes.
                    $row['on'] = $wantOn;
                } elseif ($again['on'] !== null) {
                    $row['on'] = $again['on'];
                }
                if ($again['source_id'] !== '') {
                    $row['source_id'] = $again['source_id'];
                    $row['source_name'] = $again['source_name'];
                }
            }
            $row['connected'] = true;
            $row['needs_pin'] = false;
            $row['error'] = '';
            $row['seen_at'] = time();
            $row['state_at'] = time();
            $row = $this->persist($id, $row);

            return ['ok' => true, 'on' => $row['on'] ?? null, 'tv' => $this->publicRow($row)];
        } finally {
            if ($locked) {
                $this->unlock();
            }
        }
    }

    /**
     * Read retained power and the input list when the saved picture is old.
     * The automation runner calls this so a remote or Apple Home change is visible.
     *
     * @return array<string, mixed>
     */
    public function refreshStale(int $maxAge = 15): array
    {
        $store = $this->load();
        if ($store['tvs'] === []) {
            return ['ok' => true, 'tvs' => []];
        }
        $now = time();
        $due = false;
        foreach ($store['tvs'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $at = (int) ($row['state_at'] ?? 0);
            if ($maxAge <= 0 || $at <= 0 || ($now - $at) >= $maxAge) {
                $due = true;
                break;
            }
        }
        if (!$due) {
            return ['ok' => true, 'tvs' => $this->homeRows()];
        }
        if (!$this->lock(false)) {
            return ['ok' => true, 'skipped' => true, 'tvs' => $this->homeRows()];
        }
        try {
            $store = $this->load();
            $changed = false;
            foreach ($store['tvs'] as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $at = (int) ($row['state_at'] ?? 0);
                if ($maxAge > 0 && $at > 0 && ($now - $at) < $maxAge) {
                    continue;
                }
                $store['tvs'][$id] = $this->withoutReadKeys($this->readRow($row, 1.8));
                $changed = true;
            }
            if ($changed) {
                $this->save($store);
            }

            return ['ok' => true, 'tvs' => $this->homeRows()];
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function readRow(array $row, float $wait, bool $askPin = false): array
    {
        $host = (string) ($row['host'] ?? '');
        $prefer = trim((string) ($row['token'] ?? '')) !== '' ? 'token' : trim((string) ($row['mode'] ?? ''));
        $uuid = $this->clientUuid();
        $login = json_encode([
            'type' => 'login',
            'tvLogin' => false,
            'tvDeviceId' => '',
            'tvCountry' => '',
            'mobileLogin' => true,
            'mobileDeviceId' => $uuid,
        ], JSON_UNESCAPED_SLASHES) ?: '';
        $state = $this->exchange($host, [
            ['topic' => 'login_each_other_info', 'payload' => $login],
            ['topic' => 'gettvstate', 'payload' => ''],
            ['topic' => 'sourcelist', 'payload' => ''],
        ], $wait, $prefer !== '' && $prefer !== 'legacy' ? $prefer : '');
        $row['state_at'] = time();
        if (!($state['ok'] ?? false)) {
            $row['connected'] = false;
            $row['error'] = (string) ($state['error'] ?? 'The TV did not answer');
            $row['heard'] = '';
            $row['_read_on'] = null;
            $row['_read_ok'] = false;

            return $row;
        }
        $messages = is_array($state['messages'] ?? null) ? $state['messages'] : [];
        $parsed = self::interpretMessages($messages);
        $row = $this->mergeRead($row, $parsed, (string) ($state['mode'] ?? ''));
        if ($parsed['on'] === null && trim((string) ($row['refresh_token'] ?? '')) !== '') {
            $tokenRead = $this->exchange($host, [[
                'topic' => 'gettoken',
                'payload' => json_encode(['refreshtoken' => (string) $row['refresh_token']], JSON_UNESCAPED_SLASHES) ?: '',
            ]], min(3.0, $wait));
            if (!empty($tokenRead['ok'])) {
                $tokenMessages = is_array($tokenRead['messages'] ?? null) ? $tokenRead['messages'] : [];
                $messages = array_merge($messages, $tokenMessages);
                $tokenParsed = self::interpretMessages($tokenMessages);
                $row = $this->mergeRead($row, $tokenParsed, (string) ($tokenRead['mode'] ?? ''));
                if ($tokenParsed['on'] !== null) {
                    $parsed = $tokenParsed;
                } elseif (($tokenParsed['token'] ?? '') !== '') {
                    $again = $this->exchange($host, [
                        ['topic' => 'gettvstate', 'payload' => ''],
                        ['topic' => 'sourcelist', 'payload' => ''],
                    ], $wait, 'token');
                    if (!empty($again['ok'])) {
                        $againMessages = is_array($again['messages'] ?? null) ? $again['messages'] : [];
                        $messages = array_merge($messages, $againMessages);
                        $againParsed = self::interpretMessages($againMessages);
                        $row = $this->mergeRead($row, $againParsed, (string) ($again['mode'] ?? ''));
                        if ($againParsed['on'] !== null || $againParsed['sources'] !== []) {
                            $parsed = $againParsed;
                        }
                    }
                }
            }
        }
        if (
            $askPin
            && $parsed['on'] === null
            && $parsed['sources'] === []
            && trim((string) ($row['token'] ?? '')) === ''
            && $messages === []
        ) {
            $pin = $this->exchange($host, [[
                'topic' => 'vidaa_app_connect',
                'payload' => json_encode([
                    'app_version' => 2,
                    'connect_result' => 0,
                    'device_type' => 'Mobile App',
                ], JSON_UNESCAPED_SLASHES) ?: '',
            ]], min(4.0, max($wait, 3.0)));
            if (!empty($pin['ok'])) {
                $pinMessages = is_array($pin['messages'] ?? null) ? $pin['messages'] : [];
                $messages = array_merge($messages, $pinMessages);
                $pinParsed = self::interpretMessages($pinMessages);
                $row = $this->mergeRead($row, $pinParsed, (string) ($pin['mode'] ?? ''));
                // Publishing this request puts a code on the screen. The set often
                // does that without sending the authentication message in time.
                if (($pinParsed['token'] ?? '') === '' && $pinParsed['on'] === null && $pinParsed['sources'] === []) {
                    $row['needs_pin'] = true;
                }
                if ($pinParsed['on'] !== null) {
                    $parsed = $pinParsed;
                }
            }
        }
        $row['heard'] = self::heardSummary($messages);
        $row['_read_on'] = $parsed['on'];
        $row['_read_ok'] = true;

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function mergeRead(array $row, array $parsed, string $mode): array
    {
        if (($parsed['token'] ?? '') !== '') {
            $row['token'] = $parsed['token'];
        }
        if (($parsed['refresh_token'] ?? '') !== '') {
            $row['refresh_token'] = $parsed['refresh_token'];
        }
        if (($parsed['sources'] ?? []) !== []) {
            $row['sources'] = $parsed['sources'];
        }
        if (($parsed['on'] ?? null) !== null) {
            $row['on'] = $parsed['on'];
        }
        if (($parsed['source_id'] ?? '') !== '') {
            $row['source_id'] = $parsed['source_id'];
            $row['source_name'] = $parsed['source_name'] ?? '';
        }
        if ($mode !== '') {
            $row['mode'] = $mode;
        }
        $row['connected'] = true;
        $row['error'] = '';
        $row['seen_at'] = time();
        $row['state_at'] = time();
        if (!empty($parsed['needs_pin']) && trim((string) ($row['token'] ?? '')) === '' && ($parsed['token'] ?? '') === '') {
            $row['needs_pin'] = true;
        } elseif (($parsed['sources'] ?? []) !== [] || ($parsed['on'] ?? null) !== null || ($parsed['token'] ?? '') !== '') {
            $row['needs_pin'] = false;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function persist(string $id, array $row): array
    {
        $row = $this->withoutReadKeys($row);
        $store = $this->load();
        $store['tvs'][$id] = $row;
        $this->save($store);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withoutReadKeys(array $row): array
    {
        unset($row['_read_on'], $row['_read_ok']);

        return $row;
    }

    private function lock(bool $wait): bool
    {
        if (is_resource($this->lockFh)) {
            return true;
        }
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $wait;
        }
        $fh = fopen($dir . '/hisense.lock', 'c');
        if ($fh === false) {
            return $wait;
        }
        $ok = flock($fh, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB));
        if (!$ok) {
            fclose($fh);

            return false;
        }
        $this->lockFh = $fh;

        return true;
    }

    private function unlock(): void
    {
        if (!is_resource($this->lockFh)) {
            $this->lockFh = null;

            return;
        }
        flock($this->lockFh, LOCK_UN);
        fclose($this->lockFh);
        $this->lockFh = null;
    }

    /**
     * Rows for the Home dashboard and automations. No network.
     *
     * @return list<array<string, mixed>>
     */
    public function homeRows(): array
    {
        $out = [];
        foreach ($this->load()['tvs'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = $this->publicRow($row);
        }

        return $out;
    }

    /**
     * @return array{client_id: string, username: string, password: string}
     */
    public static function dynamicCredentials(string $uuid, int $timestamp, string $brand = 'his'): array
    {
        $race = strtoupper(substr(md5(self::PATTERN . '$' . $uuid), 0, 6));
        $clientId = $uuid . '$' . $brand . '$' . $race . '_vidaacommon_001';
        $username = $brand . '$' . ($timestamp ^ self::XOR_CONST);
        $sum = 0;
        foreach (str_split((string) $timestamp) as $digit) {
            $sum += (int) $digit;
        }
        $value = $brand . ($sum % 10) . self::VALUE_SUFFIX;
        $valueMd5 = strtoupper(substr(md5($value), 0, 6));
        $password = strtoupper(md5($timestamp . '$' . $valueMd5));

        return [
            'client_id' => $clientId,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{on: ?bool, source_id: string, source_name: string, sources: list<array{id: string, name: string}>, token: string, refresh_token: string, needs_pin: bool, pin_result: ?int}
     */
    public static function interpretMessages(array $messages): array
    {
        $on = null;
        $sourceId = '';
        $sourceName = '';
        $sources = [];
        $token = '';
        $refreshToken = '';
        $needsPin = false;
        $pinResult = null;
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $topic = strtolower((string) ($message['topic'] ?? ''));
            $rawPayload = trim((string) ($message['payload'] ?? ''));
            $json = self::decodePayload($message['payload'] ?? '');
            if (!is_array($json) && $rawPayload !== '' && preg_match('/statetype\W+([A-Za-z0-9_]+)/i', $rawPayload, $stateMatch) === 1) {
                $json = ['statetype' => $stateMatch[1]];
            }
            if (str_contains($topic, 'sourcelist') && is_array($json)) {
                $parsed = self::parseSources($json);
                if ($parsed !== []) {
                    $sources = $parsed;
                }
            }
            if (is_array($json)) {
                $access = trim((string) ($json['accesstoken'] ?? $json['accessToken'] ?? $json['access_token'] ?? ''));
                if ($access !== '') {
                    $token = $access;
                }
                $refresh = trim((string) ($json['refreshtoken'] ?? $json['refreshToken'] ?? $json['refresh_token'] ?? ''));
                if ($refresh !== '') {
                    $refreshToken = $refresh;
                }
                if (
                    str_contains($topic, 'authenticationcode')
                    && !str_contains($topic, 'close')
                    && !str_contains($topic, 'toast')
                    && array_key_exists('result', $json)
                ) {
                    $pinResult = (int) $json['result'];
                }
            }
            if (str_contains($topic, 'authentication') && !str_contains($topic, 'authenticationcode')) {
                $needsPin = true;
            }
            $state = is_array($json) ? (self::flattenState($json) ?? $json) : ['statetype' => $rawPayload];
            $power = self::powerFromState($state);
            if ($power !== null) {
                $on = $power;
            }
            if (is_array($state)) {
                $sid = trim((string) ($state['sourceid'] ?? ''));
                if ($sid !== '') {
                    $sourceId = $sid;
                    $sourceName = trim((string) ($state['displayname'] ?? $state['sourcename'] ?? $state['channel_name'] ?? $sourceName));
                }
            }
        }

        return [
            'on' => $on,
            'source_id' => $sourceId,
            'source_name' => $sourceName,
            'sources' => $sources,
            'token' => $token,
            'refresh_token' => $refreshToken,
            'needs_pin' => $needsPin && $sources === [] && $token === '',
            'pin_result' => $pinResult,
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $json
     * @return list<array{id: string, name: string}>
     */
    public static function parseSources(array $json): array
    {
        $list = [];
        if (array_is_list($json)) {
            $list = $json;
        } else {
            foreach (['sourcelist', 'source_list', 'sourceList', 'sources'] as $key) {
                if (is_array($json[$key] ?? null)) {
                    $list = $json[$key];
                    break;
                }
            }
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['sourceid'] ?? $item['id'] ?? ''));
            $name = trim((string) ($item['displayname'] ?? $item['sourcename'] ?? $item['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function powerFromState(array $state): ?bool
    {
        foreach (['power', 'powerstate', 'power_state', 'tvpower'] as $key) {
            if (!array_key_exists($key, $state)) {
                continue;
            }
            $value = $state[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value !== 0;
            }
            $text = strtolower(trim((string) $value));
            if (in_array($text, ['on', 'true', '1', 'wakeup', 'normal'], true)) {
                return true;
            }
            if (in_array($text, ['off', 'false', '0', 'standby', 'sleep'], true)) {
                return false;
            }
        }
        $type = strtolower(trim((string) ($state['statetype'] ?? '')));
        if ($type === '') {
            return null;
        }
        // Firmware 9 reports power only as statetype. fake_sleep_0 is standby.
        // fake_sleep_1 is the set waking up, then remote_launcher (home) or
        // sourceswitch / livetv once a picture is showing. The remote and
        // Apple Home publish the same retained broadcast.
        if (in_array($type, ['fake_sleep_0', 'standby', 'sleep', 'poweroff', 'shutdown', 'off'], true)) {
            return false;
        }
        if (preg_match('/^fake_sleep_(\d+)$/', $type, $wake) === 1) {
            return (int) $wake[1] !== 0;
        }
        if (
            in_array($type, [
                'remote_launcher', 'remote_setting', 'sourceswitch', 'sourcechange',
                'livetv', 'app', 'appopen', 'wakeup', 'poweron', 'normal',
                'volumechange', 'volumemute',
            ], true)
            || str_starts_with($type, 'source')
        ) {
            return true;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>|null
     */
    private static function flattenState(array $json): ?array
    {
        foreach (['statetype', 'power', 'powerstate', 'power_state', 'tvpower'] as $key) {
            if (array_key_exists($key, $json)) {
                return $json;
            }
        }
        foreach ($json as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::flattenState($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Short, secret-free note of what the TV actually published.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function heardSummary(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $topic = (string) ($message['topic'] ?? '');
            $topic = preg_replace('#^/remoteapp/mobile/#', '', $topic) ?? $topic;
            $payload = trim((string) ($message['payload'] ?? ''));
            $payload = preg_replace('/"(?:access|refresh)?token"\s*:\s*"[^"]*"/i', '"token":"…"', $payload) ?? $payload;
            $payload = preg_replace('/\s+/', ' ', $payload) ?? $payload;
            if (strlen($payload) > 70) {
                $payload = substr($payload, 0, 70) . '…';
            }
            $parts[] = $topic . ($payload !== '' ? ' ' . $payload : ' (empty)');
            if (count($parts) >= 2) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(string $host): array
    {
        $host = self::normalizeHost($host);
        $open = $this->portOpen($host);
        $base = [
            'host' => $host,
            'port_open' => $open,
            'connected' => false,
            'needs_pin' => false,
            'name' => 'Hisense TV',
            'sources' => [],
            'on' => null,
            'source_id' => '',
            'source_name' => '',
            'mode' => '',
            'error' => $open ? '' : 'Nothing answered on port 36669',
        ];
        if ($host === '') {
            $base['error'] = 'Enter the TV IP address';

            return $base;
        }
        if (!$open && $this->transport === null) {
            return $base;
        }
        $asks = [
            ['topic' => 'sourcelist', 'payload' => ''],
            ['topic' => 'gettvstate', 'payload' => ''],
        ];
        if ($this->tokenFor($host) === '') {
            $asks[] = ['topic' => 'vidaa_app_connect', 'payload' => json_encode([
                'app_version' => 2,
                'connect_result' => 0,
                'device_type' => 'Mobile App',
            ], JSON_UNESCAPED_SLASHES) ?: ''];
        }
        $result = $this->exchange($host, $asks, 4.0);
        if (!($result['ok'] ?? false)) {
            $base['error'] = (string) ($result['error'] ?? 'The TV broker refused the connection');
            $base['port_open'] = $open;

            return $base;
        }
        $parsed = self::interpretMessages(is_array($result['messages'] ?? null) ? $result['messages'] : []);
        $base['connected'] = true;
        $base['port_open'] = true;
        $base['mode'] = (string) ($result['mode'] ?? '');
        $base['sources'] = $parsed['sources'];
        $base['on'] = $parsed['on'];
        $base['source_id'] = $parsed['source_id'];
        $base['source_name'] = $parsed['source_name'];
        $base['needs_pin'] = $parsed['needs_pin'] && $parsed['sources'] === [];
        $base['error'] = $base['needs_pin'] ? 'The TV is showing a code. Enter it to finish.' : '';

        return $base;
    }

    /**
     * @param list<array{topic: string, payload: string}> $publishes
     * @return array<string, mixed>
     */
    private function exchange(string $host, array $publishes, float $wait, string $prefer = ''): array
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Enter the TV IP address'];
        }
        $uuid = $this->clientUuid();
        $errors = [];
        $askedState = false;
        foreach ($publishes as $publish) {
            if (in_array((string) ($publish['topic'] ?? ''), ['gettvstate', 'sourcelist'], true)) {
                $askedState = true;
            }
        }
        $profiles = $this->profiles($uuid, $this->tokenFor($host));
        if ($prefer !== '') {
            $picked = [];
            foreach ($profiles as $profile) {
                if ((string) ($profile['mode'] ?? '') === $prefer) {
                    $picked[] = $profile;
                }
            }
            foreach ($profiles as $profile) {
                if ((string) ($profile['mode'] ?? '') === $prefer) {
                    continue;
                }
                $picked[] = $profile;
                if (count($picked) >= 2) {
                    break;
                }
            }
            if ($picked !== []) {
                $profiles = $picked;
            }
        }
        $silent = null;
        foreach ($profiles as $profile) {
            $result = $this->transport !== null
                ? ($this->transport)($host, $profile, $publishes, $wait)
                : $this->mqttExchange($host, $profile, $publishes, $wait);
            if (!is_array($result)) {
                continue;
            }
            if (!empty($result['ok'])) {
                $result['mode'] = (string) ($profile['mode'] ?? '');
                $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
                if (!$askedState || $messages !== []) {
                    return $result;
                }
                $silent = $result;
                continue;
            }
            $errors[] = (string) ($result['error'] ?? 'connection failed');
        }
        if ($silent !== null) {
            return $silent;
        }

        return [
            'ok' => false,
            'error' => $errors[0] ?? 'The TV broker refused the connection',
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<array{topic: string, payload: string}> $publishes
     * @return array<string, mixed>
     */
    private function mqttExchange(string $host, array $profile, array $publishes, float $wait): array
    {
        $clientId = (string) ($profile['client_id'] ?? 'yarbo');
        $messages = [];
        try {
            $client = new MqttClient($host, self::PORT, $clientId, MqttClient::MQTT_3_1_1);
            $settings = (new ConnectionSettings())
                ->setUsername((string) ($profile['username'] ?? ''))
                ->setPassword((string) ($profile['password'] ?? ''))
                ->setConnectTimeout(max(1, (int) ceil(min(4.0, $wait))))
                ->setSocketTimeout(max(1, (int) ceil($wait)));
            if (!empty($profile['tls'])) {
                $settings = $settings
                    ->setUseTls(true)
                    ->setTlsVerifyPeer(false)
                    ->setTlsVerifyPeerName(false)
                    ->setTlsSelfSignedAllowed(true);
                $cert = (string) ($profile['cert'] ?? '');
                $key = (string) ($profile['key'] ?? '');
                if ($cert !== '' && $key !== '') {
                    $settings = $settings
                        ->setTlsClientCertificateFile($cert)
                        ->setTlsClientCertificateKeyFile($key);
                }
            }
            $client->connect($settings, true);
            $base = '/remoteapp/mobile/' . $clientId;
            foreach ([
                $base . '/ui_service/data/sourcelist',
                $base . '/ui_service/data/state',
                $base . '/ui_service/data/authentication',
                $base . '/ui_service/data/authenticationcode',
                $base . '/ui_service/data/authenticationcodeclose',
                $base . '/ui_service/data/authenticationcodetoast',
                $base . '/ui_service/data/tokenissuance',
                $base . '/ui_service/data/vidaa_app_connect',
                $base . '/platform_service/data/authentication',
                $base . '/platform_service/data/authenticationcode',
                $base . '/platform_service/data/tokenissuance',
                $base . '/platform_service/data/gettoken',
                $base . '/platform_service/data/state',
                $base . '/platform_service/data/sourcelist',
                $base . '/ui_service/data/login_each_other_info',
                '/remoteapp/mobile/broadcast/ui_service/state',
                '/remoteapp/mobile/broadcast/ui_service/data/state',
                '/remoteapp/mobile/broadcast/platform_service/actions/tvsleep',
            ] as $topic) {
                $client->subscribe($topic, static function (string $topic, string $payload) use (&$messages): void {
                    $messages[] = ['topic' => $topic, 'payload' => $payload];
                }, 0);
            }
            $wantSources = false;
            $wantState = false;
            $doingPin = false;
            foreach ($publishes as $publish) {
                $asked = (string) ($publish['topic'] ?? '');
                if ($asked === 'sourcelist') {
                    $wantSources = true;
                }
                if ($asked === 'gettvstate') {
                    $wantState = true;
                }
                if ($asked === 'authenticationcode') {
                    $doingPin = true;
                }
            }
            $started = microtime(true);
            // The retained broadcast arrives on subscribe, before any request.
            $client->loopOnce($started, true);
            foreach ($publishes as $publish) {
                $action = (string) ($publish['topic'] ?? '');
                $services = $action === 'sendkey' ? ['remote_service'] : ['ui_service'];
                if (in_array($action, ['gettvstate', 'sourcelist', 'gettoken', 'login_each_other_info'], true)) {
                    $services[] = 'platform_service';
                }
                foreach ($services as $service) {
                    $path = '/remoteapp/tv/' . $service . '/' . $clientId . '/actions/' . $action;
                    $client->publish($path, (string) ($publish['payload'] ?? ''), 0);
                    if ($action === 'gettoken' && $service === 'platform_service') {
                        $client->publish('/remoteapp/tv/platform_service/' . $clientId . '/data/gettoken', (string) ($publish['payload'] ?? ''), 0);
                    }
                }
            }
            $pinFollowUp = false;
            $deadline = microtime(true) + $wait;
            while (microtime(true) < $deadline) {
                $client->loopOnce($started, true);
                $parsed = self::interpretMessages($messages);
                if ($doingPin && !$pinFollowUp && $parsed['pin_result'] === 1) {
                    $pinFollowUp = true;
                    $client->publish(
                        '/remoteapp/tv/platform_service/' . $clientId . '/data/gettoken',
                        '{"refreshtoken":""}',
                        0
                    );
                    $client->publish(
                        '/remoteapp/tv/ui_service/' . $clientId . '/actions/authenticationcodeclose',
                        '',
                        0
                    );
                }
                if ($doingPin && $parsed['pin_result'] !== null && $parsed['pin_result'] !== 1) {
                    break;
                }
                $gotSources = !$wantSources || $parsed['sources'] !== [];
                $gotState = !$wantState || $parsed['on'] !== null;
                if ($gotSources && $gotState) {
                    break;
                }
                if (!$wantSources && !$wantState && ($parsed['on'] !== null || $parsed['token'] !== '')) {
                    break;
                }
            }
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }

            return ['ok' => true, 'messages' => $messages];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::friendlyMqttError($e->getMessage())];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profiles(string $uuid, string $token = ''): array
    {
        $dynamic = self::dynamicCredentials($uuid, time());
        [$cert, $key] = $this->clientCert();
        $legacyId = $uuid . '$normal';
        $token = trim($token);
        $paired = $token === '' ? [] : [[
            'mode' => 'token',
            'client_id' => $dynamic['client_id'],
            'username' => $dynamic['username'],
            'password' => $token,
            'tls' => true,
            'cert' => $cert,
            'key' => $key,
        ]];
        $vidaa = [
            'mode' => 'vidaa',
            'client_id' => $dynamic['client_id'],
            'username' => $dynamic['username'],
            'password' => $dynamic['password'],
            'tls' => true,
            'cert' => $cert,
            'key' => $key,
        ];
        $legacyTls = [
            'mode' => 'legacy-tls',
            'client_id' => $legacyId,
            'username' => self::LEGACY_USER,
            'password' => self::LEGACY_PASS,
            'tls' => true,
            'cert' => $cert,
            'key' => $key,
        ];
        $legacy = [
            'mode' => 'legacy',
            'client_id' => $legacyId,
            'username' => self::LEGACY_USER,
            'password' => self::LEGACY_PASS,
            'tls' => false,
        ];

        // Firmware 9 (the 50A5LE and other 2024–2026 sets) refuses a login that
        // does not present the VIDAA app certificate. Try that first so a
        // rejected legacy login does not pop the “app is too old” notice.
        return array_merge($paired, [$vidaa, $legacyTls, $legacy]);
    }

    private function tokenFor(string $host): string
    {
        $id = self::ID_PREFIX . self::normalizeHost($host);
        $row = $this->load()['tvs'][$id] ?? null;

        return is_array($row) ? trim((string) ($row['token'] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function wake(array $row): void
    {
        $mac = strtolower(trim((string) ($row['mac'] ?? '')));
        if ($mac === '') {
            $mac = $this->neighborMac((string) ($row['host'] ?? ''));
        }
        $hex = preg_replace('/[^0-9a-f]/', '', $mac) ?? '';
        if (strlen($hex) !== 12) {
            return;
        }
        $bin = hex2bin($hex);
        if ($bin === false) {
            return;
        }
        $packet = str_repeat(chr(0xff), 6) . str_repeat($bin, 16);
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return;
        }
        @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        @socket_sendto($socket, $packet, strlen($packet), 0, '255.255.255.255', 9);
        @socket_close($socket);
        usleep(400000);
    }

    private function neighborMac(string $host): string
    {
        $raw = @shell_exec('ip neigh show ' . escapeshellarg($host) . ' 2>/dev/null') ?: '';
        if (preg_match('/lladdr\s+([0-9a-f:]{17})/i', $raw, $match) === 1) {
            return strtolower($match[1]);
        }

        return '';
    }

    private function portOpen(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if ($this->transport !== null) {
            return true;
        }
        $errno = 0;
        $err = '';
        $socket = @fsockopen($host, self::PORT, $errno, $err, 0.6);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * @return list<string>
     */
    private function scanPrefix(string $prefix): array
    {
        if ($this->portScanner !== null) {
            $found = ($this->portScanner)($prefix);

            return is_array($found) ? array_values(array_filter(array_map('strval', $found))) : [];
        }
        $open = [];
        $ips = [];
        for ($i = 1; $i <= 254; $i++) {
            $ips[] = $prefix . '.' . $i;
        }
        foreach (array_chunk($ips, 48) as $chunk) {
            $sockets = [];
            foreach ($chunk as $ip) {
                $sock = @stream_socket_client(
                    'tcp://' . $ip . ':' . self::PORT,
                    $errno,
                    $err,
                    0.01,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
                );
                if (is_resource($sock)) {
                    stream_set_blocking($sock, false);
                    $sockets[(int) $sock] = ['sock' => $sock, 'ip' => $ip];
                }
            }
            $deadline = microtime(true) + 0.35;
            while ($sockets !== [] && microtime(true) < $deadline) {
                $write = [];
                foreach ($sockets as $item) {
                    $write[] = $item['sock'];
                }
                $read = [];
                $except = [];
                $ready = @stream_select($read, $write, $except, 0, 100000);
                if ($ready === false) {
                    break;
                }
                foreach ($write as $sock) {
                    $key = (int) $sock;
                    $ip = (string) ($sockets[$key]['ip'] ?? '');
                    $peer = @stream_socket_get_name($sock, true);
                    fclose($sock);
                    unset($sockets[$key]);
                    if (is_string($peer) && $peer !== '' && $ip !== '') {
                        $open[] = $ip;
                    }
                }
            }
            foreach ($sockets as $item) {
                if (is_resource($item['sock'] ?? null)) {
                    fclose($item['sock']);
                }
            }
        }

        return $open;
    }

    /**
     * @return list<string>
     */
    private function lanPrefixes(): array
    {
        $raw = @shell_exec('ip -4 -o addr show scope global 2>/dev/null') ?: '';
        preg_match_all('/inet\s+(\d+\.\d+\.\d+)\.\d+\/(\d+)/', $raw, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $row) {
            $prefix = (string) ($row[1] ?? '');
            $bits = (int) ($row[2] ?? 0);
            if ($prefix === '' || $bits < 22 || $bits > 24) {
                continue;
            }
            if (str_starts_with($prefix, '172.17.') || str_starts_with($prefix, '172.18.') || str_starts_with($prefix, '100.')) {
                continue;
            }
            $out[$prefix] = true;
            if (count($out) >= 2) {
                break;
            }
        }

        return array_keys($out);
    }

    private function clientUuid(): string
    {
        $store = $this->load();
        $uuid = strtolower(trim((string) ($store['client_uuid'] ?? '')));
        if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $uuid) === 1) {
            return $uuid;
        }
        $parts = str_split(bin2hex(random_bytes(6)), 2);
        $uuid = implode(':', $parts);
        $store['client_uuid'] = $uuid;
        $this->save($store);

        return $uuid;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function clientCert(): array
    {
        $bundledCert = $this->projectRoot . '/resources/hisense/vidaa_client.pem';
        $bundledKey = $this->projectRoot . '/resources/hisense/vidaa_client.key';
        if (is_file($bundledCert) && is_file($bundledKey)) {
            return [$bundledCert, $bundledKey];
        }
        $cert = $this->projectRoot . '/data/hisense-client.pem';
        $key = $this->projectRoot . '/data/hisense-client.key';
        if (is_file($cert) && is_file($key)) {
            return [$cert, $key];
        }
        if (!is_dir($this->projectRoot . '/data')) {
            @mkdir($this->projectRoot . '/data', 0775, true);
        }
        $cmd = 'openssl req -x509 -newkey rsa:2048 -keyout '
            . escapeshellarg($key)
            . ' -out '
            . escapeshellarg($cert)
            . ' -days 3650 -nodes -subj '
            . escapeshellarg('/C=CN/ST=shandong/O=hh/OU=multimedia/CN=VidaaAppAndroidV01')
            . ' 2>/dev/null';
        @exec($cmd);
        if (is_file($cert) && is_file($key)) {
            return [$cert, $key];
        }

        return ['', ''];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $sources = [];
        foreach (is_array($row['sources'] ?? null) ? $row['sources'] : [] as $source) {
            if (!is_array($source)) {
                continue;
            }
            $sid = trim((string) ($source['id'] ?? ''));
            $name = trim((string) ($source['name'] ?? ''));
            if ($sid === '' || $name === '') {
                continue;
            }
            $sources[] = ['id' => $sid, 'name' => $name];
        }
        $currentId = trim((string) ($row['source_id'] ?? ''));
        $currentName = trim((string) ($row['source_name'] ?? ''));
        $knownIds = array_map(static fn (array $source): string => (string) $source['id'], $sources);
        if ($currentId !== '' && !in_array($currentId, $knownIds, true)) {
            array_unshift($sources, [
                'id' => $currentId,
                'name' => $currentName !== '' ? $currentName : $currentId,
            ]);
        }
        $knownOn = array_key_exists('on', $row) && $row['on'] !== null;
        if (!empty($row['needs_pin'])) {
            $status = 'Code on the TV';
        } elseif (empty($row['connected']) && !empty($row['error'])) {
            $status = (string) $row['error'];
        } elseif (!$knownOn) {
            $status = 'No state from the TV';
        } elseif (empty($row['on'])) {
            $status = 'Off';
        } elseif ($currentName !== '') {
            $status = 'On · ' . $currentName;
        } else {
            $status = 'On';
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? 'Hisense TV'),
            'kind' => 'tv',
            'source' => 'hisense',
            'vendor' => 'Hisense',
            'product' => 'VIDAA TV',
            'host' => (string) ($row['host'] ?? ''),
            'on' => $knownOn ? (bool) $row['on'] : null,
            'power_known' => $knownOn,
            'available' => !empty($row['connected']) || $status === '',
            'inputs' => $sources,
            'input' => (string) ($row['source_id'] ?? ''),
            'input_name' => (string) ($row['source_name'] ?? ''),
            'needs_pin' => !empty($row['needs_pin']),
            'paired' => trim((string) ($row['token'] ?? '')) !== '',
            'status' => $status,
            'detail' => trim((string) ($row['heard'] ?? '')),
            'has_on_off' => true,
        ];
    }

    /**
     * @param list<mixed> $sources
     */
    private static function sourceName(array $sources, string $id): string
    {
        foreach ($sources as $source) {
            if (is_array($source) && (string) ($source['id'] ?? '') === $id) {
                return (string) ($source['name'] ?? $id);
            }
        }

        return $id;
    }

    private static function decodePayload(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return $payload;
        }
        $text = trim((string) $payload);
        if ($text === '') {
            return null;
        }
        $json = json_decode($text, true);
        if (is_string($json)) {
            $again = json_decode($json, true);
            if (is_array($again)) {
                return $again;
            }
        }

        return is_array($json) ? $json : null;
    }

    private static function friendlyMqttError(string $message): string
    {
        $low = strtolower($message);
        if (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return 'The TV did not answer in time';
        }

        return 'The TV broker refused the connection';
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }

        return $host;
    }

    /**
     * @return array{client_uuid: string, tvs: array<string, array<string, mixed>>}
     */
    private function load(): array
    {
        $path = $this->storePath();
        $raw = is_file($path) ? file_get_contents($path) : false;
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $tvs = [];
        if (is_array($json) && is_array($json['tvs'] ?? null)) {
            foreach ($json['tvs'] as $id => $row) {
                if (is_array($row)) {
                    $tvs[(string) $id] = $row;
                }
            }
        }

        return [
            'client_uuid' => is_array($json) ? (string) ($json['client_uuid'] ?? '') : '',
            'tvs' => $tvs,
        ];
    }

    /**
     * @param array{client_uuid?: string, tvs?: array<string, mixed>} $store
     */
    private function save(array $store): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = [
            'client_uuid' => (string) ($store['client_uuid'] ?? ''),
            'tvs' => is_array($store['tvs'] ?? null) ? $store['tvs'] : [],
        ];
        file_put_contents(
            $this->storePath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }
}
