<?php
/**
 * Post lifecycle hooks.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Hooks;

use ZWPangram\Store\ItemsRepository;

/**
 * Keeps stored results aligned with post lifecycle changes.
 */
final class PostHooks
{
    /** Registers post lifecycle hooks. */
    public static function register(): void
    {
        add_action('post_updated', [self::class, 'onPostUpdated'], 10, 3);
        add_action('deleted_post', [self::class, 'onDeletedPost'], 10, 1);
    }

    /**
     * Marks a result stale after a content change.
     *
     * @param int      $post_id     Post ID.
     * @param \WP_Post $post_after  New post.
     * @param \WP_Post $post_before Previous post.
     */
    public static function onPostUpdated(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
    {
        if ($post_after->post_content === $post_before->post_content || !ItemsRepository::tableExists()) {
            return;
        }
        (new ItemsRepository())->markStale($post_id);
    }

    /**
     * Removes stored data for a deleted post.
     *
     * @param int $post_id Post ID.
     */
    public static function onDeletedPost(int $post_id): void
    {
        if (!ItemsRepository::tableExists()) {
            return;
        }
        (new ItemsRepository())->deleteByPostId($post_id);
    }
}
