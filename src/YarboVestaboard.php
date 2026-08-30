<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Vestaboard Note (3×15): layout, encode, optional push via Local or Cloud API.
 * https://docs.vestaboard.com/docs/local-api/introduction
 * https://docs.vestaboard.com/docs/read-write-api/introduction/
 */
final class YarboVestaboard
{
    public const ROWS = 3;
    public const COLS = 15;
    public const MIN_SEND_GAP_SECONDS = 15.0;
    public const DEFAULT_HOST = 'vestaboard.local';
    public const DEFAULT_PORT = 7000;
    public const TRANSPORT_LOCAL = 'local';
    public const TRANSPORT_CLOUD = 'cloud';
    public const CLOUD_URL = 'https://cloud.vestaboard.com/';
    public const COLOR_RED = 63;
    public const COLOR_ORANGE = 64;
    public const COLOR_YELLOW = 65;
    public const COLOR_GREEN = 66;
    public const COLOR_BLUE = 67;

    private const HEAD_SHORT = [
        'None' => '',
        'Snow Blower' => 'SNOW BLOWER',
        'Leaf Blower' => 'LEAF BLOWER',
        'Lawn Mower' => 'MOWER',
        'Smart Cover' => 'SMART COVER',
        'Lawn Mower Pro' => 'MOWER PRO',
        'Trimmer' => 'TRIMMER',
        'Unknown' => '',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function dataDir(): string
    {
        return $this->projectRoot . '/data';
    }

    public function configPath(): string
    {
        return $this->dataDir() . '/vestaboard-config.json';
    }

    /**
     * @return array{
     *   enabled: bool,
     *   transport: string,
     *   host: string,
     *   port: int,
     *   api_key: string,
     *   cloud_token: string,
     *   last_hash: string,
     *   last_sent_at: ?string,
     *   last_error: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'enabled' => false,
            'transport' => self::TRANSPORT_LOCAL,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'api_key' => '',
            'cloud_token' => '',
            'last_hash' => '',
            'last_sent_at' => null,
            'last_error' => '',
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        $port = (int) ($decoded['port'] ?? self::DEFAULT_PORT);
        if ($port < 1 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }

        return [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'transport' => $this->normalizeTransport($decoded['transport'] ?? $decoded['vestaboard_transport'] ?? self::TRANSPORT_LOCAL),
            'host' => trim((string) ($decoded['host'] ?? self::DEFAULT_HOST)) ?: self::DEFAULT_HOST,
            'port' => $port,
            'api_key' => (string) ($decoded['api_key'] ?? ''),
            'cloud_token' => (string) ($decoded['cloud_token'] ?? ''),
            'last_hash' => (string) ($decoded['last_hash'] ?? ''),
            'last_sent_at' => isset($decoded['last_sent_at']) && is_string($decoded['last_sent_at'])
                ? $decoded['last_sent_at']
                : null,
            'last_error' => (string) ($decoded['last_error'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $dir = $this->dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $current = $this->load();
        $host = trim((string) ($input['host'] ?? $input['vestaboard_host'] ?? $current['host']));
        if ($host === '') {
            $host = self::DEFAULT_HOST;
        }
        $port = (int) ($input['port'] ?? $input['vestaboard_port'] ?? $current['port']);
        if ($port < 1 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }
        $keyFromInput = array_key_exists('api_key', $input) || array_key_exists('vestaboard_api_key', $input);
        $apiKey = $keyFromInput
            ? (string) ($input['api_key'] ?? $input['vestaboard_api_key'] ?? '')
            : $current['api_key'];
        if ($keyFromInput && $apiKey === '') {
            $apiKey = $current['api_key'];
        }
        $tokenFromInput = array_key_exists('cloud_token', $input) || array_key_exists('vestaboard_cloud_token', $input);
        $cloudToken = $tokenFromInput
            ? (string) ($input['cloud_token'] ?? $input['vestaboard_cloud_token'] ?? '')
            : $current['cloud_token'];
        if ($tokenFromInput && $cloudToken === '') {
            $cloudToken = $current['cloud_token'];
        }
        $enabled = array_key_exists('enabled', $input) || array_key_exists('vestaboard_enabled', $input)
            ? (bool) ($input['enabled'] ?? $input['vestaboard_enabled'])
            : $current['enabled'];
        $transport = array_key_exists('transport', $input) || array_key_exists('vestaboard_transport', $input)
            ? $this->normalizeTransport($input['transport'] ?? $input['vestaboard_transport'] ?? $current['transport'])
            : $current['transport'];

        $next = [
            'enabled' => $enabled,
            'transport' => $transport,
            'host' => $host,
            'port' => $port,
            'api_key' => $apiKey,
            'cloud_token' => $cloudToken,
            'last_hash' => (string) ($input['last_hash'] ?? $current['last_hash']),
            'last_sent_at' => $input['last_sent_at'] ?? $current['last_sent_at'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
        ];
        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $config = $this->load();

        return [
            'enabled' => $config['enabled'],
            'transport' => $config['transport'],
            'host' => $config['host'],
            'port' => $config['port'],
            'api_key_set' => $config['api_key'] !== '',
            'cloud_token_set' => $config['cloud_token'] !== '',
            'last_sent_at' => $config['last_sent_at'],
            'last_error' => $config['last_error'],
        ];
    }

    /**
     * Compact payload for the dashboard card. Does not call the Note.
     *
     * @param array<string, mixed>|null $parsed
     * @return array<string, mixed>
     */
    public function dashboardPayload(?array $parsed, bool $online): array
    {
        $config = $this->load();
        if (!$config['enabled']) {
            return ['enabled' => false];
        }
        $usable = $online && is_array($parsed);
        $layout = $this->compose($usable ? $parsed : null, $usable);
        $hash = hash('sha256', json_encode($layout['codes']));

        return [
            'enabled' => true,
            'lines' => $layout['lines'],
            'codes' => $layout['codes'],
            'verb' => $layout['verb'],
            'last_sent_at' => $config['last_sent_at'],
            'pending' => $hash !== (string) $config['last_hash'],
            'last_error' => $this->publicLastError($config['last_error']),
        ];
    }

    /**
     * @return array{ok: bool, lines: list<string>, codes: list<list<int>>, verb: string, online: bool, error?: string}
     */
    public function preview(?string $sample = null): array
    {
        if ($sample !== null && $sample !== '' && $sample !== 'live') {
            $layout = $this->sampleLayout($sample);
            if ($layout === null) {
                return ['ok' => false, 'error' => 'Unknown sample', 'lines' => [], 'codes' => [], 'verb' => '', 'online' => false];
            }

            return ['ok' => true] + $layout;
        }

        return $this->layoutFromTelemetry();
    }

    /**
     * @return array{ok: bool, error?: string, skipped?: bool, sent?: bool, lines?: list<string>}
     */
    public function tick(): array
    {
        $config = $this->load();
        if (!$config['enabled']) {
            return ['ok' => true, 'skipped' => true];
        }
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }

        $layout = $this->layoutFromTelemetry();
        if (!($layout['ok'] ?? false)) {
            $this->save(['last_error' => (string) ($layout['error'] ?? 'Could not compose layout')]);

            return $layout;
        }

        return $this->sendLayout($layout, false);
    }

    /**
     * @param array<string, mixed> $override host/port/api_key for unsaved Settings form values
     * @return array<string, mixed>
     */
    public function testConnection(array $override = []): array
    {
        $config = $this->mergedConfig($override);
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }
        $result = $this->http('GET', $config, null);
        if (!($result['ok'] ?? false)) {
            return $result;
        }
        $label = $config['transport'] === self::TRANSPORT_CLOUD
            ? 'Reached the Vestaboard Cloud API.'
            : 'Reached the Vestaboard Local API.';

        return ['ok' => true, 'message' => $label];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public function sendNow(array $override = []): array
    {
        $layout = $this->layoutFromTelemetry();
        if (!($layout['ok'] ?? false)) {
            return $layout;
        }

        return $this->sendLayout($layout, true, $override);
    }

    /**
     * @param array{lines: list<string>, codes: list<list<int>>} $layout
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function sendLayout(array $layout, bool $force, array $override = []): array
    {
        $config = $this->mergedConfig($override);
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }
        if ($config['transport'] === self::TRANSPORT_CLOUD && !$this->layoutHasGlyph($layout['codes'])) {
            return ['ok' => false, 'error' => 'Cloud API does not accept a blank board.'];
        }
        $hash = hash('sha256', json_encode($layout['codes']));
        if (!$force && $hash === $config['last_hash']) {
            return ['ok' => true, 'skipped' => true, 'lines' => $layout['lines']];
        }
        if (!$force && $config['last_sent_at'] !== null) {
            $then = strtotime($config['last_sent_at']);
            if ($then !== false && (microtime(true) - $then) < self::MIN_SEND_GAP_SECONDS) {
                return ['ok' => true, 'skipped' => true, 'lines' => $layout['lines']];
            }
        }

        $posted = $this->http('POST', $config, $layout['codes']);
        if (!($posted['ok'] ?? false)) {
            $this->save(['last_error' => (string) ($posted['error'] ?? 'Send failed')]);

            return $posted;
        }
        $this->save([
            'last_hash' => $hash,
            'last_sent_at' => gmdate('c'),
            'last_error' => '',
        ]);

        return [
            'ok' => true,
            'sent' => true,
            'lines' => $layout['lines'],
            'verb' => $layout['verb'] ?? '',
        ];
    }

    /**
     * @return array{ok: bool, lines: list<string>, codes: list<list<int>>, verb: string, online: bool, error?: string}
     */
    public function layoutFromTelemetry(): array
    {
        try {
            $agent = YarboMqttAgentClient::fromEnv();
            $result = $agent->telemetry(4.0, false);
        } catch (\Throwable $e) {
            $layout = $this->compose(null, false);

            return ['ok' => true, 'online' => false] + $layout;
        }
        $raw = $result['raw'] ?? null;
        if (!($result['ok'] ?? false) || !is_array($raw) || $raw === []) {
            $layout = $this->compose(null, false);

            return ['ok' => true, 'online' => false] + $layout;
        }
        $cells = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
        $parsed = YarboTelemetry::parse($raw, $cells);
        $layout = $this->compose($parsed, true);

        return ['ok' => true, 'online' => true] + $layout;
    }

    /**
     * @param array<string, mixed>|null $parsed
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function compose(?array $parsed, bool $online): array
    {
        if (!$online || $parsed === null) {
            return $this->pack('OFFLINE', $this->pair('BATTERY', '--', 14), 'NO TELEMETRY', self::COLOR_RED);
        }

        $errorCode = (int) ($parsed['error_code'] ?? 0);
        $chargingLabel = (string) ($parsed['charging_label'] ?? 'No');
        $charging = (bool) ($parsed['charging'] ?? false);
        $returning = (bool) ($parsed['returning_to_dock'] ?? false);
        $paused = (bool) ($parsed['planning_paused'] ?? false);
        $planRunning = (bool) ($parsed['plan_running'] ?? false);
        $rain = (bool) ($parsed['rain_detected'] ?? false);
        $headName = (string) ($parsed['head_type_name'] ?? '');
        $batteryLine = $this->batteryLine($parsed);
        $batteryColor = $this->batteryColorCode($parsed);

        if ($errorCode !== 0) {
            $code = substr((string) $errorCode, 0, 6);

            return $this->pack(
                'ERROR',
                $this->pair('CODE', $code, 14),
                'STOPPED',
                self::COLOR_RED,
                self::COLOR_RED,
            );
        }
        if ($rain) {
            return $this->pack(
                'RAIN',
                $batteryLine,
                $paused ? 'PLAN HOLD' : 'DETECTED',
                $batteryColor,
                self::COLOR_BLUE,
            );
        }
        if ($returning) {
            return $this->pack('DOCKING', $batteryLine, 'HEADING HOME', $batteryColor);
        }
        if ($paused) {
            return $this->pack('PAUSED', $batteryLine, 'PLAN HOLD', $batteryColor);
        }
        if ($charging && $chargingLabel !== 'Full') {
            return $this->pack('CHARGING', $batteryLine, 'ON DOCK', $batteryColor);
        }
        if ($planRunning && $chargingLabel === 'No') {
            $verb = $this->workingVerb($headName);

            return $this->pack($verb, $batteryLine, $this->headLine($headName), $batteryColor);
        }
        if ($chargingLabel === 'Full') {
            return $this->pack('IDLE', $this->batteryLine($parsed), 'CHARGED', $batteryColor);
        }

        return $this->pack('IDLE', $batteryLine, 'READY', $batteryColor);
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}|null
     */
    public function sampleLayout(string $name): ?array
    {
        return match ($name) {
            'mowing' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'No',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => true,
                'state' => 'active',
                'head_type_name' => 'Lawn Mower',
                'battery' => 85,
            ], true),
            'charging' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Yes',
                'charging' => true,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 55,
            ], true),
            'idle' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Full',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'rain_detected' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 98,
            ], true),
            'rain' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Full',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => true,
                'plan_running' => false,
                'rain_detected' => true,
                'state' => 'rain',
                'head_type_name' => 'Lawn Mower',
                'battery' => 100,
            ], true),
            'error' => $this->compose([
                'error_code' => 12,
                'charging_label' => 'No',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 40,
            ], true),
            default => null,
        };
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    private function pack(string $verb, string $line2, string $line3, int $line2Color = 0, int $line1Color = 0): array
    {
        $line1Width = $line1Color !== 0 ? self::COLS - 1 : self::COLS;
        $line2Text = $this->clip($line2);
        if ($line2Color !== 0) {
            $line2Text = substr($line2Text, 0, self::COLS - 1) . ' ';
        }
        $lines = [
            $this->pair('YARBO', $verb, $line1Width),
            $line2Text,
            $this->clip($line3),
        ];
        $codes = array_map(fn (string $line) => $this->encodeLine($line), $lines);
        if ($line1Color !== 0) {
            $codes[0][self::COLS - 1] = $line1Color;
        }
        if ($line2Color !== 0) {
            $codes[1][self::COLS - 1] = $line2Color;
        }

        return [
            'lines' => $lines,
            'codes' => $codes,
            'verb' => $verb,
        ];
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function batteryLine(array $parsed): string
    {
        $label = (string) ($parsed['charging_label'] ?? 'No');
        if ($label === 'Full') {
            return $this->pair('BATTERY', 'FULL', 14);
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return $this->pair('BATTERY', '--', 14);
        }
        $percent = max(0, min(100, (int) $raw));
        $text = $percent === 100 ? '100%' : (string) $percent . '%';

        return $this->pair('BATTERY', $text, 14);
    }

    /**
     * Vestaboard color chip for battery: green ≥60%, yellow ≥40%, orange ≥20%, else red.
     *
     * @param array<string, mixed>|null $parsed
     */
    public function batteryColorCode(?array $parsed): int
    {
        if ($parsed === null) {
            return self::COLOR_RED;
        }
        if ((string) ($parsed['charging_label'] ?? '') === 'Full') {
            return self::COLOR_GREEN;
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return self::COLOR_RED;
        }
        $percent = (int) $raw;
        if ($percent >= 60) {
            return self::COLOR_GREEN;
        }
        if ($percent >= 40) {
            return self::COLOR_YELLOW;
        }
        if ($percent >= 20) {
            return self::COLOR_ORANGE;
        }

        return self::COLOR_RED;
    }

    private function workingVerb(string $headName): string
    {
        if ($headName === 'Snow Blower' || $headName === 'Leaf Blower') {
            return 'BLOWING';
        }

        return 'MOWING';
    }

    private function headLine(string $headName): string
    {
        $short = self::HEAD_SHORT[$headName] ?? strtoupper($this->sanitize($headName));

        return $this->clip($short);
    }

    private function pair(string $left, string $right, int $width = self::COLS): string
    {
        $left = $this->sanitize($left);
        $right = $this->sanitize($right);
        $width = max(1, min(self::COLS, $width));
        $gap = $width - strlen($left) - strlen($right);
        if ($gap < 1) {
            $keep = max(0, $width - strlen($left) - 1);
            $right = substr($right, 0, $keep);
            $gap = $width - strlen($left) - strlen($right);
        }

        return $left . str_repeat(' ', max(0, $gap)) . $right;
    }

    private function clip(string $text): string
    {
        $text = $this->sanitize($text);
        if (strlen($text) >= self::COLS) {
            return substr($text, 0, self::COLS);
        }

        return str_pad($text, self::COLS);
    }

    private function sanitize(string $text): string
    {
        $text = strtoupper($text);
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            $out .= $this->charCode($ch) === 0 && $ch !== ' ' ? ' ' : $ch;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function encodeLine(string $line): array
    {
        $line = $this->clip($line);
        $codes = [];
        for ($i = 0; $i < self::COLS; $i++) {
            $codes[] = $this->charCode($line[$i]);
        }

        return $codes;
    }

    public function charCode(string $ch): int
    {
        if ($ch === ' ' || $ch === '') {
            return 0;
        }
        $ord = ord($ch);
        if ($ord >= 65 && $ord <= 90) {
            return $ord - 64;
        }
        if ($ch === '0') {
            return 36;
        }
        if ($ord >= 49 && $ord <= 57) {
            return $ord - 22;
        }

        return match ($ch) {
            '!' => 37,
            '@' => 38,
            '#' => 39,
            '$' => 40,
            '(' => 41,
            ')' => 42,
            '-' => 44,
            '+' => 46,
            '&' => 47,
            '=' => 48,
            ';' => 49,
            ':' => 50,
            "'" => 52,
            '"' => 53,
            '%' => 54,
            ',' => 55,
            '.' => 56,
            '/' => 59,
            '?' => 60,
            default => 0,
        };
    }

    /**
     * @param array<string, mixed> $override
     * @return array{
     *   enabled: bool,
     *   transport: string,
     *   host: string,
     *   port: int,
     *   api_key: string,
     *   cloud_token: string,
     *   last_hash: string,
     *   last_sent_at: ?string,
     *   last_error: string
     * }
     */
    private function mergedConfig(array $override): array
    {
        $config = $this->load();
        if (isset($override['transport']) || isset($override['vestaboard_transport'])) {
            $config['transport'] = $this->normalizeTransport(
                $override['transport'] ?? $override['vestaboard_transport'] ?? $config['transport']
            );
        }
        if (isset($override['host']) && is_string($override['host']) && trim($override['host']) !== '') {
            $config['host'] = trim($override['host']);
        }
        if (isset($override['port']) && is_numeric($override['port'])) {
            $port = (int) $override['port'];
            if ($port >= 1 && $port <= 65535) {
                $config['port'] = $port;
            }
        }
        if (isset($override['api_key']) && is_string($override['api_key']) && $override['api_key'] !== '') {
            $config['api_key'] = $override['api_key'];
        }
        if (isset($override['cloud_token']) && is_string($override['cloud_token']) && $override['cloud_token'] !== '') {
            $config['cloud_token'] = $override['cloud_token'];
        }

        return $config;
    }

    private function normalizeTransport(mixed $value): string
    {
        return strtolower(trim((string) $value)) === self::TRANSPORT_CLOUD
            ? self::TRANSPORT_CLOUD
            : self::TRANSPORT_LOCAL;
    }

    /**
     * @param array{transport: string, api_key: string, cloud_token: string} $config
     */
    private function missingCredentialError(array $config): ?string
    {
        if ($config['transport'] === self::TRANSPORT_CLOUD) {
            return $config['cloud_token'] === '' ? 'Vestaboard Cloud API token is not set.' : null;
        }

        return $config['api_key'] === '' ? 'Vestaboard Local API key is not set.' : null;
    }

    private function publicLastError(string $error): ?string
    {
        if ($error === '' || $this->isAlreadyOnBoardError($error)) {
            return null;
        }

        return $error;
    }

    private function isAlreadyOnBoardError(string $error): bool
    {
        $lower = strtolower($error);

        return str_contains($error, 'HTTP 409') || str_contains($lower, 'already displayed');
    }

    /**
     * @param list<list<int>> $codes
     */
    private function layoutHasGlyph(array $codes): bool
    {
        foreach ($codes as $row) {
            foreach ($row as $code) {
                if ((int) $code !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<list<int>>|null $body
     * @param array{transport: string, host: string, port: int, api_key: string, cloud_token: string} $config
     * @return array<string, mixed>
     */
    private function http(string $method, array $config, ?array $body): array
    {
        if (($config['transport'] ?? self::TRANSPORT_LOCAL) === self::TRANSPORT_CLOUD) {
            return $this->httpCloud($method, $config, $body);
        }

        return $this->httpLocal($method, $config, $body);
    }

    /**
     * @param list<list<int>>|null $body
     * @param array{host: string, port: int, api_key: string} $config
     * @return array<string, mixed>
     */
    private function httpLocal(string $method, array $config, ?array $body): array
    {
        $host = $config['host'];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            return ['ok' => false, 'error' => 'Board host looks invalid. Use an IPv4 address or hostname such as vestaboard.local.'];
        }
        $url = 'http://' . $host . ':' . (int) $config['port'] . '/local-api/message';
        $payload = $body !== null ? json_encode($body) : null;
        if ($body !== null && $payload === false) {
            return ['ok' => false, 'error' => 'Could not encode Vestaboard payload.'];
        }

        return $this->request($url, $method, [
            'X-Vestaboard-Local-Api-Key: ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 'Vestaboard at ' . $host, 4, 8);
    }

    /**
     * Cloud Read/Write API: GET/POST https://cloud.vestaboard.com/ with X-Vestaboard-Token.
     *
     * @param list<list<int>>|null $body
     * @param array{cloud_token: string} $config
     * @return array<string, mixed>
     */
    private function httpCloud(string $method, array $config, ?array $body): array
    {
        $payload = null;
        if ($body !== null) {
            $payload = json_encode(['characters' => $body]);
            if ($payload === false) {
                return ['ok' => false, 'error' => 'Could not encode Vestaboard payload.'];
            }
        }

        return $this->request(self::CLOUD_URL, $method, [
            'X-Vestaboard-Token: ' . $config['cloud_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 'Vestaboard Cloud API', 6, 12);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function request(
        string $url,
        string $method,
        array $headers,
        ?string $payload,
        string $label,
        int $connectTimeout,
        int $timeout
    ): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'Could not start HTTP to the Vestaboard.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'error' => 'Could not reach ' . $label . ': ' . $err];
            }

            return $this->httpStatusResult($code, is_string($raw) ? $raw : '');
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
        ];
        if ($payload !== null) {
            $opts['http']['content'] = $payload;
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Could not reach ' . $label . '.'];
        }
        $code = 0;
        if (function_exists('http_get_last_response_headers')) {
            foreach (http_get_last_response_headers() ?: [] as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $line, $matches)) {
                    $code = (int) $matches[1];
                }
            }
        }

        return $this->httpStatusResult($code, is_string($raw) ? $raw : '');
    }

    private function httpStatusResult(int $code, string $raw): array
    {
        if ($code === 409) {
            return ['ok' => true];
        }
        if ($code === 503) {
            return ['ok' => false, 'error' => 'Vestaboard rate-limited the write (wait 15 seconds).'];
        }
        if ($code === 401) {
            return ['ok' => false, 'error' => 'Vestaboard rejected the API credential.'];
        }
        if ($code === 403) {
            return ['ok' => false, 'error' => 'Vestaboard token is missing the needed permission (Read for Test, Write for Send).'];
        }
        if ($code !== 0 && ($code < 200 || $code >= 300)) {
            $hint = $this->shortHttpError($raw);

            return ['ok' => false, 'error' => 'Vestaboard HTTP ' . $code . ($hint !== '' ? ': ' . $hint : '')];
        }

        return ['ok' => true];
    }

    private function shortHttpError(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            foreach (['error', 'message', 'status'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                    return substr($decoded[$key], 0, 160);
                }
            }
        }

        return substr(preg_replace('/\s+/', ' ', $trimmed) ?? '', 0, 160);
    }
}
