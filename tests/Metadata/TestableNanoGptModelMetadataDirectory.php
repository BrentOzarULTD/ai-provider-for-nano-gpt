<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Metadata;

use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadata;
use WordPress\NanoGptAiProvider\Metadata\NanoGptModelMetadataDirectory;

class TestableNanoGptModelMetadataDirectory extends NanoGptModelMetadataDirectory
{
    /**
     * @param array<string, bool> $subscriptionIds Included IDs.
     * @return list<NanoGptModelMetadata> Models.
     */
    public function parseText(Response $response, array $subscriptionIds = []): array
    {
        return $this->parseTextModels($response, $subscriptionIds);
    }

    /**
     * @return list<NanoGptModelMetadata> Models.
     */
    public function parseImages(Response $response): array
    {
        return $this->parseImageModels($response);
    }
}
