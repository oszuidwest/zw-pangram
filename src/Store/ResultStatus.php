<?php
/**
 * Result statuses.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Store;

/**
 * Stored scan outcome, independent of the queue state (see QueueStatus).
 */
enum ResultStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * Returns every stored value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
