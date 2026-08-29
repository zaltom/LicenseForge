<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * The audit log: who did what, when, and whether it was allowed.
 *
 * Written for every state change and every refusal that matters - licences
 * issued and revoked, credentials rotated, settings saved, authentication
 * failures, abuse flags, cron runs. It answers the question that arises long
 * afterwards: why is this customer's licence in this state?
 *
 * Two properties make it trustworthy. It never breaks the operation it records:
 * every write is wrapped, and a failure goes to PHP's error log rather than
 * being raised. And it never becomes a source of credentials: metadata is
 * redacted on the way in, by key name - see {@see encodeMetadata()}.
 *
 * Retained far longer than any other record; {@see prune()} defaults to two
 * years, because this is the data people ask about long after the fact.
 */
final class Audit
{
    /*
     * Who performed the action. Recorded rather than inferred at read time, so
     * an entry still identifies its actor after a staff member or client is
     * deleted.
     */
    public const ACTOR_ADMIN  = 'admin';
    public const ACTOR_CLIENT = 'client';
    public const ACTOR_API    = 'api';
    public const ACTOR_SYSTEM = 'system';

    /*
     * How it ended. `denied` is distinct from `failure` deliberately: one is a
     * rule refusing something, the other is something going wrong. Collapsing
     * them would make a working security control indistinguishable from a
     * broken feature.
     */
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_DENIED  = 'denied';

    /**
     * Record one auditable event.
     *
     * The actor is detected from the request when not supplied, but an explicit
     * actor overrides it, because the code performing an action is not always
     * running as the party responsible: cron acting on a licence is the system;
     * an admin action queued and applied later is still the administrator.
     *
     * Everything is length-bounded before insertion, since an over-long value
     * would fail the write and lose the entry rather than truncating it.
     *
     * Never throws. Callers may log without a try/catch and none checks a return
     * value, because a failed audit write must not become a failed licensing
     * operation.
     *
     * @param string              $action    Dotted event name, e.g. `license.revoked`.
     * @param int|null            $licenseId The licence concerned, if any.
     * @param array<string,mixed> $metadata  Detail; redacted by key - see
     *   {@see encodeMetadata()}.
     * @param string|null         $actorType Overrides detection.
     */
    public static function log(
        string $action,
        ?int $licenseId = null,
        string $result = self::RESULT_SUCCESS,
        array $metadata = [],
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorName = null
    ): void {
        try {
            if ($actorType === null) {
                [$actorType, $actorId, $actorName] = self::detectActor();
            }

            Db::table('audit_logs')->insert([
                'license_id'  => $licenseId,
                'action'      => substr($action, 0, 64),
                'result'      => $result,
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_name'  => $actorName !== null ? substr($actorName, 0, 190) : null,
                'ip_address'  => Net::clientIp(),
                'metadata'    => self::encodeMetadata($metadata),
                'created_at'  => Db::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('[LicenseForge] audit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Work out who is acting, from the current request.
     *
     * Checked most-specific first: an admin session, then a client session, then
     * the command line, and finally the API - the fallback, because an
     * authenticated API call has no session at all.
     *
     * The order matters: staff acting through the admin area also have no client
     * session, but a client may have neither, so testing the admin session first
     * is what keeps an administrator's actions attributed to them.
     *
     * @return array{0:string,1:?int,2:?string} Actor type, id and name.
     */
    private static function detectActor(): array
    {
        if (isset($_SESSION['adminid']) && (int) $_SESSION['adminid'] > 0) {
            return [self::ACTOR_ADMIN, (int) $_SESSION['adminid'], (string) ($_SESSION['adminusername'] ?? 'admin')];
        }
        if (isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 0) {
            return [self::ACTOR_CLIENT, (int) $_SESSION['uid'], null];
        }
        if (PHP_SAPI === 'cli') {
            return [self::ACTOR_SYSTEM, null, 'cron'];
        }

        return [self::ACTOR_API, null, null];
    }

    /**
     * Encode metadata for storage, redacting anything that looks like a secret.
     *
     * Redaction is by key name, matched case-insensitively as a substring and
     * applied recursively. Keying on the name rather than the value is what
     * makes it reliable: a secret is not recognisable by inspection, but the
     * field carrying it is almost always called `secret`, `token`, `signature`
     * or `proof`.
     *
     * A backstop rather than the primary control - callers are expected not to
     * pass credentials at all. It exists because the log is written from dozens
     * of places, retained for years and read by staff, so one careless call site
     * would otherwise be enough.
     *
     * Long values are truncated rather than dropped, so a large payload cannot
     * bloat the table while the entry still records what happened.
     *
     * @param array<string,mixed> $metadata
     */
    private static function encodeMetadata(array $metadata): string
    {
        $forbidden = ['secret', 'password', 'private_key', 'api_secret', 'signature', 'token', 'authorization', 'proof'];

        $walker = static function ($value, $key) use (&$walker, $forbidden) {
            if (is_string($key)) {
                foreach ($forbidden as $needle) {
                    if (stripos($key, $needle) !== false) {
                        return '[redacted]';
                    }
                }
            }
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $walker($v, (string) $k);
                }

                return $out;
            }
            if (is_string($value) && strlen($value) > 2000) {
                return substr($value, 0, 2000) . '…';
            }

            return $value;
        };

        $clean = [];
        foreach ($metadata as $key => $value) {
            $clean[$key] = $walker($value, (string) $key);
        }

        return (string) json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Query the log, with filtering and pagination.
     *
     * Filters combine as an "and", so an administrator narrows by licence,
     * action, outcome, actor and date together, which is how a specific incident
     * is found in a table holding years of entries.
     *
     * The free-text term searches the action, actor name and metadata, with LIKE
     * wildcards escaped so a search for `%` does not match everything.
     *
     * The total is counted from a clone of the query before pagination, so it
     * reflects the filters rather than the page.
     *
     * @param  array<string,mixed> $filters
     * @return array{rows:iterable<object>,total:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $query = Db::table('audit_logs');

        if (!empty($filters['license_id'])) {
            $query->where('license_id', (int) $filters['license_id']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }
        if (!empty($filters['result'])) {
            $query->where('result', (string) $filters['result']);
        }
        if (!empty($filters['actor_type'])) {
            $query->where('actor_type', (string) $filters['actor_type']);
        }
        if (!empty($filters['ip'])) {
            $query->where('ip_address', Net::normaliseIp((string) $filters['ip']));
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', (string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', (string) $filters['to']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $query->where(static function ($q) use ($term): void {
                $q->where('action', 'like', $term)
                  ->orWhere('actor_name', 'like', $term)
                  ->orWhere('metadata', 'like', $term);
            });
        }

        $total = (int) (clone $query)->count();
        $rows  = $query->orderBy('id', 'desc')
            ->forPage(max(1, $page), $perPage)
            ->get();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Delete entries older than the retention period.
     *
     * A retention of zero or less disables pruning entirely, for an installation
     * that keeps its audit history indefinitely or archives it elsewhere.
     *
     * @return int Rows removed.
     */
    public static function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        return (int) Db::table('audit_logs')->where('created_at', '<', $cutoff)->delete();
    }
}
