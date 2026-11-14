<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Turn Yarbo lights on off-dock after sunset until sunrise; off on the pad or in daylight.
 */
final class YarboDockLights
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Pure desired-state helper for tests.
     */
    public static function desiredOn(bool $onChargePad, bool $isDark): bool
    {
        return !$onChargePad && $isDark;
    }

    /**
     * @return array{ok: bool, skipped?: bool, reason?: string, desired?: bool, applied?: bool, error?: string}
     */
    public function tick(): array
    {
        $settings = new YarboDockLightsSettings($this->projectRoot . '/data');
        $cfg = $settings->load();
        if (!$cfg['enabled']) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'disabled'];
        }

        $hub = new YarboHub($this->projectRoot);
        if (!$hub->enabled(YarboHub::MODULE_YARBO)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'yarbo_off'];
        }

        $home = new YarboHomeAutomations($this->projectRoot);
        $coords = $home->resolveCoords();
        if ($coords['latitude'] === null || $coords['longitude'] === null) {
            $coords = $home->findPanelCoords();
        }
        if ($coords['latitude'] === null || $coords['longitude'] === null) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no_coords'];
        }

        try {
            $tz = new \DateTimeZone($home->timezoneName());
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $now = time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $hm = $local->format('H:i');
        $sunset = $home->sunEventHm('sunset', $now, (float) $coords['latitude'], (float) $coords['longitude'], 0, $tz);
        $sunrise = $home->sunEventHm('sunrise', $now, (float) $coords['latitude'], (float) $coords['longitude'], 0, $tz);
        if ($sunset === null || $sunrise === null) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no_sun'];
        }
        $isDark = YarboHomeAutomations::hmInWindow($hm, $sunset, $sunrise);

        try {
            $agent = YarboMqttAgentClient::fromEnv();
            $result = $agent->telemetry(2.0, false, true, false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        if (empty($result['ok']) || $raw === []) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'offline'];
        }
        $rainSens = (new YarboRainSettings($this->projectRoot . '/data'))->sensitivity();
        $parsed = YarboTelemetry::parse($raw, null, $rainSens);
        $onPad = !empty($parsed['on_charge_pad']);
        if (array_key_exists('lights_on', $result) && is_bool($result['lights_on'])) {
            // Prefer agent hold flag when present.
        }
        $desired = self::desiredOn($onPad, $isDark);
        $last = $cfg['last_applied'];
        if ($last !== null && $last === $desired) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'unchanged', 'desired' => $desired];
        }

        try {
            $sent = $agent->lights($desired);
        } catch (\Throwable $e) {
            return ['ok' => false, 'desired' => $desired, 'error' => $e->getMessage()];
        }
        if (empty($sent['ok'])) {
            return [
                'ok' => false,
                'desired' => $desired,
                'error' => (string) ($sent['error'] ?? 'lights command failed'),
            ];
        }
        $settings->write([
            'enabled' => true,
            'last_applied' => $desired,
        ]);

        return ['ok' => true, 'applied' => true, 'desired' => $desired];
    }
}
