<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMapCapture
{
    public const DURATION_SECONDS = 120;

    /** Documented SDK control topics — a map write will not be in this list. */
    public const KNOWN_APP_COMMANDS = [
        'set_working_state',
        'read_gps_ref',
        'get_map',
        'read_all_plan',
        'get_device_msg',
        'get_connect_wifi_name',
        'start_plan',
        'pause',
        'resume',
        'stop',
        'cmd_recharge',
        'set_sound_param',
        'light_ctrl',
        'set_person_detect',
        'set_follow_state',
        'save_global_params',
        'set_map_obstacle_switch',
        'ignore_obstacles',
        'set_child_lock',
        'song_cmd',
        'wireless_charging_cmd',
        'mower_target_cmd',
        'mower_speed_cmd',
        'cmd_set_chute_angle',
        'cmd_vel',
        'get_plan_feedback',
        'emergency_unlock',
        'battery_cell_temp_msg',
        'set_auto_mapping_state',
        'get_controller',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function statusPath(): string
    {
        return $this->projectRoot . '/data/map-capture.json';
    }

    public function stopPath(): string
    {
        return $this->projectRoot . '/data/map-capture.stop';
    }

    public function cloudCommandsPath(): string
    {
        return $this->projectRoot . '/data/map-capture-cloud.json';
    }

    public function logPath(): string
    {
        return $this->projectRoot . '/data/map-capture.log';
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $path = $this->statusPath();
        if (!is_file($path)) {
            return $this->idleStatus();
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return $this->idleStatus();
        }
        if (($raw['state'] ?? '') === 'listening' && !$this->processIsRunning((int) ($raw['pid'] ?? 0))) {
            if (!is_file($this->stopPath())) {
                $raw['state'] = 'error';
                $raw['error'] = $raw['error'] ?? 'Listen process stopped unexpectedly';
                $raw['finished_at'] = $raw['finished_at'] ?? gmdate('c');
                $this->writeStatus($raw);
            }
        }

        return $this->publicStatus($raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(): array
    {
        $current = $this->status();
        if (($current['state'] ?? '') === 'listening') {
            return $current;
        }

        $this->ensureDataDir();
        @unlink($this->stopPath());
        @unlink($this->cloudCommandsPath());

        $started = [
            'ok' => true,
            'state' => 'listening',
            'duration_s' => self::DURATION_SECONDS,
            'remaining_s' => self::DURATION_SECONDS,
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'via' => [],
            'app_commands' => new \stdClass(),
            'feedback_topics' => new \stdClass(),
            'unknown_commands' => [],
            'message' => 'Listening for 2 minutes. Save a map in the official Yarbo app or Yardstick now. This does not change the robot map.',
            'error' => null,
            'pid' => null,
            'cloud_pid' => null,
        ];
        $this->writeStatus($started);

        $script = $this->projectRoot . '/scripts/map_capture_job.php';
        $log = $this->logPath();
        $cmd = sprintf(
            '%s %s >>%s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            $started['state'] = 'error';
            $started['error'] = 'Could not start the listen process';
            $started['message'] = $started['error'];
            $this->writeStatus($started);

            return $this->publicStatus($started);
        }

        $started['pid'] = $pid;
        $this->writeStatus($started);

        return $this->publicStatus($started);
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(): array
    {
        $this->ensureDataDir();
        file_put_contents($this->stopPath(), (string) time());
        $raw = $this->readRaw();
        foreach (['pid', 'cloud_pid'] as $key) {
            $pid = (int) ($raw[$key] ?? 0);
            if ($pid > 1 && $this->processIsRunning($pid)) {
                @posix_kill($pid, SIGTERM);
            }
        }
        $raw = $this->readRaw();
        if (($raw['state'] ?? '') === 'listening') {
            $raw['state'] = 'done';
            $raw['remaining_s'] = 0;
            $raw['finished_at'] = gmdate('c');
            $raw['message'] = 'Stopped.';
            $this->writeStatus($raw);
        }

        return $this->publicStatus($raw);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function patch(array $patch): void
    {
        $raw = $this->readRaw();
        foreach ($patch as $key => $value) {
            $raw[$key] = $value;
        }
        $this->writeStatus($raw);
    }

    public function addVia(string $via): void
    {
        $raw = $this->readRaw();
        $viaList = is_array($raw['via'] ?? null) ? $raw['via'] : [];
        if (!in_array($via, $viaList, true)) {
            $viaList[] = $via;
        }
        $raw['via'] = $viaList;
        $this->writeStatus($raw);
    }

    public function recordAppCommand(string $command, string $via): void
    {
        $command = trim($command);
        if ($command === '' || str_contains($command, '/') || strlen($command) > 80) {
            return;
        }
        $raw = $this->readRaw();
        $app = is_array($raw['app_commands'] ?? null) ? $raw['app_commands'] : [];
        $app[$command] = (int) ($app[$command] ?? 0) + 1;
        $raw['app_commands'] = $app;
        $viaList = is_array($raw['via'] ?? null) ? $raw['via'] : [];
        if (!in_array($via, $viaList, true)) {
            $viaList[] = $via;
        }
        $raw['via'] = $viaList;
        $raw['unknown_commands'] = $this->unknownCommands($app);
        $this->writeStatus($raw);
    }

    public function recordFeedbackTopic(string $topic): void
    {
        $topic = trim($topic);
        if ($topic === '' || strlen($topic) > 80) {
            return;
        }
        $raw = $this->readRaw();
        $topics = is_array($raw['feedback_topics'] ?? null) ? $raw['feedback_topics'] : [];
        $topics[$topic] = (int) ($topics[$topic] ?? 0) + 1;
        $raw['feedback_topics'] = $topics;
        $this->writeStatus($raw);
    }

    /**
     * @param array<string, int> $commands
     */
    public function mergeCloudCommands(array $commands): void
    {
        if ($commands === []) {
            return;
        }
        $raw = $this->readRaw();
        $app = is_array($raw['app_commands'] ?? null) ? $raw['app_commands'] : [];
        foreach ($commands as $name => $count) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $app[$name] = max((int) ($app[$name] ?? 0), (int) $count);
        }
        $raw['app_commands'] = $app;
        $via = is_array($raw['via'] ?? null) ? $raw['via'] : [];
        if (!in_array('cloud', $via, true)) {
            $via[] = 'cloud';
        }
        $raw['via'] = $via;
        $raw['unknown_commands'] = $this->unknownCommands($app);
        $this->writeStatus($raw);
    }

    /**
     * @param array<string, int> $app
     * @return list<string>
     */
    public function unknownCommands(array $app): array
    {
        $known = array_fill_keys(self::KNOWN_APP_COMMANDS, true);
        $unknown = [];
        foreach (array_keys($app) as $name) {
            if (!isset($known[$name])) {
                $unknown[] = (string) $name;
            }
        }
        sort($unknown);

        return $unknown;
    }

    /**
     * @return array<string, mixed>
     */
    private function idleStatus(): array
    {
        return [
            'ok' => true,
            'state' => 'idle',
            'duration_s' => self::DURATION_SECONDS,
            'remaining_s' => 0,
            'via' => [],
            'app_commands' => new \stdClass(),
            'feedback_topics' => new \stdClass(),
            'unknown_commands' => [],
            'message' => 'Not listening.',
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readRaw(): array
    {
        $path = $this->statusPath();
        if (!is_file($path)) {
            return $this->idleStatus();
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : $this->idleStatus();
    }

    /**
     * @param array<string, mixed> $status
     */
    private function writeStatus(array $status): void
    {
        $this->ensureDataDir();
        $tmp = $this->statusPath() . '.tmp';
        file_put_contents(
            $tmp,
            json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        rename($tmp, $this->statusPath());
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function publicStatus(array $raw): array
    {
        $app = $raw['app_commands'] ?? [];
        if ($app instanceof \stdClass) {
            $app = [];
        }
        if (!is_array($app)) {
            $app = [];
        }
        $feedback = $raw['feedback_topics'] ?? [];
        if ($feedback instanceof \stdClass) {
            $feedback = [];
        }
        if (!is_array($feedback)) {
            $feedback = [];
        }

        return [
            'ok' => true,
            'state' => (string) ($raw['state'] ?? 'idle'),
            'duration_s' => (int) ($raw['duration_s'] ?? self::DURATION_SECONDS),
            'remaining_s' => max(0, (int) ($raw['remaining_s'] ?? 0)),
            'via' => array_values(array_filter((array) ($raw['via'] ?? []))),
            'app_commands' => $app,
            'feedback_topics' => $feedback,
            'unknown_commands' => $this->unknownCommands($app + $feedback),
            'message' => (string) ($raw['message'] ?? ''),
            'error' => $raw['error'] ?? null,
            'started_at' => $raw['started_at'] ?? null,
            'finished_at' => $raw['finished_at'] ?? null,
        ];
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function ensureDataDir(): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
