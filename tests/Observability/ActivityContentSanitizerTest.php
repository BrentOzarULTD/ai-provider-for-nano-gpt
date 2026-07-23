<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Observability;

use PHPUnit\Framework\TestCase;
use WordPress\NanoGptAiProvider\Observability\ActivityContentSanitizer;

class ActivityContentSanitizerTest extends TestCase
{
    public function testRedactsSecretsAndOmitsInlineMedia(): void
    {
        $encoded = ActivityContentSanitizer::encode([
            'authorization' => 'Bearer secret',
            'nested' => [
                'api_key' => 'secret',
                'access_token' => 'another secret',
                'client_secret' => 'client secret',
                'max_tokens' => 200,
                'image' => 'data:image/png;base64,abcdef',
                'b64_json' => 'abcdef',
            ],
        ]);

        self::assertStringNotContainsString('Bearer secret', $encoded);
        self::assertStringNotContainsString('"secret"', $encoded);
        self::assertStringNotContainsString('another secret', $encoded);
        self::assertStringNotContainsString('client secret', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
        self::assertStringContainsString('inline media omitted', $encoded);
        self::assertStringContainsString('"max_tokens": 200', $encoded);
    }
}
