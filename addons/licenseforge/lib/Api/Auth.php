<?php

declare(strict_types=1);

namespace LicenseForge\Api;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Net;
use LicenseForge\Support\RateLimiter;
use LicenseForge\Support\Settings;

/**
 * Server-to-server authentication for the licensing API.
 *
 * Callers prove possession of a shared secret by signing each request rather
 * than by sending the secret, so intercepting a request yields nothing
 * reusable.
 *
 * Scheme
 * ------
 *   canonical = METHOD "\n" endpoint "\n" timestamp "\n" nonce "\n" sha256hex(body)
 *   signature = hex( hmac_sha256(api_secret, canonical) )
 *
 * Headers
 * -------
 *   X-LF-Key         The public API key naming the credential.
 *   X-LF-Timestamp   Unix seconds, checked against a configurable skew window.
 *   X-LF-Nonce       8-128 URL-safe characters, single use per credential.
 *   X-LF-Signature   Lowercase hex signature of the canonical string.
 *
 * What each element defends
 * -------------------------
 * Method, endpoint and body hash are inside the signature, so none can be
 * altered in flight: a captured request cannot be pointed at a different
 * endpoint or given different parameters. The timestamp bounds how long a
 * captured request stays usable, and the nonce reduces that to a single use
 * within the window. Neither alone is sufficient - a timestamp without a nonce
 * permits replay until it expires, and a nonce without a timestamp requires
 * remembering every nonce ever seen.
 *
 * Note the deliberate asymmetry with {@see Server}: this class authenticates
 * the integration, not the customer. Where one credential is compiled into
 * every copy of a product, every customer of that product holds the same one -
 * see the discussion of credential scoping in {@see Credentials}.
 */
final class Auth
{
    /*
     * Header names, lowercase, matching how Request normalises them.
     */
    public const HEADER_KEY       = 'x-lf-key';
    public const HEADER_TIMESTAMP = 'x-lf-timestamp';
    public const HEADER_NONCE     = 'x-lf-nonce';
    public const HEADER_SIGNATURE = 'x-lf-signature';

    /**
     * Proof of possession of the per-installation secret issued at activation.
     *
     * Carried in a header rather than in the body, because the body is hashed
     * into the canonical string this signs - a proof placed inside the body
     * could not cover the body containing it.
     */
    public const HEADER_INSTALL_PROOF = 'x-lf-install-proof';

    /**
     * Constant that must be defined outside the module before the
     * `require_api_auth` setting is allowed to turn authentication off.
     *
     * See {@see unsignedPermitted()} for why the setting alone is not enough.
     */
    public const UNSIGNED_CONSTANT = 'LICENSEFORGE_ALLOW_UNSIGNED';

    /**
     * Authenticate a request and confirm its credential carries the needed scope.
     *
     * The order of checks is load-bearing in two places.
     *
     * The signature is computed before the credential is examined, and against a
     * placeholder secret when the key is unknown, so an unrecognised key costs
     * the same time as a recognised one. Returning early on a missing credential
     * would make key existence measurable through response timing.
     *
     * The nonce is consumed after the signature verifies. Burning it first would
     * let an attacker who cannot forge a signature still invalidate a legitimate
     * client's nonce by replaying it with a wrong one, turning a forgery failure
     * into a denial of service against the real caller.
     *
     * Every rejection is audited with the reason and only a prefix of the key -
     * never the signature - so a failing integration can be diagnosed from the
     * log without the log becoming a source of credentials.
     *
     * @param string $requiredScope Scope the endpoint demands, such as `activate`.
     * @return array{ok:bool,code:?string,message:?string,credential:?object}
     *   `credential` is null when authentication was skipped in unsigned mode,
     *   so callers must treat null as "unrestricted" rather than assuming a row.
     */
    public static function authenticate(Request $request, string $requiredScope): array
    {
        /*
         * Unsigned mode: permitted only when the server agrees, not the setting
         * alone. Where the two disagree signing is enforced, the safe reading -
         * but that is also a misconfiguration an operator needs to know about,
         * since their stated intent is being overridden and every unsigned
         * client is now failing. Recorded once an hour rather than once a
         * request, so a busy server in this state does not bury its audit log.
         */
        if (!Settings::bool('require_api_auth', true)) {
            if (self::unsignedPermitted()) {
                return self::pass(null);
            }

            if (RateLimiter::hit('audit:unsigned_refused', 'global', 1, 3600)['allowed']) {
                Audit::log('api.unsigned_refused', null, Audit::RESULT_DENIED, [
                    'reason'   => 'require_api_auth is off but ' . self::UNSIGNED_CONSTANT . ' is not defined; signing enforced',
                    'endpoint' => $request->endpoint(),
                    'ip'       => $request->clientIp(),
                ], Audit::ACTOR_API);
            }
        }

        $apiKey = $request->header(self::HEADER_KEY);
        if ($apiKey === '') {
            return self::deny(ErrorCodes::AUTH_REQUIRED);
        }

        $credential = Credentials::findByKey($apiKey);

        /*
         * A placeholder secret for an unknown or unreadable credential, so the
         * HMAC below is computed unconditionally. See the timing note above.
         */
        $secret = 'invalid';
        if ($credential !== null && $credential->secret_encrypted !== null) {
            try {
                $secret = Crypto::decrypt((string) $credential->secret_encrypted);
            } catch (\Throwable $e) {
                $secret = 'invalid';
            }
        }

        $timestamp = (int) $request->header(self::HEADER_TIMESTAMP);
        $nonce     = $request->header(self::HEADER_NONCE);
        $signature = strtolower($request->header(self::HEADER_SIGNATURE));

        $expected = self::sign($secret, $request->method(), $request->endpoint(), (string) $timestamp, $nonce, $request->rawBody());
        $signatureOk = Crypto::secureEquals($expected, $signature);

        if ($credential === null) {
            self::logFailure($apiKey, ErrorCodes::AUTH_INVALID, $request);

            return self::deny(ErrorCodes::AUTH_INVALID);
        }

        if (!(bool) $credential->is_active) {
            self::logFailure($apiKey, ErrorCodes::AUTH_INVALID, $request);

            return self::deny(ErrorCodes::AUTH_INVALID);
        }

        if ($credential->expires_at !== null && strtotime((string) $credential->expires_at . ' UTC') < time()) {
            self::logFailure($apiKey, ErrorCodes::AUTH_EXPIRED, $request);

            return self::deny(ErrorCodes::AUTH_EXPIRED);
        }

        $allowedIps = array_filter(array_map('trim', explode(',', (string) ($credential->allowed_ips ?? ''))));
        if ($allowedIps !== [] && !Net::ipMatchesAny(array_values($allowedIps), $request->clientIp())) {
            self::logFailure($apiKey, ErrorCodes::IP_NOT_ALLOWED, $request);

            return self::deny(ErrorCodes::IP_NOT_ALLOWED);
        }

        // Floored at 30s: a shorter window would reject clients whose clocks are
        // merely imprecise rather than wrong.
        $skew = max(30, Settings::int('request_max_skew_seconds', 300));
        if ($timestamp <= 0 || abs(time() - $timestamp) > $skew) {
            self::logFailure($apiKey, ErrorCodes::TIMESTAMP_INVALID, $request);

            return self::deny(ErrorCodes::TIMESTAMP_INVALID);
        }

        if (strlen($nonce) < 8 || strlen($nonce) > 128 || !preg_match('/^[A-Za-z0-9._\-]+$/', $nonce)) {
            self::logFailure($apiKey, ErrorCodes::AUTH_INVALID, $request);

            return self::deny(ErrorCodes::AUTH_INVALID, 'A nonce of 8-128 URL-safe characters is required.');
        }

        if (!$signatureOk) {
            self::logFailure($apiKey, ErrorCodes::SIGNATURE_INVALID, $request);

            return self::deny(ErrorCodes::SIGNATURE_INVALID);
        }

        // Remembered for twice the skew window, so a nonce cannot be forgotten
        // while a request bearing it is still within its acceptable timestamp.
        if (!RateLimiter::consumeNonce($nonce, (int) $credential->id, $skew * 2)) {
            self::logFailure($apiKey, ErrorCodes::REPLAY_DETECTED, $request);

            return self::deny(ErrorCodes::REPLAY_DETECTED);
        }

        if (!Credentials::hasScope($credential, $requiredScope)) {
            self::logFailure($apiKey, ErrorCodes::SCOPE_DENIED, $request);

            return self::deny(ErrorCodes::SCOPE_DENIED);
        }

        Credentials::recordUse((int) $credential->id, $request->clientIp());

        return self::pass($credential);
    }

    /**
     * Is unsigned mode actually permitted on this server?
     *
     * Turning authentication off is the most consequential thing the settings
     * page can do, and a web form is the wrong place for that to be the only
     * guard: an attacker holding a hijacked admin session, or landing a CSRF
     * against a logged-in one, can post it as easily as an administrator can
     * type it, and a typed confirmation phrase is no obstacle to a script.
     *
     * So the setting alone does not decide. The constant must also be defined in
     * WHMCS's configuration.php - a file this module cannot write, and reaching
     * which requires shell or FTP access. Anyone with that already controls the
     * server; the point is that compromising the admin panel is not sufficient
     * on its own.
     */
    public static function unsignedPermitted(): bool
    {
        return defined(self::UNSIGNED_CONSTANT) && constant(self::UNSIGNED_CONSTANT) === true;
    }

    /**
     * Produce the hex signature for a request.
     *
     * Shared with the client SDKs, which must build the identical canonical
     * string. Any divergence here breaks every integration at once, so this and
     * {@see canonical()} are the two methods in the module that cannot change
     * without a coordinated SDK release.
     */
    public static function sign(string $secret, string $method, string $endpoint, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $endpoint, $timestamp, $nonce, $body), $secret);
    }

    /**
     * The exact string both signatures cover.
     *
     * Fields are joined with newlines and the body appears as a hash rather than
     * inline, so the string stays a fixed shape regardless of payload size.
     * Method and endpoint are case-normalised so a client differing only in case
     * still produces a matching signature.
     *
     * Shared by credential authentication and the per-installation proof, which
     * is what gives the proof the same replay protection: the timestamp and
     * nonce are inside it, and the nonce is single-use.
     */
    public static function canonical(string $method, string $endpoint, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            strtolower($endpoint),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * A successful authentication result.
     *
     * @return array{ok:bool,code:?string,message:?string,credential:?object}
     */
    private static function pass(?object $credential): array
    {
        return ['ok' => true, 'code' => null, 'message' => null, 'credential' => $credential];
    }

    /**
     * A failed authentication result.
     *
     * Never carries the credential, even where one was found, so a caller cannot
     * act on a row that failed a later check.
     *
     * @return array{ok:bool,code:?string,message:?string,credential:?object}
     */
    private static function deny(string $code, ?string $message = null): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'credential' => null];
    }

    /**
     * Record an authentication failure.
     *
     * Only a prefix of the key is stored and the signature never is, so the
     * audit log stays useful for diagnosis without becoming somewhere a
     * credential can be recovered from.
     */
    private static function logFailure(string $apiKey, string $code, Request $request): void
    {
        Audit::log('api.auth_failed', null, Audit::RESULT_DENIED, [

            'api_key_prefix' => substr($apiKey, 0, 12),
            'code'           => $code,
            'endpoint'       => $request->endpoint(),
            'ip'             => $request->clientIp(),
        ], Audit::ACTOR_API);

        // Counted against the same per-IP failure bucket the licensing refusals
        // feed. Without this the bucket saw only failures that got past
        // authentication, so a source guessing credentials - the case it most
        // needs to catch - was bounded by the per-endpoint limit alone.
        Server::noteFailure($request->clientIp());
    }
}
