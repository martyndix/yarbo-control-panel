<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboLymow
{
    public const DEFAULT_RTSP = 'rtsp://192.168.40.154:10022/h264ESVideoTest';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/lymow-config.json';
    }

    /**
     * @return array{rtsp_url: string, last_ok: bool, last_error: string, last_check: ?string}
     */
    public function load(): array
    {
        $defaults = [
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

        return [
            'rtsp_url' => trim((string) ($decoded['rtsp_url'] ?? self::DEFAULT_RTSP)) ?: self::DEFAULT_RTSP,
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
        $url = array_key_exists('rtsp_url', $input) || array_key_exists('lymow_rtsp_url', $input)
            ? trim((string) ($input['rtsp_url'] ?? $input['lymow_rtsp_url'] ?? ''))
            : $current['rtsp_url'];
        if ($url === '') {
            $url = self::DEFAULT_RTSP;
        }
        $next = [
            'rtsp_url' => $url,
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
            'rtsp_url' => $config['rtsp_url'],
            'stream' => '/api/lymow.php?action=stream',
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
        $url = $this->load()['rtsp_url'];
        $ffmpeg = $this->ffmpegPath();
        $cmd = sprintf(
            '%s -hide_banner -loglevel error -rtsp_transport tcp -stimeout 5000000 -i %s -an -frames:v 1 -f image2 pipe:1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($url),
        );
        $data = $this->runCommand($cmd);
        if ($data === '') {
            throw new \RuntimeException('Could not grab a still from Lymow RTSP. Is ffmpeg installed, and is the mower on the LAN?');
        }
        $this->save(['last_ok' => true, 'last_error' => '', 'last_check' => gmdate('c')]);

        return $data;
    }

    public function streamMjpeg(): void
    {
        $url = $this->load()['rtsp_url'];
        $ffmpeg = $this->ffmpegPath();
        $cmd = sprintf(
            '%s -hide_banner -loglevel error -rtsp_transport tcp -stimeout 5000000 -i %s -an -f mjpeg -q:v 5 pipe:1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($url),
        );
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Content-Type: multipart/x-mixed-replace; boundary=frame');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start ffmpeg');
        }
        stream_set_blocking($pipes[1], false);
        $buffer = '';
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                usleep(20000);
                continue;
            }
            $buffer .= $chunk;
            while (($start = strpos($buffer, "\xff\xd8")) !== false) {
                $next = strpos($buffer, "\xff\xd8", $start + 2);
                if ($next === false) {
                    break;
                }
                $frame = substr($buffer, $start, $next - $start);
                $buffer = substr($buffer, $next);
                echo "--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " . strlen($frame) . "\r\n\r\n";
                echo $frame . "\r\n";
                flush();
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    private function tcpReachable(string $rtsp): bool
    {
        $host = '';
        $port = 10022;
        if (preg_match('#rtsp://([^/:]+)(?::(\d+))?#i', $rtsp, $matches)) {
            $host = $matches[1];
            if (isset($matches[2]) && $matches[2] !== '') {
                $port = (int) $matches[2];
            }
        }
        if ($host === '') {
            return false;
        }
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    private function ffmpegPath(): string
    {
        $config = @include $this->projectRoot . '/config.php';
        $fromConfig = is_array($config) ? trim((string) ($config['ffmpeg_path'] ?? '')) : '';
        if ($fromConfig !== '') {
            return $fromConfig;
        }
        $which = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        return $which !== '' ? $which : 'ffmpeg';
    }

    private function runCommand(string $cmd): string
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return '';
        }
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
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
