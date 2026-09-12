<?php
/**
 * Estimates Pangram billable units.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

/**
 * Converts word counts to Pangram 4 billable units.
 */
final class UnitEstimator
{
    /** Hard API limit of billable units per bulk request. */
    public const HARD_UNIT_LIMIT = 1000;

    /** Initial and maximum adaptive batch cap. */
    public const DEFAULT_BATCH_CAP = 900;

    /** Words per Pangram 4 billable unit. */
    public const WORDS_PER_UNIT = 100;

    /**
     * Returns the words per billable unit.
     */
    public static function wordsPerUnit(): int
    {
        return self::WORDS_PER_UNIT;
    }

    /**
     * Calculates billable units, with a minimum of one.
     *
     * @param int $words Word count of the prepared text.
     */
    public static function units(int $words): int
    {
        return max(1, (int) ceil($words / self::wordsPerUnit()));
    }
}
