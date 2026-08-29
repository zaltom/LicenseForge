<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Input;
use LicenseForge\Support\KeyGenerator;
use LicenseForge\Support\Settings;

/**
 * The licence itself: creating it, moving it through its lifecycle, and
 * describing it to clients.
 *
 * The central class of the module. Everything else - provisioning, the API, the
 * admin console, the client area - changes a licence through here, so the
 * lifecycle rules exist in one place and apply identically whoever is asking.
 *
 * Three ideas run through it.
 *
 * Status changes are guarded. Every transition is checked against the permitted
 * moves and applied under a row lock, so a licence cannot be driven into a
 * sequence the rest of the module does not expect, and two concurrent changes
 * cannot interleave into a state neither intended.
 *
 * A status change alone does not reach an installation running offline. Such an
 * installation holds a signed token and is not asking the server anything, so
 * it keeps working until that token expires. The hold is what suppresses
 * issuing further tokens - see {@see hold()} - and {@see offlineHorizon()} is
 * how long the change takes to become universal.
 *
 * A deliberate act outranks an automatic one. A licence a person suspended is
 * held, and no service event may quietly reverse it; the attempt is refused and
 * audited. Otherwise a customer paying an unrelated invoice would reactivate a
 * licence withdrawn on purpose.
 */
final class LicenseManager
{
    /** Offline token format version. Client SDKs refuse a version they predate. */
    public const TOKEN_VERSION = 3;

    /**
     * A licence by id.
     *
     * Soft-deleted licences are excluded unless asked for, so ordinary code
     * cannot act on one by accident. The admin licence page includes them, since
     * that is the only place their history can still be examined.
     *
     * @return object|null
     */
    public static function find(int $id, bool $withTrashed = false)
    {
        $query = Db::table('licenses')->where('id', $id);
        if (!$withTrashed) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    /**
     * A licence by its key.
     *
     * The key is normalised and shape-checked before any query, so a request
     * carrying whitespace, mixed case or something that is not a key at all is
     * handled without touching the database - which matters on the hot path,
     * where this runs for every API call.
     *
     * @return object|null
     */
    public static function findByKey(string $key, bool $withTrashed = false)
    {
        $key = KeyGenerator::normalise($key);
        if ($key === '' || !KeyGenerator::looksValid($key)) {
            return null;
        }
        $query = Db::table('licenses')->where('license_key', $key);
        if (!$withTrashed) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    /**
     * The licence belonging to a WHMCS service.
     *
     * The link between billing and licensing: every provisioning event arrives
     * with a service id and resolves through here.
     *
     * @return object|null
     */
    public static function findByService(int $serviceId)
    {
        return Db::table('licenses')
            ->where('service_id', $serviceId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Every licence a customer holds.
     *
     * @return iterable<object>
     */
    public static function forClient(int $clientId)
    {
        return Db::table('licenses')
            ->where('client_id', $clientId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Issue a new licence.
     *
     * The product's policy is copied onto the licence at issue rather than
     * referenced - activation and reissue limits, version constraints, default
     * entitlements. A customer therefore keeps the terms they were sold under
     * even if the product's are later changed, which is both fairer and what
     * makes a licence auditable years afterwards.
     *
     * The key, the licence row and its entitlements are written in one
     * transaction, so a failure part-way cannot leave a licence with no
     * entitlements or a key reserved against nothing.
     *
     * @param  array<string,mixed> $data
     * @return object The created licence.
     */
    public static function create(array $data)
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $product   = ProductConfig::find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Unknown licensing product.');
        }
        $policy = ProductConfig::policy($product);

        $isTrial   = !empty($data['is_trial']);
        $duration  = array_key_exists('duration_days', $data) && $data['duration_days'] !== null
            ? (int) $data['duration_days']
            : ($isTrial ? (int) $policy['trial_days'] : (int) $policy['duration_days']);

        // A zero duration with no explicit date means lifetime; an explicit date
        // always wins, so "0 days" cannot silently override one.
        $hasExplicitExpiry = !empty($data['expires_at']);
        $lifetime  = !empty($data['is_lifetime']) || (!$hasExplicitExpiry && $duration === 0);
        $expiresAt = null;
        if (!$lifetime) {
            $expiresAt = $hasExplicitExpiry
                ? Input::toDateTime((string) $data['expires_at'])
                : gmdate('Y-m-d H:i:s', time() + ($duration * 86400));
        }

        if (!empty($data['license_key'])) {
            $key = KeyGenerator::normalise((string) $data['license_key']);

            if (!KeyGenerator::looksValid($key)) {
                throw new \InvalidArgumentException('That license key is not a usable shape.');
            }

            // A supplied key is held to the same strength as a generated one: a
            // licence key is a bearer credential whatever produced it.
            $bits = KeyGenerator::entropyOf($key);
            if ((    $bits < KeyGenerator::MINIMUM_ENTROPY_BITS
                  || KeyGenerator::looksPatterned($key))
                && empty($data['allow_weak_key'])) {
                throw new \InvalidArgumentException(sprintf(
                    'That license key carries about %s bits of entropy; %s are required. '
                    . 'Use a longer or more varied key, or pass allow_weak_key for a '
                    . 'deliberate test credential.',
                    number_format($bits, 1),
                    number_format(KeyGenerator::MINIMUM_ENTROPY_BITS, 0)
                ));
            }
        } else {
            $key = KeyGenerator::issue($policy['key_prefix'] !== '' ? $policy['key_prefix'] : null);
        }

        $status = (string) ($data['status'] ?? ($policy['auto_activate'] ? LicenseStatus::ACTIVE : LicenseStatus::PENDING));
        if (!LicenseStatus::exists($status)) {
            $status = LicenseStatus::PENDING;
        }

        $now = Db::now();

        $row = [
            'license_key'       => $key,
            'status'            => $status,
            'product_id'        => $productId,
            'whmcs_product_id'  => (int) ($data['whmcs_product_id'] ?? $product->whmcs_product_id),
            'client_id'         => (int) ($data['client_id'] ?? 0),
            'service_id'        => (int) ($data['service_id'] ?? 0),
            'order_id'          => (int) ($data['order_id'] ?? 0),
            'is_trial'          => $isTrial ? 1 : 0,
            'is_lifetime'       => $lifetime ? 1 : 0,
            'created_at'        => $now,
            'updated_at'        => $now,
            'activated_at'      => $status === LicenseStatus::ACTIVE ? $now : null,
            'expires_at'        => $expiresAt,
            'grace_until'       => null,
            'primary_domain'    => isset($data['domain']) ? \LicenseForge\Support\Net::normaliseDomain((string) $data['domain']) : null,
            'primary_ip'        => isset($data['ip']) ? \LicenseForge\Support\Net::normaliseIp((string) $data['ip']) : null,
            'allowed_domains'   => json_encode(array_values((array) ($data['allowed_domains'] ?? []))),
            'allowed_ips'       => json_encode(array_values((array) ($data['allowed_ips'] ?? []))),
            'max_activations'   => (int) ($data['max_activations'] ?? $policy['max_activations']),
            'activation_count'  => 0,
            'reissue_count'     => 0,
            'max_reissues'      => (int) ($data['max_reissues'] ?? $policy['max_reissues']),
            'min_version'       => $data['min_version'] ?? null,
            'max_version'       => $data['max_version'] ?? null,
            'allowed_versions'  => $data['allowed_versions'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'admin_notes'       => $data['admin_notes'] ?? null,
        ];

        $licenseId = (int) Db::transaction(static function () use ($row, $policy, $data): int {
            $id = (int) Db::table('licenses')->insertGetId($row);

            $features = !empty($data['features']) ? (array) $data['features'] : $policy['default_features'];
            foreach ($features as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }
                Db::table('license_features')->insert([
                    'license_id'   => $id,
                    'feature_slug' => $slug,
                    'enabled'      => 1,
                    'expires_at'   => null,
                    'created_at'   => Db::now(),
                    'updated_at'   => Db::now(),
                ]);
            }

            return $id;
        });

        Audit::log('license.created', $licenseId, Audit::RESULT_SUCCESS, [
            'product_id' => $productId,
            'client_id'  => $row['client_id'],
            'service_id' => $row['service_id'],
            'status'     => $status,
            'expires_at' => $expiresAt,
        ]);

        $license = self::find($licenseId);
        Notifier::licenseCreated($license);

        return $license;
    }

    /**
     * Move a licence to a new status.
     *
     * The single path for every status change, so the transition rules, the
     * timestamps, the audit entry and the customer notification cannot be
     * skipped by one caller doing it directly.
     *
     * Setting the status a licence already has is a success, not a no-op
     * failure: a service sync repeating itself or a form submitted twice is not
     * an error and must not be reported as one.
     *
     * A move the transition table forbids is refused and audited rather than
     * applied. An unknown status throws, because that is a programming error
     * rather than a licensing decision.
     *
     * The whole change happens under a row lock, and the status is re-read
     * inside it - two concurrent changes would otherwise both pass the
     * transition check against the same starting state, and the later one would
     * silently win from an origin that no longer existed.
     *
     * @param array{automatic?:bool,admin_id?:int,service_id?:int} $context
     *   `automatic` marks a change made by a service event rather than a person,
     *   which a hold may refuse - see {@see automaticChangeAllowed()}.
     */
    public static function setStatus(int $licenseId, string $status, string $reason = '', array $context = []): bool
    {
        $automatic = !empty($context['automatic']);

        $license = self::find($licenseId);
        if ($license === null) {
            return false;
        }
        if (!LicenseStatus::exists($status)) {
            throw new \InvalidArgumentException('Unknown license status: ' . $status);
        }
        if ($license->status === $status) {
            return true;
        }
        if (!LicenseStatus::canTransition((string) $license->status, $status)) {
            Audit::log('license.status_rejected', $licenseId, Audit::RESULT_DENIED, [
                'from' => $license->status, 'to' => $status,
            ]);

            return false;
        }

        $now     = Db::now();
        $updates = ['status' => $status, 'updated_at' => $now];

        switch ($status) {
            case LicenseStatus::ACTIVE:
                if ($license->activated_at === null) {
                    $updates['activated_at'] = $now;
                }
                // Returning to active clears the marks of the states it left, so
                // a later suspension records its own date rather than an old one.
                $updates['suspended_at'] = null;
                $updates['revoked_at']   = null;
                $updates['grace_until']  = null;
                break;
            case LicenseStatus::SUSPENDED:
                $updates['suspended_at'] = $now;
                break;
            case LicenseStatus::REVOKED:
            case LicenseStatus::TERMINATED:
                $updates['revoked_at'] = $now;
                break;
        }

        $raced = false;

        Db::transaction(static function () use ($licenseId, $updates, $status, $reason, $automatic, &$raced): void {
            $locked = Db::table('licenses')->where('id', $licenseId)->lockForUpdate()->first();
            if ($locked === null) {
                $raced = true;

                return;
            }

            $current = (string) $locked->status;
            if ($current === $status) {
                return;
            }

            if ($automatic && !self::automaticChangeAllowed($locked, $status)) {
                Audit::log('license.hold_kept', $licenseId, Audit::RESULT_DENIED, [
                    'attempted' => $status,
                    'reason'    => $reason,
                    'held_by'   => $locked->held_by ?? null,
                    'detail'    => 'the hold was placed after the caller checked, and before the row was locked',
                ], Audit::ACTOR_SYSTEM);
                $raced = true;

                return;
            }

            if (!LicenseStatus::canTransition($current, $status)) {
                Audit::log('license.status_rejected', $licenseId, Audit::RESULT_DENIED, [
                    'from'   => $current,
                    'to'     => $status,
                    'reason' => 'the license changed state before this transition was applied',
                ]);
                $raced = true;

                return;
            }

            Db::table('licenses')->where('id', $licenseId)->update($updates);

            // A terminal status frees the licence's installations: nothing may
            // keep occupying a slot on a licence that no longer exists.
            if (in_array($status, [LicenseStatus::REVOKED, LicenseStatus::TERMINATED], true)) {
                Db::table('activations')
                    ->where('license_id', $licenseId)
                    ->where('status', 'active')
                    ->update([
                        'status'             => 'revoked',
                        'deactivated_at'     => Db::now(),
                        'deactivated_reason' => 'license ' . $status,
                        'updated_at'         => Db::now(),
                    ]);
                Db::table('licenses')->where('id', $licenseId)->update(['activation_count' => 0]);
            }
        });

        if ($raced) {
            return false;
        }

        Audit::log('license.status_changed', $licenseId, Audit::RESULT_SUCCESS, [
            'from'   => $license->status,
            'to'     => $status,
            'reason' => $reason,
        ] + $context);

        $updated = self::find($licenseId);
        Notifier::statusChanged($updated, (string) $license->status, $status, $reason);

        return true;
    }

    /**
     * Statuses that should also stop an installation running offline.
     *
     * Callers moving a licence to one of these are expected to place a hold as
     * well, since the status alone does not reach an installation holding a
     * signed token.
     *
     * @return list<string>
     */
    public static function restrictiveStatuses(): array
    {
        return [
            LicenseStatus::SUSPENDED,
            LicenseStatus::EXPIRED,
            LicenseStatus::REVOKED,
            LicenseStatus::TERMINATED,
        ];
    }

    /** Is this licence under a hold? */
    public static function isHeld(object $license): bool
    {
        return ($license->held_at ?? null) !== null;
    }

    /**
     * Suppress offline tokens for this licence, and record who did it.
     *
     * Two effects. New offline tokens stop being issued, so the licence's
     * restriction takes effect everywhere once existing tokens expire - see
     * {@see offlineHorizon()} for when that is.
     *
     * And the hold marks the change as deliberate. An automatic service event
     * cannot reverse a held licence, which is what stops a paid invoice quietly
     * reactivating something staff withdrew on purpose.
     */
    public static function hold(int $id, string $reason, string $by = Audit::ACTOR_ADMIN): bool
    {
        $license = self::find($id);
        if ($license === null) {
            return false;
        }

        Db::table('licenses')->where('id', $id)->update([
            'held_at'     => Db::now(),
            'held_by'     => mb_substr($by, 0, 16),
            'held_reason' => mb_substr($reason, 0, 190),
            'updated_at'  => Db::now(),
        ]);

        Audit::log('license.held', $id, Audit::RESULT_SUCCESS, [
            'status' => $license->status, 'reason' => $reason, 'by' => $by,
        ]);

        return true;
    }

    /**
     * Lift a hold, allowing offline tokens and automatic changes again.
     *
     * Called explicitly by staff, and automatically when a licence returns to a
     * permissive status - the hold describes a restriction, and keeping it after
     * the restriction is lifted would silently prevent the licence working
     * offline.
     */
    public static function releaseHold(int $id, string $reason = ''): bool
    {
        $license = self::find($id);
        if ($license === null || !self::isHeld($license)) {
            return false;
        }

        Db::table('licenses')->where('id', $id)->update([
            'held_at'     => null,
            'held_by'     => null,
            'held_reason' => null,
            'updated_at'  => Db::now(),
        ]);

        Audit::log('license.hold_released', $id, Audit::RESULT_SUCCESS, ['reason' => $reason]);

        return true;
    }

    /**
     * May a service event apply this status change?
     *
     * The rule that makes a hold mean something: an automatic change to a
     * permissive status is refused while a licence is held, because the hold
     * records a person's decision and billing events must not overwrite it.
     *
     * Restrictive changes are always allowed - a held licence being suspended
     * again is not a conflict.
     */
    public static function automaticChangeAllowed(object $license, string $status): bool
    {
        if (!self::isHeld($license)) {
            return true;
        }

        return in_array($status, self::restrictiveStatuses(), true);
    }

    /** Suspend a licence. Callers should also hold it - see {@see hold()}. */
    public static function suspend(int $id, string $reason = ''): bool
    {
        return self::setStatus($id, LicenseStatus::SUSPENDED, $reason);
    }

    /** Return a suspended licence to active. */
    public static function unsuspend(int $id, string $reason = ''): bool
    {
        return self::setStatus($id, LicenseStatus::ACTIVE, $reason);
    }

    /** Revoke a licence permanently. Terminal: it can never be reissued. */
    public static function revoke(int $id, string $reason = ''): bool
    {
        return self::setStatus($id, LicenseStatus::REVOKED, $reason);
    }

    /** Terminate a licence alongside its service. Terminal, as {@see revoke()}. */
    public static function terminate(int $id, string $reason = ''): bool
    {
        return self::setStatus($id, LicenseStatus::TERMINATED, $reason);
    }

    /**
     * Return a licence to active from any state it can reach active from.
     *
     * Including the terminal ones, so a mistaken revocation is recoverable by
     * staff.
     */
    public static function reactivate(int $id, string $reason = ''): bool
    {
        return self::setStatus($id, LicenseStatus::ACTIVE, $reason);
    }

    /** Mark a licence expired. Applied by the cron when its term ends. */
    public static function expire(int $id, string $reason = 'expired'): bool
    {
        return self::setStatus($id, LicenseStatus::EXPIRED, $reason);
    }

    /**
     * Soft-delete a licence.
     *
     * The row is retained and excluded from every listing rather than removed,
     * so the audit trail, validation history and reissue history stay meaningful
     * - all of which reference a licence that would otherwise vanish from
     * underneath them.
     */
    public static function softDelete(int $id, string $reason = ''): bool
    {
        $license = self::find($id);
        if ($license === null) {
            return false;
        }
        Db::table('licenses')->where('id', $id)->update([
            'deleted_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
        Audit::log('license.deleted', $id, Audit::RESULT_SUCCESS, ['reason' => $reason]);

        return true;
    }

    /** Undo a soft delete. */
    public static function restore(int $id): bool
    {
        Db::table('licenses')->where('id', $id)->update([
            'deleted_at' => null,
            'updated_at' => Db::now(),
        ]);
        Audit::log('license.restored', $id);

        return true;
    }

    /**
     * Set or clear a licence's expiry date.
     *
     * Null makes it lifetime. Any grace period is cleared alongside, since a
     * window measured from the old expiry has no meaning against a new one.
     */
    public static function setExpiry(int $id, ?string $expiresAt, string $reason = ''): bool
    {
        $license = self::find($id);
        if ($license === null) {
            return false;
        }

        Db::table('licenses')->where('id', $id)->update([
            'expires_at'  => $expiresAt,
            'is_lifetime' => $expiresAt === null ? 1 : 0,
            'grace_until' => null,
            'updated_at'  => Db::now(),
        ]);

        Audit::log('license.expiry_changed', $id, Audit::RESULT_SUCCESS, [
            'from'   => $license->expires_at,
            'to'     => $expiresAt,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Extend a licence by a number of days.
     *
     * Measured from the current expiry where one is in the future, so
     * consecutive renewals accumulate rather than each resetting the clock - a
     * customer renewing early does not lose the time they had left.
     */
    public static function extend(int $id, int $days, string $reason = 'renewal'): bool
    {
        $license = self::find($id);
        if ($license === null || $days <= 0) {
            return false;
        }
        if ((bool) $license->is_lifetime) {
            return true;
        }

        $base = $license->expires_at !== null ? strtotime((string) $license->expires_at . ' UTC') : time();
        $base = max((int) $base, time());

        return self::setExpiry($id, gmdate('Y-m-d H:i:s', $base + ($days * 86400)), $reason);
    }

    /**
     * Has this licence passed its expiry date?
     *
     * Computed from the date rather than read from the status, so it is correct
     * even before the cron has applied the expiry - nothing that matters should
     * depend on a scheduled task having run.
     *
     * @param bool $includeGrace Treat a licence inside its grace period as
     *   expired. False by default, since grace exists precisely to keep it
     *   working.
     */
    public static function isExpired(object $license, bool $includeGrace = false): bool
    {
        if ((bool) $license->is_lifetime || $license->expires_at === null) {
            return false;
        }
        $expiry = strtotime((string) $license->expires_at . ' UTC');
        if ($expiry === false) {
            return false;
        }
        if ($includeGrace && $license->grace_until !== null) {
            $grace = strtotime((string) $license->grace_until . ' UTC');
            if ($grace !== false) {
                $expiry = max($expiry, $grace);
            }
        }

        return $expiry < time();
    }

    /**
     * Is this licence past expiry but still inside its grace window?
     *
     * The state where a customer keeps working while renewal is arranged. Every
     * entitlement continues - validation, downloads, offline tokens - and the
     * client is told the deadline so it can warn them.
     */
    public static function inGracePeriod(object $license): bool
    {
        if (!self::isExpired($license)) {
            return false;
        }
        $policy = ProductConfig::policyForLicense($license);
        if ((int) $policy['grace_days'] <= 0) {
            return false;
        }

        // An explicit grace_until overrides the policy window, which is how an
        // administrator grants a one-off extension without changing the product.
        $graceEnd = $license->grace_until !== null
            ? strtotime((string) $license->grace_until . ' UTC')
            : strtotime((string) $license->expires_at . ' UTC') + ((int) $policy['grace_days'] * 86400);

        return $graceEnd !== false && $graceEnd >= time();
    }

    /**
     * When the grace period ends, or null when there is none.
     *
     * @return string|null A stored datetime.
     */
    public static function graceEndsAt(object $license): ?string
    {
        if (!self::inGracePeriod($license)) {
            return null;
        }

        return self::graceBoundary($license);
    }

    /**
     * The moment entitlement genuinely ends - the grace deadline, or the expiry
     * where no grace applies.
     *
     * The single date to compare against when deciding whether a licence still
     * covers anything, so callers need not reason about which of the two
     * applies.
     */
    public static function graceBoundary(object $license): ?string
    {
        if ($license->expires_at === null && $license->grace_until === null) {
            return null;
        }

        $policy = ProductConfig::policyForLicense($license);
        $graceEnd = $license->grace_until !== null
            ? strtotime((string) $license->grace_until . ' UTC')
            : strtotime((string) $license->expires_at . ' UTC') + ((int) $policy['grace_days'] * 86400);

        return $graceEnd === false ? null : gmdate('Y-m-d H:i:s', $graceEnd);
    }

    /**
     * The entitlement slugs a licence currently holds.
     *
     * Expired entitlements are excluded, so a feature sold for a period stops
     * being returned when that period ends without the licence itself changing.
     *
     * @return list<string>
     */
    public static function features(int $licenseId): array
    {
        $now      = Db::now();
        $features = [];

        $rows = Db::table('license_features')
            ->where('license_id', $licenseId)
            ->where('enabled', 1)
            ->get();

        foreach ($rows as $row) {
            if ($row->expires_at !== null && (string) $row->expires_at < $now) {
                continue;
            }
            $features[] = (string) $row->feature_slug;
        }

        return array_values(array_unique($features));
    }

    /**
     * Bind an offline token to the installation it was issued to.
     *
     * Derived from the installation's own secret, so a token copied to another
     * machine does not verify there - the copy has the token but not the secret
     * it was derived against.
     *
     * The nonce makes each token's binding distinct, so two tokens for the same
     * installation cannot be compared to learn anything about the secret.
     *
     * @return string|null Null when the installation has no secret to bind to.
     */
    private static function installationBinding(?object $activation, string $nonce): ?string
    {
        if ($activation === null) {
            return null;
        }

        // An installation on the keypair scheme is bound by its public key
        // instead; see installationKey().
        if ((string) ($activation->install_public_key ?? '') !== '') {
            return null;
        }

        $stored = (string) ($activation->install_secret ?? '');
        if ($stored === '') {
            return null;
        }

        try {
            $secret = Crypto::decrypt($stored);
        } catch (\Throwable $e) {
            return null;
        }

        return $secret === '' ? null : hash_hmac('sha256', $nonce, $secret);
    }

    /**
     * The installation's registered public key, for clients that use one.
     *
     * The alternative binding to {@see installationBinding()}: the client proves
     * possession of the private half rather than of a shared secret, so the
     * token is bound without the server holding anything that could impersonate
     * the installation.
     *
     * @return array<string,string>|null Null when no key is registered.
     */
    private static function installationKey(?object $activation): ?array
    {
        if ($activation === null) {
            return null;
        }

        $key = (string) ($activation->install_public_key ?? '');
        if ($key === '') {
            return null;
        }

        return [
            'key'       => $key,
            'algorithm' => (string) ($activation->install_key_algorithm ?? Crypto::ALG_ED25519),
        ];
    }

    /**
     * Expiry dates for entitlements that have one.
     *
     * Travels inside the offline token so a client can stop honouring a
     * time-limited feature without contacting the server.
     *
     * @return array<string,string>
     */
    public static function featureExpiry(int $licenseId): array
    {
        $now     = Db::now();
        $expiry  = [];

        $rows = Db::table('license_features')
            ->where('license_id', $licenseId)
            ->where('enabled', 1)
            ->get();

        foreach ($rows as $row) {
            if ($row->expires_at !== null && (string) $row->expires_at < $now) {
                continue;
            }
            $expiry[(string) $row->feature_slug] = $row->expires_at !== null
                ? Input::toIso($row->expires_at)
                : null;
        }

        return $expiry;
    }

    /**
     * Entitlements with their expiry and any stored value.
     *
     * Unlike {@see features()} this includes withdrawn ones, since the admin
     * page needs to show what a licence once held.
     *
     * @return list<array<string,mixed>>
     */
    public static function featureDetails(int $licenseId): array
    {
        $details = [];
        foreach (Db::table('license_features')->where('license_id', $licenseId)->get() as $row) {
            $details[(string) $row->feature_slug] = [
                'enabled'    => (bool) $row->enabled,
                'expires_at' => $row->expires_at,
                'value'      => $row->value,
            ];
        }

        return $details;
    }

    /**
     * Grant or withdraw one entitlement.
     *
     * Withdrawing marks the row rather than deleting it, so the history of what
     * a licence once included survives - which is what answers a customer asking
     * why a feature stopped working.
     *
     * @param string|null $value Optional payload for entitlements that carry
     *   one, such as a seat count.
     */
    public static function setFeature(int $licenseId, string $slug, bool $enabled, ?string $expiresAt = null, ?string $value = null): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }

        $exists = Db::table('license_features')
            ->where('license_id', $licenseId)
            ->where('feature_slug', $slug)
            ->exists();

        $payload = [
            'enabled'    => $enabled ? 1 : 0,
            'expires_at' => $expiresAt,
            'value'      => $value,
            'updated_at' => Db::now(),
        ];

        if ($exists) {
            Db::table('license_features')
                ->where('license_id', $licenseId)
                ->where('feature_slug', $slug)
                ->update($payload);
        } else {
            Db::table('license_features')->insert($payload + [
                'license_id'   => $licenseId,
                'feature_slug' => $slug,
                'created_at'   => Db::now(),
            ]);
        }

        Audit::log('license.feature_changed', $licenseId, Audit::RESULT_SUCCESS, [
            'feature' => $slug, 'enabled' => $enabled, 'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Replace a licence's entitlements with exactly this set.
     *
     * Anything absent is withdrawn, so this is the admin form's "these are the
     * features" rather than an additive grant.
     *
     * @param list<string> $slugs
     */
    public static function syncFeatures(int $licenseId, array $slugs): void
    {
        $slugs   = array_values(array_unique(array_filter(array_map('trim', $slugs))));
        $current = array_keys(self::featureDetails($licenseId));

        foreach (array_diff($slugs, $current) as $slug) {
            self::setFeature($licenseId, $slug, true);
        }
        foreach ($current as $slug) {
            self::setFeature($licenseId, $slug, in_array($slug, $slugs, true));
        }
    }

    /** Does a licence currently hold this entitlement? */
    public static function hasFeature(int $licenseId, string $slug): bool
    {
        return in_array($slug, self::features($licenseId), true);
    }

    /**
     * The licence as returned to a client over the API.
     *
     * Deliberately curated rather than a dump of the row. Internal columns,
     * admin notes and hold details are not a customer's software's business, and
     * including them by default would leak whatever a future column happens to
     * hold.
     *
     * Dates are ISO-8601 in UTC so a client parses them unambiguously.
     *
     * @return array<string,mixed>
     */
    public static function publicPayload(object $license, ?object $activation = null): array
    {
        $product = ProductConfig::find((int) $license->product_id);
        $policy  = ProductConfig::policyForLicense($license);

        $payload = [
            'key'          => (string) $license->license_key,
            'status'       => (string) $license->status,
            'product'      => $product !== null ? (string) $product->name : '',
            'product_id'   => $product !== null ? (string) $product->product_slug : '',
            'is_trial'     => (bool) $license->is_trial,
            'is_lifetime'  => (bool) $license->is_lifetime,
            'issued_at'    => Input::toIso((string) $license->created_at),
            'activated_at' => Input::toIso($license->activated_at),
            'expires_at'   => Input::toIso($license->expires_at),
            'domain'       => $license->primary_domain,
            'features'     => self::features((int) $license->id),
            'activations'  => [
                'used'  => (int) $license->activation_count,
                'limit' => (int) $license->max_activations,
            ],
            'reissues'     => [
                'used'  => (int) $license->reissue_count,
                'limit' => (int) $license->max_reissues,
            ],
            'version'      => [
                'current'   => $license->current_version,
                'latest'    => $policy['latest_version'] !== '' ? $policy['latest_version'] : null,
                'minimum'   => $policy['min_version'] !== '' ? $policy['min_version'] : null,
                'maximum'   => $policy['max_version'] !== '' ? $policy['max_version'] : null,
                'allowed'   => $policy['allowed_versions'] !== '' ? $policy['allowed_versions'] : null,
            ],
            'validation'   => [
                'interval_hours'    => (int) $policy['validation_interval_hours'],
                'last_validated_at' => Input::toIso($license->last_validated_at),
                'next_check_after'  => Input::toIso(gmdate(
                    'Y-m-d H:i:s',
                    time() + ((int) $policy['validation_interval_hours'] * 3600)
                )),
            ],
        ];

        if ($activation !== null) {
            $payload['activation'] = [
                'domain'          => $activation->domain,
                'ip'              => $activation->ip_address,
                'status'          => (string) $activation->status,
                'activated_at'    => Input::toIso((string) $activation->first_activated_at),
            ];
        }

        if (self::inGracePeriod($license)) {
            $payload['grace'] = [
                'active'  => true,
                'ends_at' => Input::toIso(self::graceEndsAt($license)),
            ];
        }

        return $payload;
    }

    /**
     * Produce a signed token letting an installation run without contacting the
     * server.
     *
     * The token carries the licence's state, its entitlements and their
     * expiries, and the bindings the client must enforce locally - so the client
     * can make the same decisions the server would while offline.
     *
     * It is signed, so a customer cannot forge or alter one, and bound to the
     * installation it was issued to, so it cannot be copied to another machine.
     * Refusing to issue one at all when there is no binding is the important
     * part: an unbound token would run anywhere, which is precisely what the
     * activation limit exists to prevent.
     *
     * The validity window is the trade this feature makes. Within it a
     * revocation, suspension or reissue has not reached the installation,
     * because it is not asking - see {@see offlineHorizon()}. That is inherent
     * to offline licensing rather than a defect, and the window is per-product
     * for exactly that reason.
     *
     * @return array<string,mixed> The signed envelope and its metadata.
     * @throws \RuntimeException When the installation has no credential to bind to.
     */
    public static function offlineToken(object $license, ?object $activation = null): array
    {
        $policy      = ProductConfig::policyForLicense($license);
        $offlineDays = max(0, (int) $policy['offline_validity_days']);
        $offlineUntil = gmdate('Y-m-d\TH:i:s\Z', time() + ($offlineDays * 86400));
        $nonce        = Crypto::randomToken(12);

        $binding      = self::installationBinding($activation, $nonce);
        $bindingKey   = self::installationKey($activation);
        if ($binding === null && $bindingKey === null) {
            throw new \RuntimeException(
                'This installation has no credential to bind an offline token to. It must '
                . 'activate again before offline licensing is available.'
            );
        }

        $payload = [

            'token_version'   => self::TOKEN_VERSION,

            // Identity and state, as the client will report it while offline.
            'license_id'      => (int) $license->id,
            'license_key'     => (string) $license->license_key,
            'product_id'      => ProductConfig::find((int) $license->product_id)->product_slug ?? '',
            'customer_id'     => (int) $license->client_id,
            'status'          => (string) $license->status,
            'expires_at'      => Input::toIso($license->expires_at),
            'domain'          => $license->primary_domain,
            'ip'              => $license->primary_ip,
            'machine_id'      => $activation !== null ? $activation->machine_id : $license->primary_machine_id,
            'installation_id' => $activation !== null ? (string) $activation->installation_id : null,
            'directory'       => $activation !== null ? $activation->directory : $license->primary_directory,
            'features'        => self::features((int) $license->id),

            'feature_expiry'  => self::featureExpiry((int) $license->id),

            // The binding rules, so the client applies the same policy the
            // server would rather than inventing its own.
            'lock_domain'      => (bool) $policy['lock_domain'],
            'lock_ip'          => (bool) $policy['lock_ip'],
            'lock_directory'   => (bool) $policy['lock_directory'],
            'lock_machine'     => (bool) $policy['lock_machine'],
            'allow_subdomains' => (bool) $policy['allow_subdomains'],
            'allow_local_domains' => (bool) $policy['allow_local_domains'],
            'allow_www_normalisation' => Settings::bool('allow_www_normalisation', true),

            'grace_ends_at'    => Input::toIso(self::graceBoundary($license)),
            'min_version'     => $policy['min_version'] !== '' ? $policy['min_version'] : null,
            'max_version'     => $policy['max_version'] !== '' ? $policy['max_version'] : null,
            'allowed_versions' => $policy['allowed_versions'] !== '' ? $policy['allowed_versions'] : null,

            // Exactly one of these two is present, per the scheme this
            // installation is on.
            'installation_binding' => $binding,

            'installation_key'      => $bindingKey['key'] ?? null,
            'installation_key_algorithm' => $bindingKey['algorithm'] ?? null,

            'issued_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            'offline_until'   => $offlineUntil,
            'nonce'           => $nonce,
        ];

        $key = Crypto::activeSigningKey();

        return [
            'token'      => Crypto::signPayload($payload),
            'expires_at' => $offlineUntil,
            'algorithm'  => $key !== null ? (string) $key->algorithm : Crypto::preferredAlgorithm(),
            'key_id'     => $key !== null ? (int) $key->id : 0,
        ];
    }

    /**
     * When a change to this licence takes effect everywhere.
     *
     * Measured from the most recent check-in of any active installation plus the
     * offline window, because the last token issued is the last one that has to
     * expire.
     *
     * Shown to an administrator whenever they restrict a licence, since it is
     * the honest answer to "when does this take effect?" - tokens already issued
     * cannot be recalled, and stating the date is better than implying the
     * change is immediate.
     *
     * @return int|null A timestamp, or null when offline use is disabled or no
     *   outstanding token can still be valid.
     */
    public static function offlineHorizon(object $license): ?int
    {
        $days = max(0, (int) ProductConfig::policyForLicense($license)['offline_validity_days']);
        if ($days === 0) {
            return null;
        }

        $latest = Db::table('activations')
            ->where('license_id', (int) $license->id)
            ->where('status', 'active')
            ->max('last_validated_at');

        if ($latest === null || $latest === '') {
            return null;
        }

        $checkedIn = strtotime((string) $latest . ' UTC');
        if ($checkedIn === false) {
            return null;
        }

        $horizon = $checkedIn + ($days * 86400);

        return $horizon > time() ? $horizon : null;
    }

    /**
     * Bring the cached activation count back in line with the rows.
     *
     * `licenses.activation_count` is a cache; `activations` is the truth. The
     * two can drift after anything that changes rows directly - an admin edit, a
     * migration, a manual fix - and the count is what the client area displays,
     * so a stale one is visible to customers.
     *
     * @return int The corrected count.
     */
    public static function recalculateActivationCount(int $licenseId): int
    {
        $count = (int) Db::table('activations')
            ->where('license_id', $licenseId)
            ->where('status', 'active')
            ->count();

        Db::table('licenses')->where('id', $licenseId)->update([
            'activation_count' => $count,
            'updated_at'       => Db::now(),
        ]);

        return $count;
    }

    /**
     * Counts for the admin dashboard.
     *
     * Aggregated in as few queries as possible, since this runs on every
     * dashboard load.
     *
     * @return array<string,int>
     */
    public static function statistics(): array
    {
        $counts = [];
        foreach (LicenseStatus::all() as $status) {
            $counts[$status] = (int) Db::table('licenses')
                ->whereNull('deleted_at')
                ->where('status', $status)
                ->count();
        }

        $since = gmdate('Y-m-d H:i:s', time() - 86400);

        return $counts + [
            'total'              => (int) Db::table('licenses')->whereNull('deleted_at')->count(),
            'activations'        => (int) Db::table('activations')->where('status', 'active')->count(),
            'validations_24h'    => (int) Db::table('validations')->where('created_at', '>=', $since)->count(),
            'failed_24h'         => (int) Db::table('validations')->where('created_at', '>=', $since)->where('success', 0)->count(),
            'reissues_24h'       => (int) Db::table('reissues')->where('created_at', '>=', $since)->count(),
            'open_abuse_events'  => (int) Db::table('abuse_events')->where('resolved', 0)->count(),
            'expiring_30d'       => (int) Db::table('licenses')
                ->whereNull('deleted_at')
                ->where('status', LicenseStatus::ACTIVE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', gmdate('Y-m-d H:i:s', time() + (30 * 86400)))
                ->where('expires_at', '>=', Db::now())
                ->count(),
        ];
    }

    /**
     * Whole days until a licence expires.
     *
     * Negative once past, so a caller can distinguish "expires soon" from
     * "expired a while ago" without a second comparison.
     *
     * @return int|null Null for a lifetime licence or one with no expiry.
     */
    public static function daysUntilExpiry(object $license): ?int
    {
        if ((bool) $license->is_lifetime || $license->expires_at === null) {
            return null;
        }
        $expiry = strtotime((string) $license->expires_at . ' UTC');
        if ($expiry === false) {
            return null;
        }

        return (int) ceil(($expiry - time()) / 86400);
    }

    /**
     * May the customer reset this licence themselves?
     *
     * Asked by the client area to decide whether to offer the button at all, so
     * a customer is not shown an action that would then be refused. The
     * authoritative decision is still made under a lock when they use it - see
     * {@see ReissueService}.
     */
    public static function clientCanReissue(object $license): bool
    {
        $policy = ProductConfig::policyForLicense($license);

        return (bool) $policy['reissue_self_service']
            && Settings::bool('reissue_self_service', true)
            && !LicenseStatus::isTerminal((string) $license->status);
    }
}
