<?php
/**
 * Tick lock.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

/**
 * Provides an atomic, owner-only lock through the unique option_name index.
 */
final class Lock
{
    public const OPTION = 'zw_pangram_tick_lock';
    public const STALE_AFTER = 600;

    /** @var array<string, string> Serialized lock value keyed by owner token. */
    private array $written = [];

    /** Returns an owner token when the lock is acquired. */
    public function acquire(): ?string
    {
        $token = wp_generate_password(32, false, false);
        $value = ['token' => $token, 'time' => time()];

        if ($this->tryAdd($value)) {
            $this->written[$token] = (string) maybe_serialize($value);
            return $token;
        }

        // Only stale locks may be replaced.
        $this->flushCache();
        $existing = get_option(self::OPTION, null);
        if (!is_array($existing) || !isset($existing['time']) || (int) $existing['time'] > time() - self::STALE_AFTER) {
            return null;
        }
        $this->deleteExact((string) maybe_serialize($existing));

        if ($this->tryAdd($value)) {
            $this->written[$token] = (string) maybe_serialize($value);
            return $token;
        }
        return null;
    }

    /**
     * Releases an owned lock.
     *
     * @param string $token Token returned by acquire().
     */
    public function release(string $token): void
    {
        if (!isset($this->written[$token])) {
            return;
        }
        $this->deleteExact($this->written[$token]);
        unset($this->written[$token]);
    }

    /**
     * Inserts the lock only when no option row exists.
     *
     * @param array{token: string, time: int} $value Lock value.
     */
    private function tryAdd(array $value): bool
    {
        $this->flushCache();
        return add_option(self::OPTION, $value, '', false);
    }

    /**
     * Deletes only the exact lock value owned by this instance.
     *
     * @param string $serialized Serialized option value.
     */
    private function deleteExact(string $serialized): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-match delete is the whole point of the lock.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::OPTION, $serialized));
        $this->flushCache();
    }

    /** Clears option caches before lock operations. */
    private function flushCache(): void
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
