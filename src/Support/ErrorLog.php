<?php
/**
 * Bounded error log.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Support;

/**
 * Stores a bounded list of recent API errors without autoloading it.
 *
 * @phpstan-type LogEntry array{time: string, message: string, http: int, post_id: int, bulk_id: string, body: string}
 */
final class ErrorLog
{
    public const OPTION = 'zw_pangram_error_log';
    public const MAX_ENTRIES = 50;
    public const MAX_MESSAGE_BYTES = 500;
    public const MAX_BODY_BYTES = 1024;

    /**
     * Prepends an error entry.
     *
     * @param string               $message Human readable message.
     * @param array<string, mixed> $context Optional http, post_id, bulk_id, body.
     */
    public static function add(string $message, array $context = []): void
    {
        $entry = [
            'time' => Dates::nowUtc(),
            'message' => Text::clip(self::redact($message), self::MAX_MESSAGE_BYTES),
            'http' => isset($context['http']) && is_numeric($context['http']) ? (int) $context['http'] : 0,
            'post_id' => isset($context['post_id']) && is_numeric($context['post_id']) ? (int) $context['post_id'] : 0,
            'bulk_id' => isset($context['bulk_id']) && is_string($context['bulk_id']) ? substr($context['bulk_id'], 0, 64) : '',
            'body' => isset($context['body']) && is_string($context['body']) ? Text::clip(self::redact($context['body']), self::MAX_BODY_BYTES) : '',
        ];
        $entries = self::all();
        array_unshift($entries, $entry);
        update_option(self::OPTION, array_slice($entries, 0, self::MAX_ENTRIES), false);
    }

    /**
     * Logs a message once per time window.
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     * @param int                  $window  Seconds.
     */
    public static function addOnce(string $message, array $context = [], int $window = 300): void
    {
        $message = Text::clip(self::redact($message), self::MAX_MESSAGE_BYTES);
        foreach (self::all() as $entry) {
            if ($entry['message'] === $message && strtotime($entry['time'] . ' UTC') >= time() - $window) {
                return;
            }
        }
        self::add($message, $context);
    }

    /**
     * Returns entries newest first.
     *
     * @return list<LogEntry>
     */
    public static function all(): array
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['time'], $entry['message'])) {
                continue;
            }
            $out[] = [
                'time' => (string) $entry['time'],
                'message' => (string) $entry['message'],
                'http' => (int) ($entry['http'] ?? 0),
                'post_id' => (int) ($entry['post_id'] ?? 0),
                'bulk_id' => (string) ($entry['bulk_id'] ?? ''),
                'body' => (string) ($entry['body'] ?? ''),
            ];
        }
        return $out;
    }

    /** Clears the log. */
    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Redacts the API key and known secret fields.
     *
     * @param string $text Text that may contain secrets.
     */
    public static function redact(string $text): string
    {
        $key = Settings::apiKey();
        if ($key !== '') {
            $text = str_replace($key, '[redacted]', $text);
        }
        return (string) preg_replace('/("?(?:api_key|x-api-key|authorization)"?\s*[:=]\s*)("?)[^",\s]+\2/i', '$1$2[redacted]$2', $text);
    }
}
