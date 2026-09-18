<?php
/**
 * Results filters.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\ResultStatus;
use ZWPangram\Support\Dates;

/**
 * Validated result filters shared by queries, statistics, and exports.
 */
final readonly class ResultsFilters
{
    public const ORDERBY = ['fraction_ai', 'date', 'scanned_at'];
    public const LABELS = ['AI', 'Mixed', 'Human'];

    /**
     * Creates result-filter state.
     *
     * @param string|null $label   AI|Mixed|Human.
     * @param int         $author  Author ID or 0.
     * @param string|null $status  ok|failed|skipped.
     * @param bool        $stale   Only stale results.
     * @param string|null $from    Y-m-d.
     * @param string|null $to      Y-m-d.
     * @param string      $search  Title search.
     * @param string      $orderby fraction_ai|date|scanned_at.
     * @param string      $order   ASC|DESC.
     */
    public function __construct(
        public ?string $label,
        public int $author,
        public ?string $status,
        public bool $stale,
        public ?string $from,
        public ?string $to,
        public string $search,
        public string $orderby,
        public string $order,
    ) {
    }

    /**
     * Validates and normalizes request values.
     *
     * @param array<string, mixed> $input Request values.
     */
    public static function fromRequest(array $input): self
    {
        $label = isset($input['label']) && is_string($input['label']) && in_array($input['label'], self::LABELS, true) ? $input['label'] : null;
        $status = isset($input['status']) && is_string($input['status']) ? ResultStatus::tryFrom($input['status'])?->value : null;
        $orderby = isset($input['orderby']) && is_string($input['orderby']) && in_array($input['orderby'], self::ORDERBY, true) ? $input['orderby'] : 'date';
        $order = isset($input['order']) && is_string($input['order']) && strtoupper($input['order']) === 'ASC' ? 'ASC' : 'DESC';
        [$from, $to] = Dates::orderedRange($input['from'] ?? null, $input['to'] ?? null);
        return new self(
            $label,
            isset($input['author']) && is_numeric($input['author']) ? max(0, (int) $input['author']) : 0,
            $status,
            !empty($input['stale']),
            $from,
            $to,
            isset($input['s']) && is_string($input['s']) ? sanitize_text_field($input['s']) : '',
            $orderby,
            $order,
        );
    }

    /**
     * Returns populated fields as query arguments.
     *
     * @return array<string, string|int>
     */
    public function toArgs(): array
    {
        $optional = [
            'label' => $this->label,
            'author' => $this->author > 0 ? $this->author : null,
            'status' => $this->status,
            'stale' => $this->stale ? 1 : null,
            'from' => $this->from,
            'to' => $this->to,
            's' => $this->search !== '' ? $this->search : null,
        ];
        return array_filter($optional, static fn ($v): bool => $v !== null) + ['orderby' => $this->orderby, 'order' => $this->order];
    }
}
