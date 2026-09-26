<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMatterAgentClient
{
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
    public function request(array $body, float $timeoutSeconds = 12.0): array
    {
        $this->ensureStarted();
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

    public function ensureStarted(): void
    {
        if (self::$spawnAttempted) {
            return;
        }
        $probe = $this->request(['op' => 'ping'], 0.8);
        if (($probe['ok'] ?? false) === true) {
            self::$spawnAttempted = true;

            return;
        }
        self::$spawnAttempted = true;
        $root = dirname(__DIR__);
        $script = $root . '/scripts/matter_agent.py';
        if (!is_file($script)) {
            return;
        }
        $log = $root . '/data/matter-agent.log';
        if (!is_dir($root . '/data')) {
            @mkdir($root . '/data', 0775, true);
        }
        $python = $root . '/.venv/bin/python';
        if (!is_executable($python)) {
            $python = 'python3';
        }
        $cmd = sprintf(
            'cd %s && %s %s >> %s 2>&1 &',
            escapeshellarg($root),
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        exec($cmd);
        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            usleep(200000);
            if (($this->request(['op' => 'ping'], 0.6)['ok'] ?? false) === true) {
                return;
            }
        }
    }
}
