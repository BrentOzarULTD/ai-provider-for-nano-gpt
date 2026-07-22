<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\NanoGptAiProvider\Models\NanoGptTextGenerationModel;

class TestableNanoGptTextGenerationModel extends NanoGptTextGenerationModel
{
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
