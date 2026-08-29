<?php

declare(strict_types=1);

namespace LicenseForge\Api;

use LicenseForge\Licensing\AbuseDetector;
use LicenseForge\Licensing\ActivationService;
use LicenseForge\Licensing\CheckResult;
use LicenseForge\Licensing\LicenseManager;
use LicenseForge\Licensing\LicenseRequest;
use LicenseForge\Licensing\ValidationService;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Input;
use LicenseForge\Support\RateLimiter;
use LicenseForge\Support\Settings;

/**
 * The licensing API's router and request pipeline.
 *
 * Every authenticated call passes through here in a fixed order:
 *
 *   route -> method -> rate limit -> authenticate -> per-credential limit
 *         -> validate input -> licensing decision -> record -> respond
 *
 * The ordering is the design. Rate limiting runs before authentication so an
 * unauthenticated flood is refused without the cost of decrypting a secret and
 * computing an HMAC; per-credential limiting necessarily runs after, since
 * until then the caller is anonymous.
 *
 * The surface is deliberately minimal. An installation activates once, then
 * checks in. Everything else - moving a licence, releasing an installation,
 * viewing details - is done by the customer on their product page or by staff
 * in the admin area, so none of it needs a machine-facing endpoint, and every
 * endpoint that does not exist is one that cannot be abused.
 *
 * This class decides nothing about licensing itself. Whether a licence may be
 * activated lives in LicenseForge\Licensing, so the same rules apply here, in
 * the client area and in the cron run.
 */
final class Server
{
    /**
     * Endpoint => [required scope, permitted HTTP methods].
     *
     * The whitelist is the routing boundary: an endpoint absent from this table
     * reaches no code at all.
     *
     * POST only, for both. A GET carries its parameters in the query string,
     * which the signature does not cover, so a GET endpoint would accept
     * parameters authenticated by nothing. See {@see Request::capture()}.
     */
    private const ROUTES = [
        'activate' => ['activate', ['POST']],

        'check'    => ['check',    ['POST']],
    ];

    /**
     * Run one request through the pipeline and return the response to send.
     *
     * Timing starts before any work, so the recorded duration reflects what the
     * caller actually waited for - including the rate-limit and authentication
     * checks rather than only the licensing decision.
     *
     * Every exception is caught here. The caller receives a generic
     * INTERNAL_ERROR while the detail goes to the PHP error log, so a bug cannot
     * disclose server paths or internal state to a customer's software. It also
     * means a failure still produces well-formed JSON, which keeps a client's
     * error handling working rather than facing a truncated body.
     */
    public static function handle(Request $request): Response
    {
        $started = microtime(true);

        try {
            if (!Settings::bool('module_enabled', true)) {
                return Response::error(ErrorCodes::SERVICE_UNAVAILABLE);
            }

            $endpoint = $request->endpoint();
            if (!isset(self::ROUTES[$endpoint])) {
                return Response::error(ErrorCodes::UNSUPPORTED_ENDPOINT, null, [
                    'available' => array_keys(self::ROUTES),
                ]);
            }

            [$scope, $methods] = self::ROUTES[$endpoint];
            if (!in_array($request->method(), $methods, true)) {
                return Response::error(ErrorCodes::METHOD_NOT_ALLOWED)->withHeader('Allow', implode(', ', $methods));
            }

            $limitCheck = self::enforceRateLimits($request, $endpoint);
            if ($limitCheck !== null) {
                return $limitCheck;
            }

            $auth = Auth::authenticate($request, $scope);
            if (!$auth['ok']) {
                return Response::error((string) $auth['code'], $auth['message']);
            }

            $credentialLimit = self::enforceCredentialLimit($auth['credential']);
            if ($credentialLimit !== null) {
                return $credentialLimit;
            }

            if ($endpoint === 'activate') {
                return self::activate($request, $started, $auth['credential']);
            }

            return self::check($request, $started, $auth['credential']);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[LicenseForge] API error on %s: %s in %s:%d',
                $request->endpoint(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return Response::error(ErrorCodes::INTERNAL_ERROR);
        }
    }

    /**
     * Bind an installation to a licence, consuming an activation slot.
     *
     * Called once by an installation, at first run. The response carries the
     * installation's own credential, which it must store - see {@see finish()}.
     *
     * Two rate-limit buckets apply here, because one cannot do both jobs; see
     * the comment at each.
     *
     * @param object|null $credential The authenticated credential, or null in
     *   unsigned mode.
     */
    private static function activate(Request $request, float $started, ?object $credential = null): Response
    {
        $licenseRequest = LicenseRequest::fromArray($request->all(), $request->clientIp());
        self::attachInstallProof($licenseRequest, $request);
        self::attachCredentialScope($licenseRequest, $credential);

        $missing = $licenseRequest->missingFor('activate');
        if ($missing !== []) {
            return Response::error(ErrorCodes::MISSING_PARAMETER, null, ['missing' => $missing]);
        }

        /*
         * Two activation buckets, keyed differently.
         *
         * The per-licence bucket is the abuse limit: it stops anyone hammering
         * activation for a single key, whoever they are. But it is keyed on a
         * value the caller supplies, so on its own it hands every caller a way
         * to exhaust someone else's quota - the API credential ships inside the
         * product, so every customer holds one, and a customer who learns
         * another's licence key could spend that licence's activations until the
         * window rolled. The victim would see RATE_LIMITED with nothing
         * identifying who caused it.
         *
         * The per-credential-and-licence bucket makes the cost land on the
         * caller instead. Checked first, and deliberately tighter: a single
         * integration has no legitimate reason to activate one licence
         * repeatedly, while the shared bucket must still tolerate every holder
         * of that licence doing so.
         *
         * Worth being exact about its limit. Where one credential is compiled
         * into every copy of a product - the common deployment - the credential
         * id is a constant, so this bucket collapses back onto the licence and
         * buys nothing: attacker and victim are the same caller as far as the
         * server can tell. It separates them only once credentials are issued
         * per customer. That is the point of doing it now rather than later: the
         * limit becomes meaningful the moment the deployment does, with no
         * second change here.
         */
        $keyLimit = Settings::int('rate_limit_activate_key', 10);

        if ($credential !== null) {
            $scoped = RateLimiter::hit(
                'activate:credential',
                $credential->id . ':' . hash('sha256', $licenseRequest->licenseKey),
                max(1, (int) ceil($keyLimit / 2)),
                3600
            );
            if (!$scoped['allowed']) {
                return self::rateLimited($scoped);
            }
        }

        $hit = RateLimiter::hit('activate:key', $licenseRequest->licenseKey, $keyLimit, 3600);
        if (!$hit['allowed']) {
            return self::rateLimited($hit);
        }

        $result = ActivationService::activate($licenseRequest);

        return self::finish($licenseRequest, 'activate', $result, $started, true);
    }

    /**
     * The periodic check-in: is this installation still licensed?
     *
     * The hot path - every installation calls it on a schedule - so it carries a
     * far higher per-IP allowance than activation and takes no bucket of its own
     * beyond that.
     *
     * @param object|null $credential The authenticated credential, or null in
     *   unsigned mode.
     */
    private static function check(Request $request, float $started, ?object $credential = null): Response
    {
        $licenseRequest = LicenseRequest::fromArray($request->all(), $request->clientIp());
        self::attachInstallProof($licenseRequest, $request);
        self::attachCredentialScope($licenseRequest, $credential);

        $missing = $licenseRequest->missingFor('check');
        if ($missing !== []) {
            return Response::error(ErrorCodes::MISSING_PARAMETER, null, ['missing' => $missing]);
        }

        $result = ActivationService::validate($licenseRequest);

        return self::finish($licenseRequest, 'check', $result, $started, true);
    }

    /**
     * Tell the licensing layer which products this caller may act on.
     *
     * Null means unrestricted, and only two things produce it: a credential
     * whose administrator deliberately ticked "all products", and unsigned mode,
     * where there is no credential to restrict.
     *
     * A blank product list is not the same thing. An absent restriction is
     * indistinguishable from one nobody got round to setting, so it authorises
     * nothing: reaching every product requires ticking the flag, never merely
     * leaving the list empty.
     */
    private static function attachCredentialScope(LicenseRequest $licenseRequest, ?object $credential): void
    {
        if ($credential === null) {
            return;
        }

        if (!empty($credential->allow_all_products)) {
            return;
        }

        $licenseRequest->credentialProducts = array_values(array_filter(array_map(
            'intval',
            Input::toList((string) ($credential->allowed_products ?? ''))
        )));
    }

    /**
     * Hand the installation proof to the licensing layer.
     *
     * The proof is an HMAC or a signature over the same canonical string that
     * credential authentication signs, so it is reconstructed here - where
     * method, endpoint, timestamp, nonce and raw body are all still available -
     * rather than having the licensing layer reach back into the transport.
     * Which of the two it is depends on whether the installation registered a
     * public key, and {@see ActivationService} decides that, not this method.
     *
     * A proof that is neither shape is dropped rather than rejected: the request
     * then proceeds unproven and faces the activation limit like any newcomer,
     * which is the correct treatment of a caller that has not demonstrated who
     * it is.
     */
    private static function attachInstallProof(LicenseRequest $licenseRequest, Request $request): void
    {
        $proof = trim($request->header(Auth::HEADER_INSTALL_PROOF));
        if ($proof === '') {
            return;
        }

        /*
         * Two shapes reach this header and must be told apart before anything is
         * normalised. An HMAC proof is 64 hex characters, where case is
         * meaningless. A signature from an installation holding a keypair is
         * base64url, where case is the data - Ed25519 is about 86 characters and
         * RSA far longer.
         *
         * Lowercasing unconditionally would corrupt every signature, and a
         * hex-only length check would then discard it, leaving a keypair
         * installation silently unproven no matter how correctly it had signed.
         */
        if (preg_match('/^[a-f0-9]{64}$/i', $proof) === 1) {
            $proof = strtolower($proof);
        } elseif (preg_match('/^[A-Za-z0-9_-]{40,1400}$/', $proof) !== 1) {
            return;
        }

        $licenseRequest->installProof     = $proof;
        $licenseRequest->installCanonical = Auth::canonical(
            $request->method(),
            $request->endpoint(),
            (string) (int) $request->header(Auth::HEADER_TIMESTAMP),
            $request->header(Auth::HEADER_NONCE),
            $request->rawBody()
        );
    }

    /**
     * Record the outcome, run abuse detection, and build the response.
     *
     * The single exit for both endpoints, so every request is recorded exactly
     * once whether it succeeded or failed - the validation history is what the
     * abuse detector and the admin pages both read, and a path that skipped it
     * would leave a blind spot rather than a gap.
     *
     * On failure the response carries only the structured details a client can
     * act on: how many activations are in use, the limit, when a cooldown ends.
     *
     * @param bool $includeOfflineToken Whether this endpoint may issue an
     *   offline token at all; the binding check below decides whether it does.
     */
    private static function finish(
        LicenseRequest $licenseRequest,
        string $endpoint,
        CheckResult $result,
        float $started,
        bool $includeOfflineToken
    ): Response {
        $license    = $result->get('license');
        $activation = $result->get('activation');
        $elapsed    = self::elapsed($started);

        ValidationService::record(
            $licenseRequest,
            $endpoint,
            $result->isOk(),
            $result->code(),
            is_object($license) ? $license : null,
            is_object($activation) ? $activation : null,
            $elapsed
        );

        if ($result->failed()) {
            self::noteFailure($licenseRequest->observedIp);
            AbuseDetector::onFailedValidation($licenseRequest, is_object($license) ? $license : null, (string) $result->code());

            $details = [];
            foreach (['used', 'limit', 'available_at'] as $key) {
                if ($result->get($key) !== null) {
                    $details[$key] = $result->get($key);
                }
            }

            return Response::error((string) $result->code(), $result->message(), $details);
        }

        if ($endpoint === 'activate' && is_object($license)) {
            AbuseDetector::onActivation($license, $licenseRequest);
        }

        $payload = [
            'status'  => is_object($license) ? (string) $license->status : 'unknown',
            'license' => is_object($license)
                ? LicenseManager::publicPayload($license, is_object($activation) ? $activation : null)
                : [],
        ];

        if ($result->get('needs_activation') === true) {
            $payload['needs_activation'] = true;
        }

        if (is_string($result->get('install_secret')) && $result->get('install_secret') !== '') {
            /*
             * The per-installation credential, returned once, by activate only.
             * The client must store it: from here on it is how this installation
             * proves it is itself, and without it the next check-in looks like a
             * newcomer and faces the activation limit.
             */
            $payload['installation'] = [
                'id'     => is_object($activation) ? (int) $activation->id : 0,
                'secret' => (string) $result->get('install_secret'),
            ];
        }
        if ($result->get('in_grace') === true) {
            $payload['grace'] = ['active' => true, 'ends_at' => $result->get('grace_ends_at')];
        }

        /*
         * An unbound installation gets no offline token.
         *
         * The token is what lets a client keep running without contacting the
         * server, so issuing one to an installation that never claimed an
         * activation slot would hand out precisely the entitlement the slot
         * limit exists to meter - and would do it through the offline path,
         * where there is no later opportunity to refuse.
         */
        $bound = !isset($payload['needs_activation']);

        if ($includeOfflineToken && $bound && is_object($license)) {
            try {
                $payload['offline'] = LicenseManager::offlineToken($license, is_object($activation) ? $activation : null);
            } catch (\Throwable $e) {
                error_log('[LicenseForge] offline token generation failed: ' . $e->getMessage());
            }
        }

        return Response::success($payload);
    }

    /**
     * Per-IP throttling, applied before authentication.
     *
     * Runs first precisely because the caller is still anonymous: refusing a
     * flood here costs a counter increment, whereas refusing it after
     * authentication would cost a decryption and an HMAC per request.
     *
     * An unreadable client IP is not throttled, rather than throttled as a
     * shared empty bucket that would let one unidentifiable caller lock out
     * every other. The per-credential limit still applies once authenticated.
     *
     * The second check is the failure bucket: a source producing many failures
     * is refused outright for a period, which turns key guessing from slow into
     * impractical.
     *
     * @return Response|null A refusal, or null to continue.
     */
    private static function enforceRateLimits(Request $request, string $endpoint): ?Response
    {
        $ip = $request->clientIp();
        if ($ip === '') {
            return null;
        }

        $limit = $endpoint === 'activate'
            ? Settings::int('rate_limit_activate_ip', 20)
            : Settings::int('rate_limit_validate_ip', 120);

        $hit = RateLimiter::hit('api:' . $endpoint, $ip, $limit);
        if (!$hit['allowed']) {
            return self::rateLimited($hit);
        }

        $failLimit = Settings::int('rate_limit_failed_ip', 30);
        if ($failLimit > 0 && RateLimiter::peek('api:failures', $ip) > $failLimit) {
            return Response::error(ErrorCodes::ABUSE_DETECTED)->withHeader('Retry-After', '300');
        }

        return null;
    }

    /**
     * A credential's own request ceiling, counted per rate window.
     *
     * Zero means no ceiling of its own; the per-IP limits above still apply.
     * Keying the bucket on the credential makes a busy integration separable
     * from a misbehaving one - one caller exhausting its quota cannot spend
     * anyone else's.
     *
     * Audited when it trips, unlike the anonymous limits, because here the
     * caller is known and a credential repeatedly hitting its ceiling is
     * something an administrator may need to act on.
     *
     * @return Response|null A refusal, or null to continue.
     */
    private static function enforceCredentialLimit(?object $credential): ?Response
    {
        if ($credential === null) {
            return null;
        }

        $limit = (int) ($credential->rate_limit ?? 0);
        if ($limit <= 0) {
            return null;
        }

        $hit = RateLimiter::hit('api:credential', (string) $credential->id, $limit);
        if ($hit['allowed']) {
            return null;
        }

        Audit::log('api.credential_rate_limited', null, Audit::RESULT_DENIED, [
            'credential_id' => (int) $credential->id,
            'limit'         => $limit,
        ], Audit::ACTOR_API);

        return self::rateLimited($hit);
    }

    /**
     * The standard rate-limit refusal.
     *
     * Carries Retry-After so a well-behaved client waits the right amount rather
     * than guessing, and the X-RateLimit headers so an integrator can see the
     * ceiling they hit without reading the documentation.
     *
     * @param array{limit:int,remaining:int,retry_after:int} $hit
     */
    private static function rateLimited(array $hit): Response
    {
        return Response::error(ErrorCodes::RATE_LIMITED)
            ->withHeader('Retry-After', (string) max(1, $hit['retry_after']))
            ->withHeader('X-RateLimit-Limit', (string) $hit['limit'])
            ->withHeader('X-RateLimit-Remaining', '0');
    }

    /** Milliseconds spent on this request, recorded with the validation. */
    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Count a failure against the per-IP failure bucket.
     *
     * Deliberately given an effectively unlimited ceiling: this bucket exists to
     * be counted and read by {@see enforceRateLimits()}, not to refuse anything
     * itself.
     *
     * Public because the licensing layer records failures that never reach a
     * response here.
     */
    public static function noteFailure(string $ip): void
    {
        if ($ip !== '') {
            RateLimiter::hit('api:failures', $ip, PHP_INT_MAX, 300);
        }
    }
}
