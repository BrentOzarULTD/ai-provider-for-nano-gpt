<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

/**
 * Image generation through Nano-GPT's OpenAI-compatible Images API.
 *
 * @since 1.0.0
 */
class NanoGptImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * Default timeout for image generation requests, in seconds.
     */
    private const DEFAULT_TIMEOUT = 300.0;

    /**
     * {@inheritDoc}
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        $options = $this->getRequestOptions();
        $options = $options === null ? new RequestOptions() : clone $options;

        if ($options->getTimeout() === null) {
            $options->setTimeout(self::DEFAULT_TIMEOUT);
        }

        return new Request(
            $method,
            NanoGptProvider::url($path),
            $headers,
            $data,
            $options
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getResultId(array $responseData): string
    {
        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'nanogpt-image-' . $responseData['created']
            : '';
    }
}
