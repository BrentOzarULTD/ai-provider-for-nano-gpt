<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

class NanoGptTextGenerationModelTest extends TestCase
{
    public function testUsesNanoGptChatCompletionsEndpoint(): void
    {
        $model = new TestableNanoGptTextGenerationModel(
            new ModelMetadata(
                'anthropic/claude-test',
                'Claude Test',
                [CapabilityEnum::textGeneration()],
                []
            ),
            new ProviderMetadata(
                'nanogpt',
                'Nano-GPT',
                ProviderTypeEnum::cloud()
            )
        );

        $request = $model->request(HttpMethodEnum::POST(), 'chat/completions');

        self::assertSame('https://nano-gpt.com/api/v1/chat/completions', $request->getUri());
    }
}
