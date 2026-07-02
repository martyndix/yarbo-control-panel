<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboUpdate
{
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
    public function runUpdate(): array
    {
        if (!$this->isGitInstall()) {
            return [
                'ok' => false,
                'error' => 'Not a git clone — cannot update automatically',
            ];
        }

        return $this->runScript(false);
    }

    public function isGitInstall(): bool
    {
        return is_dir($this->projectRoot . '/.git') && is_file($this->projectRoot . '/scripts/update.sh');
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

        $process = proc_open($args, $descriptorSpec, $pipes, $this->projectRoot);
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
