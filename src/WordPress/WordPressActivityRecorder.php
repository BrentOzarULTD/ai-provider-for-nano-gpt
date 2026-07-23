<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

use Throwable;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\NanoGptAiProvider\Observability\ActivityContentSanitizer;
use WordPress\NanoGptAiProvider\Observability\ActivityRecorderInterface;

/**
 * Converts Nano-GPT requests and responses into privacy-aware local records.
 *
 * @since 2.0.0
 */
class WordPressActivityRecorder implements ActivityRecorderInterface
{
    private ActivityRepository $repository;

    public function __construct(?ActivityRepository $repository = null)
    {
        $this->repository = $repository ?? new ActivityRepository();
    }

    public function isEnabled(): bool
    {
        return ActivitySettings::isEnabled();
    }

    public function record(
        Request $request,
        ?Response $response,
        ?Throwable $error,
        int $durationMilliseconds
    ): void {
        $requestData = $this->decodeJson($request->getBody());
        $responseData = $response === null ? [] : $this->decodeJson($response->getBody());
        $urlParts = self::urlParts($request->getUri());
        $path = isset($urlParts['path']) && is_string($urlParts['path']) ? $urlParts['path'] : '';
        $capability = substr($path, -19)
            === '/images/generations'
                ? 'image'
                : 'text';
        $model = isset($requestData['model']) && is_string($requestData['model'])
            ? $requestData['model']
            : '';
        $httpStatus = $response === null ? null : $response->getStatusCode();
        $invalidResponse = $response !== null
            && $response->isSuccessful()
            && !$this->hasExpectedResult($responseData, $capability);
        $isError = $error !== null
            || ($response !== null && !$response->isSuccessful())
            || $invalidResponse;
        $errorDetails = $this->errorDetails($responseData, $error, $httpStatus);
        if ($invalidResponse && $errorDetails['message'] === '') {
            $errorDetails = [
                'code' => 'invalid_response',
                'message' => 'Nano-GPT returned a successful HTTP status without generation results.',
            ];
        }
        $context = ActivityContextDetector::detect();
        $usage = isset($responseData['usage']) && is_array($responseData['usage'])
            ? $responseData['usage']
            : [];

        $prompt = [];
        $result = [];
        $requestConfig = [];
        if (ActivitySettings::capturesContent()) {
            $prompt = $capability === 'image'
                ? ['prompt' => $requestData['prompt'] ?? '']
                : ['messages' => $requestData['messages'] ?? []];
            $requestConfig = $requestData;
            unset($requestConfig['model'], $requestConfig['prompt'], $requestConfig['messages']);
            $result = $responseData;

            $prompt = apply_filters('nanogpt_activity_log_value', $prompt, 'prompt');
            $requestConfig = apply_filters('nanogpt_activity_log_value', $requestConfig, 'request_config');
            $result = apply_filters('nanogpt_activity_log_value', $result, 'response');
        }

        $this->repository->insert([
            'created_at_gmt' => current_time('mysql', true),
            'duration_ms' => $durationMilliseconds,
            'model' => $model,
            'capability' => $capability,
            'status' => $isError ? 'error' : 'success',
            'http_status' => $httpStatus,
            'source_type' => $context['source_type'],
            'source_name' => $context['source_name'],
            'source_detail' => $context['source_detail'],
            'request_context' => ActivityContentSanitizer::encode($context['request_context']),
            'request_config' => ActivityContentSanitizer::encode($requestConfig),
            'prompt' => ActivityContentSanitizer::encode($prompt),
            'response' => ActivityContentSanitizer::encode($result),
            'error_code' => $errorDetails['code'],
            'error_message' => $errorDetails['message'],
            'input_tokens' => $this->nullableInteger($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
            'output_tokens' => $this->nullableInteger(
                $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null
            ),
            'total_tokens' => $this->nullableInteger($usage['total_tokens'] ?? null),
            'user_id' => $this->nullableInteger($context['request_context']['user_id'] ?? null) ?? 0,
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }
        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $responseData Response data.
     * @return array{code: string, message: string}
     */
    private function errorDetails(array $responseData, ?Throwable $error, ?int $httpStatus): array
    {
        $code = $httpStatus === null ? '' : (string) $httpStatus;
        $message = $error === null ? '' : $error->getMessage();
        $apiError = $responseData['error'] ?? null;
        if (is_array($apiError)) {
            if (isset($apiError['code']) && is_scalar($apiError['code'])) {
                $code = (string) $apiError['code'];
            }
            if (isset($apiError['message']) && is_string($apiError['message'])) {
                $message = $apiError['message'];
            }
        } elseif (is_string($apiError)) {
            $message = $apiError;
        }

        return [
            'code' => sanitize_text_field($code),
            'message' => sanitize_textarea_field($message),
        ];
    }

    /**
     * @param mixed $value Value.
     */
    private function nullableInteger($value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /**
     * @param array<string, mixed> $responseData Response data.
     */
    private function hasExpectedResult(array $responseData, string $capability): bool
    {
        $field = $capability === 'image' ? 'data' : 'choices';

        return isset($responseData[$field])
            && is_array($responseData[$field])
            && $responseData[$field] !== [];
    }
}
