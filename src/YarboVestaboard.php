<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Vestaboard Note (3×15): layout, encode, optional push via Local or Cloud API.
 * https://docs.vestaboard.com/docs/local-api/introduction
 * https://docs.vestaboard.com/docs/read-write-api/introduction/
 */
final class YarboVestaboard
{
    public const ROWS = 3;
    public const COLS = 15;
    public const MIN_SEND_GAP_SECONDS = 15.0;
    public const MIN_PROGRESS_SEND_GAP_SECONDS = 120.0;
    public const EXTERNAL_HOLD_SECONDS = 3600;
    public const EXTERNAL_HOLD_SETTLE_SECONDS = 30;
    public const RECENT_HASH_LIMIT = 3;
    public const DEFAULT_HOST = 'vestaboard.local';
    public const DEFAULT_PORT = 7000;
    public const TRANSPORT_LOCAL = 'local';
    public const TRANSPORT_CLOUD = 'cloud';
    public const CLOUD_URL = 'https://cloud.vestaboard.com/';
    public const COLOR_RED = 63;
    public const COLOR_ORANGE = 64;
    public const COLOR_YELLOW = 65;
    public const COLOR_GREEN = 66;
    public const COLOR_BLUE = 67;
    public const COLOR_VIOLET = 68;
    public const COLOR_WHITE = 69;
    public const ROTATE_DEFAULT_MINUTES = 5;
    public const ROTATE_MIN_MINUTES = 1;
    public const ROTATE_MAX_MINUTES = 60;
    /** Dual-mowing / dual-error dwell before swapping live focus. */
    public const ACTIVITY_SWAP_SECONDS = 300;
    /** Rotate sessions auto-stop after this and return to Powerwall. */
    public const ROTATE_MAX_SECONDS = 86400;

    private const HEAD_SHORT = [
        'None' => '',
        'Snow Blower' => 'SNOW BLOWER',
        'Leaf Blower' => 'LEAF BLOWER',
        'Lawn Mower' => 'MOWER',
        'Smart Cover' => 'SMART COVER',
        'Lawn Mower Pro' => 'MOWER PRO',
        'Trimmer' => 'TRIMMER',
        'Unknown' => '',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function dataDir(): string
    {
        return $this->projectRoot . '/data';
    }

    public function configPath(): string
    {
        return $this->dataDir() . '/vestaboard-config.json';
    }

    public function heartbeatPath(): string
    {
        return $this->dataDir() . '/vestaboard-watch.heartbeat';
    }

    public function touchHeartbeat(): void
    {
        $dir = $this->dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->heartbeatPath(), gmdate('c') . "\n", LOCK_EX);
    }

    public function watcherStale(int $maxAgeSeconds = 45): bool
    {
        $path = $this->heartbeatPath();
        if (!is_file($path)) {
            return true;
        }

        return (time() - (int) filemtime($path)) > $maxAgeSeconds;
    }

    /**
     * @return array{
     *   enabled: bool,
     *   transport: string,
     *   host: string,
     *   port: int,
     *   api_key: string,
     *   cloud_token: string,
     *   last_hash: string,
     *   last_sent_at: ?string,
     *   last_error: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'enabled' => false,
            'transport' => self::TRANSPORT_LOCAL,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'api_key' => '',
            'cloud_token' => '',
            'last_hash' => '',
            'last_sent_at' => null,
            'last_error' => '',
            'quiet_hours_enabled' => false,
            'quiet_start' => '22:00',
            'quiet_end' => '07:00',
            'quiet_codes' => self::defaultQuietCodesStatic(),
            'quiet_active' => false,
            'night_hours_enabled' => false,
            'night_start' => '22:00',
            'night_end' => '07:00',
            'night_codes' => self::defaultNightCodesStatic(),
            'night_active' => false,
            'night_last_open' => false,
            'night_last_kind' => '',
            'night_last_open_key' => '',
            'last_live_codes' => null,
            'quiet_timezone' => '',
            'last_progress_stable_hash' => '',
            'board_codes' => null,
            'board_hash' => '',
            'board_fetched_at' => null,
            'external_hold' => false,
            'external_hold_until' => null,
            'recent_hashes' => [],
            'rotate_enabled' => false,
            'rotate_views' => [YarboHub::MODULE_YARBO],
            'rotate_minutes' => self::ROTATE_DEFAULT_MINUTES,
            'rotate_at' => null,
            'rotate_started_at' => null,
            'smart_display' => true,
            'live_resume' => null,
            'activity_focus' => null,
            'activity_swap_at' => null,
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        $port = (int) ($decoded['port'] ?? self::DEFAULT_PORT);
        if ($port < 1 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }

        return [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'transport' => $this->normalizeTransport($decoded['transport'] ?? $decoded['vestaboard_transport'] ?? self::TRANSPORT_LOCAL),
            'host' => trim((string) ($decoded['host'] ?? self::DEFAULT_HOST)) ?: self::DEFAULT_HOST,
            'port' => $port,
            'api_key' => (string) ($decoded['api_key'] ?? ''),
            'cloud_token' => (string) ($decoded['cloud_token'] ?? ''),
            'last_hash' => (string) ($decoded['last_hash'] ?? ''),
            'last_sent_at' => isset($decoded['last_sent_at']) && is_string($decoded['last_sent_at'])
                ? $decoded['last_sent_at']
                : null,
            'last_error' => (string) ($decoded['last_error'] ?? ''),
            'quiet_hours_enabled' => (bool) ($decoded['quiet_hours_enabled'] ?? false),
            'quiet_start' => self::normalizeClock((string) ($decoded['quiet_start'] ?? '22:00'), '22:00'),
            'quiet_end' => self::normalizeClock((string) ($decoded['quiet_end'] ?? '07:00'), '07:00'),
            'quiet_codes' => self::normalizeQuietCodes($decoded['quiet_codes'] ?? null),
            'quiet_active' => (bool) ($decoded['quiet_active'] ?? false),
            'night_hours_enabled' => (bool) ($decoded['night_hours_enabled'] ?? false),
            'night_start' => self::normalizeClock((string) ($decoded['night_start'] ?? '22:00'), '22:00'),
            'night_end' => self::normalizeClock((string) ($decoded['night_end'] ?? '07:00'), '07:00'),
            'night_codes' => self::normalizeQuietCodes($decoded['night_codes'] ?? self::defaultNightCodesStatic()),
            'night_active' => (bool) ($decoded['night_active'] ?? false),
            'night_last_open' => (bool) ($decoded['night_last_open'] ?? false),
            'night_last_kind' => (string) ($decoded['night_last_kind'] ?? ''),
            'night_last_open_key' => (string) ($decoded['night_last_open_key'] ?? ''),
            'last_live_codes' => self::normalizeLiveCodes($decoded['last_live_codes'] ?? null),
            'quiet_timezone' => self::normalizeTimezone((string) ($decoded['quiet_timezone'] ?? '')),
            'last_progress_stable_hash' => (string) ($decoded['last_progress_stable_hash'] ?? ''),
            'board_codes' => self::normalizeLiveCodes($decoded['board_codes'] ?? null),
            'board_hash' => (string) ($decoded['board_hash'] ?? ''),
            'board_fetched_at' => isset($decoded['board_fetched_at']) && is_string($decoded['board_fetched_at'])
                ? $decoded['board_fetched_at']
                : null,
            'external_hold' => (bool) ($decoded['external_hold'] ?? false),
            'external_hold_until' => isset($decoded['external_hold_until']) && is_string($decoded['external_hold_until'])
                && $decoded['external_hold_until'] !== ''
                ? $decoded['external_hold_until']
                : null,
            'recent_hashes' => $this->normalizeRecentHashes($decoded['recent_hashes'] ?? null, (string) ($decoded['last_hash'] ?? '')),
            'rotate_enabled' => (bool) ($decoded['rotate_enabled'] ?? false),
            'rotate_views' => $this->normalizeRotateViews($decoded['rotate_views'] ?? [YarboHub::MODULE_YARBO]),
            'rotate_minutes' => $this->normalizeRotateMinutes($decoded['rotate_minutes'] ?? self::ROTATE_DEFAULT_MINUTES),
            'rotate_at' => isset($decoded['rotate_at']) && is_string($decoded['rotate_at']) && $decoded['rotate_at'] !== ''
                ? $decoded['rotate_at']
                : null,
            'rotate_started_at' => isset($decoded['rotate_started_at']) && is_string($decoded['rotate_started_at'])
                && $decoded['rotate_started_at'] !== ''
                ? $decoded['rotate_started_at']
                : null,
            'smart_display' => array_key_exists('smart_display', $decoded)
                ? (bool) $decoded['smart_display']
                : true,
            'live_resume' => $this->normalizeLiveResume($decoded['live_resume'] ?? null),
            'activity_focus' => $this->normalizeActivityFocus($decoded['activity_focus'] ?? null),
            'activity_swap_at' => isset($decoded['activity_swap_at']) && is_string($decoded['activity_swap_at'])
                && $decoded['activity_swap_at'] !== ''
                ? $decoded['activity_swap_at']
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $dir = $this->dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $current = $this->load();
        $host = trim((string) ($input['host'] ?? $input['vestaboard_host'] ?? $current['host']));
        if ($host === '') {
            $host = self::DEFAULT_HOST;
        }
        $port = (int) ($input['port'] ?? $input['vestaboard_port'] ?? $current['port']);
        if ($port < 1 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }
        $keyFromInput = array_key_exists('api_key', $input) || array_key_exists('vestaboard_api_key', $input);
        $apiKey = $keyFromInput
            ? (string) ($input['api_key'] ?? $input['vestaboard_api_key'] ?? '')
            : $current['api_key'];
        if ($keyFromInput && $apiKey === '') {
            $apiKey = $current['api_key'];
        }
        $tokenFromInput = array_key_exists('cloud_token', $input) || array_key_exists('vestaboard_cloud_token', $input);
        $cloudToken = $tokenFromInput
            ? (string) ($input['cloud_token'] ?? $input['vestaboard_cloud_token'] ?? '')
            : $current['cloud_token'];
        if ($tokenFromInput && $cloudToken === '') {
            $cloudToken = $current['cloud_token'];
        }
        $enabled = array_key_exists('enabled', $input) || array_key_exists('vestaboard_enabled', $input)
            ? (bool) ($input['enabled'] ?? $input['vestaboard_enabled'])
            : $current['enabled'];
        $transport = array_key_exists('transport', $input) || array_key_exists('vestaboard_transport', $input)
            ? $this->normalizeTransport($input['transport'] ?? $input['vestaboard_transport'] ?? $current['transport'])
            : $current['transport'];

        $quietInInput = array_key_exists('quiet_hours_enabled', $input) || array_key_exists('vestaboard_quiet_hours', $input);
        $nightInInput = array_key_exists('night_hours_enabled', $input) || array_key_exists('vestaboard_night_hours', $input);
        $quietEnabled = $quietInInput
            ? (bool) ($input['quiet_hours_enabled'] ?? $input['vestaboard_quiet_hours'])
            : $current['quiet_hours_enabled'];
        $quietStart = array_key_exists('quiet_start', $input) || array_key_exists('vestaboard_quiet_start', $input)
            ? self::normalizeClock((string) ($input['quiet_start'] ?? $input['vestaboard_quiet_start'] ?? ''), $current['quiet_start'])
            : $current['quiet_start'];
        $quietEnd = array_key_exists('quiet_end', $input) || array_key_exists('vestaboard_quiet_end', $input)
            ? self::normalizeClock((string) ($input['quiet_end'] ?? $input['vestaboard_quiet_end'] ?? ''), $current['quiet_end'])
            : $current['quiet_end'];
        $quietCodes = array_key_exists('quiet_codes', $input) || array_key_exists('vestaboard_quiet_codes', $input)
            ? self::normalizeQuietCodes($input['quiet_codes'] ?? $input['vestaboard_quiet_codes'] ?? null)
            : $current['quiet_codes'];
        $nightEnabled = $nightInInput
            ? (bool) ($input['night_hours_enabled'] ?? $input['vestaboard_night_hours'])
            : (bool) ($current['night_hours_enabled'] ?? false);
        $nightStart = array_key_exists('night_start', $input) || array_key_exists('vestaboard_night_start', $input)
            ? self::normalizeClock((string) ($input['night_start'] ?? $input['vestaboard_night_start'] ?? ''), (string) ($current['night_start'] ?? '22:00'))
            : self::normalizeClock((string) ($current['night_start'] ?? '22:00'), '22:00');
        $nightEnd = array_key_exists('night_end', $input) || array_key_exists('vestaboard_night_end', $input)
            ? self::normalizeClock((string) ($input['night_end'] ?? $input['vestaboard_night_end'] ?? ''), (string) ($current['night_end'] ?? '07:00'))
            : self::normalizeClock((string) ($current['night_end'] ?? '07:00'), '07:00');
        $nightCodes = array_key_exists('night_codes', $input) || array_key_exists('vestaboard_night_codes', $input)
            ? self::normalizeQuietCodes($input['night_codes'] ?? $input['vestaboard_night_codes'] ?? null)
            : self::normalizeQuietCodes($current['night_codes'] ?? self::defaultNightCodesStatic());
        // Quiet hours and night hours cannot both own the overnight board.
        if ($quietEnabled && $nightEnabled) {
            if ($nightInInput && !$quietInInput) {
                $quietEnabled = false;
            } elseif ($quietInInput && !$nightInInput) {
                $nightEnabled = false;
            } else {
                $quietEnabled = false;
            }
        }
        // UniFi inventory is often empty for a bit after panel update/restart.
        // Keep an already-enabled Night hours preference across those blips;
        // only block newly turning it on when no door/window contacts exist.
        if ($nightEnabled && !$this->nightHoursAvailable() && !($current['night_hours_enabled'] ?? false)) {
            $nightEnabled = false;
        }

        $next = [
            'enabled' => $enabled,
            'transport' => $transport,
            'host' => $host,
            'port' => $port,
            'api_key' => $apiKey,
            'cloud_token' => $cloudToken,
            'last_hash' => (string) ($input['last_hash'] ?? $current['last_hash']),
            'last_sent_at' => $input['last_sent_at'] ?? $current['last_sent_at'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
            'quiet_hours_enabled' => $quietEnabled,
            'quiet_start' => $quietStart,
            'quiet_end' => $quietEnd,
            'quiet_codes' => $quietCodes,
            'quiet_active' => array_key_exists('quiet_active', $input)
                ? (bool) $input['quiet_active']
                : (bool) ($current['quiet_active'] ?? false),
            'night_hours_enabled' => $nightEnabled,
            'night_start' => $nightStart,
            'night_end' => $nightEnd,
            'night_codes' => $nightCodes,
            'night_active' => array_key_exists('night_active', $input)
                ? (bool) $input['night_active']
                : (bool) ($current['night_active'] ?? false),
            'night_last_open' => array_key_exists('night_last_open', $input)
                ? (bool) $input['night_last_open']
                : (bool) ($current['night_last_open'] ?? false),
            'night_last_kind' => array_key_exists('night_last_kind', $input)
                ? (string) $input['night_last_kind']
                : (string) ($current['night_last_kind'] ?? ''),
            'night_last_open_key' => array_key_exists('night_last_open_key', $input)
                ? (string) $input['night_last_open_key']
                : (string) ($current['night_last_open_key'] ?? ''),
            'last_live_codes' => array_key_exists('last_live_codes', $input)
                ? (self::normalizeLiveCodes($input['last_live_codes']) ?? ($current['last_live_codes'] ?? null))
                : ($current['last_live_codes'] ?? null),
            'quiet_timezone' => array_key_exists('quiet_timezone', $input) || array_key_exists('vestaboard_quiet_timezone', $input)
                ? (self::normalizeTimezone((string) ($input['quiet_timezone'] ?? $input['vestaboard_quiet_timezone'] ?? ''))
                    ?: (string) ($current['quiet_timezone'] ?? ''))
                : (string) ($current['quiet_timezone'] ?? ''),
            'last_progress_stable_hash' => (string) ($input['last_progress_stable_hash'] ?? $current['last_progress_stable_hash'] ?? ''),
            'board_codes' => array_key_exists('board_codes', $input)
                ? self::normalizeLiveCodes($input['board_codes'])
                : ($current['board_codes'] ?? null),
            'board_hash' => (string) ($input['board_hash'] ?? $current['board_hash'] ?? ''),
            'board_fetched_at' => array_key_exists('board_fetched_at', $input)
                ? (is_string($input['board_fetched_at']) && $input['board_fetched_at'] !== ''
                    ? $input['board_fetched_at']
                    : null)
                : ($current['board_fetched_at'] ?? null),
            'external_hold' => array_key_exists('external_hold', $input)
                ? (bool) $input['external_hold']
                : (bool) ($current['external_hold'] ?? false),
            'external_hold_until' => array_key_exists('external_hold_until', $input)
                ? (is_string($input['external_hold_until']) && $input['external_hold_until'] !== ''
                    ? $input['external_hold_until']
                    : null)
                : ($current['external_hold_until'] ?? null),
            'recent_hashes' => array_key_exists('recent_hashes', $input)
                ? $this->normalizeRecentHashes($input['recent_hashes'] ?? null, (string) ($input['last_hash'] ?? $current['last_hash']))
                : $this->normalizeRecentHashes($current['recent_hashes'] ?? null, (string) ($current['last_hash'] ?? '')),
            'rotate_enabled' => array_key_exists('rotate_enabled', $input)
                ? (bool) $input['rotate_enabled']
                : (bool) ($current['rotate_enabled'] ?? false),
            'rotate_views' => array_key_exists('rotate_views', $input)
                ? $this->normalizeRotateViews($input['rotate_views'])
                : $this->normalizeRotateViews($current['rotate_views'] ?? [YarboHub::MODULE_YARBO]),
            'rotate_minutes' => array_key_exists('rotate_minutes', $input)
                ? $this->normalizeRotateMinutes($input['rotate_minutes'])
                : $this->normalizeRotateMinutes($current['rotate_minutes'] ?? self::ROTATE_DEFAULT_MINUTES),
            'rotate_at' => array_key_exists('rotate_at', $input)
                ? (is_string($input['rotate_at']) && $input['rotate_at'] !== '' ? $input['rotate_at'] : null)
                : ($current['rotate_at'] ?? null),
            'rotate_started_at' => array_key_exists('rotate_started_at', $input)
                ? (is_string($input['rotate_started_at']) && $input['rotate_started_at'] !== ''
                    ? $input['rotate_started_at']
                    : null)
                : ($current['rotate_started_at'] ?? null),
            'smart_display' => array_key_exists('smart_display', $input) || array_key_exists('vestaboard_smart_display', $input)
                ? (bool) ($input['smart_display'] ?? $input['vestaboard_smart_display'])
                : (bool) ($current['smart_display'] ?? true),
            'live_resume' => array_key_exists('live_resume', $input)
                ? $this->normalizeLiveResume($input['live_resume'])
                : $this->normalizeLiveResume($current['live_resume'] ?? null),
            'activity_focus' => array_key_exists('activity_focus', $input)
                ? $this->normalizeActivityFocus($input['activity_focus'])
                : $this->normalizeActivityFocus($current['activity_focus'] ?? null),
            'activity_swap_at' => array_key_exists('activity_swap_at', $input)
                ? (is_string($input['activity_swap_at']) && $input['activity_swap_at'] !== ''
                    ? $input['activity_swap_at']
                    : null)
                : ($current['activity_swap_at'] ?? null),
        ];
        // Turning Smart Display off clears an in-progress mowing override and
        // puts the Note back on the page that was showing before the override.
        if (array_key_exists('smart_display', $input) || array_key_exists('vestaboard_smart_display', $input)) {
            $wantSmart = (bool) ($input['smart_display'] ?? $input['vestaboard_smart_display']);
            if (!$wantSmart) {
                $resume = $this->normalizeLiveResume($current['live_resume'] ?? null);
                if ($resume !== null) {
                    (new YarboHub($this->projectRoot))->save(['vestaboard_live' => $resume]);
                }
                $next['live_resume'] = null;
                $next['activity_focus'] = null;
                $next['activity_swap_at'] = null;
            }
        }
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
            'enabled' => $config['enabled'],
            'transport' => $config['transport'],
            'host' => $config['host'],
            'port' => $config['port'],
            'api_key_set' => $config['api_key'] !== '',
            'cloud_token_set' => $config['cloud_token'] !== '',
            'last_sent_at' => $config['last_sent_at'],
            'last_error' => $config['last_error'],
            'watcher_ok' => !$this->watcherStale(),
            'quiet_hours_enabled' => $config['quiet_hours_enabled'],
            'quiet_start' => $config['quiet_start'],
            'quiet_end' => $config['quiet_end'],
            'quiet_codes' => $config['quiet_codes'],
            'quiet_hours_active' => $this->isQuietHours(),
            'quiet_timezone' => $this->resolveQuietTimezone($config),
            'quiet_clock_now' => $this->quietNowHm($config),
            'night_hours_enabled' => (bool) ($config['night_hours_enabled'] ?? false),
            'night_start' => (string) ($config['night_start'] ?? '22:00'),
            'night_end' => (string) ($config['night_end'] ?? '07:00'),
            'night_codes' => self::normalizeQuietCodes($config['night_codes'] ?? self::defaultNightCodesStatic()),
            'night_hours_active' => $this->isNightHours(),
            'night_hours_available' => $this->nightHoursAvailable(),
            'night_watch' => $this->nightWatchSummary(),
            'smart_display' => (bool) ($config['smart_display'] ?? true),
            'connection_warning' => $this->connectionWarning(),
        ] + $this->rotatePublicFields($config);
    }

    public function connectionWarning(): ?string
    {
        $config = $this->load();
        if (!$config['enabled']) {
            return null;
        }
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return 'Vestaboard: ' . $missing;
        }
        $error = $this->publicLastError((string) $config['last_error']);
        if ($error !== null && YarboHub::looksLikeCredentialError($error)) {
            return 'Vestaboard: ' . $error;
        }
        $lastSent = is_string($config['last_sent_at'] ?? null) ? strtotime($config['last_sent_at']) : false;
        if ($error !== null && ($lastSent === false || (time() - $lastSent) > 900)) {
            return 'Vestaboard: ' . $error;
        }

        return null;
    }

    /**
     * Compact payload for the dashboard card. Does not call the Note.
     *
     * @param array<string, mixed>|null $parsed
     * @return array<string, mixed>
     */
    public function dashboardPayload(?array $parsed, bool $online): array
    {
        $config = $this->load();
        if (!$config['enabled']) {
            return ['enabled' => false] + $this->rotatePublicFields($config);
        }
        $held = $this->isExternalHoldActive($config);
        $holdMeta = $this->externalHoldPublicView($config);
        if ($held) {
            $cached = self::normalizeLiveCodes($config['board_codes'] ?? null);
            if ($cached !== null) {
                return $holdMeta + [
                    'enabled' => true,
                    'lines' => self::linesFromCodes($cached),
                    'codes' => $cached,
                    'verb' => 'APP',
                    'quiet_hours' => $this->isQuietHours(),
                    'quiet_until' => $config['quiet_end'],
                    'quiet_timezone' => $this->resolveQuietTimezone($config),
                    'quiet_clock_now' => $this->quietNowHm($config),
                    'night_hours' => $this->isNightHours(),
                    'night_until' => $config['night_end'] ?? '07:00',
                    'night_open' => false,
                    'last_sent_at' => $config['last_sent_at'],
                    'pending' => false,
                    'last_error' => $this->publicLastError($config['last_error']),
                    'watcher_ok' => !$this->watcherStale(),
                ] + $this->rotatePublicFields($config);
            }
        }
        if ($this->isNightHours()) {
            $layout = $this->nightLayout();
            $hash = hash('sha256', json_encode($layout['codes']));

            return $holdMeta + [
                'enabled' => true,
                'lines' => $layout['lines'],
                'codes' => $layout['codes'],
                'verb' => $layout['verb'],
                'quiet_hours' => false,
                'night_hours' => true,
                'night_until' => $config['night_end'] ?? '07:00',
                'night_open' => !empty($layout['open']),
                'quiet_timezone' => $this->resolveQuietTimezone($config),
                'quiet_clock_now' => $this->quietNowHm($config),
                'last_sent_at' => $config['last_sent_at'],
                'pending' => !$held && $hash !== (string) $config['last_hash'],
                'last_error' => $this->publicLastError($config['last_error']),
                'watcher_ok' => !$this->watcherStale(),
            ] + $this->rotatePublicFields($config);
        }
        if ($this->isQuietHours()) {
            $layout = $this->quietLayout();
            $hash = hash('sha256', json_encode($layout['codes']));

            return $holdMeta + [
                'enabled' => true,
                'lines' => $layout['lines'],
                'codes' => $layout['codes'],
                'verb' => $layout['verb'],
                'quiet_hours' => true,
                'quiet_until' => $config['quiet_end'],
                'quiet_timezone' => $this->resolveQuietTimezone($config),
                'quiet_clock_now' => $this->quietNowHm($config),
                'night_hours' => false,
                'last_sent_at' => $config['last_sent_at'],
                'pending' => !$held && $hash !== (string) $config['last_hash'],
                'last_error' => $this->publicLastError($config['last_error']),
                'watcher_ok' => !$this->watcherStale(),
            ] + $this->rotatePublicFields($config);
        }
        $usable = $online && is_array($parsed);
        $layout = $this->layoutForLiveModule($usable ? $parsed : null, $usable);
        $hash = hash('sha256', json_encode($layout['codes'] ?? []));

        return $holdMeta + [
            'enabled' => true,
            'lines' => $layout['lines'],
            'codes' => $layout['codes'],
            'verb' => $layout['verb'],
            'last_sent_at' => $config['last_sent_at'],
            'pending' => !$held && $hash !== (string) $config['last_hash'],
            'last_error' => $this->publicLastError($config['last_error']),
            'watcher_ok' => !$this->watcherStale(),
        ] + $this->rotatePublicFields($config);
    }

    /**
     * @return array{ok: bool, lines: list<string>, codes: list<list<int>>, verb: string, online: bool, error?: string}
     */
    public function preview(?string $sample = null): array
    {
        if ($sample !== null && $sample !== '' && $sample !== 'live') {
            if ($sample === 'quiet') {
                $layout = $this->quietLayout();

                return ['ok' => true, 'online' => false] + $layout;
            }
            if ($sample === 'night-secure' || $sample === 'night_secure') {
                $layout = $this->nightLayout(false);

                return ['ok' => true, 'online' => false] + $layout;
            }
            if ($sample === 'night-open' || $sample === 'night_open') {
                $layout = $this->nightLayout(true);

                return ['ok' => true, 'online' => false] + $layout;
            }
            if ($sample === 'night-open-door' || $sample === 'night_open_door') {
                $layout = $this->nightLayout(true, 'door');

                return ['ok' => true, 'online' => false] + $layout;
            }
            if ($sample === 'night-open-window' || $sample === 'night_open_window') {
                $layout = $this->nightLayout(true, 'window');

                return ['ok' => true, 'online' => false] + $layout;
            }
            if ($sample === 'night-open-multi' || $sample === 'night_open_multi') {
                $layout = $this->nightLayout(true, 'both');

                return ['ok' => true, 'online' => false] + $layout;
            }
            $layout = $this->sampleLayout($sample);
            if ($layout === null) {
                return ['ok' => false, 'error' => 'Unknown sample', 'lines' => [], 'codes' => [], 'verb' => '', 'online' => false];
            }

            return ['ok' => true] + $layout;
        }

        return $this->layoutForLiveModule();
    }

    /**
     * @return array{ok: bool, error?: string, skipped?: bool, sent?: bool, lines?: list<string>}
     */
    public function tick(): array
    {
        $this->touchHeartbeat();
        $config = $this->load();
        if (!$config['enabled']) {
            return ['ok' => true, 'skipped' => true];
        }
        if (self::normalizeTimezone((string) ($config['quiet_timezone'] ?? '')) === '') {
            $os = self::osTimezone();
            if ($os !== '') {
                $this->save(['quiet_timezone' => $os]);
                $config = $this->load();
            }
        }
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }

        $this->refreshBoardFromDevice($config);
        $config = $this->load();
        $overnightHold = ($this->isNightHours() || $this->isQuietHours()) && $this->isExternalHoldActive($config);
        if ($overnightHold) {
            $this->extendHoldThroughQuietHours($config);
            $wasOvernight = !empty($config['quiet_active']) || !empty($config['night_active']);
            if (!$wasOvernight || self::normalizeLiveCodes($config['last_live_codes'] ?? null) === null) {
                $this->snapshotLiveLayout();
            }
            $patch = [];
            if ($this->isNightHours()) {
                $patch['night_active'] = true;
                $patch['quiet_active'] = false;
            } else {
                $patch['quiet_active'] = true;
                $patch['night_active'] = false;
            }
            $this->save($patch);

            return ['ok' => true, 'skipped' => true, 'reason' => 'external_hold'];
        }
        if ($this->isExternalHoldActive($config)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'external_hold'];
        }

        $wasQuiet = !empty($config['quiet_active']);
        $wasNight = !empty($config['night_active']);
        $quietHash = $this->quietLayoutHash();
        $nightHash = $this->nightLayoutHash();
        $forceResume = $wasQuiet || $wasNight
            || ((string) $config['last_hash'] === $quietHash)
            || ((string) $config['last_hash'] === $nightHash)
            || $this->isExternalHoldExpired($config);

        if ($this->isNightHours()) {
            // Re-read Access DPS + Protect contacts every tick so open/close updates the Note.
            $this->refreshNightContacts();
            if (!$wasNight || self::normalizeLiveCodes($config['last_live_codes'] ?? null) === null) {
                $this->snapshotLiveLayout();
            }
            $layout = $this->nightLayout();
            $openNow = !empty($layout['open']);
            $kindNow = (string) ($layout['open_kind'] ?? '');
            $openKey = (string) ($layout['open_key'] ?? '');
            $openChanged = (bool) ($config['night_last_open'] ?? false) !== $openNow
                || ((string) ($config['night_last_kind'] ?? '')) !== $kindNow
                || ((string) ($config['night_last_open_key'] ?? '')) !== $openKey;
            // Entering night, or a door/window edge: write immediately (bypass 15s gap).
            // Unchanged status relies on hash skip so flaps stay still.
            $force = !$wasNight || $openChanged;
            $result = $this->sendLayout($layout, $force);
            $this->save([
                'night_active' => true,
                'quiet_active' => false,
                'night_last_open' => $openNow,
                'night_last_kind' => $kindNow,
                'night_last_open_key' => $openKey,
            ]);

            return $result;
        }

        if ($this->isQuietHours()) {
            if (!$wasQuiet || self::normalizeLiveCodes($config['last_live_codes'] ?? null) === null) {
                $this->snapshotLiveLayout();
            }
            $result = $this->sendLayout($this->quietLayout(), $forceResume);
            $this->save(['quiet_active' => true, 'night_active' => false]);

            return $result;
        }

        $this->refreshCompanionModules();
        $activity = $this->applyActivityLivePolicy();
        $rotated = false;
        if (!($activity['active'] ?? false)) {
            $rotated = $this->maybeAdvanceRotation();
        }
        $layout = $this->layoutForLiveModule();
        if (!($layout['ok'] ?? false)) {
            $this->save(['last_error' => (string) ($layout['error'] ?? 'Could not compose layout')]);

            return $layout;
        }
        if (!($layout['online'] ?? false)) {
            if ($forceResume) {
                $cached = $this->lastLiveLayout($this->load());
                if ($cached !== null) {
                    $result = $this->sendLayout($cached, true);
                    if (!empty($result['ok'])) {
                        $this->save(['quiet_active' => false, 'night_active' => false]);
                    }

                    return $result;
                }
            }

            return [
                'ok' => true,
                'skipped' => true,
                'reason' => $forceResume ? 'offline_after_quiet' : 'offline',
            ];
        }

        $result = $this->sendLayout($layout, $forceResume || $rotated || !empty($activity['changed']));
        if ($forceResume && !empty($result['ok'])) {
            $this->save(['quiet_active' => false, 'night_active' => false]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $override host/port/api_key for unsaved Settings form values
     * @return array<string, mixed>
     */
    public function testConnection(array $override = []): array
    {
        $config = $this->mergedConfig($override);
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }
        $result = $this->http('GET', $config, null);
        if (!($result['ok'] ?? false)) {
            return $result;
        }
        $label = $config['transport'] === self::TRANSPORT_CLOUD
            ? 'Reached the Vestaboard Cloud API.'
            : 'Reached the Vestaboard Local API.';

        return ['ok' => true, 'message' => $label];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public function sendNow(array $override = []): array
    {
        if ($this->isNightHours()) {
            return $this->sendLayout($this->nightLayout(), true, $override);
        }
        if ($this->isQuietHours()) {
            return $this->sendLayout($this->quietLayout(), true, $override);
        }
        $layout = $this->layoutForLiveModule();
        if (!($layout['ok'] ?? false)) {
            return $layout;
        }

        return $this->sendLayout($layout, true, $override);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Live view / rotate buttons stay locked while night or quiet hours are active.
     * Vestaboard app messages (external hold) and Resume still work.
     */
    public function liveViewsLockedByOvernight(): bool
    {
        return $this->isNightHours() || $this->isQuietHours();
    }

    public function setLiveModule(string $id): array
    {
        if ($this->liveViewsLockedByOvernight()) {
            return [
                'ok' => false,
                'error' => 'Live views are locked during night or quiet hours. Messages from the Vestaboard app still appear — use Resume when one is showing.',
            ];
        }
        $hub = new YarboHub($this->projectRoot);
        if (!$hub->save(['vestaboard_live' => $id])) {
            return ['ok' => false, 'error' => 'Could not save the Vestaboard live view.'];
        }
        // Manual pick becomes the resume target after a mowing/error override.
        $this->save([
            'rotate_at' => gmdate('c'),
            'live_resume' => null,
            'activity_focus' => null,
            'activity_swap_at' => null,
        ]);
        $saved = [
            'ok' => true,
            'vestaboard_live' => $hub->vestaboardLive(),
            'hub' => $hub->publicView(),
        ];
        if (!$this->load()['enabled']) {
            return $saved;
        }

        return $this->sendNow() + $saved;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveRotate(array $input): array
    {
        if ($this->liveViewsLockedByOvernight()) {
            return [
                'ok' => false,
                'error' => 'Rotation is locked during night or quiet hours. Messages from the Vestaboard app still appear — use Resume when one is showing.',
                'config' => $this->publicView(),
            ] + $this->rotatePublicFields();
        }
        $views = $this->normalizeRotateViews($input['rotate_views'] ?? $input['views'] ?? []);
        $minutes = $this->normalizeRotateMinutes($input['rotate_minutes'] ?? $input['minutes'] ?? self::ROTATE_DEFAULT_MINUTES);
        $enabled = (bool) ($input['rotate_enabled'] ?? $input['enabled'] ?? false);
        $available = $this->availableRotateViews($views);
        if (count($available) < 2) {
            $enabled = false;
        }
        $prev = $this->load();
        $started = $enabled
            ? (empty($prev['rotate_enabled']) || empty($prev['rotate_started_at'])
                ? gmdate('c')
                : (string) $prev['rotate_started_at'])
            : null;
        if (!$this->save([
            'rotate_enabled' => $enabled,
            'rotate_views' => $views,
            'rotate_minutes' => $minutes,
            'rotate_at' => gmdate('c'),
            'rotate_started_at' => $started,
        ])) {
            return ['ok' => false, 'error' => 'Could not save rotation settings.'];
        }
        $hub = new YarboHub($this->projectRoot);
        $current = $hub->vestaboardLive();
        if ($enabled && $available !== [] && !in_array($current, $available, true)) {
            return $this->setLiveModule($available[0]) + $this->rotatePublicFields() + [
                'config' => $this->publicView(),
            ];
        }

        return ['ok' => true, 'config' => $this->publicView()] + $this->rotatePublicFields();
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array{rotate_enabled: bool, rotate_views: list<string>, rotate_minutes: int, rotating: bool}
     */
    private function rotatePublicFields(?array $config = null): array
    {
        $config ??= $this->load();
        $views = $this->normalizeRotateViews($config['rotate_views'] ?? []);
        $available = $this->availableRotateViews($views);
        $enabled = (bool) ($config['rotate_enabled'] ?? false);

        return [
            'rotate_enabled' => $enabled,
            'rotate_views' => $views,
            'rotate_minutes' => $this->normalizeRotateMinutes($config['rotate_minutes'] ?? self::ROTATE_DEFAULT_MINUTES),
            'rotating' => $enabled && count($available) >= 2,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeRotateViews(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [YarboHub::MODULE_YARBO];
        }
        $wanted = [];
        $isList = array_is_list($raw);
        foreach ($raw as $key => $value) {
            if ($isList) {
                if (is_string($value) && $value !== '') {
                    $wanted[] = strtolower(trim($value));
                }
                continue;
            }
            if ($value) {
                $wanted[] = strtolower(trim((string) $key));
            }
        }
        $out = [];
        foreach (YarboHub::VESTABOARD_LIVE_CHOICES as $id) {
            if (in_array($id, $wanted, true) || ($id === YarboHub::LIVE_BATTERIES && in_array('all', $wanted, true))) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function normalizeRotateMinutes(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int) $raw : self::ROTATE_DEFAULT_MINUTES;
        if ($n < self::ROTATE_MIN_MINUTES) {
            return self::ROTATE_MIN_MINUTES;
        }
        if ($n > self::ROTATE_MAX_MINUTES) {
            return self::ROTATE_MAX_MINUTES;
        }

        return $n;
    }

    /**
     * @param list<string> $wanted
     * @return list<string>
     */
    private function availableRotateViews(array $wanted): array
    {
        $out = [];
        foreach ($wanted as $id) {
            if ($this->rotateViewIsAvailable($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function rotateViewIsAvailable(string $id): bool
    {
        $modules = (new YarboHub($this->projectRoot))->load()['modules'];
        if ($id === YarboHub::LIVE_BATTERIES) {
            return YarboHub::allViewAvailable($modules);
        }
        if ($id === YarboHub::MODULE_YARBO
            || $id === YarboHub::MODULE_POWERWALL
            || $id === YarboHub::MODULE_LYMOW) {
            return !empty($modules[$id]);
        }

        return false;
    }

    private function maybeAdvanceRotation(): bool
    {
        $config = $this->load();
        if (empty($config['rotate_enabled'])) {
            return false;
        }
        $sessionStart = isset($config['rotate_started_at']) && is_string($config['rotate_started_at'])
            ? strtotime($config['rotate_started_at'])
            : false;
        if ($sessionStart === false) {
            $this->save(['rotate_started_at' => gmdate('c')]);
            $sessionStart = time();
        }
        if ((time() - $sessionStart) >= self::ROTATE_MAX_SECONDS) {
            return $this->stopRotateToDefault();
        }
        $views = $this->availableRotateViews($this->normalizeRotateViews($config['rotate_views'] ?? []));
        if (count($views) < 2) {
            return false;
        }
        $started = isset($config['rotate_at']) && is_string($config['rotate_at'])
            ? strtotime($config['rotate_at'])
            : false;
        $minutes = $this->normalizeRotateMinutes($config['rotate_minutes'] ?? self::ROTATE_DEFAULT_MINUTES);
        if ($started === false) {
            $this->save(['rotate_at' => gmdate('c')]);

            return false;
        }
        if ((time() - $started) < ($minutes * 60)) {
            return false;
        }
        $hub = new YarboHub($this->projectRoot);
        $current = $hub->vestaboardLive();
        $idx = array_search($current, $views, true);
        $nextIdx = $idx === false ? 0 : ($idx + 1) % count($views);
        $next = $views[$nextIdx];
        $this->save(['rotate_at' => gmdate('c')]);
        if ($next === $current) {
            return false;
        }

        return $hub->save(['vestaboard_live' => $next]);
    }

    /**
     * End Rotate and return the idle default (Powerwall when enabled).
     */
    private function stopRotateToDefault(): bool
    {
        $hub = new YarboHub($this->projectRoot);
        $default = YarboHub::firstEnabledVestaboardLive($hub->load()['modules']);
        $this->save([
            'rotate_enabled' => false,
            'rotate_started_at' => null,
            'rotate_at' => gmdate('c'),
            'live_resume' => null,
            'activity_focus' => null,
            'activity_swap_at' => null,
        ]);

        return $hub->save(['vestaboard_live' => $default]);
    }

    /**
     * While Yarbo/Lymow are out working (mowing, heading out, docking) or in error,
     * force that live page (All/Rotate yield). Both active → swap every 5 minutes;
     * an error sticks until cleared.
     *
     * @param array{mowing?: bool, error?: bool}|null $yarboOverride
     * @param array{mowing?: bool, error?: bool}|null $lymowOverride
     * @return array{active: bool, changed: bool}
     */
    public function applyActivityLivePolicy(?array $yarboOverride = null, ?array $lymowOverride = null): array
    {
        $hub = new YarboHub($this->projectRoot);
        $modules = $hub->load()['modules'];
        $config = $this->load();
        $current = $hub->vestaboardLive();
        $smartOn = (bool) ($config['smart_display'] ?? true);
        if (!$smartOn) {
            if (($config['live_resume'] ?? null) !== null || ($config['activity_focus'] ?? null) !== null) {
                return ['active' => false, 'changed' => $this->restoreLiveAfterActivity($hub, $config, $current)];
            }

            return ['active' => false, 'changed' => false];
        }

        $yarboOn = !empty($modules[YarboHub::MODULE_YARBO]);
        $lymowOn = !empty($modules[YarboHub::MODULE_LYMOW]);
        // No robot modules → nothing for Smart Display to follow.
        if (!$yarboOn && !$lymowOn) {
            if (($config['live_resume'] ?? null) !== null || ($config['activity_focus'] ?? null) !== null) {
                return ['active' => false, 'changed' => $this->restoreLiveAfterActivity($hub, $config, $current)];
            }

            return ['active' => false, 'changed' => false];
        }
        if ($yarboOverride !== null) {
            $yarbo = $yarboOverride;
        } elseif ($yarboOn) {
            $yarbo = $this->yarboActivitySnapshot();
        } else {
            $yarbo = ['mowing' => false, 'error' => false];
        }
        if ($lymowOverride !== null) {
            $lymow = $lymowOverride;
        } elseif ($lymowOn) {
            $lymow = $this->lymowActivitySnapshot();
        } else {
            $lymow = ['mowing' => false, 'error' => false];
        }
        $yarbo = [
            'mowing' => $yarboOn && !empty($yarbo['mowing']),
            'error' => $yarboOn && !empty($yarbo['error']),
        ];
        $lymow = [
            'mowing' => $lymowOn && !empty($lymow['mowing']),
            'error' => $lymowOn && !empty($lymow['error']),
        ];
        $yActive = !empty($yarbo['mowing']) || !empty($yarbo['error']);
        $lActive = !empty($lymow['mowing']) || !empty($lymow['error']);

        if (!$yActive && !$lActive) {
            if (($config['live_resume'] ?? null) === null && ($config['activity_focus'] ?? null) === null) {
                return ['active' => false, 'changed' => false];
            }

            return ['active' => false, 'changed' => $this->restoreLiveAfterActivity($hub, $config, $current)];
        }

        $target = $this->resolveActivityFocus($yarbo, $lymow, $config);
        if ($target === null) {
            return ['active' => false, 'changed' => false];
        }

        $resume = $this->normalizeLiveResume($config['live_resume'] ?? null);
        if ($resume === null) {
            // Remember All / current page so we can restore when the job ends.
            // Rotate stays enabled; dwell resumes after the override.
            $resume = $current === YarboHub::LIVE_BATTERIES || $current === YarboHub::MODULE_POWERWALL
                || $current === YarboHub::MODULE_YARBO || $current === YarboHub::MODULE_LYMOW
                ? $current
                : YarboHub::firstEnabledVestaboardLive($modules);
        }

        $changed = false;
        $save = [
            'live_resume' => $resume,
            'activity_focus' => $target,
        ];
        if (($config['activity_focus'] ?? null) !== $target || ($config['activity_swap_at'] ?? null) === null) {
            $save['activity_swap_at'] = gmdate('c');
        }
        if ($current !== $target) {
            $changed = $hub->save(['vestaboard_live' => $target]);
        }
        $this->save($save);

        return ['active' => true, 'changed' => $changed || $current !== $target];
    }

    /**
     * @param array{mowing: bool, error: bool} $yarbo
     * @param array{mowing: bool, error: bool} $lymow
     * @param array<string, mixed> $config
     */
    private function resolveActivityFocus(array $yarbo, array $lymow, array $config): ?string
    {
        $yErr = !empty($yarbo['error']);
        $lErr = !empty($lymow['error']);
        $yMow = !empty($yarbo['mowing']);
        $lMow = !empty($lymow['mowing']);

        if ($yErr && !$lErr) {
            return YarboHub::MODULE_YARBO;
        }
        if ($lErr && !$yErr) {
            return YarboHub::MODULE_LYMOW;
        }
        if ($yErr && $lErr) {
            return $this->swapActivityFocus(
                YarboHub::MODULE_YARBO,
                YarboHub::MODULE_LYMOW,
                $config
            );
        }
        if ($yMow && !$lMow) {
            return YarboHub::MODULE_YARBO;
        }
        if ($lMow && !$yMow) {
            return YarboHub::MODULE_LYMOW;
        }
        if ($yMow && $lMow) {
            return $this->swapActivityFocus(
                YarboHub::MODULE_YARBO,
                YarboHub::MODULE_LYMOW,
                $config
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function swapActivityFocus(string $a, string $b, array $config): string
    {
        $focus = $this->normalizeActivityFocus($config['activity_focus'] ?? null);
        if ($focus !== $a && $focus !== $b) {
            return $a;
        }
        $swapAt = isset($config['activity_swap_at']) && is_string($config['activity_swap_at'])
            ? strtotime($config['activity_swap_at'])
            : false;
        if ($swapAt === false || (time() - $swapAt) < self::ACTIVITY_SWAP_SECONDS) {
            return $focus ?? $a;
        }

        return $focus === $a ? $b : $a;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function restoreLiveAfterActivity(YarboHub $hub, array $config, string $current): bool
    {
        $resume = $this->normalizeLiveResume($config['live_resume'] ?? null);
        $this->save([
            'live_resume' => null,
            'activity_focus' => null,
            'activity_swap_at' => null,
            'rotate_at' => gmdate('c'),
        ]);
        if ($resume === null) {
            return false;
        }
        if (!empty($config['rotate_enabled'])) {
            // Rotate stays on — put them back on a rotate view if needed.
            $views = $this->availableRotateViews($this->normalizeRotateViews($config['rotate_views'] ?? []));
            if ($views !== [] && !in_array($current, $views, true)) {
                return $hub->save(['vestaboard_live' => $views[0]]);
            }

            return false;
        }
        if ($resume === $current) {
            return false;
        }

        return $hub->save(['vestaboard_live' => $resume]);
    }

    /**
     * Smart Display "mowing" means the robot is out: mowing, heading to the job,
     * or docking home — not idle on the pad / paused.
     *
     * @param array<string, mixed> $parsed
     * @return array{mowing: bool, error: bool}
     */
    public static function yarboActivityFromParsed(array $parsed): array
    {
        $error = (int) ($parsed['error_code'] ?? 0) !== 0;
        $paused = (bool) ($parsed['planning_paused'] ?? false);
        $planRunning = (bool) ($parsed['plan_running'] ?? false);
        $returning = (bool) ($parsed['returning_to_dock'] ?? false);
        $mowing = !$error && ((!$paused && $planRunning) || $returning);

        return ['mowing' => $mowing, 'error' => $error];
    }

    /**
     * Lymow work_status: 2/8/9 mowing, 4 docking, 10 pause-docking.
     *
     * @return array{mowing: bool, error: bool}
     */
    public static function lymowActivityFromData(array $data): array
    {
        $error = YarboLymow::hasError($data);
        $work = isset($data['work_status']) && is_numeric($data['work_status'])
            ? (int) $data['work_status']
            : null;
        $mowing = !$error && in_array($work, [2, 4, 8, 9, 10], true);

        return ['mowing' => $mowing, 'error' => $error];
    }

    /**
     * @return array{mowing: bool, error: bool}
     */
    private function yarboActivitySnapshot(): array
    {
        try {
            $agent = YarboMqttAgentClient::fromEnv();
            $result = $agent->telemetry(2.0, false, true);
        } catch (\Throwable) {
            return ['mowing' => false, 'error' => false];
        }
        $raw = $result['raw'] ?? null;
        if (!($result['ok'] ?? false) || !is_array($raw) || $raw === []) {
            return ['mowing' => false, 'error' => false];
        }
        $cells = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
        $parsed = YarboTelemetry::parseForPanel($raw, $cells, $this->projectRoot);

        return self::yarboActivityFromParsed($parsed);
    }

    /**
     * @return array{mowing: bool, error: bool}
     */
    private function lymowActivitySnapshot(): array
    {
        $data = (new YarboLymow($this->projectRoot))->dashboardPayload();

        return self::lymowActivityFromData($data);
    }

    private function normalizeLiveResume(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $id = strtolower(trim($raw));
        if ($id === 'all') {
            $id = YarboHub::LIVE_BATTERIES;
        }
        if (in_array($id, [
            YarboHub::MODULE_YARBO,
            YarboHub::MODULE_POWERWALL,
            YarboHub::MODULE_LYMOW,
            YarboHub::LIVE_BATTERIES,
        ], true)) {
            return $id;
        }

        return null;
    }

    private function normalizeActivityFocus(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $id = strtolower(trim($raw));
        if ($id === YarboHub::MODULE_YARBO || $id === YarboHub::MODULE_LYMOW) {
            return $id;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $parsed
     * @return array{ok: bool, online: bool, lines: list<string>, codes: list<list<int>>, verb: string, error?: string}
     */
    public function layoutForLiveModule(?array $parsed = null, ?bool $online = null): array
    {
        $hub = new YarboHub($this->projectRoot);
        $live = $hub->vestaboardLive();
        $allowRefresh = $online !== false;
        if ($live === YarboHub::LIVE_BATTERIES) {
            return $this->batteriesLayout($parsed, $online);
        }
        if ($live === YarboHub::MODULE_POWERWALL) {
            return (new YarboPowerwall($this->projectRoot))->vestaboardLayout($allowRefresh);
        }
        if ($live === YarboHub::MODULE_LYMOW) {
            return (new YarboLymow($this->projectRoot))->vestaboardLayout();
        }
        if ($parsed !== null || $online !== null) {
            $usable = (bool) $online && is_array($parsed);

            return ['ok' => true, 'online' => $usable] + $this->compose($usable ? $parsed : null, $usable);
        }

        return $this->layoutFromTelemetry();
    }

    /**
     * Three battery percents with a colour chip on each row.
     *
     * @param array<string, mixed>|null $parsed
     * @return array{ok: bool, online: bool, lines: list<string>, codes: list<list<int>>, verb: string}
     */
    private function batteriesLayout(?array $parsed, ?bool $online): array
    {
        if ($parsed === null && $online === null) {
            try {
                $agent = YarboMqttAgentClient::fromEnv();
                $result = $agent->telemetry(4.0, false);
                $raw = $result['raw'] ?? null;
                if (($result['ok'] ?? false) && is_array($raw) && $raw !== []) {
                    $cells = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
                    $parsed = YarboTelemetry::parseForPanel($raw, $cells, $this->projectRoot);
                    $online = true;
                }
            } catch (\Throwable $e) {
                $parsed = null;
                $online = false;
            }
        }
        $hub = new YarboHub($this->projectRoot);
        $modules = $hub->load()['modules'];
        $yarboOn = !empty($modules[YarboHub::MODULE_YARBO]);
        $pwOn = !empty($modules[YarboHub::MODULE_POWERWALL]);
        $lyOn = !empty($modules[YarboHub::MODULE_LYMOW]);

        $yarboOnline = $yarboOn && (bool) $online && is_array($parsed);
        $yarboPct = $this->batteryPercentValue($yarboOnline ? $parsed : null);

        $pw = $pwOn ? (new YarboPowerwall($this->projectRoot))->dashboardPayload($online !== false) : [];
        $pwPct = YarboPowerwall::normalizeBatteryPercent($pw['battery_percent'] ?? null);
        $pwOnline = $pwOn && ($pwPct !== null || !empty($pw['online']));

        $ly = $lyOn ? (new YarboLymow($this->projectRoot))->dashboardPayload() : [];
        $lyPct = isset($ly['battery']) ? (int) $ly['battery'] : null;
        $lyOnline = $lyOn && ($lyPct !== null || !empty($ly['signed_in']) || !empty($ly['online']));

        $rows = [];
        if ($yarboOn) {
            $rows[] = ['YARBO', $yarboPct, $yarboOnline];
        }
        if ($pwOn) {
            $rows[] = ['POWERWALL', $pwPct, $pwOnline];
        }
        if ($lyOn) {
            $rows[] = ['LYMOW', $lyPct, $lyOnline];
        }
        if ($rows === []) {
            $rows[] = ['BATTERIES', null, false];
        }
        $lines = [];
        $codes = [];
        foreach ($rows as [$label, $pct, $on]) {
            $text = $this->pair($label, ($on && $pct !== null) ? $pct . '%' : '--', self::COLS - 1);
            $lines[] = $text;
            $row = $this->encodeLine($text);
            $row[self::COLS - 1] = self::batteryPercentChip($on ? $pct : null, $on);
            $codes[] = $row;
        }
        while (count($lines) < self::ROWS) {
            $lines[] = str_repeat(' ', self::COLS);
            $codes[] = array_fill(0, self::COLS, 0);
        }
        $anyOnline = $yarboOnline || $pwOnline || $lyOnline;

        return [
            'ok' => true,
            'online' => $anyOnline,
            'lines' => $lines,
            'codes' => $codes,
            'verb' => 'BATTERIES',
        ];
    }

    /**
     * @param array<string, mixed>|null $parsed
     */
    private function batteryPercentValue(?array $parsed): ?int
    {
        if ($parsed === null) {
            return null;
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }

        return max(0, min(100, (int) $raw));
    }

    public function refreshCompanionModules(): void
    {
        (new YarboPowerwall($this->projectRoot))->refreshIfStale();
        (new YarboLymow($this->projectRoot))->refreshIfStale();
    }

    /**
     * End an app-message hold and write the layout the panel owns right now.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public function resumeYarboStatus(array $override = []): array
    {
        return $this->sendNow($override);
    }

    /**
     * @param array{lines: list<string>, codes: list<list<int>>} $layout
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function sendLayout(array $layout, bool $force, array $override = []): array
    {
        $config = $this->mergedConfig($override);
        $missing = $this->missingCredentialError($config);
        if ($missing !== null) {
            return ['ok' => false, 'error' => $missing];
        }
        if ($config['transport'] === self::TRANSPORT_CLOUD && !$this->layoutHasGlyph($layout['codes'])) {
            return ['ok' => false, 'error' => 'Cloud API does not accept a blank board.'];
        }
        $hash = hash('sha256', json_encode($layout['codes']));
        $isQuiet = ($layout['verb'] ?? '') === 'QUIET';
        if (!$force && $hash === $config['last_hash']) {
            if (!$isQuiet) {
                $this->save(['last_live_codes' => $layout['codes']]);
            }

            return ['ok' => true, 'skipped' => true, 'lines' => $layout['lines']];
        }
        if (!$force && $this->isProgressOnlyRateLimited($layout, $hash, $config)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'progress_rate_limit', 'lines' => $layout['lines']];
        }
        if (!$force && $config['last_sent_at'] !== null) {
            $then = strtotime($config['last_sent_at']);
            if ($then !== false && (microtime(true) - $then) < self::MIN_SEND_GAP_SECONDS) {
                if (!$isQuiet) {
                    $this->save(['last_live_codes' => $layout['codes']]);
                }

                return ['ok' => true, 'skipped' => true, 'lines' => $layout['lines']];
            }
        }

        $posted = $this->http('POST', $config, $layout['codes']);
        if (!($posted['ok'] ?? false)) {
            $this->save(['last_error' => (string) ($posted['error'] ?? 'Send failed')]);

            return $posted;
        }
        $saved = [
            'last_hash' => $hash,
            'last_sent_at' => gmdate('c'),
            'last_error' => '',
            'last_progress_stable_hash' => $this->progressStableHash($layout),
            'board_codes' => $layout['codes'],
            'board_hash' => $hash,
            'board_fetched_at' => gmdate('c'),
            'external_hold' => false,
            'external_hold_until' => null,
            'recent_hashes' => $this->pushRecentHash($config['recent_hashes'] ?? [], $hash),
        ];
        if (!$isQuiet) {
            $saved['last_live_codes'] = $layout['codes'];
        }
        $this->save($saved);

        return [
            'ok' => true,
            'sent' => true,
            'lines' => $layout['lines'],
            'verb' => $layout['verb'] ?? '',
        ];
    }

    /**
     * @return array{ok: bool, lines: list<string>, codes: list<list<int>>, verb: string, online: bool, error?: string}
     */
    public function layoutFromTelemetry(): array
    {
        try {
            $agent = YarboMqttAgentClient::fromEnv();
            $result = $agent->telemetry(4.0, false);
        } catch (\Throwable $e) {
            $layout = $this->compose(null, false);

            return ['ok' => true, 'online' => false] + $layout;
        }
        $raw = $result['raw'] ?? null;
        if (!($result['ok'] ?? false) || !is_array($raw) || $raw === []) {
            $layout = $this->compose(null, false);

            return ['ok' => true, 'online' => false] + $layout;
        }
        $cells = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
        $parsed = YarboTelemetry::parseForPanel($raw, $cells, $this->projectRoot);
        $layout = $this->compose($parsed, true);

        return ['ok' => true, 'online' => true] + $layout;
    }

    /**
     * @param array<string, mixed>|null $parsed
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function compose(?array $parsed, bool $online): array
    {
        if (!$online || $parsed === null) {
            return $this->pack('OFFLINE', $this->pair('BATTERY', '--', 14), 'NO TELEMETRY', self::COLOR_RED);
        }

        $errorCode = (int) ($parsed['error_code'] ?? 0);
        $chargingLabel = (string) ($parsed['charging_label'] ?? 'No');
        $charging = (bool) ($parsed['charging'] ?? false);
        $returning = (bool) ($parsed['returning_to_dock'] ?? false);
        $paused = (bool) ($parsed['planning_paused'] ?? false);
        $planRunning = (bool) ($parsed['plan_running'] ?? false);
        $rain = (bool) ($parsed['rain_detected'] ?? false);
        $headName = (string) ($parsed['head_type_name'] ?? '');
        $batteryLine = $this->batteryLine($parsed);
        $batteryColor = $this->batteryColorCode($parsed);

        if ($errorCode !== 0) {
            $code = substr((string) $errorCode, 0, 6);

            return $this->pack(
                'ERROR',
                $this->pair('CODE', $code, 14),
                'STOPPED',
                self::COLOR_RED,
                self::COLOR_RED,
            );
        }
        if ($rain) {
            $head = $this->headLine($headName);
            $maxHead = self::COLS - 6;
            if (strlen($head) > $maxHead) {
                $head = substr($head, 0, $maxHead);
            }

            return $this->pack(
                'IDLE',
                $batteryLine,
                $this->pair($head, 'RAIN', self::COLS - 1),
                $batteryColor,
                0,
                self::COLOR_BLUE,
            );
        }
        if ($returning && $chargingLabel === 'No') {
            return $this->pack('DOCKING', $batteryLine, 'HEADING HOME', $batteryColor);
        }
        if ($paused) {
            return $this->packPaused($batteryLine, $batteryColor);
        }
        // Work plan (incl. heading to the start point) before pad/charge so a
        // leftover charging_status cannot paint IDLE/CHARGING over MOWING.
        if ($planRunning) {
            $verb = $this->workingVerb($headName);
            $accent = $verb === 'MOWING' ? self::COLOR_GREEN : 0;
            $line3 = $this->planCompleteLine($parsed) ?? $this->headLine($headName);

            return $this->pack($verb, $batteryLine, $line3, $batteryColor, $accent);
        }
        if ($charging && $chargingLabel !== 'Full') {
            return $this->pack('CHARGING', $batteryLine, 'ON DOCK', $batteryColor);
        }
        if ($chargingLabel === 'Full') {
            return $this->pack('IDLE', $this->batteryLine($parsed), 'CHARGED', $batteryColor);
        }

        return $this->pack('IDLE', $batteryLine, 'READY', $batteryColor);
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}|null
     */
    public function sampleLayout(string $name): ?array
    {
        return match ($name) {
            'mowing' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'No',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => true,
                'state' => 'active',
                'head_type_name' => 'Lawn Mower',
                'battery' => 85,
                'plan_status' => ['plan_percent' => 58],
            ], true),
            'charging' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Yes',
                'charging' => true,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 55,
            ], true),
            'idle' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Full',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'rain_detected' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 98,
            ], true),
            'paused' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'No',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => true,
                'plan_running' => true,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 81,
            ], true),
            'rain' => $this->compose([
                'error_code' => 0,
                'charging_label' => 'Full',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'rain_detected' => true,
                'state' => 'rain',
                'head_type_name' => 'Lawn Mower Pro',
                'battery' => 100,
            ], true),
            'error' => $this->compose([
                'error_code' => 12,
                'charging_label' => 'No',
                'charging' => false,
                'returning_to_dock' => false,
                'planning_paused' => false,
                'plan_running' => false,
                'state' => 'idle',
                'head_type_name' => 'Lawn Mower',
                'battery' => 40,
            ], true),
            default => null,
        };
    }

    /**
     * Same 3-line pack as Yarbo, with another name on row 1 (for example LYMOW).
     *
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function brandedLayout(
        string $brand,
        string $verb,
        string $line2,
        string $line3,
        int $line2Color = 0,
        int $line1Color = 0,
        int $line3Color = 0,
    ): array {
        return $this->pack($verb, $line2, $line3, $line2Color, $line1Color, $line3Color, $brand);
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function brandedPaused(string $brand, string $batteryLine, int $batteryColor): array
    {
        return $this->packPaused($batteryLine, $batteryColor, $brand);
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    private function pack(
        string $verb,
        string $line2,
        string $line3,
        int $line2Color = 0,
        int $line1Color = 0,
        int $line3Color = 0,
        string $brand = 'YARBO',
    ): array {
        $line1Width = $line1Color !== 0 ? self::COLS - 1 : self::COLS;
        $line2Text = $this->clip($line2);
        if ($line2Color !== 0) {
            $line2Text = substr($line2Text, 0, self::COLS - 1) . ' ';
        }
        $line3Text = $this->clip($line3);
        if ($line3Color !== 0) {
            $line3Text = substr($line3Text, 0, self::COLS - 1) . ' ';
        }
        $lines = [
            $this->pair($brand, $verb, $line1Width),
            $line2Text,
            $line3Text,
        ];
        $codes = array_map(fn (string $line) => $this->encodeLine($line), $lines);
        if ($line1Color !== 0) {
            $codes[0][self::COLS - 1] = $line1Color;
        }
        if ($line2Color !== 0) {
            $codes[1][self::COLS - 1] = $line2Color;
        }
        if ($line3Color !== 0) {
            $codes[2][self::COLS - 1] = $line3Color;
        }

        return [
            'lines' => $lines,
            'codes' => $codes,
            'verb' => $verb,
        ];
    }

    /**
     * Center PLAN HOLD with yellow flaps on both sides.
     *
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    private function packPaused(string $batteryLine, int $batteryColor, string $brand = 'YARBO'): array
    {
        $packed = $this->pack('PAUSED', $batteryLine, str_repeat(' ', self::COLS), $batteryColor, 0, 0, $brand);
        $phrase = 'PLAN HOLD';
        $start = intdiv(self::COLS - strlen($phrase), 2);
        $line3 = str_repeat(' ', $start) . $phrase . str_repeat(' ', self::COLS - $start - strlen($phrase));
        $codes = $this->encodeLine($line3);
        $end = $start + strlen($phrase);
        for ($i = 0; $i < self::COLS; $i++) {
            if ($i < $start || $i >= $end) {
                $codes[$i] = self::COLOR_YELLOW;
            }
        }
        $packed['lines'][2] = $line3;
        $packed['codes'][2] = $codes;

        return $packed;
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function batteryLine(array $parsed): string
    {
        $label = (string) ($parsed['charging_label'] ?? 'No');
        if ($label === 'Full') {
            return $this->pair('BATTERY', 'FULL', 14);
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return $this->pair('BATTERY', '--', 14);
        }
        $percent = max(0, min(100, (int) $raw));
        $text = $percent === 100 ? '100%' : (string) $percent . '%';

        return $this->pair('BATTERY', $text, 14);
    }

    public static function batteryPercentChip(?int $percent, bool $online = true): int
    {
        if (!$online) {
            return self::COLOR_RED;
        }
        if ($percent === null) {
            return self::COLOR_RED;
        }
        if ($percent >= 60) {
            return self::COLOR_GREEN;
        }
        if ($percent >= 40) {
            return self::COLOR_YELLOW;
        }
        if ($percent >= 20) {
            return self::COLOR_ORANGE;
        }

        return self::COLOR_RED;
    }

    /**
     * Vestaboard color chip for battery: green ≥60%, yellow ≥40%, orange ≥20%, else red.
     *
     * @param array<string, mixed>|null $parsed
     */
    public function batteryColorCode(?array $parsed): int
    {
        if ($parsed === null) {
            return self::COLOR_RED;
        }
        if ((string) ($parsed['charging_label'] ?? '') === 'Full') {
            return self::COLOR_GREEN;
        }
        $raw = $parsed['battery'] ?? null;
        if (!is_numeric($raw)) {
            return self::COLOR_RED;
        }

        return self::batteryPercentChip((int) $raw, true);
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function planCompleteLine(array $parsed): ?string
    {
        $status = is_array($parsed['plan_status'] ?? null) ? $parsed['plan_status'] : [];
        $raw = $status['plan_percent'] ?? $parsed['plan_percent'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }
        $n = max(0, min(100, (int) round((float) $raw)));

        return $this->pair('WORK DONE', $n . '%');
    }

    /**
     * Hash of the board ignoring WORK DONE percent digits so progress-only changes can be rate-limited.
     *
     * @param array{lines?: list<string>, codes: list<list<int>>} $layout
     */
    private function progressStableHash(array $layout): string
    {
        $codes = $layout['codes'];
        $line3 = (string) ($layout['lines'][2] ?? '');
        if (str_starts_with($line3, 'WORK DONE')) {
            $codes[2] = array_fill(0, self::COLS, 0);
        }

        return hash('sha256', json_encode($codes));
    }

    /**
     * @param array{lines?: list<string>, codes: list<list<int>>} $layout
     * @param array<string, mixed> $config
     */
    private function isProgressOnlyRateLimited(array $layout, string $hash, array $config): bool
    {
        if ($hash === (string) ($config['last_hash'] ?? '')) {
            return false;
        }
        $stable = $this->progressStableHash($layout);
        $prevStable = (string) ($config['last_progress_stable_hash'] ?? '');
        if ($prevStable === '' || $stable !== $prevStable) {
            return false;
        }
        $then = isset($config['last_sent_at']) && is_string($config['last_sent_at'])
            ? strtotime($config['last_sent_at'])
            : false;
        if ($then === false) {
            return false;
        }

        return (microtime(true) - $then) < self::MIN_PROGRESS_SEND_GAP_SECONDS;
    }

    private function workingVerb(string $headName): string
    {
        if ($headName === 'Snow Blower' || $headName === 'Leaf Blower') {
            return 'BLOWING';
        }

        return 'MOWING';
    }

    private function headLine(string $headName): string
    {
        $short = self::HEAD_SHORT[$headName] ?? strtoupper($this->sanitize($headName));

        return $this->clip($short);
    }

    private function pair(string $left, string $right, int $width = self::COLS): string
    {
        $left = $this->sanitize($left);
        $right = $this->sanitize($right);
        $width = max(1, min(self::COLS, $width));
        $gap = $width - strlen($left) - strlen($right);
        if ($gap < 1) {
            $keep = max(0, $width - strlen($left) - 1);
            $right = substr($right, 0, $keep);
            $gap = $width - strlen($left) - strlen($right);
        }

        return $left . str_repeat(' ', max(0, $gap)) . $right;
    }

    private function clip(string $text): string
    {
        $text = $this->sanitize($text);
        if (strlen($text) >= self::COLS) {
            return substr($text, 0, self::COLS);
        }

        return str_pad($text, self::COLS);
    }

    private function sanitize(string $text): string
    {
        $text = strtoupper($text);
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            $out .= $this->charCode($ch) === 0 && $ch !== ' ' ? ' ' : $ch;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function encodeLine(string $line): array
    {
        $line = $this->clip($line);
        $codes = [];
        for ($i = 0; $i < self::COLS; $i++) {
            $codes[] = $this->charCode($line[$i]);
        }

        return $codes;
    }

    public function charCode(string $ch): int
    {
        if ($ch === ' ' || $ch === '') {
            return 0;
        }
        $ord = ord($ch);
        if ($ord >= 65 && $ord <= 90) {
            return $ord - 64;
        }
        if ($ch === '0') {
            return 36;
        }
        if ($ord >= 49 && $ord <= 57) {
            return $ord - 22;
        }

        return match ($ch) {
            '!' => 37,
            '@' => 38,
            '#' => 39,
            '$' => 40,
            '(' => 41,
            ')' => 42,
            '-' => 44,
            '+' => 46,
            '&' => 47,
            '=' => 48,
            ';' => 49,
            ':' => 50,
            "'" => 52,
            '"' => 53,
            '%' => 54,
            ',' => 55,
            '.' => 56,
            '/' => 59,
            '?' => 60,
            default => 0,
        };
    }

    public function rememberTimezone(string $zone): bool
    {
        $zone = self::normalizeTimezone($zone);
        if ($zone === '') {
            return false;
        }
        $current = (string) ($this->load()['quiet_timezone'] ?? '');
        if ($current === $zone) {
            return true;
        }

        return $this->save(['quiet_timezone' => $zone]);
    }

    public function rememberClientTimezoneFromRequest(): void
    {
        $this->rememberTimezone((string) ($_SERVER['HTTP_X_CLIENT_TIMEZONE'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function resolveQuietTimezone(?array $config = null): string
    {
        $config ??= $this->load();
        $stored = self::normalizeTimezone((string) ($config['quiet_timezone'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $os = self::osTimezone();
        if ($os !== '') {
            return $os;
        }
        $php = self::normalizeTimezone((string) date_default_timezone_get());

        return $php !== '' ? $php : 'UTC';
    }

    public static function osTimezone(): string
    {
        $candidates = [];
        if (is_file('/etc/timezone')) {
            $candidates[] = trim((string) file_get_contents('/etc/timezone'));
        }
        $localtime = @readlink('/etc/localtime');
        if (is_string($localtime)) {
            $needle = '/zoneinfo/';
            $pos = strpos($localtime, $needle);
            if ($pos !== false) {
                $candidates[] = substr($localtime, $pos + strlen($needle));
            }
        }
        foreach ($candidates as $raw) {
            $zone = self::normalizeTimezone($raw);
            if ($zone === '' || strcasecmp($zone, 'UTC') === 0 || strcasecmp($zone, 'Etc/UTC') === 0) {
                continue;
            }

            return $zone;
        }

        return '';
    }

    public static function normalizeTimezone(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64 || preg_match('/^[A-Za-z0-9_+\-\/]+$/', $value) !== 1) {
            return '';
        }
        try {
            new \DateTimeZone($value);
        } catch (\Exception) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function quietNowHm(?array $config = null): string
    {
        try {
            $tz = new \DateTimeZone($this->resolveQuietTimezone($config));

            return (new \DateTimeImmutable('now', $tz))->format('H:i');
        } catch (\Exception) {
            return date('H:i');
        }
    }

    public function isQuietHours(?string $nowHm = null): bool
    {
        $config = $this->load();
        if (!($config['quiet_hours_enabled'] ?? false)) {
            return false;
        }
        $start = (string) ($config['quiet_start'] ?? '');
        $end = (string) ($config['quiet_end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }
        $now = $nowHm ?? $this->quietNowHm($config);
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function quietHoursEndAt(?array $config = null): ?\DateTimeImmutable
    {
        $config ??= $this->load();
        if (!($config['quiet_hours_enabled'] ?? false)) {
            return null;
        }
        $start = (string) ($config['quiet_start'] ?? '');
        $end = (string) ($config['quiet_end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return null;
        }
        try {
            $tz = new \DateTimeZone($this->resolveQuietTimezone($config));
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $now = new \DateTimeImmutable('now', $tz);
        $endParts = array_map('intval', explode(':', $end));
        $endToday = $now->setTime($endParts[0] ?? 0, $endParts[1] ?? 0, 0);
        if ($start < $end) {
            return $now < $endToday ? $endToday : $endToday->modify('+1 day');
        }
        if ($now < $endToday) {
            return $endToday;
        }

        return $endToday->modify('+1 day');
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function nightHoursEndAt(?array $config = null): ?\DateTimeImmutable
    {
        $config ??= $this->load();
        if (!($config['night_hours_enabled'] ?? false) || !$this->nightHoursAvailable()) {
            return null;
        }
        $start = (string) ($config['night_start'] ?? '');
        $end = (string) ($config['night_end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return null;
        }
        try {
            $tz = new \DateTimeZone($this->resolveQuietTimezone($config));
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $now = new \DateTimeImmutable('now', $tz);
        $endParts = array_map('intval', explode(':', $end));
        $endToday = $now->setTime($endParts[0] ?? 0, $endParts[1] ?? 0, 0);
        if ($start < $end) {
            return $now < $endToday ? $endToday : $endToday->modify('+1 day');
        }
        if ($now < $endToday) {
            return $endToday;
        }

        return $endToday->modify('+1 day');
    }

    /**
     * @param mixed $body
     * @return list<list<int>>|null
     */
    public static function parseMessageCodes(mixed $body): ?array
    {
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($body)) {
            return null;
        }
        if (isset($body['currentMessage']) && is_array($body['currentMessage'])) {
            $layout = $body['currentMessage']['layout'] ?? null;
            if (is_string($layout)) {
                $decoded = json_decode($layout, true);
                $body = is_array($decoded) ? $decoded : $body;
            } elseif (is_array($layout)) {
                $body = $layout;
            }
        }
        if (isset($body['characters']) && is_array($body['characters'])) {
            $body = $body['characters'];
        }
        if ($body === [] || !array_is_list($body) || !is_array($body[0] ?? null)) {
            return null;
        }

        return self::normalizeLiveCodes($body);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function isExternalHoldActive(?array $config = null): bool
    {
        $config ??= $this->load();
        if (empty($config['external_hold'])) {
            return false;
        }
        $until = $this->holdUntilTimestamp($config);

        return $until !== null && time() < $until;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function isExternalHoldExpired(?array $config = null): bool
    {
        $config ??= $this->load();
        if (empty($config['external_hold'])) {
            return false;
        }
        $until = $this->holdUntilTimestamp($config);

        return $until === null || time() >= $until;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function externalHoldPublicView(array $config): array
    {
        $active = $this->isExternalHoldActive($config);
        $until = is_string($config['external_hold_until'] ?? null) ? $config['external_hold_until'] : null;
        $untilHm = $until !== null ? $this->formatHoldUntilHm($until, $config) : '';
        $untilQuiet = $active && ($this->isQuietHours() || $this->isNightHours());

        return [
            'external_hold' => $active,
            'external_hold_until' => $active ? $until : null,
            'external_hold_until_hm' => $active ? $untilHm : '',
            'external_hold_quiet' => $untilQuiet,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function formatHoldUntilHm(string $iso, array $config): string
    {
        try {
            $dt = new \DateTimeImmutable($iso);
            $tz = new \DateTimeZone($this->resolveQuietTimezone($config));

            return $dt->setTimezone($tz)->format('H:i');
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function holdUntilTimestamp(array $config): ?int
    {
        $until = $config['external_hold_until'] ?? null;
        if (!is_string($until) || $until === '') {
            return null;
        }
        $ts = strtotime($until);

        return $ts === false ? null : $ts;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function refreshBoardFromDevice(array $config): void
    {
        $fetchedAt = $config['board_fetched_at'] ?? null;
        if (is_string($fetchedAt) && $fetchedAt !== '') {
            $then = strtotime($fetchedAt);
            if ($then !== false && (time() - $then) < (int) self::MIN_SEND_GAP_SECONDS) {
                if ($this->isExternalHoldActive($config) && $this->isQuietHours()) {
                    $this->extendHoldThroughQuietHours($config);
                }

                return;
            }
        }
        $result = $this->http('GET', $config, null);
        if (!($result['ok'] ?? false)) {
            return;
        }
        $codes = self::parseMessageCodes($result['body'] ?? $result['raw'] ?? null);
        if ($codes === null) {
            return;
        }
        $hash = hash('sha256', json_encode($codes));
        $previousHash = (string) ($config['board_hash'] ?? '');
        $lastHash = (string) ($config['last_hash'] ?? '');
        $saved = [
            'board_codes' => $codes,
            'board_hash' => $hash,
            'board_fetched_at' => gmdate('c'),
        ];
        $ours = $hash === $lastHash
            || in_array($hash, $this->normalizeRecentHashes($config['recent_hashes'] ?? null, $lastHash), true)
            || $this->codesLookLikeOwnPowerwall($codes)
            || $this->codesLookLikeOwnLymow($codes)
            || $this->codesLookLikeOwnBatteries($codes);
        if ($ours) {
            if (!empty($config['external_hold'])) {
                $saved['external_hold'] = false;
                $saved['external_hold_until'] = null;
            }
            $this->save($saved);

            return;
        }
        if ($lastHash === '') {
            $this->save($saved);

            return;
        }
        if ($this->isOwnWriteSettling($config) || $this->isExternalHoldExpired($config)) {
            $this->save($saved);

            return;
        }
        $newMessage = $hash !== $previousHash;
        $until = $this->holdUntilForExternalWrite($config, $newMessage || empty($config['external_hold']));
        if ($until !== null) {
            $saved['external_hold'] = true;
            $saved['external_hold_until'] = $until->setTimezone(new \DateTimeZone('UTC'))->format('c');
        }
        $this->save($saved);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeRecentHashes(mixed $raw, string $lastHash = ''): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $hash) {
                if (is_string($hash) && $hash !== '' && !in_array($hash, $out, true)) {
                    $out[] = $hash;
                }
            }
        }
        if ($lastHash !== '' && !in_array($lastHash, $out, true)) {
            array_unshift($out, $lastHash);
        }

        return array_slice($out, 0, self::RECENT_HASH_LIMIT);
    }

    /**
     * @param list<string>|mixed $existing
     * @return list<string>
     */
    private function pushRecentHash(mixed $existing, string $hash): array
    {
        $next = [];
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (is_string($item) && $item !== '' && $item !== $hash) {
                    $next[] = $item;
                }
            }
        }
        array_unshift($next, $hash);

        return array_slice($next, 0, self::RECENT_HASH_LIMIT);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isOwnWriteSettling(array $config): bool
    {
        $then = isset($config['last_sent_at']) && is_string($config['last_sent_at'])
            ? strtotime($config['last_sent_at'])
            : false;

        return $then !== false && (time() - $then) < self::EXTERNAL_HOLD_SETTLE_SECONDS;
    }

    /**
     * HOME/POWERWALL/OFFLINE + SOLAR/GRID + DRAW is this panel's Powerwall page, not an app scribble.
     *
     * @param list<list<int>> $codes
     */
    private function codesLookLikeOwnPowerwall(array $codes): bool
    {
        $lines = self::linesFromCodes($codes);
        $row0 = strtoupper(trim($lines[0] ?? ''));
        $row1 = strtoupper(trim($lines[1] ?? ''));
        $row2 = strtoupper(trim($lines[2] ?? ''));

        return (str_starts_with($row0, 'HOME') || str_starts_with($row0, 'POWERWALL') || str_starts_with($row0, 'OFFLINE'))
            && (str_starts_with($row1, 'SOLAR') || str_starts_with($row1, 'GRID'))
            && str_starts_with($row2, 'DRAW');
    }

    /**
     * LYMOW + BATTERY (or the older CHARGING/CAM row) is this panel's Lymow page, not an app scribble.
     *
     * @param list<list<int>> $codes
     */
    private function codesLookLikeOwnLymow(array $codes): bool
    {
        $lines = self::linesFromCodes($codes);
        $row0 = strtoupper(trim($lines[0] ?? ''));
        $row1 = strtoupper(trim($lines[1] ?? ''));
        $row2 = strtoupper(trim($lines[2] ?? ''));

        if (!str_starts_with($row0, 'LYMOW') && !str_starts_with($row0, 'OFFLINE')) {
            return false;
        }

        return str_starts_with($row1, 'BATTERY')
            || str_starts_with($row2, 'CHARGING')
            || str_starts_with($row2, 'CAM');
    }

    /**
     * YARBO / POWERWALL / LYMOW stacked percents — not a Yarbo-only live page.
     *
     * @param list<list<int>> $codes
     */
    private function codesLookLikeOwnBatteries(array $codes): bool
    {
        $lines = self::linesFromCodes($codes);
        $row0 = strtoupper(trim($lines[0] ?? ''));
        $row1 = strtoupper(trim($lines[1] ?? ''));
        $row2 = strtoupper(trim($lines[2] ?? ''));

        return str_starts_with($row0, 'YARBO')
            && str_starts_with($row1, 'POWERWALL')
            && str_starts_with($row2, 'LYMOW');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function holdUntilForExternalWrite(array $config, bool $resetTimer): ?\DateTimeImmutable
    {
        if ($this->isNightHours()) {
            return $this->nightHoursEndAt($config);
        }
        if ($this->isQuietHours()) {
            return $this->quietHoursEndAt($config);
        }
        if (!$resetTimer) {
            $existing = $config['external_hold_until'] ?? null;
            if (is_string($existing) && $existing !== '') {
                try {
                    return new \DateTimeImmutable($existing);
                } catch (\Exception) {
                }
            }
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::EXTERNAL_HOLD_SECONDS . ' seconds');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function extendHoldThroughQuietHours(array $config): void
    {
        $end = $this->isNightHours()
            ? $this->nightHoursEndAt($config)
            : $this->quietHoursEndAt($config);
        if ($end === null) {
            return;
        }
        $untilTs = $this->holdUntilTimestamp($config);
        $endTs = $end->getTimestamp();
        if ($untilTs !== null && $untilTs >= $endTs) {
            return;
        }
        $this->save([
            'external_hold' => true,
            'external_hold_until' => $end->setTimezone(new \DateTimeZone('UTC'))->format('c'),
        ]);
    }

    private function quietLayoutHash(): string
    {
        return hash('sha256', json_encode($this->quietLayout()['codes']));
    }

    private function snapshotLiveLayout(): void
    {
        $layout = $this->layoutForLiveModule();
        if (!($layout['ok'] ?? false) || !($layout['online'] ?? false)) {
            return;
        }
        $this->save(['last_live_codes' => $layout['codes']]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}|null
     */
    private function lastLiveLayout(array $config): ?array
    {
        $codes = self::normalizeLiveCodes($config['last_live_codes'] ?? null);
        if ($codes === null) {
            return null;
        }

        return [
            'lines' => self::linesFromCodes($codes),
            'codes' => $codes,
            'verb' => 'LIVE',
        ];
    }

    /**
     * @param mixed $raw
     * @return list<list<int>>|null
     */
    public static function normalizeLiveCodes(mixed $raw): ?array
    {
        if (!is_array($raw) || count($raw) < self::ROWS) {
            return null;
        }
        $out = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $row = $raw[$r] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $codes = [];
            for ($c = 0; $c < self::COLS; $c++) {
                $n = isset($row[$c]) && is_numeric($row[$c]) ? (int) $row[$c] : 0;
                if ($n < 0 || $n > 69) {
                    $n = 0;
                }
                $codes[] = $n;
            }
            $out[] = $codes;
        }

        return $out;
    }

    /**
     * @return array{lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function quietLayout(): array
    {
        $codes = self::normalizeQuietCodes($this->load()['quiet_codes'] ?? null);

        return [
            'lines' => self::linesFromCodes($codes),
            'codes' => $codes,
            'verb' => 'QUIET',
        ];
    }

    /**
     * @return list<list<int>>
     */
    public static function defaultQuietCodesStatic(): array
    {
        return [
            [7, 15, 15, 4, 0, 14, 9, 7, 8, 20, 0, 0, 0, 0, 0],
            array_fill(0, self::COLS, 0),
            array_fill(0, self::COLS, 0),
        ];
    }

    /**
     * Top two custom lines (same default as quiet); bottom row is filled at runtime.
     *
     * @return list<list<int>>
     */
    public static function defaultNightCodesStatic(): array
    {
        return self::defaultQuietCodesStatic();
    }

    private function nightLayoutHash(): string
    {
        return hash('sha256', json_encode($this->nightLayout()['codes']));
    }

    public function isNightHours(?string $nowHm = null): bool
    {
        $config = $this->load();
        if (!($config['night_hours_enabled'] ?? false)) {
            return false;
        }
        if (!$this->nightHoursAvailable()) {
            return false;
        }
        $start = (string) ($config['night_start'] ?? '');
        $end = (string) ($config['night_end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }
        $now = $nowHm ?? $this->quietNowHm($config);
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    public function nightHoursAvailable(): bool
    {
        return !empty($this->nightWatchSummary()['available']);
    }

    /**
     * UniFi door/window contacts night hours can watch.
     *
     * @return array{
     *   available: bool,
     *   unifi: bool,
     *   count: int,
     *   open: int,
     *   doors_open: int,
     *   windows_open: int,
     *   open_kind: string,
     *   open_names: list<string>
     * }
     */
    public function nightWatchSummary(): array
    {
        $empty = [
            'available' => false,
            'unifi' => false,
            'count' => 0,
            'open' => 0,
            'doors_open' => 0,
            'windows_open' => 0,
            'open_kind' => '',
            'open_names' => [],
        ];
        try {
            $hub = new YarboHub($this->projectRoot);
            if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
                return $empty;
            }
        } catch (\Throwable) {
            return $empty;
        }
        $rows = $this->nightWatchRows();
        if ($rows === []) {
            return $empty + ['unifi' => true];
        }
        $opens = $this->nightOpenContacts();
        $doorsOpen = 0;
        $windowsOpen = 0;
        $names = [];
        foreach ($opens as $open) {
            $names[] = (string) ($open['label'] ?? '');
            if (($open['role'] ?? '') === YarboHome::CONTACT_WINDOW) {
                $windowsOpen++;
            } else {
                $doorsOpen++;
            }
        }
        $openKind = '';
        if ($doorsOpen > 0 && $windowsOpen > 0) {
            $openKind = 'both';
        } elseif ($windowsOpen > 0) {
            $openKind = 'window';
        } elseif ($doorsOpen > 0) {
            $openKind = 'door';
        }

        return [
            'available' => true,
            'unifi' => true,
            'count' => count($rows),
            'open' => $doorsOpen + $windowsOpen,
            'doors_open' => $doorsOpen,
            'windows_open' => $windowsOpen,
            'open_kind' => $openKind,
            'open_names' => $names,
        ];
    }

    /**
     * Currently open night-hours contacts with Vestaboard-safe labels.
     *
     * @return list<array{id: string, label: string, role: string}>
     */
    public function nightOpenContacts(): array
    {
        $out = [];
        try {
            $home = new YarboHome($this->projectRoot);
            $store = $home->load();
        } catch (\Throwable) {
            return [];
        }
        foreach ($this->nightWatchRows() as $row) {
            if (!is_array($row) || !YarboHome::contactIsOpen($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $role = $home->contactRoleFor($row, $store);
            $out[] = [
                'id' => $id,
                'label' => $this->nightContactLabel($row, $role),
                'role' => $role === YarboHome::CONTACT_WINDOW
                    ? YarboHome::CONTACT_WINDOW
                    : YarboHome::CONTACT_DOOR,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nightWatchRows(): array
    {
        try {
            $hub = new YarboHub($this->projectRoot);
            if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
                return [];
            }
            $unifi = new YarboUnifi($this->projectRoot);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $covered = [];
        foreach ($unifi->lockableDoors() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            // Only contacts with a known open/closed reading.
            if (($row['open'] ?? null) !== true && ($row['open'] ?? null) !== false
                && trim((string) ($row['dps'] ?? '')) === ''
                && trim((string) ($row['dps_label'] ?? '')) === '') {
                continue;
            }
            $out[] = $row;
            $native = trim((string) ($row['native_id'] ?? ''));
            if ($native !== '') {
                $covered[$native] = true;
            }
            $doorId = trim((string) ($row['door_id'] ?? ''));
            if ($doorId !== '') {
                $covered[$doorId] = true;
            }
        }
        foreach ($unifi->doorSensorRows() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $doorId = trim((string) ($row['door_id'] ?? ''));
            $native = (string) ($row['native_id'] ?? '');
            if ($doorId !== '' && isset($covered[$doorId])) {
                continue;
            }
            if (str_starts_with($native, 'dps-') && isset($covered[substr($native, 4)])) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Secure: custom top two lines + HOUSE SECURE.
     * Open: full 3×15 alert with red pads and truncated contact names.
     *
     * @return array{
     *   lines: list<string>,
     *   codes: list<list<int>>,
     *   verb: string,
     *   open: bool,
     *   open_kind: string,
     *   open_key: string,
     *   open_names: list<string>
     * }
     */
    public function nightLayout(?bool $forceOpen = null, ?string $forceKind = null): array
    {
        $opens = $this->nightOpenContacts();
        $open = $forceOpen ?? ($opens !== []);
        if ($open) {
            if ($opens === []) {
                $opens = $this->nightSimulateOpenContacts($forceKind);
            } elseif ($forceKind !== null && $forceKind !== '') {
                // Preview samples can force a kind even when contacts are already open.
                $filtered = array_values(array_filter(
                    $opens,
                    static function (array $row) use ($forceKind): bool {
                        $role = (string) ($row['role'] ?? '');
                        if ($forceKind === 'window') {
                            return $role === YarboHome::CONTACT_WINDOW;
                        }
                        if ($forceKind === 'door') {
                            return $role !== YarboHome::CONTACT_WINDOW;
                        }

                        return true;
                    }
                ));
                if ($filtered !== []) {
                    $opens = $filtered;
                }
                if ($forceKind === 'both' && count($opens) < 2) {
                    $opens = $this->nightSimulateOpenContacts('both');
                }
            }

            return $this->nightOpenAlertLayout($opens);
        }

        $config = $this->load();
        $top = self::normalizeQuietCodes($config['night_codes'] ?? self::defaultNightCodesStatic());
        [$line3, $codes3] = $this->packCenteredStatus('HOUSE SECURE', self::COLOR_GREEN);

        return [
            'lines' => [
                self::linesFromCodes([$top[0]])[0],
                self::linesFromCodes([$top[1]])[0],
                $line3,
            ],
            'codes' => [$top[0], $top[1], $codes3],
            'verb' => 'NIGHT',
            'open' => false,
            'open_kind' => '',
            'open_key' => '',
            'open_names' => [],
        ];
    }

    /**
     * @param list<array{id?: string, label: string, role: string}> $opens
     * @return array{
     *   lines: list<string>,
     *   codes: list<list<int>>,
     *   verb: string,
     *   open: bool,
     *   open_kind: string,
     *   open_key: string,
     *   open_names: list<string>
     * }
     */
    public function nightOpenAlertLayout(array $opens): array
    {
        $clean = [];
        foreach ($opens as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = $this->fitNightName((string) ($row['label'] ?? ''), self::COLS);
            if ($label === '') {
                continue;
            }
            $role = (string) ($row['role'] ?? YarboHome::CONTACT_DOOR);
            $clean[] = [
                'id' => trim((string) ($row['id'] ?? $label)),
                'label' => $label,
                'role' => $role === YarboHome::CONTACT_WINDOW
                    ? YarboHome::CONTACT_WINDOW
                    : YarboHome::CONTACT_DOOR,
            ];
        }
        if ($clean === []) {
            $clean = [[
                'id' => 'sample:door',
                'label' => 'DOOR',
                'role' => YarboHome::CONTACT_DOOR,
            ]];
        }

        $doors = 0;
        $windows = 0;
        foreach ($clean as $row) {
            if ($row['role'] === YarboHome::CONTACT_WINDOW) {
                $windows++;
            } else {
                $doors++;
            }
        }
        $n = count($clean);
        $kind = '';
        if ($doors > 0 && $windows > 0) {
            $kind = 'both';
        } elseif ($windows > 0) {
            $kind = 'window';
        } else {
            $kind = 'door';
        }

        if ($n >= 3) {
            $header = $n > 9 ? '9+ OPEN' : ($n . ' OPEN');
        } elseif ($kind === 'both') {
            $header = 'OPEN';
        } elseif ($kind === 'window') {
            $header = $n === 1 ? 'WIN OPEN' : 'WINS OPEN';
        } else {
            $header = $n === 1 ? 'DOOR OPEN' : 'DOORS OPEN';
        }

        [$line1, $codes1] = $this->packCenteredStatus($header, self::COLOR_RED);
        if ($n === 1) {
            [$line2, $codes2] = $this->packCenteredStatus($clean[0]['label'], self::COLOR_RED);
            [$line3, $codes3] = $this->packCenteredStatus('', self::COLOR_RED);
        } elseif ($n === 2) {
            [$line2, $codes2] = $this->packCenteredStatus($clean[0]['label'], self::COLOR_RED);
            [$line3, $codes3] = $this->packCenteredStatus($clean[1]['label'], self::COLOR_RED);
        } else {
            [$line2, $codes2] = $this->packCenteredStatus($clean[0]['label'], self::COLOR_RED);
            $more = '+' . min(9, $n - 1) . ' MORE';
            [$line3, $codes3] = $this->packCenteredStatus($more, self::COLOR_RED);
        }

        $ids = array_map(static fn(array $row): string => (string) $row['id'], $clean);
        sort($ids);

        return [
            'lines' => [$line1, $line2, $line3],
            'codes' => [$codes1, $codes2, $codes3],
            'verb' => 'NIGHT_OPEN',
            'open' => true,
            'open_kind' => $kind,
            'open_key' => implode('|', $ids),
            'open_names' => array_map(static fn(array $row): string => (string) $row['label'], $clean),
        ];
    }

    /**
     * @return list<array{id: string, label: string, role: string}>
     */
    private function nightSimulateOpenContacts(?string $forceKind = null): array
    {
        $kind = $forceKind ?: 'both';
        $picked = [];
        try {
            $home = new YarboHome($this->projectRoot);
            $store = $home->load();
        } catch (\Throwable) {
            $home = null;
            $store = [];
        }
        foreach ($this->nightWatchRows() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $role = $home !== null
                ? $home->contactRoleFor($row, $store)
                : YarboHome::CONTACT_DOOR;
            $isWindow = $role === YarboHome::CONTACT_WINDOW;
            if ($kind === 'window' && !$isWindow) {
                continue;
            }
            if ($kind === 'door' && $isWindow) {
                continue;
            }
            $picked[] = [
                'id' => $id,
                'label' => $this->nightContactLabel($row, $role),
                'role' => $isWindow ? YarboHome::CONTACT_WINDOW : YarboHome::CONTACT_DOOR,
            ];
            if ($kind !== 'both' && count($picked) >= 1) {
                break;
            }
            if ($kind === 'both' && count($picked) >= 2) {
                break;
            }
        }
        if ($picked === []) {
            if ($kind === 'window') {
                return [[
                    'id' => 'sample:window',
                    'label' => 'KITCHEN WIN',
                    'role' => YarboHome::CONTACT_WINDOW,
                ]];
            }
            if ($kind === 'both') {
                return [
                    [
                        'id' => 'sample:door',
                        'label' => 'FRONT DOOR',
                        'role' => YarboHome::CONTACT_DOOR,
                    ],
                    [
                        'id' => 'sample:window',
                        'label' => 'KITCHEN WIN',
                        'role' => YarboHome::CONTACT_WINDOW,
                    ],
                ];
            }

            return [[
                'id' => 'sample:door',
                'label' => 'FRONT DOOR',
                'role' => YarboHome::CONTACT_DOOR,
            ]];
        }
        if ($kind === 'both' && count($picked) === 1) {
            $haveWindow = ($picked[0]['role'] ?? '') === YarboHome::CONTACT_WINDOW;
            $picked[] = $haveWindow
                ? [
                    'id' => 'sample:door',
                    'label' => 'FRONT DOOR',
                    'role' => YarboHome::CONTACT_DOOR,
                ]
                : [
                    'id' => 'sample:window',
                    'label' => 'KITCHEN WIN',
                    'role' => YarboHome::CONTACT_WINDOW,
                ];
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nightContactLabel(array $row, string $role): string
    {
        $name = strtoupper(trim((string) ($row['name'] ?? '')));
        $name = preg_replace('/[^A-Z0-9 !@#$()\-+;:\'"%,.\/?]/', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name);
        $name = preg_replace(
            '/\s+(SENSOR|CONTACT|POSITION|DPS|DOOR POSITION|USP)$/',
            '',
            $name
        ) ?? $name;
        $name = trim($name);
        if (str_starts_with($name, 'THE ')) {
            $name = trim(substr($name, 4));
        }
        if ($name === '') {
            $name = $role === YarboHome::CONTACT_WINDOW ? 'WINDOW' : 'DOOR';
        }

        return $this->fitNightName($name, self::COLS);
    }

    /**
     * Fit a label into the Vestaboard row width, preferring whole words.
     */
    public function fitNightName(string $name, int $max = self::COLS): string
    {
        $name = strtoupper(trim($name));
        $name = preg_replace('/[^A-Z0-9 !@#$()\-+;:\'"%,.\/?]/', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name);
        if ($max < 1) {
            return '';
        }
        if (strlen($name) <= $max) {
            return $name;
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        while (count($parts) > 1) {
            array_pop($parts);
            $joined = implode(' ', $parts);
            if (strlen($joined) <= $max) {
                return $joined;
            }
        }
        $one = $parts[0] ?? '';
        if (strlen($one) <= $max) {
            return $one;
        }

        return substr($one, 0, $max);
    }

    /**
     * Synchronous UniFi door/window refresh for Night hours (not the async Home kick).
     */
    private function refreshNightContacts(): void
    {
        try {
            $hub = new YarboHub($this->projectRoot);
            if (!$hub->enabled(YarboHub::MODULE_UNIFI)) {
                return;
            }
            $unifi = new YarboUnifi($this->projectRoot);
            $unifi->refreshProtectSensorsNow(1.5);
            $unifi->refreshAccessDoorsNow(1.5);
        } catch (\Throwable) {
            // Keep last inventory; next tick retries.
        }
    }

    /**
     * Center a status phrase; empty flaps become $padColor (green/red).
     *
     * @return array{0: string, 1: list<int>}
     */
    private function packCenteredStatus(string $phrase, int $padColor): array
    {
        $phrase = strtoupper(trim($phrase));
        $phrase = preg_replace('/[^A-Z0-9 !@#$()\-+;:\'"%,.\/?]/', '', $phrase) ?? '';
        if (strlen($phrase) > self::COLS) {
            $phrase = substr($phrase, 0, self::COLS);
        }
        $start = intdiv(self::COLS - strlen($phrase), 2);
        $line = str_repeat(' ', $start) . $phrase . str_repeat(' ', self::COLS - $start - strlen($phrase));
        $codes = $this->encodeLine($line);
        $end = $start + strlen($phrase);
        if ($padColor > 0 && strlen($phrase) < self::COLS) {
            for ($i = 0; $i < self::COLS; $i++) {
                if ($i < $start || $i >= $end) {
                    $codes[$i] = $padColor;
                }
            }
        }

        return [$line, $codes];
    }

    public static function normalizeClock(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m) === 1) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $fallback;
    }

    /**
     * @param mixed $raw
     * @return list<list<int>>
     */
    public static function normalizeQuietCodes(mixed $raw): array
    {
        $fallback = self::defaultQuietCodesStatic();
        if (!is_array($raw) || count($raw) < self::ROWS) {
            return $fallback;
        }
        $out = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $row = $raw[$r] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $codes = [];
            for ($c = 0; $c < self::COLS; $c++) {
                $n = isset($row[$c]) && is_numeric($row[$c]) ? (int) $row[$c] : 0;
                if ($n < 0 || $n > 69) {
                    $n = 0;
                }
                $codes[] = $n;
            }
            $out[] = $codes;
        }

        return $out;
    }

    /**
     * @param list<list<int>> $codes
     * @return list<string>
     */
    public static function linesFromCodes(array $codes): array
    {
        $lines = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $line = '';
            for ($c = 0; $c < self::COLS; $c++) {
                $line .= self::glyphFromCode((int) ($codes[$r][$c] ?? 0));
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public static function glyphFromCode(int $code): string
    {
        if ($code >= 1 && $code <= 26) {
            return chr(64 + $code);
        }
        if ($code >= 27 && $code <= 35) {
            return chr($code + 22);
        }
        if ($code === 36) {
            return '0';
        }

        return match ($code) {
            37 => '!',
            38 => '@',
            39 => '#',
            40 => '$',
            41 => '(',
            42 => ')',
            44 => '-',
            46 => '+',
            47 => '&',
            48 => '=',
            49 => ';',
            50 => ':',
            52 => "'",
            53 => '"',
            54 => '%',
            55 => ',',
            56 => '.',
            59 => '/',
            60 => '?',
            default => ' ',
        };
    }

    /**
     * @param array<string, mixed> $override
     * @return array{
     *   enabled: bool,
     *   transport: string,
     *   host: string,
     *   port: int,
     *   api_key: string,
     *   cloud_token: string,
     *   last_hash: string,
     *   last_sent_at: ?string,
     *   last_error: string
     * }
     */
    private function mergedConfig(array $override): array
    {
        $config = $this->load();
        if (isset($override['transport']) || isset($override['vestaboard_transport'])) {
            $config['transport'] = $this->normalizeTransport(
                $override['transport'] ?? $override['vestaboard_transport'] ?? $config['transport']
            );
        }
        if (isset($override['host']) && is_string($override['host']) && trim($override['host']) !== '') {
            $config['host'] = trim($override['host']);
        }
        if (isset($override['port']) && is_numeric($override['port'])) {
            $port = (int) $override['port'];
            if ($port >= 1 && $port <= 65535) {
                $config['port'] = $port;
            }
        }
        if (isset($override['api_key']) && is_string($override['api_key']) && $override['api_key'] !== '') {
            $config['api_key'] = $override['api_key'];
        }
        if (isset($override['cloud_token']) && is_string($override['cloud_token']) && $override['cloud_token'] !== '') {
            $config['cloud_token'] = $override['cloud_token'];
        }

        return $config;
    }

    private function normalizeTransport(mixed $value): string
    {
        return strtolower(trim((string) $value)) === self::TRANSPORT_CLOUD
            ? self::TRANSPORT_CLOUD
            : self::TRANSPORT_LOCAL;
    }

    /**
     * @param array{transport: string, api_key: string, cloud_token: string} $config
     */
    private function missingCredentialError(array $config): ?string
    {
        if ($config['transport'] === self::TRANSPORT_CLOUD) {
            return $config['cloud_token'] === '' ? 'Vestaboard Cloud API token is not set.' : null;
        }

        return $config['api_key'] === '' ? 'Vestaboard Local API key is not set.' : null;
    }

    private function publicLastError(string $error): ?string
    {
        if ($error === '' || $this->isAlreadyOnBoardError($error)) {
            return null;
        }

        return $error;
    }

    private function isAlreadyOnBoardError(string $error): bool
    {
        $lower = strtolower($error);

        return str_contains($error, 'HTTP 409') || str_contains($lower, 'already displayed');
    }

    /**
     * @param list<list<int>> $codes
     */
    private function layoutHasGlyph(array $codes): bool
    {
        foreach ($codes as $row) {
            foreach ($row as $code) {
                if ((int) $code !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<list<int>>|null $body
     * @param array{transport: string, host: string, port: int, api_key: string, cloud_token: string} $config
     * @return array<string, mixed>
     */
    private function http(string $method, array $config, ?array $body): array
    {
        if (($config['transport'] ?? self::TRANSPORT_LOCAL) === self::TRANSPORT_CLOUD) {
            return $this->httpCloud($method, $config, $body);
        }

        return $this->httpLocal($method, $config, $body);
    }

    /**
     * @param list<list<int>>|null $body
     * @param array{host: string, port: int, api_key: string} $config
     * @return array<string, mixed>
     */
    private function httpLocal(string $method, array $config, ?array $body): array
    {
        $host = $config['host'];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            return ['ok' => false, 'error' => 'Board host looks invalid. Use an IPv4 address or hostname such as vestaboard.local.'];
        }
        $url = 'http://' . $host . ':' . (int) $config['port'] . '/local-api/message';
        $payload = $body !== null ? json_encode($body) : null;
        if ($body !== null && $payload === false) {
            return ['ok' => false, 'error' => 'Could not encode Vestaboard payload.'];
        }

        return $this->request($url, $method, [
            'X-Vestaboard-Local-Api-Key: ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 'Vestaboard at ' . $host, 4, 8);
    }

    /**
     * Cloud Read/Write API: GET/POST https://cloud.vestaboard.com/ with X-Vestaboard-Token.
     *
     * @param list<list<int>>|null $body
     * @param array{cloud_token: string} $config
     * @return array<string, mixed>
     */
    private function httpCloud(string $method, array $config, ?array $body): array
    {
        $payload = null;
        if ($body !== null) {
            $payload = json_encode(['characters' => $body]);
            if ($payload === false) {
                return ['ok' => false, 'error' => 'Could not encode Vestaboard payload.'];
            }
        }

        return $this->request(self::CLOUD_URL, $method, [
            'X-Vestaboard-Token: ' . $config['cloud_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 'Vestaboard Cloud API', 6, 12);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function request(
        string $url,
        string $method,
        array $headers,
        ?string $payload,
        string $label,
        int $connectTimeout,
        int $timeout
    ): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'Could not start HTTP to the Vestaboard.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'error' => 'Could not reach ' . $label . ': ' . $err];
            }

            return $this->httpStatusResult($code, is_string($raw) ? $raw : '');
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
        ];
        if ($payload !== null) {
            $opts['http']['content'] = $payload;
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Could not reach ' . $label . '.'];
        }
        $code = 0;
        if (function_exists('http_get_last_response_headers')) {
            foreach (http_get_last_response_headers() ?: [] as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $line, $matches)) {
                    $code = (int) $matches[1];
                }
            }
        }

        return $this->httpStatusResult($code, is_string($raw) ? $raw : '');
    }

    private function httpStatusResult(int $code, string $raw): array
    {
        if ($code === 409) {
            return ['ok' => true];
        }
        if ($code === 503) {
            return ['ok' => false, 'error' => 'Vestaboard rate-limited the write (wait 15 seconds).'];
        }
        if ($code === 401) {
            return ['ok' => false, 'error' => 'Vestaboard rejected the API credential.'];
        }
        if ($code === 403) {
            return ['ok' => false, 'error' => 'Vestaboard token is missing the needed permission (Read for Test, Write for Send).'];
        }
        if ($code !== 0 && ($code < 200 || $code >= 300)) {
            $hint = $this->shortHttpError($raw);

            return ['ok' => false, 'error' => 'Vestaboard HTTP ' . $code . ($hint !== '' ? ': ' . $hint : '')];
        }
        $decoded = json_decode($raw, true);

        return [
            'ok' => true,
            'body' => is_array($decoded) ? $decoded : $raw,
            'raw' => $raw,
        ];
    }

    private function shortHttpError(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            foreach (['error', 'message', 'status'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                    return substr($decoded[$key], 0, 160);
                }
            }
        }

        return substr(preg_replace('/\s+/', ' ', $trimmed) ?? '', 0, 160);
    }
}
