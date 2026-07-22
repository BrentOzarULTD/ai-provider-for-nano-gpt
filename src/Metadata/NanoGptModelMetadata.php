<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Metadata;

use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Model metadata enriched with fields supplied by Nano-GPT.
 *
 * The base WordPress AI Client DTO intentionally exposes a small common set of
 * fields. These accessors retain Nano-GPT's useful selection metadata without
 * changing the common DTO's serialized shape.
 *
 * @since 1.0.0
 */
class NanoGptModelMetadata extends ModelMetadata
{
    /** @var bool Whether text requests are included in a Nano-GPT subscription. */
    private bool $subscriptionIncluded;

    /** @var string Human-friendly model family. */
    private string $family;

    /** @var int|null Model release timestamp. */
    private ?int $releasedAt;

    /** @var int|null Maximum input context size in tokens. */
    private ?int $contextLength;

    /** @var string Model category, such as text or image. */
    private string $category;

    /**
     * Constructor.
     *
     * @param string $id Model identifier.
     * @param string $name Display name.
     * @param list<\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum> $supportedCapabilities Capabilities.
     * @param list<\WordPress\AiClient\Providers\Models\DTO\SupportedOption> $supportedOptions Options.
     * @param bool $subscriptionIncluded Whether requests are subscription-included.
     * @param string $family Model family.
     * @param int|null $releasedAt Release timestamp.
     * @param int|null $contextLength Context size in tokens.
     * @param string $category Model category.
     */
    public function __construct(
        string $id,
        string $name,
        array $supportedCapabilities,
        array $supportedOptions,
        bool $subscriptionIncluded,
        string $family,
        ?int $releasedAt,
        ?int $contextLength,
        string $category
    ) {
        parent::__construct($id, $name, $supportedCapabilities, $supportedOptions);

        $this->subscriptionIncluded = $subscriptionIncluded;
        $this->family = $family;
        $this->releasedAt = $releasedAt;
        $this->contextLength = $contextLength;
        $this->category = $category;
    }

    public function isSubscriptionIncluded(): bool
    {
        return $this->subscriptionIncluded;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getReleasedAt(): ?int
    {
        return $this->releasedAt;
    }

    public function getContextLength(): ?int
    {
        return $this->contextLength;
    }

    public function getCategory(): string
    {
        return $this->category;
    }
}
