<?php
/**
 * Results tab.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

/**
 * Author statistics, the list table and the CSV export button, or the details of one result.
 */
final class ResultsTab
{
    /** Renders filtered results, statistics, and export controls, or the requested result details. */
    public static function render(): void
    {
        $detailsPostId = ResultDetails::requestedPostId();
        if ($detailsPostId > 0) {
            ResultDetails::render($detailsPostId);
            return;
        }
        $filters = ResultsFilters::fromRequest(wp_unslash($_GET)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Read-only filters, validated field by field.
        self::renderStats($filters);

        $table = new ResultsListTable($filters);
        $table->prepare_items();
        ?>
        <h2><?php esc_html_e('Scanned posts', 'zw-pangram'); ?></h2>
        <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(AdminPage::SLUG); ?>">
            <input type="hidden" name="tab" value="results">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($filters->orderby); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($filters->order); ?>">
            <?php $table->search_box(__('Search titles', 'zw-pangram'), 'zw-pangram-search'); ?>
            <?php $table->display(); ?>
        </form>
        <p>
            <a class="button" href="<?php echo esc_url(CsvExport::url($filters)); ?>"><?php esc_html_e('Export CSV (current filters)', 'zw-pangram'); ?></a>
        </p>
        <?php
    }

    /**
     * Renders per-author statistics.
     *
     * @param ResultsFilters $filters Date-range filters.
     */
    private static function renderStats(ResultsFilters $filters): void
    {
        $rows = (new AuthorStats())->compute($filters);
        ?>
        <h2><?php esc_html_e('Per author', 'zw-pangram'); ?></h2>
        <?php if ($filters->from !== null || $filters->to !== null) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: from date, 2: to date */
                    esc_html__('Published between %1$s and %2$s.', 'zw-pangram'),
                    esc_html($filters->from ?? '...'),
                    esc_html($filters->to ?? '...')
                );
                ?>
            </p>
        <?php endif; ?>
        <?php if ($rows === []) : ?>
            <p><?php esc_html_e('No successful scans yet.', 'zw-pangram'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped zw-pangram-stats">
            <thead><tr>
                <th><?php esc_html_e('Author', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('Scanned', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('Avg. AI', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('Avg. AI-assisted', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('AI', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('Mix', 'zw-pangram'); ?></th>
                <th><?php esc_html_e('Human', 'zw-pangram'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php $url = AdminPage::url('results', array_merge($filters->toArgs(), ['author' => $row['author_id']])); ?>
                <tr>
                    <td><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($row['name']); ?></a></td>
                    <td><?php echo esc_html((string) $row['scanned']); ?></td>
                    <td><?php echo esc_html(number_format_i18n($row['avg_ai'] * 100, 1) . '%'); ?></td>
                    <td><?php echo esc_html(number_format_i18n($row['avg_assisted'] * 100, 1) . '%'); ?></td>
                    <td><?php echo esc_html((string) $row['n_ai']); ?></td>
                    <td><?php echo esc_html((string) $row['n_mixed']); ?></td>
                    <td><?php echo esc_html((string) $row['n_human']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
