<?php
/**
 * Storage for queue and results.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Store;

use ZWPangram\Support\Dates;
use ZWPangram\Support\Text;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This repository owns a frequently mutated custom table that must bypass caches.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Dynamic statements use generated placeholder lists and always pass through $wpdb->prepare().

/**
 * Persists queue state and the latest result in one row per post.
 *
 * @phpstan-type Row array{
 *   id: int, post_id: int,
 *   queue_status: string, claim_token: string|null, claimed_at: string|null, bulk_id: string|null, last_bulk_id: string|null,
 *   submitted_hash: string|null, submitted_modified_gmt: string|null, attempts: int, next_attempt_at: string|null,
 *   force: bool, rescan_requested: bool, queued_at: string|null, last_error: string|null,
 *   result_status: string|null, prediction_short: string|null, fraction_ai: float|null, fraction_ai_assisted: float|null,
 *   fraction_human: float|null, headline: string|null, model: string|null, api_version: string|null, scanned_at: string|null,
 *   result_hash: string|null, result_modified_gmt: string|null, result_stale: bool, result_error: string|null,
 *   response_json: string|null, updated_at: string
 * }
 * List queries omit response_json; find() loads it explicitly.
 * The SQL below spells out status values as literals; QueueStatus and ResultStatus define them and document the
 * valid transitions, and every PHP-side comparison goes through those enums.
 */
final class ItemsRepository
{
    public const TABLE = 'zw_pangram_items';
    public const MAX_ATTEMPTS = 3;
    public const STALE_CLAIM_SECONDS = 900;
    public const MAX_BACKOFF_SECONDS = 3600;
    public const ERROR_MAX_BYTES = 500;

    /** @var bool|null Memoized table existence for this request. */
    private static ?bool $tableExists = null;

    /** Columns safe for list queries; excludes the longtext response_json column. */
    private const LIST_COLUMNS = 'id, post_id, queue_status, claim_token, claimed_at, bulk_id, last_bulk_id, submitted_hash, submitted_modified_gmt, attempts, next_attempt_at, force_rescan, rescan_requested, queued_at, last_error, result_status, prediction_short, fraction_ai, fraction_ai_assisted, fraction_human, headline, model, api_version, scanned_at, result_hash, result_modified_gmt, result_stale, result_error, updated_at';

    /** Returns the fully prefixed table name. */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Returns the dbDelta-compatible schema. */
    public static function schema(): string
    {
        global $wpdb;
        $table = self::tableName();
        $collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  queue_status varchar(20) NOT NULL DEFAULT 'none',
  claim_token char(32) DEFAULT NULL,
  claimed_at datetime DEFAULT NULL,
  bulk_id varchar(64) DEFAULT NULL,
  last_bulk_id varchar(64) DEFAULT NULL,
  submitted_hash char(64) DEFAULT NULL,
  submitted_modified_gmt datetime DEFAULT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  force_rescan tinyint(1) NOT NULL DEFAULT 0,
  rescan_requested tinyint(1) NOT NULL DEFAULT 0,
  queued_at datetime DEFAULT NULL,
  last_error varchar(500) DEFAULT NULL,
  result_status varchar(20) DEFAULT NULL,
  prediction_short varchar(10) DEFAULT NULL,
  fraction_ai decimal(5,4) DEFAULT NULL,
  fraction_ai_assisted decimal(5,4) DEFAULT NULL,
  fraction_human decimal(5,4) DEFAULT NULL,
  headline varchar(191) DEFAULT NULL,
  model varchar(64) DEFAULT NULL,
  api_version varchar(16) DEFAULT NULL,
  scanned_at datetime DEFAULT NULL,
  result_hash char(64) DEFAULT NULL,
  result_modified_gmt datetime DEFAULT NULL,
  result_stale tinyint(1) NOT NULL DEFAULT 0,
  result_error varchar(500) DEFAULT NULL,
  response_json longtext DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_id (post_id),
  KEY queue_next (queue_status,next_attempt_at,id),
  KEY claim (claim_token),
  KEY bulk (bulk_id),
  KEY result_ai (result_status,fraction_ai),
  KEY label (prediction_short),
  KEY scanned (scanned_at),
  KEY stale (result_stale,scanned_at)
) {$collate};";
    }

    /** Creates or upgrades the plugin table. */
    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::schema());
        self::$tableExists = null;
    }

    /** Checks table existence once per request. */
    public static function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        global $wpdb;
        self::$tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::tableName()))) === self::tableName();
        return self::$tableExists;
    }

    /**
     * Finds an item by post ID.
     *
     * @param int $postId Post ID.
     * @return Row|null
     */
    public function find(int $postId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d', self::tableName(), $postId), ARRAY_A);
        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * Finds items keyed by post ID.
     *
     * @param list<int> $postIds Post IDs.
     * @return array<int, Row>
     */
    public function findMany(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        global $wpdb;
        $sql = $wpdb->prepare('SELECT ' . self::LIST_COLUMNS . ' FROM %i WHERE post_id IN (' . self::placeholders($postIds) . ')', self::tableName(), ...$postIds); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated from the argument count.
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            if (is_array($row)) {
                $cast = self::cast($row);
                $out[$cast['post_id']] = $cast;
            }
        }
        return $out;
    }

    /**
     * Finds items by claim token.
     *
     * @param string $token Claim token.
     * @return list<Row>
     */
    public function byToken(string $token): array
    {
        global $wpdb;
        return self::castAll($wpdb->get_results($wpdb->prepare('SELECT ' . self::LIST_COLUMNS . ' FROM %i WHERE claim_token = %s ORDER BY id ASC', self::tableName(), $token), ARRAY_A));
    }

    /**
     * Finds submitted items for a bulk job.
     *
     * @param string $bulkId Bulk ID.
     * @return list<Row>
     */
    public function submittedForBulk(string $bulkId): array
    {
        global $wpdb;
        return self::castAll($wpdb->get_results($wpdb->prepare("SELECT " . self::LIST_COLUMNS . " FROM %i WHERE queue_status = 'submitted' AND bulk_id = %s ORDER BY id ASC", self::tableName(), $bulkId), ARRAY_A));
    }

    /**
     * Counts items by queue status.
     *
     * @return array<string, int> queue_status => count (all statuses present, zero-filled).
     */
    public function counts(): array
    {
        global $wpdb;
        $counts = [];
        foreach (QueueStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT queue_status, COUNT(*) AS n FROM %i GROUP BY queue_status', self::tableName()), ARRAY_A) as $row) {
            if (is_array($row) && isset($row['queue_status'])) {
                $counts[(string) $row['queue_status']] = (int) $row['n'];
            }
        }
        return $counts;
    }

    /**
     * Finds active queue statuses for posts.
     *
     * @param list<int> $postIds Post IDs.
     * @return array<int, string>
     */
    public function queuedStatuses(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        global $wpdb;
        $sql = $wpdb->prepare("SELECT post_id, queue_status FROM %i WHERE queue_status IN ('pending','processing','submitted') AND post_id IN (" . self::placeholders($postIds) . ')', self::tableName(), ...$postIds); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            if (is_array($row)) {
                $out[(int) $row['post_id']] = (string) $row['queue_status'];
            }
        }
        return $out;
    }

    /**
     * Finds posts with a current successful result.
     *
     * @param list<int> $postIds Post IDs.
     * @return list<int>
     */
    public function unchangedPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT i.post_id FROM %i AS i INNER JOIN %i AS p ON p.ID = i.post_id
             WHERE i.post_id IN (" . self::placeholders($postIds) . ")
               AND i.result_status = 'ok' AND i.result_stale = 0
               AND i.result_modified_gmt IS NOT NULL AND p.post_modified_gmt <= i.result_modified_gmt",
            self::tableName(),
            $wpdb->posts,
            ...$postIds
        ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    /**
     * Queues posts while preserving in-flight work.
     *
     * Forced in-flight posts are marked for a follow-up scan.
     *
     * @param list<int> $postIds Post IDs.
     * @param bool      $force   Force a rescan of unchanged content.
     */
    public function upsertPending(array $postIds, bool $force): void
    {
        if ($postIds === []) {
            return;
        }
        global $wpdb;
        $now = Dates::nowUtc();
        $values = [];
        $args = [self::tableName()];
        foreach ($postIds as $postId) {
            $values[] = '(%d, \'pending\', %d, %s, %s)';
            array_push($args, $postId, $force ? 1 : 0, $now, $now);
        }
        $sql = 'INSERT INTO %i (post_id, queue_status, force_rescan, queued_at, updated_at) VALUES ' . implode(', ', $values) . "
            ON DUPLICATE KEY UPDATE
              rescan_requested = IF(queue_status IN ('processing','submitted'), IF(VALUES(force_rescan) = 1, 1, rescan_requested), rescan_requested),
              force_rescan     = IF(queue_status IN ('processing','submitted'), force_rescan, VALUES(force_rescan)),
              attempts         = IF(queue_status IN ('processing','submitted'), attempts, 0),
              next_attempt_at  = IF(queue_status IN ('processing','submitted'), next_attempt_at, NULL),
              last_error       = IF(queue_status IN ('processing','submitted'), last_error, NULL),
              queued_at        = IF(queue_status IN ('processing','submitted'), queued_at, VALUES(queued_at)),
              updated_at       = VALUES(updated_at),
              queue_status     = IF(queue_status IN ('processing','submitted'), queue_status, 'pending')";
        $wpdb->query($wpdb->prepare($sql, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
    }

    /**
     * Atomically claims pending items that are due.
     *
     * @param int    $limit Maximum rows.
     * @param string $token New claim token.
     * @return list<Row>
     */
    public function claim(int $limit, string $token): array
    {
        global $wpdb;
        $now = Dates::nowUtc();
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET queue_status = 'processing', claim_token = %s, claimed_at = %s, updated_at = %s
             WHERE queue_status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
             ORDER BY id ASC LIMIT %d",
            self::tableName(),
            $token,
            $now,
            $now,
            $now,
            max(1, $limit)
        ));
        return $this->byToken($token);
    }

    /**
     * Releases claims without consuming an attempt.
     *
     * @param string         $token   Claim token.
     * @param list<int>|null $postIds Subset of post IDs, or all rows with the token.
     */
    public function release(string $token, ?array $postIds = null): void
    {
        if ($postIds === []) {
            return;
        }
        global $wpdb;
        $sql = "UPDATE %i SET queue_status = 'pending', claim_token = NULL, claimed_at = NULL, updated_at = %s WHERE claim_token = %s AND queue_status = 'processing'";
        $args = [self::tableName(), Dates::nowUtc(), $token];
        if ($postIds !== null) {
            $sql .= ' AND post_id IN (' . self::placeholders($postIds) . ')';
            array_push($args, ...$postIds);
        }
        $wpdb->query($wpdb->prepare($sql, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
    }

    /**
     * Reverts attempts after a definitive request rejection.
     *
     * @param string $token Claim token.
     */
    public function unstage(string $token): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET queue_status = 'pending', claim_token = NULL, claimed_at = NULL, attempts = IF(attempts > 0, attempts - 1, 0), updated_at = %s
             WHERE claim_token = %s AND queue_status = 'processing'",
            self::tableName(),
            Dates::nowUtc(),
            $token
        ));
    }

    /**
     * Stages submission snapshots before the HTTP request.
     *
     * Staging consumes an attempt; exhausted items fail and are omitted.
     *
     * @param string                                                $token     Claim token.
     * @param array<int, array{hash: string, modified_gmt: string}> $snapshots Snapshot per post ID.
     * @return array<int, Row> Staged rows keyed by post ID.
     */
    public function stageSubmission(string $token, array $snapshots): array
    {
        global $wpdb;
        $now = Dates::nowUtc();
        $staged = [];
        $rows = $this->findMany(array_keys($snapshots));
        foreach ($snapshots as $postId => $snapshot) {
            $row = $rows[$postId] ?? null;
            if ($row === null || $row['claim_token'] !== $token || $row['queue_status'] !== QueueStatus::Processing->value) {
                continue;
            }
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE %i SET attempts = attempts + 1, submitted_hash = %s, submitted_modified_gmt = %s, updated_at = %s
                 WHERE post_id = %d AND claim_token = %s AND queue_status = 'processing' AND attempts < %d",
                self::tableName(),
                $snapshot['hash'],
                $snapshot['modified_gmt'],
                $now,
                $postId,
                $token,
                self::MAX_ATTEMPTS
            ));
            if ($affected === 1) {
                $staged[] = $postId;
                continue;
            }
            $this->writeFailed($row, 'Too many submission attempts.');
        }
        return $this->findMany($staged);
    }

    /**
     * Links accepted items to a bulk job.
     *
     * @param string    $token   Claim token.
     * @param list<int> $postIds Accepted post IDs.
     * @param string    $bulkId  Bulk ID.
     */
    public function markSubmitted(string $token, array $postIds, string $bulkId): void
    {
        if ($postIds === []) {
            return;
        }
        global $wpdb;
        $sql = "UPDATE %i SET queue_status = 'submitted', bulk_id = %s, claim_token = NULL, claimed_at = NULL, next_attempt_at = NULL, updated_at = %s
                WHERE claim_token = %s AND queue_status = 'processing' AND post_id IN (" . self::placeholders($postIds) . ')';
        $wpdb->query($wpdb->prepare($sql, self::tableName(), $bulkId, Dates::nowUtc(), $token, ...$postIds)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
    }

    /**
     * Backs off claimed items after a consumed attempt.
     *
     * @param string $token Claim token.
     * @param string $error Reason.
     */
    public function backoff(string $token, string $error): void
    {
        foreach ($this->byToken($token) as $row) {
            if ($row['queue_status'] !== QueueStatus::Processing->value) {
                continue;
            }
            if ($row['attempts'] >= self::MAX_ATTEMPTS) {
                $this->writeFailed($row, 'Retries exhausted: ' . $error);
                continue;
            }
            $delay = min(self::MAX_BACKOFF_SECONDS, 60 * (2 ** $row['attempts']));
            $this->update($row['post_id'], [
                'queue_status' => QueueStatus::Pending->value,
                'claim_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => Dates::utcIn($delay),
                'last_error' => self::clip($error),
            ]);
        }
    }

    /**
     * Requeues or fails submitted items without changing attempts.
     *
     * @param string $bulkId Bulk ID.
     * @param bool   $fail   Fail instead of requeue.
     * @param string $reason Reason stored on the row.
     * @return int Number of rows touched.
     */
    public function requeueBulk(string $bulkId, bool $fail, string $reason): int
    {
        $n = 0;
        foreach ($this->submittedForBulk($bulkId) as $row) {
            $n++;
            if ($fail || $row['attempts'] >= self::MAX_ATTEMPTS) {
                $this->writeFailed($row, $reason, $bulkId);
                continue;
            }
            $this->update($row['post_id'], [
                'queue_status' => QueueStatus::Pending->value,
                'bulk_id' => null,
                'next_attempt_at' => null,
                'last_error' => self::clip($reason),
            ]);
        }
        return $n;
    }

    /**
     * Requeues items outside the active bulk job.
     *
     * @param string|null $activeBulkId Active bulk ID, or null when there is no open job.
     * @return int Rows touched.
     */
    public function requeueSubmittedExcept(?string $activeBulkId): int
    {
        global $wpdb;
        $sql = "SELECT DISTINCT bulk_id FROM %i WHERE queue_status = 'submitted'";
        $args = [self::tableName()];
        if ($activeBulkId !== null) {
            $sql .= ' AND (bulk_id IS NULL OR bulk_id <> %s)';
            $args[] = $activeBulkId;
        }
        $n = 0;
        foreach ((array) $wpdb->get_col($wpdb->prepare($sql, ...$args)) as $bulkId) { // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Conditional placeholder.
            $n += $this->requeueBulk((string) $bulkId, false, 'Submitted without an open job; requeued.');
        }
        return $n;
    }

    /**
     * Releases stale claims except active owner tokens.
     *
     * @param list<string> $activeTokens Tokens that are still legitimately in use.
     * @return int Rows released.
     */
    public function releaseStaleProcessing(array $activeTokens): int
    {
        global $wpdb;
        $sql = "UPDATE %i SET queue_status = 'pending', claim_token = NULL, claimed_at = NULL, updated_at = %s
                WHERE queue_status = 'processing' AND claimed_at < %s";
        $args = [self::tableName(), Dates::nowUtc(), Dates::utcIn(-self::STALE_CLAIM_SECONDS)];
        $activeTokens = array_values(array_filter($activeTokens, static fn (string $t): bool => $t !== ''));
        if ($activeTokens !== []) {
            $sql .= ' AND (claim_token IS NULL OR claim_token NOT IN (' . implode(', ', array_fill(0, count($activeTokens), '%s')) . '))';
            array_push($args, ...$activeTokens);
        }
        $result = $wpdb->query($wpdb->prepare($sql, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated placeholder list.
        return is_int($result) ? $result : 0;
    }

    /**
     * Stores a successful result and optionally requeues it.
     *
     * @param Row                  $row           Current row.
     * @param array<string, mixed> $fields        prediction_short, fraction_*, headline, model, api_version, response_json, last_bulk_id, result_stale.
     * @param string|null          $requeueReason Reason to put the row back in the queue, or null.
     */
    public function writeOk(array $row, array $fields, ?string $requeueReason = null): void
    {
        $this->settle($row, [
            'result_status' => ResultStatus::Ok->value,
            'prediction_short' => $fields['prediction_short'],
            'fraction_ai' => $fields['fraction_ai'],
            'fraction_ai_assisted' => $fields['fraction_ai_assisted'],
            'fraction_human' => $fields['fraction_human'],
            'headline' => isset($fields['headline']) ? mb_substr((string) $fields['headline'], 0, 191) : null,
            'model' => $fields['model'],
            'api_version' => isset($fields['api_version']) ? substr((string) $fields['api_version'], 0, 16) : null,
            'result_hash' => $row['submitted_hash'],
            'result_modified_gmt' => $row['submitted_modified_gmt'],
            'result_stale' => !empty($fields['result_stale']) ? 1 : 0,
            'result_error' => null,
            'response_json' => $fields['response_json'],
            'last_bulk_id' => $fields['last_bulk_id'] ?? $row['last_bulk_id'],
        ], QueueStatus::Done, $requeueReason, null);
    }

    /**
     * Stores a failure and clears the successful result fields.
     *
     * @param Row         $row           Current row.
     * @param string      $message       Failure message.
     * @param string|null $bulkId        Bulk ID associated with the failure, if any.
     * @param string|null $requeueReason Reason to requeue instead of failing the queue row.
     */
    public function writeFailed(array $row, string $message, ?string $bulkId = null, ?string $requeueReason = null): void
    {
        $this->settle($row, self::clearedResult() + [
            'result_status' => ResultStatus::Failed->value,
            'result_error' => self::clip($message),
            'last_bulk_id' => $bulkId ?? $row['last_bulk_id'],
        ], QueueStatus::Failed, $requeueReason, $message);
    }

    /**
     * Stores a skipped result and clears successful result fields.
     *
     * @param Row    $row    Current row.
     * @param string $reason Reason.
     */
    public function writeSkipped(array $row, string $reason): void
    {
        $this->settle($row, self::clearedResult() + [
            'result_status' => ResultStatus::Skipped->value,
            'result_error' => self::clip($reason),
        ], QueueStatus::Skipped, null, $reason);
    }

    /**
     * Skips queue work while retaining the stored result.
     *
     * @param Row    $row    Current row.
     * @param string $reason Reason stored in last_error.
     */
    public function markSkippedInQueue(array $row, string $reason): void
    {
        $this->update($row['post_id'], [
            'queue_status' => QueueStatus::Skipped->value,
            'claim_token' => null,
            'claimed_at' => null,
            'next_attempt_at' => null,
            'last_error' => self::clip($reason),
        ]);
    }

    /**
     * Marks a successful result stale.
     *
     * @param int $postId Post ID.
     */
    public function markStale(int $postId): bool
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET result_stale = 1, updated_at = %s WHERE post_id = %d AND result_status = 'ok' AND result_stale = 0",
            self::tableName(),
            Dates::nowUtc(),
            $postId
        ));
        return $result === 1;
    }

    /**
     * Removes stored data for a post.
     *
     * @param int $postId Post ID.
     */
    public function deleteByPostId(int $postId): void
    {
        global $wpdb;
        $wpdb->delete(self::tableName(), ['post_id' => $postId], ['%d']);
    }

    /**
     * Clears queued work while retaining in-flight items and results.
     *
     * @return int Rows touched.
     */
    public function clearQueue(): int
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET queue_status = 'none', claim_token = NULL, claimed_at = NULL, next_attempt_at = NULL, attempts = 0, force_rescan = 0, rescan_requested = 0, last_error = NULL, updated_at = %s
             WHERE queue_status IN ('pending','done','failed','skipped')",
            self::tableName(),
            Dates::nowUtc()
        ));
        return is_int($result) ? $result : 0;
    }

    /**
     * Removes stored response text in resumable chunks.
     *
     * @param int $limit   Rows per call.
     * @param int $afterId Only rows with a larger ID (0 to start).
     * @return array{0: int, 1: int} Rows updated and the last ID examined (0 when no rows were left).
     */
    public function purgeText(int $limit, int $afterId = 0): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, post_id, response_json FROM %i WHERE id > %d AND response_json IS NOT NULL AND response_json LIKE %s ORDER BY id ASC LIMIT %d',
            self::tableName(),
            $afterId,
            '%"text":%',
            max(1, $limit)
        ), ARRAY_A);
        $n = 0;
        $lastId = 0;
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lastId = (int) $row['id'];
            $decoded = json_decode((string) $row['response_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $stripped = self::stripText($decoded);
            $encoded = wp_json_encode($stripped);
            if ($encoded === false) {
                continue;
            }
            $this->update((int) $row['post_id'], ['response_json' => $encoded]);
            $n++;
        }
        return [$n, $lastId];
    }

    /**
     * Removes top-level and per-window text from a result.
     *
     * @param array<string, mixed> $result Result.
     * @return array<string, mixed>
     */
    public static function stripText(array $result): array
    {
        unset($result['text']);
        if (isset($result['windows']) && is_array($result['windows'])) {
            foreach ($result['windows'] as $i => $window) {
                if (is_array($window)) {
                    unset($window['text']);
                    $result['windows'][$i] = $window;
                }
            }
        }
        return $result;
    }

    /**
     * Stores a result, settles its queue state and releases its claim.
     *
     * @param Row                  $row           Current row.
     * @param array<string, mixed> $result        Result columns.
     * @param QueueStatus          $terminal      Queue status when not requeued.
     * @param string|null          $requeueReason Reason to requeue instead.
     * @param string|null          $lastError     last_error when terminal.
     */
    private function settle(array $row, array $result, QueueStatus $terminal, ?string $requeueReason, ?string $lastError): void
    {
        $data = $result + [
            'scanned_at' => Dates::nowUtc(),
            'bulk_id' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'next_attempt_at' => null,
        ] + $this->queueOutcome($terminal, $requeueReason, $lastError);
        $this->update($row['post_id'], $data);
    }

    /**
     * Builds queue columns for a terminal outcome or requeue.
     *
     * @param QueueStatus $terminal      Status when not requeued.
     * @param string|null $requeueReason Reason to requeue.
     * @param string|null $lastError     last_error when terminal.
     * @return array<string, mixed>
     */
    private function queueOutcome(QueueStatus $terminal, ?string $requeueReason, ?string $lastError): array
    {
        if ($requeueReason !== null) {
            return [
                'queue_status' => QueueStatus::Pending->value,
                'force_rescan' => 1,
                'attempts' => 0,
                'rescan_requested' => 0,
                'queued_at' => Dates::nowUtc(),
                'last_error' => self::clip($requeueReason),
            ];
        }
        return [
            'queue_status' => $terminal->value,
            'last_error' => $lastError === null ? null : self::clip($lastError),
        ];
    }

    /**
     * Returns cleared successful result columns.
     *
     * @return array<string, mixed>
     */
    private static function clearedResult(): array
    {
        return [
            'prediction_short' => null,
            'fraction_ai' => null,
            'fraction_ai_assisted' => null,
            'fraction_human' => null,
            'headline' => null,
            'api_version' => null,
            'result_hash' => null,
            'result_modified_gmt' => null,
            'result_stale' => 0,
            'response_json' => null,
        ];
    }

    /**
     * Updates an item by post ID.
     *
     * @param int                  $postId Post ID.
     * @param array<string, mixed> $data   Columns.
     */
    private function update(int $postId, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = Dates::nowUtc();
        $formats = [];
        foreach ($data as $column => $value) {
            $formats[] = match (true) {
                $value === null => null,
                is_int($value), is_bool($value) => '%d',
                is_float($value) => '%f',
                default => '%s',
            };
        }
        // wpdb::update maps null values to "= NULL" regardless of the format.
        $wpdb->update(self::tableName(), $data, ['post_id' => $postId], $formats, ['%d']);
    }

    /**
     * Builds a comma-separated list of integer placeholders.
     *
     * @param list<int> $ids IDs.
     */
    private static function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '%d'));
    }

    /**
     * Clips text to the error column limit.
     *
     * @param string $text Text.
     */
    private static function clip(string $text): string
    {
        return Text::clip($text, self::ERROR_MAX_BYTES);
    }

    /**
     * Casts a database row to its typed shape.
     *
     * @param array<string, mixed> $row Raw row.
     * @return Row
     */
    private static function cast(array $row): array
    {
        $str = static fn (string $k): ?string => isset($row[$k]) ? (string) $row[$k] : null;
        $flt = static fn (string $k): ?float => isset($row[$k]) ? (float) $row[$k] : null;
        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'queue_status' => (string) $row['queue_status'],
            'claim_token' => $str('claim_token'),
            'claimed_at' => $str('claimed_at'),
            'bulk_id' => $str('bulk_id'),
            'last_bulk_id' => $str('last_bulk_id'),
            'submitted_hash' => $str('submitted_hash'),
            'submitted_modified_gmt' => $str('submitted_modified_gmt'),
            'attempts' => (int) $row['attempts'],
            'next_attempt_at' => $str('next_attempt_at'),
            'force' => (bool) $row['force_rescan'],
            'rescan_requested' => (bool) $row['rescan_requested'],
            'queued_at' => $str('queued_at'),
            'last_error' => $str('last_error'),
            'result_status' => $str('result_status'),
            'prediction_short' => $str('prediction_short'),
            'fraction_ai' => $flt('fraction_ai'),
            'fraction_ai_assisted' => $flt('fraction_ai_assisted'),
            'fraction_human' => $flt('fraction_human'),
            'headline' => $str('headline'),
            'model' => $str('model'),
            'api_version' => $str('api_version'),
            'scanned_at' => $str('scanned_at'),
            'result_hash' => $str('result_hash'),
            'result_modified_gmt' => $str('result_modified_gmt'),
            'result_stale' => (bool) $row['result_stale'],
            'result_error' => $str('result_error'),
            'response_json' => $str('response_json'),
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Casts database rows to typed shapes.
     *
     * @param mixed $rows get_results() output.
     * @return list<Row>
     */
    private static function castAll(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $out[] = self::cast($row);
            }
        }
        return $out;
    }
}
