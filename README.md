# AI Provider for Nano-GPT

A [Nano-GPT](https://nano-gpt.com/) provider for the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client). It supports dynamic text and image model discovery, text generation, text-to-image generation, subscription-aware model labels, and account balance display in WordPress.

This project is a maintained fork of Jonathan Bossenger's original OpenRouter provider. Its history and attribution are intentionally preserved so generally useful improvements can still be contributed upstream.

## Requirements

- PHP 7.4 or newer
- WordPress 7.0 or newer when installed as a plugin
- A Nano-GPT API key

## WordPress installation

1. Upload the project to `/wp-content/plugins/ai-provider-for-nano-gpt/`.
2. Activate **AI Provider for Nano-GPT**.
3. Open **Settings > Connectors** and add the Nano-GPT API key.
4. Open **Settings > Nano-GPT** to confirm the connection and view the account balance.

For deployment environments, the key can instead be supplied as an environment variable or PHP constant:

```php
define('NANOGPT_API_KEY', 'your-api-key');
```

The provider ID used by the AI Client is `nanogpt`.

## Composer installation

Until the fork is published to Packagist, add this repository as a VCS repository in the consuming project's `composer.json`, then require `brentozar/ai-provider-for-nano-gpt`. The package installs the PHP AI Client as a dependency.

Register the provider in a standalone application:

```php
use WordPress\AiClient\AiClient;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

putenv('NANOGPT_API_KEY=your-api-key');
AiClient::defaultRegistry()->registerProvider(NanoGptProvider::class);

$text = AiClient::prompt('Explain database indexing in plain language.')
    ->usingProvider('nanogpt')
    ->generateText();
```

Select a specific text model when predictable routing matters:

```php
$model = NanoGptProvider::model('anthropic/claude-sonnet-4.5');

$text = AiClient::prompt('Summarize this incident report.')
    ->usingModel($model)
    ->generateText();
```

Generate an image with a current image model ID from Nano-GPT's catalog:

```php
$model = NanoGptProvider::model('your-image-model-id');

$image = AiClient::prompt('A hand-drawn map of a seaside village')
    ->usingModel($model)
    ->generateImage();
```

Image support currently covers text-to-image generation through Nano-GPT's OpenAI-compatible Images API. Image aspect ratios and orientations are discovered from each model's advertised resolutions. Pixel dimensions, ratio strings, and named presets are normalized for WordPress AI while the exact model-specific value is preserved for API requests. Image editing and image-to-image workflows are outside the initial scope.

## Model discovery and labels

The provider retrieves Nano-GPT's current text, subscription, and image catalogs rather than maintaining a stale hard-coded list. Text model labels include the information most useful at selection time:

```text
Claude Sonnet … — Subscription-included · Claude · 2025-09 · 200K context
GPT … — Paid · GPT · 2025-08 · 400K context
```

- **Subscription-included** means Nano-GPT reports the text model as included for the authenticated subscription. It does not mean the model is universally free or that subscription limits cannot apply.
- **Paid** means requests use pay-as-you-go balance.
- Image models are labeled **Pay-as-you-go** because Nano-GPT's subscription catalog currently classifies text models only.
- Release month and context size are shown when supplied by the API.

Consumers that need structured values can inspect `NanoGptModelMetadata` with `isSubscriptionIncluded()`, `getFamily()`, `getReleasedAt()`, `getContextLength()`, and `getCategory()`.

## Balance and credential handling

**Settings > Nano-GPT** shows the available USD and Nano (XNO) balances. Results are cached for five minutes and can be refreshed manually. API keys are never displayed by this plugin.

The same page provides a searchable, sortable model catalog and lets administrators select preferred text, vision, and image models. Saved choices are prepended to the WordPress AI plugin's model preference lists; its normal provider fallbacks remain available. Catalog filters include subscription-included status, context size, family, release month, and capability.

For local development, copy `.env.example` to `.env`. Local `.env` variants and PHPUnit cache files are ignored by Git. WordPress does not load `.env` files itself; use your local environment loader or configure the key through Connectors.

## External service and privacy

Model catalogs, prompts, attachments, generation settings, and generated content are sent to Nano-GPT. Nano-GPT may route request content to the provider that operates the selected model. The settings screen also sends the API key to Nano-GPT's balance endpoint. Review the [Nano-GPT API documentation](https://docs.nano-gpt.com/), [terms](https://nano-gpt.com/legal/terms-of-service), and [privacy policy](https://nano-gpt.com/legal/privacy-policy) before use.

## Development

```bash
composer install
composer lint
```

`composer lint` runs PHPCS, PHPStan, and PHPUnit. Tests use fixtures and do not call Nano-GPT or consume balance.

## Building a WordPress release

After the quality checks pass, build the provider-only WordPress plugin ZIP:

```bash
composer lint
composer build-release
```

The build reads the version from the plugin header and writes the ZIP and its SHA-256 checksum to `dist/`. The ZIP contains only the plugin bootstrap, runtime source, license, and WordPress readme; it does not bundle Composer dependencies or development files.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
