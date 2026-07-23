<?php

/**
 * Scheduled live contract tests for Nano-GPT.
 *
 * This script intentionally prints no prompts, responses, balances, credentials,
 * generated media, or signed media URLs.
 */

declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\NanoGptAiProvider\Provider\NanoGptProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Minimal test-only transporter for the standalone PHP AI Client.
 */
class LiveCurlTransporter implements HttpTransporterInterface
{
    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $handle = curl_init($request->getUri());
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the HTTP client.');
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $responseHeaders = [];
        $requestOptions = $options ?? $request->getOptions();
        $timeout = $requestOptions !== null && $requestOptions->getTimeout() !== null
            ? (int) ceil($requestOptions->getTimeout())
            : 30;
        $connectTimeout = $requestOptions !== null && $requestOptions->getConnectTimeout() !== null
            ? (int) ceil($requestOptions->getConnectTimeout())
            : 10;

        curl_setopt_array(
            $handle,
            [
                CURLOPT_CUSTOMREQUEST => $request->getMethod()->value,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $line = trim($line);
                    if ($line === '' || strpos($line, ':') === false) {
                        return $length;
                    }

                    [$name, $value] = explode(':', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    if ($name !== '') {
                        $responseHeaders[$name][] = $value;
                    }

                    return $length;
                },
            ]
        );
        $body = $request->getBody();
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if (!is_string($responseBody) || $statusCode < 100) {
            throw new RuntimeException($error !== '' ? $error : 'Nano-GPT returned no response.');
        }

        return new Response($statusCode, $responseHeaders, $responseBody);
    }
}

/**
 * @return never
 */
function liveFailure(string $message): void
{
    fwrite(STDERR, 'Live integration failure: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function liveJsonRequest(
    string $method,
    string $url,
    array $headers,
    ?array $payload = null,
    int $timeout = 30
): array {
    $handle = curl_init($url);
    if ($handle === false) {
        liveFailure('Unable to initialize the HTTP client.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($payload !== null) {
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }
    curl_setopt_array($handle, $options);

    $body = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);

    if (!is_string($body)) {
        liveFailure($error !== '' ? $error : 'Nano-GPT returned no response body.');
    }

    $data = json_decode($body, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($data) && isset($data['message']) && is_string($data['message'])
            ? $data['message']
            : 'HTTP ' . $statusCode;
        liveFailure('Nano-GPT request failed: ' . $message);
    }
    if (!is_array($data)) {
        liveFailure('Nano-GPT returned invalid JSON.');
    }

    return $data;
}

$apiKey = getenv('NANOGPT_API_KEY');
if (!is_string($apiKey) || trim($apiKey) === '') {
    liveFailure('NANOGPT_API_KEY is not configured.');
}

$balance = liveJsonRequest(
    'POST',
    'https://nano-gpt.com/api/check-balance',
    [
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ]
);
if (!is_numeric($balance['usd_balance'] ?? null) || !is_numeric($balance['nano_balance'] ?? null)) {
    liveFailure('The balance response is missing numeric balance values.');
}
fwrite(STDOUT, 'Balance contract: OK' . PHP_EOL);

$registry = AiClient::defaultRegistry();
$registry->setHttpTransporter(new LiveCurlTransporter());
if (!$registry->hasProvider(NanoGptProvider::class)) {
    $registry->registerProvider(NanoGptProvider::class);
}

$textModelId = getenv('NANOGPT_LIVE_TEXT_MODEL');
$textModelId = is_string($textModelId) && $textModelId !== ''
    ? $textModelId
    : 'openai/gpt-oss-20b';
$textModel = $registry->getProviderModel('nanogpt', $textModelId);
$text = AiClient::prompt('Reply with the single token LIVE_OK.')
    ->usingModel($textModel)
    ->usingMaxTokens(256)
    ->generateText();
if (trim($text) === '') {
    liveFailure('Text generation returned an empty result.');
}
fwrite(STDOUT, 'Text generation contract (' . $textModelId . '): OK' . PHP_EOL);

$runImage = getenv('NANOGPT_LIVE_RUN_IMAGE');
if (!is_string($runImage) || !filter_var($runImage, FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDOUT, 'Image generation contract: skipped' . PHP_EOL);
    exit(0);
}

$imageModelId = getenv('NANOGPT_LIVE_IMAGE_MODEL');
$imageModelId = is_string($imageModelId) && $imageModelId !== ''
    ? $imageModelId
    : 'flux-2-klein-4b';
$imageModel = $registry->getProviderModel('nanogpt', $imageModelId);
$image = AiClient::prompt('A single small blue circle on a plain white background.')
    ->usingModel($imageModel)
    ->asOutputMediaAspectRatio('1:1')
    ->generateImage();

$validInlineImage = $image->isInline()
    && is_string($image->getBase64Data())
    && strlen($image->getBase64Data()) > 100;
$validRemoteImage = $image->isRemote()
    && is_string($image->getUrl())
    && filter_var($image->getUrl(), FILTER_VALIDATE_URL) !== false;
if (!$validInlineImage && !$validRemoteImage) {
    liveFailure('Image generation returned neither inline image data nor a remote URL.');
}
if (strpos($image->getMimeType(), 'image/') !== 0) {
    liveFailure('Image generation returned a non-image MIME type.');
}

fwrite(STDOUT, 'Image generation contract (' . $imageModelId . '): OK' . PHP_EOL);
