<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadataDirectory;
use WordPress\NanoGptAiProvider\Models\NanoGptImageGenerationModel;
use WordPress\NanoGptAiProvider\Models\NanoGptTextGenerationModel;

/**
 * Nano-GPT provider for the WordPress AI Client.
 *
 * @since 1.0.0
 */
class NanoGptProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     */
    protected static function baseUrl(): string
    {
        return 'https://nano-gpt.com/api/v1';
    }

    /**
     * Returns a URL for Nano-GPT's subscription-specific API.
     *
     * @since 1.0.0
     *
     * @param string $path Optional path to append.
     * @return string Complete subscription API URL.
     */
    public static function subscriptionUrl(string $path = ''): string
    {
        $baseUrl = 'https://nano-gpt.com/api/subscription/v1';

        return $path === '' ? $baseUrl : $baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isImageGeneration()) {
                return new NanoGptImageGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isTextGeneration()) {
                return new NanoGptTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $modelMetadata->getSupportedCapabilities())
        );
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'nanogpt',
            'Nano-GPT',
            ProviderTypeEnum::cloud(),
            'https://nano-gpt.com/api',
            RequestAuthenticationMethod::apiKey()
        );
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new NanoGptModelMetadataDirectory();
    }
}
