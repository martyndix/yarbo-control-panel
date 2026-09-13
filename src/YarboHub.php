<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboHub
{
    public const MODULE_YARBO = 'yarbo';
    public const MODULE_POWERWALL = 'powerwall';
    public const MODULE_LYMOW = 'lymow';
    public const LIVE_BATTERIES = 'batteries';

    public const VESTABOARD_LIVE_CHOICES = [
        self::MODULE_YARBO,
        self::MODULE_POWERWALL,
        self::MODULE_LYMOW,
        self::LIVE_BATTERIES,
    ];

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
     *   vestaboard_live: string,
     *   house_name: string
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
            'house_name' => '',
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
        $live = $this->normalizeVestaboardLive((string) ($decoded['vestaboard_live'] ?? self::MODULE_YARBO), $modules);
        $house = self::normalizeDisplayName((string) ($decoded['house_name'] ?? ''));

        return [
            'modules' => $modules,
            'active_module' => $active,
            'vestaboard_live' => $live,
            'house_name' => $house,
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
            ? $this->normalizeVestaboardLive((string) $input['vestaboard_live'], $modules)
            : $this->normalizeVestaboardLive($current['vestaboard_live'], $modules);
        $house = array_key_exists('house_name', $input)
            ? self::normalizeDisplayName((string) $input['house_name'])
            : $current['house_name'];
        $json = json_encode([
            'modules' => $modules,
            'active_module' => $active,
            'vestaboard_live' => $live,
            'house_name' => $house,
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
     * @return list<array{id: string, label: string}>
     */
    public static function vestaboardLiveChoices(): array
    {
        return [
            ['id' => self::MODULE_YARBO, 'label' => 'Yarbo'],
            ['id' => self::MODULE_POWERWALL, 'label' => 'Powerwall'],
            ['id' => self::MODULE_LYMOW, 'label' => 'Lymow'],
            ['id' => self::LIVE_BATTERIES, 'label' => 'ALL'],
        ];
    }

    public function houseName(): string
    {
        return $this->load()['house_name'];
    }

    public static function panelTitle(string $houseName): string
    {
        $house = self::normalizeDisplayName($houseName);

        return $house === '' ? 'Control Panel' : $house . ' Control Panel';
    }

    public static function normalizeDisplayName(string $raw, int $max = 48): string
    {
        $name = trim(preg_replace('/[\r\n\t]+/', ' ', $raw) ?? '');
        $name = trim(preg_replace('/ {2,}/', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }
        if (strlen($name) > $max) {
            $name = rtrim(substr($name, 0, $max));
        }

        return $name;
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
            'house_name' => $config['house_name'],
            'panel_title' => self::panelTitle($config['house_name']),
            'vestaboard_live_choices' => self::vestaboardLiveChoices(),
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

    /**
     * Combined batteries is a Vestaboard page, not a dashboard module.
     *
     * @param array<string, bool> $modules
     */
    private function normalizeVestaboardLive(string $id, array $modules): string
    {
        $id = strtolower(trim($id));
        if ($id === 'all') {
            $id = self::LIVE_BATTERIES;
        }
        if ($id === self::LIVE_BATTERIES) {
            if (!empty($modules[self::MODULE_POWERWALL]) || !empty($modules[self::MODULE_LYMOW])) {
                return self::LIVE_BATTERIES;
            }

            return self::MODULE_YARBO;
        }
        if ($id === self::MODULE_POWERWALL && !empty($modules[self::MODULE_POWERWALL])) {
            return $id;
        }
        if ($id === self::MODULE_LYMOW && !empty($modules[self::MODULE_LYMOW])) {
            return $id;
        }

        return self::MODULE_YARBO;
    }
}
