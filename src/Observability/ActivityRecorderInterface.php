<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Observability;

use Throwable;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Receives completed Nano-GPT generation requests for optional persistence.
 *
 * @since 2.0.0
 */
interface ActivityRecorderInterface
{
    public function isEnabled(): bool;

    public function record(
        Request $request,
        ?Response $response,
        ?Throwable $error,
        int $durationMilliseconds
    ): void;
}
