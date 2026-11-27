<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Starts the local live-view listener and, if needed, flushes its pictures.
 * Tokens stay in the Python process.
 */
final class YarboCameraLive
{
    private const CELLULAR_MESSAGE = 'Live view stays off while the robot is on 4G. It uses home Wi-Fi or HaLow.';
    public const CAMERA_PORT_DEFAULT = 8769;
    /** MQTT 8765, Matter 8766, HomePod 8767, HomePod audio bridge 8768. */
    public const RESERVED_PORTS = [8765, 8766, 8767, 8768];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public static function cameraPort(): int
    {
        $port = (int) (getenv('YARBO_CAMERA_PORT') ?: self::CAMERA_PORT_DEFAULT);
        if ($port <= 0 || in_array($port, self::RESERVED_PORTS, true)) {
            return self::CAMERA_PORT_DEFAULT;
        }

        return $port;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $snap = $this->readStatusFile();
        $pid = $this->readPid();
        $alive = $this->pidAlive($pid);
        if ($alive && !is_file($this->projectRoot . '/data/camera-live-status.json')) {
            $snap['state'] = 'starting';
            $snap['message'] = 'Starting live view';
        } elseif (!$alive && in_array((string) ($snap['state'] ?? ''), ['starting', 'joining', 'live'], true)) {
            $snap['state'] = 'stopped';
            $snap['message'] = $snap['message'] ?? 'Live view is off';
        }
        $snap['ok'] = true;
        $snap['running'] = $alive;

        return $snap;
    }

    /**
     * @return array<string, mixed>
     */
    public function start(): array
    {
        if ($this->robotOnCellular()) {
            return ['ok' => false, 'error' => self::CELLULAR_MESSAGE];
        }

        $existing = $this->status();
        if (!empty($existing['running']) && in_array((string) ($existing['state'] ?? ''), ['starting', 'live'], true)) {
            return [
                'ok' => true,
                'already' => true,
                'state' => $existing['state'],
                'message' => 'Live view is already on',
            ];
        }

        $python = $this->pythonBinary();
        $script = $this->projectRoot . '/scripts/yarbo_camera_agent.py';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'Live view is not installed. Update the panel and try again.'];
        }

        $data = $this->projectRoot . '/data';
        if (!is_dir($data) && !mkdir($data, 0755, true) && !is_dir($data)) {
            return ['ok' => false, 'error' => 'Could not create the data folder'];
        }

        $log = $data . '/camera-live.log';
        $pidFile = $data . '/camera-live.pid';
        @unlink($data . '/camera-live-status.json');
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
        $nohup = is_executable('/usr/bin/nohup') || is_executable('/bin/nohup') ? 'nohup ' : '';
        $mqttPort = (int) (getenv('YARBO_MQTT_AGENT_PORT') ?: 8765);
        $cameraPort = self::cameraPort();
        $cmd = sprintf(
            'cd %s && YARBO_MQTT_AGENT_PORT=%d YARBO_CAMERA_PORT=%d %s%s%s %s --root %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($this->projectRoot),
            $mqttPort,
            $cameraPort,
            $nohup,
            $setsid,
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($this->projectRoot),
            escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid > 0) {
            file_put_contents($pidFile, (string) $pid);
        }

        return [
            'ok' => true,
            'state' => 'starting',
            'message' => 'Starting live view',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(): array
    {
        $snap = $this->readStatusFile();
        $port = (int) ($snap['port'] ?? self::cameraPort());
        if (in_array($port, self::RESERVED_PORTS, true)) {
            $port = self::cameraPort();
        }
        if ($port > 0) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 2, 'ignore_errors' => true]]);
            @file_get_contents('http://127.0.0.1:' . $port . '/stop', false, $ctx);
        }
        $pid = $this->readPid();
        if ($this->pidAlive($pid)) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 15);
            } else {
                @exec('kill ' . (int) $pid . ' 2>/dev/null');
            }
        }

        return ['ok' => true, 'state' => 'stopped', 'message' => 'Stopping live view'];
    }

    public function proxyFrames(): void
    {
        $snap = $this->readStatusFile();
        $port = (int) ($snap['port'] ?? 0);
        $state = (string) ($snap['state'] ?? '');
        if ($port <= 0 || !in_array($state, ['starting', 'live'], true) || !$this->pidAlive($this->readPid())) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => (string) ($snap['message'] ?? 'Live view is not running. Press Start live view.'),
            ]);

            return;
        }

        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
        if (!is_resource($socket)) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Live view is not running. Press Start live view.']);

            return;
        }

        fwrite($socket, "GET /frames HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $header = '';
        while (!str_contains($header, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $header .= $chunk;
            if (strlen($header) > 8192) {
                break;
            }
        }
        $parts = explode("\r\n\r\n", $header, 2);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        if (isset($parts[1]) && $parts[1] !== '') {
            echo $parts[1];
            flush();
        }
        while (!connection_aborted()) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
        }
        fclose($socket);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function viewFor(string $id): string
    {
        return match ($id) {
            'front' => 'middle',
            'rear' => 'back',
            default => $id,
        };
    }

    private function robotOnCellular(): bool
    {
        try {
            $snap = YarboMqttAgentClient::fromEnv()->telemetry(1.0, false, true, false);
        } catch (\Throwable) {
            return false;
        }
        $raw = $snap['raw'] ?? null;

        return is_array($raw) && YarboTelemetry::linkIsCellular($raw);
    }

    private function pythonBinary(): string
    {
        $noted = $this->projectRoot . '/data/camera-python.txt';
        if (is_file($noted)) {
            $path = trim((string) file_get_contents($noted));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }
        foreach ([
            $this->projectRoot . '/.venv-camera/bin/python3',
            $this->projectRoot . '/.venv/bin/python3',
            $this->projectRoot . '/.venv/bin/python',
        ] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $found = trim((string) shell_exec('command -v python3 2>/dev/null'));

        return $found !== '' ? $found : 'python3';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatusFile(): array
    {
        $path = $this->projectRoot . '/data/camera-live-status.json';
        if (!is_file($path)) {
            return ['state' => 'stopped', 'message' => 'Live view is off'];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['state' => 'stopped', 'message' => 'Live view is off'];
    }

    private function readPid(): int
    {
        $path = $this->projectRoot . '/data/camera-live.pid';
        if (!is_file($path)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($path));
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }
}
