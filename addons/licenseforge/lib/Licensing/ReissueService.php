<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Api\ErrorCodes;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\KeyGenerator;
use LicenseForge\Support\Net;
use LicenseForge\Support\RateLimiter;
use LicenseForge\Support\Settings;

/**
 * Moving a licence from one installation to another.
 *
 * A reissue retires every installation currently bound to a licence and frees
 * it to be activated somewhere else, optionally rotating the key. It is what a
 * customer needs after migrating servers, rebuilding a machine, or losing an
 * installation they can no longer release from the client area.
 *
 * Because it hands back every activation slot at once, it is also the obvious
 * way to share a licence - so it is bounded on three axes: a per-licence quota,
 * a cooldown between uses, and optionally an approval step. All three are
 * enforced under a row lock, not merely checked, since the interesting failure
 * is two requests arriving together.
 *
 * Every admissibility decision is made twice: once cheaply before any lock, and
 * again against the locked licence row inside the transaction that acts on it.
 * The first is a fast path; only the second is enforcement. Between them the
 * licence can be revoked, its product's policy switched off, or its quota
 * consumed by a concurrent request.
 */
final class ReissueService
{
    /*
     * Who initiated a reissue. Not merely attribution: BY_ADMIN bypasses the
     * self-service policy and the customer quota, because staff are expected to
     * override them. It never bypasses the licence's own admissibility.
     */
    public const BY_CLIENT = 'client';
    public const BY_ADMIN  = 'admin';
    public const BY_API    = 'api';
    public const BY_SYSTEM = 'system';

    /**
     * Request lifecycle.
     *
     *   pending ──approve()──> processing ──execute() ok───> completed
     *      │                        └──────execute() failed─> failed
     *      └──reject()───────────────────────────────────────> rejected
     *
     * PROCESSING exists because approve() cannot know the outcome when it claims
     * the request: execute() re-validates against the locked licence row and may
     * refuse. It is the only status that is not a resting place, and
     * {@see sweepStalled()} returns rows abandoned in it - a process killed
     * between the claim and the resolution - to pending, where an administrator
     * can decide again.
     *
     * FAILED records a decision that was made and could not be carried out,
     * which is a different fact from one that was refused (rejected) and from
     * one that worked (completed). Collapsing it into either loses the reason
     * the customer is about to ask about.
     */
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_REJECTED   = 'rejected';

    /**
     * How long a claimed request may sit in `processing` before the sweep treats
     * it as abandoned.
     *
     * Generous: execute() is a short transaction, so anything still here after
     * this did not survive its request.
     */
    private const STALLED_AFTER_SECONDS = 900;

    /**
     * May this actor reissue this licence right now?
     *
     * A terminal licence - revoked or terminated - can never be reissued whoever
     * asks. Reissuing means freeing a licence for use elsewhere, which is
     * precisely what those states exist to prevent.
     *
     * Everything else is waived for an administrator: the product's
     * self-service setting, the quota and the cooldown all exist to bound what a
     * customer may do unaided.
     *
     * Returns the resolved policy on success, so the caller need not read it
     * again - and so the decision and the values it was made from cannot
     * diverge.
     */
    public static function check(object $license, string $initiatedBy = self::BY_CLIENT): CheckResult
    {
        if (LicenseStatus::isTerminal((string) $license->status)) {
            return CheckResult::fail(ErrorCodes::REISSUE_NOT_ALLOWED, 'This license can no longer be reissued.');
        }

        $policy = ProductConfig::policyForLicense($license);

        if ($initiatedBy !== self::BY_ADMIN) {
            if (!$policy['reissue_self_service'] || !Settings::bool('reissue_self_service', true)) {
                return CheckResult::fail(ErrorCodes::REISSUE_NOT_ALLOWED, 'Self-service reissuing is disabled for this product.');
            }

            $violation = self::quotaViolation($license, $policy);
            if ($violation !== null) {
                return $violation;
            }
        }

        return CheckResult::ok(['policy' => $policy]);
    }

    /**
     * The per-licence limits: reissue count, cooldown, and an outstanding request.
     *
     * Split out from {@see check()} so it can be re-evaluated inside a
     * transaction against a row-locked copy of the licence, which is what
     * actually makes the limit safe against concurrent requests. Checking it
     * only before the lock would let two requests each see quota remaining and
     * both consume it.
     *
     * @param  array<string,mixed> $policy
     * @return CheckResult|null Failure, or null when the reissue is allowed.
     */
    private static function quotaViolation(object $license, array $policy): ?CheckResult
    {
        $limit = (int) $license->max_reissues;
        if ($limit > 0 && (int) $license->reissue_count >= $limit) {
            return CheckResult::fail(ErrorCodes::REISSUE_LIMIT, null, [
                'used' => (int) $license->reissue_count, 'limit' => $limit,
            ]);
        }

        $cooldown = (int) $policy['reissue_cooldown_hours'];
        if ($cooldown > 0 && $license->last_reissued_at !== null) {
            $last = strtotime((string) $license->last_reissued_at . ' UTC');
            if ($last !== false && ($last + ($cooldown * 3600)) > time()) {
                return CheckResult::fail(ErrorCodes::REISSUE_COOLDOWN, null, [
                    'available_at' => gmdate('Y-m-d\TH:i:s\Z', $last + ($cooldown * 3600)),
                ]);
            }
        }

        if (self::hasPendingRequest((int) $license->id)) {
            return CheckResult::fail(ErrorCodes::REISSUE_PENDING);
        }

        return null;
    }

    /**
     * Re-read the licence with `SELECT ... FOR UPDATE`.
     *
     * Every concurrent reissue of the same licence serialises here, so a quota
     * decision made after this point holds for the rest of the transaction. The
     * lock is held until commit.
     *
     * @return object|null Null when the licence has gone.
     */
    private static function lockLicense(int $licenseId): ?object
    {
        $row = Db::table('licenses')->where('id', $licenseId)->lockForUpdate()->first();

        return $row === null ? null : (object) $row;
    }

    /**
     * Is a request for this licence still outstanding?
     *
     * `processing` counts. It is a request an administrator has approved and
     * whose execution has not finished, which is at least as outstanding as an
     * untouched one - and before it existed this check could not miss it,
     * because approve() went straight from pending to a resting status. Leaving
     * it out would let a customer raise a second request during the approval of
     * the first, and a stalled claim returned to the queue would then find a
     * duplicate waiting.
     */
    public static function hasPendingRequest(int $licenseId): bool
    {
        return Db::table('reissues')
            ->where('license_id', $licenseId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->exists();
    }

    /**
     * Reissue a licence, or record a request for approval.
     *
     * The entry point for every caller. Which of the two happens is the
     * product's decision: with approval required, a customer's request is queued
     * for staff rather than applied, while an administrator's is applied
     * immediately.
     *
     * Client-initiated reissues are additionally rate-limited per client, on top
     * of the quota and cooldown - those bound how often a licence may move, this
     * bounds how hard someone may try.
     *
     * A refusal is audited with its reason, so a customer reporting that the
     * button did nothing can be answered from the record.
     *
     * @param  array{reason?:string,activation_id?:int,regenerate_key?:bool,new_domain?:string,initiator_id?:int} $options
     * @return CheckResult On success, `pending` is true when approval is required.
     */
    public static function reissue(object $license, string $initiatedBy = self::BY_CLIENT, array $options = []): CheckResult
    {
        $check = self::check($license, $initiatedBy);
        if ($check->failed()) {
            Audit::log('license.reissue_denied', (int) $license->id, Audit::RESULT_DENIED, [
                'code' => $check->code(), 'initiated_by' => $initiatedBy,
            ]);

            return $check;
        }
        $policy = $check->get('policy', []);

        if ($initiatedBy === self::BY_CLIENT) {
            $limit = Settings::int('rate_limit_reissue_client', 5);
            $hit   = RateLimiter::hit('reissue:client', (string) $license->client_id, $limit, 3600);
            if (!$hit['allowed']) {
                return CheckResult::fail(ErrorCodes::RATE_LIMITED, 'Too many reissue attempts. Please try again later.');
            }
        }

        $reason       = mb_substr((string) ($options['reason'] ?? ''), 0, 500);
        $activationId = (int) ($options['activation_id'] ?? 0);
        $newDomain    = Net::normaliseDomain((string) ($options['new_domain'] ?? ''));
        $initiatorId  = isset($options['initiator_id']) ? (int) $options['initiator_id'] : null;

        $requiresApproval = $initiatedBy !== self::BY_ADMIN
            && (bool) ($policy['reissue_requires_approval'] ?? false);

        // The nominated activation, constrained to this licence so an id from
        // elsewhere cannot be named. Falls back to the oldest active one, which
        // is what the record should show when the caller did not choose.
        $oldActivation = null;
        if ($activationId > 0) {
            $oldActivation = Db::table('activations')
                ->where('id', $activationId)
                ->where('license_id', (int) $license->id)
                ->first();
        }
        if ($oldActivation === null) {
            $oldActivation = Db::table('activations')
                ->where('license_id', (int) $license->id)
                ->where('status', 'active')
                ->orderBy('id', 'asc')
                ->first();
        }

        $now = Db::now();

        if ($requiresApproval) {
            $denied    = null;
            $requestId = (int) Db::transaction(static function () use ($license, $oldActivation, $newDomain, $reason, $initiatedBy, $initiatorId, $now, &$denied): int {
                $locked = self::lockLicense((int) $license->id);
                if ($locked === null) {
                    $denied = CheckResult::fail(ErrorCodes::INVALID_LICENSE);

                    return 0;
                }

                /*
                 * Re-run the whole admissibility check, not just the quota,
                 * against the locked row. The quota was the race that bit first,
                 * but it is not the only state that can move: the licence can be
                 * suspended or revoked, the product's self-service policy
                 * switched off, or the cooldown changed, all between the
                 * caller's check() and this lock. check() also recomputes the
                 * policy from the locked row, so a stale policy cannot authorise
                 * a reissue either.
                 */
                $revalidated = self::check($locked, $initiatedBy);
                if ($revalidated->failed()) {
                    $denied = $revalidated;

                    return 0;
                }

                return (int) Db::table('reissues')->insertGetId([
                    'license_id'        => (int) $license->id,
                    'old_activation_id' => $oldActivation !== null ? (int) $oldActivation->id : null,

                    // Masked for display, hashed for identification - the
                    // history never stores a usable key.
                    'old_key'           => KeyGenerator::mask((string) $locked->license_key),
                    'old_key_hash'      => hash('sha256', KeyGenerator::normalise((string) $locked->license_key)),
                    'old_domain'        => $oldActivation !== null ? $oldActivation->domain : $locked->primary_domain,
                    'new_domain'        => $newDomain !== '' ? $newDomain : null,
                    'status'            => self::STATUS_PENDING,
                    'reason'            => $reason,
                    'initiated_by'      => $initiatedBy,
                    'initiator_id'      => $initiatorId,
                    'ip_address'        => Net::clientIp(),
                    'created_at'        => $now,
                ]);
            });

            if ($denied !== null) {
                Audit::log('license.reissue_denied', (int) $license->id, Audit::RESULT_DENIED, [
                    'code' => $denied->code(), 'initiated_by' => $initiatedBy,
                ]);

                return $denied;
            }

            Audit::log('license.reissue_requested', (int) $license->id, Audit::RESULT_SUCCESS, [
                'request_id' => $requestId, 'reason' => $reason,
            ]);

            return CheckResult::ok(['pending' => true, 'request_id' => $requestId]);
        }

        return self::execute($license, $initiatedBy, [
            'reason'          => $reason,
            'old_activation'  => $oldActivation,
            'new_domain'      => $newDomain,
            'regenerate_key'  => !empty($options['regenerate_key']),
            'initiator_id'    => $initiatorId,
        ]);
    }

    /**
     * Apply a reissue immediately.
     *
     * Everything happens in one transaction under the licence row lock: the
     * admissibility re-check, retiring the installations, rotating the key,
     * resetting the counters, and recording the outcome. Either all of it lands
     * or none does.
     *
     * `request_id` resolves an existing request row in place instead of
     * recording a new one - see the write at the end of the transaction. Only
     * {@see approve()} passes it; a reissue that nobody had to ask for has no
     * row to resolve.
     *
     * @param array<string,mixed> $options
     */
    public static function execute(object $license, string $initiatedBy, array $options = []): CheckResult
    {
        $oldActivation = $options['old_activation'] ?? null;
        $reason        = (string) ($options['reason'] ?? '');
        $newDomain     = (string) ($options['new_domain'] ?? '');
        $regenerate    = !empty($options['regenerate_key']);
        $oldKey        = (string) $license->license_key;
        $requestId     = (int) ($options['request_id'] ?? 0);

        /*
         * The replacement key is NOT minted here.
         *
         * It used to be, from a policy read before the lock - so a product's key
         * prefix changed between this point and the commit produced a key in the
         * old format while the reissue recorded the new policy. Nothing rejects
         * such a key, which is why it went unnoticed: it is simply a key that
         * does not look like the ones issued either side of it, and the support
         * question it eventually raises has no answer left in the record.
         *
         * Minted inside the transaction instead, from the policy recomputed
         * against the locked row.
         */
        $newKey = $oldKey;

        $denied    = null;
        $reissueId = (int) Db::transaction(static function () use ($license, $oldActivation, $reason, $newDomain, $regenerate, $initiatedBy, $options, $requestId, &$denied, &$newKey, &$oldKey): int {
            $now = Db::now();

            $locked = self::lockLicense((int) $license->id);
            if ($locked === null) {
                $denied = CheckResult::fail(ErrorCodes::INVALID_LICENSE);

                return 0;
            }

            $revalidated = self::check($locked, $initiatedBy);
            if ($revalidated->failed()) {
                $denied = $revalidated;

                return 0;
            }

            $oldKey = (string) $locked->license_key;

            if ($regenerate) {
                $lockedPolicy = $revalidated->get('policy', []);
                $prefix       = (string) ($lockedPolicy['key_prefix'] ?? '');

                $newKey = KeyGenerator::issue($prefix !== '' ? $prefix : null);
            } else {
                $newKey = $oldKey;
            }

            /*
             * Every active installation, not just the one that asked.
             *
             * This used to retire only the nominated activation while still
             * setting the count to zero. On a licence allowing three
             * installations that left two rows active and a counter claiming
             * none: the survivors kept their credentials and kept validating,
             * and the licence had two installations it did not know about.
             * Reissue means "retire what exists and move the licence", so it has
             * to mean that for all of them.
             *
             * Their credentials are cleared with them. A reissued activation
             * cannot validate anyway, but leaving a usable secret or public key
             * on it keeps material that authenticates nothing - and would
             * authenticate again if the row were ever reactivated.
             */
            Db::table('activations')
                ->where('license_id', (int) $license->id)
                ->where('status', 'active')
                ->update([
                    'status'             => 'reissued',
                    'deactivated_at'     => $now,
                    'deactivated_reason' => $reason !== ''
                        ? 'reissued: ' . mb_substr($reason, 0, 150)
                        : 'reissued',
                    'updated_at'         => $now,

                    'install_secret'        => null,
                    'install_secret_at'     => null,
                    'install_public_key'    => null,
                    'install_key_algorithm' => null,
                    'install_key_at'        => null,
                ]);

            // The licence's primary bindings are cleared so the next activation
            // establishes them afresh - that is what "moved" means.
            $licenseUpdates = [
                'license_key'        => $newKey,
                'reissue_count'      => Db::raw('reissue_count + 1'),
                'last_reissued_at'   => $now,
                'activation_count'   => 0,
                'primary_domain'     => $newDomain !== '' ? $newDomain : null,
                'primary_ip'         => null,
                'primary_directory'  => null,
                'primary_machine_id' => null,
                'updated_at'         => $now,
            ];
            Db::table('licenses')->where('id', (int) $license->id)->update($licenseUpdates);

            $outcome = [
                'old_activation_id' => $oldActivation !== null ? (int) $oldActivation->id : null,
                'new_activation_id' => null,
                'old_key'           => KeyGenerator::mask($oldKey),
                'old_key_hash'      => hash('sha256', KeyGenerator::normalise($oldKey)),
                'new_key'           => KeyGenerator::mask($newKey),
                'new_key_hash'      => hash('sha256', KeyGenerator::normalise($newKey)),
                'old_domain'        => $oldActivation !== null ? $oldActivation->domain : $locked->primary_domain,
                'new_domain'        => $newDomain !== '' ? $newDomain : null,
                'status'            => self::STATUS_COMPLETED,
                'resolved_at'       => $now,
            ];

            if ($requestId > 0) {
                Db::table('reissues')->where('id', $requestId)->update($outcome);

                return $requestId;
            }

            return (int) Db::table('reissues')->insertGetId($outcome + [
                'license_id'   => (int) $license->id,
                'reason'       => $reason,
                'initiated_by' => $initiatedBy,
                'initiator_id' => $options['initiator_id'] ?? null,
                'ip_address'   => Net::clientIp(),
                'created_at'   => $now,
            ]);
        });

        if ($denied !== null) {
            Audit::log('license.reissue_denied', (int) $license->id, Audit::RESULT_DENIED, [
                'code' => $denied->code(), 'initiated_by' => $initiatedBy, 'stage' => 'locked',
            ]);

            return $denied;
        }

        $updated = LicenseManager::find((int) $license->id);

        Audit::log('license.reissued', (int) $license->id, Audit::RESULT_SUCCESS, [
            'reissue_id'   => $reissueId,
            'initiated_by' => $initiatedBy,
            'key_rotated'  => $newKey !== $oldKey,
            'new_domain'   => $newDomain,
            'reason'       => $reason,
        ]);

        AbuseDetector::onReissue($updated);
        Notifier::licenseReissued($updated, $oldKey);

        return CheckResult::ok([
            'license'    => $updated,
            'reissue_id' => $reissueId,
            'new_key'    => $newKey,
            'key_rotated' => $newKey !== $oldKey,
        ]);
    }

    /**
     * Approve a pending reissue request.
     *
     * Approval is not the same as success. execute() re-validates against the
     * locked licence row and can still refuse - the licence may have been
     * revoked, the policy switched off, or the quota exhausted between the
     * customer asking and the administrator clicking approve.
     *
     * So the request is claimed as `processing` rather than completed, and
     * resolved afterwards from what actually happened. Writing 'completed' up
     * front recorded an outcome that had not occurred yet, and when the refusal
     * came back the row kept saying completed: the queue showed it resolved, the
     * audit log said approved, the licence was untouched, and nothing anywhere
     * disagreed.
     *
     * The claim is also the concurrency guard - scoped to `pending` and acted on
     * only if it moved a row, so two administrators approving at once produce
     * one reissue.
     */
    public static function approve(int $requestId, int $adminId): CheckResult
    {
        $request = Db::table('reissues')->where('id', $requestId)->where('status', self::STATUS_PENDING)->first();
        if ($request === null) {
            return CheckResult::fail(ErrorCodes::INVALID_REQUEST, 'No pending reissue request with that ID.');
        }
        $license = LicenseManager::find((int) $request->license_id);
        if ($license === null) {
            return CheckResult::fail(ErrorCodes::INVALID_LICENSE);
        }

        $oldActivation = $request->old_activation_id !== null
            ? Db::table('activations')->where('id', (int) $request->old_activation_id)->first()
            : null;

        $claimed = Db::table('reissues')
            ->where('id', $requestId)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'     => self::STATUS_PROCESSING,
                'claimed_at' => Db::now(),
            ]);

        if ($claimed === 0) {
            return CheckResult::fail(ErrorCodes::INVALID_REQUEST, 'That reissue request has already been resolved.');
        }

        $result = self::execute($license, self::BY_ADMIN, [
            'reason'         => (string) $request->reason,
            'old_activation' => $oldActivation,
            'new_domain'     => (string) ($request->new_domain ?? ''),
            'initiator_id'   => $adminId,
            'request_id'     => $requestId,
        ]);

        if ($result->failed()) {
            Db::table('reissues')
                ->where('id', $requestId)
                ->update([
                    'status'      => self::STATUS_FAILED,
                    'resolved_at' => Db::now(),
                ]);

            Audit::log('license.reissue_approval_failed', (int) $license->id, Audit::RESULT_FAILURE, [
                'request_id' => $requestId,
                'admin_id'   => $adminId,
                'code'       => $result->code(),
            ]);

            return $result;
        }

        Audit::log('license.reissue_approved', (int) $license->id, Audit::RESULT_SUCCESS, [
            'request_id' => $requestId, 'admin_id' => $adminId,
        ]);

        return $result;
    }

    /**
     * Reject a pending request, with an optional reason for the record.
     *
     * Scoped to `pending` in the same way as the approval claim, so a request
     * already being processed cannot be rejected out from under it.
     */
    public static function reject(int $requestId, int $adminId, string $reason = ''): bool
    {
        $request = Db::table('reissues')->where('id', $requestId)->where('status', self::STATUS_PENDING)->first();
        if ($request === null) {
            return false;
        }

        $claimed = Db::table('reissues')
            ->where('id', $requestId)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'      => self::STATUS_REJECTED,
                'reason'      => mb_substr(((string) $request->reason) . ' | rejected: ' . $reason, 0, 500),
                'resolved_at' => Db::now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        Audit::log('license.reissue_rejected', (int) $request->license_id, Audit::RESULT_DENIED, [
            'request_id' => $requestId, 'admin_id' => $adminId, 'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Every reissue recorded against a licence, newest first.
     *
     * Keys appear masked, with a hash beside them - see the storage note in
     * {@see execute()}. Enough to identify which reissue retired a given key,
     * without the history holding usable credentials.
     *
     * @return iterable<object>
     */
    public static function history(int $licenseId)
    {
        return Db::table('reissues')->where('license_id', $licenseId)->orderBy('id', 'desc')->get();
    }

    /**
     * Requests awaiting an administrator's decision.
     *
     * @return iterable<object>
     */
    public static function pending()
    {
        return Db::table('reissues')->where('status', self::STATUS_PENDING)->orderBy('id', 'desc')->get();
    }

    /**
     * Return abandoned claims to the queue.
     *
     * `processing` is held only across execute(), which is one short
     * transaction. A row still sitting in it long afterwards means the request
     * that claimed it died - a PHP fatal, a killed worker, a dropped connection
     * - and nothing will ever resolve it. Left alone it is invisible in both
     * directions: gone from the pending queue an administrator watches, and
     * never appearing as completed or failed.
     *
     * Returning it to pending is safe whichever side of execute() the process
     * died on. If it died before, nothing happened and the request is genuinely
     * still pending. If it died after, the licence has already moved, and
     * re-approving re-runs the full admissibility check against the locked row -
     * which now sees the new state and refuses, marking it failed. Neither path
     * can reissue twice, because that decision is made under the licence lock
     * rather than by this status.
     *
     * @return int Rows returned to the queue.
     */
    public static function sweepStalled(): int
    {
        /*
         * claimed_at, not created_at: the question is how long this has been
         * held, not how long ago the customer asked. A request raised last week
         * and approved a moment ago is not stalled.
         *
         * A null claimed_at is included deliberately. It means the row reached
         * `processing` before the column existed, or by some path that does not
         * set it; either way nothing is going to resolve it, and leaving it out
         * would make the one row the sweep exists for the one row it skips.
         */
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALLED_AFTER_SECONDS);

        $stalled = Db::table('reissues')
            ->where('status', self::STATUS_PROCESSING)
            ->where(static function ($query) use ($cutoff): void {
                $query->where('claimed_at', '<', $cutoff)->orWhereNull('claimed_at');
            })
            ->get();

        $returned = 0;
        foreach ($stalled as $row) {
            // Scoped to `processing` again, so a row resolved between the read
            // and this write is left alone rather than dragged back to pending.
            $moved = Db::table('reissues')
                ->where('id', (int) $row->id)
                ->where('status', self::STATUS_PROCESSING)
                ->update([
                    'status'     => self::STATUS_PENDING,
                    'claimed_at' => null,
                ]);

            if ($moved === 0) {
                continue;
            }

            $returned++;

            Audit::log('license.reissue_stalled', (int) $row->license_id, Audit::RESULT_FAILURE, [
                'request_id' => (int) $row->id,
                'reason'     => 'claimed for approval but never resolved; returned to the queue',
            ], Audit::ACTOR_SYSTEM);
        }

        return $returned;
    }
}
