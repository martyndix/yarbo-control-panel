<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Paper companion devices: pairing tokens, compact status, USB flash.
 * Hardware: M5Stack PaperMono SKU C153, or PaperColor (Spectra 6, no touch).
 */
final class YarboPaperDevice
{
    public const KIND_MONO = 'papermono';
    public const KIND_COLOR = 'papercolor';
    public const FIRMWARE_VERSION = '0.1.37';
    public const FIRMWARE_VERSION_COLOR = '0.2.14-colour';
    public const OTA_ONLINE_MONO_S = 90;
    public const OTA_ONLINE_COLOR_S = 180;
    public const MESSAGE_MAX = 50;
    public const MESSAGE_CHARS = 180;
    public const LOGO_MAX_EDGE = 160;
    public const LOGO_MAX_UPLOAD_BYTES = 2097152;
    private const PLANS_CACHE_TTL_S = 300;
    public const FIRMWARE_RELATIVE = 'firmware/papermono/.pio/build/papermono/firmware.bin';
    public const FIRMWARE_RELATIVE_COLOR = 'firmware/papercolor/.pio/build/papercolor/firmware.bin';
    public const FIRMWARE_FACTORY_RELATIVE = 'firmware/papermono/.pio/build/papermono/firmware-factory.bin';
    public const FIRMWARE_FACTORY_RELATIVE_COLOR = 'firmware/papercolor/.pio/build/papercolor/firmware-factory.bin';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function storePath(): string
    {
        return $this->projectRoot . '/data/papermono-devices.json';
    }

    public function firmwarePath(?string $kind = null): string
    {
        $relative = $this->normalizeKind($kind) === self::KIND_COLOR
            ? self::FIRMWARE_RELATIVE_COLOR
            : self::FIRMWARE_RELATIVE;

        return $this->projectRoot . '/' . $relative;
    }

    public function firmwareFlashPath(?string $kind = null): string
    {
        $relative = $this->normalizeKind($kind) === self::KIND_COLOR
            ? self::FIRMWARE_FACTORY_RELATIVE_COLOR
            : self::FIRMWARE_FACTORY_RELATIVE;

        return $this->projectRoot . '/' . $relative;
    }

    public function firmwareAvailable(?string $kind = null): bool
    {
        $path = $this->firmwarePath($kind);

        return is_file($path) && filesize($path) > 1024;
    }

    public function firmwareFlashAvailable(?string $kind = null): bool
    {
        $path = $this->firmwareFlashPath($kind);

        return is_file($path) && filesize($path) > 1024;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'beta' => false,
            'firmware_version' => self::FIRMWARE_VERSION,
            'firmware_built' => $this->firmwareAvailable(self::KIND_MONO),
            'firmware_path' => self::FIRMWARE_RELATIVE,
            'firmware' => [
                self::KIND_MONO => [
                    'label' => 'PaperMono',
                    'version' => self::FIRMWARE_VERSION,
                    'built' => $this->firmwareAvailable(self::KIND_MONO),
                    'needs_build' => $this->firmwareNeedsBuild(self::KIND_MONO),
                    'path' => self::FIRMWARE_RELATIVE,
                    'pio' => 'pio run -d firmware/papermono',
                ],
                self::KIND_COLOR => [
                    'label' => 'Paper Colour',
                    'version' => self::FIRMWARE_VERSION_COLOR,
                    'built' => $this->firmwareAvailable(self::KIND_COLOR),
                    'needs_build' => $this->firmwareNeedsBuild(self::KIND_COLOR),
                    'path' => self::FIRMWARE_RELATIVE_COLOR,
                    'pio' => 'pio run -e papercolor -d firmware/papercolor',
                ],
            ],
            'devices' => $this->publicDevices(),
            'prefs' => $this->publicPrefs(),
        ] + $this->logoPublicView();
    }

    public function logoPath(): string
    {
        return $this->projectRoot . '/data/paper-logo.png';
    }

    /**
     * @return array{logo_hash: string, logo_set: bool, logo_url: ?string}
     */
    public function logoPublicView(): array
    {
        $hash = $this->logoHash();

        return [
            'logo_hash' => $hash,
            'logo_set' => $hash !== '',
            'logo_url' => $hash !== '' ? '/api/device.php?action=logo&v=' . substr($hash, 0, 16) : null,
        ];
    }

    public function logoHash(): string
    {
        $path = $this->logoPath();
        if (!is_file($path) || filesize($path) < 8) {
            return '';
        }
        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : '';
    }

    /**
     * @param array<string, mixed> $file $_FILES['logo']
     * @return array<string, mixed>
     */
    public function saveUploadedLogo(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Choose a PNG or JPEG image (max 2 MB).'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Could not read the uploaded image.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::LOGO_MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'error' => 'Image is larger than 2 MB.'];
        }
        $raw = file_get_contents($tmp);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'Could not read the uploaded image.'];
        }
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create the data folder.'];
        }
        $saved = $this->writeLogoPng($raw);
        if ($saved !== null) {
            return ['ok' => false, 'error' => $saved];
        }

        return ['ok' => true, 'message' => 'Logo saved. The tablet picks it up on the next poll.'] + $this->logoPublicView();
    }

    /**
     * @return array<string, mixed>
     */
    public function clearLogo(): array
    {
        $path = $this->logoPath();
        if (is_file($path)) {
            @unlink($path);
        }

        return ['ok' => true, 'message' => 'Logo removed.'] + $this->logoPublicView();
    }

    /**
     * @return ?string error
     */
    private function writeLogoPng(string $raw): ?string
    {
        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($raw);
            if ($src === false) {
                return 'Use a PNG or JPEG image.';
            }
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                imagedestroy($src);

                return 'That image has no size.';
            }
            $scale = min(1.0, self::LOGO_MAX_EDGE / max($sw, $sh));
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));
            $box = self::LOGO_MAX_EDGE;
            $dst = imagecreatetruecolor($box, $box);
            if ($dst === false) {
                imagedestroy($src);

                return 'Could not resize the logo.';
            }
            imagealphablending($dst, true);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $box, $box, $white);
            $ox = (int) floor(($box - $dw) / 2);
            $oy = (int) floor(($box - $dh) / 2);
            imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);
            imagesavealpha($dst, false);
            $ok = imagepng($dst, $this->logoPath(), 6);
            imagedestroy($dst);

            return $ok ? null : 'Could not save the logo.';
        }
        if (!str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            return 'This PHP has no GD. Upload a PNG (max 200 KB).';
        }
        if (strlen($raw) > 204800) {
            return 'This PHP has no GD. Upload a PNG of 200 KB or less.';
        }

        return file_put_contents($this->logoPath(), $raw) !== false ? null : 'Could not save the logo.';
    }

    public function prepareLogoForDevice(): void
    {
        $path = $this->logoPath();
        if (!is_file($path) || filesize($path) < 8) {
            return;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $this->writeLogoPng($raw);
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
        $kind = $this->normalizeKind($input['kind'] ?? $input['hardware'] ?? null);
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $kind === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';
        }
        $device = [
            'id' => bin2hex(random_bytes(4)),
            'name' => $name,
            'kind' => $kind,
            'token' => bin2hex(random_bytes(16)),
            'created_at' => gmdate('c'),
            'last_seen_at' => null,
            'fw_reported' => null,
            'ota_pending' => false,
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

    public function rename(string $id, string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 40) {
            return null;
        }
        $store = $this->load();
        foreach ($store['devices'] as &$device) {
            if (!is_array($device) || (string) ($device['id'] ?? '') !== $id) {
                continue;
            }
            $device['name'] = $name;
            $this->save($store);

            return $this->publicDevice($device);
        }
        unset($device);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOta(string $id): array
    {
        $store = $this->load();
        foreach ($store['devices'] as &$device) {
            if (!is_array($device) || (string) ($device['id'] ?? '') !== $id) {
                continue;
            }
            $kind = $this->deviceKind($device);
            $latest = $this->firmwareVersionForKind($kind);
            $label = (string) ($device['name'] ?? $this->kindLabel($kind));
            if (!$this->firmwareAvailable($kind)) {
                return [
                    'ok' => false,
                    'error' => 'Build firmware for ' . $this->kindLabel($kind) . ' first.',
                ];
            }
            if (!$this->deviceIsOnline($device)) {
                return [
                    'ok' => false,
                    'error' => $label . ' is not online. Wait until it polls, then try again.',
                ];
            }
            $reported = (string) ($device['fw_reported'] ?? '');
            if ($reported !== '' && $reported === $latest) {
                return [
                    'ok' => false,
                    'error' => $label . ' is already on ' . $latest . '.',
                ];
            }
            $device['ota_pending'] = true;
            $device['ota_requested_at'] = gmdate('c');
            $device['ota_served_at'] = null;
            $this->save($store);

            return [
                'ok' => true,
                'device' => $this->publicDevice($device),
                'message' => 'Update queued for ' . $label . '. It starts on the next poll (beep and green LED on PaperMono).',
            ];
        }
        unset($device);

        return ['ok' => false, 'error' => 'Device not found'];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOtaAll(): array
    {
        $queued = [];
        $errors = [];
        foreach ($this->load()['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $id = (string) ($device['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $public = $this->publicDevice($device);
            if (empty($public['ota_available']) || empty($public['online'])) {
                continue;
            }
            $result = $this->requestOta($id);
            if ($result['ok'] ?? false) {
                $queued[] = $public['name'] ?? $id;
            } else {
                $errors[] = (string) ($result['error'] ?? 'Could not queue an update');
            }
        }
        if ($queued === []) {
            return [
                'ok' => false,
                'error' => $errors[0] ?? 'No online tablets need a firmware update.',
            ];
        }

        return [
            'ok' => true,
            'queued' => $queued,
            'errors' => $errors,
            'message' => 'Update queued for ' . implode(', ', $queued) . '.',
        ];
    }

    /**
     * @param array<string, mixed> $device
     */
    public function deviceIsOnline(array $device): bool
    {
        $seen = (string) ($device['last_seen_at'] ?? '');
        if ($seen === '') {
            return false;
        }
        $ts = strtotime($seen);
        if ($ts === false) {
            return false;
        }
        $kind = $this->deviceKind($device);
        $window = $kind === self::KIND_COLOR ? self::OTA_ONLINE_COLOR_S : self::OTA_ONLINE_MONO_S;

        return (time() - $ts) <= $window;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function savePrefs(array $input): array
    {
        $store = $this->load();
        $store['prefs'] = $this->normalizePrefs($input + $store['prefs']);
        $this->save($store);

        return ['ok' => true, 'prefs' => $this->publicPrefs($store['prefs'])];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPrefs(?array $prefs = null): array
    {
        return $this->normalizePrefs($prefs ?? $this->load()['prefs']);
    }

    /**
     * @param array<string, mixed> $fromDevice
     * @return array<string, mixed>
     */
    public function postPaperMessage(array $fromDevice, string $to, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Message is empty'];
        }
        if (strlen($text) > self::MESSAGE_CHARS) {
            $text = substr($text, 0, self::MESSAGE_CHARS);
        }
        $to = trim($to);
        if ($to === '' || $to === '*') {
            $to = '*';
        }
        $fromId = (string) ($fromDevice['id'] ?? '');
        $fromName = (string) ($fromDevice['name'] ?? 'PaperMono');
        $toName = 'ALL';
        if ($to !== '*') {
            $peer = $this->findById($to);
            if ($peer === null) {
                return ['ok' => false, 'error' => 'Unknown recipient'];
            }
            $toName = (string) ($peer['name'] ?? $to);
        }
        $store = $this->load();
        $message = [
            'id' => bin2hex(random_bytes(4)),
            'from' => $fromId,
            'from_name' => $fromName,
            'to' => $to,
            'to_name' => $toName,
            'text' => $text,
            'at' => gmdate('c'),
        ];
        $store['messages'][] = $message;
        if (count($store['messages']) > self::MESSAGE_MAX) {
            $store['messages'] = array_slice($store['messages'], -self::MESSAGE_MAX);
        }
        $this->save($store);

        return ['ok' => true, 'message' => $message];
    }

    public function findById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach ($this->load()['devices'] as $device) {
            if (is_array($device) && (string) ($device['id'] ?? '') === $id) {
                return $device;
            }
        }

        return null;
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

    public function markOtaServed(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $store = $this->load();
        foreach ($store['devices'] as &$device) {
            if (!is_array($device) || (string) ($device['id'] ?? '') !== $id) {
                continue;
            }
            $device['ota_served_at'] = gmdate('c');
            $this->save($store);
            unset($device);

            return;
        }
        unset($device);
    }

    /**
     * @param array<string, mixed> $device
     */
    private function shouldDropOtaPending(array $device): bool
    {
        if (empty($device['ota_pending'])) {
            return false;
        }
        $reported = (string) ($device['fw_reported'] ?? '');
        $latest = $this->firmwareVersionForKind($this->deviceKind($device));
        if ($reported !== '' && $reported === $latest) {
            return true;
        }
        if (!empty($device['ota_served_at'])) {
            return true;
        }
        $requestedAt = strtotime((string) ($device['ota_requested_at'] ?? '')) ?: 0;

        return $requestedAt > 0 && (time() - $requestedAt) >= 180;
    }

    /**
     * Settings UI and compact ota_pending: keep showing Updating until the
     * tablet has polled after a binary was served, or it is already on latest.
     *
     * @param array<string, mixed>|null $device
     */
    private function otaPendingActive(?array $device): bool
    {
        if (!is_array($device) || empty($device['ota_pending'])) {
            return false;
        }
        $reported = (string) ($device['fw_reported'] ?? '');
        $latest = $this->firmwareVersionForKind($this->deviceKind($device));
        if ($reported !== '' && $reported === $latest) {
            return false;
        }
        $servedAt = strtotime((string) ($device['ota_served_at'] ?? '')) ?: 0;
        $seenAt = strtotime((string) ($device['last_seen_at'] ?? '')) ?: 0;
        if ($servedAt > 0 && $seenAt >= $servedAt) {
            return false;
        }

        return true;
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
                $inferred = $this->kindFromFirmware($fwReported);
                if ($inferred !== null) {
                    $device['kind'] = $inferred;
                }
            }
            if ($this->shouldDropOtaPending($device)) {
                $device['ota_pending'] = false;
                $device['ota_requested_at'] = null;
                $device['ota_served_at'] = null;
            }
        }
        unset($device);
        $this->save($store);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed>|null $forDevice
     * @return array<string, mixed>
     */
    public function compactStatus(?string $kind = null, ?array $forDevice = null): array
    {
        $latest = $this->firmwareVersionForKind($kind);
        $agent = YarboMqttAgentClient::fromEnv();
        $result = $agent->telemetry(4.0, false);
        $raw = $result['raw'] ?? null;
        if (!($result['ok'] ?? false) || !is_array($raw) || $raw === []) {
            $mqttError = trim((string) ($result['error'] ?? 'MQTT not connected'));
            if ($mqttError === '') {
                $mqttError = 'MQTT not connected';
            }

            return [
                'ok' => true,
                'yarbo_ok' => false,
                'connection_type' => 'MQTT',
                'connection_status' => self::clip($mqttError, 28),
                'firmware_latest' => $latest,
                'error_code' => 0,
            ] + $this->companionCompact(null, false, $forDevice);
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
            'yarbo_ok' => true,
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
            'firmware_latest' => $latest,
            'updated_at' => $parsed['updated_at'] ?? gmdate('c'),
        ] + $this->companionCompact($parsed, true, $forDevice);
    }

    /**
     * @param array<string, mixed>|null $parsed
     * @param array<string, mixed>|null $forDevice
     * @return array<string, mixed>
     */
    private function companionCompact(?array $parsed = null, bool $online = false, ?array $forDevice = null): array
    {
        $hub = new YarboHub($this->projectRoot);
        $pw = (new YarboPowerwall($this->projectRoot))->dashboardPayload();
        $ly = (new YarboLymow($this->projectRoot))->dashboardPayload();
        $vbObj = new YarboVestaboard($this->projectRoot);
        $vb = $vbObj->load();
        $yarboEnabled = $hub->enabled(YarboHub::MODULE_YARBO);
        $pwEnabled = $hub->enabled(YarboHub::MODULE_POWERWALL);
        $lyEnabled = $hub->enabled(YarboHub::MODULE_LYMOW);
        $homeEnabled = $hub->enabled(YarboHub::MODULE_HOME);
        $homeItems = [];
        if ($homeEnabled) {
            $tabletId = is_array($forDevice) ? (string) ($forDevice['id'] ?? '') : '';
            $homeItems = (new YarboHome($this->projectRoot))->paperItems($tabletId);
        }
        $errorCode = is_array($parsed) ? ($parsed['error_code'] ?? 0) : 0;
        $powerFault = is_array($parsed) ? (int) ($parsed['power_fault'] ?? 0) : 0;
        $lyWork = isset($ly['work_status']) ? (int) $ly['work_status'] : null;

        return [
            'hub' => $hub->publicView(),
            'vestaboard_enabled' => !empty($vb['enabled']),
            'vestaboard_live' => $hub->vestaboardLive(),
            'yarbo_enabled' => $yarboEnabled,
            'powerwall_enabled' => $pwEnabled,
            'lymow_enabled' => $lyEnabled,
            'home_enabled' => $homeEnabled,
            'home_items' => $homeItems,
            'powerwall_pct' => isset($pw['battery_percent']) ? (int) round((float) $pw['battery_percent']) : -1,
            'powerwall_solar' => (string) ($pw['solar_label'] ?? '—'),
            'powerwall_load' => (string) ($pw['load_label'] ?? '—'),
            'powerwall_ok' => !empty($pw['ok']) || !empty($pw['online']),
            'lymow_ok' => !empty($ly['ok']) || !empty($ly['online']),
            'lymow_battery' => isset($ly['battery']) ? (int) $ly['battery'] : -1,
            'lymow_state' => $this->lymowCompanionState($ly),
            'lymow_charging' => (string) ($ly['charging_label'] ?? '—'),
            'lymow_name' => (string) ($ly['page_name'] ?? ''),
            'yarbo_error' => $yarboEnabled && $online && ((int) $errorCode !== 0 || $powerFault > 0),
            'powerwall_error' => false,
            'lymow_error' => $lyEnabled && $lyWork === 7,
            'ota_pending' => $this->otaPendingActive($forDevice),
        ] + $this->logoPublicView()
            + $this->prefsCompact($forDevice)
            + $this->vestaboardCompact($vbObj, $vb, $parsed, $online)
            + $this->clockCompact($vbObj);
    }

    /**
     * @param array<string, mixed> $ly
     */
    private function lymowCompanionState(array $ly): string
    {
        $state = (string) ($ly['work_label'] ?? '—');
        $progress = (string) ($ly['mow_progress_label'] ?? '—');
        if ($progress !== '' && $progress !== '—') {
            return trim($state . ' ' . $progress);
        }

        return $state !== '' ? $state : '—';
    }

    /**
     * @param array<string, mixed>|null $forDevice
     * @return array<string, mixed>
     */
    private function prefsCompact(?array $forDevice): array
    {
        $store = $this->load();
        $prefs = $this->normalizePrefs($store['prefs']);
        $id = (string) ($forDevice['id'] ?? '');
        $name = (string) ($forDevice['name'] ?? '');
        $peers = [];
        foreach ($store['devices'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $peerId = (string) ($device['id'] ?? '');
            if ($peerId === '' || $peerId === $id) {
                continue;
            }
            $peers[] = [
                'id' => $peerId,
                'name' => (string) ($device['name'] ?? $this->kindLabel($this->deviceKind($device))),
                'kind' => $this->deviceKind($device),
            ];
        }
        $inbox = [];
        foreach ($store['messages'] as $message) {
            if (!is_array($message)) {
                continue;
            }
            $to = (string) ($message['to'] ?? '*');
            if ($to !== '*' && $to !== $id) {
                continue;
            }
            if ((string) ($message['from'] ?? '') === $id) {
                continue;
            }
            $inbox[] = [
                'id' => (string) ($message['id'] ?? ''),
                'from' => (string) ($message['from'] ?? ''),
                'from_name' => (string) ($message['from_name'] ?? ''),
                'to' => $to,
                'to_name' => (string) ($message['to_name'] ?? ''),
                'text' => (string) ($message['text'] ?? ''),
                'at' => (string) ($message['at'] ?? ''),
            ];
        }
        $inbox = array_slice($inbox, -8);

        return $prefs + [
            'device_id' => $id,
            'device_name' => $name,
            'paper_peers' => $peers,
            'paper_messages' => $inbox,
            'radio_sync' => $this->radioSyncWord(),
        ];
    }

    /**
     * @param array<string, mixed> $vb
     * @param array<string, mixed>|null $parsed
     * @return array<string, mixed>
     */
    private function vestaboardCompact(YarboVestaboard $vbObj, array $vb, ?array $parsed, bool $online): array
    {
        $layout = $vbObj->layoutForLiveModule($parsed, $online);
        $codes = YarboVestaboard::normalizeLiveCodes($layout['codes'] ?? null);
        $lines = is_array($layout['lines'] ?? null) ? array_values($layout['lines']) : null;
        if ($this->vestaboardCodesBlank($codes)) {
            $codes = YarboVestaboard::normalizeLiveCodes($vb['last_live_codes'] ?? null);
            $lines = null;
        }
        if ($this->vestaboardCodesBlank($codes)) {
            $codes = YarboVestaboard::normalizeLiveCodes($vb['board_codes'] ?? null);
            $lines = null;
        }
        if ($codes === null) {
            $codes = [
                array_fill(0, 15, 0),
                array_fill(0, 15, 0),
                array_fill(0, 15, 0),
            ];
        }
        if (!is_array($lines) || count($lines) < 3) {
            $lines = YarboVestaboard::linesFromCodes($codes);
        }

        return [
            'vestaboard_codes' => $codes,
            'vestaboard_lines' => $lines,
            'vestaboard_hash' => hash('sha256', json_encode($codes)),
        ];
    }

    /**
     * @param list<list<int>>|null $codes
     */
    private function vestaboardCodesBlank(?array $codes): bool
    {
        if ($codes === null) {
            return true;
        }
        foreach ($codes as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $n) {
                if ((int) $n !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function clockCompact(YarboVestaboard $vbObj): array
    {
        $prefs = $this->normalizePrefs($this->load()['prefs']);
        $zoneName = YarboVestaboard::normalizeTimezone((string) ($prefs['timezone'] ?? ''));
        if ($zoneName === '') {
            return [];
        }
        try {
            $tz = new \DateTimeZone($zoneName);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
            $zoneName = 'UTC';
        }
        $now = new \DateTimeImmutable('now', $tz);

        return [
            'clock_tz' => $zoneName,
            'clock_local' => $now->format('H:i'),
            'clock_date' => $now->format('D j M'),
            'clock_epoch' => $now->getTimestamp(),
            'clock_offset' => $now->getOffset(),
        ];
    }

    public function radioSyncWord(): int
    {
        $config = @include $this->projectRoot . '/config.php';
        $serial = is_array($config) ? (string) ($config['serial'] ?? '') : '';
        $hash = hexdec(substr(hash('sha256', 'yarbo-paper-lora|' . $serial), 0, 2));
        $word = $hash & 0xFF;
        if ($word === 0 || $word === 0x12 || $word === 0x34) {
            $word = 0xA5;
        }

        return $word;
    }

    /**
     * Prefs copied into USB CFG so the tablet works before it can reach the Pi.
     *
     * @return array<string, mixed>
     */
    private function usbCompanionFields(): array
    {
        $prefs = $this->publicPrefs();
        $clock = $this->clockCompact(new YarboVestaboard($this->projectRoot));
        $vb = (new YarboVestaboard($this->projectRoot))->load();
        $out = [
            'brightness' => (int) $prefs['brightness'],
            'lock_screen' => (string) $prefs['lock_screen'],
            'unlock_page' => (string) $prefs['unlock_page'],
            'vestaboard_enabled' => !empty($vb['enabled']),
        ];
        if (isset($clock['clock_offset'])) {
            $out['clock_offset'] = (int) $clock['clock_offset'];
            $out['clock_tz'] = (string) ($clock['clock_tz'] ?? '');
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function usbCompanionCliArgs(): array
    {
        $fields = $this->usbCompanionFields();
        $args = [
            '--brightness',
            (string) $fields['brightness'],
            '--lock-screen',
            (string) $fields['lock_screen'],
            '--unlock-page',
            (string) $fields['unlock_page'],
            '--vestaboard',
            !empty($fields['vestaboard_enabled']) ? '1' : '0',
        ];
        if (isset($fields['clock_offset'])) {
            $args[] = '--clock-offset';
            $args[] = (string) $fields['clock_offset'];
            if (($fields['clock_tz'] ?? '') !== '') {
                $args[] = '--timezone';
                $args[] = (string) $fields['clock_tz'];
            }
        }

        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPrefs(): array
    {
        return [
            'lock_after_s' => 60,
            'light_off_s' => 15,
            'brightness' => 80,
            'lock_screen' => 'both',
            'unlock_page' => 'home',
            'timezone' => '',
            'alert_message' => true,
            'alert_yarbo' => true,
            'alert_lymow' => true,
            'alert_powerwall' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizePrefs(array $input): array
    {
        $defaults = $this->defaultPrefs();
        $lockScreen = strtolower(trim((string) ($input['lock_screen'] ?? $defaults['lock_screen'])));
        if (!in_array($lockScreen, ['logo', 'vestaboard', 'both'], true)) {
            $lockScreen = 'logo';
        }
        $unlockPage = strtolower(trim((string) ($input['unlock_page'] ?? $defaults['unlock_page'])));
        if (!in_array($unlockPage, [
            'home', 'status', 'health', 'plans', 'note', 'board',
            'powerwall', 'lymow', 'radio', 'device', 'house',
        ], true)) {
            $unlockPage = 'home';
        }
        $timezone = YarboVestaboard::normalizeTimezone((string) ($input['timezone'] ?? $defaults['timezone']));
        $bool = static function (mixed $value, bool $fallback): bool {
            if ($value === null) {
                return $fallback;
            }
            if (is_bool($value)) {
                return $value;
            }
            $s = strtolower(trim((string) $value));

            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        };

        return [
            'lock_after_s' => max(10, min(600, (int) ($input['lock_after_s'] ?? $defaults['lock_after_s']))),
            'light_off_s' => max(5, min(300, (int) ($input['light_off_s'] ?? $defaults['light_off_s']))),
            'brightness' => max(0, min(100, (int) ($input['brightness'] ?? $defaults['brightness']))),
            'lock_screen' => $lockScreen,
            'unlock_page' => $unlockPage,
            'timezone' => $timezone,
            'alert_message' => $bool($input['alert_message'] ?? null, $defaults['alert_message']),
            'alert_yarbo' => $bool($input['alert_yarbo'] ?? null, $defaults['alert_yarbo']),
            'alert_lymow' => $bool($input['alert_lymow'] ?? null, $defaults['alert_lymow']),
            'alert_powerwall' => $bool($input['alert_powerwall'] ?? null, $defaults['alert_powerwall']),
        ];
    }

    /**
     * Named work plans for PaperMono. Cached so status polling does not hit read_all_plan.
     *
     * @return array<string, mixed>
     */
    public function compactPlans(bool $forceRefresh = false, ?string $kind = null): array
    {
        $latest = $this->firmwareVersionForKind($kind);
        $cachePath = $this->projectRoot . '/data/papermono-plans.json';
        if (!$forceRefresh && is_file($cachePath)) {
            $raw = file_get_contents($cachePath);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            $at = is_array($cached) ? strtotime((string) ($cached['updated_at'] ?? '')) : false;
            if (is_array($cached) && $at !== false && (time() - $at) < self::PLANS_CACHE_TTL_S) {
                $cached['ok'] = true;
                $cached['cached'] = true;
                $cached['firmware_latest'] = $latest;

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
            'firmware_latest' => $latest,
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
     * Install PlatformIO if needed, then compile the selected tablet firmware.
     *
     * @return array<string, mixed>
     */
    public function buildFirmware(?string $kind, bool $force = true): array
    {
        $kind = $this->normalizeKind($kind);
        $label = $this->kindLabel($kind);
        $venv = $this->ensureProjectVenv();
        if (!($venv['ok'] ?? false)) {
            return $venv;
        }
        $pio = $this->ensurePlatformio();
        if (!($pio['ok'] ?? false)) {
            return $pio;
        }
        if (!$force && !$this->firmwareNeedsBuild($kind)) {
            return [
                'ok' => true,
                'skipped' => true,
                'built' => true,
                'kind' => $kind,
                'version' => $this->firmwareVersionForKind($kind),
                'message' => $label . ' firmware is already built.',
            ] + $this->dashboard();
        }

        $dir = $this->firmwareDir($kind);
        $cmd = [$this->pioBin(), 'run', '-d', $dir];
        if ($kind === self::KIND_COLOR) {
            $cmd = [$this->pioBin(), 'run', '-e', 'papercolor', '-d', $dir];
        }
        $result = $this->runProcess($cmd, 900.0, $this->pioEnv());
        $log = (string) ($result['log'] ?? '');
        if (!($result['ok'] ?? false) || !$this->firmwareAvailable($kind) || !$this->firmwareFlashAvailable($kind)) {
            $detail = trim((string) ($result['error'] ?? ''));
            if ($detail === '') {
                $detail = 'PlatformIO did not produce a USB factory image (firmware-factory.bin).';
            }

            return [
                'ok' => false,
                'kind' => $kind,
                'error' => 'Could not build ' . $label . ' firmware. ' . $detail,
                'log' => $this->tailLog($log, 1800),
            ];
        }

        return [
            'ok' => true,
            'kind' => $kind,
            'built' => true,
            'version' => $this->firmwareVersionForKind($kind),
            'message' => $label . ' firmware ' . $this->firmwareVersionForKind($kind) . ' is built. You can flash it over USB.',
            'log' => $this->tailLog($log, 800),
        ] + $this->dashboard();
    }

    public function firmwareNeedsBuild(?string $kind = null): bool
    {
        if (!$this->firmwareAvailable($kind) || !$this->firmwareFlashAvailable($kind)) {
            return true;
        }
        $binMtime = (int) filemtime($this->firmwarePath($kind));
        $dir = $this->firmwareDir($kind);
        $watch = [$dir . '/platformio.ini', $dir . '/src'];
        foreach ($watch as $path) {
            if (is_file($path) && filemtime($path) > $binMtime) {
                return true;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getMTime() > $binMtime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function flash(array $input): array
    {
        $kind = $this->normalizeKind($input['kind'] ?? null);
        $port = trim((string) ($input['port'] ?? ''));
        $ssid = trim((string) ($input['wifi_ssid'] ?? ''));
        $password = (string) ($input['wifi_password'] ?? '');
        $panelUrl = rtrim(trim((string) ($input['panel_url'] ?? '')), '/');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $kind === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';
        }
        $label = $kind === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';

        if ($port === '') {
            return ['ok' => false, 'error' => 'Select the USB serial port for the ' . $label . '.'];
        }
        if ($ssid === '') {
            return ['ok' => false, 'error' => 'Wi-Fi name (SSID) is required.'];
        }
        if ($panelUrl === '' || !preg_match('#^https?://#i', $panelUrl)) {
            return ['ok' => false, 'error' => 'Panel URL must start with http:// or https://'];
        }

        $builtNow = false;
        if ($this->firmwareNeedsBuild($kind)) {
            $build = $this->buildFirmware($kind, true);
            if (!($build['ok'] ?? false)) {
                return $build;
            }
            $builtNow = true;
        }

        $registered = $this->register(['name' => $name, 'kind' => $kind]);
        $result = $this->runPython(array_merge([
            'flash',
            '--port', $port,
            '--ssid', $ssid,
            '--password', $password,
            '--panel-url', $panelUrl,
            '--token', (string) $registered['token'],
            '--name', $name,
            '--kind', $kind,
        ], $this->usbCompanionCliArgs()), 180.0);
        $result['device'] = $registered;
        $result['built'] = $builtNow;
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
        $kind = $this->normalizeKind($input['kind'] ?? null);
        $port = trim((string) ($input['port'] ?? ''));
        $ssid = trim((string) ($input['wifi_ssid'] ?? ''));
        $password = (string) ($input['wifi_password'] ?? '');
        $panelUrl = rtrim(trim((string) ($input['panel_url'] ?? '')), '/');
        $token = trim((string) ($input['token'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $kind === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';
        }

        if ($port === '' || $ssid === '' || $panelUrl === '') {
            return ['ok' => false, 'error' => 'USB port, Wi-Fi name, and panel URL are required.'];
        }

        if ($token === '') {
            $device = $this->register(['name' => $name, 'kind' => $kind]);
            $token = (string) $device['token'];
        } else {
            $device = $this->findByToken($token);
            if ($device === null) {
                return ['ok' => false, 'error' => 'Unknown device token. Leave it blank to create a new companion entry.'];
            }
            $device = $this->publicDevice($device, true);
        }

        $result = $this->runPython(array_merge([
            'config',
            '--port', $port,
            '--ssid', $ssid,
            '--password', $password,
            '--panel-url', $panelUrl,
            '--token', $token,
            '--name', $name,
            '--kind', $kind,
        ], $this->usbCompanionCliArgs()), 90.0);
        $result['device'] = $device;

        return $result;
    }

    /**
     * Build a laptop USB setup zip (firmware + CFG JSON + flash.py). No USB on this host.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setupKit(array $input): array
    {
        $kind = $this->normalizeKind($input['kind'] ?? null);
        $ssid = trim((string) ($input['wifi_ssid'] ?? ''));
        $password = (string) ($input['wifi_password'] ?? '');
        $panelUrl = rtrim(trim((string) ($input['panel_url'] ?? '')), '/');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $kind === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';
        }
        $label = $this->kindLabel($kind);

        if ($ssid === '') {
            return ['ok' => false, 'error' => 'Wi-Fi name (SSID) is required.'];
        }
        if ($panelUrl === '' || !preg_match('#^https?://#i', $panelUrl)) {
            return ['ok' => false, 'error' => 'Panel URL must start with http:// or https://'];
        }
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:|/|$)#i', $panelUrl)) {
            return [
                'ok' => false,
                'error' => 'Panel URL must be how the tablet will reach this server at the site, not localhost.',
            ];
        }

        $builtNow = false;
        if ($this->firmwareNeedsBuild($kind)) {
            $build = $this->buildFirmware($kind, true);
            if (!($build['ok'] ?? false)) {
                return $build;
            }
            $builtNow = true;
        }

        if (!$this->firmwareAvailable($kind) || !$this->firmwareFlashAvailable($kind)) {
            return ['ok' => false, 'error' => $label . ' firmware is not built yet. Click Build firmware first.'];
        }

        $script = $this->projectRoot . '/scripts/paper_setup_kit.py';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'scripts/paper_setup_kit.py is missing.'];
        }

        $registered = $this->register(['name' => $name, 'kind' => $kind]);
        $stamp = bin2hex(random_bytes(4));
        $kitDir = sys_get_temp_dir() . '/yarbo-paper-kit-' . $stamp;
        if (!mkdir($kitDir, 0700, true) && !is_dir($kitDir)) {
            $this->revoke((string) $registered['id']);

            return ['ok' => false, 'error' => 'Could not create a temporary folder for the setup kit.'];
        }

        $config = [
            'ssid' => $ssid,
            'password' => $password,
            'panel_url' => $panelUrl,
            'token' => (string) $registered['token'],
            'name' => $name,
            'kind' => $kind,
            'version' => $this->firmwareVersionForKind($kind),
        ] + $this->usbCompanionFields();
        $packed = $this->writeSetupKitArchive($kitDir, $kind, $config, $script);
        if (!($packed['ok'] ?? false)) {
            $this->revoke((string) $registered['id']);
            $this->removeTree($kitDir);
            if (isset($packed['zip_path']) && is_string($packed['zip_path']) && is_file($packed['zip_path'])) {
                @unlink($packed['zip_path']);
            }

            return $packed;
        }

        $this->removeTree($kitDir);

        return [
            'ok' => true,
            'zip_path' => $packed['zip_path'],
            'filename' => $packed['filename'],
            'device' => $registered,
            'built' => $builtNow,
            'kind' => $kind,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function writeSetupKitArchive(string $kitDir, string $kind, array $config, string $script): array
    {
        $label = $this->kindLabel($kind);
        $firmwareSrc = $this->firmwareFlashPath($kind);
        $firmwareDst = $kitDir . '/firmware.bin';
        if (!@copy($firmwareSrc, $firmwareDst) || !is_file($firmwareDst)) {
            return ['ok' => false, 'error' => 'Could not copy the USB factory image into the setup kit. Click Build firmware first.'];
        }
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($configJson) || @file_put_contents($kitDir . '/config.json', $configJson . "\n") === false) {
            return ['ok' => false, 'error' => 'Could not write config.json for the setup kit.'];
        }
        if (!@copy($script, $kitDir . '/flash.py')) {
            return ['ok' => false, 'error' => 'Could not copy flash.py into the setup kit.'];
        }
        $readme = $this->setupKitReadme($kind, $config);
        if (@file_put_contents($kitDir . '/README.txt', $readme) === false) {
            return ['ok' => false, 'error' => 'Could not write README.txt for the setup kit.'];
        }

        $filename = $this->setupKitFilename($kind, (string) $config['name'], (string) $config['version']);
        $zipPath = $kitDir . '.zip';
        $files = [
            $kitDir . '/firmware.bin',
            $kitDir . '/config.json',
            $kitDir . '/flash.py',
            $kitDir . '/README.txt',
        ];
        $zipped = $this->writeZipArchive($zipPath, $files);
        if (!($zipped['ok'] ?? false)) {
            return $zipped + ['zip_path' => $zipPath];
        }

        return [
            'ok' => true,
            'zip_path' => $zipPath,
            'filename' => $filename,
            'kind' => $kind,
            'label' => $label,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setupKitReadme(string $kind, array $config): string
    {
        $label = $this->kindLabel($kind);
        $hold = $kind === self::KIND_COLOR ? 'about 3 seconds' : 'about 2 seconds';
        $name = (string) ($config['name'] ?? $label);
        $url = (string) ($config['panel_url'] ?? '');
        $ssid = (string) ($config['ssid'] ?? '');

        return $label . " USB setup kit\n"
            . "Tablet: {$name}\n"
            . "Site Wi-Fi: {$ssid}\n"
            . "Panel URL: {$url}\n"
            . "\n"
            . "This zip holds the firmware, the site Wi-Fi password, and the pairing token.\n"
            . "Keep it private. Do not email it.\n"
            . "\n"
            . "Unzip the whole folder (firmware.bin, config.json, flash.py, and this file must stay together).\n"
            . "\n"
            . "Mac\n"
            . "1. Install Python 3 if Terminal says python3 is missing (python.org or Homebrew).\n"
            . "2. cd into the unzipped folder, then: python3 flash.py\n"
            . "   The script creates .venv in that folder and installs esptool there.\n"
            . "   Do not use python3 -m pip install on Homebrew Python (externally-managed-environment).\n"
            . "   If you already tried that, from this folder:\n"
            . "     python3 -m venv .venv && .venv/bin/pip install esptool pyserial && .venv/bin/python flash.py\n"
            . "3. Plug the tablet in by USB-C. Hold power {$hold} for download mode (red LED blinks).\n"
            . "4. If more than one serial device: python3 flash.py --port /dev/cu.usbmodemXXXX\n"
            . "   List ports: python3 flash.py --list-ports\n"
            . "\n"
            . "Windows\n"
            . "1. Install Python from python.org and tick Add python.exe to PATH.\n"
            . "2. In the unzipped folder: py flash.py\n"
            . "   That also creates .venv and installs esptool. Or: py flash.py --port COM3\n"
            . "3. If no COM port appears, install Espressif USB JTAG/serial (ESP32-S3 native USB).\n"
            . "4. Plug USB-C, hold power {$hold} for download mode.\n"
            . "\n"
            . "Keep USB in until the setup screen clears, then ship the tablet to the site 2.4 GHz Wi-Fi.\n"
            . "Later firmware updates go over Wi-Fi from the panel (Settings → paired device Update).\n";
    }

    private function setupKitFilename(string $kind, string $name, string $version): string
    {
        $prefix = $kind === self::KIND_COLOR ? 'papercolor' : 'papermono';
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = $prefix;
        }
        $ver = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $version));
        $ver = trim($ver, '-');
        if ($ver === '') {
            $ver = 'kit';
        }

        return $prefix . '-setup-' . $slug . '-' . $ver . '.zip';
    }

    /**
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private function writeZipArchive(string $zipPath, array $files): array
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return ['ok' => false, 'error' => 'Could not create the setup kit zip.'];
            }
            foreach ($files as $path) {
                if (!is_file($path) || !$zip->addFile($path, basename($path))) {
                    $zip->close();
                    @unlink($zipPath);

                    return ['ok' => false, 'error' => 'Could not add ' . basename($path) . ' to the setup kit.'];
                }
            }
            if ($zip->close() !== true || !is_file($zipPath) || filesize($zipPath) < 1024) {
                @unlink($zipPath);

                return ['ok' => false, 'error' => 'Setup kit zip is empty.'];
            }

            return ['ok' => true];
        }

        $python = $this->pythonBin();
        $code = 'import os,sys,zipfile; z=sys.argv[1]; a=zipfile.ZipFile(z,"w",compression=zipfile.ZIP_DEFLATED);'
            . ' [a.write(p, os.path.basename(p)) for p in sys.argv[2:]]; a.close()';
        $cmd = array_merge([$python, '-c', $code, $zipPath], $files);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($escaped, $spec, $pipes, $this->projectRoot);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Could not start Python to zip the setup kit. Install php-zip.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0 || !is_file($zipPath) || filesize($zipPath) < 1024) {
            @unlink($zipPath);
            $detail = trim((string) ((is_string($stderr) && $stderr !== '') ? $stderr : $stdout));

            return [
                'ok' => false,
                'error' => 'Could not zip the setup kit. Install php-zip, or Python 3.'
                    . ($detail !== '' ? ' ' . $detail : ''),
            ];
        }

        return ['ok' => true];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            @rmdir($dir);

            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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

    public function firmwareDir(?string $kind = null): string
    {
        return $this->normalizeKind($kind) === self::KIND_COLOR
            ? $this->projectRoot . '/firmware/papercolor'
            : $this->projectRoot . '/firmware/papermono';
    }

    private function pioBin(): string
    {
        $venv = $this->projectRoot . '/.venv/bin/pio';
        if (is_file($venv)) {
            return $venv;
        }

        return 'pio';
    }

    /**
     * @return array<string, mixed>
     */
    private function ensurePlatformio(): array
    {
        if (is_file($this->projectRoot . '/.venv/bin/pio')) {
            return ['ok' => true];
        }
        $result = $this->runProcess(
            [$this->pythonBin(), '-m', 'pip', 'install', '--disable-pip-version-check', 'platformio'],
            180.0,
            $this->pioEnv()
        );
        if (!($result['ok'] ?? false) || !is_file($this->projectRoot . '/.venv/bin/pio')) {
            return [
                'ok' => false,
                'error' => 'Could not install PlatformIO into this panel’s Python environment. '
                    . trim((string) ($result['error'] ?? 'pip install platformio failed.')),
                'log' => $this->tailLog((string) ($result['log'] ?? ''), 1200),
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, string>
     */
    private function pioEnv(): array
    {
        $path = $this->projectRoot . '/.venv/bin';
        $existing = (string) getenv('PATH');
        if ($existing === '') {
            $existing = '/usr/local/bin:/usr/bin:/bin';
        }
        $path .= PATH_SEPARATOR . $existing;
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = $this->projectRoot;
        }

        return [
            'HOME' => $home,
            'PATH' => $path,
            'PLATFORMIO_CORE_DIR' => $this->projectRoot . '/.pio-core',
            'PLATFORMIO_NO_ANALYTICS' => '1',
        ];
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function runProcess(array $cmd, float $timeout, array $env = []): array
    {
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $fullEnv = $env + $_ENV;
        $proc = proc_open($escaped, $spec, $pipes, $this->projectRoot, $fullEnv !== [] ? $fullEnv : null);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Could not start ' . ($cmd[0] ?? 'command') . '.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (empty($status['running'])) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($proc, 15);
                usleep(200000);
                proc_terminate($proc, 9);
                break;
            }
            usleep(150000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $log = trim($stdout . "\n" . $stderr);
        if ($timedOut) {
            return [
                'ok' => false,
                'error' => 'Timed out after ' . (int) $timeout . 's.',
                'log' => $log,
            ];
        }
        if ($code !== 0) {
            return [
                'ok' => false,
                'error' => 'Command failed (exit ' . $code . ').',
                'log' => $log,
            ];
        }

        return ['ok' => true, 'log' => $log];
    }

    private function tailLog(string $log, int $limit = 1200): string
    {
        $log = trim($log);
        if (strlen($log) <= $limit) {
            return $log;
        }

        return substr($log, -$limit);
    }

    public function normalizeKind(mixed $kind): string
    {
        $value = strtolower(str_replace([' ', '_'], '', (string) $kind));
        if (in_array($value, ['papercolor', 'papercolour', 'color', 'colour'], true)) {
            return self::KIND_COLOR;
        }

        return self::KIND_MONO;
    }

    public function kindLabel(string $kind): string
    {
        return $this->normalizeKind($kind) === self::KIND_COLOR ? 'Paper Colour' : 'PaperMono';
    }

    public function firmwareVersionForKind(?string $kind): string
    {
        return $this->normalizeKind($kind) === self::KIND_COLOR
            ? self::FIRMWARE_VERSION_COLOR
            : self::FIRMWARE_VERSION;
    }

    /**
     * @param array<string, mixed> $device
     */
    public function deviceKind(array $device): string
    {
        $inferred = $this->kindFromFirmware((string) ($device['fw_reported'] ?? ''));
        if ($inferred !== null) {
            return $inferred;
        }
        if (isset($device['kind']) && (string) $device['kind'] !== '') {
            return $this->normalizeKind($device['kind']);
        }

        return self::KIND_MONO;
    }

    private function kindFromFirmware(string $fw): ?string
    {
        $fw = strtolower($fw);
        if ($fw === '') {
            return null;
        }
        if (str_contains($fw, 'color') || str_contains($fw, 'colour')) {
            return self::KIND_COLOR;
        }
        if (str_contains($fw, 'beta') || str_starts_with($fw, '0.1')) {
            return self::KIND_MONO;
        }

        return null;
    }

    /**
     * @return array{devices: list<array<string, mixed>>, prefs: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    private function load(): array
    {
        $empty = [
            'devices' => [],
            'prefs' => $this->defaultPrefs(),
            'messages' => [],
        ];
        $path = $this->storePath();
        if (!is_file($path)) {
            return $empty;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !isset($decoded['devices']) || !is_array($decoded['devices'])) {
            return $empty;
        }
        $messages = [];
        if (isset($decoded['messages']) && is_array($decoded['messages'])) {
            foreach ($decoded['messages'] as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }

        return [
            'devices' => array_values($decoded['devices']),
            'prefs' => $this->normalizePrefs(is_array($decoded['prefs'] ?? null) ? $decoded['prefs'] : []),
            'messages' => $messages,
        ];
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
        $kind = $this->deviceKind($device);
        $row = [
            'id' => (string) ($device['id'] ?? ''),
            'name' => (string) ($device['name'] ?? $this->kindLabel($kind)),
            'kind' => $kind,
            'kind_label' => $this->kindLabel($kind),
            'created_at' => $device['created_at'] ?? null,
            'last_seen_at' => $device['last_seen_at'] ?? null,
            'fw_reported' => $device['fw_reported'] ?? null,
            'firmware_latest' => $this->firmwareVersionForKind($kind),
            'firmware_built' => $this->firmwareAvailable($kind),
            'online' => $this->deviceIsOnline($device),
            'ota_pending' => $this->otaPendingActive($device),
            'ota_available' => $this->firmwareAvailable($kind)
                && (string) ($device['fw_reported'] ?? '') !== $this->firmwareVersionForKind($kind),
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
