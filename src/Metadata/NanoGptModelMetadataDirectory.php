<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Metadata;

use Throwable;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

/**
 * Discovers and normalizes Nano-GPT text and image model metadata.
 *
 * @since 1.0.0
 */
class NanoGptModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     */
    protected function sendListModelsRequest(): array
    {
        $transporter = $this->getHttpTransporter();
        $authentication = $this->getRequestAuthentication();

        $textRequest = new Request(
            HttpMethodEnum::GET(),
            NanoGptProvider::url('models?detailed=true')
        );
        $textResponse = $transporter->send(
            $authentication->authenticateRequest($textRequest)
        );
        ResponseUtil::throwIfNotSuccessful($textResponse);

        $subscriptionIds = $this->subscriptionIdsFromTextResponse($textResponse);

        // The subscription endpoint is authoritative, but classification should
        // gracefully fall back to the detailed catalog if it is unavailable.
        try {
            $subscriptionRequest = new Request(
                HttpMethodEnum::GET(),
                NanoGptProvider::subscriptionUrl('models?detailed=true')
            );
            $subscriptionResponse = $transporter->send(
                $authentication->authenticateRequest($subscriptionRequest)
            );
            if ($subscriptionResponse->isSuccessful()) {
                $subscriptionIds = $this->modelIdsFromResponse($subscriptionResponse);
            }
        } catch (Throwable $exception) {
            // Optional enrichment only; the canonical response remains usable.
        }

        $models = $this->parseTextModels($textResponse, $subscriptionIds);

        // An image catalog outage should not make text generation unavailable.
        try {
            $imageRequest = new Request(
                HttpMethodEnum::GET(),
                NanoGptProvider::url('image-models?detailed=true')
            );
            $imageResponse = $transporter->send(
                $authentication->authenticateRequest($imageRequest)
            );
            if ($imageResponse->isSuccessful()) {
                $models = array_merge($models, $this->parseImageModels($imageResponse));
            }
        } catch (Throwable $exception) {
            // Text models are still valid and should remain available.
        }

        usort($models, [$this, 'compareModels']);

        $modelMap = [];
        foreach ($models as $model) {
            $modelMap[$model->getId()] = $model;
        }

        return $modelMap;
    }

    /**
     * Parses Nano-GPT text models.
     *
     * @param Response $response Detailed canonical models response.
     * @param array<string, bool> $subscriptionIds Subscription-included model IDs.
     * @return list<NanoGptModelMetadata> Parsed models.
     */
    protected function parseTextModels(Response $response, array $subscriptionIds = []): array
    {
        $modelsData = $this->responseModelList($response);
        $models = [];

        foreach ($modelsData as $modelData) {
            if (!isset($modelData['id']) || !is_string($modelData['id']) || $modelData['id'] === '') {
                continue;
            }

            $modelId = $modelData['id'];
            $included = isset($subscriptionIds[$modelId]);
            $subscription = $modelData['subscription'] ?? null;
            if (!$included && is_array($subscription) && isset($subscription['included'])) {
                $included = (bool) $subscription['included'];
            }

            $family = $this->determineFamily($modelData);
            $releasedAt = $this->nullablePositiveInteger($modelData['created'] ?? null);
            $contextLength = $this->nullablePositiveInteger($modelData['context_length'] ?? null);
            $baseName = isset($modelData['name']) && is_string($modelData['name']) && $modelData['name'] !== ''
                ? $modelData['name']
                : $modelId;

            $models[] = new NanoGptModelMetadata(
                $modelId,
                $this->buildDisplayName(
                    $baseName,
                    $included ? 'Subscription-included' : 'Paid',
                    $family,
                    $releasedAt,
                    $contextLength
                ),
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                $this->textOptions($modelData),
                $included,
                $family,
                $releasedAt,
                $contextLength,
                'text'
            );
        }

        return $models;
    }

    /**
     * Parses Nano-GPT image models.
     *
     * @param Response $response Image models response.
     * @return list<NanoGptModelMetadata> Parsed models.
     */
    protected function parseImageModels(Response $response): array
    {
        $modelsData = $this->responseModelList($response);
        $models = [];

        foreach ($modelsData as $modelData) {
            if (!isset($modelData['id']) || !is_string($modelData['id']) || $modelData['id'] === '') {
                continue;
            }
            $capabilities = $modelData['capabilities'] ?? null;
            if (
                is_array($capabilities) &&
                isset($capabilities['image_generation']) &&
                !$capabilities['image_generation']
            ) {
                continue;
            }

            $modelId = $modelData['id'];
            $family = $this->determineFamily($modelData);
            $releasedAt = $this->nullablePositiveInteger($modelData['created'] ?? null);
            $baseName = isset($modelData['name']) && is_string($modelData['name']) && $modelData['name'] !== ''
                ? $modelData['name']
                : $modelId;

            $models[] = new NanoGptModelMetadata(
                $modelId,
                $this->buildDisplayName($baseName, 'Pay-as-you-go', $family, $releasedAt, null),
                [CapabilityEnum::imageGeneration()],
                $this->imageOptions($modelData),
                false,
                $family,
                $releasedAt,
                null,
                'image'
            );
        }

        return $models;
    }

    /**
     * Extracts the model list from a Nano-GPT response.
     *
     * @param Response $response API response.
     * @return list<array<string, mixed>> Model records.
     * @throws ResponseException If the response has no valid data list.
     */
    private function responseModelList(Response $response): array
    {
        $data = $response->getData();
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw ResponseException::fromMissingData('Nano-GPT', 'data');
        }

        $models = [];
        foreach ($data['data'] as $model) {
            if (is_array($model) && !array_is_list($model)) {
                $normalizedModel = [];
                foreach ($model as $key => $value) {
                    if (is_string($key)) {
                        $normalizedModel[$key] = $value;
                    }
                }
                if ($normalizedModel) {
                    $models[] = $normalizedModel;
                }
            }
        }

        return $models;
    }

    /**
     * Gets subscription flags embedded in the canonical detailed response.
     *
     * @return array<string, bool> Included IDs as a set.
     */
    private function subscriptionIdsFromTextResponse(Response $response): array
    {
        $ids = [];
        foreach ($this->responseModelList($response) as $model) {
            $subscription = $model['subscription'] ?? null;
            if (
                isset($model['id']) &&
                is_string($model['id']) &&
                is_array($subscription) &&
                !empty($subscription['included'])
            ) {
                $ids[$model['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * Gets all model IDs in a response as a set.
     *
     * @return array<string, bool> Model IDs.
     */
    private function modelIdsFromResponse(Response $response): array
    {
        $ids = [];
        foreach ($this->responseModelList($response) as $model) {
            if (isset($model['id']) && is_string($model['id']) && $model['id'] !== '') {
                $ids[$model['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * Builds supported text-generation options from advertised capabilities.
     *
     * @param array<string, mixed> $model Model record.
     * @return list<SupportedOption> Supported options.
     */
    private function textOptions(array $model): array
    {
        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $capabilities = isset($model['capabilities']) && is_array($model['capabilities'])
            ? $model['capabilities']
            : [];

        if (!empty($capabilities['tool_calling'])) {
            $options[] = new SupportedOption(OptionEnum::functionDeclarations());
        }
        if (!empty($capabilities['structured_output'])) {
            $options[] = new SupportedOption(OptionEnum::outputSchema());
            $options[] = new SupportedOption(
                OptionEnum::outputMimeType(),
                ['text/plain', 'application/json']
            );
        } else {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain']);
        }

        $inputModalities = [[ModalityEnum::text()]];
        $architecture = $model['architecture'] ?? null;
        $advertisedInputs = is_array($architecture)
            ? ($architecture['input_modalities'] ?? [])
            : [];
        if (is_array($advertisedInputs) && in_array('image', $advertisedInputs, true)) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        $options[] = new SupportedOption(OptionEnum::inputModalities(), $inputModalities);
        $options[] = new SupportedOption(
            OptionEnum::outputModalities(),
            [[ModalityEnum::text()]]
        );

        return $options;
    }

    /**
     * Builds image-generation options from the model catalog.
     *
     * @param array<string, mixed> $model Model record.
     * @return list<SupportedOption> Supported options.
     */
    private function imageOptions(array $model): array
    {
        $parameters = isset($model['supported_parameters']) && is_array($model['supported_parameters'])
            ? $model['supported_parameters']
            : [];

        $maxImages = $this->nullablePositiveInteger(
            $parameters['fixed_image_count'] ??
            $parameters['max_output_images'] ??
            $parameters['max_images'] ??
            1
        );
        $maxImages = min($maxImages ?? 1, 16);
        $candidateCounts = range(1, $maxImages);
        if (isset($parameters['fixed_image_count']) && is_numeric($parameters['fixed_image_count'])) {
            $candidateCounts = [(int) $parameters['fixed_image_count']];
        }

        $aspectRatios = [];
        $orientations = [];
        $compatibleAspectRatios = ['1:1', '3:2', '7:4', '2:3', '4:7'];
        $resolutions = $parameters['resolutions'] ?? [];
        if (is_array($resolutions)) {
            foreach ($resolutions as $resolution) {
                if (!is_string($resolution) || !preg_match('/^(\d+)x(\d+)$/', $resolution, $matches)) {
                    continue;
                }
                $width = (int) $matches[1];
                $height = (int) $matches[2];
                if ($width < 1 || $height < 1) {
                    continue;
                }
                $divisor = $this->greatestCommonDivisor($width, $height);
                $aspectRatio = ($width / $divisor) . ':' . ($height / $divisor);
                if (!in_array($aspectRatio, $compatibleAspectRatios, true)) {
                    continue;
                }
                $aspectRatios[] = $aspectRatio;
                if ($width === $height) {
                    $orientations['square'] = MediaOrientationEnum::square();
                } elseif ($width > $height) {
                    $orientations['landscape'] = MediaOrientationEnum::landscape();
                } else {
                    $orientations['portrait'] = MediaOrientationEnum::portrait();
                }
            }
        }

        $aspectRatios = array_values(array_unique($aspectRatios));
        $options = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount(), $candidateCounts),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png']),
            new SupportedOption(
                OptionEnum::outputFileType(),
                [FileTypeEnum::inline(), FileTypeEnum::remote()]
            ),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        if ($orientations) {
            $options[] = new SupportedOption(
                OptionEnum::outputMediaOrientation(),
                array_values($orientations)
            );
        }
        if ($aspectRatios) {
            $options[] = new SupportedOption(OptionEnum::outputMediaAspectRatio(), $aspectRatios);
        }

        return $options;
    }

    /**
     * Creates a compact, informative name for existing WordPress model pickers.
     */
    private function buildDisplayName(
        string $name,
        string $billingLabel,
        string $family,
        ?int $releasedAt,
        ?int $contextLength
    ): string {
        $details = [$billingLabel, $family];
        if ($releasedAt !== null) {
            $details[] = gmdate('Y-m', $releasedAt);
        }
        if ($contextLength !== null) {
            $details[] = $this->formatTokenCount($contextLength) . ' context';
        }

        return $name . ' — ' . implode(' · ', $details);
    }

    /**
     * Determines a familiar model family from provider, ID, and display name.
     *
     * @param array<string, mixed> $model Model record.
     */
    private function determineFamily(array $model): string
    {
        $owner = isset($model['owned_by']) && is_string($model['owned_by'])
            ? strtolower($model['owned_by'])
            : '';
        $id = isset($model['id']) && is_string($model['id']) ? strtolower($model['id']) : '';
        $name = isset($model['name']) && is_string($model['name']) ? strtolower($model['name']) : '';
        $value = $owner . ' ' . $id . ' ' . $name;

        $families = [
            'DALL-E' => ['dall-e'],
            'Claude' => ['anthropic', 'claude'],
            'Imagen' => ['imagen'],
            'Gemini' => ['gemini', 'google/'],
            'GPT' => ['openai', 'chatgpt', '/gpt-', ' gpt-', ' o1', ' o3', ' o4'],
            'Grok' => ['x-ai', 'grok'],
            'Llama' => ['meta', 'llama'],
            'DeepSeek' => ['deepseek'],
            'Mistral' => ['mistral', 'mixtral'],
            'Qwen' => ['qwen', 'alibaba'],
            'Kimi' => ['moonshot', 'kimi'],
            'GLM' => ['zhipu', 'glm'],
            'Command' => ['cohere', 'command-r'],
            'Sonar' => ['perplexity', 'sonar'],
            'MiniMax' => ['minimax'],
            'FLUX' => ['black-forest', 'bfl', 'flux'],
            'Stable Diffusion' => ['stability', 'stable-diffusion', 'sdxl'],
        ];

        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                if (strpos($value, $needle) !== false) {
                    return $family;
                }
            }
        }

        if ($owner !== '' && $owner !== 'other' && $owner !== 'organization-owner') {
            return ucwords(str_replace(['-', '_'], ' ', $owner));
        }

        return 'Other';
    }

    /**
     * Sorts subscription models first, then newest models and names.
     */
    private function compareModels(ModelMetadata $first, ModelMetadata $second): int
    {
        if ($first instanceof NanoGptModelMetadata && $second instanceof NanoGptModelMetadata) {
            if ($first->isSubscriptionIncluded() !== $second->isSubscriptionIncluded()) {
                return $first->isSubscriptionIncluded() ? -1 : 1;
            }

            $firstDate = $first->getReleasedAt() ?? 0;
            $secondDate = $second->getReleasedAt() ?? 0;
            if ($firstDate !== $secondDate) {
                return $secondDate <=> $firstDate;
            }
        }

        return strcasecmp($first->getName(), $second->getName());
    }

    /**
     * @param mixed $value Candidate integer.
     */
    private function nullablePositiveInteger($value): ?int
    {
        if (!is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1000000) {
            return rtrim(rtrim(number_format($tokens / 1000000, 2, '.', ''), '0'), '.') . 'M';
        }
        if ($tokens >= 1000) {
            return rtrim(rtrim(number_format($tokens / 1000, 1, '.', ''), '0'), '.') . 'K';
        }

        return (string) $tokens;
    }

    private function greatestCommonDivisor(int $first, int $second): int
    {
        while ($second !== 0) {
            $remainder = $first % $second;
            $first = $second;
            $second = $remainder;
        }

        return max(1, $first);
    }
}
