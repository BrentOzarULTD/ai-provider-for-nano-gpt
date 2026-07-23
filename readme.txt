=== AI Provider for Nano-GPT ===
Contributors: psykro, brentozar
Tags: ai, nano-gpt, image-generation, artificial-intelligence, connector
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nano-GPT text and image generation for the WordPress AI Client.

== Description ==

AI Provider for Nano-GPT connects the WordPress AI Client to Nano-GPT's OpenAI-compatible API.

**Features:**

* Text generation through Nano-GPT's current text model catalog
* Text-to-image generation through the current image model catalog
* Subscription-included versus paid labels in model selectors
* Model family, release month, and context size in model labels when available
* USD and Nano (XNO) balance display under Settings > Nano-GPT
* Searchable and sortable model catalog with preferred text, vision, and image models
* Native WordPress Connectors configuration
* Automatic provider and model discovery

"Subscription-included" means Nano-GPT reports that a text model is covered by the authenticated account's subscription. It does not mean universal or unlimited free access. Image models are labeled pay-as-you-go.

This maintained fork preserves the history and credit of Jonathan Bossenger's original OpenRouter provider.

== External services ==

This plugin connects to Nano-GPT at https://nano-gpt.com/.

It sends the configured API key to retrieve the current text and image model catalogs, classify subscription-included text models, perform generation, and retrieve account balances. Prompts, attachments, generation settings, and generated content are sent when a site feature requests generation. Nano-GPT may send request content to the operator of the selected model.

A Nano-GPT API key is required and can be obtained from https://nano-gpt.com/api.

Nano-GPT API documentation: https://docs.nano-gpt.com/
Nano-GPT terms of service: https://nano-gpt.com/legal/terms-of-service
Nano-GPT privacy policy: https://nano-gpt.com/legal/privacy-policy

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-nano-gpt/`.
2. Activate the plugin through the Plugins screen.
3. Open Settings > Connectors and configure the Nano-GPT API key.
4. Open Settings > Nano-GPT to verify the connection and view the balance.

The `NANOGPT_API_KEY` environment variable or PHP constant can be used instead of storing the key in WordPress.

== Frequently Asked Questions ==

= How do I get a Nano-GPT API key? =

Visit https://nano-gpt.com/api and create an API key.

= Does "Subscription-included" mean a model is free for everyone? =

No. It means Nano-GPT lists that text model as included for the API key's active subscription. Nano-GPT's plan limits and terms still apply.

= Where can I see my remaining balance? =

Open Settings > Nano-GPT. The USD and Nano balances are cached for five minutes; use the refresh button to request a current value.

= What image operations are supported? =

The initial release supports text-to-image generation. Image editing and image-to-image workflows are not yet supported.

== Changelog ==

= 2.0.0 =

* Replaced OpenRouter with Nano-GPT text generation and dynamic model discovery.
* Added Nano-GPT text-to-image generation.
* Added subscription-aware billing labels, model family, release month, and context size.
* Added WordPress Connectors integration and account balance display.
* Added preferred-model settings with catalog filtering and sorting.
* Fixed image size and orientation support for Nano-GPT's model-specific resolution formats.
