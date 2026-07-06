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
     * Decode map/plan payload fields that may be an array, zlib bytes, or base64+zlib text.
     *
     * @return array<string, mixed>
     */
    public static function decodePayloadField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $attempts = [$value];
        $decoded64 = base64_decode($value, true);
        if ($decoded64 !== false) {
            $attempts[] = $decoded64;
        }

        foreach ($attempts as $attempt) {
            try {
                $result = self::decode($attempt);
                if ($result !== [] && !isset($result['_raw'])) {
                    return $result;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
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
