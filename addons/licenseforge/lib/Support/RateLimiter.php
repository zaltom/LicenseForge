<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Fixed-window rate limiting, and the single-use nonces that stop replay.
 *
 * A bucket is identified by (scope, identifier) and holds a counter with the
 * time its window opened. One statement per request keeps this affordable on
 * the licensing hot path, where it runs before anything else.
 *
 * REQUIRES MySQL or MariaDB. This is the one component in the module that is
 * not portable SQL: {@see increment()} depends on `ON DUPLICATE KEY UPDATE` and
 * on MySQL evaluating that clause's assignments left to right, with later
 * expressions seeing earlier ones. That is documented MySQL behaviour, but
 * PostgreSQL and SQLite have no equivalent - a port would need
 * `INSERT ... ON CONFLICT DO UPDATE` and a different way to express the window
 * rollover.
 *
 * WHMCS itself supports only MySQL/MariaDB, so this is no practical limit. It
 * is recorded because the coupling is invisible from the call sites and because
 * failure would be silent: on an engine without that guarantee the window would
 * never roll over and every bucket would count upward forever.
 */
final class RateLimiter
{
    /**
     * Scopes whose limit guards a security boundary rather than smoothing load.
     *
     * Matched as prefixes. For these an unusable counter denies by default:
     * unlike a whole-database outage, a failure confined to the counter table
     * leaves the rest of the API happily serving the very requests the limit
     * exists to cap.
     *
     * An operator who needs one of these open during a counter outage can set
     * its limit to 0, which disables that limiter outright - see {@see hit()}.
     */
    private const CRITICAL_SCOPES = ['activate:', 'api:credential', 'reissue:'];

    /**
     * Consume one unit of quota and report whether the caller may proceed.
     *
     * The counter is incremented by a single atomic statement. A read-then-write
     * sequence would let concurrent requests observe the same value and each
     * write back the same increment, so a caller could exceed the limit simply
     * by issuing requests in parallel - which is how anyone who cares about the
     * limit would issue them.
     *
     * A limit of zero or less disables that limiter and always allows.
     *
     * @param  string    $scope      What is being limited, e.g. `api:activate`.
     * @param  string    $identifier What it is counted against - an IP, a licence
     *   key, a credential id.
     * @param  int       $limit      Requests permitted per window; 0 disables.
     * @param  int|null  $windowSeconds Defaults to the configured window.
     * @param  bool|null $failClosed Deny rather than allow if the counter is
     *   unusable; defaults per scope - see {@see failsClosed()}.
     * @return array{allowed:bool,remaining:int,limit:int,retry_after:int}
     */
    public static function hit(
        string $scope,
        string $identifier,
        int $limit,
        ?int $windowSeconds = null,
        ?bool $failClosed = null
    ): array {
        $windowSeconds = $windowSeconds ?: Settings::int('rate_window_seconds', 60);
        if ($limit <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'limit' => 0, 'retry_after' => 0];
        }

        $bucket = hash('sha256', $scope . '|' . $identifier);
        $now    = time();

        try {
            ['hits' => $hits, 'window_start' => $windowStart] =
                self::increment($bucket, $scope, $now, $windowSeconds);
            $retryAfter = max(1, ($windowStart + $windowSeconds) - $now);

            return [
                'allowed'     => $hits <= $limit,
                'remaining'   => max(0, $limit - $hits),
                'limit'       => $limit,
                'retry_after' => $hits > $limit ? $retryAfter : 0,
            ];
        } catch (\Throwable $e) {
            error_log('[LicenseForge] rate limiter error: ' . $e->getMessage());

            $failClosed = $failClosed ?? self::failsClosed($scope);

            return [
                'allowed'     => !$failClosed,
                'remaining'   => $failClosed ? 0 : $limit,
                'limit'       => $limit,
                'retry_after' => $failClosed ? $windowSeconds : 0,
            ];
        }
    }

    /**
     * Should an unusable counter deny this scope?
     *
     * Two tiers. Load-smoothing limits stay open, because the licensing API
     * needs the same database to answer anything at all - there a counter
     * failure is nearly always a failure everywhere, and denying manufactures an
     * outage rather than preventing an attack. Limits gating a security boundary
     * close, on the reasoning in {@see CRITICAL_SCOPES}.
     *
     * Operators who want the strict reading everywhere set
     * `rate_limit_fail_closed`.
     */
    private static function failsClosed(string $scope): bool
    {
        foreach (self::CRITICAL_SCOPES as $prefix) {
            if (strncmp($scope, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return Settings::bool('rate_limit_fail_closed', false);
    }

    /**
     * Increment the bucket and return its new count, in one statement.
     *
     * MySQL evaluates ON DUPLICATE KEY UPDATE assignments left to right, later
     * expressions seeing values assigned by earlier ones. The order below
     * depends on that: `hits` is decided against the OLD `window_start`,
     * `window_start` then rolls the window over, and `expires_at` uses the NEW
     * `window_start`. Reordering these silently breaks window expiry.
     *
     * The count is read back rather than returned by the statement. The
     * increment is still atomic, and a concurrent request can only make the
     * re-read higher, never lower, so the limit errs toward refusing. The usual
     * trick for returning a value from an upsert, `id = LAST_INSERT_ID(hits)`,
     * is not usable here: it would assign the hit count to the primary key.
     *
     * MySQL reports one affected row for an insert and two for an update, so a
     * fresh bucket needs no second query - and the first hit of a window is the
     * case that must never be wrong.
     *
     * @return array{hits:int,window_start:int}
     */
    private static function increment(string $bucket, string $scope, int $now, int $windowSeconds): array
    {
        $table = Db::name('rate_limits');
        $pdo   = Db::connection()->getPdo();

        $sql = "INSERT INTO `{$table}` (`bucket`, `scope`, `hits`, `window_start`, `expires_at`)
                VALUES (:bucket, :scope, 1, :now, :expires)
                ON DUPLICATE KEY UPDATE
                    `hits`         = IF(:now2 - `window_start` >= :window, 1, `hits` + 1),
                    `window_start` = IF(:now3 - `window_start` >= :window2, :now4, `window_start`),
                    `expires_at`   = `window_start` + :window3";

        // PDO named placeholders cannot be reused within one statement, hence
        // the numbered duplicates of :now and :window.
        $statement = $pdo->prepare($sql);
        $statement->execute([
            'bucket'  => $bucket,
            'scope'   => substr($scope, 0, 64),
            'now'     => $now,
            'expires' => $now + $windowSeconds,
            'now2'    => $now,
            'now3'    => $now,
            'now4'    => $now,
            'window'  => $windowSeconds,
            'window2' => $windowSeconds,
            'window3' => $windowSeconds,
        ]);

        if ($statement->rowCount() === 1) {
            return ['hits' => 1, 'window_start' => $now];
        }

        $row = Db::table('rate_limits')->where('bucket', $bucket)->first();
        if ($row === null) {
            return ['hits' => 1, 'window_start' => $now];
        }

        return [
            'hits'         => max(1, (int) $row->hits),
            'window_start' => (int) $row->window_start,
        ];
    }

    /**
     * Inspect a bucket without consuming quota.
     *
     * Expired windows read as zero. `hits` is only meaningful together with the
     * window it was counted in: {@see hit()} resets it when the window rolls
     * over, but a reader that never increments has nothing to trigger that
     * reset, so returning the raw column would make a count from a window closed
     * hours ago look current.
     *
     * That mattered concretely. The abuse check in the API peeks at the failure
     * bucket and refuses the request before anything increments it, so a caller
     * that tripped the threshold could never clear it by waiting: only a further
     * failure would have rolled the window, and it was no longer getting that
     * far. The block held until the pruning cron removed the row rather than for
     * the period the Retry-After header promised.
     */
    public static function peek(string $scope, string $identifier): int
    {
        $bucket = hash('sha256', $scope . '|' . $identifier);
        $row    = Db::table('rate_limits')->where('bucket', $bucket)->first();
        if ($row === null) {
            return 0;
        }

        return (int) $row->expires_at > time() ? (int) $row->hits : 0;
    }

    /**
     * Clear one bucket, restoring its full quota immediately.
     *
     * For an administrator lifting a limit a customer hit legitimately, without
     * waiting for the window to close.
     */
    public static function reset(string $scope, string $identifier): void
    {
        $bucket = hash('sha256', $scope . '|' . $identifier);
        Db::table('rate_limits')->where('bucket', $bucket)->delete();
    }

    /**
     * Delete buckets whose windows closed long ago.
     *
     * Kept for an hour past expiry rather than removed as soon as they lapse, so
     * {@see peek()} can still distinguish "seen recently, window closed" from
     * "never seen" while the delay matters.
     *
     * @return int Rows removed.
     */
    public static function prune(): int
    {
        return (int) Db::table('rate_limits')->where('expires_at', '<', time() - 3600)->delete();
    }

    /**
     * Record a request nonce, refusing one already used.
     *
     * The uniqueness is the unique index, not a lookup: the insert succeeds for
     * exactly one caller and fails for every other, so two identical requests
     * arriving together cannot both pass. A read-then-insert check would let
     * them.
     *
     * That is why an exception is treated as "already used" rather than raised -
     * a duplicate-key violation is the expected outcome here, and the whole
     * mechanism.
     *
     * Nonces are scoped per credential, so two integrations cannot invalidate
     * each other's by coincidence.
     *
     * @param  int  $ttlSeconds How long to remember it; should exceed the
     *   timestamp skew window, or a request could be replayed after its nonce is
     *   forgotten but while its timestamp is still acceptable.
     * @return bool True if this nonce had not been used.
     */
    public static function consumeNonce(string $nonce, int $credentialId, int $ttlSeconds): bool
    {
        $hash = hash('sha256', $credentialId . '|' . $nonce);
        try {
            Db::table('api_nonces')->insert([
                'nonce_hash'    => $hash,
                'credential_id' => $credentialId,
                'expires_at'    => time() + $ttlSeconds,
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete nonces past their expiry.
     *
     * Safe to remove exactly at expiry: a request bearing a timestamp that old
     * is already refused by the skew check, so the nonce has nothing left to
     * protect.
     *
     * @return int Rows removed.
     */
    public static function pruneNonces(): int
    {
        return (int) Db::table('api_nonces')->where('expires_at', '<', time())->delete();
    }
}
