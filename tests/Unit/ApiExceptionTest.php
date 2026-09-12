<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Api\ApiException;

final class ApiExceptionTest extends \WP_UnitTestCase
{
    /**
     * @dataProvider classification
     */
    public function test_classification(int $status, bool $retryable, bool $auth, bool $tooLarge, bool $notFound): void
    {
        $e = new ApiException('x', $status);
        $this->assertSame($retryable, $e->isRetryable(), 'retryable');
        $this->assertSame($auth, $e->isAuthOrBilling(), 'auth');
        $this->assertSame($tooLarge, $e->isTooLarge(), 'tooLarge');
        $this->assertSame($notFound, $e->isNotFound(), 'notFound');
    }

    /** @return array<string, array{int, bool, bool, bool, bool}> */
    public function classification(): array
    {
        return [
            'transport' => [0, true, false, false, false],
            '400' => [400, false, false, false, false],
            '401' => [401, false, true, false, false],
            '402' => [402, false, true, false, false],
            '403' => [403, false, true, false, false],
            '404' => [404, false, false, false, true],
            '413' => [413, false, false, true, false],
            '422' => [422, false, false, false, false],
            '429' => [429, true, false, false, false],
            '500' => [500, true, false, false, false],
            '503' => [503, true, false, false, false],
        ];
    }
}
