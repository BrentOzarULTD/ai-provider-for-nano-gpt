<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress {
    require_once __DIR__ . '/TestOptions.php';

    /**
     * Test double for the WordPress option API.
     *
     * @param mixed $default Default value.
     * @return mixed Stored or default value.
     */
    function get_option(string $option, $default = false)
    {
        return \WordPress\NanoGptAiProvider\Tests\WordPress\TestOptions::$values[$option] ?? $default;
    }
}

namespace WordPress\NanoGptAiProvider\Tests\WordPress {
    use PHPUnit\Framework\TestCase;
    use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;
    use WordPress\NanoGptAiProvider\WordPress\DefaultModelPreferences;

    class DefaultModelPreferencesTest extends TestCase
    {
        protected function setUp(): void
        {
            TestOptions::$values = [];
        }

        public function testLeavesPreferencesUnchangedWithoutSavedModel(): void
        {
            $models = [['openai', 'gpt-test']];

            self::assertSame($models, DefaultModelPreferences::preferTextModel($models));
        }

        public function testPrependsSavedModelAndRemovesDuplicate(): void
        {
            TestOptions::$values = [DefaultModelPreferences::TEXT_OPTION => 'claude-test'];
            $models = [
                ['openai', 'gpt-test'],
                ['nanogpt', 'claude-test'],
            ];

            self::assertSame(
                [
                    ['nanogpt', 'claude-test'],
                    ['openai', 'gpt-test'],
                ],
                DefaultModelPreferences::preferTextModel($models)
            );
        }

        public function testReturnsSelectionsForEveryCapability(): void
        {
            TestOptions::$values = [
                DefaultModelPreferences::TEXT_OPTION => 'text-test',
                DefaultModelPreferences::VISION_OPTION => 'vision-test',
                DefaultModelPreferences::IMAGE_OPTION => 'image-test',
            ];

            self::assertSame(
                [
                    'text' => 'text-test',
                    'vision' => 'vision-test',
                    'image' => 'image-test',
                ],
                DefaultModelPreferences::selections()
            );
        }

        public function testPrioritizesUniqueSavedModelsAndPreservesRemainingOrder(): void
        {
            TestOptions::$values = [
                DefaultModelPreferences::TEXT_OPTION => 'text-test',
                DefaultModelPreferences::VISION_OPTION => 'text-test',
                DefaultModelPreferences::IMAGE_OPTION => 'image-test',
            ];
            $models = [
                $this->model('other-test'),
                $this->model('image-test'),
                $this->model('text-test'),
                $this->model('last-test'),
            ];

            self::assertSame(
                ['text-test', 'image-test', 'other-test', 'last-test'],
                array_map(
                    static fn (NanoGptModelMetadata $model): string => $model->getId(),
                    DefaultModelPreferences::prioritizeCatalog($models)
                )
            );
        }

        private function model(string $id): NanoGptModelMetadata
        {
            return new NanoGptModelMetadata(
                $id,
                $id,
                [],
                [],
                false,
                'Test',
                null,
                null,
                'text'
            );
        }
    }
}
