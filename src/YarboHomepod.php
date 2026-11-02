<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * HomePod / AirPlay speaker outputs via local pyatv agent (RAOP sender).
 */
final class YarboHomepod
{
    public const KIND_PREFIX = 'homepod:';
    public const AGENT_PORT_DEFAULT = 8767;
    private const SPAWN_COOLDOWN_S = 3.0;

    private static bool $spawnAttempted = false;
    private static float $lastSpawnAt = 0.0;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = self::AGENT_PORT_DEFAULT,
    ) {
    }

    public static function fromEnv(string $projectRoot): self
    {
        $port = (int) (getenv('YARBO_HOMEPOD_AGENT_PORT') ?: self::AGENT_PORT_DEFAULT);

        return new self($projectRoot, '127.0.0.1', $port > 0 ? $port : self::AGENT_PORT_DEFAULT);
    }

    public static function isHomepodTargetId(string $id): bool
    {
        return str_starts_with($id, self::KIND_PREFIX);
    }

    public static function stripPrefix(string $id): string
    {
        if (str_starts_with($id, self::KIND_PREFIX)) {
            return substr($id, strlen(self::KIND_PREFIX));
        }

        return $id;
    }

    public static function withPrefix(string $nativeId): string
    {
        $nativeId = trim($nativeId);
        if ($nativeId === '' || str_starts_with($nativeId, self::KIND_PREFIX)) {
            return $nativeId;
        }

        return self::KIND_PREFIX . $nativeId;
    }

    /**
     * @param list<string> $targets
     * @return list<string> native HomePod identifiers
     */
    public static function filterHomepodIds(array $targets): array
    {
        $out = [];
        foreach ($targets as $id) {
            $id = trim((string) $id);
            if ($id === '' || !self::isHomepodTargetId($id)) {
                continue;
            }
            $native = self::stripPrefix($id);
            if ($native !== '') {
                $out[] = $native;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $targets
     * @return list<string> Protect camera ids (non-HomePod)
     */
    public static function filterProtectIds(array $targets): array
    {
        $out = [];
        foreach ($targets as $id) {
            $id = trim((string) $id);
            if ($id === '' || self::isHomepodTargetId($id)) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    /**
     * Speakers for the Radio Player checklist (paired + enabled).
     *
     * @return list<array<string, mixed>>
     */
    public function speakerRows(): array
    {
        $devices = $this->savedDevices();
        $out = [];
        foreach ($devices as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $native = trim((string) ($row['id'] ?? ''));
            if ($native === '') {
                continue;
            }
            $out[] = [
                'id' => self::withPrefix($native),
                'native_id' => self::withPrefix($native),
                'name' => (string) ($row['name'] ?? $native),
                'kind' => 'homepod',
                'model' => (string) ($row['model'] ?? ''),
                'has_speaker' => true,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $agent = $this->request(['op' => 'status'], 2.0, false);
        $saved = $this->savedDevices();
        $playing = is_array($agent['playing'] ?? null) ? $agent['playing'] : [];

        return [
            'ok' => true,
            'agent_running' => (bool) ($agent['ok'] ?? false),
            'pyatv' => (bool) ($agent['pyatv'] ?? false),
            'import_error' => $agent['import_error'] ?? null,
            'devices' => array_map(static function (array $row): array {
                $row['credentials'] = !empty($row['credentials']);
                $row['target_id'] = YarboHomepod::withPrefix((string) ($row['id'] ?? ''));

                return $row;
            }, $saved),
            'playing' => $playing,
            'message' => !($agent['ok'] ?? false)
                ? ((string) ($agent['error'] ?? 'HomePod agent is not running'))
                : (empty($saved)
                    ? 'No HomePods paired yet — scan and pair below'
                    : (count($saved) . ' paired speaker(s)')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scan(float $timeout = 5.0): array
    {
        $result = $this->request(['op' => 'scan', 'timeout' => $timeout], 35.0, true);
        if (!($result['ok'] ?? false)) {
            return $result + ['devices' => []];
        }
        $devices = is_array($result['devices'] ?? null) ? $result['devices'] : [];
        foreach ($devices as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['target_id'] = self::withPrefix((string) ($row['id'] ?? ''));
        }
        unset($row);
        $result['devices'] = $devices;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function pairStart(string $id): array
    {
        return $this->request(['op' => 'pair_start', 'id' => self::stripPrefix($id)], 50.0, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function pairFinish(string $id, string $pin): array
    {
        return $this->request([
            'op' => 'pair_finish',
            'id' => self::stripPrefix($id),
            'pin' => $pin,
        ], 50.0, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function setEnabled(string $id, bool $enabled): array
    {
        return $this->request([
            'op' => 'enable',
            'id' => self::stripPrefix($id),
            'enabled' => $enabled,
        ], 10.0, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(string $id): array
    {
        return $this->request(['op' => 'remove', 'id' => self::stripPrefix($id)], 15.0, true);
    }

    /**
     * @param list<string> $ids native or prefixed
     * @return array{ok: bool, started: list<string>, errors: list<string>}
     */
    public function streamToMany(array $ids, string $source, ?int $volume = null): array
    {
        $started = [];
        $errors = [];
        foreach ($ids as $id) {
            $native = self::stripPrefix(trim((string) $id));
            if ($native === '') {
                continue;
            }
            $body = [
                'op' => 'stream',
                'id' => $native,
                'source' => $source,
            ];
            if ($volume !== null) {
                $body['volume'] = max(0, min(100, $volume));
            }
            $result = $this->request($body, 35.0, true);
            if ($result['ok'] ?? false) {
                $started[] = self::withPrefix($native);
            } else {
                $errors[] = (string) ($result['error'] ?? 'stream failed') . ' (' . $native . ')';
            }
        }

        return [
            'ok' => $started !== [] || $errors === [],
            'started' => $started,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string>|null $ids null = stop all
     * @return array<string, mixed>
     */
    public function stop(?array $ids = null): array
    {
        if ($ids === null) {
            return $this->request(['op' => 'stop_all'], 20.0, false);
        }
        $errors = [];
        foreach ($ids as $id) {
            $native = self::stripPrefix(trim((string) $id));
            if ($native === '') {
                continue;
            }
            $result = $this->request(['op' => 'stop', 'id' => $native], 15.0, false);
            if (!($result['ok'] ?? false)) {
                $errors[] = (string) ($result['error'] ?? 'stop failed');
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param list<string>|null $ids null = all currently playing
     * @return array<string, mixed>
     */
    public function setVolume(?array $ids, int $volume): array
    {
        $volume = max(0, min(100, $volume));
        if ($ids === null || $ids === []) {
            return $this->request(['op' => 'volume', 'volume' => $volume], 15.0, false);
        }
        $errors = [];
        $updated = [];
        foreach ($ids as $id) {
            $native = self::stripPrefix(trim((string) $id));
            if ($native === '') {
                continue;
            }
            $result = $this->request([
                'op' => 'volume',
                'id' => $native,
                'volume' => $volume,
            ], 15.0, false);
            if ($result['ok'] ?? false) {
                $updated[] = self::withPrefix($native);
            } else {
                $errors[] = (string) ($result['error'] ?? 'volume failed') . ' (' . $native . ')';
            }
        }

        return ['ok' => $errors === [] || $updated !== [], 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function savedDevices(): array
    {
        $path = $this->projectRoot . '/data/homepod/devices.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['devices'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($decoded['devices'] as $row) {
            if (is_array($row) && trim((string) ($row['id'] ?? '')) !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function request(array $body, float $timeoutSeconds = 12.0, bool $spawn = true): array
    {
        if ($spawn) {
            $this->ensureStarted();
        }
        $result = $this->post($body, $timeoutSeconds);
        if ($spawn && $this->shouldRestart($result)) {
            $this->forceRestart();
            $result = $this->post($body, $timeoutSeconds);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body, float $timeoutSeconds): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'Could not encode HomePod request'];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $url = sprintf('http://%s:%d/', $this->host, $this->port);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'HomePod agent is not running'];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'HomePod agent returned invalid JSON'];
    }

    private function shouldRestart(array $result): bool
    {
        $error = strtolower((string) ($result['error'] ?? ''));

        return $error !== '' && str_contains($error, 'homepod agent is not running');
    }

    private function ensureStarted(): void
    {
        if ($this->portOpen()) {
            return;
        }
        $now = microtime(true);
        if (self::$spawnAttempted && ($now - self::$lastSpawnAt) < self::SPAWN_COOLDOWN_S) {
            return;
        }
        self::$spawnAttempted = true;
        self::$lastSpawnAt = $now;
        $this->spawnAgent();
        for ($i = 0; $i < 20; $i++) {
            usleep(100000);
            if ($this->portOpen()) {
                return;
            }
        }
    }

    private function forceRestart(): void
    {
        $this->killAgent();
        self::$spawnAttempted = false;
        self::$lastSpawnAt = 0.0;
        $this->ensureStarted();
    }

    private function portOpen(): bool
    {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    private function killAgent(): void
    {
        @shell_exec("pkill -f '[s]cripts/homepod_agent.py' 2>/dev/null");
        usleep(150000);
    }

    private function spawnAgent(): void
    {
        $root = $this->projectRoot;
        $script = $root . '/scripts/homepod_agent.py';
        if (!is_file($script)) {
            return;
        }
        $python = $root . '/.venv/bin/python';
        if (!is_executable($python)) {
            $python = 'python3';
        }
        $log = escapeshellarg($root . '/data/homepod-agent.log');
        $port = (int) $this->port;
        @mkdir($root . '/data', 0755, true);
        $cmd = sprintf(
            'cd %s && YARBO_HOMEPOD_AGENT_PORT=%d %s %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($root),
            $port,
            escapeshellarg($python),
            escapeshellarg($script),
            $log
        );
        @shell_exec($cmd);
    }
}
