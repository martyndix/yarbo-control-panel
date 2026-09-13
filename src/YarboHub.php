<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboHub
{
    public const MODULE_YARBO = 'yarbo';
    public const MODULE_POWERWALL = 'powerwall';
    public const MODULE_LYMOW = 'lymow';

    /** @var list<string> */
    public const MODULES = [
        self::MODULE_YARBO,
        self::MODULE_POWERWALL,
        self::MODULE_LYMOW,
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/hub-config.json';
    }

    /**
     * @return array{
     *   modules: array<string, bool>,
     *   active_module: string,
     *   vestaboard_live: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'modules' => [
                self::MODULE_YARBO => true,
                self::MODULE_POWERWALL => false,
                self::MODULE_LYMOW => false,
            ],
            'active_module' => self::MODULE_YARBO,
            'vestaboard_live' => self::MODULE_YARBO,
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        $modules = $defaults['modules'];
        if (isset($decoded['modules']) && is_array($decoded['modules'])) {
            foreach (self::MODULES as $id) {
                if (array_key_exists($id, $decoded['modules'])) {
                    $modules[$id] = (bool) $decoded['modules'][$id];
                }
            }
        }
        $modules[self::MODULE_YARBO] = true;
        $active = $this->normalizeModule((string) ($decoded['active_module'] ?? self::MODULE_YARBO), $modules);
        $live = $this->normalizeModule((string) ($decoded['vestaboard_live'] ?? self::MODULE_YARBO), $modules);

        return [
            'modules' => $modules,
            'active_module' => $active,
            'vestaboard_live' => $live,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $current = $this->load();
        $modules = $current['modules'];
        if (isset($input['modules']) && is_array($input['modules'])) {
            foreach (self::MODULES as $id) {
                if (array_key_exists($id, $input['modules'])) {
                    $modules[$id] = (bool) $input['modules'][$id];
                }
            }
        }
        foreach (self::MODULES as $id) {
            $key = 'module_' . $id;
            if (array_key_exists($key, $input)) {
                $modules[$id] = (bool) $input[$key];
            }
        }
        $modules[self::MODULE_YARBO] = true;
        $active = array_key_exists('active_module', $input)
            ? $this->normalizeModule((string) $input['active_module'], $modules)
            : $this->normalizeModule($current['active_module'], $modules);
        $live = array_key_exists('vestaboard_live', $input)
            ? $this->normalizeModule((string) $input['vestaboard_live'], $modules)
            : $this->normalizeModule($current['vestaboard_live'], $modules);
        $json = json_encode([
            'modules' => $modules,
            'active_module' => $active,
            'vestaboard_live' => $live,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->configPath(), $json . "\n", LOCK_EX) !== false;
    }

    public function enabled(string $id): bool
    {
        $modules = $this->load()['modules'];

        return !empty($modules[$id]);
    }

    public function vestaboardLive(): string
    {
        return $this->load()['vestaboard_live'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $config = $this->load();
        $labels = [
            self::MODULE_YARBO => 'Yarbo',
            self::MODULE_POWERWALL => 'Powerwall',
            self::MODULE_LYMOW => 'Lymow',
        ];
        $enabled = [];
        foreach (self::MODULES as $id) {
            if (!empty($config['modules'][$id])) {
                $enabled[] = [
                    'id' => $id,
                    'label' => $labels[$id] ?? $id,
                ];
            }
        }

        return [
            'modules' => $config['modules'],
            'enabled' => $enabled,
            'active_module' => $config['active_module'],
            'vestaboard_live' => $config['vestaboard_live'],
        ];
    }

    /**
     * @param array<string, bool> $modules
     */
    private function normalizeModule(string $id, array $modules): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !in_array($id, self::MODULES, true) || empty($modules[$id])) {
            return self::MODULE_YARBO;
        }

        return $id;
    }
}
