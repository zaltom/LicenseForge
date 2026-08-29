<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Api\ErrorCodes;
use LicenseForge\Support\Db;
use LicenseForge\Support\Net;
use LicenseForge\Support\Settings;
use LicenseForge\Support\VersionRange;

/**
 * The rules that decide whether a licence covers the request in front of it.
 *
 * Five questions, asked in a fixed order by {@see evaluate()}: is the licence in
 * a usable state, is it for this product, does it cover this software version,
 * do the bindings match, and is there a free activation slot.
 *
 * Order is chosen so the answer a caller receives is the most useful true one.
 * An expired licence is reported as expired even if its domain also fails to
 * match, because renewing is what the customer must do and a domain error would
 * send them somewhere unhelpful. Cheap checks also precede expensive ones, so
 * the common refusals cost no queries.
 *
 * Every comparison here is against values already normalised by
 * {@see LicenseRequest}, which is what makes the bindings meaningful: a domain
 * lock cannot be defeated by casing, a scheme, a port or a trailing dot,
 * because none of those survive to reach this class.
 *
 * The class decides and reports; it does not write. Binding a licence is
 * {@see ActivationService}'s job, and the activation-limit check here is a fast
 * path whose authoritative counterpart runs under a row lock there - see
 * {@see checkBindings()}.
 */
final class ValidationService
{
    /**
     * Run every check and return the first refusal, or success.
     *
     * The grace-period detail from the status check is carried through to the
     * successful result, so a caller can tell an entitled-but-expiring licence
     * from a healthy one without asking again.
     *
     * The policy is resolved once and passed down rather than re-read by each
     * check, so every rule is judged against the same configuration even if a
     * product is edited mid-request.
     *
     * @param object|null $activation The installation this request resolved to,
     *   or null if it is new. Several rules differ between the two cases.
     * @param string      $endpoint   'activate' or 'validate'; only activation
     *   consumes a slot.
     */
    public static function evaluate(object $license, LicenseRequest $request, ?object $activation = null, string $endpoint = 'validate'): CheckResult
    {
        $policy = ProductConfig::policyForLicense($license);

        $result = self::checkStatus($license, $policy);
        if ($result->failed()) {
            return $result;
        }
        $graceInfo = $result->data();

        $result = self::checkProduct($license, $request);
        if ($result->failed()) {
            return $result;
        }

        $result = self::checkVersion($license, $request, $policy);
        if ($result->failed()) {
            return $result;
        }

        $result = self::checkBindings($license, $request, $activation, $policy, $endpoint);
        if ($result->failed()) {
            return $result;
        }

        return CheckResult::ok($graceInfo + $result->data() + ['policy' => $policy]);
    }

    /**
     * Is the licence in a state that entitles its holder to anything?
     *
     * Expiry is checked before the status, and separately, because a licence can
     * be past its date while still recorded as active - the cron applies expiry,
     * and this must not depend on the cron having run.
     *
     * A licence inside its grace period passes, and says so. That is the purpose
     * of grace: the customer keeps working while renewal is arranged, and the
     * caller receives the deadline so it can warn them.
     *
     * Any other unusable state is refused with the code specific to it, so the
     * client can distinguish suspended from revoked from terminated.
     *
     * @param array<string,mixed> $policy
     */
    public static function checkStatus(object $license, array $policy): CheckResult
    {
        $status = (string) $license->status;

        if ($status === LicenseStatus::EXPIRED || LicenseManager::isExpired($license)) {
            if (LicenseManager::inGracePeriod($license)) {
                return CheckResult::ok([
                    'in_grace'      => true,
                    'grace_ends_at' => LicenseManager::graceEndsAt($license),
                ]);
            }

            return CheckResult::fail(ErrorCodes::LICENSE_EXPIRED);
        }

        if (!LicenseStatus::isUsable($status)) {
            return CheckResult::fail(ErrorCodes::forStatus($status));
        }

        return CheckResult::ok(['in_grace' => false]);
    }

    /**
     * Is this licence for the product asking?
     *
     * Without this, a customer holding any valid licence could use it to run a
     * different product from the same vendor - the key alone would suffice.
     *
     * Accepts either the product slug or its WHMCS id, because integrations were
     * written against both. A client that names no product is not refused: the
     * licence already knows its product, and the check-in endpoint does not
     * require one.
     */
    public static function checkProduct(object $license, LicenseRequest $request): CheckResult
    {
        if ($request->productIdentifier === '') {
            return CheckResult::ok();
        }

        $product = ProductConfig::find((int) $license->product_id);
        if ($product === null) {
            return CheckResult::fail(ErrorCodes::PRODUCT_MISMATCH);
        }

        $identifier = strtolower($request->productIdentifier);
        $matches = hash_equals(strtolower((string) $product->product_slug), $identifier)
            || (ctype_digit($identifier) && (int) $identifier === (int) $product->whmcs_product_id);

        return $matches ? CheckResult::ok() : CheckResult::fail(ErrorCodes::PRODUCT_MISMATCH);
    }

    /**
     * Does the licence cover the version of the software running?
     *
     * Supports the three shapes a vendor actually needs: a minimum, a maximum,
     * and an explicit list of permitted versions. Together they express a
     * major-version licence, a maintenance window, or a specific build.
     *
     * A client that reports no version passes, since older SDKs do not send one
     * and refusing them would break upgrades rather than enforce anything.
     *
     * The refusal carries the specific problem - too old, too new, not listed -
     * because "unsupported version" alone leaves a customer nothing to act on.
     *
     * @param array<string,mixed> $policy
     */
    public static function checkVersion(object $license, LicenseRequest $request, array $policy): CheckResult
    {
        if ($request->version === '') {
            return CheckResult::ok();
        }

        $problem = VersionRange::check(
            $request->version,
            $policy['min_version'] !== '' ? $policy['min_version'] : null,
            $policy['max_version'] !== '' ? $policy['max_version'] : null,
            $policy['allowed_versions'] !== '' ? $policy['allowed_versions'] : null
        );

        if ($problem !== null) {
            return CheckResult::fail(
                ErrorCodes::VERSION_NOT_SUPPORTED,
                'This software version is not covered by the license: ' . $problem . '.'
            );
        }

        return CheckResult::ok();
    }

    /**
     * Does this request come from where the licence is tied to?
     *
     * Four independent locks, each enabled per product: domain, IP, directory
     * and machine. A vendor selling to web agencies locks the domain; one
     * shipping a desktop application locks the machine.
     *
     * The rules differ sharply between a new activation and an existing one,
     * which is the subtlety here. A new activation is establishing what it will
     * be bound to, so it must supply the value but has nothing to be compared
     * against yet. An existing one is proving it is still the same installation,
     * so its value must match what was recorded. Applying the strict comparison
     * to a first activation would make binding impossible; applying the lax one
     * to a later request would make it meaningless.
     *
     * The activation-limit check at the end is a fast path only, not the
     * enforcement point: between this count and the insert a concurrent request
     * can claim the same slot, so {@see ActivationService} allocates the slot
     * again under a row lock inside the transaction that creates the activation.
     *
     * @param object|null         $activation Null for a new installation.
     * @param array<string,mixed> $policy
     */
    public static function checkBindings(
        object $license,
        LicenseRequest $request,
        ?object $activation,
        array $policy,
        string $endpoint = 'validate'
    ): CheckResult {
        $isNewActivation = $activation === null;

        if ($policy['lock_domain']) {
            if ($request->domain === '') {
                return CheckResult::fail(ErrorCodes::DOMAIN_MISMATCH, 'A domain must be supplied for this license.');
            }
            if (!$policy['allow_local_domains'] && Net::isLocalDomain($request->domain)) {
                return CheckResult::fail(ErrorCodes::DOMAIN_MISMATCH, 'Development and local domains are not permitted for this license.');
            }

            $allowed = self::allowedDomains($license, $activation);
            if ($allowed !== []) {
                if (!Net::domainMatchesAny($allowed, $request->domain, (bool) $policy['allow_subdomains'])) {
                    return CheckResult::fail(ErrorCodes::DOMAIN_MISMATCH);
                }
            } elseif (!$isNewActivation) {
                // Bound to nothing recognisable: refuse rather than treat an
                // empty allow-list as permitting everything.
                return CheckResult::fail(ErrorCodes::DOMAIN_MISMATCH);
            }
        }

        if ($policy['lock_ip']) {
            // The observed address is preferred over the declared one: a client
            // may state any address, but cannot choose where it connects from.
            $candidateIp = $request->observedIp !== '' ? $request->observedIp : $request->ip;
            if ($candidateIp === '') {
                return CheckResult::fail(ErrorCodes::IP_MISMATCH, 'The source IP address could not be determined.');
            }

            $allowedIps = self::allowedIps($license, $activation);
            if ($allowedIps !== []) {
                if (!Net::ipMatchesAny($allowedIps, $candidateIp)) {
                    return CheckResult::fail(ErrorCodes::IP_MISMATCH);
                }
            } elseif (!$isNewActivation) {
                return CheckResult::fail(ErrorCodes::IP_MISMATCH);
            }
        }

        if ($policy['lock_directory']) {
            if ($request->directory === '') {
                return CheckResult::fail(
                    ErrorCodes::DIRECTORY_MISMATCH,
                    'An installation directory must be supplied for this license.'
                );
            }

            if (!$isNewActivation) {
                $recorded = Net::normalisePath((string) ($activation->directory ?? ''));
                if ($recorded !== '' && !hash_equals($recorded, $request->directory)) {
                    return CheckResult::fail(ErrorCodes::DIRECTORY_MISMATCH);
                }
            }
        }

        if ($policy['lock_machine']) {
            if ($request->machineId === '') {
                return CheckResult::fail(ErrorCodes::MACHINE_MISMATCH, 'A machine identifier must be supplied for this license.');
            }
            $recorded = (string) ($activation->machine_id ?? $license->primary_machine_id ?? '');
            if (!$isNewActivation && $recorded !== '' && !hash_equals($recorded, $request->machineId)) {
                return CheckResult::fail(ErrorCodes::MACHINE_MISMATCH);
            }
        }

        if ($isNewActivation && $endpoint === 'activate') {
            $used  = (int) Db::table('activations')
                ->where('license_id', (int) $license->id)
                ->where('status', 'active')
                ->count();
            $limit = (int) $license->max_activations;
            if ($limit > 0 && $used >= $limit) {
                return CheckResult::fail(ErrorCodes::ACTIVATION_LIMIT, null, ['used' => $used, 'limit' => $limit]);
            }
        }

        return CheckResult::ok(['new_activation' => $isNewActivation]);
    }

    /**
     * Every domain this request could legitimately be coming from.
     *
     * Three sources combine: the domain this installation already activated on,
     * the licence's primary domain, and any additional domains staff have
     * permitted. The installation's own comes first so the ordinary case - an
     * installation checking in from where it was activated - matches
     * immediately.
     *
     * An empty result is meaningful rather than permissive: for an existing
     * activation it means the licence is bound to nothing recognisable, which
     * {@see checkBindings()} treats as a refusal.
     *
     * @return list<string>
     */
    public static function allowedDomains(object $license, ?object $activation = null): array
    {
        $domains = [];

        if ($activation !== null && ($activation->domain ?? '') !== '') {
            $domains[] = (string) $activation->domain;
        }
        if (($license->primary_domain ?? '') !== '') {
            $domains[] = (string) $license->primary_domain;
        }
        foreach (self::decodeList($license->allowed_domains ?? null) as $entry) {
            $domains[] = $entry;
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * Every address this request could legitimately be coming from.
     *
     * Same three sources as {@see allowedDomains()}. Entries may be single
     * addresses or CIDR ranges, which is what makes an IP lock workable for a
     * customer behind a pool of outbound addresses.
     *
     * @return list<string>
     */
    public static function allowedIps(object $license, ?object $activation = null): array
    {
        $ips = [];

        if ($activation !== null && ($activation->ip_address ?? '') !== '') {
            $ips[] = (string) $activation->ip_address;
        }
        if (($license->primary_ip ?? '') !== '') {
            $ips[] = (string) $license->primary_ip;
        }
        foreach (self::decodeList($license->allowed_ips ?? null) as $entry) {
            $ips[] = $entry;
        }

        return array_values(array_unique(array_filter($ips)));
    }

    /**
     * Read a stored list of domains or addresses.
     *
     * Accepts three encodings because the column has held all of them: an array,
     * a JSON array, and a comma-separated string. An installation upgraded from
     * an early version still has comma-separated values here, and reading them
     * as a single malformed entry would quietly widen or void a binding rather
     * than fail visibly.
     *
     * @param  mixed $raw
     * @return list<string>
     */
    public static function decodeList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('strval', $decoded)))
            : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Record the outcome of a licensing decision.
     *
     * Written for every request, successful or not, because this log is what the
     * abuse detector reads and what the admin pages show - a path that skipped
     * it would create a blind spot rather than a gap.
     *
     * The licence key is stored only as a hash. The log is retained far longer
     * than any request and is read by staff, so keeping the key in clear would
     * turn the highest-volume table in the module into a source of working
     * credentials. The hash still answers the question the log is for: which
     * requests concerned this licence, including requests naming a key that does
     * not exist.
     */
    public static function record(
        LicenseRequest $request,
        string $endpoint,
        bool $success,
        ?string $errorCode = null,
        ?object $license = null,
        ?object $activation = null,
        int $durationMs = 0
    ): void {
        // Successful check-ins are the bulk of the volume and can be switched
        // off; failures are always recorded, since they are what the abuse
        // detector and any later investigation depend on.
        $writeDetailRow = $success ? Settings::bool('log_validations', true) : true;

        try {
            if ($writeDetailRow) {
                Db::table('validations')->insert([
                    'license_id'       => $license !== null ? (int) $license->id : null,
                    'activation_id'    => $activation !== null ? (int) $activation->id : null,
                    'license_key_hash' => hash('sha256', $request->licenseKey),
                    'endpoint'         => $endpoint,
                    'success'          => $success ? 1 : 0,
                    'error_code'       => $errorCode,
                    'domain'           => $request->domain !== '' ? $request->domain : null,
                    'ip_address'       => $request->observedIp !== '' ? $request->observedIp : null,
                    'machine_id'       => $request->machineId !== '' ? $request->machineId : null,
                    'version'          => $request->version !== '' ? $request->version : null,
                    'duration_ms'      => $durationMs,
                    'created_at'       => Db::now(),
                ]);
            }

            if ($license !== null) {
                $now     = Db::now();
                $updates = [
                    'last_validated_at' => $now,
                    'updated_at'        => $now,
                ];
                if ($success) {
                    $updates['last_success_at'] = $now;
                    if ($request->version !== '') {
                        $updates['current_version'] = $request->version;
                    }
                } else {
                    $updates['last_failure_at']   = $now;
                    $updates['last_failure_code'] = $errorCode;
                }

                Db::table('licenses')->where('id', (int) $license->id)->update($updates);
                Db::table('licenses')->where('id', (int) $license->id)->increment(
                    $success ? 'validation_count' : 'failed_validation_count'
                );
            }

            if ($activation !== null) {
                Db::table('activations')->where('id', (int) $activation->id)->update([
                    'last_validated_at' => Db::now(),
                    'last_domain'       => $request->domain !== '' ? $request->domain : $activation->last_domain,
                    'last_ip'           => $request->observedIp !== '' ? $request->observedIp : $activation->last_ip,
                    'version'           => $request->version !== '' ? $request->version : $activation->version,
                    'updated_at'        => Db::now(),
                ]);
                Db::table('activations')->where('id', (int) $activation->id)->increment('validation_count');
            }
        } catch (\Throwable $e) {
            error_log('[LicenseForge] validation logging failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete validation records older than the retention period.
     *
     * The fastest-growing table the module owns - every installation writes to
     * it on every check-in - and its value is almost entirely recent: the abuse
     * detector looks at hours, the admin pages at days.
     *
     * A retention of zero or less disables pruning, for an operator who keeps
     * this data deliberately.
     *
     * @return int Rows removed.
     */
    public static function pruneValidationLog(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        return (int) Db::table('validations')->where('created_at', '<', $cutoff)->delete();
    }
}
