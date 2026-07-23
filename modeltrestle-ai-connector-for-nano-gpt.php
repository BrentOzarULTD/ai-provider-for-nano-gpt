<?php

/**
 * Plugin Name: ModelTrestle AI Connector for Nano-GPT
 * Plugin URI: https://github.com/BrentOzarULTD/ai-provider-for-nano-gpt
 * Description: Nano-GPT text and image generation provider for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 2.0.0
 * Author: Brent Ozar
 * Author URI: https://www.brentozar.com/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: modeltrestle-ai-connector-for-nano-gpt
 *
 * @package WordPress\NanoGptAiProvider
 */

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\NanoGptAiProvider\Observability\ActivityLoggingHttpTransporter;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;
use WordPress\NanoGptAiProvider\WordPress\ActivityPage;
use WordPress\NanoGptAiProvider\WordPress\ActivityRepository;
use WordPress\NanoGptAiProvider\WordPress\SettingsPage;
use WordPress\NanoGptAiProvider\WordPress\WordPressActivityRecorder;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers Nano-GPT with the WordPress AI Client.
 *
 * @since 1.0.0
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();
    if (!$registry->hasProvider(NanoGptProvider::class)) {
        $registry->registerProvider(NanoGptProvider::class);
    }

    $transporter = $registry->getHttpTransporter();
    if (!$transporter instanceof ActivityLoggingHttpTransporter) {
        $registry->setHttpTransporter(
            new ActivityLoggingHttpTransporter($transporter, new WordPressActivityRecorder())
        );
    }
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
SettingsPage::register(__FILE__);
ActivityPage::register();

register_activation_hook(__FILE__, [ActivityRepository::class, 'install']);
register_deactivation_hook(__FILE__, [ActivityPage::class, 'deactivate']);
register_uninstall_hook(__FILE__, [ActivityRepository::class, 'uninstall']);
