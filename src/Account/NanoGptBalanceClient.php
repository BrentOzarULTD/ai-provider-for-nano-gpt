<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Account;

use RuntimeException;

/**
 * Retrieves a Nano-GPT account balance through the WordPress HTTP API.
 *
 * @since 1.0.0
 */
class NanoGptBalanceClient
{
    private const ENDPOINT = 'https://nano-gpt.com/api/check-balance';

    /**
     * Fetches the current account balance.
     *
     * @throws RuntimeException If the request fails or returns invalid data.
     */
    public function fetch(string $apiKey): NanoGptBalance
    {
        if ($apiKey === '') {
            throw new RuntimeException('A Nano-GPT API key is required.');
        }
        if (!function_exists('wp_remote_post')) {
            throw new RuntimeException('The WordPress HTTP API is unavailable.');
        }

        $response = wp_remote_post(
            self::ENDPOINT,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                    'x-api-key' => $apiKey,
                ],
            ]
        );

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($data) && isset($data['message']) && is_string($data['message'])
                ? $data['message']
                : 'Nano-GPT returned HTTP ' . $statusCode . '.';
            throw new RuntimeException($message);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Nano-GPT returned an invalid balance response.');
        }

        return self::fromArray($data);
    }

    /**
     * Converts response data to a validated balance value.
     *
     * @param array<mixed> $data Response data.
     * @throws RuntimeException If required balance fields are missing.
     */
    public static function fromArray(array $data): NanoGptBalance
    {
        $usdBalance = $data['usd_balance'] ?? null;
        $nanoBalance = $data['nano_balance'] ?? null;

        if (!is_numeric($usdBalance) || !is_numeric($nanoBalance)) {
            throw new RuntimeException('Nano-GPT returned invalid balance values.');
        }

        return new NanoGptBalance((string) $usdBalance, (string) $nanoBalance);
    }
}
