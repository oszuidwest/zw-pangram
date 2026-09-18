<?php
/**
 * Builds bulk request payloads.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Store\QueueStatus;
use ZWPangram\Store\ResultStatus;
use ZWPangram\Support\Settings;
use ZWPangram\Support\Text;
use ZWPangram\Support\UnitEstimator;

/**
 * Selects claimed rows within scope and request limits.
 *
 * @phpstan-import-type Row from ItemsRepository
 * @phpstan-type Snapshot array{hash: string, modified_gmt: string, text: string, units: int}
 */
final class BatchBuilder
{
    public const BYTE_CAP = 4 * 1024 * 1024;
    public const CAP_OPTION = 'zw_pangram_unit_cap';
    public const MIN_CAP = 1;

    /**
     * Creates a batch builder.
     *
     * @param ItemsRepository $repo Repository.
     */
    public function __construct(private readonly ItemsRepository $repo)
    {
    }

    /** Returns the adaptive batch cap. */
    public static function cap(): int
    {
        $cap = get_option(self::CAP_OPTION, UnitEstimator::DEFAULT_BATCH_CAP);
        // The option may contain either one cap or caps keyed by model.
        if (is_array($cap)) {
            $cap = $cap[Settings::MODEL] ?? UnitEstimator::DEFAULT_BATCH_CAP;
        }
        return is_numeric($cap) ? max(self::MIN_CAP, min(UnitEstimator::DEFAULT_BATCH_CAP, (int) $cap)) : UnitEstimator::DEFAULT_BATCH_CAP;
    }

    /**
     * Stores the adaptive batch cap.
     *
     * @param int $cap Unit cap.
     */
    public static function setCap(int $cap): void
    {
        update_option(self::CAP_OPTION, max(self::MIN_CAP, min(UnitEstimator::DEFAULT_BATCH_CAP, $cap)), false);
    }

    /**
     * Builds one request while settling or releasing unused claims.
     *
     * @param list<Row>                  $rows     Claimed rows (ordered by ID).
     * @param array<string, mixed>       $settings Settings::get().
     * @param string                     $token    Claim token.
     * @param int                        $cap      Adaptive unit cap.
     * @return array<int, Snapshot> Snapshots keyed by post ID, in claim order.
     */
    public function build(array $rows, array $settings, string $token, int $cap): array
    {
        $snapshots = [];
        $units = 0;
        $bytes = 64;
        $release = [];
        $stopped = false;
        _prime_post_caches(array_column($rows, 'post_id'), false, false);

        foreach ($rows as $row) {
            if ($row['queue_status'] !== QueueStatus::Processing->value) {
                continue;
            }
            if ($stopped) {
                $release[] = $row['post_id'];
                continue;
            }

            $post = get_post($row['post_id']);
            $reason = $this->outOfScope($post, $settings);
            if ($reason !== null) {
                $this->repo->markSkippedInQueue($row, $reason);
                continue;
            }
            /** @var \WP_Post $post */
            $text = Text::prepare($post->post_content);
            $words = Text::wordCount($text);
            $hash = Text::hash($text);

            if ($words < (int) $settings['min_words']) {
                $this->repo->writeSkipped($row, sprintf('Too short: %d words, minimum is %d.', $words, (int) $settings['min_words']));
                continue;
            }
            if (!$row['force'] && $row['result_status'] === ResultStatus::Ok->value && !$row['result_stale'] && $row['result_hash'] === $hash) {
                $this->repo->markSkippedInQueue($row, 'unchanged');
                continue;
            }

            $itemUnits = UnitEstimator::units($words);
            if ($itemUnits > UnitEstimator::HARD_UNIT_LIMIT) {
                $this->repo->writeFailed($row, sprintf('Too long for the API: %d units, the limit is %d.', $itemUnits, UnitEstimator::HARD_UNIT_LIMIT));
                continue;
            }

            $itemBytes = strlen((string) wp_json_encode(['id' => 'post-' . $row['post_id'], 'text' => $text])) + 2;
            $first = $snapshots === [];
            if (!$first && ($units + $itemUnits > $cap || $bytes + $itemBytes > self::BYTE_CAP)) {
                $release[] = $row['post_id'];
                $stopped = true;
                continue;
            }

            $snapshots[$row['post_id']] = [
                'hash' => $hash,
                'modified_gmt' => $post->post_modified_gmt,
                'text' => $text,
                'units' => $itemUnits,
            ];
            $units += $itemUnits;
            $bytes += $itemBytes;
        }

        if ($release !== []) {
            $this->repo->release($token, $release);
        }
        return $snapshots;
    }

    /**
     * Builds API items from staged snapshots in claim order.
     *
     * @param array<int, Row>      $staged    Staged rows keyed by post ID.
     * @param array<int, Snapshot> $snapshots Snapshots keyed by post ID.
     * @return list<array{id: string, text: string}>
     */
    public static function itemsFor(array $staged, array $snapshots): array
    {
        $items = [];
        foreach ($snapshots as $postId => $snapshot) {
            if (isset($staged[$postId])) {
                $items[] = ['id' => 'post-' . $postId, 'text' => $snapshot['text']];
            }
        }
        return $items;
    }

    /**
     * Totals the units for staged snapshots.
     *
     * @param array<int, Row>      $staged    Staged rows.
     * @param array<int, Snapshot> $snapshots Snapshots.
     */
    public static function unitsFor(array $staged, array $snapshots): int
    {
        $units = 0;
        foreach ($snapshots as $postId => $snapshot) {
            if (isset($staged[$postId])) {
                $units += $snapshot['units'];
            }
        }
        return $units;
    }

    /**
     * Returns why a post is outside the configured scan scope.
     *
     * @param \WP_Post|null        $post     Post.
     * @param array<string, mixed> $settings Settings.
     */
    private function outOfScope(?\WP_Post $post, array $settings): ?string
    {
        if ($post === null) {
            return 'Post no longer exists.';
        }
        if (in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            return 'Post is trashed or an auto-draft.';
        }
        if (!in_array($post->post_type, (array) $settings['post_types'], true)) {
            return 'Post type is not enabled in the settings.';
        }
        if (!in_array($post->post_status, (array) $settings['post_statuses'], true)) {
            return 'Post status is not enabled in the settings.';
        }
        if ($post->post_password !== '' && empty($settings['include_password_protected'])) {
            return 'Password-protected posts are excluded in the settings.';
        }
        return null;
    }
}
