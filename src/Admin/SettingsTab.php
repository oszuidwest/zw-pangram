<?php
/**
 * Settings tab.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Support\Settings;

/**
 * Settings API registration and the settings form.
 */
final class SettingsTab
{
    /** Registers plugin settings with the Settings API. */
    public static function registerSettings(): void
    {
        add_action('admin_init', static function (): void {
            register_setting(Settings::GROUP, Settings::OPTION, [
                'type' => 'array',
                'sanitize_callback' => [Settings::class, 'sanitize'],
                'default' => Settings::defaults(),
            ]);
        });
    }

    /** Renders the settings form and data-purge control. */
    public static function render(): void
    {
        $settings = Settings::get();
        $fromConstant = Settings::apiKeyFromConstant();
        $hasStoredKey = $settings['api_key'] !== '';
        settings_errors(); // Include core confirmation and validation messages.
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" class="zw-pangram-settings">
            <?php settings_fields(Settings::GROUP); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="zw-pangram-api-key"><?php esc_html_e('Pangram API key', 'zw-pangram'); ?></label></th>
                    <td>
                        <?php if ($fromConstant) : ?>
                            <p><code>ZW_PANGRAM_API_KEY</code> <?php esc_html_e('is defined in wp-config.php; the stored key is ignored and hidden.', 'zw-pangram'); ?></p>
                        <?php else : ?>
                            <input type="password" id="zw-pangram-api-key" name="<?php echo esc_attr(Settings::OPTION); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $hasStoredKey ? esc_attr__('(stored key kept when left empty)', 'zw-pangram') : ''; ?>">
                            <?php if ($hasStoredKey) : ?>
                                <label class="zw-pangram-inline"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[remove_api_key]" value="1"> <?php esc_html_e('Remove the stored API key', 'zw-pangram'); ?></label>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Stored in the options table. For production, define ZW_PANGRAM_API_KEY in wp-config.php instead.', 'zw-pangram'); ?></p>
                        <?php endif; ?>
                        <p>
                            <button type="button" class="button" id="zw-pangram-test-connection"><?php esc_html_e('Test connection', 'zw-pangram'); ?></button>
                            <span id="zw-pangram-test-result" role="status"></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Model', 'zw-pangram'); ?></th>
                    <td>
                        <code><?php echo esc_html(Settings::MODEL); ?></code>
                        <p class="description"><?php esc_html_e('ZuidWest Pangram always uses Pangram 4.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zw-pangram-batch-size"><?php esc_html_e('Posts per cron tick', 'zw-pangram'); ?></label></th>
                    <td>
                        <input type="number" id="zw-pangram-batch-size" name="<?php echo esc_attr(Settings::OPTION); ?>[batch_size]" value="<?php echo esc_attr((string) $settings['batch_size']); ?>" min="<?php echo esc_attr((string) Settings::BATCH_MIN); ?>" max="<?php echo esc_attr((string) Settings::BATCH_MAX); ?>" class="small-text">
                        <p class="description"><?php esc_html_e('Maximum posts claimed per bulk request (1-1000). The request is also capped by Pangram\'s 1000 billable units.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zw-pangram-min-words"><?php esc_html_e('Minimum word count', 'zw-pangram'); ?></label></th>
                    <td>
                        <input type="number" id="zw-pangram-min-words" name="<?php echo esc_attr(Settings::OPTION); ?>[min_words]" value="<?php echo esc_attr((string) $settings['min_words']); ?>" min="<?php echo esc_attr((string) Settings::MIN_WORDS_MIN); ?>" max="<?php echo esc_attr((string) Settings::MIN_WORDS_MAX); ?>" class="small-text">
                        <p class="description"><?php esc_html_e('Shorter posts are skipped instead of sent.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post types', 'zw-pangram'); ?></th>
                    <td>
                        <?php foreach (Settings::allowedPostTypes() as $type) : ?>
                            <?php $object = get_post_type_object($type); ?>
                            <label class="zw-pangram-inline"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[post_types][]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $settings['post_types'], true)); ?>> <?php echo esc_html($object instanceof \WP_Post_Type ? $object->labels->name : $type); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post statuses', 'zw-pangram'); ?></th>
                    <td>
                        <?php foreach (Settings::ALLOWED_STATUSES as $status) : ?>
                            <?php $object = get_post_status_object($status); ?>
                            <label class="zw-pangram-inline"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[post_statuses][]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $settings['post_statuses'], true)); ?>> <?php echo esc_html($object !== null ? $object->label : $status); ?></label>
                        <?php endforeach; ?>
                        <br>
                        <label class="zw-pangram-inline"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[include_password_protected]" value="1" <?php checked($settings['include_password_protected']); ?>> <?php esc_html_e('Include password-protected posts', 'zw-pangram'); ?></label>
                        <p class="description"><?php esc_html_e('Warning: the full text of every selected post is sent to Pangram Labs, a third party. Unpublished, private or password-protected content is only sent when you enable it here.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Stored response', 'zw-pangram'); ?></th>
                    <td>
                        <label class="zw-pangram-inline"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[store_full_text]" value="1" <?php checked($settings['store_full_text']); ?>> <?php esc_html_e('Store the full response including the analyzed text and per-segment text', 'zw-pangram'); ?></label>
                        <p class="description"><?php esc_html_e('Off by default: the response is stored without text fields. Turning this off later does not remove text already stored; use the purge button below.', 'zw-pangram'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <?php AdminPage::actionForm('zw_pangram_purge_text', Actions::NONCE_PURGE, __('Purge stored response text', 'zw-pangram')); ?>
        <?php
    }
}
