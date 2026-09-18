<?php
/**
 * Queues posts for scanning.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Queue;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Store\QueueStatus;
use ZWPangram\Support\Dates;
use ZWPangram\Support\Settings;

/**
 * Enqueues matches page by page and skips unchanged content.
 */
final class Enqueuer
{
    public const PAGE_SIZE = 500;

    /**
     * Creates a queue service.
     *
     * @param ItemsRepository $repo Repository.
     */
    public function __construct(private readonly ItemsRepository $repo)
    {
    }

    /**
     * Enqueues posts matching validated filters.
     *
     * @param ScanFilters $filters Selection.
     * @return array{matched: int, enqueued: int, unchanged: int, already_queued: int}
     */
    public function enqueue(ScanFilters $filters): array
    {
        $settings = Settings::get();
        $totals = ['matched' => 0, 'enqueued' => 0, 'unchanged' => 0, 'already_queued' => 0];

        $args = [
            'post_type' => $filters->postTypes,
            'post_status' => $filters->postStatuses,
            'fields' => 'ids',
            'posts_per_page' => self::PAGE_SIZE,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ];
        if (!$settings['include_password_protected']) {
            $args['has_password'] = false;
        }
        if ($filters->authors !== []) {
            $args['author__in'] = $filters->authors;
        }
        if ($filters->categories !== []) {
            $args['category__in'] = $filters->categories;
        }
        if ($filters->dateFrom !== null || $filters->dateTo !== null) {
            $range = ['column' => 'post_date', 'inclusive' => true];
            if ($filters->dateFrom !== null) {
                $range['after'] = Dates::startOfDay($filters->dateFrom);
            }
            if ($filters->dateTo !== null) {
                $range['before'] = Dates::endOfDay($filters->dateTo);
            }
            $args['date_query'] = [$range];
        }

        $page = 1;
        while (true) {
            $args['paged'] = $page;
            $query = new \WP_Query($args);
            $ids = array_values(array_map('intval', $query->posts));
            if ($ids === []) {
                break;
            }
            $totals['matched'] += count($ids);

            if (!$filters->force) {
                $unchanged = $this->repo->unchangedPostIds($ids);
                $totals['unchanged'] += count($unchanged);
                $ids = array_values(array_diff($ids, $unchanged));
            }

            $queued = $this->repo->queuedStatuses($ids);
            $totals['already_queued'] += count($queued);
            if ($filters->force) {
                // Forced in-flight rows request a rescan; pending rows remain unchanged.
                $inFlight = array_keys(array_filter($queued, static fn (string $status): bool => $status !== QueueStatus::Pending->value));
                $this->repo->upsertPending($inFlight, true);
            }

            $ids = array_values(array_diff($ids, array_keys($queued)));
            $this->repo->upsertPending($ids, $filters->force);
            $totals['enqueued'] += count($ids);

            if (count($query->posts) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $totals;
    }
}
