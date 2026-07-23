<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;

class NanoGptImageGenerationModelTest extends TestCase
{
    public function testUsesImagesEndpointAndLongDefaultTimeout(): void
    {
        $model = $this->model();
        $request = $model->request(HttpMethodEnum::POST(), 'images/generations');

        self::assertSame('https://nano-gpt.com/api/v1/images/generations', $request->getUri());
        self::assertNotNull($request->getOptions());
        self::assertSame(300.0, $request->getOptions()->getTimeout());
    }

    public function testPreservesExplicitRequestTimeout(): void
    {
        $model = $this->model();
        $options = new RequestOptions();
        $options->setTimeout(45.0);
        $model->setRequestOptions($options);

        $request = $model->request(HttpMethodEnum::POST(), 'images/generations');

        self::assertNotNull($request->getOptions());
        self::assertSame(45.0, $request->getOptions()->getTimeout());
    }

    public function testUsesExactModelSpecificSizeValues(): void
    {
        $model = new TestableNanoGptImageGenerationModel(
            new NanoGptModelMetadata(
                'mixed-resolution-test',
                'Mixed Resolution Test',
                [CapabilityEnum::imageGeneration()],
                [],
                false,
                'Other',
                null,
                null,
                'image',
                ['16:9' => 'landscape_16_9', '47:20' => '2.35:1'],
                ['landscape' => 'landscape_16_9', 'portrait' => 'portrait_4_3']
            ),
            new ProviderMetadata('nanogpt', 'Nano-GPT', ProviderTypeEnum::cloud())
        );

        self::assertSame('landscape_16_9', $model->size(null, '16:9'));
        self::assertSame('2.35:1', $model->size(null, '47:20'));
        self::assertSame('portrait_4_3', $model->size(MediaOrientationEnum::portrait(), null));
    }

    private function model(): TestableNanoGptImageGenerationModel
    {
        return new TestableNanoGptImageGenerationModel(
            new ModelMetadata(
                'gpt-image-test',
                'GPT Image Test',
                [CapabilityEnum::imageGeneration()],
                []
            ),
            new ProviderMetadata(
                'nanogpt',
                'Nano-GPT',
                ProviderTypeEnum::cloud()
            )
        );
    }
}
