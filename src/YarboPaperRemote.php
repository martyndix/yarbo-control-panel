<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Optional HTTPS origin for PaperMono / Paper Colour when they leave the LAN.
 * The public tunnel must only reach /api/device.php (see scripts/paper_remote_router.php).
 */
final class YarboPaperRemote
{
    public const GATE_PORT = 8089;
    public const PROVIDER_TAILSCALE = 'tailscale';
    public const PROVIDER_CUSTOM = 'custom';
    public const VIA_HEADER = 'X-Yarbo-Paper-Via';
    public const NET_HEADER = 'X-Yarbo-Paper-Net';

    /** @var list<string> */
    public const GET_ACTIONS = ['compact', 'plans', 'firmware', 'logo'];

    /** @var list<string> */
    public const POST_ACTIONS = ['command', 'paper_message', 'paper_read'];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/data/paper-remote.json';
    }

    public function scriptPath(): string
    {
        return $this->projectRoot . '/scripts/paper_remote.sh';
    }

    /**
     * @return array{
     *   enabled: bool,
     *   provider: string,
     *   origin: string
     * }
     */
    public function load(): array
    {
        $defaults = [
            'enabled' => false,
            'provider' => self::PROVIDER_TAILSCALE,
            'origin' => '',
            'funnel_origin' => '',
        ];
        if (!is_file($this->configPath())) {
            return $defaults;
        }
        $raw = file_get_contents($this->configPath());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        $provider = (string) ($decoded['provider'] ?? self::PROVIDER_TAILSCALE);
        if ($provider !== self::PROVIDER_CUSTOM) {
            $provider = self::PROVIDER_TAILSCALE;
        }
        $origin = $this->normalizeOrigin((string) ($decoded['origin'] ?? ''));
        $funnelOrigin = $this->normalizeOrigin((string) ($decoded['funnel_origin'] ?? ''));
        if ($funnelOrigin === '' && self::originLooksLikeFunnel($origin)) {
            $funnelOrigin = $origin;
        }

        return [
            'enabled' => !empty($decoded['enabled']),
            'provider' => $provider,
            'origin' => $origin,
            'funnel_origin' => $funnelOrigin,
        ];
    }

    /**
     * HTTPS origin tablets should try after LAN, or empty when remote is off.
     * Only Tailscale Funnel (*.ts.net). Never push a browser client-certificate
     * subdomain — that briefly worked for nothing, then overwrote remurl and
     * left the tablet offline on the next poll.
     */
    public function tabletOrigin(): string
    {
        $cfg = $this->load();
        if (!$cfg['enabled']) {
            return '';
        }
        $funnel = $this->normalizeOrigin((string) ($cfg['funnel_origin'] ?? ''));
        if ($funnel !== '' && self::originLooksLikeFunnel($funnel)) {
            return $funnel;
        }
        $origin = $cfg['origin'];
        if ($origin !== '' && self::originLooksLikeFunnel($origin)) {
            return $origin;
        }
        // Omit remote_url from compact so the tablet keeps its stored Funnel URL.
        return '';
    }

    public static function originLooksLikeFunnel(string $origin): bool
    {
        $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));

        return $host !== '' && str_ends_with($host, '.ts.net');
    }

    public function enabled(): bool
    {
        return $this->load()['enabled'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $current = $this->load();
        $enabled = array_key_exists('enabled', $input)
            ? self::asBool($input['enabled'])
            : $current['enabled'];
        $provider = (string) ($input['provider'] ?? $current['provider']);
        if ($provider !== self::PROVIDER_CUSTOM) {
            $provider = self::PROVIDER_TAILSCALE;
        }
        $originIn = array_key_exists('origin', $input) || array_key_exists('remote_url', $input)
            ? (string) ($input['origin'] ?? $input['remote_url'] ?? '')
            : $current['origin'];
        $origin = $this->normalizeOrigin($originIn);
        if ($enabled && $provider === self::PROVIDER_CUSTOM) {
            $check = $this->validateOrigin($origin);
            if ($check !== null) {
                return ['ok' => false, 'error' => $check];
            }
        }
        if ($enabled && $provider === self::PROVIDER_TAILSCALE && $origin !== '') {
            $check = $this->validateOrigin($origin);
            if ($check !== null) {
                return ['ok' => false, 'error' => $check];
            }
        }
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create the data folder.'];
        }
        $funnelOrigin = $current['funnel_origin'];
        if (self::originLooksLikeFunnel($origin)) {
            $funnelOrigin = $origin;
        }
        $payload = [
            'enabled' => $enabled,
            'provider' => $provider,
            'origin' => $origin,
            'funnel_origin' => $funnelOrigin,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($this->configPath(), $json . "\n") === false) {
            return ['ok' => false, 'error' => 'Could not write paper remote settings. Check permissions on data/.'];
        }
        $this->syncSidecar($enabled);

        return ['ok' => true] + $this->publicView(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(bool $probe = true): array
    {
        $cfg = $this->load();
        $view = [
            'enabled' => $cfg['enabled'],
            'provider' => $cfg['provider'],
            'origin' => $cfg['origin'],
            'funnel_origin' => $cfg['funnel_origin'],
            'tablet_url' => $this->tabletOrigin(),
            'gate_port' => self::GATE_PORT,
            'gate_listening' => $this->gateListening(),
            'suggested_panel_url' => self::suggestLanPanelUrl(),
        ];
        if ($probe) {
            $view['tailscale'] = $this->tailscaleStatus();
            if ($cfg['enabled']) {
                $funnelOrigin = $this->originFromTailscale($view['tailscale']);
                if ($funnelOrigin !== '') {
                    $this->rememberFunnelOrigin($funnelOrigin);
                    $view['funnel_origin'] = $funnelOrigin;
                    // Tablets always use Funnel when it is up — not a cert-gated browser subdomain.
                    $view['tablet_url'] = $funnelOrigin;
                    if ($cfg['provider'] === self::PROVIDER_TAILSCALE && $funnelOrigin !== $cfg['origin']) {
                        $this->saveOriginQuiet($funnelOrigin);
                        $view['origin'] = $funnelOrigin;
                    }
                }
            }
        } else {
            $view['tailscale'] = self::emptyTailscaleStatus();
        }

        return $view;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @return array{ok: bool, status?: int, error?: string}
     */
    public function allowRemoteRequest(string $method, string $path, array $query, array $headers, string $body): array
    {
        $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        $pathOnly = '/' . ltrim($pathOnly, '/');
        if ($pathOnly !== '/api/device.php') {
            return ['ok' => false, 'status' => 404, 'error' => 'Not found'];
        }
        $token = self::tokenFrom($headers, $query, $body);
        if (!self::tokenLooksValid($token)) {
            return ['ok' => false, 'status' => 401, 'error' => 'Paper token required'];
        }
        $method = strtoupper($method);
        if ($method === 'GET') {
            $action = (string) ($query['action'] ?? '');
            if (!in_array($action, self::GET_ACTIONS, true)) {
                return ['ok' => false, 'status' => 403, 'error' => 'This path is not available remotely'];
            }

            return ['ok' => true];
        }
        if ($method === 'POST') {
            $action = self::actionFromBody($body, $query);
            if (!in_array($action, self::POST_ACTIONS, true)) {
                return ['ok' => false, 'status' => 403, 'error' => 'This path is not available remotely'];
            }

            return ['ok' => true];
        }

        return ['ok' => false, 'status' => 405, 'error' => 'Method not allowed'];
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function requestIsRemote(array $query = []): bool
    {
        $via = strtolower(trim((string) ($_SERVER['HTTP_X_YARBO_PAPER_VIA'] ?? '')));
        if ($via === 'remote') {
            return true;
        }
        if (function_exists('getallheaders')) {
            $got = getallheaders();
            if (is_array($got)) {
                foreach ($got as $name => $value) {
                    if (strtolower((string) $name) === 'x-yarbo-paper-via'
                        && strtolower(trim((string) $value)) === 'remote') {
                        return true;
                    }
                }
            }
        }
        $q = strtolower(trim((string) ($query['via'] ?? $query['remote'] ?? '')));

        return in_array($q, ['1', 'true', 'yes', 'remote'], true);
    }

    /**
     * Whether the tablet is joined to its flashed home SSID (not travel Wi-Fi).
     * Null when the firmware did not report it (older builds).
     *
     * @param array<string, mixed> $query
     */
    public static function requestHomeWifi(array $query = []): ?bool
    {
        $net = strtolower(trim((string) ($_SERVER['HTTP_X_YARBO_PAPER_NET'] ?? '')));
        if ($net === '' && function_exists('getallheaders')) {
            $got = getallheaders();
            if (is_array($got)) {
                foreach ($got as $name => $value) {
                    if (strtolower((string) $name) === 'x-yarbo-paper-net') {
                        $net = strtolower(trim((string) $value));
                        break;
                    }
                }
            }
        }
        if ($net === '') {
            $net = strtolower(trim((string) ($query['net'] ?? $query['wifi'] ?? '')));
        }
        if (in_array($net, ['home', 'lan', 'local', '1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($net, ['travel', 'guest', 'away', 'remote', '0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Forward an allowed tablet request to the LAN panel.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function proxyToPanel(string $method, array $query, array $headers, string $body): void
    {
        $port = (string) (getenv('YARBO_PANEL_PORT') ?: '8080');
        if (!preg_match('/^[0-9]+$/', $port)) {
            $port = '8080';
        }
        $url = 'http://127.0.0.1:' . $port . '/api/device.php';
        $qs = [];
        foreach ($query as $key => $value) {
            if (is_scalar($value)) {
                $qs[(string) $key] = (string) $value;
            }
        }
        if ($qs !== []) {
            $url .= '?' . http_build_query($qs);
        }
        $hdrs = [];
        foreach ($headers as $name => $value) {
            $lname = strtolower((string) $name);
            if (in_array($lname, ['host', 'content-length', 'connection', 'accept-encoding', 'transfer-encoding'], true)) {
                continue;
            }
            $hdrs[] = $name . ': ' . $value;
        }
        $hdrs[] = self::VIA_HEADER . ': remote';
        $method = strtoupper($method);
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $hdrs),
                'content' => $method === 'GET' || $method === 'HEAD' ? '' : $body,
                'ignore_errors' => true,
                'timeout' => 180,
                'follow_location' => 0,
            ],
        ];
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        $lines = $http_response_header ?? [];
        $status = 502;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $matches)) {
                $status = (int) $matches[1];
                continue;
            }
            $lower = strtolower($line);
            if (str_starts_with($lower, 'transfer-encoding:')
                || str_starts_with($lower, 'connection:')
                || str_starts_with($lower, 'keep-alive:')) {
                continue;
            }
            if (str_contains($line, ':')) {
                header($line, false);
            }
        }
        http_response_code($status);
        echo $raw === false ? json_encode(['ok' => false, 'error' => 'Panel not reachable']) : $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function runStep(string $step): array
    {
        $step = trim($step);
        $allowed = ['status', 'install', 'up', 'funnel-on', 'funnel-off', 'start-gate', 'stop-gate', 'ensure'];
        if (!in_array($step, $allowed, true)) {
            return ['ok' => false, 'error' => 'Unknown Tailscale step'];
        }
        $script = $this->scriptPath();
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'scripts/paper_remote.sh is missing.'];
        }
        $result = $this->runScript($step);
        if ($step === 'funnel-on' || $step === 'ensure' || $step === 'status' || $step === 'up' || $step === 'install') {
            $view = $this->publicView($step !== 'install');
            if (is_array($result)) {
                $view = $this->mergeScriptResult($view, $result);
            }
            $ok = is_array($result) ? (!isset($result['ok']) || !empty($result['ok'])) : false;

            return ['ok' => $ok] + $view;
        }

        return is_array($result) ? $result + $this->publicView(false) : ['ok' => false, 'error' => 'No response'] + $this->publicView(false);
    }

    public function gateListening(): bool
    {
        $errno = 0;
        $err = '';
        $fp = @fsockopen('127.0.0.1', self::GATE_PORT, $errno, $err, 0.15);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function tailscaleStatus(): array
    {
        $script = $this->scriptPath();
        if (!is_file($script)) {
            return self::emptyTailscaleStatus('paper_remote.sh missing');
        }
        $result = $this->runScript('status');
        if (!is_array($result)) {
            return self::emptyTailscaleStatus('Could not read Tailscale status');
        }

        return $this->tailscaleFromScript($result);
    }

    public function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        $origin = rtrim($origin, '/');
        if ($origin === '') {
            return '';
        }
        if (!preg_match('#^https://#i', $origin)) {
            return $origin;
        }

        return preg_replace('#^https://#i', 'https://', $origin) ?? $origin;
    }

    public function validateOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return 'Paste an HTTPS URL, or finish Tailscale Funnel so this panel can fill it in.';
        }
        if (!preg_match('#^https://[a-z0-9.-]+#i', $origin)) {
            return 'Remote URL must start with https:// and a hostname (not a LAN IP).';
        }
        $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return 'Remote URL must be a public HTTPS hostname, not localhost.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 'Remote URL must be a DNS name (Tailscale or Cloudflare), not a raw IP.';
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public static function tokenFrom(array $headers, array $query, string $body): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-papermono-token') {
                $token = trim((string) $value);
                if ($token !== '') {
                    return $token;
                }
            }
            if (strtolower((string) $name) === 'authorization' && stripos((string) $value, 'Bearer ') === 0) {
                $token = trim(substr((string) $value, 7));
                if ($token !== '') {
                    return $token;
                }
            }
        }
        $q = trim((string) ($query['token'] ?? ''));
        if ($q !== '') {
            return $q;
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $t = trim((string) ($decoded['token'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    public static function tokenLooksValid(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16,64}$/i', $token);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function actionFromBody(string $body, array $query): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['action'])) {
            return trim((string) $decoded['action']);
        }
        if (preg_match('/(?:^|&)action=([^&]+)/', $body, $matches)) {
            return trim(urldecode($matches[1]));
        }

        return trim((string) ($query['action'] ?? ''));
    }

    /**
     * @param array<string, mixed> $ts
     */
    private function originFromTailscale(array $ts): string
    {
        $name = trim((string) ($ts['dns_name'] ?? ''), '.');
        if ($name === '' || empty($ts['funnel_on'])) {
            return '';
        }

        return 'https://' . $name;
    }

    private function saveOriginQuiet(string $origin): void
    {
        $cfg = $this->load();
        $cfg['origin'] = $origin;
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($this->configPath(), $json . "\n");
        }
    }

    private function syncSidecar(bool $enabled): void
    {
        if (!$enabled) {
            $this->runScript('stop-gate');
            $this->runScript('funnel-off');

            return;
        }
        // Keep Funnel + the path-limited gate even when the form shows a custom
        // HTTPS URL. Browser-only client-cert subdomains must not tear Funnel down —
        // ESP32 tablets rely on the .ts.net Funnel origin.
        $this->runScript('ensure');
    }

    private function rememberFunnelOrigin(string $origin): void
    {
        $origin = $this->normalizeOrigin($origin);
        if ($origin === '' || !self::originLooksLikeFunnel($origin)) {
            return;
        }
        $cfg = $this->load();
        if ($cfg['funnel_origin'] === $origin
            && ($cfg['provider'] !== self::PROVIDER_TAILSCALE || $cfg['origin'] === $origin)) {
            return;
        }
        $cfg['funnel_origin'] = $origin;
        if ($cfg['provider'] === self::PROVIDER_TAILSCALE) {
            $cfg['origin'] = $origin;
        }
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($this->configPath(), $json . "\n");
        }
    }

    /**
     * LAN Panel URL for USB flash / home Wi-Fi — never the public browser hostname.
     */
    public static function suggestLanPanelUrl(?int $port = null): string
    {
        $port = $port ?? (int) (getenv('YARBO_PANEL_PORT') ?: 8080);
        if ($port <= 0) {
            $port = 8080;
        }
        $out = trim((string) @shell_exec('hostname -I'));
        $parts = preg_split('/\s+/', $out) ?: [];
        $fallback = '';
        foreach ($parts as $ip) {
            $ip = trim((string) $ip);
            if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip) !== 1) {
                continue;
            }
            if ($fallback === '') {
                $fallback = $ip;
            }
            if (self::ipv4IsPrivate($ip)) {
                return 'http://' . $ip . ':' . $port;
            }
        }
        if ($fallback !== '') {
            return 'http://' . $fallback . ':' . $port;
        }

        return 'http://192.168.1.50:' . $port;
    }

    public static function ipv4IsPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $private = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];
        foreach ($private as [$lo, $hi]) {
            if ($long >= ip2long($lo) && $long <= ip2long($hi)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when Panel URL is a LAN host the tablet can use on home Wi-Fi.
     */
    public static function panelUrlLooksLan(string $url): bool
    {
        $host = strtolower((string) (parse_url(trim($url), PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::ipv4IsPrivate($host);
        }
        foreach (['.local', '.lan', '.home.arpa', '.internal'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep login / Funnel URLs from the step stdout when a later status probe is empty.
     *
     * @param array<string, mixed> $view
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function mergeScriptResult(array $view, array $result): array
    {
        $view['step'] = $result;
        $ts = $this->tailscaleFromScript(array_merge(
            is_array($view['tailscale'] ?? null) ? $view['tailscale'] : [],
            $result
        ));
        foreach (['auth_url', 'funnel_enable_url', 'dns_name', 'backend', 'login_hint', 'sudo_hint'] as $key) {
            $value = trim((string) ($result[$key] ?? ''));
            if ($value !== '') {
                $ts[$key] = $value;
            }
        }
        foreach (['logged_in', 'funnel_on', 'needs_funnel_acl', 'installed'] as $key) {
            if (!empty($result[$key])) {
                $ts[$key] = true;
            }
        }
        $stepError = trim((string) ($result['error'] ?? ''));
        if ($stepError !== '') {
            $ts['error'] = $stepError;
            $view['error'] = $stepError;
        }
        $message = trim((string) ($result['message'] ?? ''));
        if ($message !== '') {
            $view['message'] = $message;
        }
        $view['tailscale'] = $ts;

        return $view;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function tailscaleFromScript(array $result): array
    {
        $status = self::emptyTailscaleStatus();
        $status['installed'] = !empty($result['installed']);
        $status['logged_in'] = !empty($result['logged_in']);
        $status['auth_url'] = (string) ($result['auth_url'] ?? '');
        $status['dns_name'] = (string) ($result['dns_name'] ?? '');
        $status['funnel_on'] = !empty($result['funnel_on']);
        $status['needs_funnel_acl'] = !empty($result['needs_funnel_acl']);
        $status['funnel_enable_url'] = (string) ($result['funnel_enable_url'] ?? '');
        $status['backend'] = (string) ($result['backend'] ?? '');
        $status['error'] = (string) ($result['error'] ?? '');
        $status['login_hint'] = (string) ($result['login_hint'] ?? '');
        $status['sudo_hint'] = (string) ($result['sudo_hint'] ?? '');

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyTailscaleStatus(string $error = ''): array
    {
        return [
            'installed' => false,
            'logged_in' => false,
            'auth_url' => '',
            'dns_name' => '',
            'funnel_on' => false,
            'needs_funnel_acl' => false,
            'funnel_enable_url' => '',
            'backend' => '',
            'error' => $error,
            'login_hint' => '',
            'sudo_hint' => '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runScript(string $step): ?array
    {
        $script = $this->scriptPath();
        $cmd = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($step) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $raw = trim(implode("\n", $out));
        $decoded = $this->decodeScriptOutput($raw);

        return $decoded ?? [
            'ok' => false,
            'error' => $this->emptyScriptError($raw, $code),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeScriptOutput(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                if (count($lines) > 1) {
                    $decoded['log'] = substr($raw, 0, 800);
                }

                return $decoded;
            }
        }

        return [
            'ok' => false,
            'error' => substr($raw, 0, 400),
            'log' => substr($raw, 0, 800),
        ];
    }

    private function emptyScriptError(string $raw, int $code): string
    {
        $hint = 'sudo ' . $this->scriptPath() . ' funnel-on';
        $logPath = $this->projectRoot . '/data/paper-remote.log';
        $log = '';
        if (is_file($logPath) && is_readable($logPath)) {
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
            if (is_array($lines) && $lines !== []) {
                $log = trim(implode(' ', array_slice($lines, -8)));
            }
        }
        $error = 'Start Funnel produced no JSON (exit ' . $code . '). On the Pi run: ' . $hint;
        if ($raw !== '') {
            $error .= ' Output: ' . substr($raw, 0, 240);
        }
        if ($log !== '') {
            $error .= ' Log: ' . substr($log, 0, 240);
        }

        return $error;
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'true', 'on', 'yes'], true);
    }
}
