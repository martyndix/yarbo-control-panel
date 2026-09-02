<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * PaperMono companion devices (beta): pairing tokens, compact status, USB flash.
 * Target hardware: M5Stack PaperMono SKU C153 (https://docs.m5stack.com/en/core/PaperMono).
 */
final class YarboPaperDevice
{
    public const FIRMWARE_VERSION = '0.1.2-beta';
    private const PLANS_CACHE_TTL_S = 300;
    // PlatformIO env is papermono, so the binary lands in .pio/build/papermono/
    public const FIRMWARE_RELATIVE = 'firmware/papermono/.pio/build/papermono/firmware.bin';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function storePath(): string
    {
        return $this->projectRoot . '/data/papermono-devices.json';
    }

    public function firmwarePath(): string
    {
        return $this->projectRoot . '/' . self::FIRMWARE_RELATIVE;
    }

    public function firmwareAvailable(): bool
    {
        return is_file($this->firmwarePath()) && filesize($this->firmwarePath()) > 1024;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'beta' => true,
            'firmware_version' => self::FIRMWARE_VERSION,
            'firmware_built' => $this->firmwareAvailable(),
            'firmware_path' => self::FIRMWARE_RELATIVE,
            'devices' => $this->publicDevices(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicDevices(): array
    {
        $out = [];
        foreach ($this->load()['devices'] as $device) {
            if (is_array($device)) {
                $out[] = $this->publicDevice($device);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        $name = trim((string) ($input['name'] ?? 'PaperMono'));
        if ($name === '') {
            $name = 'PaperMono';
        }
        $device = [
            'id' => bin2hex(random_bytes(4)),
            'name' => $name,
            'token' => bin2hex(random_bytes(16)),
            'created_at' => gmdate('c'),
            'last_seen_at' => null,
            'fw_reported' => null,
        ];
        $store = $this->load();
        $store['devices'][] = $device;
        $this->save($store);

        return $this->publicDevice($device, true);
    }

    public function revoke(string $id): bool
    {
        $store = $this->load();
        $before = count($store['devices']);
        $store['devices'] = array_values(array_filter(
            $store['devices'],
            static fn (mixed $device): bool => is_array($device) && (string) ($device['id'] ?? '') !== $id
        ));
        if (count($store['devices']) === $before) {
            return false;
        }
        $this->save($store);

        return true;
    }

    public function findByToken(?string $token): ?array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }
        foreach ($this->load()['devices'] as $device) {
            if (is_array($device) && hash_equals((string) ($device['token'] ?? ''), $token)) {
                return $device;
            }
        }

        return null;
    }

    public function touch(string $id, ?string $fwReported = null): void
    {
        $store = $this->load();
        foreach ($store['devices'] as &$device) {
            if (!is_array($device) || (string) ($device['id'] ?? '') !== $id) {
                continue;
            }
            $device['last_seen_at'] = gmdate('c');
            if ($fwReported !== null && $fwReported !== '') {
                $device['fw_reported'] = $fwReported;
            }
        }
        unset($device);
        $this->save($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function compactStatus(): array
    {
        $agent = YarboMqttAgentClient::fromEnv();
        $result = $agent->telemetry(4.0, false);
        $raw = $result['raw'] ?? null;
        if (!($result['ok'] ?? false) || !is_array($raw) || $raw === []) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'telemetry unavailable'),
                'firmware_latest' => self::FIRMWARE_VERSION,
            ];
        }

        $cells = is_array($result['battery_cells'] ?? null) ? $result['battery_cells'] : null;
        $parsed = YarboTelemetry::parseForPanel($raw, $cells, $this->projectRoot);
        $config = @include $this->projectRoot . '/config.php';
        $serial = is_array($config) ? (string) ($config['serial'] ?? '') : '';
        $parsed = (new YarboRobotName($this->projectRoot))->apply($parsed, $serial);

        $wifiEnvelope = is_array($result['wifi'] ?? null)
            ? ['data' => $result['wifi'], 'topic' => 'get_connect_wifi_name']
            : null;
        $wifi = YarboWifi::parse($wifiEnvelope);
        $batteryDiag = is_array($parsed['battery_diagnostics'] ?? null) ? $parsed['battery_diagnostics'] : [];
        $rtkDiag = is_array($parsed['rtk_diagnostics'] ?? null) ? $parsed['rtk_diagnostics'] : [];
        $network = is_array($parsed['network'] ?? null) ? $parsed['network'] : [];
        $planStatus = is_array($parsed['plan_status'] ?? null) ? $parsed['plan_status'] : [];
        $errorCode = $parsed['error_code'] ?? 0;
        $powerFault = isset($parsed['power_fault']) ? (int) $parsed['power_fault'] : 0;
        $errorLabel = (string) $errorCode;
        if ($powerFault > 0) {
            $errorLabel .= ' (power ' . $powerFault . ')';
        }
        $planName = trim((string) ($planStatus['plan_name'] ?? ''));
        $planActivity = 'idle';
        if (!empty($parsed['plan_running'])) {
            $planActivity = $planName !== '' ? 'running: ' . $planName : 'running';
        } elseif (!empty($parsed['planning_paused'])) {
            $planActivity = 'paused';
        } elseif (!empty($parsed['returning_to_dock'])) {
            $planActivity = 'docking';
        }

        return [
            'ok' => true,
            'battery' => $parsed['battery'] ?? null,
            'charging_label' => $parsed['charging_label'] ?? 'No',
            'state' => $parsed['state'] ?? 'idle',
            'head_type_name' => $parsed['head_type_name'] ?? 'Unknown',
            'robot_name' => $parsed['robot_name'] ?? null,
            'error_code' => $errorCode,
            'error_label' => $errorLabel,
            'heading' => $parsed['heading'] ?? null,
            'rain_label' => self::formatRainLabel($parsed),
            'connection_type' => self::clip((string) ($parsed['connection_type'] ?? ''), 28) ?: '—',
            'connection_status' => self::clip((string) ($parsed['connection_status'] ?? ''), 28) ?: '—',
            'wifi_network' => self::formatWifiNetwork($wifi),
            'wifi_signal' => self::formatWifiSignal($wifi),
            'wifi_security' => self::formatWifiSecurity($wifi),
            'battery_temp' => self::formatBatteryTemp($batteryDiag),
            'wireless_charge' => self::formatWirelessCharge($batteryDiag),
            'rtk_status' => self::formatRtkStatus($rtkDiag),
            'rtcm_age' => isset($network['rtcm_age']) ? self::clip((string) $network['rtcm_age'], 20) : '—',
            'route_priority' => self::formatRoutePriority($network['route_priority'] ?? null),
            'rain_sensor' => self::formatRainSensor($parsed),
            'net_module' => self::formatNetModule($network['net_module_status'] ?? null),
            'plan_activity' => self::clip($planActivity, 40),
            'hold_controller' => (bool) ($result['hold_controller'] ?? false),
            'firmware_latest' => self::FIRMWARE_VERSION,
            'updated_at' => $parsed['updated_at'] ?? gmdate('c'),
        ];
    }

    /**
     * Named work plans for PaperMono. Cached so status polling does not hit read_all_plan.
     *
     * @return array<string, mixed>
     */
    public function compactPlans(bool $forceRefresh = false): array
    {
        $cachePath = $this->projectRoot . '/data/papermono-plans.json';
        if (!$forceRefresh && is_file($cachePath)) {
            $raw = file_get_contents($cachePath);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            $at = is_array($cached) ? strtotime((string) ($cached['updated_at'] ?? '')) : false;
            if (is_array($cached) && $at !== false && (time() - $at) < self::PLANS_CACHE_TTL_S) {
                $cached['ok'] = true;
                $cached['cached'] = true;
                $cached['firmware_latest'] = self::FIRMWARE_VERSION;

                return $cached;
            }
        }

        $fetched = $this->fetchPlans();
        $plans = [];
        foreach ($fetched['plans'] as $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $id = $plan['id'] ?? null;
            $name = trim((string) ($plan['name'] ?? ''));
            if ($id === null || $name === '') {
                continue;
            }
            $plans[] = [
                'id' => is_numeric($id) ? (int) $id : (string) $id,
                'name' => self::clip($name, 28),
            ];
            if (count($plans) >= 20) {
                break;
            }
        }

        $payload = [
            'ok' => true,
            'plans' => $plans,
            'count' => count($plans),
            'responded' => (bool) ($fetched['responded'] ?? false),
            'source' => (string) ($fetched['via'] ?? 'none'),
            'note' => $this->plansNote($fetched, $plans),
            'updated_at' => gmdate('c'),
            'firmware_latest' => self::FIRMWARE_VERSION,
        ];

        $dir = $this->projectRoot . '/data';
        if (is_dir($dir) || mkdir($dir, 0755, true)) {
            file_put_contents(
                $cachePath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                LOCK_EX
            );
        }

        $payload['cached'] = false;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function listSerialPorts(): array
    {
        return $this->runPython(['ports']);
    }

    /**
     * Install pyserial and esptool into the project .venv (create it if missing).
     *
     * @return array<string, mixed>
     */
    public function installUsbTools(): array
    {
        $venv = $this->ensureProjectVenv();
        if (!($venv['ok'] ?? false)) {
            return $venv;
        }

        return $this->runPython(['install_tools'], 120.0);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function flash(array $input): array
    {
        $port = trim((string) ($input['port'] ?? ''));
        $ssid = trim((string) ($input['wifi_ssid'] ?? ''));
        $password = (string) ($input['wifi_password'] ?? '');
        $panelUrl = rtrim(trim((string) ($input['panel_url'] ?? '')), '/');
        $name = trim((string) ($input['name'] ?? 'PaperMono'));

        if ($port === '') {
            return ['ok' => false, 'error' => 'Select the USB serial port for the PaperMono.'];
        }
        if ($ssid === '') {
            return ['ok' => false, 'error' => 'Wi-Fi name (SSID) is required.'];
        }
        if ($panelUrl === '' || !preg_match('#^https?://#i', $panelUrl)) {
            return ['ok' => false, 'error' => 'Panel URL must start with http:// or https://'];
        }

        $registered = $this->register(['name' => $name]);
        $result = $this->runPython([
            'flash',
            '--port', $port,
            '--ssid', $ssid,
            '--password', $password,
            '--panel-url', $panelUrl,
            '--token', (string) $registered['token'],
            '--name', $name,
        ], 180.0);
        $result['device'] = $registered;
        if (!($result['ok'] ?? false)) {
            $this->revoke((string) $registered['id']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function configureUsb(array $input): array
    {
        $port = trim((string) ($input['port'] ?? ''));
        $ssid = trim((string) ($input['wifi_ssid'] ?? ''));
        $password = (string) ($input['wifi_password'] ?? '');
        $panelUrl = rtrim(trim((string) ($input['panel_url'] ?? '')), '/');
        $token = trim((string) ($input['token'] ?? ''));
        $name = trim((string) ($input['name'] ?? 'PaperMono'));

        if ($port === '' || $ssid === '' || $panelUrl === '') {
            return ['ok' => false, 'error' => 'USB port, Wi-Fi name, and panel URL are required.'];
        }

        if ($token === '') {
            $device = $this->register(['name' => $name]);
            $token = (string) $device['token'];
        } else {
            $device = $this->findByToken($token);
            if ($device === null) {
                return ['ok' => false, 'error' => 'Unknown device token. Leave it blank to create a new PaperMono entry.'];
            }
            $device = $this->publicDevice($device, true);
        }

        $result = $this->runPython([
            'config',
            '--port', $port,
            '--ssid', $ssid,
            '--password', $password,
            '--panel-url', $panelUrl,
            '--token', $token,
            '--name', $name,
        ], 45.0);
        $result['device'] = $device;

        return $result;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runPython(array $args, float $timeout = 20.0): array
    {
        $script = $this->projectRoot . '/scripts/papermono_flash.py';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'scripts/papermono_flash.py is missing.'];
        }

        $cmd = array_merge([$this->pythonBin(), $script], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($escaped, $spec, $pipes, $this->projectRoot, [
            'PAPERMONO_ROOT' => $this->projectRoot,
        ]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Could not start the PaperMono flash helper.'];
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], (int) ceil($timeout));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $decoded = json_decode((string) $stdout, true);
        if (is_array($decoded)) {
            if (is_string($stderr) && trim($stderr) !== '') {
                $decoded['log'] = trim($stderr);
            }

            return $decoded;
        }

        return [
            'ok' => false,
            'error' => 'Flash helper failed (exit ' . $code . '). '
                . trim((string) ((is_string($stderr) && $stderr !== '') ? $stderr : $stdout)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureProjectVenv(): array
    {
        $venvPython = $this->projectRoot . '/.venv/bin/python';
        if (is_file($venvPython)) {
            return ['ok' => true];
        }

        $cmd = implode(' ', array_map('escapeshellarg', [
            'python3',
            '-m',
            'venv',
            $this->projectRoot . '/.venv',
        ]));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, $this->projectRoot);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Could not create a Python virtualenv for USB tools.'];
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 60);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || !is_file($venvPython)) {
            $detail = trim((string) ((is_string($stderr) && $stderr !== '') ? $stderr : $stdout));

            return [
                'ok' => false,
                'error' => 'Could not create .venv. Install Python 3 venv support, then try again.'
                    . ($detail !== '' ? ' ' . $detail : ''),
            ];
        }

        return ['ok' => true];
    }

    private function pythonBin(): string
    {
        $venv = $this->projectRoot . '/.venv/bin/python';
        if (is_file($venv)) {
            return $venv;
        }

        return 'python3';
    }

    /**
     * @return array{devices: list<array<string, mixed>>}
     */
    private function load(): array
    {
        $path = $this->storePath();
        if (!is_file($path)) {
            return ['devices' => []];
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !isset($decoded['devices']) || !is_array($decoded['devices'])) {
            return ['devices' => []];
        }

        return ['devices' => array_values($decoded['devices'])];
    }

    /**
     * @param array{devices: list<array<string, mixed>>} $store
     */
    private function save(array $store): void
    {
        $dir = dirname($this->storePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->storePath(),
            json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function publicDevice(array $device, bool $includeToken = false): array
    {
        $row = [
            'id' => (string) ($device['id'] ?? ''),
            'name' => (string) ($device['name'] ?? 'PaperMono'),
            'created_at' => $device['created_at'] ?? null,
            'last_seen_at' => $device['last_seen_at'] ?? null,
            'fw_reported' => $device['fw_reported'] ?? null,
        ];
        if ($includeToken) {
            $row['token'] = (string) ($device['token'] ?? '');
        }

        return $row;
    }

    /**
     * @return array{plans: list<array<string, mixed>>, responded: bool, via: string, error?: string}
     */
    private function fetchPlans(): array
    {
        $empty = ['plans' => [], 'responded' => false, 'via' => 'none'];
        $config = @include $this->projectRoot . '/config.php';
        if (!is_array($config)) {
            return $empty;
        }
        $serial = (string) ($config['serial'] ?? '');
        $cloudSettings = new YarboCloudSettings($this->projectRoot . '/data');
        $cloudConfig = $cloudSettings->load();
        $cloud = new YarboCloud($cloudSettings, $this->projectRoot);

        // Cloud first: PaperMono must not open a competing MQTT session while the agent runs.
        if ($cloudConfig['enabled']) {
            $cloudResult = $this->fetchPlansCloud($cloud, $serial);
            if ($cloudResult['responded'] && $cloudResult['plans'] !== []) {
                return $cloudResult;
            }
        }

        try {
            $client = new YarboMqtt(
                (string) ($config['broker_host'] ?? ''),
                (int) ($config['broker_port'] ?? 1883),
                $serial,
            );
            $client->connect();
            $response = $client->requestDataFeedback('read_all_plan', [], 8.0, false);
            $client->disconnect();
            $local = [
                'plans' => YarboPlans::parseList($response),
                'responded' => $response !== null,
                'via' => 'local',
            ];
            if ($local['responded'] && $local['plans'] !== []) {
                return $local;
            }
            if ($cloudConfig['enabled']) {
                $cloudResult = $this->fetchPlansCloud($cloud, $serial);
                if ($cloudResult['responded'] && $cloudResult['plans'] !== []) {
                    return $cloudResult;
                }
            }

            return $local;
        } catch (\Throwable) {
            if ($cloudConfig['enabled']) {
                return $this->fetchPlansCloud($cloud, $serial);
            }

            return $empty;
        }
    }

    /**
     * @return array{plans: list<array<string, mixed>>, responded: bool, via: string, error?: string}
     */
    private function fetchPlansCloud(YarboCloud $cloud, string $serial): array
    {
        $response = $cloud->fetch('read_all_plan', $serial, 15.0);
        if ($response === null) {
            return ['plans' => [], 'responded' => false, 'via' => 'cloud', 'error' => 'Cloud not configured'];
        }
        if (($response['ok'] ?? true) === false) {
            return [
                'plans' => [],
                'responded' => false,
                'via' => 'cloud',
                'error' => (string) ($response['error'] ?? 'Cloud read failed'),
            ];
        }
        $envelope = is_array($response['data'] ?? null) ? $response['data'] : $response;

        return [
            'plans' => YarboPlans::parseList($envelope),
            'responded' => $envelope !== null,
            'via' => 'cloud',
        ];
    }

    /**
     * @param array{plans?: list<mixed>, responded?: bool, via?: string, error?: string} $fetched
     * @param list<array<string, mixed>> $plans
     */
    private function plansNote(array $fetched, array $plans): string
    {
        if ($plans !== []) {
            return '';
        }
        if (isset($fetched['error']) && is_string($fetched['error']) && $fetched['error'] !== '') {
            return self::clip($fetched['error'], 48);
        }
        if (!($fetched['responded'] ?? false)) {
            return 'No plans — wake the robot or try again';
        }

        return 'Robot returned no saved plans';
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private static function formatRainLabel(array $parsed): string
    {
        $reading = $parsed['rain_sensor_data'] ?? null;
        $detected = (bool) ($parsed['rain_detected'] ?? false);
        $num = is_numeric($reading) ? (string) (0 + $reading) : null;
        if ($detected) {
            return $num !== null ? 'Wet ' . $num : 'Wet';
        }
        if ($num !== null) {
            return 'Dry ' . $num;
        }

        return '—';
    }

    /**
     * @param array<string, mixed> $wifi
     */
    private static function formatWifiNetwork(array $wifi): string
    {
        if (!($wifi['available'] ?? false)) {
            return '—';
        }
        $name = (string) ($wifi['network_name'] ?? 'Unknown');

        return self::clip($name, 28);
    }

    /**
     * @param array<string, mixed> $wifi
     */
    private static function formatWifiSignal(array $wifi): string
    {
        if (!($wifi['available'] ?? false) || !isset($wifi['signal_percent'])) {
            return '—';
        }
        $pct = (int) $wifi['signal_percent'];
        $label = (string) ($wifi['signal_label'] ?? '');

        return self::clip($label !== '' ? $pct . '% (' . $label . ')' : $pct . '%', 28);
    }

    /**
     * @param array<string, mixed> $wifi
     */
    private static function formatWifiSecurity(array $wifi): string
    {
        if (!($wifi['available'] ?? false)) {
            return '—';
        }
        $security = trim((string) ($wifi['security'] ?? ''));

        return $security !== '' ? self::clip($security, 28) : '—';
    }

    /**
     * @param array<string, mixed> $diag
     */
    private static function formatBatteryTemp(array $diag): string
    {
        $temp = $diag['temperature_c'] ?? null;
        if (!is_numeric($temp)) {
            return '—';
        }
        $cells = is_array($diag['cells'] ?? null) ? $diag['cells'] : [];
        $n = 0;
        foreach ($cells as $cell) {
            if (is_array($cell) && isset($cell['temperature_c']) && is_numeric($cell['temperature_c'])) {
                $n++;
            }
        }
        $label = number_format((float) $temp, 1) . '°C';
        if ($n > 0) {
            $label .= ' · ' . $n . ' cells';
        }

        return self::clip($label, 28);
    }

    /**
     * @param array<string, mixed> $diag
     */
    private static function formatWirelessCharge(array $diag): string
    {
        $volts = $diag['wireless_charge_voltage'] ?? null;
        $amps = $diag['wireless_charge_current'] ?? null;
        if (!is_numeric($volts) && !is_numeric($amps)) {
            return '—';
        }
        if (is_numeric($volts) && is_numeric($amps)) {
            return number_format((float) $volts, 2) . 'V / ' . number_format((float) $amps, 2) . 'A';
        }
        if (is_numeric($volts)) {
            return number_format((float) $volts, 2) . 'V';
        }

        return number_format((float) $amps, 2) . 'A';
    }

    /**
     * @param array<string, mixed> $rtk
     */
    private static function formatRtkStatus(array $rtk): string
    {
        $status = $rtk['rtk_status'] ?? null;
        $fix = $rtk['fix_quality'] ?? null;
        if ($status === null && $fix === null) {
            return '—';
        }
        $label = $status !== null ? (string) $status : '';
        if ($fix !== null) {
            $label = trim($label . ' (fix ' . $fix . ')');
        }

        return self::clip($label !== '' ? $label : '—', 28);
    }

    private static function formatRoutePriority(mixed $value): string
    {
        if (!is_array($value)) {
            return '—';
        }
        $names = ['hg0' => 'HaLow', 'wlan0' => 'WiFi', 'wwan0' => '4G'];
        $best = null;
        $bestPri = null;
        foreach ($value as $iface => $priority) {
            if (!is_numeric($priority)) {
                continue;
            }
            $pri = (float) $priority;
            if ($pri < 0) {
                continue;
            }
            if ($bestPri === null || $pri < $bestPri) {
                $bestPri = $pri;
                $best = $names[(string) $iface] ?? (string) $iface;
            }
        }

        return $best !== null ? self::clip($best, 28) : '—';
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private static function formatRainSensor(array $parsed): string
    {
        $fields = is_array($parsed['rain_fields'] ?? null) ? $parsed['rain_fields'] : [];
        if ($fields !== []) {
            $parts = [];
            foreach ($fields as $key => $val) {
                $parts[] = $key . '=' . $val;
            }

            return self::clip(implode(', ', $parts), 28);
        }
        $reading = $parsed['rain_sensor_data'] ?? null;

        return is_numeric($reading) ? (string) (0 + $reading) : '—';
    }

    private static function formatNetModule(mixed $value): string
    {
        if (!is_array($value)) {
            return '—';
        }
        $statusRaw = isset($value['lte_status']) && is_numeric($value['lte_status'])
            ? (int) $value['lte_status']
            : null;
        $label = $statusRaw === 1 ? 'LTE connected' : ($statusRaw === 0 ? 'LTE down' : 'LTE unknown');

        return self::clip($label, 28);
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        return rtrim(substr($value, 0, $max - 1)) . '...';
    }
}
