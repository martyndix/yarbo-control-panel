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

        return [
            'host' => $host,
            'rtsp_url' => self::rtspForHost($host),
            'last_ok' => (bool) ($decoded['last_ok'] ?? false),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
            'last_check' => isset($decoded['last_check']) && is_string($decoded['last_check'])
                ? $decoded['last_check']
                : null,
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
        $next = [
            'host' => $host,
            'rtsp_url' => self::rtspForHost($host),
            'last_ok' => array_key_exists('last_ok', $input) ? (bool) $input['last_ok'] : $current['last_ok'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
            'last_check' => $input['last_check'] ?? $current['last_check'],
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
            'host' => $config['host'],
            'rtsp_url' => $config['rtsp_url'],
            'last_ok' => $config['last_ok'],
            'last_error' => $config['last_error'] !== '' ? $config['last_error'] : null,
            'last_check' => $config['last_check'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array
    {
        $config = $this->load();

        return [
            'ok' => $config['last_ok'],
            'online' => $config['last_ok'],
            'host' => $config['host'],
            'rtsp_url' => $config['rtsp_url'],
            'snapshot' => '/api/lymow.php?action=snapshot',
            'last_check' => $config['last_check'],
            'error' => $config['last_error'] !== '' ? $config['last_error'] : null,
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
        $online = !empty($data['online']);
        $verb = $online ? 'CAMERA' : 'OFFLINE';
        $line3 = $online ? 'RTSP         OK' : 'RTSP       DOWN';
        $lines = [
            str_pad('LYMOW', 6) . str_pad($verb, 9, ' ', STR_PAD_LEFT),
            'MOWER CAMERA  ',
            $line3,
        ];
        $codes = [];
        foreach ($lines as $line) {
            $row = [];
            $padded = str_pad(strtoupper($line), 15);
            for ($c = 0; $c < 15; $c++) {
                $row[] = $this->charToCode($padded[$c] ?? ' ');
            }
            $codes[] = $row;
        }
        $codes[0][14] = $online ? YarboVestaboard::COLOR_GREEN : YarboVestaboard::COLOR_RED;

        return [
            'ok' => true,
            'online' => $online,
            'lines' => YarboVestaboard::linesFromCodes($codes),
            'codes' => $codes,
            'verb' => $verb,
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

        return 0;
    }
}
