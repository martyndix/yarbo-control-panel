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
        $python = $config['python_path'] ?: 'python3';
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
            'python_version' => $pythonVersion,
            'configured' => $config['enabled'] && $config['email'] !== '' && $config['password'] !== '',
            'data_source' => $config['data_source'],
            'error' => $error,
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
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function runBridge(array $args, bool $requireCredentials): array
    {
        $config = $this->settings->load();
        $python = $config['python_path'] ?: 'python3';
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

        $process = proc_open($cmd, $descriptorSpec, $pipes, $this->projectRoot);
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
}
