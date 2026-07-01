<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboCodec
{
    /**
     * Encode a payload dict to zlib-compressed JSON bytes (wire format for all MQTT publishes).
     */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $compressed = gzcompress($json);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to zlib-compress payload');
        }

        return $compressed;
    }

    /**
     * Decode zlib-compressed JSON (or plain JSON for heart_beat) to an array.
     */
    public static function decode(string $data): array
    {
        $decoded = @gzuncompress($data);
        if ($decoded !== false) {
            return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        }

        $zlibDecoded = @zlib_decode($data);
        if ($zlibDecoded !== false) {
            return json_decode($zlibDecoded, true, 512, JSON_THROW_ON_ERROR);
        }

        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['_raw' => bin2hex(substr($data, 0, 512))];
        }
    }
}
