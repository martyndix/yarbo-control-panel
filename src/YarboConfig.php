<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboConfig
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function applySettings(string $configPath, array $settings): bool
    {
        if (!is_file($configPath) || !is_writable($configPath)) {
            return false;
        }

        /** @var array<string, mixed> $current */
        $current = require $configPath;

        if (isset($settings['broker_host']) && $settings['broker_host'] !== '') {
            $current['broker_host'] = (string) $settings['broker_host'];
        }
        if (isset($settings['serial']) && $settings['serial'] !== '') {
            $current['serial'] = (string) $settings['serial'];
        }
        if (isset($settings['broker_port']) && $settings['broker_port'] !== '') {
            $current['broker_port'] = (int) $settings['broker_port'];
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($current, true) . ";\n";

        return file_put_contents($configPath, $content, LOCK_EX) !== false;
    }
}
