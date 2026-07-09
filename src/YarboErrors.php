<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboErrors
{
    public const MSG_TIMEOUT = 'Connection to the Yarbo robot timed out. Check the broker IP and serial number in Settings, '
        . 'and make sure the robot is powered on and on your home network.';

    public const MSG_TELEMETRY_TIMEOUT = 'Connected to the Yarbo MQTT broker but the robot did not respond. '
        . 'Check the serial number in Settings, wake the robot, and try again.';

    public const MSG_REFUSED = 'Cannot reach the Yarbo robot at the configured IP address (MQTT port 1883 refused the connection). '
        . 'Open Settings and check the broker IP matches your Yarbo base station, the robot is powered on, '
        . 'and this device is on the same home network.';

    public const MSG_NO_ROUTE = 'Cannot find the Yarbo robot on the network at the configured IP address. '
        . 'Verify the broker IP in Settings and that you are on the same Wi‑Fi or LAN.';

    public const MSG_UNREACHABLE = 'The network route to the Yarbo robot is unreachable. '
        . 'Check your Wi‑Fi connection and the broker IP in Settings.';

    public const MSG_MQTT_FAILED = 'Cannot connect to the Yarbo MQTT broker. Check the broker IP and port (1883) in Settings, '
        . 'and confirm the robot is powered on.';

    public static function friendly(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'connection refused') || str_contains($message, '[111]')) {
            return self::MSG_REFUSED;
        }

        if (str_contains($lower, 'no route to host') || str_contains($message, '[113]')) {
            return self::MSG_NO_ROUTE;
        }

        if (str_contains($lower, 'network is unreachable') || str_contains($message, '[101]')) {
            return self::MSG_UNREACHABLE;
        }

        if (str_contains($lower, 'no telemetry received') || str_contains($lower, 'telemetry_timeout')) {
            return self::MSG_TELEMETRY_TIMEOUT;
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return self::MSG_TIMEOUT;
        }

        if (str_contains($lower, 'establishing a connection to the mqtt broker failed')) {
            return self::MSG_MQTT_FAILED;
        }

        return $message;
    }
}
