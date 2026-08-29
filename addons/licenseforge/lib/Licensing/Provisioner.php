<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\Settings;

/**
 * Keeps a licence in step with the WHMCS service that pays for it.
 *
 * Every lifecycle event WHMCS raises for a service - created, suspended,
 * unsuspended, terminated, upgraded, renewed - arrives here and is translated
 * into what should happen to the licence. That translation is the module's
 * whole integration with billing: a customer who stops paying stops being
 * licensed, without anyone doing anything.
 *
 * Two principles run through it.
 *
 * The service is the source of truth for anything billing decides - the term,
 * the expiry date, whether the account is in good standing. The licence follows
 * it rather than tracking its own.
 *
 * A deliberate act by staff outranks an automatic one. A licence an
 * administrator suspended is held, and no service event may quietly reverse
 * that; the attempt is refused and audited instead. Otherwise a customer paying
 * an unrelated invoice would silently reactivate a licence withdrawn on
 * purpose.
 *
 * WHMCS's own `tblhosting` is read directly, so every access is guarded - a
 * schema difference or a missing table must not take down provisioning.
 */
final class Provisioner
{
    /**
     * A WHMCS service row.
     *
     * Reads WHMCS's own table, so failure is treated as absence rather than
     * raised: the callers all handle a missing service, and none of them is
     * improved by an exception surfacing inside a billing hook.
     *
     * @return object|null
     */
    public static function service(int $serviceId)
    {
        if ($serviceId <= 0) {
            return null;
        }

        try {
            return Db::connection()->table('tblhosting')->where('id', $serviceId)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Issue a licence for a newly created service.
     *
     * Idempotent by design: an existing licence is returned rather than a second
     * one created. WHMCS can raise a creation event more than once - a retried
     * provisioning, a manual "run module create" - and each must not mint
     * another licence for a customer who already has one.
     *
     * The initial status reflects the service, not just the policy. A product
     * set to activate automatically still issues a pending licence when the
     * service itself is suspended, terminated, cancelled or flagged as fraud, so
     * a licence is never born active on an account that is not in good standing.
     *
     * Returns null wherever licensing does not apply - the module is off, the
     * service is gone, or its product does not issue licences - so callers treat
     * "no licence" as a normal outcome rather than a failure.
     *
     * @param  string $trigger What caused this, recorded in the audit log.
     * @return object|null The licence, existing or new.
     */
    public static function provision(int $serviceId, string $trigger = '')
    {
        if (!Settings::bool('module_enabled', true)) {
            return null;
        }

        $service = self::service($serviceId);
        if ($service === null) {
            return null;
        }

        $product = ProductConfig::findByWhmcsProduct((int) $service->packageid);
        if ($product === null || !(bool) $product->licensing_enabled) {
            return null;
        }

        $existing = LicenseManager::findByService($serviceId);
        if ($existing !== null) {
            return $existing;
        }

        $policy = ProductConfig::policy($product);
        $term   = self::term($policy, $service);

        $license = LicenseManager::create([
            'product_id'       => (int) $product->id,
            'whmcs_product_id' => (int) $service->packageid,
            'client_id'        => (int) $service->userid,
            'service_id'       => $serviceId,
            'order_id'         => (int) ($service->orderid ?? 0),
            'domain'           => (string) ($service->domain ?? ''),
            'expires_at'       => $term['expires_at'],
            'is_lifetime'      => $term['lifetime'],
            'duration_days'    => $term['duration_days'],

            // Auto-activation still yields to the service's own standing.
            'status'           => $policy['auto_activate']
                && !in_array((string) $service->domainstatus, ['Suspended', 'Terminated', 'Cancelled', 'Fraud'], true)
                    ? LicenseStatus::ACTIVE
                    : LicenseStatus::PENDING,
        ]);

        Audit::log('service.license_provisioned', (int) $license->id, Audit::RESULT_SUCCESS, [
            'service_id' => $serviceId, 'trigger' => $trigger,
        ], Audit::ACTOR_SYSTEM);

        return $license;
    }

    /**
     * Work out when a new licence should expire.
     *
     * Three shapes, from the product's policy:
     *
     *   lifetime      No expiry.
     *   fixed_days    A fixed period, counted from issue.
     *   billing_cycle Follows the service's next due date, so the licence and
     *                 the invoice always agree.
     *
     * Two cases collapse to lifetime deliberately. A fixed term of zero days has
     * no end, and a billing-cycle licence on a service with no due date has
     * nothing to follow - in both, issuing a licence that expires immediately
     * would be worse than issuing one that does not expire, since the customer
     * has paid either way and staff can correct it.
     *
     * @param  array<string,mixed> $policy
     * @return array{expires_at:?string,lifetime:bool,duration_days:?int}
     */
    private static function term(array $policy, $service): array
    {
        if ((string) $policy['license_term'] === 'lifetime') {
            return ['expires_at' => null, 'lifetime' => true, 'duration_days' => null];
        }

        if ((string) $policy['license_term'] === 'fixed_days') {
            $days = (int) $policy['duration_days'];

            return $days <= 0
                ? ['expires_at' => null, 'lifetime' => true, 'duration_days' => null]
                : ['expires_at' => null, 'lifetime' => false, 'duration_days' => $days];
        }

        $nextDue = self::nextDueDate($service);

        return $nextDue === null
            ? ['expires_at' => null, 'lifetime' => true, 'duration_days' => null]
            : ['expires_at' => $nextDue, 'lifetime' => false, 'duration_days' => null];
    }

    /**
     * The service's next due date as a licence expiry.
     *
     * Taken to the end of that day rather than its start, so a licence does not
     * lapse on the morning of the day it is still paid for.
     *
     * MySQL's zero date is treated as absent - it means WHMCS has no due date,
     * not a date in year zero.
     *
     * @param object $service
     */
    private static function nextDueDate($service): ?string
    {
        $nextDue = (string) ($service->nextduedate ?? '');
        if ($nextDue === '' || strncmp($nextDue, '0000', 4) === 0) {
            return null;
        }

        $timestamp = strtotime($nextDue . ' 23:59:59');

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Apply a service status change to its licence.
     *
     * The mapping is configurable rather than fixed, because vendors disagree
     * about what a suspended service should mean for a licence - some suspend,
     * some expire, some do nothing.
     *
     * Two things can refuse the change. The product may have automatic
     * suspension switched off, leaving that judgement to staff. And a held
     * licence refuses any automatic change: the hold records a deliberate
     * decision, and a service event must not overwrite it. The refusal is
     * audited, so the reason a licence did not follow its service is
     * discoverable rather than mysterious.
     *
     * @param  string $settingKey Which mapping to apply, e.g. `map_suspended`.
     * @return bool   True if the licence's status actually changed.
     */
    public static function mapStatus(int $serviceId, string $settingKey, string $reason): bool
    {
        $license = LicenseManager::findByService($serviceId);
        if ($license === null) {
            return false;
        }

        $policy = ProductConfig::policy(ProductConfig::find((int) $license->product_id));

        if ($settingKey === 'map_suspended' && !$policy['auto_suspend']) {
            return false;
        }

        // An unmapped or unrecognised target means "do nothing", which is how a
        // vendor switches one transition off.
        $target = (string) Settings::get($settingKey, '');
        if ($target === '' || !LicenseStatus::exists($target)) {
            return false;
        }

        if (!LicenseManager::automaticChangeAllowed($license, $target)) {
            Audit::log('license.hold_kept', (int) $license->id, Audit::RESULT_DENIED, [
                'attempted'  => $target,
                'reason'     => $reason,
                'service_id' => $serviceId,
                'held_by'    => $license->held_by,
            ], Audit::ACTOR_SYSTEM);

            return false;
        }

        return LicenseManager::setStatus((int) $license->id, $target, $reason, [
            'service_id' => $serviceId,
            'automatic'  => true,
        ]);
    }

    /**
     * Move a licence with its service to a different product.
     *
     * Four outcomes, in the order they are decided:
     *
     *   The new product is not licensed  The licence is terminated. It covers a
     *                                    product the customer no longer has.
     *   There is no licence yet          Provision one; the service has just
     *                                    become eligible.
     *   Policy says `new_license`        Terminate and replace, for vendors
     *                                    whose tiers are separate products.
     *   Otherwise                        Move the existing licence, keeping its
     *                                    key and its installations.
     *
     * The last is the default and the one customers expect: an upgrade should
     * not invalidate the key already deployed across their servers.
     *
     * Moving applies the new product's limits and adds its default entitlements.
     * Entitlements are added, never removed - an upgrade should not strip
     * something granted specifically to that customer.
     *
     * Whether the term is extended is the product's choice, since an upgrade may
     * or may not come with more time.
     */
    public static function changePackage(int $serviceId): void
    {
        $service = self::service($serviceId);
        if ($service === null) {
            return;
        }

        $license    = LicenseManager::findByService($serviceId);
        $newProduct = ProductConfig::findByWhmcsProduct((int) $service->packageid);

        if ($newProduct === null || !(bool) $newProduct->licensing_enabled) {
            if ($license !== null) {
                LicenseManager::setStatus((int) $license->id, LicenseStatus::TERMINATED, 'moved to an unlicensed product', [
                    'service_id' => $serviceId,
                    'automatic'  => true,
                ]);
            }

            return;
        }

        if ($license === null) {
            self::provision($serviceId, 'change_package');

            return;
        }

        $policy = ProductConfig::policy($newProduct);

        if ($policy['upgrade_behaviour'] === 'new_license') {
            // Soft-deleted rather than removed, so the old key's history and
            // audit trail survive the replacement.
            LicenseManager::setStatus((int) $license->id, LicenseStatus::TERMINATED, 'replaced by upgrade', [
                'service_id' => $serviceId,
                'automatic'  => true,
            ]);
            LicenseManager::softDelete((int) $license->id, 'replaced by upgrade');
            self::provision($serviceId, 'upgrade');

            return;
        }

        Db::table('licenses')->where('id', (int) $license->id)->update([
            'product_id'       => (int) $newProduct->id,
            'whmcs_product_id' => (int) $service->packageid,
            'max_activations'  => (int) $policy['max_activations'],
            'max_reissues'     => (int) $policy['max_reissues'],
            'updated_at'       => Db::now(),
        ]);

        foreach ($policy['default_features'] as $slug) {
            LicenseManager::setFeature((int) $license->id, (string) $slug, true);
        }

        if ($policy['upgrade_behaviour'] === 'extend') {
            $term = self::term($policy, $service);
            if ($term['lifetime']) {
                LicenseManager::setExpiry((int) $license->id, null, 'package upgrade');
            } elseif ($term['expires_at'] !== null) {
                LicenseManager::setExpiry((int) $license->id, $term['expires_at'], 'package upgrade');
            } elseif ($term['duration_days'] !== null) {
                LicenseManager::extend((int) $license->id, $term['duration_days'], 'package upgrade');
            }
        }

        Audit::log('service.package_changed', (int) $license->id, Audit::RESULT_SUCCESS, [
            'service_id'  => $serviceId,
            'new_product' => (int) $newProduct->id,
            'behaviour'   => $policy['upgrade_behaviour'],
        ], Audit::ACTOR_SYSTEM);
    }

    /**
     * Extend a licence when its service is paid for.
     *
     * Provisions one first if the service somehow has none, so a renewal on a
     * service that failed to provision originally repairs itself rather than
     * silently doing nothing.
     *
     * How the term moves depends on the product. A billing-cycle licence simply
     * follows the new due date. A fixed-term licence either extends from where
     * it was or resets from today, which is a genuine choice: extending is right
     * for a subscription, resetting for a period that starts when it is bought.
     *
     * Expiry notifications are cleared, so the next approaching expiry sends
     * fresh warnings rather than being suppressed as already-sent.
     *
     * Reactivation is attempted last, and only for a licence in a state payment
     * plausibly resolves. It still respects a hold: a licence an administrator
     * withdrew is not brought back by an invoice being paid, and the refusal is
     * audited so the discrepancy is explainable.
     */
    public static function renew(int $serviceId, string $trigger = 'renewal'): void
    {
        $license = LicenseManager::findByService($serviceId) ?? self::provision($serviceId, $trigger);
        if ($license === null) {
            return;
        }

        $policy  = ProductConfig::policy(ProductConfig::find((int) $license->product_id));
        $service = self::service($serviceId);

        $term = (string) $policy['license_term'];

        if ($term === 'billing_cycle') {
            $nextDue = $service !== null ? self::nextDueDate($service) : null;
            if ($nextDue !== null && !(bool) $license->is_lifetime) {
                LicenseManager::setExpiry((int) $license->id, $nextDue, 'renewal payment');
            }
        } elseif ($term === 'fixed_days' && $policy['renewal_behaviour'] !== 'none' && !(bool) $license->is_lifetime) {
            $days = (int) $policy['duration_days'];
            if ($days > 0 && $policy['renewal_behaviour'] === 'reset') {
                LicenseManager::setExpiry(
                    (int) $license->id,
                    gmdate('Y-m-d H:i:s', time() + ($days * 86400)),
                    'renewal payment (reset)'
                );
            } elseif ($days > 0) {
                LicenseManager::extend((int) $license->id, $days, 'renewal payment');
            }
        }

        Notifier::resetNotifications((int) $license->id, 'expir');

        $fresh = LicenseManager::find((int) $license->id);
        if ($fresh !== null && in_array((string) $fresh->status, [LicenseStatus::PENDING, LicenseStatus::SUSPENDED, LicenseStatus::EXPIRED], true)) {
            if (LicenseManager::automaticChangeAllowed($fresh, LicenseStatus::ACTIVE)) {
                LicenseManager::setStatus((int) $fresh->id, LicenseStatus::ACTIVE, 'payment received', [
                    'service_id' => $serviceId,
                    'automatic'  => true,
                ]);
            } else {
                Audit::log('license.hold_kept', (int) $fresh->id, Audit::RESULT_DENIED, [
                    'attempted'  => LicenseStatus::ACTIVE,
                    'reason'     => 'payment received',
                    'service_id' => $serviceId,
                    'held_by'    => $fresh->held_by,
                ], Audit::ACTOR_SYSTEM);
            }
        }

        Audit::log('service.renewed', (int) $license->id, Audit::RESULT_SUCCESS, [
            'service_id' => $serviceId, 'trigger' => $trigger,
        ], Audit::ACTOR_SYSTEM);
    }
}
