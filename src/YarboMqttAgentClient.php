<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Client for the persistent MQTT agent (scripts/mqtt_agent.py / .php).
 */
final class YarboMqttAgentClient
{
    private static bool $spawnAttempted = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8765,
        private readonly float $timeoutSeconds = 8.0,
    ) {
    }

    public static function fromEnv(): self
    {
        $port = (int) (getenv('YARBO_MQTT_AGENT_PORT') ?: 8765);

        return new self('127.0.0.1', $port);
    }

    /**
     * Return a client, starting the agent in the background if it is not up.
     */
    public static function requireRunning(): self
    {
        $client = self::fromEnv();
        if ($client->isAvailable()) {
            return $client;
        }

        $client->ensureStarted();
        if (!$client->isAvailable()) {
            throw new \RuntimeException(
                'MQTT agent is not running. Start the panel with ./scripts/dev.sh '
                . '(or ./scripts/panel.sh).'
            );
        }

        return $client;
    }

    public function isAvailable(): bool
    {
        try {
            $result = $this->request(['op' => 'ping'], 1.5, false);

            return (bool) ($result['ok'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Spawn scripts/mqtt_agent.py (or .php) once if the TCP port is closed.
     */
    public function ensureStarted(): void
    {
        if (self::$spawnAttempted) {
            return;
        }
        self::$spawnAttempted = true;

        if ($this->isAvailable()) {
            return;
        }

        $root = dirname(__DIR__);
        $log = $root . '/data/mqtt-agent.log';
        if (!is_dir($root . '/data')) {
            @mkdir($root . '/data', 0775, true);
        }

        $venvPy = $root . '/.venv/bin/python';
        $pyAgent = $root . '/scripts/mqtt_agent.py';
        $phpAgent = $root . '/scripts/mqtt_agent.php';

        $argv = null;
        if (is_file($pyAgent) && is_executable($venvPy)) {
            $check = @exec(escapeshellarg($venvPy) . ' -c ' . escapeshellarg('import yarbo') . ' 2>/dev/null; echo $?');
            if (trim((string) $check) === '0') {
                $argv = [escapeshellarg($venvPy), escapeshellarg($pyAgent)];
            }
        }
        if ($argv === null && is_file($phpAgent)) {
            $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $argv = [escapeshellarg($php), escapeshellarg($phpAgent)];
        }
        if ($argv === null) {
            return;
        }

        $this->spawnDetached($root, $argv, $log);

        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            usleep(200000);
            if ($this->isAvailable()) {
                return;
            }
        }
    }

    /**
     * Start the agent without blocking the single-threaded php -S request.
     *
     * PHP exec() waits on inherited sockets unless stdin is closed and the
     * child is fully detached (nohup / setsid). That hang is what produced
     * blank panels and "Failed to Fetch" on macOS php -S.
     *
     * @param list<string> $escapedArgv already escapeshellarg()'d binary + args
     */
    private function spawnDetached(string $root, array $escapedArgv, string $log): void
    {
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
        $nohup = is_executable('/usr/bin/nohup') || is_executable('/bin/nohup') ? 'nohup ' : '';
        // echo $! so exec() returns as soon as the shell backgrounds the agent.
        $cmd = sprintf(
            'cd %s && YARBO_MQTT_AGENT_PORT=%d %s%s%s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($root),
            $this->port,
            $nohup,
            $setsid,
            implode(' ', $escapedArgv),
            escapeshellarg($log)
        );
        @exec($cmd);
    }

    /**
     * @return array<string, mixed>
     */
    public function drive(float $linear, float $angular, bool $enterManual): array
    {
        return $this->request([
            'op' => 'drive',
            'linear' => $linear,
            'angular' => $angular,
            'enter_manual' => $enterManual,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publish(string $cmd, array $payload): array
    {
        return $this->request([
            'op' => 'publish',
            'cmd' => $cmd,
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<int, array{cmd: string, payload: array<string, mixed>}> $variants
     * @return array<string, mixed>
     */
    public function publishVariants(array $variants): array
    {
        return $this->request([
            'op' => 'publish_variants',
            'variants' => $variants,
        ]);
    }

    /**
     * Fetch telemetry via the persistent agent (avoids competing MQTT clients).
     *
     * @return array<string, mixed>
     */
    public function telemetry(float $timeoutSeconds = 4.0, bool $wifi = true): array
    {
        return $this->request([
            'op' => 'telemetry',
            'timeout' => $timeoutSeconds,
            'wifi' => $wifi,
        ], max(8.0, $timeoutSeconds + 4.0));
    }

    /**
     * Turn lights on/off with agent-side hold (re-publishes light_ctrl so they stay lit).
     *
     * @return array<string, mixed>
     */
    public function lights(bool $on): array
    {
        return $this->request([
            'op' => 'lights',
            'on' => $on,
        ]);
    }

    /**
     * Hold or release desired app-controller role (force get_controller when on).
     *
     * @return array<string, mixed>
     */
    public function controller(bool $on): array
    {
        return $this->request([
            'op' => 'controller',
            'on' => $on,
        ], 12.0);
    }

    /**
     * Sound buzzer (wakes controller session; also tries song_cmd).
     *
     * @return array<string, mixed>
     */
    public function buzzer(): array
    {
        // Holds cmd_buzzer on for ~1.6s plus song probes.
        return $this->request([
            'op' => 'buzzer',
        ], 20.0);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(array $payload, ?float $timeoutSeconds = null, bool $autoStart = true): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            0.4
        );
        if ($socket === false && $autoStart) {
            $this->ensureStarted();
            $socket = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                0.4
            );
        }
        if ($socket === false) {
            throw new \RuntimeException(
                'MQTT agent is not running. Start the panel with ./scripts/dev.sh '
                . '(or ./scripts/panel.sh).'
            );
        }

        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        stream_set_timeout($socket, (int) ceil($timeout));
        $payload['id'] = bin2hex(random_bytes(4));
        fwrite($socket, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");

        $line = fgets($socket);
        fclose($socket);

        if ($line === false || trim($line) === '') {
            throw new \RuntimeException('No response from MQTT agent');
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid response from MQTT agent');
        }

        return $decoded;
    }
}
