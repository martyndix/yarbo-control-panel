<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboLymow
{
    public const DEFAULT_HOST = '192.168.40.154';
    public const RTSP_PORT = 10022;
    public const RTSP_PATH = '/h264ESVideoTest';
    public const DEFAULT_RTSP = 'rtsp://192.168.40.154:10022/h264ESVideoTest';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/lymow-config.json';
    }

    public static function rtspForHost(string $host): string
    {
        return 'rtsp://' . self::normalizeHost($host) . ':' . self::RTSP_PORT . self::RTSP_PATH;
    }

    public static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::DEFAULT_HOST;
        }
        if (preg_match('#rtsp://([^/:]+)#i', $value, $matches)) {
            return $matches[1];
        }
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $host = explode('/', explode(':', $value, 2)[0])[0];

        return $host !== '' ? $host : self::DEFAULT_HOST;
    }

    /**
     * @return array{host: string, rtsp_url: string, last_ok: bool, last_error: string, last_check: ?string}
     */
    public function load(): array
    {
        $defaults = [
            'host' => self::DEFAULT_HOST,
            'rtsp_url' => self::DEFAULT_RTSP,
            'last_ok' => false,
            'last_error' => '',
            'last_check' => null,
            'email' => '',
            'password' => '',
            'region' => 'auto',
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        $host = self::normalizeHost((string) ($decoded['host'] ?? $decoded['rtsp_url'] ?? self::DEFAULT_HOST));
        $region = strtolower(trim((string) ($decoded['region'] ?? 'auto')));
        if ($region === '') {
            $region = 'auto';
        }

        return [
            'host' => $host,
            'rtsp_url' => self::rtspForHost($host),
            'last_ok' => (bool) ($decoded['last_ok'] ?? false),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
            'last_check' => isset($decoded['last_check']) && is_string($decoded['last_check'])
                ? $decoded['last_check']
                : null,
            'email' => trim((string) ($decoded['email'] ?? '')),
            'password' => (string) ($decoded['password'] ?? ''),
            'region' => $region,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $current = $this->load();
        $hostInput = $input['lymow_host'] ?? $input['lymow_rtsp_url'] ?? $input['rtsp_url'] ?? null;
        $host = $hostInput !== null
            ? self::normalizeHost((string) $hostInput)
            : $current['host'];
        $email = array_key_exists('lymow_email', $input) || array_key_exists('email', $input)
            ? trim((string) ($input['lymow_email'] ?? $input['email'] ?? ''))
            : $current['email'];
        $password = $current['password'];
        if (array_key_exists('lymow_password', $input) || array_key_exists('password', $input)) {
            $nextPassword = (string) ($input['lymow_password'] ?? $input['password'] ?? '');
            if ($nextPassword !== '') {
                $password = $nextPassword;
            }
        }
        $region = $current['region'];
        if (array_key_exists('lymow_region', $input) || array_key_exists('region', $input)) {
            $region = strtolower(trim((string) ($input['lymow_region'] ?? $input['region'] ?? 'auto'))) ?: 'auto';
        }
        $next = [
            'host' => $host,
            'rtsp_url' => self::rtspForHost($host),
            'last_ok' => array_key_exists('last_ok', $input) ? (bool) $input['last_ok'] : $current['last_ok'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
            'last_check' => $input['last_check'] ?? $current['last_check'],
            'email' => $email,
            'password' => $password,
            'region' => $region,
        ];
        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $ok = file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
        if ($ok && ($email !== '' || $password !== '')) {
            $this->syncCloudFile($next);
        }

        return $ok;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $config = $this->load();

        return [
            'host' => $config['host'],
            'rtsp_url' => $config['rtsp_url'],
            'last_ok' => $config['last_ok'],
            'last_error' => $config['last_error'] !== '' ? $config['last_error'] : null,
            'last_check' => $config['last_check'],
            'email' => $config['email'],
            'region' => $config['region'],
            'password_set' => $config['password'] !== '',
            'signed_in' => $this->cloudSignedIn(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array
    {
        $config = $this->load();
        $cloud = $this->readCloudState();
        $battery = isset($cloud['battery']) ? (int) $cloud['battery'] : null;
        $work = isset($cloud['work_status']) ? (int) $cloud['work_status'] : null;
        $ip = trim((string) ($cloud['ip_address'] ?? ''));
        if ($ip !== '' && ($config['host'] === self::DEFAULT_HOST || $config['host'] === '')) {
            $config['host'] = self::normalizeHost($ip);
        }
        $cloudOk = !empty($cloud['ok']) || $battery !== null || !empty($cloud['online']);
        $camOk = (bool) $config['last_ok'];

        return [
            'ok' => $camOk || $cloudOk,
            'online' => $camOk || !empty($cloud['online']) || $battery !== null,
            'host' => $config['host'],
            'rtsp_url' => $config['rtsp_url'],
            'snapshot' => '/api/lymow.php?action=snapshot',
            'live' => '/api/lymow.php?action=live',
            'last_check' => $config['last_check'],
            'error' => $config['last_error'] !== '' ? $config['last_error'] : ($cloud['error'] ?? null),
            'camera_ok' => $camOk,
            'signed_in' => $this->cloudSignedIn(),
            'battery' => $battery,
            'battery_label' => $battery !== null ? $battery . '%' : '—',
            'work_status' => $work,
            'work_label' => self::workLabel($work, !empty($cloud['is_charging'])),
            'charging' => !empty($cloud['is_charging']) || !empty($cloud['is_recharging']),
            'charging_label' => !empty($cloud['is_charging']) ? 'Yes' : (!empty($cloud['is_recharging']) ? 'Returning' : 'No'),
            'mow_progress' => isset($cloud['mow_progress']) ? (float) $cloud['mow_progress'] : null,
            'device_name' => $cloud['device_name'] ?? null,
            'cloud_updated' => $cloud['fetched_at'] ?? null,
            'cloud_error' => $this->cloudHint($cloud, $battery),
        ];
    }

    public function probe(): array
    {
        $url = $this->load()['rtsp_url'];
        $ok = $this->tcpReachable($url);
        $this->save([
            'last_ok' => $ok,
            'last_error' => $ok ? '' : 'Could not reach the Lymow RTSP port.',
            'last_check' => gmdate('c'),
        ]);

        return $this->dashboardPayload();
    }

    public function refreshIfStale(): void
    {
        $hub = new YarboHub($this->projectRoot);
        if (!$hub->enabled(YarboHub::MODULE_LYMOW)) {
            return;
        }
        $this->refreshCloudIfStale();
        $check = $this->load()['last_check'];
        $then = is_string($check) ? strtotime($check) : false;
        if ($then !== false && (time() - $then) < 20) {
            return;
        }
        $this->probe();
    }

    /**
     * @return array{ok: bool, online: bool, lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function vestaboardLayout(): array
    {
        $data = $this->dashboardPayload();
        $battery = isset($data['battery']) ? (int) $data['battery'] : null;
        $camOk = !empty($data['camera_ok']);
        $cloudOk = $battery !== null || !empty($data['online']);
        $showLive = $camOk || $cloudOk;
        $work = (string) ($data['work_label'] ?? '—');
        if ($work === '—' || $work === '') {
            $work = $battery !== null ? 'IDLE' : '--';
        }
        $progress = isset($data['mow_progress']) ? (int) round((float) $data['mow_progress']) : null;
        $workRight = ($work === 'MOWING' && $progress !== null) ? $progress . '%' : '';
        $lines = [
            $showLive
                ? $this->pair('LYMOW', $battery !== null ? $battery . '%' : '--', 14)
                : $this->pair('OFFLINE', '', 14),
            $this->pair($work, $workRight),
            $this->pair('CAM', $camOk ? 'UP' : 'DOWN'),
        ];
        $codes = $this->encodeLines($lines, $battery, $showLive);

        return [
            'ok' => true,
            'online' => $showLive,
            'lines' => YarboVestaboard::linesFromCodes($codes),
            'codes' => $codes,
            'verb' => $showLive ? 'LYMOW' : 'OFFLINE',
        ];
    }

    public function snapshotJpeg(): string
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $jpgPath = $dir . '/lymow-snapshot.jpg';
        $lockPath = $dir . '/lymow-snapshot.lock';
        if (is_file($jpgPath) && (time() - (int) filemtime($jpgPath)) < 2) {
            $cached = (string) file_get_contents($jpgPath);
            if (str_starts_with($cached, "\xff\xd8")) {
                return $cached;
            }
        }

        $lock = fopen($lockPath, 'c');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Could not lock Lymow snapshot capture.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $stale = is_file($jpgPath) ? (string) file_get_contents($jpgPath) : '';
            fclose($lock);
            if (str_starts_with($stale, "\xff\xd8")) {
                return $stale;
            }
            throw new \RuntimeException('Lymow camera is busy capturing a frame. Try again in a moment.');
        }

        try {
            if (is_file($jpgPath) && (time() - (int) filemtime($jpgPath)) < 2) {
                $cached = (string) file_get_contents($jpgPath);
                if (str_starts_with($cached, "\xff\xd8")) {
                    return $cached;
                }
            }
            $url = $this->load()['rtsp_url'];
            $ffmpeg = $this->ffmpegBinary();
            $args = [
                '-hide_banner',
                '-loglevel', 'error',
                '-nostdin',
                '-rtsp_transport', 'tcp',
                '-i', $url,
                '-an',
                '-frames:v', '1',
                '-q:v', '5',
                '-f', 'mjpeg',
                'pipe:1',
            ];
            $ran = $this->runFfmpeg($ffmpeg, $args, 12.0);
            $jpeg = $ran['out'];
            if ($jpeg === '' || !str_starts_with($jpeg, "\xff\xd8")) {
                $err = trim($ran['err']);
                if ($err === '') {
                    $err = 'No JPEG from Lymow RTSP. Is the mower on, and is ffmpeg installed?';
                }
                $this->save([
                    'last_ok' => false,
                    'last_error' => $err,
                    'last_check' => gmdate('c'),
                ]);
                throw new \RuntimeException($err);
            }
            file_put_contents($jpgPath, $jpeg);
            $this->save(['last_ok' => true, 'last_error' => '', 'last_check' => gmdate('c')]);

            return $jpeg;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function streamMjpeg(): void
    {
        $jpeg = $this->snapshotJpeg();
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) strlen($jpeg));
        echo $jpeg;
    }

    private function tcpReachable(string $rtsp): bool
    {
        $host = self::normalizeHost($rtsp);
        $fp = @fsockopen($host, self::RTSP_PORT, $errno, $errstr, 1.5);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    private function ffmpegBinary(): string
    {
        $config = @include $this->projectRoot . '/config.php';
        $fromConfig = is_array($config) ? trim((string) ($config['ffmpeg_path'] ?? '')) : '';
        if ($fromConfig !== '' && $fromConfig !== 'ffmpeg' && is_file($fromConfig)) {
            return $fromConfig;
        }
        $which = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($which === '') {
            throw new \RuntimeException('ffmpeg is not installed on this panel host. On a Pi: sudo apt install -y ffmpeg');
        }

        return $which;
    }

    /**
     * @param list<string> $args
     * @return array{out: string, err: string}
     */
    private function runFfmpeg(string $ffmpeg, array $args, float $timeout): array
    {
        $cmd = array_merge([$ffmpeg], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $proc = proc_open($escaped, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['out' => '', 'err' => 'Failed to start ffmpeg'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $out .= (string) fread($pipes[1], 8192);
            $err .= (string) fread($pipes[2], 8192);
            $status = proc_get_status($proc);
            if (!($status['running'] ?? true)) {
                break;
            }
            usleep(30000);
        }
        $status = proc_get_status($proc);
        if ($status['running'] ?? false) {
            proc_terminate($proc, 9);
            $err = trim($err . ' ffmpeg timed out after ' . (int) $timeout . 's');
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return ['out' => $out, 'err' => trim($err)];
    }

    public function cloudConfigPath(): string
    {
        return $this->projectRoot . '/data/lymow-cloud.json';
    }

    public function cloudStatePath(): string
    {
        return $this->projectRoot . '/data/lymow-state.json';
    }

    public function loginCloud(): array
    {
        $this->syncCloudFile($this->load());
        $deps = $this->runBridge(['deps'], 130.0);
        if (!($deps['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($deps['error'] ?? 'Could not install paho-mqtt / websocket-client on this host.'),
            ];
        }
        $result = $this->runBridge(['login'], 70.0);
        if (!($result['ok'] ?? false)) {
            return $result;
        }
        if (!empty($result['ip_address'])) {
            $this->save(['lymow_host' => (string) $result['ip_address']]);
        }
        $this->startListener();
        $dash = $this->dashboardPayload();
        $batt = $dash['battery_label'] ?? '—';
        $work = $dash['work_label'] ?? '—';
        $message = 'Signed in to Lymow.';
        if (($dash['battery'] ?? null) !== null) {
            $message = 'Signed in. Battery ' . $batt . ', ' . $work . '.';
        } elseif (!empty($dash['cloud_error'])) {
            $message = 'Signed in, but live battery failed: ' . (string) $dash['cloud_error'];
        } else {
            $message = 'Signed in. Waiting for Lymow MQTT battery (can take up to a minute).';
        }

        return ['ok' => true, 'message' => $message] + $dash;
    }

    public function refreshCloud(): array
    {
        if (!$this->cloudSignedIn() && $this->load()['email'] === '') {
            return ['ok' => false, 'error' => 'Enter the Lymow app email and password in Settings first.'];
        }
        $this->syncCloudFile($this->load());
        $this->startListener();
        $result = $this->runBridge(['state', '--wait', '25'], 40.0);
        if (!($result['ok'] ?? false)) {
            return $result;
        }
        if (!empty($result['ip_address']) && $this->load()['host'] === self::DEFAULT_HOST) {
            $this->save(['lymow_host' => (string) $result['ip_address']]);
        }

        return ['ok' => true, 'message' => 'Updated Lymow status.'] + $this->dashboardPayload();
    }

    public function refreshCloudIfStale(): void
    {
        if (!$this->cloudSignedIn()) {
            return;
        }
        $this->startListener();
    }

    /**
     * @return array<string, mixed>
     */
    public function startListener(): array
    {
        if ($this->listenRunning()) {
            return ['ok' => true, 'running' => true];
        }
        $script = $this->projectRoot . '/scripts/lymow_bridge.py';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'scripts/lymow_bridge.py is missing.'];
        }
        $log = $this->projectRoot . '/data/lymow-listen.log';
        $cmd = implode(' ', [
            'nohup',
            escapeshellarg($this->pythonBin()),
            escapeshellarg($script),
            'listen',
            '--config', escapeshellarg($this->cloudConfigPath()),
            '--state', escapeshellarg($this->cloudStatePath()),
            '>>', escapeshellarg($log),
            '2>&1',
            '&',
            'echo $!',
        ]);
        $pidLine = [];
        exec($cmd, $pidLine);
        $pid = (int) ($pidLine[0] ?? 0);
        if ($pid < 1) {
            return ['ok' => false, 'error' => 'Could not start the Lymow MQTT listener.'];
        }
        file_put_contents($this->listenPidPath(), (string) $pid . "\n");

        return ['ok' => true, 'running' => true, 'pid' => $pid];
    }

    private function listenPidPath(): string
    {
        return $this->projectRoot . '/data/lymow-listen.pid';
    }

    private function listenPid(): int
    {
        if (!is_file($this->listenPidPath())) {
            return 0;
        }

        return (int) trim((string) file_get_contents($this->listenPidPath()));
    }

    private function listenRunning(): bool
    {
        $pid = $this->listenPid();
        if ($pid < 1) {
            return false;
        }
        if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $cloud
     */
    private function cloudHint(array $cloud, ?int $battery): ?string
    {
        $err = $cloud['error'] ?? $cloud['mqtt_error'] ?? null;
        if (is_string($err) && $err !== '') {
            return $err;
        }
        if ($this->cloudSignedIn() && $battery === null) {
            return 'Waiting for Lymow MQTT battery (can take up to a minute).';
        }

        return null;
    }

    public function startLive(): array
    {
        if ($this->liveRunning()) {
            return ['ok' => true, 'running' => true];
        }
        try {
            $ffmpeg = $this->ffmpegBinary();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $url = $this->load()['rtsp_url'];
        $jpg = $this->liveJpgPath();
        $log = $this->projectRoot . '/data/lymow-live.log';
        $cmd = implode(' ', [
            'nohup',
            escapeshellarg($ffmpeg),
            '-hide_banner',
            '-loglevel', 'error',
            '-nostdin',
            '-rtsp_transport', 'tcp',
            '-i', escapeshellarg($url),
            '-an',
            '-vf', 'fps=5',
            '-q:v', '5',
            '-update', '1',
            '-y',
            escapeshellarg($jpg),
            '>>', escapeshellarg($log),
            '2>&1',
            '&',
            'echo $!',
        ]);
        $pidLine = [];
        exec($cmd, $pidLine);
        $pid = (int) ($pidLine[0] ?? 0);
        if ($pid < 1) {
            return ['ok' => false, 'error' => 'Could not start ffmpeg for the Lymow stream.'];
        }
        file_put_contents($this->livePidPath(), (string) $pid . "\n");

        return ['ok' => true, 'running' => true, 'pid' => $pid];
    }

    public function stopLive(): void
    {
        $pid = $this->livePid();
        if ($pid > 0) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 15);
                usleep(150000);
                @posix_kill($pid, 9);
            } else {
                exec('kill -TERM ' . (int) $pid . ' 2>/dev/null');
            }
        }
        @unlink($this->livePidPath());
    }

    public function liveJpeg(): string
    {
        $this->startLive();
        $jpg = $this->liveJpgPath();
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (is_file($jpg) && filesize($jpg) > 128) {
                $data = (string) file_get_contents($jpg);
                if (str_starts_with($data, "\xff\xd8")) {
                    return $data;
                }
            }
            usleep(80000);
        }

        return $this->snapshotJpeg();
    }

    public static function workLabel(?int $status, bool $charging = false): string
    {
        if ($charging && !in_array($status, [2, 8, 9], true)) {
            return 'CHARGE';
        }

        return match ($status) {
            0 => 'IDLE',
            1 => 'WAIT',
            2, 8, 9 => 'MOWING',
            3 => 'PAUSE',
            4, 10 => 'DOCKING',
            5 => 'CHARGE',
            6 => 'REMOTE',
            7 => 'ERROR',
            11 => 'UPDATE',
            12 => 'FULL',
            13 => 'STOP',
            14 => 'ESCAPE',
            default => $status === null ? '—' : 'UNKNOWN',
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function syncCloudFile(array $config): void
    {
        $cloud = [];
        if (is_file($this->cloudConfigPath())) {
            $raw = file_get_contents($this->cloudConfigPath());
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $cloud = $decoded;
            }
        }
        $cloud['email'] = $config['email'];
        if (($config['password'] ?? '') !== '') {
            $cloud['password'] = $config['password'];
        }
        $cloud['region'] = $config['region'] ?: 'auto';
        file_put_contents(
            $this->cloudConfigPath(),
            json_encode($cloud, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    private function cloudSignedIn(): bool
    {
        if (!is_file($this->cloudConfigPath())) {
            return false;
        }
        $raw = file_get_contents($this->cloudConfigPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return false;
        }

        return trim((string) ($decoded['refresh_token'] ?? '')) !== ''
            || trim((string) ($decoded['access_token'] ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function readCloudState(): array
    {
        if (!is_file($this->cloudStatePath())) {
            return [];
        }
        $raw = file_get_contents($this->cloudStatePath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runBridge(array $args, float $timeout = 20.0): array
    {
        $script = $this->projectRoot . '/scripts/lymow_bridge.py';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'scripts/lymow_bridge.py is missing.'];
        }
        $python = $this->pythonBin();
        $cmd = array_merge(
            [$python, $script],
            $args,
            ['--config', $this->cloudConfigPath(), '--state', $this->cloudStatePath()]
        );
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $proc = proc_open($escaped, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Could not start the Lymow cloud helper.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $stdout .= (string) fread($pipes[1], 8192);
            $stderr .= (string) fread($pipes[2], 8192);
            $status = proc_get_status($proc);
            if (!($status['running'] ?? true)) {
                break;
            }
            usleep(40000);
        }
        $status = proc_get_status($proc);
        if ($status['running'] ?? false) {
            proc_terminate($proc, 9);
            $stderr = trim($stderr . ' Lymow cloud helper timed out.');
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'ok' => false,
            'error' => trim($stderr) !== '' ? trim($stderr) : 'Lymow cloud helper returned no JSON.',
        ];
    }

    private function pythonBin(): string
    {
        $venv = $this->projectRoot . '/.venv/bin/python';
        if (is_file($venv)) {
            return $venv;
        }

        return 'python3';
    }

    private function liveJpgPath(): string
    {
        return $this->projectRoot . '/data/lymow-live.jpg';
    }

    private function livePidPath(): string
    {
        return $this->projectRoot . '/data/lymow-live.pid';
    }

    private function livePid(): int
    {
        if (!is_file($this->livePidPath())) {
            return 0;
        }

        return (int) trim((string) file_get_contents($this->livePidPath()));
    }

    private function liveRunning(): bool
    {
        $pid = $this->livePid();
        if ($pid < 1) {
            return false;
        }
        if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return true;
        }

        return is_file($this->liveJpgPath()) && (time() - (int) filemtime($this->liveJpgPath())) < 3;
    }

    private function pair(string $left, string $right, int $width = 15): string
    {
        $left = strtoupper(substr($left, 0, $width));
        $right = strtoupper(substr($right, 0, $width));
        $pad = $width - strlen($left) - strlen($right);
        if ($pad < 1) {
            return substr($left . $right, 0, $width);
        }

        return $left . str_repeat(' ', $pad) . $right;
    }

    /**
     * @param list<string> $lines
     * @return list<list<int>>
     */
    private function encodeLines(array $lines, ?int $battery, bool $online): array
    {
        $codes = [];
        for ($r = 0; $r < 3; $r++) {
            $line = str_pad(strtoupper($lines[$r] ?? ''), 15);
            $row = [];
            for ($c = 0; $c < 15; $c++) {
                $row[] = $this->charToCode($line[$c] ?? ' ');
            }
            $codes[] = $row;
        }
        $chip = YarboVestaboard::batteryPercentChip($battery, $online);
        $codes[0][14] = $chip;

        return $codes;
    }

    private function charToCode(string $ch): int
    {
        if ($ch === ' ') {
            return 0;
        }
        $ord = ord($ch);
        if ($ord >= 65 && $ord <= 90) {
            return $ord - 64;
        }
        if ($ord >= 49 && $ord <= 57) {
            return $ord - 22;
        }
        if ($ch === '0') {
            return 36;
        }
        if ($ch === '%') {
            return 54;
        }
        if ($ch === '.') {
            return 56;
        }
        if ($ch === '-') {
            return 44;
        }

        return 0;
    }
}
