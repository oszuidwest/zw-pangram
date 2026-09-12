<?php
/**
 * Pangram API exception.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Api;

/**
 * Carries the HTTP status and a redacted body; status 0 means transport failure.
 */
final class ApiException extends \RuntimeException
{
    /**
     * Creates an exception safe for display and logging.
     *
     * @param string $message Redacted message.
     * @param int    $status  HTTP status, 0 for transport errors.
     * @param string $body    Redacted, truncated response body.
     */
    public function __construct(string $message, int $status = 0, private readonly string $body = '')
    {
        parent::__construct($message, $status);
    }

    /** HTTP status code (0 = no response). */
    public function status(): int
    {
        return $this->getCode();
    }

    /** Redacted response body. */
    public function body(): string
    {
        return $this->body;
    }

    /** Whether the request may be retried; the failed attempt remains consumed. */
    public function isRetryable(): bool
    {
        $s = $this->getCode();
        return $s === 0 || $s === 408 || $s === 429 || $s >= 500;
    }

    /** Whether queue processing must pause without consuming an attempt. */
    public function isAuthOrBilling(): bool
    {
        return in_array($this->getCode(), [401, 402, 403], true);
    }

    /** Whether the payload exceeded a service limit. */
    public function isTooLarge(): bool
    {
        return $this->getCode() === 413;
    }

    /** Whether the job is expired or belongs to another API key. */
    public function isNotFound(): bool
    {
        return $this->getCode() === 404;
    }
}
