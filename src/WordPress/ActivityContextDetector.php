<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

/**
 * Derives the WordPress component and request context behind an AI call.
 *
 * @since 2.0.0
 */
class ActivityContextDetector
{
    /**
     * @return array{
     *     source_type: string,
     *     source_name: string,
     *     source_detail: string,
     *     request_context: array<string, mixed>
     * }
     */
    public static function detect(): array
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Caller attribution is an advertised diagnostic feature.
        $source = self::sourceFromTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $context = self::requestContext();

        /**
         * Filters the detected activity source and request context.
         *
         * @param array<string, mixed> $source Detected source fields.
         */
        $filtered = apply_filters('nanogpt_activity_source_context', array_merge($source, [
            'request_context' => $context,
        ]));
        if (!is_array($filtered)) {
            $filtered = [];
        }

        $requestContext = isset($filtered['request_context']) && is_array($filtered['request_context'])
            ? self::stringKeyedArray($filtered['request_context'])
            : $context;

        return [
            'source_type' => self::stringValue($filtered['source_type'] ?? $source['source_type']),
            'source_name' => self::stringValue($filtered['source_name'] ?? $source['source_name']),
            'source_detail' => self::stringValue($filtered['source_detail'] ?? $source['source_detail']),
            'request_context' => $requestContext,
        ];
    }

    /**
     * @param list<array<string, mixed>> $trace Backtrace.
     * @return array{source_type: string, source_name: string, source_detail: string}
     */
    private static function sourceFromTrace(array $trace): array
    {
        $pluginRoot = dirname(__DIR__, 2);
        foreach ($trace as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            if ($file === '' || strpos($file, $pluginRoot) === 0 || self::isAiInfrastructure($file)) {
                continue;
            }
            $function = self::frameFunction($frame);
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $detail = $function . ($line > 0 ? ' at ' . basename($file) . ':' . $line : '');

            $plugin = self::pathComponent($file, self::stringConstant('WP_PLUGIN_DIR'));
            if ($plugin !== '') {
                return ['source_type' => 'plugin', 'source_name' => $plugin, 'source_detail' => $detail];
            }
            $muPlugin = self::pathComponent($file, self::stringConstant('WPMU_PLUGIN_DIR'));
            if ($muPlugin !== '') {
                return ['source_type' => 'mu-plugin', 'source_name' => $muPlugin, 'source_detail' => $detail];
            }
            $themeRoot = function_exists('get_theme_root') ? get_theme_root() : '';
            $theme = self::pathComponent($file, is_string($themeRoot) ? $themeRoot : '');
            if ($theme !== '') {
                return ['source_type' => 'theme', 'source_name' => $theme, 'source_detail' => $detail];
            }
            $wordpressRoot = self::stringConstant('ABSPATH');
            if ($wordpressRoot !== '' && strpos($file, $wordpressRoot) === 0) {
                return ['source_type' => 'core', 'source_name' => 'WordPress core', 'source_detail' => $detail];
            }

            return ['source_type' => 'application', 'source_name' => basename($file), 'source_detail' => $detail];
        }

        return ['source_type' => 'unknown', 'source_name' => 'Unknown', 'source_detail' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestContext(): array
    {
        $context = [];
        if (defined('WP_CLI') && WP_CLI) {
            $context['channel'] = 'wp-cli';
        } elseif (function_exists('wp_doing_cron') && wp_doing_cron()) {
            $context['channel'] = 'cron';
        } elseif (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $context['channel'] = 'ajax';
            // This read-only value describes the request; it does not authorize an action.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) {
                $context['action'] = sanitize_key(wp_unslash($_REQUEST['action']));
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        } elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $context['channel'] = 'rest';
        } elseif (function_exists('is_admin') && is_admin()) {
            $context['channel'] = 'admin';
        } else {
            $context['channel'] = 'front-end';
        }

        // This read-only server value describes the request; it does not authorize an action.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $requestUri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            $requestPath = wp_parse_url($requestUri, PHP_URL_PATH);
            if (is_string($requestPath)) {
                $context['request_path'] = sanitize_text_field($requestPath);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (isset($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])) {
            $hooks = array_values(array_filter($GLOBALS['wp_current_filter'], 'is_string'));
            $context['hooks'] = array_slice($hooks, -5);
        }
        if (function_exists('get_current_user_id')) {
            $context['user_id'] = get_current_user_id();
        }

        return $context;
    }

    private static function isAiInfrastructure(string $file): bool
    {
        return strpos($file, '/wp-includes/php-ai-client/') !== false
            || strpos($file, '/wp-includes/ai-client/') !== false
            || strpos($file, '/vendor/wordpress/php-ai-client/') !== false;
    }

    private static function pathComponent(string $file, string $root): string
    {
        if ($root === '' || strpos($file, rtrim($root, '/') . '/') !== 0) {
            return '';
        }
        $relative = substr($file, strlen(rtrim($root, '/')) + 1);
        $parts = explode('/', $relative);

        return $parts[0] ?? '';
    }

    /**
     * @param array<string, mixed> $frame Backtrace frame.
     */
    private static function frameFunction(array $frame): string
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';

        return $class . $type . $function;
    }

    /**
     * @param mixed $value Value.
     */
    private static function stringValue($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function stringConstant(string $name): string
    {
        if (!defined($name)) {
            return '';
        }
        $value = constant($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $value Array.
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
