<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Hooks\PostHooks;
use ZWPangram\Tests\Support\PluginTestCase;

final class PostHooksTest extends PluginTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        PostHooks::register();
    }

    public function test_content_change_marks_stale_but_title_change_does_not(): void
    {
        $p = $this->post();
        $this->storeOk($p);
        wp_update_post(['ID' => $p, 'post_title' => 'New title']);
        $this->assertFalse($this->row($p)['result_stale']);
        wp_update_post(['ID' => $p, 'post_content' => 'Changed content with several more words in it.']);
        $this->assertTrue($this->row($p)['result_stale']);
    }

    public function test_deleting_post_removes_row(): void
    {
        $p = $this->post();
        $this->repo->upsertPending([$p], false);
        wp_delete_post($p, true);
        $this->assertNull($this->repo->find($p));
    }
}
