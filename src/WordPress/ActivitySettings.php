<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

/**
 * Activity logging preferences and safe defaults.
 *
 * @since 2.0.0
 */
class ActivitySettings
{
    public const ENABLED_OPTION = 'nanogpt_activity_logging_enabled';
    public const CONTENT_OPTION = 'nanogpt_activity_content_enabled';
    public const RETENTION_DAYS_OPTION = 'nanogpt_activity_retention_days';
    public const MAX_RECORDS_OPTION = 'nanogpt_activity_max_records';

    public static function isEnabled(): bool
    {
        return get_option(self::ENABLED_OPTION, '0') === '1';
    }

    public static function capturesContent(): bool
    {
        return get_option(self::CONTENT_OPTION, '0') === '1';
    }

    public static function retentionDays(): int
    {
        return self::boundedOption(self::RETENTION_DAYS_OPTION, 7, 1, 90);
    }

    public static function maxRecords(): int
    {
        return self::boundedOption(self::MAX_RECORDS_OPTION, 500, 10, 5000);
    }

    private static function boundedOption(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = get_option($name, (string) $default);
        $integer = is_numeric($value) ? (int) $value : $default;

        return max($minimum, min($maximum, $integer));
    }
}
