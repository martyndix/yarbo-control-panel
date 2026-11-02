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

    public function metadataPipePath(): string
    {
        return $this->projectRoot . '/data/airplay/metadata.fifo';
    }

    public function bridgePath(): string
    {
        return $this->projectRoot . '/data/airplay/bridge.json';
    }

    public function sessionPath(): string
    {
        return $this->projectRoot . '/data/airplay/session.json';
    }

    public function hooksFlagPath(): string
    {
        return $this->projectRoot . '/data/airplay/hooks.json';
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
     * Speakers + volume remembered while the LAN receiver is advertised.
     *
     * @param list<string> $targets
     * @return array{armed: bool, targets: list<string>, volume: int}
     */
    public function saveBridge(array $targets, int $volume, bool $armed = true): array
    {
        $clean = [];
        foreach ($targets as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $clean[] = $id;
            }
        }
        $clean = array_values(array_unique($clean));
        $payload = [
            'armed' => $armed,
            'targets' => $clean,
            'volume' => max(0, min(100, $volume)),
            'updated_at' => time(),
        ];
        $this->writeJson($this->bridgePath(), $payload);

        return [
            'armed' => $armed,
            'targets' => $clean,
            'volume' => $payload['volume'],
        ];
    }

    /**
     * @return array{armed: bool, targets: list<string>, volume: int}
     */
    public function bridge(): array
    {
        $decoded = $this->readJson($this->bridgePath());
        $targets = [];
        if (is_array($decoded['targets'] ?? null)) {
            foreach ($decoded['targets'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $targets[] = $id;
                }
            }
        }

        return [
            'armed' => !empty($decoded['armed']),
            'targets' => array_values(array_unique($targets)),
            'volume' => max(0, min(100, (int) ($decoded['volume'] ?? 60))),
        ];
    }

    public function clearBridge(): void
    {
        $cur = $this->bridge();
        $this->saveBridge($cur['targets'], $cur['volume'], false);
    }

    /**
     * @return array{active: bool, client_name: ?string, updated_at: ?int}
     */
    public function session(): array
    {
        $decoded = $this->readJson($this->sessionPath());
        $client = trim((string) ($decoded['client_name'] ?? ''));
        if (function_exists('mb_substr')) {
            $client = mb_substr($client, 0, 64);
        } else {
            $client = substr($client, 0, 64);
        }

        return [
            'active' => !empty($decoded['active']),
            'client_name' => $client !== '' ? $client : null,
            'updated_at' => isset($decoded['updated_at']) ? (int) $decoded['updated_at'] : null,
        ];
    }

    public function markSessionActive(bool $active): void
    {
        $cur = $this->session();
        $this->writeJson($this->sessionPath(), [
            'active' => $active,
            'client_name' => $active ? $cur['client_name'] : null,
            'updated_at' => time(),
        ]);
    }

    public function setClientName(string $name): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return;
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 64);
        } else {
            $name = substr($name, 0, 64);
        }
        $cur = $this->session();
        $this->writeJson($this->sessionPath(), [
            'active' => $cur['active'],
            'client_name' => $name,
            'updated_at' => time(),
        ]);
    }

    public function sessionHooksAvailable(): bool
    {
        $decoded = $this->readJson($this->hooksFlagPath());

        return !empty($decoded['hooks']);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config();
        $bridge = $this->bridge();
        $session = $this->session();
        $installed = $this->binaryInstalled('shairport-sync');
        $nqptp = $this->binaryInstalled('nqptp');
        $running = $this->receiverRunning();
        $log = $this->logTail();
        $hooks = $this->sessionHooksAvailable();

        $message = 'AirPlay off';
        if (!$installed) {
            $message = 'shairport-sync is not installed on this panel';
        } elseif ($running && $session['active']) {
            $who = $session['client_name'] ?: 'AirPlay device';
            $message = 'Playing from ' . $who;
        } elseif ($running && $bridge['armed']) {
            $message = 'Available as ' . $cfg['name'] . ' — waiting for a device';
        } elseif ($running) {
            $message = 'AirPlay receiver is running as ' . $cfg['name'];
        } else {
            $message = 'AirPlay receiver is off — tap Start AirPlay';
        }

        return [
            'ok' => true,
            'installed' => $installed,
            'nqptp' => $nqptp,
            'running' => $running,
            'armed' => $bridge['armed'] && $running,
            'session_active' => $session['active'],
            'client_name' => $session['client_name'],
            'hooks' => $hooks,
            'name' => $cfg['name'],
            'fifo' => $this->fifoPath(),
            'fifo_ready' => file_exists($this->fifoPath()),
            'message' => $message,
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

        return $this->runScript($script) + ['config' => $this->config()] + $this->status();
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
        $this->clearBridge();
        $this->markSessionActive(false);
        $out = $this->runScript($script);

        return $out + ['config' => $this->config()] + $this->status();
    }

    private function binaryInstalled(string $name): bool
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/'] as $dir) {
            $path = $dir . $name;
            if (is_executable($path)) {
                return true;
            }
        }
        $out = trim((string) shell_exec(
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin command -v '
            . escapeshellarg($name) . ' 2>/dev/null'
        ));

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
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($path, $json . "\n", LOCK_EX);
        }
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
