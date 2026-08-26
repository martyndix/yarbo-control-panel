<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Vestaboard Note (3×15) Local API: layout, encode, optional push.
 * https://docs.vestaboard.com/docs/local-api/introduction
 */
final class YarboVestaboard
{
    public const ROWS = 3;
    public const COLS = 15;
    public const MIN_SEND_GAP_SECONDS = 15.0;
    public const DEFAULT_HOST = 'vestaboard.local';
    public const DEFAULT_PORT = 7000;

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
     *   host: string,
     *   port: int,
     *   api_key: string,
     *   last_hash: string,
     *   last_sent_at: ?string,
     *   last_error: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'enabled' => false,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'api_key' => '',
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
            'host' => trim((string) ($decoded['host'] ?? self::DEFAULT_HOST)) ?: self::DEFAULT_HOST,
            'port' => $port,
            'api_key' => (string) ($decoded['api_key'] ?? ''),
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
        $enabled = array_key_exists('enabled', $input) || array_key_exists('vestaboard_enabled', $input)
            ? (bool) ($input['enabled'] ?? $input['vestaboard_enabled'])
            : $current['enabled'];

        $next = [
            'enabled' => $enabled,
            'host' => $host,
            'port' => $port,
            'api_key' => $apiKey,
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
            'host' => $config['host'],
            'port' => $config['port'],
            'api_key_set' => $config['api_key'] !== '',
            'last_sent_at' => $config['last_sent_at'],
            'last_error' => $config['last_error'],
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
        if ($config['api_key'] === '') {
            return ['ok' => false, 'error' => 'Vestaboard Local API key is not set.'];
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
        if ($config['api_key'] === '') {
            return ['ok' => false, 'error' => 'Local API key is required.'];
        }
        $result = $this->http('GET', $config, null);
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        return ['ok' => true, 'message' => 'Reached the Vestaboard Local API.'];
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
        if ($config['api_key'] === '') {
            return ['ok' => false, 'error' => 'Local API key is required.'];
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
            return $this->pack('OFFLINE', $this->pair('BATTERY', '--'), 'NO TELEMETRY');
        }

        $errorCode = (int) ($parsed['error_code'] ?? 0);
        $chargingLabel = (string) ($parsed['charging_label'] ?? 'No');
        $charging = (bool) ($parsed['charging'] ?? false);
        $returning = (bool) ($parsed['returning_to_dock'] ?? false);
        $paused = (bool) ($parsed['planning_paused'] ?? false);
        $planRunning = (bool) ($parsed['plan_running'] ?? false);
        $active = ($parsed['state'] ?? 'idle') === 'active';
        $headName = (string) ($parsed['head_type_name'] ?? '');
        $batteryLine = $this->batteryLine($parsed);

        if ($errorCode !== 0) {
            $code = substr((string) $errorCode, 0, 6);

            return $this->pack('ERROR', $this->pair('CODE', $code), 'STOPPED');
        }
        if ($returning) {
            return $this->pack('DOCKING', $batteryLine, 'HEADING HOME');
        }
        if ($paused) {
            return $this->pack('PAUSED', $batteryLine, 'PLAN HOLD');
        }
        if ($charging && $chargingLabel !== 'Full') {
            return $this->pack('CHARGING', $batteryLine, 'ON DOCK');
        }
        if ($planRunning || $active) {
            $verb = $this->workingVerb($headName);

            return $this->pack($verb, $batteryLine, $this->headLine($headName));
        }
        if ($chargingLabel === 'Full') {
            return $this->pack('IDLE', $this->pair('BATTERY', 'FULL'), 'CHARGED');
        }

        return $this->pack('IDLE', $batteryLine, 'READY');
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
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 98,
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
    private function pack(string $verb, string $line2, string $line3): array
    {
        $lines = [
            $this->pair('YARBO', $verb),
            $this->clip($line2),
            $this->clip($line3),
        ];

        return [
            'lines' => $lines,
            'codes' => array_map(fn (string $line) => $this->encodeLine($line), $lines),
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
            return $this->pair('BATTERY', 'FULL');
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return $this->pair('BATTERY', '--');
        }
        $rounded = (int) (round(((int) $raw) / 5) * 5);
        $rounded = max(0, min(100, $rounded));
        $text = $rounded === 100 ? '100%' : (string) $rounded . '%';

        return $this->pair('BATTERY', $text);
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

    private function pair(string $left, string $right): string
    {
        $left = $this->sanitize($left);
        $right = $this->sanitize($right);
        $gap = self::COLS - strlen($left) - strlen($right);
        if ($gap < 1) {
            $keep = max(0, self::COLS - strlen($left) - 1);
            $right = substr($right, 0, $keep);
            $gap = self::COLS - strlen($left) - strlen($right);
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
            return $ord - 21;
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
     * @return array{enabled: bool, host: string, port: int, api_key: string, last_hash: string, last_sent_at: ?string, last_error: string}
     */
    private function mergedConfig(array $override): array
    {
        $config = $this->load();
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

        return $config;
    }

    /**
     * @param list<list<int>>|null $body
     * @param array{host: string, port: int, api_key: string} $config
     * @return array<string, mixed>
     */
    private function http(string $method, array $config, ?array $body): array
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

        $headers = [
            'X-Vestaboard-Local-Api-Key: ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'Could not start HTTP to the Vestaboard.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'error' => 'Could not reach Vestaboard at ' . $host . ': ' . $err];
            }
            if ($code === 503) {
                return ['ok' => false, 'error' => 'Vestaboard rate-limited the write (wait 15 seconds).'];
            }
            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'error' => 'Vestaboard HTTP ' . $code];
            }

            return ['ok' => true];
        }

        $headerStr = implode("\r\n", $headers);
        $opts = [
            'http' => [
                'method' => $method,
                'header' => $headerStr,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ];
        if ($payload !== null) {
            $opts['http']['content'] = $payload;
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Could not reach Vestaboard at ' . $host . '.'];
        }

        return ['ok' => true];
    }
}
