<?php
/**
 * Scan tab.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Cron\BulkJob;
use ZWPangram\Queue\QueueState;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Settings;

/**
 * Enqueue form, queue controls and the error log.
 */
final class ScanTab
{
    /** Renders scan controls and queue state. */
    public static function render(): void
    {
        self::renderForm();
        self::renderStatus();
        self::renderLog();
    }

    /** Renders the enqueue form. */
    private static function renderForm(): void
    {
        $settings = Settings::get();
        $authors = get_users(['capability' => 'edit_posts', 'fields' => ['ID', 'display_name'], 'orderby' => 'display_name', 'number' => 500]);
        $categories = get_categories(['hide_empty' => false, 'number' => 500]);
        ?>
        <h2><?php esc_html_e('Add posts to the queue', 'zw-pangram'); ?></h2>
        <p class="description"><?php esc_html_e('The full text of every selected post is sent to Pangram Labs. Only post types and statuses enabled in the settings can be selected.', 'zw-pangram'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zw-pangram-scan-form">
            <?php wp_nonce_field(Actions::NONCE_ENQUEUE); ?>
            <input type="hidden" name="action" value="zw_pangram_enqueue">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Post types', 'zw-pangram'); ?></th>
                    <td>
                        <?php foreach ($settings['post_types'] as $type) : ?>
                            <?php $object = get_post_type_object($type); ?>
                            <label class="zw-pangram-inline"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type); ?>" checked> <?php echo esc_html($object instanceof \WP_Post_Type ? $object->labels->name : $type); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post statuses', 'zw-pangram'); ?></th>
                    <td>
                        <?php foreach ($settings['post_statuses'] as $status) : ?>
                            <?php $object = get_post_status_object($status); ?>
                            <label class="zw-pangram-inline"><input type="checkbox" name="post_statuses[]" value="<?php echo esc_attr($status); ?>" checked> <?php echo esc_html($object !== null ? $object->label : $status); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zw-pangram-date-from"><?php esc_html_e('Published between', 'zw-pangram'); ?></label></th>
                    <td>
                        <input type="date" id="zw-pangram-date-from" name="date_from">
                        <span aria-hidden="true">-</span>
                        <input type="date" id="zw-pangram-date-to" name="date_to" aria-label="<?php esc_attr_e('Published until', 'zw-pangram'); ?>">
                        <p class="description"><?php esc_html_e('Both bounds are inclusive and use the post date. Leave empty for all.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zw-pangram-authors"><?php esc_html_e('Authors', 'zw-pangram'); ?></label></th>
                    <td>
                        <select id="zw-pangram-authors" name="authors[]" multiple size="6">
                            <?php foreach ($authors as $author) : ?>
                                <option value="<?php echo esc_attr((string) $author->ID); ?>"><?php echo esc_html($author->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Leave empty for all authors.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zw-pangram-categories"><?php esc_html_e('Categories', 'zw-pangram'); ?></label></th>
                    <td>
                        <select id="zw-pangram-categories" name="categories[]" multiple size="6">
                            <?php foreach ($categories as $category) : ?>
                                <option value="<?php echo esc_attr((string) $category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Leave empty for all categories.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rescan', 'zw-pangram'); ?></th>
                    <td>
                        <label class="zw-pangram-inline"><input type="checkbox" name="force" value="1"> <?php esc_html_e('Force a rescan of posts whose content did not change (costs credits)', 'zw-pangram'); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Add to queue', 'zw-pangram'), 'primary', 'zw_pangram_enqueue_submit', false); ?>
        </form>
        <?php
    }

    /** Renders queue counters, the active job, and controls. */
    private static function renderStatus(): void
    {
        $counts = (new ItemsRepository())->counts();
        $job = BulkJob::get();
        $state = QueueState::get();
        ?>
        <h2><?php esc_html_e('Queue', 'zw-pangram'); ?></h2>
        <div class="zw-pangram-status" id="zw-pangram-status">
            <ul class="zw-pangram-counters">
                <?php foreach (['pending', 'processing', 'submitted', 'done', 'failed', 'skipped'] as $key) : ?>
                    <li><span class="zw-pangram-pill zw-pangram-pill-<?php echo esc_attr($key); ?>" data-count="<?php echo esc_attr($key); ?>"><?php echo esc_html((string) ($counts[$key] ?? 0)); ?></span> <?php echo esc_html(StatusLabels::queue($key)); ?></li>
                <?php endforeach; ?>
            </ul>
            <p id="zw-pangram-job">
                <?php if ($job !== null) : ?>
                    <?php
                    printf(
                        /* translators: 1: bulk ID, 2: status, 3: item count, 4: results processed, 5: age */
                        esc_html__('Open job %1$s: %2$s, %3$d items, %4$d results processed, submitted %5$s ago.', 'zw-pangram'),
                        '<code>' . esc_html($job['bulk_id']) . '</code>',
                        esc_html(StatusLabels::bulk($job['status'])),
                        (int) $job['item_count'],
                        (int) $job['results_offset'],
                        esc_html(human_time_diff($job['submitted_at'], time()))
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e('No open Pangram job.', 'zw-pangram'); ?>
                <?php endif; ?>
            </p>
            <p id="zw-pangram-paused">
                <?php if ($state['paused']) : ?>
                    <strong><?php esc_html_e('Paused.', 'zw-pangram'); ?></strong> <?php echo esc_html($state['reason']); ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="zw-pangram-actions">
            <?php if ($state['paused']) : ?>
                <?php AdminPage::actionForm('zw_pangram_resume', Actions::NONCE_QUEUE_STATE, __('Resume queue', 'zw-pangram')); ?>
            <?php else : ?>
                <?php AdminPage::actionForm('zw_pangram_pause', Actions::NONCE_QUEUE_STATE, __('Pause queue', 'zw-pangram')); ?>
            <?php endif; ?>
            <?php AdminPage::actionForm('zw_pangram_run_tick', Actions::NONCE_RUN_TICK, __('Run tick now', 'zw-pangram')); ?>
            <?php AdminPage::actionForm('zw_pangram_clear_queue', Actions::NONCE_CLEAR_QUEUE, __('Clear queue', 'zw-pangram')); ?>
            <?php if ($job !== null) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zw-pangram-inline-form">
                    <?php wp_nonce_field(Actions::NONCE_ABANDON); ?>
                    <input type="hidden" name="action" value="zw_pangram_abandon_job">
                    <label class="zw-pangram-inline"><input type="radio" name="outcome" value="requeue" checked> <?php esc_html_e('requeue rows', 'zw-pangram'); ?></label>
                    <label class="zw-pangram-inline"><input type="radio" name="outcome" value="fail"> <?php esc_html_e('mark rows failed', 'zw-pangram'); ?></label>
                    <?php submit_button(__('Abandon open job', 'zw-pangram'), 'secondary', 'zw_pangram_abandon', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Renders the error log. */
    private static function renderLog(): void
    {
        $entries = ErrorLog::all();
        _prime_post_caches(array_values(array_filter(array_column($entries, 'post_id'))), false, false);
        ?>
        <h2><?php esc_html_e('Error log', 'zw-pangram'); ?></h2>
        <?php if ($entries === []) : ?>
            <p><?php esc_html_e('No errors logged.', 'zw-pangram'); ?></p>
        <?php else : ?>
            <table class="widefat striped zw-pangram-log">
                <thead><tr>
                    <th><?php esc_html_e('Time (UTC)', 'zw-pangram'); ?></th>
                    <th><?php esc_html_e('HTTP', 'zw-pangram'); ?></th>
                    <th><?php esc_html_e('Post', 'zw-pangram'); ?></th>
                    <th><?php esc_html_e('Job', 'zw-pangram'); ?></th>
                    <th><?php esc_html_e('Message', 'zw-pangram'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['time']); ?></td>
                        <td><?php echo $entry['http'] > 0 ? esc_html((string) $entry['http']) : '-'; ?></td>
                        <td>
                            <?php if ($entry['post_id'] > 0) : ?>
                                <a href="<?php echo esc_url((string) get_edit_post_link($entry['post_id'])); ?>"><?php echo esc_html(get_the_title($entry['post_id']) ?: '#' . $entry['post_id']); ?></a>
                            <?php else : ?>-<?php endif; ?>
                        </td>
                        <td><?php echo $entry['bulk_id'] !== '' ? '<code>' . esc_html($entry['bulk_id']) . '</code>' : '-'; ?></td>
                        <td>
                            <?php echo esc_html($entry['message']); ?>
                            <?php if ($entry['body'] !== '') : ?>
                                <details><summary><?php esc_html_e('Response body', 'zw-pangram'); ?></summary><pre><?php echo esc_html($entry['body']); ?></pre></details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php AdminPage::actionForm('zw_pangram_clear_log', Actions::NONCE_CLEAR_LOG, __('Clear log', 'zw-pangram')); ?>
        <?php endif; ?>
        <?php
    }
}
