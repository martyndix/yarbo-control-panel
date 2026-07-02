<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboWifi
{
    /**
     * Parse get_connect_wifi_name data_feedback response.
     *
     * @return array{
     *   available: bool,
     *   network_name: string|null,
     *   signal_percent: int|null,
     *   signal_label: string|null,
     *   security: string|null,
     *   ip: string|null,
     *   saved: bool|null
     * }
     */
    public static function parse(?array $response): array
    {
        $empty = [
            'available' => false,
            'network_name' => null,
            'signal_percent' => null,
            'signal_label' => null,
            'security' => null,
            'ip' => null,
            'saved' => null,
        ];

        if ($response === null) {
            return $empty;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        if ($data === []) {
            return $empty;
        }

        $name = trim((string) ($data['name'] ?? $data['ssid'] ?? $data['wifi_name'] ?? ''));
        $signalRaw = $data['signal'] ?? $data['rssi'] ?? $data['signal_strength'] ?? null;
        $signalPercent = self::normalizeSignalPercent($signalRaw);
        $security = trim((string) ($data['security'] ?? $data['encryption'] ?? ''));
        $ip = trim((string) ($data['ip'] ?? $data['wifi_ip'] ?? ''));
        $saved = isset($data['saved']) ? (bool) $data['saved'] : null;

        if ($name === '' && $signalPercent === null && $security === '' && $ip === '') {
            return $empty;
        }

        return [
            'available' => true,
            'network_name' => $name !== '' ? $name : null,
            'signal_percent' => $signalPercent,
            'signal_label' => self::signalQualityLabel($signalPercent),
            'security' => $security !== '' ? $security : null,
            'ip' => $ip !== '' ? $ip : null,
            'saved' => $saved,
        ];
    }

    private static function normalizeSignalPercent(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $num = (float) $value;

        // Yarbo often reports WiFi signal as 0–100 percentage.
        if ($num >= 0 && $num <= 100) {
            return (int) round($num);
        }

        // dBm style RSSI (typical WiFi range -90 to -30).
        if ($num < 0 && $num >= -100) {
            $clamped = max(-90.0, min(-30.0, $num));
            return (int) round((($clamped + 90) / 60) * 100);
        }

        return null;
    }

    private static function signalQualityLabel(?int $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        return match (true) {
            $percent >= 75 => 'Excellent',
            $percent >= 55 => 'Good',
            $percent >= 35 => 'Fair',
            default => 'Weak',
        };
    }
}
