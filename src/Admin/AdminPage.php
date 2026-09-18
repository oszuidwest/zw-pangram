<?php
/**
 * Admin page under Tools.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Activation;
use ZWPangram\Cron\BulkJob;
use ZWPangram\Cron\Scheduler;
use ZWPangram\Queue\QueueState;
use ZWPangram\Support\Settings;

/**
 * Owns the Tools page, tab routing, and page-scoped assets.
 */
final class AdminPage
{
    public const SLUG = 'zw-pangram';
    public const CAPABILITY = 'manage_options';
    public const TABS = ['results', 'scan', 'settings'];

    /** Registers admin-page hooks. */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_filter('set_screen_option_' . ResultsListTable::PER_PAGE_OPTION, [self::class, 'saveScreenOption'], 10, 3);
    }

    /** Adds the Tools submenu entry. */
    public static function addMenu(): void
    {
        $hook = (string) add_management_page(
            __('Pangram AI detection', 'zw-pangram'),
            __('Pangram', 'zw-pangram'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render']
        );
        add_action('load-' . $hook, [self::class, 'onLoad']);
    }

    /** Registers hooks and options scoped to the plugin screen. */
    public static function onLoad(): void
    {
        wp_prime_option_caches([Settings::OPTION, QueueState::OPTION, BulkJob::OPTION, Scheduler::LAST_TICK_OPTION, Scheduler::SCHEDULE_ERROR_OPTION]);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_notices', [Notices::class, 'renderHealth']);
        if (self::currentTab() === 'results') {
            add_screen_option('per_page', [
                'label' => __('Results per page', 'zw-pangram'),
                'default' => ResultsListTable::PER_PAGE_DEFAULT,
                'option' => ResultsListTable::PER_PAGE_OPTION,
            ]);
        }
    }

    /**
     * Limits the saved per-page value to 1–500.
     *
     * @param mixed  $status Filtered value.
     * @param string $option Screen-option name.
     * @param mixed  $value  Submitted value.
     */
    public static function saveScreenOption(mixed $status, string $option, mixed $value): int
    {
        return max(1, min(500, (int) $value));
    }

    /** Returns a supported query-string tab. */
    public static function currentTab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'results';
        return in_array($tab, self::TABS, true) ? $tab : 'results';
    }

    /**
     * Builds a plugin tab URL.
     *
     * @param string               $tab  Tab slug.
     * @param array<string, mixed> $args Additional query arguments.
     */
    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('tools.php'));
    }

    /** Enqueues assets for the plugin screen. */
    public static function enqueueAssets(): void
    {
        wp_enqueue_style('zw-pangram-admin', \ZW_PANGRAM_URL . 'assets/admin.css', [], \ZW_PANGRAM_VERSION);
        wp_enqueue_script('zw-pangram-admin', \ZW_PANGRAM_URL . 'assets/admin.js', ['jquery', 'wp-util'], \ZW_PANGRAM_VERSION, true);
        if (self::currentTab() === 'results') {
            wp_enqueue_style('wp-tooltip');
            wp_enqueue_script('wp-tooltip');
        }
        wp_localize_script('zw-pangram-admin', 'zwPangram', [
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'i18n' => [
                'testing' => __('Testing connection...', 'zw-pangram'),
                'valid' => __('Connection OK. Pangram 4 is available.', 'zw-pangram'),
                'invalid' => __('Connection failed:', 'zw-pangram'),
            ],
        ]);
    }

    /**
     * Requires the plugin capability and a valid admin-post nonce.
     *
     * @param string $nonceAction Expected nonce action.
     */
    public static function guard(string $nonceAction): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'zw-pangram'), '', ['response' => 403]);
        }
        check_admin_referer($nonceAction);
    }

    /**
     * Prints a nonce-protected, single-button admin-post form.
     *
     * @param string                    $action Admin-post action.
     * @param string                    $nonce  Nonce action.
     * @param string                    $label  Button label.
     * @param array<string, int|string> $hidden Additional hidden fields.
     */
    public static function actionForm(string $action, string $nonce, string $label, array $hidden = []): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zw-pangram-inline-form">
            <?php wp_nonce_field($nonce); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($hidden as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
            <?php endforeach; ?>
            <?php submit_button($label, 'secondary', $action . '_submit', false); ?>
        </form>
        <?php
    }

    /** Renders the current plugin tab. */
    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'zw-pangram'), '', ['response' => 403]);
        }
        if (!Activation::ensureTable()) {
            echo '<div class="wrap">';
            wp_admin_notice(esc_html__('ZuidWest Pangram could not create its database table. See the error log and your database permissions.', 'zw-pangram'), ['type' => 'error']);
            echo '</div>';
            return;
        }
        $tab = self::currentTab();
        $tabs = [
            'results' => __('Results', 'zw-pangram'),
            'scan' => __('Scan', 'zw-pangram'),
            'settings' => __('Settings', 'zw-pangram'),
        ];
        echo '<div class="wrap zw-pangram">';
        echo '<h1>' . esc_html__('Pangram AI detection', 'zw-pangram') . '</h1>';
        Notices::renderActionFeedback();
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $class = 'nav-tab' . ($key === $tab ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        match ($tab) {
            'scan' => ScanTab::render(),
            'settings' => SettingsTab::render(),
            default => ResultsTab::render(),
        };
        echo '</div>';
    }
}
