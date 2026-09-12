<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Queue\QueueState;
use ZWPangram\Support\Settings;

final class SettingsTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(Settings::OPTION);
    }

    public function test_sanitize_clamps_ranges_and_filters_lists(): void
    {
        $s = Settings::sanitize([
            'batch_size' => '5000',
            'min_words' => '0',
            'post_types' => ['post', 'nonexistent_type'],
            'post_statuses' => ['draft', 'trash'],
            'model' => 'pangram-3',
        ]);
        $this->assertSame(1000, $s['batch_size']);
        $this->assertSame(1, $s['min_words']);
        $this->assertSame(['post'], $s['post_types']);
        $this->assertSame(['draft'], $s['post_statuses']);
        $this->assertArrayNotHasKey('model', $s);
    }

    public function test_empty_key_keeps_stored_key_and_checkbox_removes_it(): void
    {
        Settings::update(['api_key' => 'stored-key']);
        $this->assertSame('stored-key', Settings::sanitize(['api_key' => ''])['api_key']);
        $this->assertSame('new-key', Settings::sanitize(['api_key' => ' new-key '])['api_key']);
        $this->assertSame('', Settings::sanitize(['remove_api_key' => '1', 'api_key' => 'ignored'])['api_key']);
    }

    public function test_update_stores_option_without_autoload(): void
    {
        Settings::update(['api_key' => 'k']);
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION));
        $this->assertContains($autoload, ['no', 'off']);
    }

    public function test_fingerprint_is_stable_and_not_the_key(): void
    {
        $a = Settings::apiKeyFingerprint('secret');
        $this->assertSame(16, strlen($a));
        $this->assertSame($a, Settings::apiKeyFingerprint('secret'));
        $this->assertNotSame($a, Settings::apiKeyFingerprint('other'));
        $this->assertStringNotContainsString('secret', $a);
        $this->assertSame('', Settings::apiKeyFingerprint(''));
    }

    public function test_saving_a_missing_key_resumes_an_automatic_pause_only(): void
    {
        QueueState::pause('The Pangram API key is missing.', true);
        Settings::sanitize(['api_key' => 'k']);
        $this->assertFalse(QueueState::isPaused());

        Settings::update(['api_key' => '']);
        QueueState::pause('Paused by an administrator.', false);
        Settings::sanitize(['api_key' => 'k']);
        $this->assertTrue(QueueState::isPaused(), 'manual pauses are kept');

        Settings::update(['api_key' => 'k']);
        QueueState::pause('Rejected', true);
        Settings::sanitize(['api_key' => 'k2']);
        $this->assertTrue(QueueState::isPaused(), 'only a previously missing key resumes');
    }
}
