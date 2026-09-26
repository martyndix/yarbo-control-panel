<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboMetrics
{
    public const DEFAULT_URL = 'https://yarbo-panel-metrics.martyndix.workers.dev/ping';
    private const MIN_INTERVAL_S = 72000;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function enabled(): bool
    {
        $env = getenv('YARBO_METRICS');
        if ($env === '0' || strtolower((string) $env) === 'false' || strtolower((string) $env) === 'off') {
            return false;
        }
        $config = @include $this->projectRoot . '/config.php';
        if (is_array($config) && array_key_exists('metrics_enabled', $config)) {
            return (bool) $config['metrics_enabled'];
        }

        return true;
    }

    public function pingUrl(): string
    {
        $env = getenv('YARBO_METRICS_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $config = @include $this->projectRoot . '/config.php';
        if (is_array($config) && is_string($config['metrics_url'] ?? null) && $config['metrics_url'] !== '') {
            return (string) $config['metrics_url'];
        }

        return self::DEFAULT_URL;
    }

    /**
     * Fork a ping from a web request without blocking the PHP server.
     */
    public function kickBackground(): void
    {
        try {
            if (!$this->enabled() || !$this->due()) {
                return;
            }
            $script = $this->projectRoot . '/scripts/metrics_ping.php';
            if (!is_file($script)) {
                return;
            }
            $cmd = sprintf(
                '%s %s >/dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script)
            );
            exec($cmd);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{ok: bool, skipped?: bool, error?: string}
     */
    public function ping(): array
    {
        try {
            if (!$this->enabled()) {
                return ['ok' => true, 'skipped' => true];
            }
            if (!$this->due()) {
                return ['ok' => true, 'skipped' => true];
            }

            $payload = $this->payload();
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                return ['ok' => false, 'error' => 'Could not encode ping'];
            }

            $ok = $this->postJson($this->pingUrl(), $body);
            if ($ok) {
                $this->markSent();
            }

            return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Ping failed'];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Ping failed'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $hub = (new YarboHub($this->projectRoot))->load();
        $modules = is_array($hub['modules'] ?? null) ? $hub['modules'] : [];
        $vb = (new YarboVestaboard($this->projectRoot))->load();
        $paper = new YarboPaperDevice($this->projectRoot);
        $mono = 0;
        $colour = 0;
        foreach ($paper->publicDevices() as $device) {
            if (($device['kind'] ?? '') === YarboPaperDevice::KIND_COLOR) {
                $colour++;
            } else {
                $mono++;
            }
        }
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : (PHP_OS_FAMILY === 'Linux' ? 'linux' : 'other');

        return [
            'id' => $this->installId(),
            'version' => YarboChangelog::currentVersion($this->projectRoot) ?: 'unknown',
            'modules' => [
                'yarbo' => !empty($modules[YarboHub::MODULE_YARBO]),
                'powerwall' => !empty($modules[YarboHub::MODULE_POWERWALL]),
                'lymow' => !empty($modules[YarboHub::MODULE_LYMOW]),
                'vestaboard' => !empty($vb['enabled']),
            ],
            'paper' => [
                'papermono' => $mono,
                'papercolor' => $colour,
            ],
            'os' => $os,
        ];
    }

    public function installId(): string
    {
        $path = $this->idPath();
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $id = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
            if ($id !== '' && preg_match('/^[a-f0-9-]{16,64}$/i', $id)) {
                return $id;
            }
        }
        $id = $this->newId();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode(['id' => $id], JSON_UNESCAPED_SLASHES) . "\n");

        return $id;
    }

    private function postJson(string $url, string $body): bool
    {
        $curl = $this->curlPath();
        if ($curl !== null && $this->postWithCurl($curl, $url, $body)) {
            return true;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: yarbo-control-panel\r\n",
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return is_string($result) && $status >= 200 && $status < 300;
    }

    private function postWithCurl(string $curl, string $url, string $body): bool
    {
        $proc = proc_open(
            [
                $curl,
                '-sS',
                '--max-time', '10',
                '--connect-timeout', '5',
                '-A', 'yarbo-control-panel',
                '-H', 'Content-Type: application/json',
                '-X', 'POST',
                '--data-binary', '@-',
                '-o', '-',
                '-w', "\n%{http_code}",
                $url,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot
        );
        if (!is_resource($proc)) {
            return false;
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (!is_string($out)) {
            return false;
        }
        $out = trim($out);
        if (!preg_match('/(\d{3})$/', $out, $m)) {
            return false;
        }

        return $code === 0 && (int) $m[1] >= 200 && (int) $m[1] < 300;
    }

    private function curlPath(): ?string
    {
        foreach (['/usr/bin/curl', '/usr/local/bin/curl', '/opt/homebrew/bin/curl'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function due(): bool
    {
        $path = $this->lastPath();
        $version = YarboChangelog::currentVersion($this->projectRoot) ?: 'unknown';
        if (!is_file($path)) {
            return true;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $at = is_array($decoded) ? (int) ($decoded['sent_at'] ?? 0) : 0;
        $lastVersion = is_array($decoded) ? (string) ($decoded['version'] ?? '') : '';
        if ($lastVersion !== $version) {
            return true;
        }

        return $at <= 0 || (time() - $at) >= self::MIN_INTERVAL_S;
    }

    private function markSent(): void
    {
        $path = $this->lastPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'sent_at' => time(),
            'version' => YarboChangelog::currentVersion($this->projectRoot) ?: 'unknown',
        ], JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function idPath(): string
    {
        return $this->projectRoot . '/data/install-id.json';
    }

    private function lastPath(): string
    {
        return $this->projectRoot . '/data/metrics-last.json';
    }
}
