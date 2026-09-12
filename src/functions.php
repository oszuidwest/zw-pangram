<?php
/**
 * Public read API.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use ZWPangram\Store\ItemsRepository;

if (!function_exists('zw_pangram_get_result')) {
    /**
     * Returns the latest stored Pangram result for a post.
     *
     * @param int $post_id Post ID.
     * @return array{
     *   status: string,
     *   label: string|null,
     *   headline: string|null,
     *   fraction_ai: float|null,
     *   fraction_ai_assisted: float|null,
     *   fraction_human: float|null,
     *   model: string|null,
     *   scanned_at: string|null,
     *   stale: bool,
     *   error: string|null
     * }|null
     */
    function zw_pangram_get_result(int $post_id): ?array
    {
        if ($post_id <= 0 || !ItemsRepository::tableExists()) {
            return null;
        }
        $row = (new ItemsRepository())->findMany([$post_id])[$post_id] ?? null;
        if ($row === null || $row['result_status'] === null) {
            return null;
        }
        return [
            'status' => $row['result_status'],
            'label' => $row['prediction_short'],
            'headline' => $row['headline'],
            'fraction_ai' => $row['fraction_ai'],
            'fraction_ai_assisted' => $row['fraction_ai_assisted'],
            'fraction_human' => $row['fraction_human'],
            'model' => $row['model'],
            'scanned_at' => $row['scanned_at'],
            'stale' => $row['result_stale'],
            'error' => $row['result_error'],
        ];
    }
}
