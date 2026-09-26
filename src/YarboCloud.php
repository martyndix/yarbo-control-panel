<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboCloud
{
    public function __construct(
        private readonly YarboCloudSettings $settings,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $config = $this->settings->load();
        $python = $this->resolvePython($config['python_path']);
        $bridge = $this->projectRoot . '/scripts/cloud_bridge.py';

        $available = is_file($bridge);
        $sdkInstalled = false;
        $pythonVersion = null;
        $error = null;

        if ($available) {
            $probe = $this->runBridge(['status'], false);
            $sdkInstalled = (bool) ($probe['sdk_installed'] ?? false);
            $pythonVersion = $probe['python_version'] ?? null;
            if (!($probe['ok'] ?? false) && isset($probe['error'])) {
                $error = (string) $probe['error'];
            }
        } else {
            $error = 'cloud_bridge.py not found';
        }

        return [
            'bridge_available' => $available,
            'sdk_installed' => $sdkInstalled,
            'python' => $python,
            'python_executable' => $python,
            'python_version' => $pythonVersion,
            'venv_python' => $this->venvPythonPath(),
            'sdk_path_hint' => $sdkInstalled
                ? null
                : 'Run ./scripts/install.sh (or sudo ./scripts/install.sh --deps on a fresh Pi)',
            'configured' => $config['enabled'] && $config['email'] !== '' && $config['password'] !== '',
            'data_source' => $config['data_source'],
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testLogin(): array
    {
        $config = $this->settings->load();
        if ($config['email'] === '' || $config['password'] === '') {
            return [
                'ok' => false,
                'error' => 'Cloud email and password are required',
            ];
        }

        return $this->runBridge(['test-login'], true);
    }

    /**
     * @return array{name: ?string, sn: string}|null
     */
    public function fetchRobotName(string $serial): ?array
    {
        $config = $this->settings->load();
        if (!$config['enabled'] || $config['email'] === '' || $config['password'] === '') {
            return null;
        }

        $result = $this->runBridge([
            'device-name',
            '--serial',
            $serial,
        ], true);

        if (!($result['ok'] ?? false)) {
            return ['name' => null, 'sn' => $serial];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $name = trim((string) ($data['name'] ?? ''));

        return [
            'name' => $name !== '' ? $name : null,
            'sn' => (string) ($data['sn'] ?? $serial),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $action, string $serial, float $timeout = 30.0): ?array
    {
        $config = $this->settings->load();
        if (!$config['enabled'] || $config['email'] === '' || $config['password'] === '') {
            return null;
        }

        $result = $this->runBridge([
            $action,
            '--serial',
            $serial,
            '--timeout',
            (string) $timeout,
        ], true);

        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Cloud bridge failed'),
                'cloud' => true,
            ];
        }

        return is_array($result['data'] ?? null) ? $result['data'] : $result;
    }

    /**
     * Publish an unpublished MQTT command on Yarbo cloud (backup/restore).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function command(string $cmd, string $serial, array $payload, float $timeout = 30.0): array
    {
        $config = $this->settings->load();
        if (!$config['enabled'] || $config['email'] === '' || $config['password'] === '') {
            return [
                'ok' => false,
                'error' => 'Enable Settings → cloud fallback with your Yarbo account. Map backup/restore uses cloud MQTT, not the LAN broker.',
                'cloud' => true,
            ];
        }

        $file = tempnam(sys_get_temp_dir(), 'yarbo-cmd-');
        if ($file === false) {
            return ['ok' => false, 'error' => 'Could not write command payload', 'cloud' => true];
        }
        $toWrite = $payload === [] ? new \stdClass() : $payload;
        file_put_contents($file, json_encode($toWrite, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $result = $this->runBridge([
                'command',
                '--serial',
                $serial,
                '--timeout',
                (string) $timeout,
                '--cmd',
                $cmd,
                '--payload-file',
                $file,
            ], true);
        } finally {
            @unlink($file);
        }

        if (!($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Cloud command failed'),
                'cloud' => true,
            ];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $data['ok'] = true;
        $data['cloud'] = true;

        return $data;
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function runBridge(array $args, bool $requireCredentials): array
    {
        $config = $this->settings->load();
        $python = $this->resolvePython($config['python_path']);
        $bridge = $this->projectRoot . '/scripts/cloud_bridge.py';

        if (!is_file($bridge)) {
            return ['ok' => false, 'error' => 'Cloud bridge script missing'];
        }

        $cmd = array_merge([$python, $bridge], $args);
        if ($requireCredentials) {
            $cmd[] = '--config';
            $cmd[] = $this->settings->configPath();
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes, $this->projectRoot, $this->processEnvironment());
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start cloud bridge process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => trim($stderr !== '' ? $stderr : 'Invalid JSON from cloud bridge'),
                'stdout' => $stdout,
            ];
        }

        return $decoded;
    }

    private function resolvePython(string $configuredPath): string
    {
        $venv = $this->venvPythonPath();
        if ($venv !== null) {
            return $venv;
        }

        $configured = trim($configuredPath);
        if ($configured !== '' && $configured !== 'python3') {
            return $configured;
        }

        return 'python3';
    }

    private function venvPythonPath(): ?string
    {
        $path = $this->projectRoot . '/.venv/bin/python3';
        if (is_executable($path)) {
            return $path;
        }

        return null;
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

        $path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $venvBin = $this->projectRoot . '/.venv/bin';
        if (is_dir($venvBin) && !str_contains($path, $venvBin)) {
            $path = $venvBin . ':' . $path;
        }

        return [
            'HOME' => $home,
            'PATH' => $path,
        ];
    }
}
