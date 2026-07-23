<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Models;

use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\NanoGptAiProvider\Models\NanoGptImageGenerationModel;

class TestableNanoGptImageGenerationModel extends NanoGptImageGenerationModel
{
    public function size(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        return $this->prepareSizeParam($orientation, $aspectRatio);
    }

    /**
     * @param array<string, string|list<string>> $headers Headers.
     * @param string|array<string, mixed>|null $data Request data.
     */
    public function request(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return $this->createRequest($method, $path, $headers, $data);
    }
}
