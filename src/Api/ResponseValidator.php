<?php
/**
 * Strict validation of Pangram responses.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Api;

/**
 * Validates every response shape before storage.
 */
final class ResponseValidator
{
    public const ITEM_ID_PATTERN = '/^post-(\d{1,20})$/';
    public const MODEL_PATTERN = '/^[a-z0-9._-]{1,64}$/i';
    public const PREDICTIONS = ['AI', 'Human', 'Mixed'];
    public const BULK_STATUSES = ['queued', 'running', 'succeeded', 'failed', 'partial'];

    /**
     * Validates a GET /models response.
     *
     * @param mixed $data Decoded JSON.
     * @return list<string>|null
     */
    public static function models(mixed $data): ?array
    {
        if (!is_array($data) || !isset($data['models']) || !is_array($data['models'])) {
            return null;
        }
        $models = [];
        foreach ($data['models'] as $model) {
            if (!is_string($model) || !preg_match(self::MODEL_PATTERN, $model)) {
                return null;
            }
            $models[] = $model;
        }
        return $models;
    }

    /**
     * Validates a POST /bulk response.
     *
     * @param mixed $data Decoded JSON.
     */
    public static function bulkAccepted(mixed $data): bool
    {
        if (!is_array($data) || !isset($data['bulk_id']) || !is_string($data['bulk_id']) || $data['bulk_id'] === '' || strlen($data['bulk_id']) > 64) {
            return false;
        }
        if (!isset($data['status']) || !is_string($data['status'])) {
            return false;
        }
        foreach (['accepted_items', 'failed_items'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                return false;
            }
            foreach ($data[$key] ?? [] as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validates a GET /bulk/{id} response.
     *
     * @param mixed $data Decoded JSON.
     */
    public static function bulkStatus(mixed $data): bool
    {
        return is_array($data)
            && isset($data['status'])
            && is_string($data['status'])
            && in_array($data['status'], self::BULK_STATUSES, true);
    }

    /**
     * Validates a GET /bulk/{id}/results response.
     *
     * @param mixed $data Decoded JSON.
     */
    public static function resultsPage(mixed $data): bool
    {
        if (!is_array($data) || !isset($data['total_items']) || !is_numeric($data['total_items'])) {
            return false;
        }
        foreach (['items', 'failed_items'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                return false;
            }
            foreach ($data[$key] ?? [] as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                    return false;
                }
                if (isset($item['result']) && !is_array($item['result'])) {
                    return false;
                }
                if (isset($item['error']) && !is_string($item['error'])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validates one item result.
     *
     * @param mixed $result Decoded item result.
     */
    public static function result(mixed $result): bool
    {
        if (!is_array($result)) {
            return false;
        }
        foreach (['fraction_ai', 'fraction_ai_assisted', 'fraction_human'] as $key) {
            if (!isset($result[$key]) || !self::isFraction($result[$key])) {
                return false;
            }
        }
        $sum = (float) $result['fraction_ai'] + (float) $result['fraction_ai_assisted'] + (float) $result['fraction_human'];
        if ($sum < 0.98 || $sum > 1.02) {
            return false;
        }
        if (!isset($result['prediction_short']) || !is_string($result['prediction_short']) || !in_array($result['prediction_short'], self::PREDICTIONS, true)) {
            return false;
        }
        foreach (['headline', 'prediction', 'version', 'text'] as $key) {
            if (isset($result[$key]) && !is_string($result[$key])) {
                return false;
            }
        }
        if (isset($result['windows'])) {
            if (!is_array($result['windows'])) {
                return false;
            }
            foreach ($result['windows'] as $window) {
                if (!is_array($window)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Decodes a post ID from an item ID.
     *
     * @param string $itemId Item ID, such as "post-123".
     */
    public static function postIdFromItemId(string $itemId): ?int
    {
        if (!preg_match(self::ITEM_ID_PATTERN, $itemId, $m)) {
            return null;
        }
        $id = (int) $m[1];
        return $id > 0 ? $id : null;
    }

    /**
     * Checks for a finite number from 0 through 1.
     *
     * @param mixed $value Value.
     */
    public static function isFraction(mixed $value): bool
    {
        if (!is_int($value) && !is_float($value)) {
            return false;
        }
        $f = (float) $value;
        return is_finite($f) && $f >= 0.0 && $f <= 1.0;
    }
}
