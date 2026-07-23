<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;

class NanoGptModelMetadataDirectoryTest extends TestCase
{
    public function testParsesSubscriptionTextModelSelectionMetadata(): void
    {
        $directory = new TestableNanoGptModelMetadataDirectory();
        $models = $directory->parseText(
            $this->response([
                [
                    'id' => 'anthropic/claude-sonnet-test',
                    'name' => 'Claude Sonnet Test',
                    'created' => 1735689600,
                    'owned_by' => 'anthropic',
                    'context_length' => 200000,
                    'architecture' => [
                        'input_modalities' => ['text', 'image'],
                        'output_modalities' => ['text'],
                    ],
                    'capabilities' => [
                        'vision' => true,
                        'tool_calling' => true,
                        'structured_output' => true,
                    ],
                    'subscription' => ['included' => true],
                ],
            ]),
            ['anthropic/claude-sonnet-test' => true]
        );

        self::assertCount(1, $models);
        $model = $models[0];
        self::assertInstanceOf(NanoGptModelMetadata::class, $model);
        self::assertTrue($model->isSubscriptionIncluded());
        self::assertSame('Claude', $model->getFamily());
        self::assertSame(1735689600, $model->getReleasedAt());
        self::assertSame(200000, $model->getContextLength());
        self::assertSame('text', $model->getCategory());
        self::assertStringContainsString('Subscription-included', $model->getName());
        self::assertStringContainsString('Claude', $model->getName());
        self::assertStringContainsString('2025-01', $model->getName());
        self::assertStringContainsString('200K context', $model->getName());

        $optionNames = [];
        foreach ($model->getSupportedOptions() as $option) {
            $optionNames[] = $option->getName()->value;
        }
        self::assertContains(OptionEnum::functionDeclarations()->value, $optionNames);
        self::assertContains(OptionEnum::outputSchema()->value, $optionNames);
    }

    public function testParsesPaidTextModel(): void
    {
        $directory = new TestableNanoGptModelMetadataDirectory();
        $models = $directory->parseText($this->response([
            [
                'id' => 'openai/gpt-test',
                'name' => 'GPT Test',
                'created' => 1704067200,
                'owned_by' => 'openai',
                'context_length' => 1048576,
                'architecture' => ['input_modalities' => ['text']],
                'capabilities' => [],
                'subscription' => ['included' => false],
            ],
        ]));

        self::assertFalse($models[0]->isSubscriptionIncluded());
        self::assertSame('GPT', $models[0]->getFamily());
        self::assertStringContainsString('Paid', $models[0]->getName());
        self::assertStringContainsString('1.05M context', $models[0]->getName());
    }

    public function testParsesImageModelCapabilitiesAndOptions(): void
    {
        $directory = new TestableNanoGptModelMetadataDirectory();
        $models = $directory->parseImages($this->response([
            [
                'id' => 'gpt-image-test',
                'name' => 'GPT Image Test',
                'created' => 1743465600,
                'owned_by' => 'openai',
                'architecture' => [
                    'input_modalities' => ['text'],
                    'output_modalities' => ['image'],
                ],
                'capabilities' => ['image_generation' => true],
                'supported_parameters' => [
                    'resolutions' => ['1024x1024', '1536x1024', '1024x1536'],
                    'max_images' => 4,
                ],
            ],
        ]));

        self::assertCount(1, $models);
        self::assertSame('image', $models[0]->getCategory());
        self::assertFalse($models[0]->isSubscriptionIncluded());
        self::assertStringContainsString('Pay-as-you-go', $models[0]->getName());
        self::assertTrue($models[0]->getSupportedCapabilities()[0]->isImageGeneration());

        $candidateCounts = null;
        $aspectRatios = null;
        foreach ($models[0]->getSupportedOptions() as $option) {
            if ($option->getName()->isCandidateCount()) {
                $candidateCounts = $option->getSupportedValues();
            }
            if ($option->getName()->isOutputMediaAspectRatio()) {
                $aspectRatios = $option->getSupportedValues();
            }
        }
        self::assertSame([1, 2, 3, 4], $candidateCounts);
        self::assertIsArray($aspectRatios);
        self::assertContains('1:1', $aspectRatios);
        self::assertContains('3:2', $aspectRatios);
        self::assertContains('2:3', $aspectRatios);
    }

    public function testParsesEveryNanoGptImageResolutionFormat(): void
    {
        $directory = new TestableNanoGptModelMetadataDirectory();
        $models = $directory->parseImages($this->response([
            [
                'id' => 'mixed-resolution-test',
                'name' => 'Mixed Resolution Test',
                'capabilities' => ['image_generation' => true],
                'supported_parameters' => [
                    'resolutions' => [
                        'square_hd',
                        'landscape_16_9',
                        'portrait_4_3',
                        '2.35:1',
                        '832*1248',
                        'auto',
                        '2k',
                    ],
                ],
            ],
        ]));

        $aspectRatios = null;
        $orientations = null;
        foreach ($models[0]->getSupportedOptions() as $option) {
            if ($option->getName()->isOutputMediaAspectRatio()) {
                $aspectRatios = $option->getSupportedValues();
            }
            if ($option->getName()->isOutputMediaOrientation()) {
                $orientations = $option->getSupportedValues();
            }
        }

        self::assertSame(['1:1', '16:9', '3:4', '47:20', '2:3'], $aspectRatios);
        self::assertEquals(
            [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ],
            $orientations
        );
        self::assertSame('square_hd', $models[0]->getSizeForAspectRatio('1:1'));
        self::assertSame('landscape_16_9', $models[0]->getSizeForAspectRatio('16:9'));
        self::assertSame('portrait_4_3', $models[0]->getSizeForAspectRatio('3:4'));
        self::assertSame('2.35:1', $models[0]->getSizeForAspectRatio('47:20'));
        self::assertSame('832*1248', $models[0]->getSizeForAspectRatio('2:3'));
        self::assertSame('landscape_16_9', $models[0]->getSizeForOrientation('landscape'));
        self::assertSame('portrait_4_3', $models[0]->getSizeForOrientation('portrait'));
    }

    /**
     * @param list<array<string, mixed>> $models Model records.
     */
    private function response(array $models): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['object' => 'list', 'data' => $models], JSON_THROW_ON_ERROR)
        );
    }
}
