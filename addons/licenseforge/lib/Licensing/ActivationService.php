<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Api\ErrorCodes;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Net;
use LicenseForge\Support\Settings;

/**
 * Installation binding: creating, recognising, refreshing and releasing the
 * activations that consume a licence's slots.
 *
 * An activation is one installation of the customer's software, bound to a
 * licence. The activation limit is the thing a customer actually buys, so this
 * class is where licensing is enforced rather than merely evaluated.
 *
 * Two problems dominate the design.
 *
 * Identity. Everything an installation is matched on - installation id, machine
 * id, domain, directory - is a value the client chose, and all of them appear
 * in API responses. Matching alone therefore proves nothing: a second machine
 * given the same values would resolve onto the first installation's activation
 * and consume no slot, making the limit advisory. The per-installation secret
 * issued at activation is the evidence, and {@see provesInstallation()} is
 * where it is required.
 *
 * Concurrency. Counting free slots and then inserting is not enough - two
 * requests can pass the same count and both insert. Slot allocation therefore
 * happens again under a licence row lock, inside the transaction that creates
 * the activation. The cheap check that precedes it is a fast path, not
 * enforcement.
 */
final class ActivationService
{
    /**
     * Find the activation this request belongs to, if any.
     *
     * Matching is deliberately conservative and ordered by strength: an
     * installation id, then a machine id, then an exact domain and directory.
     * Anything looser would let one customer's installation silently resolve
     * onto another's activation, consuming no slot.
     *
     * Every match must then survive {@see provesInstallation()}. A caller that
     * matches but cannot prove it is treated as an installation the server has
     * not seen - which is exactly what it is - and faces the activation limit
     * like any newcomer.
     *
     * @return object|null The activation, or null for a caller to be treated as
     *   new.
     */
    public static function resolve(object $license, LicenseRequest $request)
    {
        $licenseId      = (int) $license->id;
        $installationId = $request->resolvedInstallationId();

        $activation = Db::table('activations')
            ->where('license_id', $licenseId)
            ->where('installation_id', $installationId)
            ->first();
        if ($activation !== null) {
            return self::provesInstallation($activation, $request) ? $activation : null;
        }

        if ($request->machineId !== '') {
            $activation = Db::table('activations')
                ->where('license_id', $licenseId)
                ->where('machine_id', $request->machineId)
                ->where('status', 'active')
                ->first();
            if ($activation !== null) {
                return self::provesInstallation($activation, $request) ? $activation : null;
            }
        }

        if ($request->domain !== '') {
            $query = Db::table('activations')
                ->where('license_id', $licenseId)
                ->where('domain', $request->domain)
                ->where('status', 'active');
            if ($request->directory !== '') {
                $query->where('directory', $request->directory);
            }
            $activation = $query->first();
            if ($activation !== null) {
                return self::provesInstallation($activation, $request) ? $activation : null;
            }
        }

        return null;
    }

    /**
     * Is this request entitled to be treated as that activation?
     *
     * The secret issued at activation is the evidence; an HMAC over the
     * request's own canonical string is how it is presented without travelling.
     * An installation that registered a public key is checked against that
     * instead, and first - so one that has moved to a keypair can never be
     * authenticated by the secret it used to hold.
     *
     * The keypair path is the stronger of the two because of what the server
     * stops holding: verifying a signature needs only the public half, so a
     * database dump plus the master key recovers nothing that could
     * authenticate as that installation. The secret path remains supported
     * because not every client can generate a keypair.
     *
     * Failing this does not refuse the request. It refuses the claim, which is
     * what makes the activation limit enforceable rather than advisory.
     *
     * Three outcomes, deliberately distinct:
     *
     *   proof verifies          true
     *   proof missing or wrong  false, audited - the caller is treated as new
     *   secret unreadable       throws
     *
     * The last is the important one. A secret on record that cannot be decrypted
     * is a server-side fault, and the only truthful answer to "is this
     * installation authentic?" is that we cannot tell. Returning true would be
     * catastrophic: every secret is wrapped with the same master key, so losing
     * the key file or changing the WHMCS encryption hash would not fail one
     * installation but silently switch installation authentication off across
     * the estate, with no symptom at all. Returning false would bill the
     * customer for our fault, spending an activation slot they already own. So
     * the request fails as the internal error it is.
     */
    private static function provesInstallation(object $activation, LicenseRequest $request): bool
    {
        $stored = (string) ($activation->install_secret ?? '');

        if ($stored === '') {
            if (Settings::bool('require_install_proof', false)) {
                self::denyInstallationClaim($activation, 'no secret on record and proof is required');

                return false;
            }

            // Grandfathered: activations created before per-installation
            // credentials existed have nothing to prove with. Recorded so the
            // remaining population is visible.
            Audit::log('activation.unproven', (int) $activation->license_id, Audit::RESULT_SUCCESS, [
                'activation_id'   => (int) $activation->id,
                'installation_id' => (string) $activation->installation_id,
                'reason'          => 'activation predates per-installation credentials',
            ], Audit::ACTOR_API);

            return true;
        }

        if ($request->installProof === '' || $request->installCanonical === '') {
            self::denyInstallationClaim($activation, 'no proof presented');

            return false;
        }

        $publicKey = (string) ($activation->install_public_key ?? '');
        if ($publicKey !== '') {
            $signature = self::decodeProof($request->installProof);
            $algorithm = (string) ($activation->install_key_algorithm ?? Crypto::ALG_ED25519);

            if ($signature === '' || !Crypto::verifyDetached($request->installCanonical, $signature, $publicKey, $algorithm)) {
                self::denyInstallationClaim($activation, 'signature did not verify');

                return false;
            }

            return true;
        }

        try {
            $secret = Crypto::decrypt($stored);
        } catch (\Throwable $e) {
            Audit::log('activation.proof_unavailable', (int) $activation->license_id, Audit::RESULT_FAILURE, [
                'activation_id'   => (int) $activation->id,
                'installation_id' => (string) $activation->installation_id,
                'reason'          => 'the stored installation secret could not be decrypted',
            ], Audit::ACTOR_API);

            throw new \RuntimeException(sprintf(
                'The installation secret for activation %d could not be decrypted, so this '
                . 'installation cannot be authenticated. Check storage/master-key.php and the '
                . 'WHMCS encryption hash. Original error: %s',
                (int) $activation->id,
                $e->getMessage()
            ), 0, $e);
        }

        $expected = hash_hmac('sha256', $request->installCanonical, $secret);
        if (!Crypto::secureEquals($expected, $request->installProof)) {
            self::denyInstallationClaim($activation, 'proof did not verify');

            return false;
        }

        return true;
    }

    /**
     * The raw signature bytes behind an installation proof.
     *
     * HMAC proofs are hex, and a signature is far too long for that to stay
     * convenient, so key-based proofs are base64url. Accepting both here keeps
     * one header doing one job across both schemes.
     */
    private static function decodeProof(string $proof): string
    {
        if (preg_match('/^[0-9a-f]+$/i', $proof) === 1 && strlen($proof) % 2 === 0) {
            $raw = @hex2bin($proof);
            if ($raw !== false && $raw !== '') {
                return $raw;
            }
        }

        return Crypto::base64UrlDecode($proof);
    }

    /**
     * May the authenticated caller act on this licence at all?
     *
     * Authentication established that the caller holds a valid credential. It
     * never established that the credential was entitled to this licence - so a
     * credential shipped inside one product could otherwise activate and check
     * licences belonging to any other product on the same server, with the
     * licence key as the only thing between them. A key is a bearer token that
     * travels in support tickets and screenshots.
     *
     * Null means unrestricted, which is what every credential issued before this
     * existed remains: narrowing them silently would break integrations that
     * work today, so it is opt-in per credential.
     *
     * Note what this does not do. It does not separate two customers of the same
     * product, who legitimately share a credential baked into that product's
     * build. It removes the cross-product case only.
     */
    private static function credentialMayUse(object $license, LicenseRequest $request): bool
    {
        if ($request->credentialProducts === null) {
            return true;
        }

        if (in_array((int) $license->product_id, $request->credentialProducts, true)) {
            return true;
        }

        Audit::log('api.credential_scope_denied', (int) $license->id, Audit::RESULT_DENIED, [
            'product_id' => (int) $license->product_id,
            'allowed'    => $request->credentialProducts,
        ], Audit::ACTOR_API);

        return false;
    }

    /**
     * Record that a caller failed to prove an installation it claimed.
     *
     * Audited rather than silent, because the pattern matters: a burst of these
     * against one installation id is what a cloned deployment looks like, and
     * nothing else would show it.
     */
    private static function denyInstallationClaim(object $activation, string $reason): void
    {
        Audit::log('activation.claim_denied', (int) $activation->license_id, Audit::RESULT_DENIED, [
            'activation_id'   => (int) $activation->id,
            'installation_id' => (string) $activation->installation_id,
            'reason'          => $reason,
        ], Audit::ACTOR_API);
    }

    /**
     * Record the public key an installation wants to be identified by.
     *
     * Called only from the two points where identity is established or
     * re-established, so a caller that could not prove the current credential
     * cannot register a key of its own choosing against someone else's
     * activation - that path issues a fresh secret first, which retires the copy
     * holding the old one.
     *
     * A key that does not parse is ignored rather than refused. The client keeps
     * its secret and stays on the HMAC path; refusing the activation outright
     * would turn a client-side crypto quirk into a licensing failure, and the
     * secret path is exactly as authentic as it was before.
     *
     * Once registered a key is never silently cleared: {@see provesInstallation()}
     * checks the key first, so dropping it would quietly reopen the weaker
     * scheme for an installation that had moved past it.
     */
    private static function registerInstallKey(int $activationId, LicenseRequest $request): void
    {
        $key = trim($request->installPublicKey);
        if ($key === '') {
            return;
        }

        $algorithm = $request->installKeyAlgorithm !== ''
            ? strtolower($request->installKeyAlgorithm)
            : Crypto::ALG_ED25519;

        if (!in_array($algorithm, [Crypto::ALG_ED25519, Crypto::ALG_RSA], true)) {
            return;
        }

        if (!Crypto::isUsablePublicKey($key, $algorithm)) {
            Audit::log('activation.key_rejected', null, Audit::RESULT_FAILURE, [
                'activation_id' => $activationId,
                'algorithm'     => $algorithm,
                'reason'        => 'the public key could not be parsed',
            ], Audit::ACTOR_API);

            return;
        }

        Db::table('activations')->where('id', $activationId)->update([
            'install_public_key'    => $key,
            'install_key_algorithm' => $algorithm,
            'install_key_at'        => Db::now(),
        ]);

        Audit::log('activation.key_registered', null, Audit::RESULT_SUCCESS, [
            'activation_id' => $activationId,
            'algorithm'     => $algorithm,
        ], Audit::ACTOR_API);
    }

    /**
     * Mint and store a fresh per-installation secret, returning the plaintext.
     *
     * Called only where identity is being established rather than confirmed: a
     * first activation, an activation reclaimed by a caller who could not prove
     * the old secret, or one that predates the scheme.
     *
     * @return string The plaintext, returned to the client exactly once.
     */
    private static function issueInstallSecret(int $activationId): string
    {
        $secret = 'lfi_' . Crypto::base64UrlEncode(random_bytes(32));

        Db::table('activations')->where('id', $activationId)->update([
            'install_secret'    => Crypto::encrypt($secret),
            'install_secret_at' => Db::now(),
        ]);

        return $secret;
    }

    /**
     * The secret to hand back when re-activating an installation we recognise.
     *
     * Reaching here means {@see provesInstallation()} was satisfied, so the
     * caller either proved the secret it already holds or is on the
     * grandfathered path with none. The two want opposite things: one keeps its
     * secret, the other needs issuing with one.
     *
     * Rotating in the first case was wrong. Activations of one licence are
     * serialised on the licence row lock, so the two requests never interleave -
     * and it still broke, because serialising does not help: the first commits a
     * new secret and returns it, the second commits another and returns that,
     * and whichever client holds the older one is now unprovable and will be
     * metered as a new installation. Two workers starting together, a duplicated
     * cron, a retried deploy step - an ordinary duplicate request, not a race in
     * the usual sense.
     *
     * Nothing is lost by keeping it. A caller who cannot prove the secret never
     * arrives here; that path resolves as new and issues a fresh secret where
     * identity actually has to be re-established. So a lost secret is still
     * recoverable and a clone is still retired.
     *
     * @return string Plaintext to return to the client, or '' when it already
     *   holds the right one. Never hands back a stored secret: a caller that
     *   could not prove possession must not be able to collect one by asking.
     */
    private static function keepOrIssueInstallSecret(object $activation): string
    {
        $stored = (string) ($activation->install_secret ?? '');

        return $stored === '' ? self::issueInstallSecret((int) $activation->id) : '';
    }

    /**
     * Bind an installation to a licence, consuming a slot.
     *
     * Idempotent for an installation that proves itself: re-activating one
     * already bound refreshes its record and returns success without consuming
     * another slot. A previously released installation may reclaim its row, but
     * must pass the activation limit again like any newcomer.
     *
     * The real work happens inside a transaction under the licence row lock,
     * where the admissibility check is re-run and the slot allocated. See the
     * comments there for why the earlier check is not sufficient.
     *
     * @return CheckResult On success, carries `license`, `activation`, and
     *   `install_secret` when one was issued.
     */
    public static function activate(LicenseRequest $request): CheckResult
    {
        $license = LicenseManager::findByKey($request->licenseKey);
        if ($license === null) {
            return CheckResult::fail(ErrorCodes::INVALID_LICENSE);
        }
        if (!self::credentialMayUse($license, $request)) {
            return CheckResult::fail(ErrorCodes::CREDENTIAL_SCOPE);
        }

        $existing = self::resolve($license, $request);

        // A released row is not an existing binding: it must pass the limit
        // again before it can be reclaimed.
        if ($existing !== null && (string) $existing->status !== 'active') {
            $existing = null;
        }

        $check = ValidationService::evaluate($license, $request, $existing, 'activate');
        if ($check->failed()) {
            if ($check->code() === ErrorCodes::ACTIVATION_LIMIT) {
                Notifier::activationLimitReached($license);
            }

            return $check->with(['license' => $license]);
        }

        $limitHit      = false;
        $slotsUsed     = 0;
        $slotsLimit    = 0;
        $failure       = null;
        $installSecret = '';

        /*
         * The check above is a fast path that avoids taking a lock in the common
         * case. It is NOT the enforcement point: between it and the insert
         * below, a concurrent request can claim the same slot, and two
         * activations fit through a limit of one. The unique index on
         * (license_id, installation_id) does not help - it only stops one
         * installation racing itself, while the interesting race is two
         * different ones. So the slot is allocated again, under a row lock, as
         * part of the same transaction that creates the activation.
         */
        $activation = Db::transaction(static function () use (
            &$license, $request, &$existing, &$check, &$limitHit, &$slotsUsed, &$slotsLimit, &$failure, &$installSecret
        ) {
            $now            = Db::now();
            $installationId = $request->resolvedInstallationId();

            /*
             * Serialise every concurrent activation of this licence behind one
             * row lock, so the count below cannot be stale by the time we act on
             * it. Held until commit.
             *
             * The locked row replaces the caller's copy for everything that
             * follows. Taking the lock and then judging the request against a
             * licence read before it would waste the lock: an administrator
             * revoking the licence or lowering max_activations in the meantime
             * would be invisible, and the decision would be made on a state that
             * no longer exists.
             */
            $locked = Db::table('licenses')->where('id', (int) $license->id)->lockForUpdate()->first();
            if ($locked === null) {
                $failure = CheckResult::fail(ErrorCodes::INVALID_LICENSE);

                return null;
            }
            $license    = (object) $locked;
            $slotsLimit = (int) $license->max_activations;

            /*
             * Re-resolve under the lock. The fast path may have looked before a
             * concurrent request for the SAME installation created its row, and
             * acting on that stale answer refuses an installation the slot it
             * already owns: two parallel activations of one install, limit of
             * one, and the second gets ACTIVATION_LIMIT. Re-reading here makes
             * activation idempotent under concurrency.
             */
            $resolved = self::resolve($license, $request);
            if ($resolved !== null && (string) $resolved->status !== 'active') {
                $resolved = null;
            }

            $recheck = ValidationService::evaluate($license, $request, $resolved, 'activate');
            if ($recheck->failed()) {
                $failure = $recheck;

                return null;
            }
            $check = $recheck;

            $existing = $resolved;

            if ($existing === null && $slotsLimit > 0) {
                $slotsUsed = (int) Db::table('activations')
                    ->where('license_id', (int) $license->id)
                    ->where('status', 'active')
                    ->count();

                if ($slotsUsed >= $slotsLimit) {
                    $limitHit = true;

                    return null;
                }
            }

            if ($existing !== null) {
                // Refresh in place. Each field falls back to what is recorded,
                // so a client that omits one does not erase it.
                Db::table('activations')->where('id', (int) $existing->id)->update([
                    'domain'            => $request->domain !== '' ? $request->domain : $existing->domain,
                    'ip_address'        => $request->observedIp !== '' ? $request->observedIp : $existing->ip_address,
                    'directory'         => $request->directory !== '' ? $request->directory : $existing->directory,
                    'machine_id'        => $request->machineId !== '' ? $request->machineId : $existing->machine_id,
                    'version'           => $request->version !== '' ? $request->version : $existing->version,
                    'metadata'          => json_encode($request->metadata),
                    'last_domain'       => $request->domain !== '' ? $request->domain : $existing->last_domain,
                    'last_ip'           => $request->observedIp !== '' ? $request->observedIp : $existing->last_ip,
                    'last_validated_at' => $now,
                    'status'            => 'active',
                    'deactivated_at'    => null,
                    'deactivated_reason' => null,
                    'updated_at'        => $now,
                ]);

                $installSecret = self::keepOrIssueInstallSecret($existing);
                self::registerInstallKey((int) $existing->id, $request);

                return Db::table('activations')->where('id', (int) $existing->id)->first();
            }

            /*
             * Re-use a RELEASED row for the same installation id if present, so
             * the unique (licence, installation) index is respected.
             *
             * The status filter is the whole point of this query. Reaching here
             * means resolve() returned null, which for an installation id that
             * already exists means one thing: the caller could not prove it owns
             * that activation. Without the filter the row it failed to prove
             * would be found anyway and overwritten in place - new bindings, a
             * fresh secret handed to the caller, and the legitimate
             * installation's credential retired. An unproven caller could
             * therefore displace a live installation just by knowing its id,
             * which is not a secret: it travels in API responses and sits in the
             * client's cache. Any licence with a spare slot was exposed, because
             * the limit check passes before this point.
             *
             * That directly contradicted what the proof is for. Failing it is
             * supposed to mean "this is not that installation"; the row must not
             * then be located by the very identifier the proof exists to stop
             * being taken at face value.
             */
            $released = Db::table('activations')
                ->where('license_id', (int) $license->id)
                ->where('installation_id', $installationId)
                ->where('status', '!=', 'active')
                ->first();

            if ($released === null) {
                $occupied = Db::table('activations')
                    ->where('license_id', (int) $license->id)
                    ->where('installation_id', $installationId)
                    ->where('status', 'active')
                    ->first();

                if ($occupied !== null) {
                    Audit::log('activation.takeover_refused', (int) $license->id, Audit::RESULT_DENIED, [
                        'activation_id'   => (int) $occupied->id,
                        'installation_id' => $installationId,
                        'reason'          => 'an unproven caller claimed an active installation id',
                    ], Audit::ACTOR_API);

                    $failure = CheckResult::fail(
                        ErrorCodes::ACTIVATION_NOT_FOUND,
                        'That installation is already active and this request could not prove it owns it. '
                        . 'Release the installation first, or contact support to reset it.'
                    );

                    return null;
                }
            }

            $payload = [
                'license_id'        => (int) $license->id,
                'installation_id'   => $installationId,
                'status'            => 'active',
                'domain'            => $request->domain !== '' ? $request->domain : null,
                'ip_address'        => $request->observedIp !== '' ? $request->observedIp : null,
                'directory'         => $request->directory !== '' ? $request->directory : null,
                'machine_id'        => $request->machineId !== '' ? $request->machineId : null,
                'version'           => $request->version !== '' ? $request->version : null,
                'last_domain'       => $request->domain !== '' ? $request->domain : null,
                'last_ip'           => $request->observedIp !== '' ? $request->observedIp : null,
                'metadata'          => json_encode($request->metadata),

                // Cleared explicitly: a reclaimed row must not keep the previous
                // occupant's key, which would authenticate them against it.
                'install_public_key'    => null,
                'install_key_algorithm' => null,
                'install_key_at'        => null,

                'first_activated_at' => $now,
                'last_validated_at' => $now,
                'deactivated_at'    => null,
                'deactivated_reason' => null,
                'validation_count'  => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];

            if ($released !== null) {
                Db::table('activations')->where('id', (int) $released->id)->update($payload);
                $activationId = (int) $released->id;
            } else {
                $activationId = (int) Db::table('activations')->insertGetId($payload);
            }

            // The licence's primary bindings are recorded from the first
            // installation to supply each, and never overwritten afterwards.
            $licenseUpdates = ['updated_at' => $now];
            if ($license->activated_at === null) {
                $licenseUpdates['activated_at'] = $now;
            }
            if (($license->primary_domain ?? '') === '' && $request->domain !== '') {
                $licenseUpdates['primary_domain'] = $request->domain;
            }
            if (($license->primary_ip ?? '') === '' && $request->observedIp !== '') {
                $licenseUpdates['primary_ip'] = $request->observedIp;
            }
            if (($license->primary_directory ?? '') === '' && $request->directory !== '') {
                $licenseUpdates['primary_directory'] = $request->directory;
            }
            if (($license->primary_machine_id ?? '') === '' && $request->machineId !== '') {
                $licenseUpdates['primary_machine_id'] = $request->machineId;
            }
            Db::table('licenses')->where('id', (int) $license->id)->update($licenseUpdates);

            $installSecret = self::issueInstallSecret($activationId);
            self::registerInstallKey($activationId, $request);

            return Db::table('activations')->where('id', $activationId)->first();
        });

        if ($failure !== null) {
            if ($failure->code() === ErrorCodes::ACTIVATION_LIMIT) {
                Notifier::activationLimitReached($license);
            }

            return $failure->with(['license' => $license]);
        }

        if ($limitHit) {
            Notifier::activationLimitReached($license);
            Audit::log('license.activation_refused', (int) $license->id, Audit::RESULT_DENIED, $request->toLogContext() + [
                'reason' => 'activation_limit_race',
                'used'   => $slotsUsed,
                'limit'  => $slotsLimit,
            ], Audit::ACTOR_API);

            return CheckResult::fail(ErrorCodes::ACTIVATION_LIMIT, null, [
                'used' => $slotsUsed, 'limit' => $slotsLimit,
            ])->with(['license' => $license]);
        }

        LicenseManager::recalculateActivationCount((int) $license->id);
        $license = LicenseManager::find((int) $license->id);

        Audit::log('license.activated', (int) $license->id, Audit::RESULT_SUCCESS, $request->toLogContext() + [
            'reused' => $existing !== null,
        ], Audit::ACTOR_API);

        if ($existing === null) {
            Notifier::licenseActivated($license, $activation);
        }

        return CheckResult::ok($check->data() + [
            'license'       => $license,
            'activation'    => $activation,

            // Empty when the caller already holds the right secret.
            'install_secret' => $installSecret,
        ]);
    }

    /**
     * The periodic check-in: is this installation still licensed?
     *
     * The hot path - every installation calls it on a schedule - so it does no
     * locking and allocates nothing. It resolves the installation, evaluates the
     * licence against it, and records the check-in.
     *
     * An unrecognised caller is not refused: it is told it needs to activate,
     * which is the correct answer for software whose installation record was
     * reset or released. What it does not get is an offline token, since it
     * holds no slot.
     */
    public static function validate(LicenseRequest $request): CheckResult
    {
        $license = LicenseManager::findByKey($request->licenseKey);
        if ($license === null) {
            return CheckResult::fail(ErrorCodes::INVALID_LICENSE);
        }
        if (!self::credentialMayUse($license, $request)) {
            return CheckResult::fail(ErrorCodes::CREDENTIAL_SCOPE);
        }

        $activation = self::resolve($license, $request);
        if ($activation !== null && (string) $activation->status !== 'active') {
            return CheckResult::fail(ErrorCodes::ACTIVATION_NOT_FOUND, 'This installation is no longer activated.')
                ->with(['license' => $license]);
        }

        $check = ValidationService::evaluate($license, $request, $activation, 'validate');
        if ($check->failed()) {
            return $check->with(['license' => $license, 'activation' => $activation]);
        }

        return CheckResult::ok($check->data() + [
            'license'       => $license,
            'activation'    => $activation,
            'needs_activation' => $activation === null,
        ]);
    }

    /**
     * Release an installation at the client's own request.
     *
     * Requires the same proof as any other claim on an activation, so one
     * installation cannot release another's slot by naming its id.
     */
    public static function deactivate(LicenseRequest $request, string $reason = 'client request'): CheckResult
    {
        $license = LicenseManager::findByKey($request->licenseKey);
        if ($license === null) {
            return CheckResult::fail(ErrorCodes::INVALID_LICENSE);
        }
        if (!self::credentialMayUse($license, $request)) {
            return CheckResult::fail(ErrorCodes::CREDENTIAL_SCOPE);
        }

        $activation = self::resolve($license, $request);
        if ($activation === null || (string) $activation->status !== 'active') {
            return CheckResult::fail(ErrorCodes::ACTIVATION_NOT_FOUND)->with(['license' => $license]);
        }

        self::release((int) $activation->id, $reason);

        return CheckResult::ok([
            'license'    => LicenseManager::find((int) $license->id),
            'activation' => Db::table('activations')->where('id', (int) $activation->id)->first(),
        ]);
    }

    /**
     * Release one activation, freeing its slot.
     *
     * Runs in a transaction under the same licence row lock that
     * {@see activate()} takes, and re-reads the activation inside it. Without
     * that, a release and a concurrent activation could interleave and disagree
     * about the slot count - whichever committed last would win, and the counter
     * would drift from the rows.
     *
     * A row that stopped being active under the lock is not released again,
     * which is what makes a double submission or two administrators acting at
     * once harmless.
     *
     * @param  string|null $staleBefore Only release if the installation has not
     *   checked in since this time. The stale sweep chooses its candidates in
     *   advance, and one that checked in between the choosing and the acting has
     *   just proved it is alive.
     * @return bool True if this call released it.
     */
    public static function release(int $activationId, string $reason = '', ?string $staleBefore = null): bool
    {
        $existing = Db::table('activations')->where('id', $activationId)->first();
        if ($existing === null) {
            return false;
        }

        $licenseId = (int) $existing->license_id;

        return (bool) Db::transaction(static function () use ($activationId, $licenseId, $reason, $staleBefore): bool {
            // Taken for its lock alone; the row itself is not needed here.
            Db::table('licenses')->where('id', $licenseId)->lockForUpdate()->first();

            $activation = Db::table('activations')->where('id', $activationId)->first();
            if ($activation === null || (string) $activation->status !== 'active') {
                return false;
            }

            if ($staleBefore !== null) {
                $seen = (string) ($activation->last_validated_at ?? '');
                if ($seen === '' || $seen >= $staleBefore) {
                    return false;
                }
            }

            Db::table('activations')->where('id', $activationId)->update([
                'status'             => 'deactivated',
                'deactivated_at'     => Db::now(),
                'deactivated_reason' => mb_substr($reason, 0, 190),
                'updated_at'         => Db::now(),
            ]);

            LicenseManager::recalculateActivationCount($licenseId);

            Audit::log('activation.released', $licenseId, Audit::RESULT_SUCCESS, [
                'activation_id'   => $activationId,
                'installation_id' => $activation->installation_id,
                'domain'          => $activation->domain,
                'reason'          => $reason,
            ]);

            return true;
        });
    }

    /**
     * Release every installation bound to a licence.
     *
     * The remedy when a customer has stranded their activations - moved servers
     * without releasing, or lost access to the machines. Their software
     * re-activates on its next check-in.
     *
     * @return int Activations released.
     */
    public static function releaseAll(int $licenseId, string $reason = 'reset by administrator'): int
    {
        $count = 0;
        foreach (Db::table('activations')->where('license_id', $licenseId)->where('status', 'active')->get() as $row) {
            if (self::release((int) $row->id, $reason)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A licence's activations, for the admin and client panels.
     *
     * Released rows are included by default, because the history of where a
     * licence has run is what answers most support questions about it.
     *
     * @return iterable<object>
     */
    public static function forLicense(int $licenseId, bool $activeOnly = false)
    {
        $query = Db::table('activations')->where('license_id', $licenseId);
        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * Activations that have stopped checking in.
     *
     * The threshold is twice the validation interval, allowing for an
     * installation legitimately running offline on a signed token, which stops
     * calling home for the length of that token and is entitled to.
     *
     * @return iterable<object>
     */
    public static function staleActivations(int $licenseId)
    {
        $hours  = max(1, Settings::int('validation_interval_hours', 24)) * 2;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));

        return Db::table('activations')
            ->where('license_id', $licenseId)
            ->where('status', 'active')
            ->where(static function ($q) use ($cutoff): void {
                $q->whereNull('last_validated_at')->orWhere('last_validated_at', '<', $cutoff);
            })
            ->get();
    }

    /**
     * A short human label for an activation.
     *
     * Prefers whatever most identifies the installation to the person reading
     * it - a customer recognises their domain, not an installation id.
     */
    public static function describe(object $activation): string
    {
        $parts = array_filter([
            $activation->domain ?? null,
            $activation->ip_address ?? null,
            $activation->directory ?? null,
        ]);

        return $parts === [] ? (string) $activation->installation_id : implode(' · ', $parts);
    }

    /**
     * Normalise a list of domains for storage.
     *
     * Applied when an administrator saves allowed domains, so the stored values
     * are already in the canonical form the validation path compares against.
     * Otherwise a licence could be locked to a domain that never matches: the
     * comparison normalises the incoming value but not the stored pattern.
     *
     * @param  list<string> $domains
     * @return list<string>
     */
    public static function normaliseDomainList(array $domains): array
    {
        $clean = [];
        foreach ($domains as $domain) {
            $domain = trim((string) $domain);
            if ($domain === '') {
                continue;
            }
            // The wildcard is stripped before normalising and restored after,
            // since `*.` is not part of a hostname.
            $wildcard = strncmp($domain, '*.', 2) === 0;
            $bare     = Net::normaliseDomain($wildcard ? substr($domain, 2) : $domain);
            if ($bare === '') {
                continue;
            }
            $clean[] = $wildcard ? '*.' . $bare : $bare;
        }

        return array_values(array_unique($clean));
    }
}
