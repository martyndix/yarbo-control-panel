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

        return [
            'enabled' => !empty($decoded['enabled']),
            'provider' => $provider,
            'origin' => $origin,
        ];
    }

    /**
     * HTTPS origin tablets should try after LAN, or empty when remote is off.
     */
    public function tabletOrigin(): string
    {
        $cfg = $this->load();
        if (!$cfg['enabled']) {
            return '';
        }

        return $cfg['origin'];
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
        $payload = [
            'enabled' => $enabled,
            'provider' => $provider,
            'origin' => $origin,
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
            'tablet_url' => $this->tabletOrigin(),
            'gate_port' => self::GATE_PORT,
            'gate_listening' => $this->gateListening(),
        ];
        if ($probe) {
            $view['tailscale'] = $this->tailscaleStatus();
            if ($cfg['enabled'] && $cfg['provider'] === self::PROVIDER_TAILSCALE) {
                $funnelOrigin = $this->originFromTailscale($view['tailscale']);
                if ($funnelOrigin !== '' && $funnelOrigin !== $cfg['origin']) {
                    $this->saveOriginQuiet($funnelOrigin);
                    $view['origin'] = $funnelOrigin;
                    $view['tablet_url'] = $cfg['enabled'] ? $funnelOrigin : '';
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
        $cfg = $this->load();
        if ($enabled && $cfg['provider'] === self::PROVIDER_TAILSCALE) {
            $this->runScript('ensure');

            return;
        }
        $this->runScript('stop-gate');
        if (!$enabled || $cfg['provider'] !== self::PROVIDER_TAILSCALE) {
            $this->runScript('funnel-off');
        }
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
        $cmd = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($step) . ' 2>/dev/null';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $raw = trim(implode("\n", $out));
        if ($raw === '') {
            return ['ok' => $code === 0, 'error' => $code === 0 ? '' : 'Command produced no output'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => $code === 0,
                'error' => $code === 0 ? '' : substr($raw, 0, 400),
                'log' => substr($raw, 0, 800),
            ];
        }

        return $decoded;
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
