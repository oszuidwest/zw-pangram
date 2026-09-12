<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Api\ResponseValidator;
use ZWPangram\Tests\Support\FakeClient;

final class ResponseValidatorTest extends \WP_UnitTestCase
{
    public function test_models(): void
    {
        $this->assertSame(['pangram-4'], ResponseValidator::models(['models' => ['pangram-4']]));
        $this->assertNull(ResponseValidator::models(['models' => 'pangram-4']));
        $this->assertNull(ResponseValidator::models(['models' => ['bad model']]));
        $this->assertNull(ResponseValidator::models('nope'));
    }

    public function test_bulk_accepted(): void
    {
        $this->assertTrue(ResponseValidator::bulkAccepted(FakeClient::accepted('b1', [1, 2], [3])));
        $this->assertFalse(ResponseValidator::bulkAccepted(['status' => 'queued']));
        $this->assertFalse(ResponseValidator::bulkAccepted(['bulk_id' => 'b', 'status' => 'queued', 'accepted_items' => [['no_id' => 1]]]));
    }

    public function test_bulk_status(): void
    {
        $this->assertTrue(ResponseValidator::bulkStatus(['status' => 'partial']));
        $this->assertFalse(ResponseValidator::bulkStatus(['status' => 'weird']));
    }

    public function test_results_page(): void
    {
        $this->assertTrue(ResponseValidator::resultsPage(FakeClient::page([FakeClient::okItem(1)], [FakeClient::failedItem(2)])));
        $this->assertFalse(ResponseValidator::resultsPage(['items' => []]));
        $this->assertFalse(ResponseValidator::resultsPage(['total_items' => 1, 'items' => [['id' => 'post-1', 'result' => 'string']]]));
        $this->assertFalse(ResponseValidator::resultsPage(['total_items' => 1, 'items' => 'x']));
    }

    public function test_result_rejects_bad_fractions_and_labels(): void
    {
        $ok = FakeClient::okItem(1)['result'];
        $this->assertTrue(ResponseValidator::result($ok));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['fraction_ai' => 1.5, 'fraction_human' => -0.5])));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['fraction_ai' => NAN])));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['fraction_ai' => '0.8'])));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['prediction_short' => 'Robot'])));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['fraction_ai' => 0.5, 'fraction_ai_assisted' => 0.5, 'fraction_human' => 0.5])));
        $this->assertFalse(ResponseValidator::result(array_replace($ok, ['windows' => 'x'])));
        $this->assertFalse(ResponseValidator::result('json string'));
    }

    public function test_post_id_from_item_id(): void
    {
        $this->assertSame(123, ResponseValidator::postIdFromItemId('post-123'));
        $this->assertNull(ResponseValidator::postIdFromItemId('post-0'));
        $this->assertNull(ResponseValidator::postIdFromItemId('123'));
        $this->assertNull(ResponseValidator::postIdFromItemId('post-12a'));
    }
}
