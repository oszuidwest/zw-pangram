<?php
/**
 * Persists queue pause state.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Queue;

use ZWPangram\Support\Dates;

/**
 * Tracks the pause reason and whether the pause was automatic.
 *
 * @phpstan-type State array{paused: bool, reason: string, automatic: bool, paused_at: string}
 */
final class QueueState
{
    public const OPTION = 'zw_pangram_queue_state';

    /**
     * Returns the current state.
     *
     * @return State
     */
    public static function get(): array
    {
        $state = get_option(self::OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }
        return [
            'paused' => !empty($state['paused']),
            'reason' => isset($state['reason']) && is_string($state['reason']) ? $state['reason'] : '',
            'automatic' => !empty($state['automatic']),
            'paused_at' => isset($state['paused_at']) && is_string($state['paused_at']) ? $state['paused_at'] : '',
        ];
    }

    /** Returns whether submissions are paused. */
    public static function isPaused(): bool
    {
        return self::get()['paused'];
    }

    /**
     * Pauses queue submissions.
     *
     * @param string $reason    Human readable reason.
     * @param bool   $automatic Whether the plugin paused itself.
     */
    public static function pause(string $reason, bool $automatic): void
    {
        update_option(self::OPTION, ['paused' => true, 'reason' => $reason, 'automatic' => $automatic, 'paused_at' => Dates::nowUtc()], false);
    }

    /** Resumes queue submissions. */
    public static function resume(): void
    {
        update_option(self::OPTION, ['paused' => false, 'reason' => '', 'automatic' => false, 'paused_at' => ''], false);
    }
}
