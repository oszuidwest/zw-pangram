<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Support\ErrorLog;
use ZWPangram\Support\Settings;

final class ErrorLogTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        ErrorLog::clear();
        Settings::update(['api_key' => 'sk-very-secret-key-000']);
    }

    public function test_redacts_effective_key_and_secret_fields_but_keeps_ids(): void
    {
        ErrorLog::add('key sk-very-secret-key-000 leaked, {"api_key": "abc"} x-api-key: zzz bulk 0123456789abcdef0123456789abcdef', ['bulk_id' => 'bulk-1']);
        $entry = ErrorLog::all()[0];
        $this->assertStringNotContainsString('sk-very-secret-key-000', $entry['message']);
        $this->assertStringNotContainsString('"abc"', $entry['message']);
        $this->assertStringNotContainsString('zzz', $entry['message']);
        $this->assertStringContainsString('0123456789abcdef0123456789abcdef', $entry['message']);
        $this->assertSame('bulk-1', $entry['bulk_id']);
    }

    public function test_bounds_entries_and_bytes(): void
    {
        for ($i = 0; $i < 60; $i++) {
            ErrorLog::add('m' . $i, ['body' => str_repeat('b', 5000)]);
        }
        $all = ErrorLog::all();
        $this->assertCount(ErrorLog::MAX_ENTRIES, $all);
        $this->assertSame('m59', $all[0]['message']);
        $this->assertLessThanOrEqual(ErrorLog::MAX_BODY_BYTES, strlen($all[0]['body']));
        ErrorLog::add(str_repeat('x', 900));
        $this->assertLessThanOrEqual(ErrorLog::MAX_MESSAGE_BYTES, strlen(ErrorLog::all()[0]['message']));
    }

    public function test_add_once_dedupes_within_window(): void
    {
        ErrorLog::addOnce('same');
        ErrorLog::addOnce('same');
        $this->assertCount(1, ErrorLog::all());
    }
}
