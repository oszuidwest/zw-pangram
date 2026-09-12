<?php
/**
 * Validates scan filters.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Queue;

use ZWPangram\Support\Dates;
use ZWPangram\Support\Settings;

/**
 * Limits scan selection to supported values.
 */
final readonly class ScanFilters
{
    /**
     * Stores a validated selection.
     *
     * @param list<string> $postTypes    Post types (subset of configured).
     * @param list<string> $postStatuses Post statuses (subset of configured).
     * @param string|null  $dateFrom     Y-m-d inclusive.
     * @param string|null  $dateTo       Y-m-d inclusive.
     * @param list<int>    $authors      Author IDs.
     * @param list<int>    $categories   Category term IDs.
     * @param bool         $force        Rescan unchanged content.
     */
    public function __construct(
        public array $postTypes,
        public array $postStatuses,
        public ?string $dateFrom,
        public ?string $dateTo,
        public array $authors,
        public array $categories,
        public bool $force,
    ) {
    }

    /**
     * Builds request filters limited to configured values.
     *
     * @param array<string, mixed> $input Raw request values.
     */
    public static function fromRequest(array $input): self
    {
        $settings = Settings::get();
        [$from, $to] = Dates::orderedRange($input['date_from'] ?? null, $input['date_to'] ?? null);

        return new self(
            Settings::subsetOrAll($input['post_types'] ?? null, $settings['post_types'], $settings['post_types']),
            Settings::subsetOrAll($input['post_statuses'] ?? null, $settings['post_statuses'], $settings['post_statuses']),
            $from,
            $to,
            self::ints($input['authors'] ?? null),
            self::ints($input['categories'] ?? null),
            !empty($input['force']),
        );
    }

    /**
     * Returns unique positive integers from a mixed list.
     *
     * @param mixed $value Raw value.
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }
}
