<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Observability;

use RuntimeException;
use Throwable;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\NanoGptAiProvider\Observability\ActivityRecorderInterface;

class FakeActivityRecorder implements ActivityRecorderInterface
{
    public bool $enabled = true;
    public bool $throwOnRecord = false;

    /** @var list<array{request: Request, response: Response|null, error: Throwable|null, duration: int}> */
    public array $records = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function record(
        Request $request,
        ?Response $response,
        ?Throwable $error,
        int $durationMilliseconds
    ): void {
        if ($this->throwOnRecord) {
            throw new RuntimeException('Logging failed');
        }
        $this->records[] = [
            'request' => $request,
            'response' => $response,
            'error' => $error,
            'duration' => $durationMilliseconds,
        ];
    }
}
