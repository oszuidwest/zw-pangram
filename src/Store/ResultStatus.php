<?php
/**
 * Result statuses.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Store;

/**
 * Stored scan outcome.
 */
enum ResultStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
