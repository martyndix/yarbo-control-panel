<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * AirPlay receiver (shairport-sync → FIFO) for bridging into Protect talkback.
 */
final class YarboPanelAirPlay
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/airplay/config.json';
    }

    public function fifoPath(): string
    {
        return $this->projectRoot . '/data/airplay/audio.fifo';
    }

    /**
     * @return array{name: string}
     */
    public function config(): array
    {
        $defaults = ['name' => 'Yarbo Speakers'];
        $decoded = $this->readJson($this->configPath());
        if (!is_array($decoded)) {
            return $defaults;
        }
        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name === '') {
            $name = $defaults['name'];
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 48);
        } else {
            $name = substr($name, 0, 48);
        }

        return ['name' => $name];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string}
     */
    public function saveConfig(array $input): array
    {
        $name = trim((string) ($input['name'] ?? $input['airplay_name'] ?? ''));
        if ($name === '') {
            $name = 'Yarbo Speakers';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 48);
        } else {
            $name = substr($name, 0, 48);
        }
        $dir = dirname($this->configPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode(['name' => $name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->configPath(), $json . "\n", LOCK_EX);
        }

        return $this->config();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config();
        $installed = $this->binaryInstalled('shairport-sync');
        $nqptp = $this->binaryInstalled('nqptp');
        $running = $this->receiverRunning();
        $log = $this->logTail();

        return [
            'ok' => true,
            'installed' => $installed,
            'nqptp' => $nqptp,
            'running' => $running,
            'name' => $cfg['name'],
            'fifo' => $this->fifoPath(),
            'fifo_ready' => file_exists($this->fifoPath()),
            'message' => !$installed
                ? 'shairport-sync is not installed on this panel'
                : ($running
                    ? ('AirPlay receiver is running as ' . $cfg['name'])
                    : 'AirPlay receiver is off — tap Start AirPlay'),
            'log_tail' => $log,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensure(): array
    {
        $script = $this->projectRoot . '/scripts/panel_airplay_ensure.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'panel_airplay_ensure.sh missing'] + $this->status();
        }

        return $this->runScript($script) + ['config' => $this->config()];
    }

    /**
     * @return array<string, mixed>
     */
    public function stopReceiver(): array
    {
        $script = $this->projectRoot . '/scripts/panel_airplay_stop.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'panel_airplay_stop.sh missing'] + $this->status();
        }

        return $this->runScript($script) + ['config' => $this->config()];
    }

    private function binaryInstalled(string $name): bool
    {
        $out = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        return $out !== '' && is_executable($out);
    }

    private function receiverRunning(): bool
    {
        $pf = $this->projectRoot . '/data/airplay/shairport-sync.pid';
        if (is_file($pf)) {
            $pid = (int) trim((string) @file_get_contents($pf));
            if ($pid > 1 && $this->pidAlive($pid)) {
                return true;
            }
        }
        $out = trim((string) shell_exec('pgrep -x shairport-sync 2>/dev/null | head -n1'));

        return $out !== '' && ctype_digit($out);
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = trim((string) shell_exec('kill -0 ' . $pid . ' 2>/dev/null; echo $?'));

        return $out === '0';
    }

    /**
     * @return array<string, mixed>
     */
    private function runScript(string $script): array
    {
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
            ]
        );
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start AirPlay script'];
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
                'error' => trim($stderr !== '' ? $stderr : 'Invalid JSON from AirPlay script'),
                'stdout' => $stdout,
                'log_tail' => $this->logTail(),
            ];
        }
        if (!empty($decoded['log_tail']) && is_string($decoded['log_tail'])) {
            $decoded['log_tail'] = str_replace('|', "\n", $decoded['log_tail']);
        }

        return $decoded;
    }

    private function logTail(): ?string
    {
        $path = $this->projectRoot . '/data/panel-airplay.log';
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return null;
        }
        $lines = preg_split('/\R/', trim($raw)) ?: [];

        return implode("\n", array_slice($lines, -25));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
