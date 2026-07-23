<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Observability;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\NanoGptAiProvider\Observability\ActivityLoggingHttpTransporter;

class ActivityLoggingHttpTransporterTest extends TestCase
{
    public function testRecordsSuccessfulGenerationRequest(): void
    {
        $recorder = new FakeActivityRecorder();
        $response = new Response(200, [], '{"choices":[]}');
        $transporter = new ActivityLoggingHttpTransporter(
            new FakeHttpTransporter($response),
            $recorder
        );

        self::assertSame($response, $transporter->send($this->generationRequest()));
        self::assertCount(1, $recorder->records);
        self::assertSame($response, $recorder->records[0]['response']);
        self::assertNull($recorder->records[0]['error']);
        self::assertGreaterThanOrEqual(0, $recorder->records[0]['duration']);
    }

    public function testRecordsTransportErrorAndRethrowsIt(): void
    {
        $error = new RuntimeException('Network failed');
        $recorder = new FakeActivityRecorder();
        $transporter = new ActivityLoggingHttpTransporter(
            new FakeHttpTransporter(null, $error),
            $recorder
        );

        try {
            $transporter->send($this->generationRequest());
            self::fail('Expected transport error.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertCount(1, $recorder->records);
        self::assertSame($error, $recorder->records[0]['error']);
        self::assertNull($recorder->records[0]['response']);
    }

    public function testDoesNotRecordCatalogRequestsOrDisabledLogging(): void
    {
        $recorder = new FakeActivityRecorder();
        $transporter = new ActivityLoggingHttpTransporter(new FakeHttpTransporter(), $recorder);
        $catalog = new Request(
            HttpMethodEnum::GET(),
            'https://nano-gpt.com/api/v1/models?detailed=true'
        );

        $transporter->send($catalog);
        $recorder->enabled = false;
        $transporter->send($this->generationRequest());

        self::assertSame([], $recorder->records);
    }

    public function testDoesNotRecordAnotherProvidersCompatibleEndpoint(): void
    {
        $recorder = new FakeActivityRecorder();
        $transporter = new ActivityLoggingHttpTransporter(new FakeHttpTransporter(), $recorder);
        $otherProvider = new Request(
            HttpMethodEnum::POST(),
            'https://example.com/api/v1/chat/completions'
        );

        $transporter->send($otherProvider);

        self::assertSame([], $recorder->records);
    }

    public function testLoggingFailureDoesNotBreakGeneration(): void
    {
        $recorder = new FakeActivityRecorder();
        $recorder->throwOnRecord = true;
        $response = new Response(200, [], '{}');
        $transporter = new ActivityLoggingHttpTransporter(
            new FakeHttpTransporter($response),
            $recorder
        );

        self::assertSame($response, $transporter->send($this->generationRequest()));
    }

    private function generationRequest(): Request
    {
        return new Request(
            HttpMethodEnum::POST(),
            'https://nano-gpt.com/api/v1/chat/completions',
            ['Content-Type' => 'application/json'],
            ['model' => 'test-model', 'messages' => []]
        );
    }
}
