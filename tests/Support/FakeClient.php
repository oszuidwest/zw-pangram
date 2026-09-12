<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Support;

use ZWPangram\Api\ApiException;
use ZWPangram\Api\ClientInterface;

final class FakeClient implements ClientInterface
{
    /** @var array<string, list<mixed>> */
    private array $queues = ['models' => [], 'submitBulk' => [], 'getBulk' => [], 'getBulkResults' => []];

    /** @var array<string, mixed> */
    private array $last = [];

    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    public function queue(string $method, mixed $response): self
    {
        $this->queues[$method][] = $response;
        return $this;
    }

    /** @return list<list<mixed>> */
    public function callsTo(string $method): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                $out[] = $call['args'];
            }
        }
        return $out;
    }

    public function models(): array
    {
        return $this->next('models', []);
    }

    public function submitBulk(array $payload): array
    {
        return $this->next('submitBulk', [$payload]);
    }

    public function getBulk(string $bulkId): array
    {
        return $this->next('getBulk', [$bulkId]);
    }

    public function getBulkResults(string $bulkId, int $offset, int $limit): array
    {
        return $this->next('getBulkResults', [$bulkId, $offset, $limit]);
    }

    /**
     * @param list<mixed> $args
     * @return array<mixed>
     */
    private function next(string $method, array $args): array
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        if ($this->queues[$method] !== []) {
            $this->last[$method] = array_shift($this->queues[$method]);
        }
        if (!array_key_exists($method, $this->last)) {
            throw new \LogicException("FakeClient: no response queued for {$method}().");
        }
        $response = $this->last[$method];
        if ($response instanceof ApiException) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            $response = $response(...$args);
        }
        return (array) $response;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function okItem(int $postId, array $overrides = []): array
    {
        return [
            'id' => 'post-' . $postId,
            'result' => array_replace([
                'text' => 'analyzed text',
                'version' => '4.0',
                'prediction_short' => 'AI',
                'fraction_ai' => 0.8,
                'fraction_ai_assisted' => 0.1,
                'fraction_human' => 0.1,
                'headline' => 'AI Detected',
                'windows' => [['text' => 'segment one', 'label' => 'AI Generated']],
            ], $overrides),
        ];
    }

    /** @return array<string, mixed> */
    public static function failedItem(int $postId, string $error = 'processing failed'): array
    {
        return ['id' => 'post-' . $postId, 'error' => $error];
    }

    /**
     * @param list<int> $postIds Accepted post IDs.
     * @param list<int> $failed  Rejected post IDs.
     * @return array<string, mixed>
     */
    public static function accepted(string $bulkId, array $postIds, array $failed = []): array
    {
        $accepted = [];
        foreach ($postIds as $id) {
            $accepted[] = ['id' => 'post-' . $id];
        }
        $failedItems = [];
        foreach ($failed as $id) {
            $failedItems[] = ['id' => 'post-' . $id, 'error' => 'rejected'];
        }
        return ['bulk_id' => $bulkId, 'status' => 'queued', 'total_items' => count($postIds) + count($failed), 'accepted_items' => $accepted, 'failed_items' => $failedItems];
    }

    /** @return array<string, mixed> */
    public static function status(string $status): array
    {
        return ['status' => $status];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $failed
     * @return array<string, mixed>
     */
    public static function page(array $items, array $failed = [], ?int $total = null): array
    {
        return ['total_items' => $total ?? (count($items) + count($failed)), 'items' => $items, 'failed_items' => $failed];
    }
}
