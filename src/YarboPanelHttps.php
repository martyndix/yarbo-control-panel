<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * LAN HTTPS (:8443) status / ensure for browser mic (Announce Record).
 */
final class YarboPanelHttps
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $port = $this->httpsPort();
        $listening = $this->portAccepts($port);
        $log = $this->logTail();
        $host = $this->guessLanHost();

        return [
            'ok' => true,
            'enabled' => $this->enabled(),
            'listening' => $listening,
            'https_port' => $port,
            'http_port' => $this->httpPort(),
            'https_url' => $host !== '' ? 'https://' . $host . ':' . $port : null,
            'message' => $listening
                ? ('HTTPS is up on port ' . $port)
                : ('HTTPS is not listening on port ' . $port),
            'log_tail' => $log,
        ];
    }

    /**
     * Start the proxy if needed (and try to open ufw for the port).
     *
     * @return array<string, mixed>
     */
    public function ensure(): array
    {
        $script = $this->projectRoot . '/scripts/panel_https_ensure.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'panel_https_ensure.sh missing'];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['bash', $script],
            $descriptorSpec,
            $pipes,
            $this->projectRoot,
            [
                'HOME' => getenv('HOME') ?: $this->projectRoot,
                'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'YARBO_PANEL_PORT' => (string) $this->httpPort(),
                'YARBO_PANEL_HTTPS_PORT' => (string) $this->httpsPort(),
                'YARBO_PANEL_HTTPS' => getenv('YARBO_PANEL_HTTPS') !== false
                    ? (string) getenv('YARBO_PANEL_HTTPS')
                    : '1',
            ]
        );
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start HTTPS ensure script'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => trim($stderr !== '' ? $stderr : 'Invalid JSON from HTTPS ensure script'),
                'stdout' => $stdout,
                'log_tail' => $this->logTail(),
            ];
        }

        $host = $this->guessLanHost();
        $port = (int) ($decoded['https_port'] ?? $this->httpsPort());
        if ($host !== '' && empty($decoded['https_url'])) {
            $decoded['https_url'] = 'https://' . $host . ':' . $port;
        }
        if (!empty($decoded['log_tail']) && is_string($decoded['log_tail'])) {
            $decoded['log_tail'] = str_replace('|', "\n", $decoded['log_tail']);
        }

        return $decoded;
    }

    private function enabled(): bool
    {
        $flag = getenv('YARBO_PANEL_HTTPS');
        if ($flag === false || $flag === '') {
            return true;
        }

        return !in_array(strtolower((string) $flag), ['0', 'false', 'off', 'no'], true);
    }

    private function httpsPort(): int
    {
        $port = (int) (getenv('YARBO_PANEL_HTTPS_PORT') ?: 8443);

        return $port > 0 ? $port : 8443;
    }

    private function httpPort(): int
    {
        $port = (int) (getenv('YARBO_PANEL_PORT') ?: 8080);

        return $port > 0 ? $port : 8080;
    }

    private function portAccepts(int $port): bool
    {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    private function logTail(): ?string
    {
        $path = $this->projectRoot . '/data/panel-https.log';
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return null;
        }
        $lines = preg_split('/\R/', trim($raw)) ?: [];
        $tail = array_slice($lines, -25);

        return implode("\n", $tail);
    }

    private function guessLanHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = trim($host);
        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            return $host;
        }
        // Best-effort from hostname -I when running on the Pi.
        $out = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $out) === 1) {
            return $out;
        }

        return $host !== '' ? $host : '127.0.0.1';
    }
}
