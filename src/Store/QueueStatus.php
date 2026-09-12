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
