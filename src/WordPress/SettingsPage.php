<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

use RuntimeException;
use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\NanoGptAiProvider\Account\NanoGptBalance;
use WordPress\NanoGptAiProvider\Account\NanoGptBalanceClient;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

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

    /** @var string Absolute path to the plugin bootstrap file. */
    private static string $pluginFile = '';

    /**
     * Registers WordPress hooks.
     */
    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        self::$pluginBasename = plugin_basename($pluginFile);

        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_nanogpt_refresh_balance', [self::class, 'refreshBalance']);
        add_action('admin_post_nanogpt_save_default_models', [self::class, 'saveDefaultModels']);
        add_action('wp_connectors_init', [self::class, 'customizeConnector']);
        add_filter(
            'plugin_action_links_' . self::$pluginBasename,
            [self::class, 'addPluginActionLink']
        );

        DefaultModelPreferences::register();
    }

    /**
     * Loads the model-grid assets only on this settings screen.
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::PAGE_SLUG || self::$pluginFile === '') {
            return;
        }

        wp_enqueue_style(
            'nanogpt-ai-provider-settings',
            plugins_url('assets/css/settings.css', self::$pluginFile),
            [],
            '2.0.0'
        );
        wp_enqueue_script(
            'nanogpt-ai-provider-settings',
            plugins_url('assets/js/settings.js', self::$pluginFile),
            [],
            '2.0.0',
            true
        );
    }

    /**
     * Adds Settings > Nano-GPT.
     */
    public static function addSettingsPage(): void
    {
        add_options_page(
            __('Nano-GPT', 'ai-provider-for-nano-gpt'),
            __('Nano-GPT', 'ai-provider-for-nano-gpt'),
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
            'ai-provider-for-nano-gpt'
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
                esc_html__('Settings', 'ai-provider-for-nano-gpt') .
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
            wp_die(esc_html__('You are not allowed to manage these settings.', 'ai-provider-for-nano-gpt'));
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
     * Validates and saves the preferred models selected in the catalog grid.
     */
    public static function saveDefaultModels(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'ai-provider-for-nano-gpt'));
        }

        check_admin_referer('nanogpt_save_default_models');

        try {
            $models = self::modelCatalog();
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        $modelsById = [];
        foreach ($models as $model) {
            $modelsById[$model->getId()] = $model;
        }

        $selections = [
            'text' => [DefaultModelPreferences::TEXT_OPTION, self::submittedModel('nanogpt_text_model')],
            'vision' => [DefaultModelPreferences::VISION_OPTION, self::submittedModel('nanogpt_vision_model')],
            'image' => [DefaultModelPreferences::IMAGE_OPTION, self::submittedModel('nanogpt_image_model')],
        ];

        foreach ($selections as $type => [$option, $modelId]) {
            if ($modelId === '') {
                delete_option($option);
                continue;
            }
            if (!isset($modelsById[$modelId]) || !self::modelSupportsPreference($modelsById[$modelId], $type)) {
                wp_die(esc_html__('One of the selected models is no longer available.', 'ai-provider-for-nano-gpt'));
            }
            update_option($option, $modelId);
        }

        wp_safe_redirect(add_query_arg('nanogpt_models_updated', '1', self::settingsUrl()));
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

        $tab = self::currentTab();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Nano-GPT', 'ai-provider-for-nano-gpt'); ?></h1>
            <?php self::renderTabs($tab); ?>
            <?php if ($tab === 'activity') : ?>
                <?php ActivityPage::render(); ?>
            </div>
                <?php
                return;
            endif; ?>
        <?php
        $apiKey = self::apiKey();
        $balance = null;
        $error = '';
        $models = [];
        $catalogError = '';
        if ($apiKey !== '') {
            try {
                $balance = self::cachedBalance($apiKey);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
            try {
                $models = self::modelCatalog();
            } catch (Throwable $exception) {
                $catalogError = $exception->getMessage();
            }
        }
        // A nonce is unnecessary because this query parameter only controls a success notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $modelsUpdated = isset($_GET['nanogpt_models_updated']);

        ?>
            <?php if ($modelsUpdated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Preferred models saved.', 'ai-provider-for-nano-gpt'); ?>
                </p></div>
            <?php endif; ?>
            <p>
                <?php
                echo esc_html__(
                    'Use Nano-GPT models through the WordPress AI Client.',
                    'ai-provider-for-nano-gpt'
                );
                ?>
            </p>

            <h2><?php echo esc_html__('Connection', 'ai-provider-for-nano-gpt'); ?></h2>
            <?php if ($apiKey === '') : ?>
                <div class="notice notice-warning inline"><p>
                    <?php echo esc_html__('No Nano-GPT API key is configured.', 'ai-provider-for-nano-gpt'); ?>
                </p></div>
                <?php if (self::connectorsAvailable()) : ?>
                    <p><a
                        class="button button-primary"
                        href="<?php echo esc_url(admin_url('options-connectors.php')); ?>"
                    >
                        <?php echo esc_html__('Configure API key in Connectors', 'ai-provider-for-nano-gpt'); ?>
                    </a></p>
                <?php else : ?>
                    <p>
                        <?php
                        echo esc_html__(
                            'Set NANOGPT_API_KEY as an environment variable or PHP constant.',
                            'ai-provider-for-nano-gpt'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php echo esc_html__('API key configured', 'ai-provider-for-nano-gpt'); ?>
                </p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Available balance', 'ai-provider-for-nano-gpt'); ?></h2>
            <?php if ($balance instanceof NanoGptBalance) : ?>
                <table class="widefat striped" style="max-width: 520px">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('USD balance', 'ai-provider-for-nano-gpt'); ?></th>
                            <td>
                                <strong>
                                    $<?php echo esc_html(self::formatBalance($balance->getUsdBalance(), 4)); ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php echo esc_html__('Nano (XNO) balance', 'ai-provider-for-nano-gpt'); ?>
                            </th>
                            <td><?php echo esc_html(self::formatBalance($balance->getNanoBalance(), 6)); ?> XNO</td>
                        </tr>
                    </tbody>
                </table>
                <p class="description">
                    <?php echo esc_html__('Balance is cached for five minutes.', 'ai-provider-for-nano-gpt'); ?>
                </p>
                <p><a class="button" href="<?php echo esc_url(self::refreshUrl()); ?>">
                    <?php echo esc_html__('Refresh balance', 'ai-provider-for-nano-gpt'); ?>
                </a></p>
            <?php elseif ($error !== '') : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
                <p><a class="button" href="<?php echo esc_url(self::refreshUrl()); ?>">
                    <?php echo esc_html__('Try again', 'ai-provider-for-nano-gpt'); ?>
                </a></p>
            <?php else : ?>
                <p>
                    <?php
                    echo esc_html__(
                        'Configure an API key to view the account balance.',
                        'ai-provider-for-nano-gpt'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Preferred models', 'ai-provider-for-nano-gpt'); ?></h2>
            <?php if ($apiKey === '') : ?>
                <p>
                    <?php
                    echo esc_html__(
                        'Configure an API key to load the model catalog.',
                        'ai-provider-for-nano-gpt'
                    );
                    ?>
                </p>
            <?php elseif ($catalogError !== '') : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($catalogError); ?></p></div>
            <?php else : ?>
                <?php self::renderModelPreferences($models); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function currentTab(): string
    {
        // The tab only selects which read-only settings view is rendered.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) && is_string($_GET['tab'])
            ? sanitize_key(wp_unslash($_GET['tab']))
            : 'settings';

        return $tab === 'activity' ? 'activity' : 'settings';
    }

    private static function renderTabs(string $current): void
    {
        $tabs = [
            'settings' => __('Settings and models', 'ai-provider-for-nano-gpt'),
            'activity' => __('Activity', 'ai-provider-for-nano-gpt'),
        ];
        ?>
        <nav
            class="nav-tab-wrapper"
            aria-label="<?php echo esc_attr__('Nano-GPT settings', 'ai-provider-for-nano-gpt'); ?>"
        >
            <?php foreach ($tabs as $tab => $label) : ?>
                <a
                    class="nav-tab <?php echo $current === $tab ? 'nav-tab-active' : ''; ?>"
                    href="<?php echo esc_url(add_query_arg(
                        $tab === 'activity' ? ['tab' => 'activity'] : [],
                        self::settingsUrl()
                    )); ?>"
                >
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * @param list<NanoGptModelMetadata> $models Available Nano-GPT models.
     */
    private static function renderModelPreferences(array $models): void
    {
        $selections = DefaultModelPreferences::selections();
        $families = [];
        foreach ($models as $model) {
            $families[$model->getFamily()] = true;
        }
        $families = array_keys($families);
        natcasesort($families);

        ?>
        <p>
            <?php
            echo esc_html__(
                'These choices are tried first by the WordPress AI plugin. ' .
                'If a selected model is unavailable, its normal fallback list remains available.',
                'ai-provider-for-nano-gpt'
            );
            ?>
        </p>
        <p class="description">
            <?php
            echo esc_html__(
                'Free means Nano-GPT reports the model as included with your subscription; ' .
                'plan limits may still apply.',
                'ai-provider-for-nano-gpt'
            );
            ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="nanogpt_save_default_models">
            <?php wp_nonce_field('nanogpt_save_default_models'); ?>

            <div
                class="nanogpt-model-filters"
                aria-label="<?php echo esc_attr__('Filter models', 'ai-provider-for-nano-gpt'); ?>"
            >
                <label>
                    <span><?php echo esc_html__('Model', 'ai-provider-for-nano-gpt'); ?></span>
                    <input
                        type="search"
                        data-nanogpt-filter="model"
                        placeholder="<?php echo esc_attr__('Search name or ID', 'ai-provider-for-nano-gpt'); ?>"
                    >
                </label>
                <label>
                    <span><?php echo esc_html__('Free', 'ai-provider-for-nano-gpt'); ?></span>
                    <select data-nanogpt-filter="free">
                        <option value=""><?php echo esc_html__('All', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="1"><?php echo esc_html__('Included', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="0"><?php echo esc_html__('Paid', 'ai-provider-for-nano-gpt'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Minimum context', 'ai-provider-for-nano-gpt'); ?></span>
                    <select data-nanogpt-filter="context">
                        <option value="0"><?php echo esc_html__('Any size', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="32000">32K+</option>
                        <option value="100000">100K+</option>
                        <option value="200000">200K+</option>
                        <option value="1000000">1M+</option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Family', 'ai-provider-for-nano-gpt'); ?></span>
                    <select data-nanogpt-filter="family">
                        <option value=""><?php echo esc_html__('All families', 'ai-provider-for-nano-gpt'); ?></option>
                        <?php foreach ($families as $family) : ?>
                            <option value="<?php echo esc_attr($family); ?>"><?php echo esc_html($family); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Release month', 'ai-provider-for-nano-gpt'); ?></span>
                    <input type="month" data-nanogpt-filter="release">
                </label>
                <label>
                    <span><?php echo esc_html__('Type', 'ai-provider-for-nano-gpt'); ?></span>
                    <select data-nanogpt-filter="category">
                        <option value=""><?php echo esc_html__('All types', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="text"><?php echo esc_html__('Text', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="vision"><?php echo esc_html__('Vision', 'ai-provider-for-nano-gpt'); ?></option>
                        <option value="image"><?php echo esc_html__('Image', 'ai-provider-for-nano-gpt'); ?></option>
                    </select>
                </label>
                <button type="button" class="button" data-nanogpt-reset-filters>
                    <?php echo esc_html__('Reset filters', 'ai-provider-for-nano-gpt'); ?>
                </button>
            </div>

            <p class="nanogpt-model-count" aria-live="polite"></p>
            <div class="nanogpt-model-table-wrap">
                <table class="widefat striped nanogpt-model-table">
                    <thead>
                        <tr>
                            <th scope="col">
                                <?php echo esc_html__('Text default', 'ai-provider-for-nano-gpt'); ?>
                            </th>
                            <th scope="col">
                                <?php echo esc_html__('Vision default', 'ai-provider-for-nano-gpt'); ?>
                            </th>
                            <th scope="col">
                                <?php echo esc_html__('Image default', 'ai-provider-for-nano-gpt'); ?>
                            </th>
                            <?php self::renderSortableHeading('model', __('Model', 'ai-provider-for-nano-gpt')); ?>
                            <?php self::renderSortableHeading('free', __('Free', 'ai-provider-for-nano-gpt')); ?>
                            <?php
                            self::renderSortableHeading(
                                'context',
                                __('Context size', 'ai-provider-for-nano-gpt')
                            );
                            ?>
                            <?php self::renderSortableHeading('family', __('Family', 'ai-provider-for-nano-gpt')); ?>
                            <?php
                            self::renderSortableHeading(
                                'release',
                                __('Release date', 'ai-provider-for-nano-gpt')
                            );
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="nanogpt-default-row">
                            <?php self::renderDefaultRadio('nanogpt_text_model', $selections['text']); ?>
                            <?php self::renderDefaultRadio('nanogpt_vision_model', $selections['vision']); ?>
                            <?php self::renderDefaultRadio('nanogpt_image_model', $selections['image']); ?>
                            <th scope="row">
                                <?php echo esc_html__('Use WordPress AI defaults', 'ai-provider-for-nano-gpt'); ?>
                            </th>
                            <td>—</td><td>—</td><td>—</td><td>—</td>
                        </tr>
                        <?php foreach ($models as $model) : ?>
                            <?php self::renderModelRow($model, $selections); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php submit_button(__('Save preferred models', 'ai-provider-for-nano-gpt')); ?>
        </form>
        <?php
    }

    private static function renderSortableHeading(string $key, string $label): void
    {
        ?>
        <th scope="col" aria-sort="none">
            <button type="button" class="button-link" data-nanogpt-sort="<?php echo esc_attr($key); ?>">
                <?php echo esc_html($label); ?> <span aria-hidden="true">↕</span>
            </button>
        </th>
        <?php
    }

    private static function renderDefaultRadio(string $name, string $selection): void
    {
        $labels = [
            'nanogpt_text_model' => __('Use WordPress AI default for text', 'ai-provider-for-nano-gpt'),
            'nanogpt_vision_model' => __('Use WordPress AI default for vision', 'ai-provider-for-nano-gpt'),
            'nanogpt_image_model' => __('Use WordPress AI default for images', 'ai-provider-for-nano-gpt'),
        ];
        ?>
        <td class="nanogpt-default-choice">
            <label>
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="" <?php checked($selection, ''); ?>>
                <span class="screen-reader-text"><?php echo esc_html($labels[$name]); ?></span>
            </label>
        </td>
        <?php
    }

    /**
     * @param array{text: string, vision: string, image: string} $selections Saved selections.
     */
    private static function renderModelRow(NanoGptModelMetadata $model, array $selections): void
    {
        $isText = $model->getCategory() === 'text';
        $isVision = $isText && self::supportsVision($model);
        $isImage = $model->getCategory() === 'image';
        $context = $model->getContextLength();
        $releasedAt = $model->getReleasedAt();
        $releaseMonth = $releasedAt === null ? '' : gmdate('Y-m', $releasedAt);
        $categories = array_keys(array_filter([
            'text' => $isText,
            'vision' => $isVision,
            'image' => $isImage,
        ]));
        $shortName = explode(' — ', $model->getName(), 2)[0];

        ?>
        <tr
            data-nanogpt-model-row
            data-model="<?php echo esc_attr(strtolower($shortName . ' ' . $model->getId())); ?>"
            data-free="<?php echo $model->isSubscriptionIncluded() ? '1' : '0'; ?>"
            data-context="<?php echo esc_attr((string) ($context ?? 0)); ?>"
            data-family="<?php echo esc_attr($model->getFamily()); ?>"
            data-release="<?php echo esc_attr($releaseMonth); ?>"
            data-category="<?php echo esc_attr(implode(' ', $categories)); ?>"
        >
            <?php
            self::renderModelRadio(
                'nanogpt_text_model',
                $model,
                $selections['text'],
                $isText,
                __('text', 'ai-provider-for-nano-gpt')
            );
            self::renderModelRadio(
                'nanogpt_vision_model',
                $model,
                $selections['vision'],
                $isVision,
                __('vision', 'ai-provider-for-nano-gpt')
            );
            self::renderModelRadio(
                'nanogpt_image_model',
                $model,
                $selections['image'],
                $isImage,
                __('image', 'ai-provider-for-nano-gpt')
            );
            ?>
            <th scope="row">
                <strong><?php echo esc_html($shortName); ?></strong>
                <code><?php echo esc_html($model->getId()); ?></code>
            </th>
            <td>
                <?php
                echo esc_html(
                    $model->isSubscriptionIncluded()
                        ? __('Yes', 'ai-provider-for-nano-gpt')
                        : __('No', 'ai-provider-for-nano-gpt')
                );
                ?>
            </td>
            <td><?php echo esc_html($context === null ? '—' : self::formatTokenCount($context)); ?></td>
            <td><?php echo esc_html($model->getFamily()); ?></td>
            <td><?php echo esc_html($releaseMonth === '' ? '—' : $releaseMonth); ?></td>
        </tr>
        <?php
    }

    private static function renderModelRadio(
        string $name,
        NanoGptModelMetadata $model,
        string $selection,
        bool $eligible,
        string $preferenceLabel
    ): void {
        ?>
        <td class="nanogpt-default-choice">
            <?php if ($eligible) : ?>
                <label>
                    <input
                        type="radio"
                        name="<?php echo esc_attr($name); ?>"
                        value="<?php echo esc_attr($model->getId()); ?>"
                        <?php checked($selection, $model->getId()); ?>
                    >
                    <span class="screen-reader-text">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: preference type, 2: model name. */
                                __('Use as the preferred %1$s model: %2$s', 'ai-provider-for-nano-gpt'),
                                $preferenceLabel,
                                $model->getName()
                            )
                        );
                        ?>
                    </span>
                </label>
            <?php else : ?>
                <span aria-hidden="true">—</span>
            <?php endif; ?>
        </td>
        <?php
    }

    /**
     * @return list<NanoGptModelMetadata> Available models.
     */
    private static function modelCatalog(): array
    {
        $models = [];
        foreach (NanoGptProvider::modelMetadataDirectory()->listModelMetadata() as $model) {
            if ($model instanceof NanoGptModelMetadata) {
                $models[] = $model;
            }
        }

        return $models;
    }

    private static function modelSupportsPreference(NanoGptModelMetadata $model, string $type): bool
    {
        if ($type === 'text') {
            return $model->getCategory() === 'text';
        }
        if ($type === 'vision') {
            return $model->getCategory() === 'text' && self::supportsVision($model);
        }
        if ($type === 'image') {
            return $model->getCategory() === 'image';
        }

        return false;
    }

    private static function supportsVision(NanoGptModelMetadata $model): bool
    {
        foreach ($model->getSupportedOptions() as $option) {
            if (!$option->getName()->isInputModalities()) {
                continue;
            }
            foreach ($option->getSupportedValues() ?? [] as $modalities) {
                if (!is_array($modalities)) {
                    continue;
                }
                foreach ($modalities as $modality) {
                    if ($modality instanceof ModalityEnum && $modality->isImage()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function submittedModel(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        $modelId = wp_unslash($_POST[$key]);
        if (!is_string($modelId)) {
            return '';
        }

        return sanitize_text_field($modelId);
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

    private static function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1000000) {
            return rtrim(rtrim(number_format($tokens / 1000000, 2, '.', ''), '0'), '.') . 'M';
        }
        if ($tokens >= 1000) {
            return rtrim(rtrim(number_format($tokens / 1000, 1, '.', ''), '0'), '.') . 'K';
        }

        return (string) $tokens;
    }
}
