<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Rain Sensitivity from the Yarbo app slider (20–1000).
 * Blank settings uses 20 (the app minimum — mowing can resume below that).
 */
final class YarboRainSettings
{
    public const MIN = 20;
    public const MAX = 1000;
    public const DEFAULT = 20;

    public function __construct(private readonly string $dataDir)
    {
    }

    public function configPath(): string
    {
        return $this->dataDir . '/rain-config.json';
    }

    /**
     * @return array{sensitivity: ?int}
     */
    public function load(): array
    {
        $defaults = ['sensitivity' => null];
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

        return [
            'sensitivity' => self::normalize($decoded['sensitivity'] ?? null),
        ];
    }

    /**
     * User-entered slider, or null to use the default 20.
     */
    public function sensitivity(): ?int
    {
        return $this->load()['sensitivity'];
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
        $raw = $input['rain_sensitivity'] ?? $input['sensitivity'] ?? null;
        if ($raw === '' || $raw === null) {
            $sensitivity = array_key_exists('rain_sensitivity', $input) || array_key_exists('sensitivity', $input)
                ? null
                : $current['sensitivity'];
        } else {
            $sensitivity = self::normalize($raw);
        }

        $json = json_encode(['sensitivity' => $sensitivity], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
    }

    /**
     * @return array{sensitivity: ?int, threshold: int}
     */
    public function publicView(): array
    {
        $sensitivity = $this->sensitivity();

        return [
            'sensitivity' => $sensitivity,
            'threshold' => $sensitivity ?? self::DEFAULT,
        ];
    }

    public static function normalize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (int) round((float) $value);
        if ($n < self::MIN || $n > self::MAX) {
            return null;
        }

        return $n;
    }
}
