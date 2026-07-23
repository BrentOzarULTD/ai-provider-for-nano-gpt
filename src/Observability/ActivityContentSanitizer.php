<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Observability;

/**
 * Removes secrets and large inline media from activity-log payloads.
 *
 * @since 2.0.0
 */
class ActivityContentSanitizer
{
    private const MAX_JSON_BYTES = 200000;

    /**
     * @param mixed $value Raw request or response value.
     * @return mixed Sanitized value.
     */
    public static function sanitize($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : '';
                if (self::isSecretKey($keyString)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }
                if (in_array($keyString, ['b64_json', 'imagedataurl', 'imagedataurls'], true)) {
                    $sanitized[$key] = self::omittedMediaLabel($item);
                    continue;
                }
                $sanitized[$key] = self::sanitize($item);
            }

            return $sanitized;
        }

        if (is_string($value) && preg_match('/^data:[^;]+;base64,/i', $value)) {
            return self::omittedMediaLabel($value);
        }

        return $value;
    }

    /**
     * Encodes and caps a sanitized payload for database storage.
     *
     * @param mixed $value Payload.
     */
    public static function encode($value): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode(self::sanitize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : json_encode(self::sanitize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }
        if (strlen($json) <= self::MAX_JSON_BYTES) {
            return $json;
        }

        return substr($json, 0, self::MAX_JSON_BYTES)
            . "\n[truncated after " . self::MAX_JSON_BYTES . ' bytes]';
    }

    private static function isSecretKey(string $key): bool
    {
        if (
            in_array(
                $key,
                [
                    'authorization',
                    'api_key',
                    'apikey',
                    'cookie',
                    'password',
                    'set-cookie',
                    'secret',
                    'token',
                    'x-api-key',
                ],
                true
            )
        ) {
            return true;
        }

        return substr($key, -6) === '_token'
            || substr($key, -7) === '_secret'
            || substr($key, -9) === '_password';
    }

    /**
     * @param mixed $value Media value.
     */
    private static function omittedMediaLabel($value): string
    {
        if (is_string($value)) {
            return '[inline media omitted: ' . strlen($value) . ' bytes]';
        }
        if (is_array($value)) {
            return '[inline media omitted: ' . count($value) . ' items]';
        }

        return '[inline media omitted]';
    }
}
