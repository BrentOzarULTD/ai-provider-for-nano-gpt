<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

use RuntimeException;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\NanoGptAiProvider\Account\NanoGptBalance;
use WordPress\NanoGptAiProvider\Account\NanoGptBalanceClient;

/**
 * WordPress settings and connector integration for Nano-GPT.
 *
 * @since 1.0.0
 */
class SettingsPage
{
    private const PAGE_SLUG = 'nanogpt-ai-provider';
    private const CONNECTOR_SETTING = 'connectors_ai_nanogpt_api_key';
    private const CACHE_TTL = 300;

    /** @var string Plugin basename used for the plugin action link. */
    private static string $pluginBasename = '';

    /**
     * Registers WordPress hooks.
     */
    public static function register(string $pluginFile): void
    {
        self::$pluginBasename = plugin_basename($pluginFile);

        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_post_nanogpt_refresh_balance', [self::class, 'refreshBalance']);
        add_action('wp_connectors_init', [self::class, 'customizeConnector']);
        add_filter(
            'plugin_action_links_' . self::$pluginBasename,
            [self::class, 'addPluginActionLink']
        );
    }

    /**
     * Adds Settings > Nano-GPT.
     */
    public static function addSettingsPage(): void
    {
        add_options_page(
            __('Nano-GPT', 'ai-provider-for-nanogpt'),
            __('Nano-GPT', 'ai-provider-for-nanogpt'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Improves the automatically discovered WordPress 7.0 connector metadata.
     *
     * @param object $registry WP_Connector_Registry instance.
     */
    public static function customizeConnector($registry): void
    {
        if (
            !method_exists($registry, 'is_registered') ||
            !method_exists($registry, 'unregister') ||
            !method_exists($registry, 'register') ||
            !$registry->is_registered('nanogpt')
        ) {
            return;
        }

        $connector = $registry->unregister('nanogpt');
        if (!is_array($connector)) {
            return;
        }

        $connector['description'] = __(
            'Text and image generation through hundreds of AI models.',
            'ai-provider-for-nanogpt'
        );
        $plugin = isset($connector['plugin']) && is_array($connector['plugin'])
            ? $connector['plugin']
            : [];
        $plugin['file'] = self::$pluginBasename;
        $connector['plugin'] = $plugin;
        $registry->register('nanogpt', $connector);
    }

    /**
     * Adds a Settings shortcut on the Plugins screen.
     *
     * @param list<string> $links Existing links.
     * @return list<string> Updated links.
     */
    public static function addPluginActionLink(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(self::settingsUrl()) . '">' .
                esc_html__('Settings', 'ai-provider-for-nanogpt') .
            '</a>'
        );

        return $links;
    }

    /**
     * Clears the cached balance after a nonce-protected admin request.
     */
    public static function refreshBalance(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'ai-provider-for-nanogpt'));
        }

        check_admin_referer('nanogpt_refresh_balance');
        $apiKey = self::apiKey();
        if ($apiKey !== '') {
            delete_transient(self::balanceCacheKey($apiKey));
        }

        wp_safe_redirect(self::settingsUrl());
        exit;
    }

    /**
     * Renders the balance settings screen.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $apiKey = self::apiKey();
        $balance = null;
        $error = '';
        if ($apiKey !== '') {
            try {
                $balance = self::cachedBalance($apiKey);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Nano-GPT', 'ai-provider-for-nanogpt'); ?></h1>
            <p>
                <?php
                echo esc_html__(
                    'Use Nano-GPT models through the WordPress AI Client.',
                    'ai-provider-for-nanogpt'
                );
                ?>
            </p>

            <h2><?php echo esc_html__('Connection', 'ai-provider-for-nanogpt'); ?></h2>
            <?php if ($apiKey === '') : ?>
                <div class="notice notice-warning inline"><p>
                    <?php echo esc_html__('No Nano-GPT API key is configured.', 'ai-provider-for-nanogpt'); ?>
                </p></div>
                <?php if (self::connectorsAvailable()) : ?>
                    <p><a
                        class="button button-primary"
                        href="<?php echo esc_url(admin_url('options-connectors.php')); ?>"
                    >
                        <?php echo esc_html__('Configure API key in Connectors', 'ai-provider-for-nanogpt'); ?>
                    </a></p>
                <?php else : ?>
                    <p>
                        <?php
                        echo esc_html__(
                            'Set NANOGPT_API_KEY as an environment variable or PHP constant.',
                            'ai-provider-for-nanogpt'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php echo esc_html__('API key configured', 'ai-provider-for-nanogpt'); ?>
                </p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Available balance', 'ai-provider-for-nanogpt'); ?></h2>
            <?php if ($balance instanceof NanoGptBalance) : ?>
                <table class="widefat striped" style="max-width: 520px">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('USD balance', 'ai-provider-for-nanogpt'); ?></th>
                            <td>
                                <strong>
                                    $<?php echo esc_html(self::formatBalance($balance->getUsdBalance(), 4)); ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php echo esc_html__('Nano (XNO) balance', 'ai-provider-for-nanogpt'); ?>
                            </th>
                            <td><?php echo esc_html(self::formatBalance($balance->getNanoBalance(), 6)); ?> XNO</td>
                        </tr>
                    </tbody>
                </table>
                <p class="description">
                    <?php echo esc_html__('Balance is cached for five minutes.', 'ai-provider-for-nanogpt'); ?>
                </p>
                <p><a class="button" href="<?php echo esc_url(self::refreshUrl()); ?>">
                    <?php echo esc_html__('Refresh balance', 'ai-provider-for-nanogpt'); ?>
                </a></p>
            <?php elseif ($error !== '') : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
                <p><a class="button" href="<?php echo esc_url(self::refreshUrl()); ?>">
                    <?php echo esc_html__('Try again', 'ai-provider-for-nanogpt'); ?>
                </a></p>
            <?php else : ?>
                <p>
                    <?php
                    echo esc_html__(
                        'Configure an API key to view the account balance.',
                        'ai-provider-for-nanogpt'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Model labels', 'ai-provider-for-nanogpt'); ?></h2>
            <p>
                <?php
                echo esc_html__(
                    'Model choices show billing, family, release month, and context size.',
                    'ai-provider-for-nanogpt'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Resolves the API key using WordPress's environment, constant, and database order.
     */
    private static function apiKey(): string
    {
        $environmentKey = getenv('NANOGPT_API_KEY');
        if (is_string($environmentKey) && $environmentKey !== '') {
            return $environmentKey;
        }
        if (defined('NANOGPT_API_KEY')) {
            $constantKey = constant('NANOGPT_API_KEY');
            if (is_string($constantKey) && $constantKey !== '') {
                return $constantKey;
            }
        }

        if (class_exists(AiClient::class)) {
            try {
                $authentication = AiClient::defaultRegistry()
                    ->getProviderRequestAuthentication('nanogpt');
                if ($authentication instanceof ApiKeyRequestAuthentication) {
                    return $authentication->getApiKey();
                }
            } catch (\Throwable $exception) {
                // Fall through to the connector option.
            }
        }

        $storedKey = get_option(self::CONNECTOR_SETTING, '');

        return is_string($storedKey) ? $storedKey : '';
    }

    private static function cachedBalance(string $apiKey): NanoGptBalance
    {
        $cacheKey = self::balanceCacheKey($apiKey);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return NanoGptBalanceClient::fromArray($cached);
        }

        $balance = (new NanoGptBalanceClient())->fetch($apiKey);
        set_transient(
            $cacheKey,
            [
                'usd_balance' => $balance->getUsdBalance(),
                'nano_balance' => $balance->getNanoBalance(),
            ],
            self::CACHE_TTL
        );

        return $balance;
    }

    private static function balanceCacheKey(string $apiKey): string
    {
        return 'nanogpt_balance_' . hash('sha256', $apiKey);
    }

    private static function settingsUrl(): string
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    private static function refreshUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=nanogpt_refresh_balance'),
            'nanogpt_refresh_balance'
        );
    }

    private static function connectorsAvailable(): bool
    {
        return function_exists('wp_get_connectors');
    }

    private static function formatBalance(string $balance, int $precision): string
    {
        return number_format((float) $balance, $precision, '.', ',');
    }
}
