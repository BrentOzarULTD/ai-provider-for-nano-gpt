<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Observability;

use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

class FakeHttpTransporter implements HttpTransporterInterface
{
    private ?Response $response;
    private ?Throwable $error;

    public function __construct(?Response $response = null, ?Throwable $error = null)
    {
        $this->response = $response;
        $this->error = $error;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->response ?? new Response(200, [], '{}');
    }
}
