<?php
/**
 * Results list table.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\QueueStatus;
use ZWPangram\Store\ResultStatus;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Sortable, filterable table of scanned posts.
 */
final class ResultsListTable extends \WP_List_Table
{
    public const PER_PAGE_OPTION = 'zw_pangram_results_per_page';
    public const PER_PAGE_DEFAULT = 20;

    /**
     * Creates a table for the supplied filters.
     *
     * @param ResultsFilters $filters Filters.
     */
    public function __construct(private readonly ResultsFilters $filters)
    {
        parent::__construct(['singular' => 'result', 'plural' => 'results', 'ajax' => false]);
    }

    /**
     * Defines the visible result columns.
     *
     * @return array<string, string>
     */
    public function get_columns(): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        return [
            'title' => __('Title', 'zw-pangram'),
            'author' => __('Author', 'zw-pangram'),
            'date' => __('Published', 'zw-pangram'),
            'label' => __('Label', 'zw-pangram'),
            'fraction_ai' => __('AI', 'zw-pangram'),
            'fraction_ai_assisted' => __('AI-assisted', 'zw-pangram'),
            'fraction_human' => __('Human', 'zw-pangram'),
            'headline' => __('Result', 'zw-pangram'),
            'status' => __('Status', 'zw-pangram'),
        ];
    }

    /**
     * Defines sortable result columns.
     *
     * @return array<string, array{0: string, 1: bool, 2?: string, 3?: string, 4?: string}>
     */
    protected function get_sortable_columns(): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        return [
            'fraction_ai' => ['fraction_ai', true],
            'date' => ['date', true, '', '', 'desc'],
        ];
    }

    /** Loads rows and pagination for the current request. */
    public function prepare_items(): void // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        $perPage = (int) $this->get_items_per_page(self::PER_PAGE_OPTION, self::PER_PAGE_DEFAULT);
        $query = new ResultsQuery();
        $this->items = $query->rows($this->filters, $perPage, $this->get_pagenum());
        // Preload data used by row links and author names to avoid per-row queries.
        _prime_post_caches(array_column($this->items, 'ID'), true, false);
        cache_users(array_unique(array_column($this->items, 'post_author')));
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'title'];
        $this->set_pagination_args(['total_items' => $query->count($this->filters), 'per_page' => $perPage]);
    }

    /** Prints the empty-table message. */
    public function no_items(): void // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        esc_html_e('No scanned posts match the current filters.', 'zw-pangram');
    }

    /**
     * Renders filters above the table.
     *
     * @param string $which Table navigation position.
     */
    protected function extra_tablenav($which): void // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        if ($which !== 'top') {
            return;
        }
        $f = $this->filters;
        ?>
        <div class="alignleft actions zw-pangram-filters">
            <select name="label" aria-label="<?php esc_attr_e('Label', 'zw-pangram'); ?>">
                <option value=""><?php esc_html_e('All labels', 'zw-pangram'); ?></option>
                <?php foreach (ResultsFilters::LABELS as $label) : ?>
                    <option value="<?php echo esc_attr($label); ?>" <?php selected($f->label, $label); ?>><?php echo esc_html(StatusLabels::label($label)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" aria-label="<?php esc_attr_e('Status', 'zw-pangram'); ?>">
                <option value=""><?php esc_html_e('All statuses', 'zw-pangram'); ?></option>
                <?php foreach (ResultStatus::cases() as $case) : ?>
                    <option value="<?php echo esc_attr($case->value); ?>" <?php selected($f->status, $case->value); ?>><?php echo esc_html(StatusLabels::result($case->value)); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
            wp_dropdown_users([
                'name' => 'author',
                'selected' => $f->author,
                'show_option_all' => __('All authors', 'zw-pangram'),
                'capability' => 'edit_posts',
                'number' => 500,
            ]);
            ?>
            <input type="date" name="from" value="<?php echo esc_attr((string) $f->from); ?>" aria-label="<?php esc_attr_e('Published from', 'zw-pangram'); ?>">
            <input type="date" name="to" value="<?php echo esc_attr((string) $f->to); ?>" aria-label="<?php esc_attr_e('Published until', 'zw-pangram'); ?>">
            <label class="zw-pangram-inline"><input type="checkbox" name="stale" value="1" <?php checked($f->stale); ?>> <?php esc_html_e('Stale only', 'zw-pangram'); ?></label>
            <?php submit_button(__('Filter', 'zw-pangram'), 'secondary', 'filter_action', false); ?>
        </div>
        <?php
    }

    /**
     * Renders a title cell with row actions.
     *
     * @param array<string, mixed> $item Row.
     */
    protected function column_title(array $item): string // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        $id = (int) $item['ID'];
        $title = (string) $item['post_title'] !== '' ? (string) $item['post_title'] : sprintf('#%d', $id);
        $edit = get_edit_post_link($id);
        $html = '<strong>' . ($edit ? '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>' : esc_html($title)) . '</strong>';
        if ($item['result_stale']) {
            $html .= ' <span class="zw-pangram-badge zw-pangram-badge-stale">' . esc_html__('stale', 'zw-pangram') . '</span> ';
            $html .= wp_get_toggletip(__('Content changed after this scan', 'zw-pangram'), [
                'id' => 'zw-pangram-stale-' . $id,
                'label' => __('Why is this result stale?', 'zw-pangram'),
            ]);
        }
        $view = get_permalink($id);
        $actions = ['details' => '<a href="' . esc_url(ResultDetails::url($id)) . '">' . esc_html__('Details', 'zw-pangram') . '</a>'];
        if ($view) {
            $actions['view'] = '<a href="' . esc_url($view) . '">' . esc_html__('View', 'zw-pangram') . '</a>';
        }
        $actions['rescan'] = sprintf(
            '<form method="post" action="%s" class="zw-pangram-row-form">%s<input type="hidden" name="action" value="zw_pangram_rescan"><input type="hidden" name="post_id" value="%d"><button type="submit" class="button-link">%s</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field(Actions::NONCE_RESCAN . $id, '_wpnonce', true, false),
            $id,
            esc_html__('Rescan', 'zw-pangram')
        );
        return $html . $this->row_actions($actions);
    }

    /**
     * Renders non-title cells.
     *
     * @param array<string, mixed> $item        Row.
     * @param string               $column_name Column.
     */
    protected function column_default($item, $column_name): string // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Parent API.
    {
        switch ($column_name) {
            case 'author':
                $name = get_the_author_meta('display_name', (int) $item['post_author']);
                $url = AdminPage::url('results', array_merge($this->filters->toArgs(), ['author' => (int) $item['post_author']]));
                return '<a href="' . esc_url($url) . '">' . esc_html($name !== '' ? $name : '#' . (int) $item['post_author']) . '</a>';
            case 'date':
                return esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $item['post_date']));
            case 'label':
                $label = $item['prediction_short'];
                return $label === null ? '-' : '<span class="zw-pangram-badge zw-pangram-badge-' . esc_attr(strtolower((string) $label)) . '">' . esc_html(StatusLabels::label((string) $label)) . '</span>';
            case 'fraction_ai':
            case 'fraction_ai_assisted':
            case 'fraction_human':
                return $item[$column_name] === null ? '-' : esc_html(number_format_i18n((float) $item[$column_name] * 100, 1) . '%');
            case 'headline':
                return esc_html((string) ($item['headline'] ?? '-'));
            case 'status':
                $status = (string) $item['result_status'];
                $error = (string) ($item['result_error'] ?? '');
                $html = '<span class="zw-pangram-pill zw-pangram-pill-' . esc_attr($status) . '">' . esc_html(StatusLabels::result($status)) . '</span>';
                if ($error !== '') {
                    $html .= ' ' . wp_get_toggletip($error, [
                        'id' => 'zw-pangram-error-' . (int) $item['ID'],
                        'label' => __('View error details', 'zw-pangram'),
                    ]);
                }
                if (in_array($item['queue_status'], [QueueStatus::Pending->value, QueueStatus::Processing->value, QueueStatus::Submitted->value], true)) {
                    $html .= ' <small>(' . esc_html(StatusLabels::queue((string) $item['queue_status'])) . ')</small>';
                }
                return $html;
        }
        return '';
    }
}
