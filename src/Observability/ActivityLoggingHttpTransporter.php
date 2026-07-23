<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Observability;

use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Records Nano-GPT generation traffic while leaving transport behavior intact.
 *
 * @since 2.0.0
 */
class ActivityLoggingHttpTransporter implements HttpTransporterInterface
{
    private HttpTransporterInterface $inner;
    private ActivityRecorderInterface $recorder;

    public function __construct(HttpTransporterInterface $inner, ActivityRecorderInterface $recorder)
    {
        $this->inner = $inner;
        $this->recorder = $recorder;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        if (!$this->isGenerationRequest($request) || !$this->recorder->isEnabled()) {
            return $this->inner->send($request, $options);
        }

        $startedAt = hrtime(true);
        try {
            $response = $this->inner->send($request, $options);
            $this->recordSafely($request, $response, null, $startedAt);

            return $response;
        } catch (Throwable $error) {
            $this->recordSafely($request, null, $error, $startedAt);
            throw $error;
        }
    }

    private function isGenerationRequest(Request $request): bool
    {
        $parts = self::urlParts($request->getUri());
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        if ($host !== 'nano-gpt.com' || strpos($path, '/api/v1/') === false) {
            return false;
        }

        return substr($path, -17) === '/chat/completions'
            || substr($path, -19) === '/images/generations';
    }

    /**
     * Uses WordPress URL parsing while retaining Composer-library compatibility.
     *
     * @return array<string, mixed> Parsed URL components.
     */
    private static function urlParts(string $url): array
    {
        if (function_exists('wp_parse_url')) {
            $parts = wp_parse_url($url);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone Composer fallback.
            $parts = parse_url($url);
        }

        return is_array($parts) ? $parts : [];
    }

    private function recordSafely(
        Request $request,
        ?Response $response,
        ?Throwable $error,
        int $startedAt
    ): void {
        $duration = max(0, (int) round((hrtime(true) - $startedAt) / 1000000));
        try {
            $this->recorder->record($request, $response, $error, $duration);
        } catch (Throwable $loggingError) {
            // Observability must never break the AI request it observes.
        }
    }
}
