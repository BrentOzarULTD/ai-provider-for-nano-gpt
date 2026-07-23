<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

/**
 * Activity log settings, filters, details, and retention controls.
 *
 * @since 2.0.0
 */
class ActivityPage
{
    private const PAGE_SLUG = 'nanogpt-ai-provider';
    private const CLEANUP_HOOK = 'nanogpt_activity_daily_cleanup';
    private const PER_PAGE = 50;

    public static function register(): void
    {
        add_action('admin_post_nanogpt_save_activity_settings', [self::class, 'saveSettings']);
        add_action('admin_post_nanogpt_clear_activity', [self::class, 'clearActivity']);
        add_action(self::CLEANUP_HOOK, [self::class, 'cleanup']);
        add_action('init', [self::class, 'scheduleCleanup']);
        add_action('init', [ActivityRepository::class, 'maybeInstall']);
    }

    public static function scheduleCleanup(): void
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CLEANUP_HOOK);
        if (is_int($timestamp)) {
            wp_unschedule_event($timestamp, self::CLEANUP_HOOK);
        }
    }

    public static function cleanup(): void
    {
        (new ActivityRepository())->prune(
            ActivitySettings::retentionDays(),
            ActivitySettings::maxRecords()
        );
    }

    public static function saveSettings(): void
    {
        self::authorize('nanogpt_save_activity_settings');

        // The nonce and capability check above authorize these checkbox reads.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        update_option(
            ActivitySettings::ENABLED_OPTION,
            isset($_POST['nanogpt_activity_enabled']) ? '1' : '0',
            false
        );
        update_option(
            ActivitySettings::CONTENT_OPTION,
            isset($_POST['nanogpt_activity_content']) ? '1' : '0',
            false
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        update_option(
            ActivitySettings::RETENTION_DAYS_OPTION,
            (string) self::submittedInteger('nanogpt_activity_retention_days', 7, 1, 90),
            false
        );
        update_option(
            ActivitySettings::MAX_RECORDS_OPTION,
            (string) self::submittedInteger('nanogpt_activity_max_records', 500, 10, 5000),
            false
        );
        self::cleanup();

        wp_safe_redirect(add_query_arg('activity-updated', '1', self::url()));
        exit;
    }

    public static function clearActivity(): void
    {
        self::authorize('nanogpt_clear_activity');
        (new ActivityRepository())->clear();

        wp_safe_redirect(add_query_arg('activity-cleared', '1', self::url()));
        exit;
    }

    public static function render(): void
    {
        $filters = self::filters();
        $page = self::currentPage();
        $repository = new ActivityRepository();
        $results = $repository->query($filters, $page, self::PER_PAGE);

        self::renderNotices();
        self::renderSettings();
        self::renderFilters(
            $filters,
            $repository->distinctValues('model'),
            $repository->distinctValues('source_name')
        );
        self::renderTable($results['items']);
        self::renderPagination($results['total'], $page, $filters);
        self::renderClearButton($results['total']);
    }

    private static function renderNotices(): void
    {
        // These query parameters only control success notices.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['activity-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Activity settings saved.', 'ai-provider-for-nano-gpt')
                . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['activity-cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Activity log cleared.', 'ai-provider-for-nano-gpt')
                . '</p></div>';
        }
    }

    private static function renderSettings(): void
    {
        ?>
        <h2><?php echo esc_html__('Activity logging', 'ai-provider-for-nano-gpt'); ?></h2>
        <p>
            <?php
            echo esc_html__(
                'Keep a bounded local history of Nano-GPT generation calls for troubleshooting.',
                'ai-provider-for-nano-gpt'
            );
            ?>
        </p>
        <div class="notice notice-warning inline"><p>
            <?php
            echo esc_html__(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- Translation functions require a single literal.
                'Prompts and responses may contain personal, confidential, or unpublished information. Content storage is optional and should follow your site privacy policy.',
                'ai-provider-for-nano-gpt'
            );
            ?>
        </p></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="nanogpt_save_activity_settings">
            <?php wp_nonce_field('nanogpt_save_activity_settings'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Logging', 'ai-provider-for-nano-gpt'); ?></th>
                        <td><label>
                            <input
                                type="checkbox"
                                name="nanogpt_activity_enabled"
                                value="1"
                                <?php checked(ActivitySettings::isEnabled()); ?>
                            >
                            <?php echo esc_html__('Record Nano-GPT generation activity', 'ai-provider-for-nano-gpt'); ?>
                        </label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Content', 'ai-provider-for-nano-gpt'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="nanogpt_activity_content"
                                    value="1"
                                    <?php checked(ActivitySettings::capturesContent()); ?>
                                >
                                <?php
                                echo esc_html__(
                                    'Store prompts, responses, and generation settings',
                                    'ai-provider-for-nano-gpt'
                                );
                                ?>
                            </label>
                            <p class="description">
                                <?php
                                echo esc_html__(
                                    'API credentials and inline image data are never stored.',
                                    'ai-provider-for-nano-gpt'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="nanogpt-activity-days">
                                <?php echo esc_html__('Retention', 'ai-provider-for-nano-gpt'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                id="nanogpt-activity-days"
                                class="small-text"
                                type="number"
                                name="nanogpt_activity_retention_days"
                                min="1"
                                max="90"
                                value="<?php echo esc_attr((string) ActivitySettings::retentionDays()); ?>"
                            >
                            <?php echo esc_html__('days, up to', 'ai-provider-for-nano-gpt'); ?>
                            <input
                                class="small-text"
                                type="number"
                                name="nanogpt_activity_max_records"
                                min="10"
                                max="5000"
                                value="<?php echo esc_attr((string) ActivitySettings::maxRecords()); ?>"
                            >
                            <?php echo esc_html__('calls', 'ai-provider-for-nano-gpt'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Save activity settings', 'ai-provider-for-nano-gpt')); ?>
        </form>
        <?php
    }

    /**
     * @param array{status: string, capability: string, model: string, source: string} $filters Filters.
     * @param list<string> $models Models.
     * @param list<string> $sources Sources.
     */
    private static function renderFilters(array $filters, array $models, array $sources): void
    {
        ?>
        <hr>
        <h2><?php echo esc_html__('Recent calls', 'ai-provider-for-nano-gpt'); ?></h2>
        <?php if (!ActivitySettings::isEnabled()) : ?>
            <p class="description">
                <?php echo esc_html__('Logging is currently disabled.', 'ai-provider-for-nano-gpt'); ?>
            </p>
        <?php endif; ?>
        <form method="get" class="nanogpt-activity-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <input type="hidden" name="tab" value="activity">
            <?php self::filterSelect('activity_status', __('Status', 'ai-provider-for-nano-gpt'), [
                '' => __('All statuses', 'ai-provider-for-nano-gpt'),
                'success' => __('Success', 'ai-provider-for-nano-gpt'),
                'error' => __('Error', 'ai-provider-for-nano-gpt'),
            ], $filters['status']); ?>
            <?php self::filterSelect('activity_capability', __('Type', 'ai-provider-for-nano-gpt'), [
                '' => __('All types', 'ai-provider-for-nano-gpt'),
                'text' => __('Text', 'ai-provider-for-nano-gpt'),
                'image' => __('Image', 'ai-provider-for-nano-gpt'),
            ], $filters['capability']); ?>
            <?php self::filterSelect(
                'activity_model',
                __('Model', 'ai-provider-for-nano-gpt'),
                array_combine(array_merge([''], $models), array_merge([
                    __('All models', 'ai-provider-for-nano-gpt'),
                ], $models)),
                $filters['model']
            ); ?>
            <?php self::filterSelect(
                'activity_source',
                __('Source', 'ai-provider-for-nano-gpt'),
                array_combine(array_merge([''], $sources), array_merge([
                    __('All sources', 'ai-provider-for-nano-gpt'),
                ], $sources)),
                $filters['source']
            ); ?>
            <button class="button" type="submit">
                <?php echo esc_html__('Filter', 'ai-provider-for-nano-gpt'); ?>
            </button>
            <a class="button" href="<?php echo esc_url(self::url()); ?>">
                <?php echo esc_html__('Reset', 'ai-provider-for-nano-gpt'); ?>
            </a>
        </form>
        <?php
    }

    /**
     * @param list<object> $items Records.
     */
    private static function renderTable(array $items): void
    {
        ?>
        <div class="nanogpt-activity-table-wrap">
            <table class="widefat striped nanogpt-activity-table">
                <thead><tr>
                    <th><?php echo esc_html__('Time', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Source', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Type', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Model', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Status', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Duration', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Prompt', 'ai-provider-for-nano-gpt'); ?></th>
                    <th><?php echo esc_html__('Details', 'ai-provider-for-nano-gpt'); ?></th>
                </tr></thead>
                <tbody>
                    <?php if (!$items) : ?>
                        <tr><td colspan="8">
                            <?php echo esc_html__('No matching Nano-GPT calls.', 'ai-provider-for-nano-gpt'); ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item) : ?>
                        <?php self::renderRow($item); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderRow(object $item): void
    {
        $status = isset($item->status) && $item->status === 'error' ? 'error' : 'success';
        $prompt = isset($item->prompt) && is_string($item->prompt) ? $item->prompt : '';
        ?>
        <tr>
            <td><?php echo esc_html(self::localTime($item->created_at_gmt ?? '')); ?></td>
            <td>
                <strong><?php echo esc_html(self::propertyString($item, 'source_name', 'Unknown')); ?></strong>
                <span class="description"><?php echo esc_html(self::propertyString($item, 'source_type')); ?></span>
            </td>
            <td><?php echo esc_html(ucfirst(self::propertyString($item, 'capability'))); ?></td>
            <td><code><?php echo esc_html(self::propertyString($item, 'model')); ?></code></td>
            <td><span class="nanogpt-status nanogpt-status-<?php echo esc_attr($status); ?>">
                <?php echo esc_html(ucfirst($status)); ?>
            </span></td>
            <td><?php echo esc_html(self::propertyString($item, 'duration_ms', '0')); ?> ms</td>
            <td class="nanogpt-activity-preview"><?php echo esc_html(self::preview($prompt)); ?></td>
            <td>
                <details>
                    <summary><?php echo esc_html__('View', 'ai-provider-for-nano-gpt'); ?></summary>
                    <?php self::renderDetails($item); ?>
                </details>
            </td>
        </tr>
        <?php
    }

    private static function renderDetails(object $item): void
    {
        self::detail(__('Caller', 'ai-provider-for-nano-gpt'), self::propertyString($item, 'source_detail'));
        self::detail(
            __('Request context', 'ai-provider-for-nano-gpt'),
            self::propertyString($item, 'request_context')
        );
        self::detail(
            __('Generation settings', 'ai-provider-for-nano-gpt'),
            self::propertyString($item, 'request_config')
        );
        self::detail(__('Prompt', 'ai-provider-for-nano-gpt'), self::propertyString($item, 'prompt'));
        self::detail(__('Response', 'ai-provider-for-nano-gpt'), self::propertyString($item, 'response'));

        $tokens = sprintf(
            /* translators: 1: input tokens, 2: output tokens, 3: total tokens. */
            __('Input: %1$s · Output: %2$s · Total: %3$s', 'ai-provider-for-nano-gpt'),
            self::displayNullable($item->input_tokens ?? null),
            self::displayNullable($item->output_tokens ?? null),
            self::displayNullable($item->total_tokens ?? null)
        );
        self::detail(__('Tokens', 'ai-provider-for-nano-gpt'), $tokens);
        if (self::propertyString($item, 'status') === 'error') {
            $error = trim(
                self::propertyString($item, 'error_code')
                . ' '
                . self::propertyString($item, 'error_message')
            );
            self::detail(__('Error', 'ai-provider-for-nano-gpt'), $error);
        }
    }

    private static function detail(string $label, string $value): void
    {
        if ($value === '' || $value === '[]') {
            return;
        }
        ?>
        <section class="nanogpt-activity-detail">
            <h4><?php echo esc_html($label); ?></h4>
            <pre><?php echo esc_html($value); ?></pre>
        </section>
        <?php
    }

    /**
     * @param array{status: string, capability: string, model: string, source: string} $filters Filters.
     */
    private static function renderPagination(int $total, int $page, array $filters): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages < 2) {
            return;
        }
        $queryFilters = [
            'activity_status' => $filters['status'],
            'activity_capability' => $filters['capability'],
            'activity_model' => $filters['model'],
            'activity_source' => $filters['source'],
        ];
        $links = paginate_links([
            'base' => add_query_arg(
                array_filter(array_merge(
                    ['page' => self::PAGE_SLUG, 'tab' => 'activity'],
                    $queryFilters
                )),
                admin_url('options-general.php')
            ) . '%_%',
            'format' => '&activity_page=%#%',
            'current' => $page,
            'total' => $pages,
            'type' => 'list',
        ]);
        if (is_string($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">'
                . wp_kses_post($links)
                . '</div></div>';
        }
    }

    private static function renderClearButton(int $total): void
    {
        if ($total < 1) {
            return;
        }
        $confirmation = __('Permanently clear the Nano-GPT activity log?', 'ai-provider-for-nano-gpt');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="nanogpt_clear_activity">
            <?php wp_nonce_field('nanogpt_clear_activity'); ?>
            <button
                type="submit"
                class="button button-link-delete"
                data-nanogpt-confirm="<?php echo esc_attr($confirmation); ?>"
            >
                <?php echo esc_html__('Clear activity log', 'ai-provider-for-nano-gpt'); ?>
            </button>
        </form>
        <?php
    }

    /**
     * @param array<string, string>|false $options Options.
     */
    private static function filterSelect(string $name, string $label, $options, string $selected): void
    {
        $options = is_array($options) ? $options : [];
        ?>
        <label>
            <span><?php echo esc_html($label); ?></span>
            <select name="<?php echo esc_attr($name); ?>">
                <?php foreach ($options as $value => $optionLabel) : ?>
                    <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($selected, $value); ?>>
                        <?php echo esc_html($optionLabel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
    }

    /**
     * @return array{status: string, capability: string, model: string, source: string}
     */
    private static function filters(): array
    {
        // Read-only list filters do not need a nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = self::queryValue('activity_status');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $capability = self::queryValue('activity_capability');

        return [
            'status' => in_array($status, ['success', 'error'], true) ? $status : '',
            'capability' => in_array($capability, ['text', 'image'], true) ? $capability : '',
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'model' => self::queryValue('activity_model'),
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'source' => self::queryValue('activity_source'),
        ];
    }

    private static function currentPage(): int
    {
        // Read-only pagination does not need a nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['activity_page']) || !is_scalar($_GET['activity_page'])) {
            return 1;
        }

        $page = max(1, absint($_GET['activity_page']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $page;
    }

    private static function queryValue(string $key): string
    {
        // Read-only list filters do not need a nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return '';
        }

        $value = sanitize_text_field(wp_unslash($_GET[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $value;
    }

    private static function submittedInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        // saveSettings() verifies the nonce and capability before calling this helper.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return $default;
        }

        $value = max($minimum, min($maximum, (int) $_POST[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return $value;
    }

    private static function authorize(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage these settings.', 'ai-provider-for-nano-gpt'));
        }
        check_admin_referer($nonceAction);
    }

    private static function url(): string
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=activity');
    }

    /**
     * @param mixed $date Date.
     */
    private static function localTime($date): string
    {
        if (!is_string($date) || $date === '') {
            return '';
        }

        return get_date_from_gmt($date, 'Y-m-d H:i:s');
    }

    private static function preview(string $json): string
    {
        if ($json === '' || $json === '[]') {
            return '—';
        }
        $decoded = json_decode($json, true);
        $strings = [];
        self::collectStrings($decoded, $strings);
        $preview = trim(implode(' ', $strings));
        if ($preview === '') {
            return '—';
        }

        return function_exists('mb_strimwidth')
            ? mb_strimwidth($preview, 0, 140, '…')
            : substr($preview, 0, 140);
    }

    /**
     * @param mixed $value Value.
     * @param list<string> $strings Strings.
     */
    private static function collectStrings($value, array &$strings): void
    {
        if (is_string($value)) {
            $strings[] = $value;
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::collectStrings($item, $strings);
        }
    }

    /**
     * @param mixed $value Value.
     */
    private static function displayNullable($value): string
    {
        return is_numeric($value) ? number_format_i18n((int) $value) : '—';
    }

    private static function propertyString(object $item, string $property, string $default = ''): string
    {
        if (!isset($item->{$property}) || !is_scalar($item->{$property})) {
            return $default;
        }

        return (string) $item->{$property};
    }
}
