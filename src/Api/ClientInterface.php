<?php
/**
 * Pangram client contract.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Api;

/**
 * Minimal surface of the Pangram bulk API used by the plugin.
 */
interface ClientInterface
{
    /**
     * Lists available models via GET /models.
     *
     * @return list<string>
     * @throws ApiException On any failure.
     */
    public function models(): array;

    /**
     * Submits a bulk scan via POST /bulk.
     *
     * @param array{items: list<array{id: string, text: string}>, model: string} $payload Request body.
     * @return array<string, mixed> Validated response.
     * @throws ApiException On any failure.
     */
    public function submitBulk(array $payload): array;

    /**
     * Gets bulk-job status via GET /bulk/{id}.
     *
     * @param string $bulkId Job ID.
     * @return array<string, mixed> Validated response.
     * @throws ApiException On any failure.
     */
    public function getBulk(string $bulkId): array;

    /**
     * Gets a page of bulk results via GET /bulk/{id}/results.
     *
     * @param string $bulkId Job ID.
     * @param int    $offset Offset.
     * @param int    $limit  Page size.
     * @return array<string, mixed> Validated response.
     * @throws ApiException On any failure.
     */
    public function getBulkResults(string $bulkId, int $offset, int $limit): array;
}
