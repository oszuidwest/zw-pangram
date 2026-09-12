<?php
/**
 * HTTP client for the Pangram bulk API.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Api;

use ZWPangram\Support\Text;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are escaped where they are displayed or logged.

/**
 * Sends Pangram requests through the WordPress HTTP API and validates responses.
 */
final class Client implements ClientInterface
{
    public const BASE_URL = 'https://text.external-api.pangram.com';
    public const SUBMIT_TIMEOUT = 60;
    public const READ_TIMEOUT = 30;
    public const MAX_BODY_BYTES = 1024;

    /**
     * Creates a client for an API key.
     *
     * @param string $apiKey Pangram API key.
     */
    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Lists available models via GET /models.
     *
     * @return list<string>
     * @throws ApiException On any failure.
     */
    public function models(): array
    {
        $data = $this->request('GET', '/models', null, self::READ_TIMEOUT);
        $models = ResponseValidator::models($data);
        if ($models === null) {
            throw new ApiException('Unexpected response format from GET /models.', 200);
        }
        return $models;
    }

    /**
     * Submits a bulk scan via POST /bulk.
     *
     * @param array{items: list<array{id: string, text: string}>, model: string} $payload Request body.
     * @return array<string, mixed>
     * @throws ApiException On any failure.
     */
    public function submitBulk(array $payload): array
    {
        $data = $this->request('POST', '/bulk', $payload, self::SUBMIT_TIMEOUT);
        if (!ResponseValidator::bulkAccepted($data)) {
            throw new ApiException('Unexpected response format from POST /bulk.', 200);
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Gets bulk-job status via GET /bulk/{id}.
     *
     * @param string $bulkId Job ID.
     * @return array<string, mixed>
     * @throws ApiException On any failure.
     */
    public function getBulk(string $bulkId): array
    {
        $data = $this->request('GET', '/bulk/' . rawurlencode($bulkId), null, self::READ_TIMEOUT);
        if (!ResponseValidator::bulkStatus($data)) {
            throw new ApiException('Unexpected response format from GET /bulk/{id}.', 200);
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Gets a page of bulk results via GET /bulk/{id}/results.
     *
     * @param string $bulkId Job ID.
     * @param int    $offset Offset.
     * @param int    $limit  Page size.
     * @return array<string, mixed>
     * @throws ApiException On any failure.
     */
    public function getBulkResults(string $bulkId, int $offset, int $limit): array
    {
        $path = sprintf('/bulk/%s/results?offset=%d&limit=%d', rawurlencode($bulkId), max(0, $offset), max(1, $limit));
        $data = $this->request('GET', $path, null, self::READ_TIMEOUT);
        if (!ResponseValidator::resultsPage($data)) {
            throw new ApiException('Unexpected response format from GET /bulk/{id}/results.', 200);
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Performs a request and decodes JSON.
     *
     * @param string                    $method  HTTP method.
     * @param string                    $path    Path with leading slash.
     * @param array<string, mixed>|null $body    JSON body.
     * @param int                       $timeout Seconds.
     * @return mixed Decoded JSON.
     * @throws ApiException On transport error, non-2xx status or invalid JSON.
     */
    private function request(string $method, string $path, ?array $body, int $timeout): mixed
    {
        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'headers' => [
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => 'zw-pangram/' . \ZW_PANGRAM_VERSION . '; ' . home_url('/'),
            ],
        ];
        if ($body !== null) {
            $encoded = wp_json_encode($body);
            if ($encoded === false) {
                throw new ApiException('Unable to encode request body.', 0);
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded;
        }

        $response = wp_remote_request(self::BASE_URL . $path, $args);
        if (is_wp_error($response)) {
            throw new ApiException($this->redact($response->get_error_message()), 0);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            throw new ApiException($this->messageFor($status, $raw), $status, $this->redact(Text::clip($raw, self::MAX_BODY_BYTES)));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException('Invalid JSON in Pangram response.', $status, $this->redact(Text::clip($raw, self::MAX_BODY_BYTES)));
        }
        return $decoded;
    }

    /**
     * Builds a redacted message from an error response.
     *
     * @param int    $status HTTP status.
     * @param string $raw    Raw body.
     */
    private function messageFor(int $status, string $raw): string
    {
        $decoded = json_decode($raw, true);
        $detail = '';
        if (is_array($decoded)) {
            foreach (['error', 'detail', 'message'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $detail = $decoded[$key];
                    break;
                }
            }
        }
        if ($detail === '') {
            $detail = Text::clip($raw, self::MAX_BODY_BYTES);
        }
        return $this->redact(sprintf('Pangram API returned HTTP %d: %s', $status, $detail));
    }

    /**
     * Removes the API key from text.
     *
     * @param string $text Text.
     */
    private function redact(string $text): string
    {
        return $this->apiKey === '' ? $text : str_replace($this->apiKey, '[redacted]', $text);
    }
}
