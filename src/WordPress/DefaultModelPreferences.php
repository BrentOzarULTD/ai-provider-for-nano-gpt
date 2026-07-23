<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;

/**
 * Adds saved Nano-GPT models to the WordPress AI plugin's preference lists.
 *
 * @since 2.0.0
 */
class DefaultModelPreferences
{
    public const TEXT_OPTION = 'nanogpt_preferred_text_model';
    public const VISION_OPTION = 'nanogpt_preferred_vision_model';
    public const IMAGE_OPTION = 'nanogpt_preferred_image_model';

    private const PROVIDER_ID = 'nanogpt';

    /**
     * Registers preference filters exposed by the WordPress AI plugin.
     */
    public static function register(): void
    {
        add_filter('wpai_preferred_text_models', [self::class, 'preferTextModel']);
        add_filter('wpai_preferred_vision_models', [self::class, 'preferVisionModel']);
        add_filter('wpai_preferred_image_models', [self::class, 'preferImageModel']);
    }

    /**
     * @param array<int, array{string, string}> $models Existing preferences.
     * @return array<int, array{string, string}> Updated preferences.
     */
    public static function preferTextModel(array $models): array
    {
        return self::prependSavedModel($models, self::TEXT_OPTION);
    }

    /**
     * @param array<int, array{string, string}> $models Existing preferences.
     * @return array<int, array{string, string}> Updated preferences.
     */
    public static function preferVisionModel(array $models): array
    {
        return self::prependSavedModel($models, self::VISION_OPTION);
    }

    /**
     * @param array<int, array{string, string}> $models Existing preferences.
     * @return array<int, array{string, string}> Updated preferences.
     */
    public static function preferImageModel(array $models): array
    {
        return self::prependSavedModel($models, self::IMAGE_OPTION);
    }

    /**
     * Returns the three saved selections.
     *
     * @return array{text: string, vision: string, image: string}
     */
    public static function selections(): array
    {
        return [
            'text' => self::savedModel(self::TEXT_OPTION),
            'vision' => self::savedModel(self::VISION_OPTION),
            'image' => self::savedModel(self::IMAGE_OPTION),
        ];
    }

    /**
     * Moves saved defaults to the beginning of the model catalog.
     *
     * Text, vision, and image selection order is preserved, with duplicate
     * selections shown only once.
     *
     * @param list<NanoGptModelMetadata> $models Available models.
     * @return list<NanoGptModelMetadata> Models with saved defaults first.
     */
    public static function prioritizeCatalog(array $models): array
    {
        $modelsById = [];
        foreach ($models as $model) {
            $modelsById[$model->getId()] = $model;
        }

        $prioritized = [];
        foreach (array_unique(array_values(self::selections())) as $modelId) {
            if ($modelId === '' || !isset($modelsById[$modelId])) {
                continue;
            }
            $prioritized[] = $modelsById[$modelId];
            unset($modelsById[$modelId]);
        }
        foreach ($models as $model) {
            if (isset($modelsById[$model->getId()])) {
                $prioritized[] = $model;
                unset($modelsById[$model->getId()]);
            }
        }

        return $prioritized;
    }

    /**
     * @param array<int, array{string, string}> $models Existing preferences.
     * @return array<int, array{string, string}> Updated preferences.
     */
    private static function prependSavedModel(array $models, string $option): array
    {
        $modelId = self::savedModel($option);
        if ($modelId === '') {
            return $models;
        }

        $preferred = [[self::PROVIDER_ID, $modelId]];
        foreach ($models as $model) {
            if (
                count($model) === 2 &&
                $model[0] === self::PROVIDER_ID &&
                $model[1] === $modelId
            ) {
                continue;
            }
            $preferred[] = $model;
        }

        return $preferred;
    }

    private static function savedModel(string $option): string
    {
        $modelId = get_option($option, '');

        return is_string($modelId) ? $modelId : '';
    }
}
