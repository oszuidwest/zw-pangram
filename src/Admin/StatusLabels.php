<?php
/**
 * Translated status labels for wp-admin.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

/**
 * Keeps stored and API status values stable while localizing their display.
 */
final class StatusLabels
{
    /**
     * Returns the display label for a result status.
     *
     * @param string $status Stored status.
     */
    public static function result(string $status): string
    {
        return match ($status) {
            'ok' => __('OK', 'zw-pangram'),
            'failed' => __('Failed', 'zw-pangram'),
            'skipped' => __('Skipped', 'zw-pangram'),
            default => $status,
        };
    }

    /**
     * Returns the display label for a queue status.
     *
     * @param string $status Stored status.
     */
    public static function queue(string $status): string
    {
        return match ($status) {
            'pending' => __('Pending', 'zw-pangram'),
            'processing' => __('Processing', 'zw-pangram'),
            'submitted' => __('Submitted', 'zw-pangram'),
            'done' => __('Done', 'zw-pangram'),
            'failed' => __('Failed', 'zw-pangram'),
            'skipped' => __('Skipped', 'zw-pangram'),
            default => $status,
        };
    }

    /**
     * Returns the display label for a bulk-job status.
     *
     * @param string $status API status.
     */
    public static function bulk(string $status): string
    {
        return match ($status) {
            'queued' => __('Queued', 'zw-pangram'),
            'running' => __('Running', 'zw-pangram'),
            'succeeded' => __('Succeeded', 'zw-pangram'),
            'failed' => __('Failed', 'zw-pangram'),
            'partial' => __('Partially completed', 'zw-pangram'),
            default => $status,
        };
    }
}
