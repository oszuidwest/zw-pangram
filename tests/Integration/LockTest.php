<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Support\Lock;
use ZWPangram\Tests\Support\PluginTestCase;

final class LockTest extends PluginTestCase
{
    public function test_second_acquire_fails_until_release(): void
    {
        $lock = new Lock();
        $token = $lock->acquire();
        $this->assertNotNull($token);
        $this->assertNull((new Lock())->acquire());
        $lock->release($token);
        $this->assertNotNull((new Lock())->acquire());
    }

    public function test_release_with_foreign_token_does_nothing(): void
    {
        $owner = new Lock();
        $token = $owner->acquire();
        $this->assertNotNull($token);
        (new Lock())->release('someone-elses-token');
        $owner->release('wrong');
        $this->assertNull((new Lock())->acquire(), 'lock still held');
        $owner->release($token);
    }

    public function test_stale_lock_is_taken_over(): void
    {
        add_option(Lock::OPTION, ['token' => 'old', 'time' => time() - Lock::STALE_AFTER - 1], '', false);
        $lock = new Lock();
        $token = $lock->acquire();
        $this->assertNotNull($token);
        $stored = get_option(Lock::OPTION);
        $this->assertSame($token, $stored['token']);
        $lock->release($token);
        $this->assertFalse(get_option(Lock::OPTION));
    }
}
