<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * PaperMono companion devices (beta): pairing tokens, compact status, USB flash.
 * Target hardware: M5Stack PaperMono SKU C153 (https://docs.m5stack.com/en/core/PaperMono).
 */
final class YarboPaperDevice
{
    public const FIRMWARE_VERSION = '0.1.0-beta';
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
        $parsed = YarboTelemetry::parse($raw, $cells);

        return [
            'ok' => true,
            'battery' => $parsed['battery'] ?? null,
            'charging_label' => $parsed['charging_label'] ?? 'No',
            'state' => $parsed['state'] ?? 'idle',
            'head_type_name' => $parsed['head_type_name'] ?? 'Unknown',
            'error_code' => $parsed['error_code'] ?? 0,
            'heading' => $parsed['heading'] ?? null,
            'hold_controller' => (bool) ($result['hold_controller'] ?? false),
            'firmware_latest' => self::FIRMWARE_VERSION,
            'updated_at' => $parsed['updated_at'] ?? gmdate('c'),
        ];
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
}
