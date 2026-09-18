<?php
/**
 * Queue statuses.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Store;

/**
 * Queue state of an item.
 *
 * The queue_status column tracks the work in flight and result_status (ResultStatus) the last stored outcome. Both are
 * orthogonal: a row can be Pending for a rescan, or Skipped as unchanged, while result_status still holds the ok
 * result of the previous scan. ItemsRepository owns every transition; each of its methods documents its own.
 */
enum QueueStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Processing = 'processing';
    case Submitted = 'submitted';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
