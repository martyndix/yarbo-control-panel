<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboChangelog
{
    /**
     * @return list<array{version: string, date: ?string, sections: array<string, list<string>>}>
     */
    public static function parseReleases(string $markdown): array
    {
        $releases = [];
        $current = null;
        $currentSection = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^## \[([^\]]+)\](?: - (.+))?$/', $line, $matches)) {
                if ($current !== null) {
                    $releases[] = $current;
                }

                $current = [
                    'version' => trim($matches[1]),
                    'date' => isset($matches[2]) ? trim($matches[2]) : null,
                    'sections' => [],
                ];
                $currentSection = null;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^### (Added|Changed|Fixed|Deprecated|Removed|Security)$/', $line, $matches)) {
                $currentSection = $matches[1];
                $current['sections'][$currentSection] = [];
                continue;
            }

            if ($currentSection !== null && preg_match('/^- (.+)$/', $line, $matches)) {
                $current['sections'][$currentSection][] = $matches[1];
            }
        }

        if ($current !== null) {
            $releases[] = $current;
        }

        return $releases;
    }

    public static function currentVersion(string $projectRoot): ?string
    {
        $path = $projectRoot . '/CHANGELOG.md';
        if (!is_file($path)) {
            return null;
        }

        $markdown = file_get_contents($path);
        if ($markdown === false) {
            return null;
        }

        foreach (self::parseReleases($markdown) as $release) {
            if (strtolower($release['version']) !== 'unreleased') {
                return $release['version'];
            }
        }

        return null;
    }

    /**
     * @return array{version: ?string, release_notes: list<array{version: string, date: ?string, sections: array<string, list<string>>}>}
     */
    public static function installedRelease(string $projectRoot): array
    {
        $path = $projectRoot . '/CHANGELOG.md';
        if (!is_file($path)) {
            return [
                'version' => null,
                'release_notes' => [],
            ];
        }

        $markdown = file_get_contents($path);
        if ($markdown === false) {
            return [
                'version' => null,
                'release_notes' => [],
            ];
        }

        foreach (self::parseReleases($markdown) as $release) {
            if (strtolower($release['version']) === 'unreleased') {
                continue;
            }

            return [
                'version' => $release['version'],
                'release_notes' => [$release],
            ];
        }

        return [
            'version' => null,
            'release_notes' => [],
        ];
    }

    /**
     * @return array{pending_version: ?string, release_notes: list<array{version: string, date: ?string, sections: array<string, list<string>>}>}
     */
    public static function pendingReleases(string $projectRoot, ?string $remoteRef = null): array
    {
        $localVersion = self::currentVersion($projectRoot);
        $remoteMarkdown = self::readRemoteChangelog($projectRoot, $remoteRef);
        if ($remoteMarkdown === null) {
            return [
                'pending_version' => null,
                'release_notes' => [],
            ];
        }

        $pending = [];
        foreach (self::parseReleases($remoteMarkdown) as $release) {
            if (strtolower($release['version']) === 'unreleased') {
                continue;
            }

            if ($localVersion !== null && version_compare($release['version'], $localVersion, '<=')) {
                break;
            }

            $pending[] = $release;
        }

        return [
            'pending_version' => $pending[0]['version'] ?? null,
            'release_notes' => $pending,
        ];
    }

    /**
     * @return array{pending_version: ?string, release_notes: list<array{version: string, date: ?string, sections: array<string, list<string>>}>}
     */
    public static function latestRemoteRelease(string $projectRoot, ?string $remoteRef = null): array
    {
        $remoteMarkdown = self::readRemoteChangelog($projectRoot, $remoteRef);
        if ($remoteMarkdown === null) {
            return [
                'pending_version' => null,
                'release_notes' => [],
            ];
        }

        foreach (self::parseReleases($remoteMarkdown) as $release) {
            if (strtolower($release['version']) === 'unreleased') {
                continue;
            }

            return [
                'pending_version' => $release['version'],
                'release_notes' => [$release],
            ];
        }

        return [
            'pending_version' => null,
            'release_notes' => [],
        ];
    }

    private static function remoteRef(?string $remoteRef = null): string
    {
        if ($remoteRef !== null && $remoteRef !== '') {
            return str_contains($remoteRef, ':') ? $remoteRef : $remoteRef . ':CHANGELOG.md';
        }

        $branch = getenv('YARBO_PANEL_BRANCH') ?: 'main';

        return 'origin/' . $branch . ':CHANGELOG.md';
    }

    private static function readRemoteChangelog(string $projectRoot, ?string $remoteRef = null): ?string
    {
        $ref = self::remoteRef($remoteRef);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['git', '-C', $projectRoot, 'show', $ref],
            $descriptorSpec,
            $pipes,
            $projectRoot,
            [
                'HOME' => getenv('HOME') ?: $projectRoot,
                'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'safe.directory',
                'GIT_CONFIG_VALUE_0' => $projectRoot,
            ]
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return null;
        }

        return $stdout;
    }
}
