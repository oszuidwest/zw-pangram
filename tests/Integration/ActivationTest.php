<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Activation;
use ZWPangram\Cron\Scheduler;
use ZWPangram\Queue\QueueState;
use ZWPangram\Store\ItemsRepository;
use ZWPangram\Support\Settings;
use ZWPangram\Tests\Support\PluginTestCase;

final class ActivationTest extends PluginTestCase
{
    public function test_activation_installs_table_version_and_single_cron_event(): void
    {
        global $wpdb;
        $this->assertSame(ItemsRepository::tableName(), $wpdb->get_var("SHOW TABLES LIKE '" . ItemsRepository::tableName() . "'"));
        $this->assertSame(Activation::DB_VERSION, get_option(Activation::DB_VERSION_OPTION));
        $this->assertNotFalse(wp_next_scheduled(Scheduler::EVENT));
        Activation::activate(false);
        Scheduler::ensureScheduled();
        $count = 0;
        foreach (_get_cron_array() as $hooks) {
            $count += isset($hooks[Scheduler::EVENT]) ? count($hooks[Scheduler::EVENT]) : 0;
        }
        $this->assertSame(1, $count, 'no duplicate events');
        $this->assertArrayHasKey(Scheduler::SCHEDULE, wp_get_schedules());
        $this->assertTrue(Activation::maybeUpgrade());
    }

    public function test_deactivation_clears_cron_but_keeps_data(): void
    {
        $p = $this->post();
        $this->repo->upsertPending([$p], false);
        Activation::deactivate();
        $this->assertFalse(wp_next_scheduled(Scheduler::EVENT));
        $this->assertNotNull($this->repo->find($p));
        $this->assertSame(Activation::DB_VERSION, get_option(Activation::DB_VERSION_OPTION));
    }

    public function test_maybe_upgrade_recreates_missing_table(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE ' . ItemsRepository::tableName());
        update_option(Activation::DB_VERSION_OPTION, '0');
        $this->assertTrue(Activation::maybeUpgrade());
        $this->assertSame(ItemsRepository::tableName(), $wpdb->get_var("SHOW TABLES LIKE '" . ItemsRepository::tableName() . "'"));
        $this->assertSame(Activation::DB_VERSION, get_option(Activation::DB_VERSION_OPTION));
    }

    public function test_maybe_upgrade_replaces_the_legacy_queue_index(): void
    {
        global $wpdb;
        $table = ItemsRepository::tableName();
        $wpdb->query("ALTER TABLE {$table} DROP INDEX queue_claim, ADD KEY queue_next (queue_status,next_attempt_at,id)");
        update_option(Activation::DB_VERSION_OPTION, '1');

        $this->assertTrue(Activation::maybeUpgrade());
        $rows = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'queue_claim'), ARRAY_A);
        usort($rows, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $this->assertSame(['queue_status', 'queued_at', 'id', 'next_attempt_at'], array_column($rows, 'Column_name'));
        $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'queue_next')));
    }

    public function test_uninstall_removes_everything(): void
    {
        global $wpdb;
        $this->repo->upsertPending([123456], false);
        QueueState::pause('x', false);

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'zw-pangram/zw-pangram.php');
        }
        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertNull($wpdb->get_var("SHOW TABLES LIKE '" . ItemsRepository::tableName() . "'"));
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertFalse(get_option(QueueState::OPTION));
        $this->assertFalse(get_option(Activation::DB_VERSION_OPTION));
        $this->assertFalse(wp_next_scheduled(Scheduler::EVENT));

        Activation::activate(false);
        $this->assertTrue(ItemsRepository::tableExists());
    }
}
