<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Starts the local live-view listener. Pictures are not copied through PHP.
 * Tokens stay in the Python process.
 */
final class YarboCameraLive
{
    private const CELLULAR_MESSAGE = 'Live view stays off while the robot is on 4G. It uses home Wi-Fi or HaLow.';

    public function __construct(private readonly string $projectRoot)
    {
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

        // A picture process left over from the previous update keeps running
        // after Stop, and Start used to leave it in place. That process was
        // still dropping the frames Safari needs.
        $this->stopAgent();
        usleep(300000);

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
        $cameraPort = (int) (getenv('YARBO_CAMERA_PORT') ?: 8767);
        if ($cameraPort === 8766) {
            $cameraPort = 8767;
        }
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
        $this->stopAgent();

        return ['ok' => true, 'state' => 'stopped', 'message' => 'Stopping live view'];
    }

    private function stopAgent(): void
    {
        $snap = $this->readStatusFile();
        $port = (int) ($snap['port'] ?? (getenv('YARBO_CAMERA_PORT') ?: 8767));
        if ($port === 8766) {
            $port = 8767;
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
        // setsid can leave the picture process running after the pid file is gone.
        @exec('pkill -f ' . escapeshellarg('[y]arbo_camera_agent.py') . ' 2>/dev/null');
    }

    public function proxyFrames(): void
    {
        // The picture stream used to stay inside this PHP worker until Stop
        // view. Dock, lights, and every other page waited behind it.
        // HTTPS sends the pictures straight from the camera listener.
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'error' => 'Live pictures are not served by the panel page. Open the panel with HTTPS.',
        ]);
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
