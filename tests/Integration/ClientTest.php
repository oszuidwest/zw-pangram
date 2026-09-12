<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Api\ApiException;
use ZWPangram\Api\Client;

final class ClientTest extends \WP_UnitTestCase
{
    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, mixed>|\WP_Error */
    private mixed $response = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->requests = [];
        add_filter('pre_http_request', function (mixed $pre, array $args, string $url): mixed {
            $this->requests[] = ['url' => $url, 'args' => $args];
            return $this->response;
        }, 10, 3);
    }

    public function test_sends_key_header_and_json_body_and_accepts_202(): void
    {
        $this->respond(202, ['bulk_id' => 'b1', 'status' => 'queued', 'total_items' => 1, 'accepted_items' => [['index' => 0, 'id' => 'post-1', 'task_id' => 't']], 'failed_items' => []]);
        $client = new Client('sk-secret');
        $result = $client->submitBulk(['items' => [['id' => 'post-1', 'text' => 'hi']], 'model' => 'pangram-4']);
        $this->assertSame('b1', $result['bulk_id']);
        $req = $this->requests[0];
        $this->assertSame(Client::BASE_URL . '/bulk', $req['url']);
        $this->assertSame('POST', $req['args']['method']);
        $this->assertSame('sk-secret', $req['args']['headers']['x-api-key']);
        $this->assertArrayNotHasKey('Authorization', $req['args']['headers']);
        $this->assertSame(['items' => [['id' => 'post-1', 'text' => 'hi']], 'model' => 'pangram-4'], json_decode($req['args']['body'], true));
        $this->assertSame(Client::SUBMIT_TIMEOUT, $req['args']['timeout']);
    }

    public function test_models_and_results_urls(): void
    {
        $this->respond(200, ['models' => ['pangram-4']]);
        $this->assertSame(['pangram-4'], (new Client('k'))->models());
        $this->respond(200, ['total_items' => 0, 'items' => [], 'failed_items' => []]);
        (new Client('k'))->getBulkResults('b 1', 100, 50);
        $this->assertSame(Client::BASE_URL . '/bulk/b%201/results?offset=100&limit=50', $this->requests[1]['url']);
        $this->assertSame(Client::READ_TIMEOUT, $this->requests[1]['args']['timeout']);
    }

    public function test_error_status_becomes_exception_with_redacted_message(): void
    {
        $this->respond(401, ['error' => 'invalid key sk-secret']);
        try {
            (new Client('sk-secret'))->getBulk('b');
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->status());
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString('sk-secret', $e->getMessage());
            $this->assertStringNotContainsString('sk-secret', $e->body());
            $this->assertTrue($e->isAuthOrBilling());
        }
    }

    public function test_invalid_json_and_unexpected_shape(): void
    {
        $this->response = ['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'not json', 'headers' => [], 'cookies' => [], 'filename' => null];
        try {
            (new Client('k'))->models();
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame(200, $e->status());
            $this->assertStringContainsString('Invalid JSON', $e->getMessage());
        }
        $this->respond(200, ['status' => 'nope']);
        $this->expectException(ApiException::class);
        (new Client('k'))->getBulk('b');
    }

    public function test_transport_error_is_status_zero(): void
    {
        $this->response = new \WP_Error('http_request_failed', 'cURL error 28: timeout');
        try {
            (new Client('k'))->getBulk('b');
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame(0, $e->status());
            $this->assertTrue($e->isRetryable());
        }
    }

    /** @param array<string, mixed> $body */
    private function respond(int $code, array $body): void
    {
        $this->response = ['response' => ['code' => $code, 'message' => ''], 'body' => (string) wp_json_encode($body), 'headers' => [], 'cookies' => [], 'filename' => null];
    }
}
