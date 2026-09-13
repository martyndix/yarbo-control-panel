<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboPowerwall
{
    public const TRANSPORT_LOCAL = 'local';
    public const TRANSPORT_CLOUD = 'cloud';
    public const CACHE_TTL_SECONDS = 12;
    public const STALE_ONLINE_SECONDS = 600;
    public const VB_BATTERY_INTERVAL_SECONDS = 120;
    public const VB_POWER_INTERVAL_SECONDS = 300;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/powerwall-config.json';
    }

    public function cachePath(): string
    {
        return $this->projectRoot . '/data/powerwall-cache.json';
    }

    public function privateKeyPath(): string
    {
        return $this->projectRoot . '/data/tesla-fleet-private.pem';
    }

    public function publicKeyPath(): string
    {
        return $this->projectRoot . '/public/.well-known/appspecific/com.tesla.3p.public-key.pem';
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $defaults = [
            'transport' => self::TRANSPORT_CLOUD,
            'gateway_host' => '',
            'gateway_email' => '',
            'gateway_password' => '',
            'region' => 'eu',
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
            'access_token' => '',
            'access_expires_at' => 0,
            'energy_site_id' => '',
            'public_panel_url' => '',
            'last_error' => '',
            'vb_batt' => null,
            'vb_batt_at' => null,
            'vb_solar_w' => null,
            'vb_solar_at' => null,
            'vb_load_w' => null,
            'vb_load_at' => null,
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, [
            'transport' => strtolower((string) ($decoded['transport'] ?? self::TRANSPORT_CLOUD)) === self::TRANSPORT_LOCAL
                ? self::TRANSPORT_LOCAL
                : self::TRANSPORT_CLOUD,
            'gateway_host' => trim((string) ($decoded['gateway_host'] ?? '')),
            'gateway_email' => trim((string) ($decoded['gateway_email'] ?? '')),
            'gateway_password' => (string) ($decoded['gateway_password'] ?? ''),
            'region' => $this->normalizeRegion((string) ($decoded['region'] ?? 'eu')),
            'client_id' => trim((string) ($decoded['client_id'] ?? '')),
            'client_secret' => (string) ($decoded['client_secret'] ?? ''),
            'refresh_token' => (string) ($decoded['refresh_token'] ?? ''),
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'access_expires_at' => (int) ($decoded['access_expires_at'] ?? 0),
            'energy_site_id' => trim((string) ($decoded['energy_site_id'] ?? '')),
            'public_panel_url' => rtrim(trim((string) ($decoded['public_panel_url'] ?? '')), '/'),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
            'vb_batt' => $this->nullableInt($decoded['vb_batt'] ?? null),
            'vb_batt_at' => $this->nullableTime($decoded['vb_batt_at'] ?? null),
            'vb_solar_w' => $this->nullableInt($decoded['vb_solar_w'] ?? null),
            'vb_solar_at' => $this->nullableTime($decoded['vb_solar_at'] ?? null),
            'vb_load_w' => $this->nullableInt($decoded['vb_load_w'] ?? null),
            'vb_load_at' => $this->nullableTime($decoded['vb_load_at'] ?? null),
        ]);
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
        $password = $current['gateway_password'];
        if (array_key_exists('gateway_password', $input) || array_key_exists('powerwall_gateway_password', $input)) {
            $next = (string) ($input['gateway_password'] ?? $input['powerwall_gateway_password'] ?? '');
            if ($next !== '') {
                $password = $next;
            }
        }
        $secret = $current['client_secret'];
        if (array_key_exists('client_secret', $input) || array_key_exists('powerwall_client_secret', $input)) {
            $next = (string) ($input['client_secret'] ?? $input['powerwall_client_secret'] ?? '');
            if ($next !== '') {
                $secret = $next;
            }
        }
        $refresh = $current['refresh_token'];
        if (array_key_exists('refresh_token', $input) || array_key_exists('powerwall_refresh_token', $input)) {
            $next = trim((string) ($input['refresh_token'] ?? $input['powerwall_refresh_token'] ?? ''));
            if ($next !== '') {
                $refresh = $next;
            }
        }
        $next = [
            'transport' => (isset($input['transport']) || isset($input['powerwall_transport']))
                ? ((string) ($input['transport'] ?? $input['powerwall_transport']) === self::TRANSPORT_LOCAL
                    ? self::TRANSPORT_LOCAL
                    : self::TRANSPORT_CLOUD)
                : $current['transport'],
            'gateway_host' => array_key_exists('gateway_host', $input) || array_key_exists('powerwall_gateway_host', $input)
                ? trim((string) ($input['gateway_host'] ?? $input['powerwall_gateway_host'] ?? ''))
                : $current['gateway_host'],
            'gateway_email' => array_key_exists('gateway_email', $input) || array_key_exists('powerwall_gateway_email', $input)
                ? trim((string) ($input['gateway_email'] ?? $input['powerwall_gateway_email'] ?? ''))
                : $current['gateway_email'],
            'gateway_password' => $password,
            'region' => $this->normalizeRegion((string) ($input['region'] ?? $input['powerwall_region'] ?? $current['region'])),
            'client_id' => array_key_exists('client_id', $input) || array_key_exists('powerwall_client_id', $input)
                ? trim((string) ($input['client_id'] ?? $input['powerwall_client_id'] ?? ''))
                : $current['client_id'],
            'client_secret' => $secret,
            'refresh_token' => $refresh,
            'access_token' => (string) ($input['access_token'] ?? $current['access_token']),
            'access_expires_at' => (int) ($input['access_expires_at'] ?? $current['access_expires_at']),
            'energy_site_id' => array_key_exists('energy_site_id', $input) || array_key_exists('powerwall_energy_site_id', $input)
                ? trim((string) ($input['energy_site_id'] ?? $input['powerwall_energy_site_id'] ?? ''))
                : $current['energy_site_id'],
            'public_panel_url' => array_key_exists('public_panel_url', $input) || array_key_exists('powerwall_public_url', $input)
                ? rtrim(trim((string) ($input['public_panel_url'] ?? $input['powerwall_public_url'] ?? '')), '/')
                : $current['public_panel_url'],
            'last_error' => (string) ($input['last_error'] ?? $current['last_error']),
            'vb_batt' => array_key_exists('vb_batt', $input) ? $this->nullableInt($input['vb_batt']) : $current['vb_batt'],
            'vb_batt_at' => array_key_exists('vb_batt_at', $input) ? $this->nullableTime($input['vb_batt_at']) : $current['vb_batt_at'],
            'vb_solar_w' => array_key_exists('vb_solar_w', $input) ? $this->nullableInt($input['vb_solar_w']) : $current['vb_solar_w'],
            'vb_solar_at' => array_key_exists('vb_solar_at', $input) ? $this->nullableTime($input['vb_solar_at']) : $current['vb_solar_at'],
            'vb_load_w' => array_key_exists('vb_load_w', $input) ? $this->nullableInt($input['vb_load_w']) : $current['vb_load_w'],
            'vb_load_at' => array_key_exists('vb_load_at', $input) ? $this->nullableTime($input['vb_load_at']) : $current['vb_load_at'],
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
            'transport' => $config['transport'],
            'gateway_host' => $config['gateway_host'],
            'gateway_email' => $config['gateway_email'],
            'gateway_password_set' => $config['gateway_password'] !== '',
            'region' => $config['region'],
            'client_id' => $config['client_id'],
            'client_secret_set' => $config['client_secret'] !== '',
            'refresh_token_set' => $config['refresh_token'] !== '',
            'energy_site_id' => $config['energy_site_id'],
            'public_panel_url' => $config['public_panel_url'],
            'public_key_ready' => is_file($this->publicKeyPath()),
            'oauth_url' => $this->authorizeUrl(),
            'last_error' => $config['last_error'] !== '' ? $config['last_error'] : null,
        ];
    }

    /**
     * Cached house / solar / battery numbers for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array
    {
        $fresh = $this->readCache(true);
        if ($fresh !== null) {
            return $fresh;
        }
        $stale = $this->readCache(false);
        if ($stale !== null && $this->readingStillUsable($stale)) {
            $stale['online'] = true;
            $error = $this->load()['last_error'];
            if ($error !== '') {
                $stale['ok'] = false;
                $stale['error'] = $error;
            }

            return $stale;
        }

        return [
            'ok' => false,
            'online' => false,
            'source' => $this->load()['transport'],
            'error' => $this->load()['last_error'] !== '' ? $this->load()['last_error'] : 'No Powerwall reading yet.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $config = $this->load();
        try {
            $payload = $config['transport'] === self::TRANSPORT_LOCAL
                ? $this->fetchLocal($config)
                : $this->fetchCloud($config);
            $payload['ok'] = true;
            $payload['online'] = true;
            $payload['fetched_at'] = gmdate('c');
            $this->writeCache($payload);
            $this->save(['last_error' => '']);

            return $payload;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->save(['last_error' => $message]);
            $stale = $this->readCache(false);
            if ($stale !== null && $this->readingStillUsable($stale)) {
                $stale['ok'] = false;
                $stale['online'] = true;
                $stale['error'] = $message;

                return $stale;
            }

            return [
                'ok' => false,
                'online' => false,
                'source' => $config['transport'],
                'error' => $message,
            ];
        }
    }

    public function refreshIfStale(): void
    {
        $hub = new YarboHub($this->projectRoot);
        if (!$hub->enabled(YarboHub::MODULE_POWERWALL)) {
            return;
        }
        $cached = $this->readCache();
        if ($cached !== null && !empty($cached['ok'])) {
            return;
        }
        $this->refresh();
    }

    /**
     * @return array{ok: bool, online: bool, lines: list<string>, codes: list<list<int>>, verb: string}
     */
    public function vestaboardLayout(): array
    {
        $data = $this->dashboardPayload();
        $online = !empty($data['online']) && (isset($data['battery_percent']) || isset($data['load_w']));
        $batt = isset($data['battery_percent']) ? (int) round((float) $data['battery_percent']) : null;
        $solarW = isset($data['solar_w']) ? (int) round((float) $data['solar_w']) : null;
        $loadW = isset($data['load_w']) ? (int) round((float) $data['load_w']) : null;
        $config = $this->load();
        [$battShow, $touchBatt] = $this->rateLimitField($config['vb_batt'], $config['vb_batt_at'], $batt, self::VB_BATTERY_INTERVAL_SECONDS);
        [$solarShow, $touchSolar] = $this->rateLimitField($config['vb_solar_w'], $config['vb_solar_at'], $solarW, self::VB_POWER_INTERVAL_SECONDS);
        [$loadShow, $touchLoad] = $this->rateLimitField($config['vb_load_w'], $config['vb_load_at'], $loadW, self::VB_POWER_INTERVAL_SECONDS);
        if ($touchBatt || $touchSolar || $touchLoad) {
            $this->save([
                'vb_batt' => $touchBatt ? $battShow : $config['vb_batt'],
                'vb_batt_at' => $touchBatt ? gmdate('c') : $config['vb_batt_at'],
                'vb_solar_w' => $touchSolar ? $solarShow : $config['vb_solar_w'],
                'vb_solar_at' => $touchSolar ? gmdate('c') : $config['vb_solar_at'],
                'vb_load_w' => $touchLoad ? $loadShow : $config['vb_load_w'],
                'vb_load_at' => $touchLoad ? gmdate('c') : $config['vb_load_at'],
            ]);
        }
        $hasNumbers = $battShow !== null || $solarShow !== null || $loadShow !== null;
        $showLive = $online || $hasNumbers;
        $verb = $showLive ? 'POWERWALL' : 'OFFLINE';
        $lines = [
            $showLive
                ? $this->pair('POWERWALL', $battShow !== null ? $battShow . '%' : '--', 14)
                : $this->pair('OFFLINE', '', 14),
            $this->pair('SOLAR', $solarShow !== null ? $this->formatW((float) $solarShow) : '--'),
            $this->pair('DRAW', $loadShow !== null ? $this->formatW((float) $loadShow) : '--'),
        ];
        $codes = YarboVestaboard::normalizeQuietCodes($this->encodeLines($lines, $battShow, $showLive));

        return [
            'ok' => true,
            'online' => $showLive,
            'lines' => YarboVestaboard::linesFromCodes($codes),
            'codes' => $codes,
            'verb' => $verb,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $result = $this->refresh();
        if (!empty($result['ok'])) {
            return ['ok' => true, 'message' => 'Reached the Powerwall (' . ($result['source'] ?? '') . ').'];
        }

        return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Powerwall test failed')];
    }

    public function generateFleetKeys(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            return ['ok' => false, 'error' => 'Could not generate an EC key pair (OpenSSL).'];
        }
        if (!openssl_pkey_export($key, $private)) {
            return ['ok' => false, 'error' => 'Could not export the Tesla private key.'];
        }
        $details = openssl_pkey_get_details($key);
        $public = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($public === '') {
            return ['ok' => false, 'error' => 'Could not export the Tesla public key.'];
        }
        $dir = dirname($this->publicKeyPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create .well-known/appspecific.'];
        }
        if (file_put_contents($this->privateKeyPath(), $private, LOCK_EX) === false
            || file_put_contents($this->publicKeyPath(), $public, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not write Tesla key files. Check data/ and public/ permissions.'];
        }

        return [
            'ok' => true,
            'message' => 'Created Tesla Fleet public key. Host this panel on HTTPS so Tesla can fetch /.well-known/appspecific/com.tesla.3p.public-key.pem',
            'public_key_url' => ($this->load()['public_panel_url'] !== ''
                ? $this->load()['public_panel_url']
                : '') . '/.well-known/appspecific/com.tesla.3p.public-key.pem',
        ];
    }

    public function authorizeUrl(): ?string
    {
        $config = $this->load();
        if ($config['client_id'] === '' || $config['public_panel_url'] === '') {
            return null;
        }
        $redirect = $config['public_panel_url'] . '/api/tesla.php?action=callback';
        $query = http_build_query([
            'client_id' => $config['client_id'],
            'locale' => 'en-US',
            'prompt' => 'login',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'openid offline_access user_data energy_device_data',
            'state' => bin2hex(random_bytes(8)),
        ]);

        return 'https://auth.tesla.com/oauth2/v3/authorize?' . $query;
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $config = $this->load();
        if ($config['client_id'] === '' || $config['public_panel_url'] === '') {
            return ['ok' => false, 'error' => 'Set Tesla Client ID and public panel URL first.'];
        }
        $redirect = $config['public_panel_url'] . '/api/tesla.php?action=callback';
        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'code' => $code,
            'redirect_uri' => $redirect,
            'audience' => $this->fleetBase($config['region']),
        ];
        if ($config['client_secret'] !== '') {
            $body['client_secret'] = $config['client_secret'];
        }
        $token = $this->httpJson('POST', $this->tokenUrl($config['region']), $body, null);
        $refresh = (string) ($token['refresh_token'] ?? '');
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            return ['ok' => false, 'error' => 'Tesla did not return an access token. Check Client ID, secret, redirect URI, and public key.'];
        }
        $this->save([
            'refresh_token' => $refresh !== '' ? $refresh : $config['refresh_token'],
            'access_token' => $access,
            'access_expires_at' => time() + max(60, (int) ($token['expires_in'] ?? 3600) - 60),
            'last_error' => '',
        ]);
        $this->refresh();

        return ['ok' => true, 'message' => 'Tesla account linked. Powerwall cloud polling is on.'];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function fetchLocal(array $config): array
    {
        $host = $config['gateway_host'];
        if ($host === '' || $config['gateway_password'] === '') {
            throw new \RuntimeException('Local Powerwall needs the Gateway IP and the 5-character customer password.');
        }
        $login = $this->httpJson(
            'POST',
            'https://' . $host . '/api/login/Basic',
            [
                'username' => 'customer',
                'email' => $config['gateway_email'] !== '' ? $config['gateway_email'] : 'panel@local',
                'password' => $config['gateway_password'],
                'force_sm_off' => false,
            ],
            null,
            true
        );
        $token = (string) ($login['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Gateway login did not return a token. Check the last-5 sticker password.');
        }
        $headers = ['Authorization: Bearer ' . $token];
        $agg = $this->httpJson('GET', 'https://' . $host . '/api/meters/aggregates', null, $headers, true);
        $soe = $this->httpJson('GET', 'https://' . $host . '/api/system_status/soe', null, $headers, true);

        return $this->normalizeReading(
            (float) ($agg['load']['instant_power'] ?? 0),
            (float) ($agg['solar']['instant_power'] ?? 0),
            (float) ($agg['site']['instant_power'] ?? 0),
            (float) ($agg['battery']['instant_power'] ?? 0),
            (float) ($soe['percentage'] ?? 0),
            'local'
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function fetchCloud(array $config): array
    {
        $access = $this->cloudAccessToken($config);
        $siteId = $config['energy_site_id'];
        if ($siteId === '') {
            $siteId = $this->discoverEnergySite($config['region'], $access);
            $this->save(['energy_site_id' => $siteId]);
        }
        $live = $this->httpJson(
            'GET',
            $this->fleetBase($config['region']) . '/api/1/energy_sites/' . rawurlencode($siteId) . '/live_status',
            null,
            ['Authorization: Bearer ' . $access]
        );
        $response = is_array($live['response'] ?? null) ? $live['response'] : $live;

        return $this->normalizeReading(
            (float) ($response['load_power'] ?? 0),
            (float) ($response['solar_power'] ?? 0),
            (float) ($response['grid_power'] ?? 0),
            (float) ($response['battery_power'] ?? 0),
            (float) ($response['percentage_charged'] ?? 0),
            'cloud'
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function cloudAccessToken(array $config): string
    {
        if ($config['refresh_token'] === '' && $config['access_token'] === '') {
            throw new \RuntimeException('Tesla cloud is not linked. Paste a refresh token or use Sign in with Tesla (see Settings).');
        }
        if ($config['access_token'] !== '' && (int) $config['access_expires_at'] > time() + 30) {
            return $config['access_token'];
        }
        if ($config['refresh_token'] === '') {
            throw new \RuntimeException('Tesla access token expired and no refresh token is stored. Sign in with Tesla again.');
        }
        $body = [
            'grant_type' => 'refresh_token',
            'client_id' => $config['client_id'] !== '' ? $config['client_id'] : 'ownerapi',
            'refresh_token' => $config['refresh_token'],
        ];
        if ($config['client_secret'] !== '') {
            $body['client_secret'] = $config['client_secret'];
        }
        $token = $this->httpJson('POST', $this->tokenUrl($config['region']), $body, null);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('Tesla refused the refresh token. Create a Fleet app and sign in again.');
        }
        $this->save([
            'access_token' => $access,
            'refresh_token' => (string) ($token['refresh_token'] ?? $config['refresh_token']),
            'access_expires_at' => time() + max(60, (int) ($token['expires_in'] ?? 3600) - 60),
        ]);

        return $access;
    }

    private function discoverEnergySite(string $region, string $access): string
    {
        $products = $this->httpJson(
            'GET',
            $this->fleetBase($region) . '/api/1/products',
            null,
            ['Authorization: Bearer ' . $access]
        );
        $list = $products['response'] ?? $products;
        if (!is_array($list)) {
            throw new \RuntimeException('Tesla products list was empty.');
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $site = $item['energy_site_id'] ?? $item['site_id'] ?? null;
            $resource = (string) ($item['resource_type'] ?? '');
            if ($site !== null && $site !== '' && ($resource === 'battery' || $resource === 'energy' || isset($item['energy_site_id']))) {
                return (string) $site;
            }
        }
        foreach ($list as $item) {
            if (is_array($item) && isset($item['energy_site_id'])) {
                return (string) $item['energy_site_id'];
            }
        }

        throw new \RuntimeException('No Tesla energy site on this account. Confirm the Tesla app shows Powerwall.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeReading(float $loadW, float $solarW, float $gridW, float $batteryW, float $percent, string $source): array
    {
        return [
            'source' => $source,
            'load_w' => $loadW,
            'solar_w' => $solarW,
            'grid_w' => $gridW,
            'battery_w' => $batteryW,
            'battery_percent' => $percent,
            'load_label' => $this->formatW($loadW),
            'solar_label' => $this->formatW($solarW),
            'grid_label' => $this->formatW($gridW),
            'battery_label' => (string) (int) round($percent) . '%',
        ];
    }

    private function formatW(float $watts): string
    {
        return (int) round($watts) . 'W';
    }

    /**
     * @param mixed $held
     * @param mixed $at
     * @param mixed $current
     * @return array{0: mixed, 1: bool}
     */
    private function rateLimitField(mixed $held, mixed $at, mixed $current, int $seconds): array
    {
        if ($current === null) {
            return [$held, false];
        }
        $atTs = is_string($at) && $at !== '' ? strtotime($at) : false;
        if ($held === null || $atTs === false) {
            return [$current, true];
        }
        if ($current === $held) {
            return [$held, false];
        }
        if ((time() - $atTs) >= $seconds) {
            return [$current, true];
        }

        return [$held, false];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readingStillUsable(array $row): bool
    {
        $at = strtotime((string) ($row['fetched_at'] ?? ''));
        if ($at === false || (time() - $at) > self::STALE_ONLINE_SECONDS) {
            return false;
        }

        return array_key_exists('battery_percent', $row) || array_key_exists('load_w', $row);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string>|null $headers
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $url, ?array $body, ?array $headers, bool $insecure = false): array
    {
        $payload = $body !== null ? json_encode($body) : null;
        $headerList = ['Accept: application/json'];
        if ($payload !== null) {
            $headerList[] = 'Content-Type: application/json';
        }
        if ($headers !== null) {
            $headerList = array_merge($headerList, $headers);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not start HTTP to Tesla / Powerwall.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach ' . $url . ': ' . $err);
        }
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if ($code < 200 || $code >= 300) {
            $hint = '';
            if (is_array($decoded)) {
                $hint = (string) ($decoded['error'] ?? $decoded['error_description'] ?? $decoded['message'] ?? '');
            }
            throw new \RuntimeException('HTTP ' . $code . ($hint !== '' ? ': ' . $hint : ''));
        }
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function fleetBase(string $region): string
    {
        return 'https://fleet-api.prd.' . $this->normalizeRegion($region) . '.vn.cloud.tesla.com';
    }

    private function tokenUrl(string $region): string
    {
        return $this->fleetBase($region) . '/oauth2/v3/token';
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));

        return in_array($region, ['na', 'eu', 'cn'], true) ? $region : 'eu';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(bool $respectTtl = true): ?array
    {
        if (!is_file($this->cachePath())) {
            return null;
        }
        $raw = file_get_contents($this->cachePath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return null;
        }
        if ($respectTtl) {
            $at = strtotime((string) ($decoded['fetched_at'] ?? ''));
            if ($at === false || (time() - $at) > self::CACHE_TTL_SECONDS) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($this->cachePath(), json_encode($payload, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    }

    private function pair(string $left, string $right, int $width = 15): string
    {
        $left = strtoupper(substr($left, 0, $width));
        $right = strtoupper(substr($right, 0, $width));
        $pad = $width - strlen($left) - strlen($right);
        if ($pad < 1) {
            return substr($left . $right, 0, $width);
        }

        return $left . str_repeat(' ', $pad) . $right;
    }

    /**
     * @param list<string> $lines
     * @return list<list<int>>
     */
    private function encodeLines(array $lines, ?int $battery, bool $online): array
    {
        $codes = [];
        for ($r = 0; $r < 3; $r++) {
            $line = str_pad(strtoupper($lines[$r] ?? ''), 15);
            $row = [];
            for ($c = 0; $c < 15; $c++) {
                $ch = $line[$c] ?? ' ';
                $row[] = $this->charToCode($ch);
            }
            $codes[] = $row;
        }
        $chip = !$online ? YarboVestaboard::COLOR_RED : YarboVestaboard::COLOR_GREEN;
        if ($online && $battery !== null) {
            $chip = $battery >= 60 ? YarboVestaboard::COLOR_GREEN
                : ($battery >= 30 ? YarboVestaboard::COLOR_YELLOW : YarboVestaboard::COLOR_ORANGE);
        }
        $codes[0][14] = $chip;

        return $codes;
    }

    private function charToCode(string $ch): int
    {
        if ($ch === ' ') {
            return 0;
        }
        $ord = ord($ch);
        if ($ord >= 65 && $ord <= 90) {
            return $ord - 64;
        }
        if ($ord >= 49 && $ord <= 57) {
            return $ord - 22;
        }
        if ($ch === '0') {
            return 36;
        }
        if ($ch === '%') {
            return 54;
        }
        if ($ch === '.') {
            return 56;
        }
        if ($ch === '-') {
            return 44;
        }

        return 0;
    }
}
