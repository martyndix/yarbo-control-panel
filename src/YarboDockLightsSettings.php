<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Opt-in: Yarbo lights on when off the dock between sunset and sunrise.
 */
final class YarboDockLightsSettings
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function configPath(): string
    {
        return rtrim($this->dataDir, '/') . '/yarbo-dock-lights.json';
    }

    /**
     * @return array{enabled: bool, last_applied: ?bool}
     */
    public function load(): array
    {
        $defaults = ['enabled' => false, 'last_applied' => null];
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
        $last = $decoded['last_applied'] ?? null;
        if ($last !== null) {
            $last = (bool) $last;
        }

        return [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'last_applied' => $last,
        ];
    }

    public function enabled(): bool
    {
        return $this->load()['enabled'];
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
        $enabled = $current['enabled'];
        if (array_key_exists('yarbo_dock_lights', $input) || array_key_exists('dock_lights_enabled', $input)) {
            $enabled = (bool) ($input['yarbo_dock_lights'] ?? $input['dock_lights_enabled']);
        } elseif (array_key_exists('enabled', $input)) {
            $enabled = (bool) $input['enabled'];
        }
        $last = $current['last_applied'];
        if (array_key_exists('last_applied', $input)) {
            $raw = $input['last_applied'];
            $last = $raw === null ? null : (bool) $raw;
        }

        return $this->write([
            'enabled' => $enabled,
            'last_applied' => $last,
        ]);
    }

    /**
     * @param array{enabled: bool, last_applied: ?bool} $data
     */
    public function write(array $data): bool
    {
        $json = json_encode([
            'enabled' => (bool) $data['enabled'],
            'last_applied' => $data['last_applied'] === null ? null : (bool) $data['last_applied'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
    }

    /**
     * @return array{enabled: bool}
     */
    public function publicView(): array
    {
        return ['enabled' => $this->enabled()];
    }
}
