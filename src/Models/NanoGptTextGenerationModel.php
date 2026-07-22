<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

/**
 * Text generation through Nano-GPT's OpenAI-compatible Chat Completions API.
 *
 * @since 1.0.0
 */
class NanoGptTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            NanoGptProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}
