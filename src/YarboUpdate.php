<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboUpdate
{
    private const LOCK_STALE_SECONDS = 900;

    /** @var list<string> */
    private const ACTIVE_PROGRESS_STATES = ['running', 'pulling', 'composer', 'restarting'];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(bool $fetchRemote = true): array
    {
        if (!$this->isGitInstall()) {
            return [
                'ok' => true,
                'git_install' => false,
                'can_update' => false,
                'message' => 'Not a git clone. Use git clone to install, then update via git pull.',
            ];
        }

        $result = $this->runScript($fetchRemote);
        if (!($result['ok'] ?? false)) {
            return array_merge([
                'git_install' => true,
                'can_update' => false,
            ], $result);
        }

        return array_merge([
            'ok' => true,
            'git_install' => true,
            'can_update' => true,
        ], $result);
    }

    /**
     * @return array<string, mixed>
     */
    public function runUpdateAsync(): array
    {
        if (!$this->isGitInstall()) {
            return [
                'ok' => false,
                'error' => 'Not a git clone — cannot update automatically',
            ];
        }

        if ($this->isUpdateRunning()) {
            return [
                'ok' => false,
                'error' => 'An update is already running',
                'progress' => $this->readProgress(),
            ];
        }

        $check = $this->runScript(true);
        if (!($check['ok'] ?? false)) {
            return $check;
        }

        if (!($check['update_available'] ?? false)) {
            return array_merge($check, [
                'started' => false,
                'updated' => false,
                'message' => (string) ($check['message'] ?? 'Already on latest commit'),
            ]);
        }

        if (!$this->ensureDataDir()) {
            return ['ok' => false, 'error' => 'Could not create data directory'];
        }

        $this->writeProgress([
            'state' => 'running',
            'message' => 'Update started',
            'started_at' => gmdate('c'),
        ]);

        $script = $this->projectRoot . '/scripts/update.sh';
        $logFile = $this->projectRoot . '/data/update.log';
        $cmd = sprintf(
            'nohup bash %s >> %s 2>&1 &',
            escapeshellarg($script),
            escapeshellarg($logFile)
        );

        if (!$this->spawnBackgroundCommand($cmd)) {
            $this->writeProgress([
                'state' => 'failed',
                'message' => 'Could not start background update',
                'error' => 'Could not start background update',
                'updated_at' => gmdate('c'),
            ]);

            return ['ok' => false, 'error' => 'Could not start background update'];
        }

        return [
            'ok' => true,
            'started' => true,
            'message' => 'Update started. The panel will restart when finished.',
            'update_available' => true,
            'current_commit_short' => $check['current_commit_short'] ?? null,
            'remote_commit_short' => $check['remote_commit_short'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readProgress(): array
    {
        $path = $this->progressPath();
        if (!is_file($path)) {
            return ['ok' => true, 'state' => 'idle'];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['ok' => true, 'state' => 'unknown'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => true, 'state' => 'unknown'];
        }

        return array_merge(['ok' => true], $decoded);
    }

    public function isGitInstall(): bool
    {
        return is_dir($this->projectRoot . '/.git') && is_file($this->projectRoot . '/scripts/update.sh');
    }

    public function isUpdateRunning(): bool
    {
        $this->clearStaleUpdateLock();

        if (is_file($this->lockPath())) {
            return true;
        }

        $progress = $this->readProgress();
        $state = (string) ($progress['state'] ?? '');
        if (!in_array($state, self::ACTIVE_PROGRESS_STATES, true)) {
            return false;
        }

        $startedAt = strtotime((string) ($progress['started_at'] ?? $progress['updated_at'] ?? ''));
        if ($startedAt > 0 && (time() - $startedAt) < 120) {
            return true;
        }

        return false;
    }

    private function clearStaleUpdateLock(): void
    {
        $lock = $this->lockPath();
        if (!is_file($lock)) {
            return;
        }

        $progress = $this->readProgress();
        $state = (string) ($progress['state'] ?? '');
        if (!in_array($state, self::ACTIVE_PROGRESS_STATES, true)) {
            @unlink($lock);

            return;
        }

        $age = time() - (int) filemtime($lock);
        if ($age > self::LOCK_STALE_SECONDS) {
            @unlink($lock);
            $this->writeProgress(array_merge($progress, [
                'state' => 'failed',
                'message' => 'Update timed out',
                'error' => 'Update lock expired after ' . self::LOCK_STALE_SECONDS . ' seconds',
                'updated_at' => gmdate('c'),
            ]));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeProgress(array $payload): void
    {
        $this->ensureDataDir();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->progressPath(), $json . "\n", LOCK_EX);
        }
    }

    private function progressPath(): string
    {
        return $this->projectRoot . '/data/update-status.json';
    }

    private function lockPath(): string
    {
        return $this->projectRoot . '/data/.update-running';
    }

    private function ensureDataDir(): bool
    {
        $dataDir = $this->projectRoot . '/data';

        return is_dir($dataDir) || mkdir($dataDir, 0755, true) || is_dir($dataDir);
    }

    private function spawnBackgroundCommand(string $command): bool
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['bash', '-c', $command],
            $descriptorSpec,
            $pipes,
            $this->projectRoot,
            $this->processEnvironment()
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $home = getenv('HOME') ?: '';
        if ($home === '') {
            $home = $this->projectRoot;
        }

        return [
            'HOME' => $home,
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runScript(bool $checkOnly): array
    {
        $script = $this->projectRoot . '/scripts/update.sh';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'update.sh not found'];
        }

        $args = ['bash', $script];
        if ($checkOnly) {
            $args[] = '--check-only';
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($args, $descriptorSpec, $pipes, $this->projectRoot, $this->processEnvironment());
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start update script'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $decoded = $this->decodeScriptOutput($stdout);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => trim($stderr !== '' ? $stderr : 'Invalid JSON from update script'),
                'stdout' => $stdout,
                'exit_code' => $exitCode,
            ];
        }

        if ($exitCode !== 0 && ($decoded['ok'] ?? true)) {
            $decoded['ok'] = false;
            $decoded['error'] = (string) ($decoded['error'] ?? 'Update script failed');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeScriptOutput(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R/', $trimmed) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
