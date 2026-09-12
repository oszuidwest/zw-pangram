<?php
/**
 * Persists bulk results.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

use ZWPangram\Api\ResponseValidator;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Store\QueueStatus;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Text;

/**
 * Maps result items idempotently and detects stale content.
 */
final class ResultWriter
{
    /**
     * Creates a result writer.
     *
     * @param ItemsRepository $repo          Repository.
     * @param bool            $storeFullText Whether response text is retained.
     */
    public function __construct(private readonly ItemsRepository $repo, private readonly bool $storeFullText)
    {
    }

    /**
     * Writes every success and failure in one results page.
     *
     * @param array<string, mixed>       $job   Open job.
     * @param list<array<string, mixed>> $items Result and failed items.
     */
    public function writePage(array $job, array $items): void
    {
        $ids = [];
        foreach ($items as $item) {
            $postId = ResponseValidator::postIdFromItemId((string) $item['id']);
            if ($postId !== null) {
                $ids[] = $postId;
            }
        }
        $rows = $this->repo->findMany($ids);
        _prime_post_caches($ids, false, false);
        foreach ($items as $item) {
            $this->write($job, $item, $rows);
        }
    }

    /**
     * Writes one item and tracks same-page replays.
     *
     * @param array<string, mixed>             $job  Open job.
     * @param array<string, mixed>             $item Result or failed item.
     * @param array<int, array<string, mixed>> $rows Current rows keyed by post ID; updated so duplicate IDs read as replays.
     */
    private function write(array $job, array $item, array &$rows): void
    {
        $bulkId = (string) $job['bulk_id'];
        $itemId = (string) $item['id'];
        $postId = ResponseValidator::postIdFromItemId($itemId);
        if ($postId === null) {
            ErrorLog::add(sprintf('Ignored result with unexpected item id "%s".', $itemId), ['bulk_id' => $bulkId]);
            return;
        }

        $row = $rows[$postId] ?? null;
        if ($row === null) {
            ErrorLog::add('Ignored result for a post that no longer has a queue row (deleted during the job?).', ['bulk_id' => $bulkId, 'post_id' => $postId]);
            return;
        }
        if ($row['last_bulk_id'] === $bulkId) {
            return; // Ignore duplicate delivery for this bulk job.
        }
        if ($row['queue_status'] !== QueueStatus::Submitted->value || $row['bulk_id'] !== $bulkId) {
            ErrorLog::add('Ignored orphan result: row is not submitted in this job.', ['bulk_id' => $bulkId, 'post_id' => $postId]);
            return;
        }
        $rows[$postId]['last_bulk_id'] = $bulkId; // Prevent duplicate IDs in this page from being processed twice.

        $error = isset($item['error']) && is_string($item['error']) ? trim($item['error']) : '';
        $result = isset($item['result']) && is_array($item['result']) ? $item['result'] : null;
        if ($error !== '' || $result === null) {
            $this->fail($row, $error !== '' ? $error : 'Pangram returned no result for this item.', $bulkId);
            return;
        }
        if (!ResponseValidator::result($result)) {
            $this->fail($row, 'Pangram returned a result in an unexpected format.', $bulkId);
            return;
        }

        $stale = $this->contentChanged($row);
        $stored = $this->storeFullText ? $result : ItemsRepository::stripText($result);
        $json = wp_json_encode($stored);
        if ($json === false) {
            $this->fail($row, 'Unable to encode the result for storage.', $bulkId);
            return;
        }

        $this->repo->writeOk($row, [
            'prediction_short' => (string) $result['prediction_short'],
            'fraction_ai' => round((float) $result['fraction_ai'], 4),
            'fraction_ai_assisted' => round((float) $result['fraction_ai_assisted'], 4),
            'fraction_human' => round((float) $result['fraction_human'], 4),
            'headline' => isset($result['headline']) ? (string) $result['headline'] : null,
            'model' => (string) $job['model'],
            'api_version' => isset($result['version']) ? (string) $result['version'] : null,
            'response_json' => $json,
            'last_bulk_id' => $bulkId,
            'result_stale' => $stale,
        ], $this->requeueReason($row, $stale));
    }

    /**
     * Records a failure and requeues when another scan is needed.
     *
     * @param array<string, mixed> $row     Row.
     * @param string               $message Message.
     * @param string               $bulkId  Bulk ID.
     */
    private function fail(array $row, string $message, string $bulkId): void
    {
        $this->repo->writeFailed($row, $message, $bulkId, $this->requeueReason($row, $this->contentChanged($row)));
        ErrorLog::add($message, ['bulk_id' => $bulkId, 'post_id' => (int) $row['post_id']]);
    }

    /**
     * Returns why the row needs another scan.
     *
     * @param array<string, mixed> $row     Row.
     * @param bool                 $changed Whether the content changed since submission.
     */
    private function requeueReason(array $row, bool $changed): ?string
    {
        if ($changed) {
            return 'Content changed during scan.';
        }
        return !empty($row['rescan_requested']) ? 'Rescan requested.' : null;
    }

    /**
     * Checks whether the submitted content is stale.
     *
     * @param array<string, mixed> $row Row.
     */
    private function contentChanged(array $row): bool
    {
        $post = get_post((int) $row['post_id']);
        if (!$post instanceof \WP_Post) {
            return true;
        }
        return Text::hash(Text::prepare($post->post_content)) !== (string) $row['submitted_hash'];
    }
}
