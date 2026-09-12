<?php
/**
 * Schedules queue processing.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Cron;

/**
 * Defines the recurrence, callback, and schedule health.
 */
final class Scheduler
{
    public const SCHEDULE = 'zw_pangram_every_minute';
    public const EVENT = 'zw_pangram_tick';
    public const LAST_TICK_OPTION = 'zw_pangram_last_tick';
    public const SCHEDULE_ERROR_OPTION = 'zw_pangram_schedule_error';
    public const STALE_AFTER = 600;

    /**
     * Provides the recurrence required during activation.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Registered intervals.
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => did_action('init') ? __('Every minute (ZuidWest Pangram)', 'zw-pangram') : 'Every minute (ZuidWest Pangram)',
        ];
        return $schedules;
    }

    /** Registers the callback and restores missing events in admin requests. */
    public static function register(): void
    {
        add_action(self::EVENT, [self::class, 'runTick']);
        if (is_admin()) {
            add_action('init', [self::class, 'ensureScheduled']);
        }
    }

    /** Schedules a missing event and records failures. */
    public static function ensureScheduled(): void
    {
        if (wp_next_scheduled(self::EVENT) !== false) {
            return;
        }
        $result = wp_schedule_event(time(), self::SCHEDULE, self::EVENT, [], true);
        if (is_wp_error($result)) {
            update_option(self::SCHEDULE_ERROR_OPTION, $result->get_error_message(), false);
            return;
        }
        delete_option(self::SCHEDULE_ERROR_OPTION);
    }

    /** Runs the scheduled queue tick. */
    public static function runTick(): void
    {
        Tick::create()->run();
    }

    /** Records the latest tick time. */
    public static function recordTick(): void
    {
        update_option(self::LAST_TICK_OPTION, time(), false);
    }

    /**
     * Returns schedule health for admin notices.
     *
     * @return array{cron_disabled: bool, last_tick: int, stale: bool, schedule_error: string}
     */
    public static function health(): array
    {
        $last = (int) get_option(self::LAST_TICK_OPTION, 0);
        return [
            'cron_disabled' => defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON,
            'last_tick' => $last,
            'stale' => $last === 0 || $last < time() - self::STALE_AFTER,
            'schedule_error' => (string) get_option(self::SCHEDULE_ERROR_OPTION, ''),
        ];
    }
}
