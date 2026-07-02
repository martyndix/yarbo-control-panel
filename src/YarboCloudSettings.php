<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboCloudSettings
{
    public const DATA_SOURCE_LOCAL = 'local';
    public const DATA_SOURCE_CLOUD = 'cloud';
    public const DATA_SOURCE_AUTO = 'auto';

    public function __construct(private readonly string $dataDir)
    {
    }

    public function configPath(): string
    {
        return $this->dataDir . '/cloud-config.json';
    }

    /**
     * @return array{
     *   enabled: bool,
     *   email: string,
     *   password: string,
     *   data_source: string,
     *   python_path: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'enabled' => false,
            'email' => '',
            'password' => '',
            'data_source' => self::DATA_SOURCE_AUTO,
            'python_path' => 'python3',
        ];

        $path = $this->configPath();
        if (!is_file($path)) {
            return $defaults;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $dataSource = (string) ($decoded['data_source'] ?? self::DATA_SOURCE_AUTO);
        if (!in_array($dataSource, [self::DATA_SOURCE_LOCAL, self::DATA_SOURCE_CLOUD, self::DATA_SOURCE_AUTO], true)) {
            $dataSource = self::DATA_SOURCE_AUTO;
        }

        return [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'email' => (string) ($decoded['email'] ?? ''),
            'password' => (string) ($decoded['password'] ?? ''),
            'data_source' => $dataSource,
            'python_path' => (string) ($decoded['python_path'] ?? 'python3'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0755, true) && !is_dir($this->dataDir)) {
            return false;
        }

        $current = $this->load();

        $dataSource = (string) ($input['data_source'] ?? $current['data_source']);
        if (!in_array($dataSource, [self::DATA_SOURCE_LOCAL, self::DATA_SOURCE_CLOUD, self::DATA_SOURCE_AUTO], true)) {
            $dataSource = self::DATA_SOURCE_AUTO;
        }

        $next = [
            'enabled' => array_key_exists('cloud_enabled', $input)
                ? (bool) $input['cloud_enabled']
                : (array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $current['enabled']),
            'email' => trim((string) ($input['cloud_email'] ?? $input['email'] ?? $current['email'])),
            'password' => array_key_exists('cloud_password', $input) || array_key_exists('password', $input)
                ? (string) ($input['cloud_password'] ?? $input['password'] ?? '')
                : $current['password'],
            'data_source' => $dataSource,
            'python_path' => trim((string) ($input['python_path'] ?? $current['python_path'] ?: 'python3')),
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
            'cloud_enabled' => $config['enabled'],
            'cloud_email' => $config['email'],
            'cloud_password_set' => $config['password'] !== '',
            'data_source' => $config['data_source'],
            'python_path' => $config['python_path'],
            'writable' => is_dir($this->dataDir) ? is_writable($this->dataDir) : is_writable(dirname($this->dataDir)),
        ];
    }
}
