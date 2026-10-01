<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMatterAgentClient
{
    public const MIN_VERSION = 9;

    private static bool $spawnAttempted = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8766,
    ) {
    }

    public static function fromEnv(): self
    {
        $port = (int) (getenv('YARBO_MATTER_AGENT_PORT') ?: 8766);

        return new self('127.0.0.1', $port);
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

        return $this->post($body, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body, float $timeoutSeconds): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'Could not encode Matter request'];
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
            return ['ok' => false, 'error' => 'Matter agent is not running. Restart the panel after enabling Home.'];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Matter agent returned invalid JSON'];
    }

    public function ping(): array
    {
        return $this->request(['op' => 'ping'], 1.5);
    }

    public function isAvailable(): bool
    {
        return (bool) ($this->ping()['ok'] ?? false);
    }

    public static function agentSupportsColor(array $probe): bool
    {
        if (($probe['ok'] ?? false) !== true) {
            return false;
        }
        $version = (int) ($probe['version'] ?? 0);
        if ($version > 0 && $version < self::MIN_VERSION) {
            return false;
        }
        $features = $probe['features'] ?? null;
        if (is_array($features) && in_array('color', $features, true)) {
            return true;
        }

        return $version >= self::MIN_VERSION;
    }

    public static function isUnknownCommandError(array $result): bool
    {
        $error = strtolower((string) ($result['error'] ?? ''));

        return $error !== '' && str_contains($error, 'unknown matter command');
    }

    public function forceRestart(): void
    {
        self::$spawnAttempted = false;
        $this->stopAgentProcesses();
        $this->ensureStarted();
    }

    public function ensureStarted(): void
    {
        if (self::$spawnAttempted) {
            return;
        }
        self::$spawnAttempted = true;
        $probe = $this->post(['op' => 'ping'], 0.4);
        if (self::agentSupportsColor($probe)) {
            return;
        }
        $this->spawnAgent();
    }

    private function spawnAgent(): void
    {
        $root = dirname(__DIR__);
        $script = $root . '/scripts/matter_agent.py';
        if (!is_file($script)) {
            return;
        }
        $this->stopAgentProcesses();
        $log = $root . '/data/matter-agent.log';
        if (!is_dir($root . '/data')) {
            @mkdir($root . '/data', 0775, true);
        }
        $python = $root . '/.venv/bin/python';
        if (!is_executable($python)) {
            $python = 'python3';
        }
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
        $nohup = is_executable('/usr/bin/nohup') || is_executable('/bin/nohup') ? 'nohup ' : '';
        $cmd = sprintf(
            'cd %s && YARBO_MATTER_AGENT_PORT=%d %s%s%s %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($root),
            $this->port,
            $nohup,
            $setsid,
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        exec($cmd);
        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            usleep(200000);
            $ready = $this->post(['op' => 'ping'], 0.4);
            if (self::agentSupportsColor($ready)) {
                return;
            }
        }
    }

    private function stopAgentProcesses(): void
    {
        $port = (int) $this->port;
        @exec('lsof -ti tcp:' . $port . ' 2>/dev/null | xargs kill -9 2>/dev/null');
        @exec('fuser -k ' . $port . '/tcp >/dev/null 2>&1');
        @exec("pkill -f '[s]cripts/matter_agent.py' 2>/dev/null");
        usleep(250000);
    }
}
