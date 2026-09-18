<?php
/**
 * Translated status labels for wp-admin.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\QueueStatus;
use ZWPangram\Store\ResultStatus;

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
        return match (ResultStatus::tryFrom($status)) {
            ResultStatus::Ok => __('OK', 'zw-pangram'),
            ResultStatus::Failed => __('Failed', 'zw-pangram'),
            ResultStatus::Skipped => __('Skipped', 'zw-pangram'),
            null => $status,
        };
    }

    /**
     * Returns the display label for a queue status.
     *
     * @param string $status Stored status.
     */
    public static function queue(string $status): string
    {
        return match (QueueStatus::tryFrom($status)) {
            QueueStatus::Pending => __('Pending', 'zw-pangram'),
            QueueStatus::Processing => __('Processing', 'zw-pangram'),
            QueueStatus::Submitted => __('Submitted', 'zw-pangram'),
            QueueStatus::Done => __('Done', 'zw-pangram'),
            QueueStatus::Failed => __('Failed', 'zw-pangram'),
            QueueStatus::Skipped => __('Skipped', 'zw-pangram'),
            QueueStatus::None, null => $status,
        };
    }

    /**
     * Returns the display label for a stored Pangram prediction.
     *
     * @param string $label Stored label: AI, Mixed or Human.
     */
    public static function label(string $label): string
    {
        return match ($label) {
            'AI' => __('AI', 'zw-pangram'),
            'Mixed' => __('Mix', 'zw-pangram'),
            'Human' => __('Human', 'zw-pangram'),
            default => $label,
        };
    }

    /**
     * Returns the display label for a Pangram segment (window) label.
     *
     * @param string $label API label.
     */
    public static function segment(string $label): string
    {
        return match ($label) {
            'AI-Generated' => __('AI-generated', 'zw-pangram'),
            'AI-Assisted' => __('AI-assisted', 'zw-pangram'),
            'Human Written' => __('Human-written', 'zw-pangram'),
            default => $label,
        };
    }

    /**
     * Returns the display label for a segment confidence level.
     *
     * @param string $confidence API confidence: High, Medium or Low.
     */
    public static function confidence(string $confidence): string
    {
        return match ($confidence) {
            'High' => __('High', 'zw-pangram'),
            'Medium' => __('Medium', 'zw-pangram'),
            'Low' => __('Low', 'zw-pangram'),
            default => $confidence,
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
