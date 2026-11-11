<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboErrors
{
    public const MSG_TIMEOUT = 'Yarbo cloud login or MQTT timed out. Check internet access and the email/password under Settings → Yarbo.';

    public const MSG_TELEMETRY_TIMEOUT = 'Cloud MQTT connected but the robot did not reply. Check the serial in Settings → Yarbo, '
        . 'confirm the robot is online in the Yarbo app, then try again.';

    public const MSG_REFUSED = 'Cannot reach Yarbo over cloud MQTT. Open Settings → Yarbo and check the account email/password '
        . 'and serial, confirm the robot is online in the Yarbo app, then Save (restarts the cloud agent).';

    public const MSG_NO_ROUTE = 'Cannot reach Yarbo cloud from this Pi. Check internet on the panel host and try Test cloud connection.';

    public const MSG_UNREACHABLE = 'Network route to Yarbo cloud is unreachable. Check internet on the panel host.';

    public const MSG_MQTT_FAILED = 'Cannot connect to Yarbo cloud MQTT. Check Settings → Yarbo account login and serial, '
        . 'and confirm the robot shows online in the Yarbo app.';

    public const MSG_MQTT_CLOSED = 'The cloud MQTT connection dropped. Try Save again under Settings → Yarbo, or Test cloud connection.';

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

        if (
            str_contains($lower, 'no telemetry received')
            || str_contains($lower, 'telemetry_timeout')
            || str_contains($lower, 'no data_feedback response')
            || str_contains($lower, 'get_device_msg')
        ) {
            return self::MSG_TELEMETRY_TIMEOUT;
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return self::MSG_TIMEOUT;
        }

        if (str_contains($lower, 'establishing a connection to the mqtt broker failed')) {
            return self::MSG_MQTT_FAILED;
        }

        if (
            str_contains($lower, 'eof')
            || str_contains($message, '[66]')
            || str_contains($lower, 'transferring data over socket')
        ) {
            return self::MSG_MQTT_CLOSED;
        }

        return $message;
    }
}
