<?php
/**
 * Date parsing helpers.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

/**
 * Validates and formats dates for admin filters.
 */
final class Dates
{
    /**
     * Validates a Y-m-d value.
     *
     * @param mixed $value Raw input.
     */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $value;
    }

    /**
     * Formats an inclusive post_date lower bound.
     *
     * @param string $date Y-m-d.
     */
    public static function startOfDay(string $date): string
    {
        return $date . ' 00:00:00';
    }

    /**
     * Formats an inclusive post_date upper bound.
     *
     * @param string $date Y-m-d.
     */
    public static function endOfDay(string $date): string
    {
        return $date . ' 23:59:59';
    }

    /**
     * Orders an inclusive date range.
     *
     * @param mixed $from Raw lower bound.
     * @param mixed $to   Raw upper bound.
     * @return array{0: string|null, 1: string|null}
     */
    public static function orderedRange(mixed $from, mixed $to): array
    {
        $from = self::parse($from);
        $to = self::parse($to);
        return $from !== null && $to !== null && $from > $to ? [$to, $from] : [$from, $to];
    }

    /** Returns the current UTC time in MySQL format. */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Returns an offset UTC time in MySQL format.
     *
     * @param int $seconds Offset in seconds.
     */
    public static function utcIn(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }
}
