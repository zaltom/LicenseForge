<?php
/**
 * LicenseForge PHP SDK
 * ====================
 *
 * A single-file client for validating LicenseForge licenses from inside your
 * PHP product. Copy it into your codebase, or install it as part of your vendor
 * tree; it has no dependencies beyond ext-curl and ext-json (ext-sodium and
 * ext-openssl are used when available, and improve security).
 *
 * ---------------------------------------------------------------------------
 * QUICK START
 * ---------------------------------------------------------------------------
 *
 *     $license = new \LicenseForge\SDK\LicenseClient([
 *         'license_key'    => 'LIC-7F92-A82D-91BC-72F4',
 *         'product_id'     => 'my-product',
 *         'license_server' => 'https://billing.example.com/modules/addons/licenseforge/api/index.php',
 *         'api_key'        => 'lfk_...',
 *         'api_secret'     => 'lfs_...',
 *         'public_key'     => '<base64 ed25519 public key from your LicenseForge server>',
 *         'cache_file'     => '/var/lib/my-product/license.cache',
 *     ]);
 *
 *     // Run once, when the customer first enters their key.
 *     $result = $license->activate();
 *     if (!$result->isValid()) {
 *         exit($result->getErrorMessage());
 *     }
 *
 *     // Run on every request/boot. Cached, so this is normally a file read.
 *     if ($license->check()->isValid()) {
 *         // ... start the application ...
 *     }
 *
 * That is the whole API surface you need. Moving a license to a new server, or
 * releasing an activation slot, is done by the customer from their product page
 * in your client area - not through this SDK.
 *
 * ---------------------------------------------------------------------------
 * WHERE TO PUT THE CACHE FILE
 * ---------------------------------------------------------------------------
 *
 * `cache_file` must point somewhere that is NOT reachable over HTTP, and that
 * PHP can write to. It stores the license key and the signed offline token, so
 * a downloadable cache file leaks both. The constructor refuses a path inside
 * DOCUMENT_ROOT unless you explicitly set `cache_allow_web_root`.
 *
 * Two more files are created alongside it:
 *
 *     license.cache            the cached answer + signed offline token
 *     license.cache.install    this installation's activation secret
 *     license.cache.install.key  this installation's private key
 *
 * Despite the name, this directory is PERSISTENT STATE, not a cache. Only the
 * first file is disposable; the other two are this installation's identity. Put
 * it where you would put a data directory - outside the release, backed up with
 * the install - and never inside anything a deploy replaces.
 *
 * Losing the secret while `installation_id` stays the same is a lockout, not a
 * silent extra activation: the server refuses a caller that cannot prove it owns
 * the slot it is still holding, and recovery needs a reset. See the note on
 * activate().
 *
 * ---------------------------------------------------------------------------
 * HOW A DECISION IS REACHED
 * ---------------------------------------------------------------------------
 *
 * 1. A cached answer, while it is younger than `cache_ttl`.
 * 2. The licensing server.
 * 3. The signed offline token saved by the last successful call, used only when
 *    the server is unreachable, and only while its signature verifies, its key
 *    matches, the server-issued offline window is open, and every binding
 *    (domain, directory, machine, installation) still holds.
 *
 * Nothing fails open. A license that cannot be proven genuine is treated as
 * invalid. In particular, leaving `public_key` empty means offline validation
 * can never succeed, so the first network outage stops every customer's copy.
 *
 * ---------------------------------------------------------------------------
 * ERRORS
 * ---------------------------------------------------------------------------
 *
 * Licensing outcomes - including refusals - are always returned as a
 * LicenseResult. A thrown LicenseException means the SDK itself was configured
 * incorrectly and is a bug in your integration, not a customer-facing state.
 *
 * ---------------------------------------------------------------------------
 * REDISTRIBUTION
 * ---------------------------------------------------------------------------
 *
 * You may copy this file into software you license with LicenseForge, modify it
 * to suit your product, and distribute it to your own customers as part of that
 * software.
 *
 * @package LicenseForge\SDK
 * @version 1.0.0
 */

declare(strict_types=1);

namespace LicenseForge\SDK;

/**
 * The outcome of a licensing call.
 *
 * Returned by every public method that talks to the licensing server or reads
 * the offline cache. It is an immutable value object: inspect it, branch on it,
 * and discard it. A result never throws.
 *
 * The two questions you will ask most often are:
 *
 *     $result->isValid()       may this copy run?
 *     $result->getErrorCode()  if not, why not?
 *
 * Everything else - features, expiry, grace state - is detail layered on top.
 */
class LicenseResult
{
    /**
     * The full decoded server response, or the fields reconstructed from a
     * verified offline token.
     *
     * @var array<string,mixed>
     */
    protected array $data;

    /** Whether the licensed software is permitted to run. */
    protected bool $valid;

    /** Stable machine-readable refusal code, or null when valid. */
    protected ?string $errorCode;

    /** Human-readable refusal reason, or null when valid. */
    protected ?string $errorMessage;

    /** Which path produced this answer: "remote", "cache" or "offline". */
    protected string $source;

    /**
     * Build a result.
     *
     * You will not normally construct one of these yourself; the client does it
     * for you. It is public so that you can fabricate results in your own unit
     * tests, or wrap the SDK behind your own interface.
     *
     * @param bool                $valid        Whether the software may run.
     * @param array<string,mixed> $data         The decoded response payload.
     * @param string|null         $errorCode    Stable refusal code, or null.
     * @param string|null         $errorMessage Readable refusal reason, or null.
     * @param string              $source       "remote", "cache" or "offline".
     */
    public function __construct(bool $valid, array $data = [], ?string $errorCode = null, ?string $errorMessage = null, string $source = 'remote')
    {
        $this->valid        = $valid;
        $this->data         = $data;
        $this->errorCode    = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->source       = $source;
    }

    /**
     * Whether this copy of the software is permitted to run.
     *
     * This is the single question the SDK exists to answer. It is true only
     * when the license was positively proven valid - by the server, or by a
     * signed offline token that passed every check. Anything else, including an
     * unreachable server with no usable offline token, returns false.
     *
     * @return bool True when the software may run.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * The license state as a lowercase word.
     *
     * One of: "active", "expired", "suspended", "revoked" or "invalid". Useful
     * for showing the customer what is wrong; use getErrorCode() when you need
     * to branch in code.
     *
     * @return string The license status.
     */
    public function getStatus(): string
    {
        return (string) ($this->data['status'] ?? ($this->valid ? 'active' : 'invalid'));
    }

    /**
     * Why the license was refused, as a stable code, or null on success.
     *
     * Branch on this rather than on getErrorMessage(), which is prose and may
     * be reworded at any time. Codes you should expect to handle:
     *
     *     INVALID_LICENSE        the key is not recognised, or is not active
     *     LICENSE_EXPIRED        past its expiry and past any grace period
     *     ACTIVATION_NOT_FOUND   this installation has never been activated
     *     ACTIVATION_LIMIT       no free activation slots remain
     *     DOMAIN_MISMATCH        running on a domain the license is not bound to
     *     DIRECTORY_MISMATCH     running from a different install path
     *     MACHINE_MISMATCH       running on a different machine or installation
     *     PRODUCT_MISMATCH       the key belongs to a different product
     *     VERSION_NOT_SUPPORTED  this software version is not covered
     *     SIGNATURE_INVALID      the cached token could not be verified
     *     SERVICE_UNAVAILABLE    the server could not be reached, and no valid
     *                            offline token was available
     *
     * SERVICE_UNAVAILABLE says nothing about the license itself - it reports a
     * transport or offline-window problem. Treat it as "unknown", and decide
     * for your product whether that warrants a hard stop or a warning.
     *
     * @return string|null The refusal code, or null when valid.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * A human-readable explanation of the refusal, safe to display to the user.
     *
     * The wording is plain English, contains no internal details, and is
     * intended to be shown verbatim in your UI or CLI output.
     *
     * @return string|null The reason, or null when valid.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Where this answer came from.
     *
     *     "remote"   the licensing server answered this call directly
     *     "cache"    a fresh earlier answer was reused (no network call made)
     *     "offline"  the server was unreachable, so the signed offline token
     *                was verified locally instead
     *
     * Useful for diagnostics and for showing a "working offline" indicator.
     *
     * @return string One of "remote", "cache" or "offline".
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * The raw decoded response.
     *
     * Use this to read anything the typed accessors do not expose - custom
     * fields you have added on the server, subscription metadata, and so on.
     * The shape is whatever the licensing server returned, so guard your reads.
     *
     * @return array<string,mixed> The full payload.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The license record itself.
     *
     * Contains at least `key`, `status`, `product_id`, `expires_at`, `domain`
     * and `features`, though individual keys may be absent depending on how the
     * product is configured.
     *
     * @return array<string,mixed> The license record, or an empty array.
     */
    public function getLicense(): array
    {
        $license = $this->data['license'] ?? [];

        return is_array($license) ? $license : [];
    }

    /**
     * The entitlement slugs granted to this license.
     *
     * Features are the mechanism for selling tiers or add-ons from one product:
     * define slugs such as "premium-reports" or "api-access" on the server, and
     * gate the corresponding code paths on them.
     *
     * Expired features are already removed for you, both online and offline.
     *
     * @return list<string> The granted feature slugs.
     */
    public function getFeatures(): array
    {
        $license = $this->getLicense();
        $features = $license['features'] ?? [];

        return is_array($features) ? array_values(array_map('strval', $features)) : [];
    }

    /**
     * Whether a single entitlement is granted.
     *
     * @param string $slug The feature slug, exactly as configured on the server.
     *
     * @return bool True when the license grants that feature.
     */
    public function hasFeature(string $slug): bool
    {
        return in_array($slug, $this->getFeatures(), true);
    }

    /**
     * When the license expires, as the raw server-supplied date string.
     *
     * Returns null for a lifetime license. Use LicenseClient::getExpirationDate()
     * if you would rather have a DateTimeImmutable.
     *
     * @return string|null The expiry timestamp, or null if it never expires.
     */
    public function getExpiration(): ?string
    {
        $license = $this->getLicense();
        $value   = $license['expires_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Whether this installation still needs to claim an activation slot.
     *
     * True when the key itself is fine but this particular copy has never been
     * bound to it - a fresh install, or a restore onto a new server. The
     * correct response is to call LicenseClient::activate(), or to prompt the
     * customer to do so.
     *
     * @return bool True when activate() should be called.
     */
    public function needsActivation(): bool
    {
        return !empty($this->data['needs_activation'])
            || in_array($this->errorCode, ['ACTIVATION_NOT_FOUND', 'LICENSE_PENDING'], true);
    }

    /**
     * Whether the license has lapsed but is still being honoured.
     *
     * A result that is both valid and in grace means the customer's license has
     * expired and the product's configured grace period is keeping them running
     * for now. Show a renewal warning; do not block.
     *
     * @return bool True when running on grace.
     */
    public function inGracePeriod(): bool
    {
        return !empty($this->data['grace']['active']);
    }
}

/**
 * Thrown when the SDK itself is misconfigured.
 *
 * This is never used to report a licensing decision - a refused, expired or
 * unrecognised license always comes back as a LicenseResult with isValid()
 * false. If you see this exception, the fix is in your integration code (a
 * missing license key, a non-HTTPS server URL, a cache path inside the web
 * root), and it should surface during your own testing rather than in a
 * customer's error log.
 */
class LicenseException extends \RuntimeException
{
}

/**
 * The LicenseForge licensing client.
 *
 * Construct it once with your configuration, then call activate() when the
 * customer supplies their key and check() from then on. Every other method is
 * a convenience built on those two.
 *
 * ---------------------------------------------------------------------------
 * CONFIGURATION
 * ---------------------------------------------------------------------------
 *
 * Required:
 *
 *   license_key           string  The customer's key.
 *   license_server        string  Base URL of your LicenseForge API endpoint.
 *                                 Must be HTTPS (plain HTTP is allowed only for
 *                                 127.0.0.1 / localhost, for local testing).
 *
 * Strongly recommended:
 *
 *   product_id            string  Your product's slug on the server. Lets the
 *                                 SDK reject a key issued for another product.
 *   api_key               string  Your product's API key ("lfk_...").
 *   api_secret            string  Your product's API secret ("lfs_...").
 *   public_key            string  The server's offline-token public key. Without
 *                                 it, offline validation can never succeed.
 *   cache_file            string  Absolute path, outside the web root, writable.
 *   version               string  Your software's version, so the server can
 *                                 enforce version constraints on the license.
 *
 * Identity overrides (all auto-detected when left null):
 *
 *   domain                string  The hostname to report. Set this for CLI, cron
 *                                 and queue workers, which have no HTTP host.
 *   directory             string  The install path to report. Set this to a
 *                                 stable path on deploy systems that use
 *                                 timestamped release directories, otherwise
 *                                 every deploy consumes an activation slot.
 *   machine_id            string  Override the derived machine fingerprint.
 *   installation_id       string  Override the derived activation-slot id. Set
 *                                 this to keep one logical installation across a
 *                                 hostname or path change.
 *   install_secret        string  Normally left blank - activation issues one
 *                                 and stores it beside the cache. Set it only if
 *                                 you manage installation identity yourself, e.g.
 *                                 from a container secret store or image build.
 *
 * Behaviour:
 *
 *   public_key_algorithm  string  "ed25519" (default) or "rsa-sha256".
 *   cache_ttl             int     Seconds a successful answer is reused before
 *                                 the server is contacted again. Default 86400.
 *   grace_period          int     Fallback grace, in seconds, used only for
 *                                 legacy tokens that carry no server-signed
 *                                 grace deadline. Default 259200 (3 days).
 *   minimum_token_version int     Oldest offline-token format this client will
 *                                 act on. Default 3; see below. 0 disables.
 *   cache_allow_web_root  bool    Set true only if you have confirmed the cache
 *                                 directory is genuinely not served over HTTP.
 *   metadata              array   Extra key/value pairs sent with every call and
 *                                 recorded against the activation.
 *
 * Transport:
 *
 *   timeout               int     Total request timeout in seconds. Default 10.
 *   connect_timeout       int     Connection timeout in seconds. Default 5.
 *   retries               int     Extra attempts on transport failure. Default 2.
 *   retry_delay_ms        int     Base backoff between attempts. Default 300.
 *   verify_tls            bool    Verify the server certificate. Default true;
 *                                 do not turn this off in production.
 *   ca_bundle             string  Path to a custom CA bundle, if you need one.
 *   user_agent            string  Overrides the default User-Agent header.
 *
 * ---------------------------------------------------------------------------
 * A NOTE ON `minimum_token_version`
 * ---------------------------------------------------------------------------
 *
 * Offline tokens are versioned. Version 3 is the first that carries a
 * cryptographic proof binding the token to one specific installation. Older
 * tokens fall back to weaker defaults, so the client refuses them by default:
 * the cost is a single round trip, after which the server issues a current
 * token. Lower this only for a deployment that genuinely cannot reconnect -
 * an air-gapped install being upgraded offline is the case it exists for.
 *
 * ---------------------------------------------------------------------------
 * THREAD AND PROCESS SAFETY
 * ---------------------------------------------------------------------------
 *
 * The client is safe to use from several PHP workers starting simultaneously.
 * The first-activation keypair is claimed with an exclusive filesystem
 * primitive so exactly one worker's key becomes the installation key and the
 * rest adopt it; cache writes are made under an exclusive lock.
 */
class LicenseClient
{
    /** The SDK version, reported to the server in metadata and User-Agent. */
    public const VERSION = '1.0.0';

    /**
     * How far the system clock may read behind evidence this installation has
     * already seen before an offline answer is refused, in seconds.
     *
     * One day absorbs ordinary drift, a VM resuming with a stale clock, and NTP
     * stepping backwards. It does not absorb a deliberate rollback intended to
     * revive an expired offline token.
     */
    public const CLOCK_TOLERANCE = 86400;

    /**
     * The newest offline-token format this client understands.
     *
     * A token claiming a higher version is refused rather than interpreted,
     * because unknown fields would be read as "absent, so the older and looser
     * rule applies" - which would turn a tightened server policy into a relaxed
     * client one.
     */
    public const SUPPORTED_TOKEN_VERSION = 3;

    /**
     * Prefix written at the top of the cache and install-secret files.
     *
     * It makes the file inert if it is ever executed as PHP, and stops it being
     * valid JSON if it is ever served raw. Must end in a newline: the readers
     * strip exactly the first line.
     */
    private const CACHE_GUARD = "<?php /* LicenseForge cache. Not web-accessible. */ exit; ?>\n";

    /**
     * The merged configuration: the defaults below, overridden by what was
     * passed to the constructor.
     *
     * @var array<string,mixed>
     */
    protected array $config;

    /**
     * The decoded cache file, memoised for the life of this object.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $cache = null;

    /** The most recent result, so repeated accessor calls do not re-validate. */
    protected ?LicenseResult $lastResult = null;

    /** Memoised installation secret; '' means "looked for it, none stored". */
    protected ?string $installSecret = null;

    /** Memoised installation private key; '' means "looked for it, none stored". */
    protected ?string $installPrivateKey = null;

    /**
     * Create a client and validate its configuration.
     *
     * Configuration problems are raised here, immediately, so that you meet
     * them while integrating rather than in a customer's logs. See the class
     * docblock for the full list of accepted options.
     *
     * @param array<string,mixed> $config Your configuration; merged over the defaults.
     *
     * @throws LicenseException If the license key is missing, the server URL is
     *                          missing or not HTTPS, or the cache file is inside
     *                          the web root.
     */
    public function __construct(array $config)
    {
        $defaults = [
            'license_key'      => '',
            'product_id'       => '',
            'license_server'   => '',
            'api_key'          => '',
            'api_secret'       => '',
            'public_key'       => '',
            'public_key_algorithm' => 'ed25519',
            'version'          => '',
            'domain'           => null,
            'directory'        => null,
            'machine_id'       => null,
            'installation_id'  => null,
            'install_secret'   => '',
            'cache_file'       => null,
            'cache_allow_web_root' => false,
            'minimum_token_version' => 3,
            'cache_ttl'        => 86400,
            'grace_period'     => 259200,
            'timeout'          => 10,
            'connect_timeout'  => 5,
            'retries'          => 2,
            'retry_delay_ms'   => 300,
            'verify_tls'       => true,
            'ca_bundle'        => null,
            'user_agent'       => 'LicenseForge-SDK/' . self::VERSION . ' PHP/' . PHP_VERSION,
            'metadata'         => [],
        ];

        $this->config = array_merge($defaults, $config);

        if ($this->config['license_key'] === '') {
            throw new LicenseException('A license_key is required.');
        }
        if ($this->config['license_server'] === '') {
            throw new LicenseException('A license_server URL is required.');
        }
        if (strncmp((string) $this->config['license_server'], 'https://', 8) !== 0
            && strncmp((string) $this->config['license_server'], 'http://127.0.0.1', 16) !== 0
            && strncmp((string) $this->config['license_server'], 'http://localhost', 16) !== 0) {
            throw new LicenseException('The license_server URL must use HTTPS.');
        }

        $this->assertCacheIsPrivate();
    }

    /**
     * Refuse a cache path that the web server would hand out over HTTP.
     *
     * The cache holds the license key, its status and the signed offline token.
     * File permissions cannot protect it: the web server process usually *owns*
     * the file, so mode 0600 still lets it read the file and serve it happily.
     * Only the location decides whether it is reachable by URL.
     *
     * Skipped when no cache file is configured, when `cache_allow_web_root` is
     * set, or when there is no DOCUMENT_ROOT to compare against (CLI).
     *
     * @return void
     *
     * @throws LicenseException If the cache directory resolves inside DOCUMENT_ROOT.
     */
    private function assertCacheIsPrivate(): void
    {
        $file = $this->config['cache_file'];
        if (!is_string($file) || $file === '' || $this->config['cache_allow_web_root']) {
            return;
        }

        $documentRoot = self::realPath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot === '') {
            return;
        }

        // The cache file need not exist yet, so its directory is what resolves.
        $directory = self::realPath(dirname($file));
        if ($directory === '' || strpos($directory, $documentRoot) !== 0) {
            return;
        }

        throw new LicenseException(sprintf(
            'cache_file (%s) is inside the web root (%s), where it may be downloadable. '
            . 'It holds the license key and the signed offline token. Move it outside the '
            . 'document root, or set cache_allow_web_root to true if you have confirmed the '
            . 'directory is not served.',
            $file,
            $documentRoot
        ));
    }

    /**
     * Resolve a path to a canonical absolute form comparable on any platform.
     *
     * Separators are normalised to forward slashes and, on Windows only, the
     * result is lowercased, because Windows paths are case-insensitive while
     * POSIX paths are not.
     *
     * @param string $path The path to resolve. May be relative or non-existent.
     *
     * @return string The canonical path, or '' if it could not be resolved.
     */
    private static function realPath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $resolved = @realpath($path);
        if (!is_string($resolved) || $resolved === '') {
            return '';
        }
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($resolved) : $resolved;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Bind this installation to the license and claim an activation slot.
     *
     * Call this once, when the customer first enters their key. It always
     * contacts the server; the cache is never consulted.
     *
     * Calling it again from the same installation re-binds the existing slot
     * rather than consuming another, because the installation identity is
     * stable across calls. It is therefore safe to call from an installer that
     * may be re-run, or from a "re-check my license" button.
     *
     * That holds only while this installation can still prove itself. If the
     * credential files beside the cache have been lost but `installation_id`
     * resolves to the same value, the server refuses rather than re-binding -
     * ACTIVATION_NOT_FOUND, or ACTIVATION_LIMIT if that slot was the last free
     * one - because handing a live installation's record to a caller that
     * cannot prove it owns it would make `installation_id` a credential, and it
     * is not one. Recovery is a reset by the customer or an administrator.
     *
     * On success the server issues this installation a credential, which is
     * stored beside the cache file. From then on every request proves its own
     * identity, so the activation limit can be enforced. Where the platform
     * supports it, a keypair is generated locally and only the public half is
     * ever sent, so the server holds nothing capable of impersonating this
     * installation.
     *
     * @return LicenseResult The activation outcome. Check isValid().
     */
    public function activate(): LicenseResult
    {
        $environment = $this->environment();

        $registered = $this->generateInstallKey();
        if ($registered !== null) {
            $environment['install_public_key']    = $registered['key'];
            $environment['install_key_algorithm'] = $registered['algorithm'];
        }

        $result = $this->call('activate', $environment);
        $this->storeResult($result);

        return $result;
    }

    /**
     * The recurring licensing check-in. Call this on every run.
     *
     * A successful answer is reused until `cache_ttl` elapses, so in normal
     * operation this costs a single file read rather than a network round trip.
     * When the cache is stale the server is contacted; when the server is
     * unreachable the signed offline token is verified locally instead.
     *
     * This is the call you should gate your application on:
     *
     *     if (!$license->check()->isValid()) { ... }
     *
     * @param bool $force Skip the cache and always contact the server. Suitable
     *                    for an explicit "refresh license" control. Do not force
     *                    on every request - you will hit the server's rate limit.
     *
     * @return LicenseResult The licensing outcome. Check isValid().
     */
    public function check(bool $force = false): LicenseResult
    {
        if (!$force) {
            $cached = $this->freshCachedResult();
            if ($cached !== null) {
                $this->lastResult = $cached;

                return $cached;
            }
        }

        $result = $this->call('check', $this->environment());
        $this->storeResult($result);

        return $result;
    }

    /**
     * Alias for check(), for call sites that read better as "validate".
     *
     * @param bool $force Skip the cache and always contact the server.
     *
     * @return LicenseResult The licensing outcome.
     */
    public function validate(bool $force = false): LicenseResult
    {
        return $this->check($force);
    }

    /**
     * Whether an entitlement is granted, validating first if nothing is known yet.
     *
     * Convenient for one-off feature gates. If you are testing several features
     * in the same request, call check() once and use LicenseResult::hasFeature()
     * on the returned object instead.
     *
     * @param string $slug The feature slug configured on the server.
     *
     * @return bool True when the current license grants that feature.
     */
    public function hasFeature(string $slug): bool
    {
        return $this->current()->hasFeature($slug);
    }

    /**
     * Whether the license has passed its expiry date.
     *
     * Note that this reports the raw expiry only. A license that is expired but
     * still inside its grace period will return true here while check() still
     * returns a valid result - which is the intended behaviour for showing a
     * renewal notice without blocking the customer.
     *
     * @return bool True when expired. False for a lifetime license.
     */
    public function isExpired(): bool
    {
        $expiry = $this->getExpirationDate();
        if ($expiry === null) {
            return false;
        }

        return $expiry->getTimestamp() < time();
    }

    /**
     * The license expiry as a date object.
     *
     * @return \DateTimeImmutable|null The expiry, or null for a lifetime license
     *                                 or an unparseable server value.
     */
    public function getExpirationDate(): ?\DateTimeImmutable
    {
        $value = $this->current()->getExpiration();
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * The entitlement slugs on the current license, validating first if needed.
     *
     * @return list<string> The granted feature slugs.
     */
    public function getFeatures(): array
    {
        return $this->current()->getFeatures();
    }

    /**
     * The current license state, validating first if nothing is known yet.
     *
     * @return string One of "active", "expired", "suspended", "revoked", "invalid".
     */
    public function getStatus(): string
    {
        return $this->current()->getStatus();
    }

    /**
     * The most recent result, performing a validation first if none exists.
     *
     * All the convenience accessors above funnel through here, so several of
     * them in one request cost at most one validation between them.
     *
     * @return LicenseResult The current licensing outcome.
     */
    public function current(): LicenseResult
    {
        if ($this->lastResult === null) {
            $this->lastResult = $this->validate();
        }

        return $this->lastResult;
    }

    /**
     * Read the private half of this installation's registered keypair.
     *
     * Stored beside the installation secret and treated the same way: it is
     * identity, not cache, so clearCache() deliberately leaves it alone. It
     * never leaves the machine - only the public half was ever transmitted, and
     * only once, at activation.
     *
     * The result is memoised, so repeated calls do not re-read the file.
     *
     * @return string The private key (PEM for RSA, base64 for Ed25519), or ''
     *                if this installation has no registered keypair.
     */
    protected function installPrivateKey(): string
    {
        if ($this->installPrivateKey !== null) {
            return $this->installPrivateKey;
        }

        $file = $this->installSecretFile() . '.key';
        $this->installPrivateKey = '';
        if ($file !== '' && is_file($file)) {
            $raw = (string) @file_get_contents($file);
            $this->installPrivateKey = trim($raw);
        }

        return $this->installPrivateKey;
    }

    /**
     * Generate this installation's keypair and return the public half to register.
     *
     * Called once, from activate(), and only where there is somewhere durable to
     * store the private key - an installation that cannot store it would
     * register a key it could never sign with and lock itself out of its own
     * activation.
     *
     * Ed25519 is used where libsodium is available, RSA-2048 otherwise. If
     * neither is available the method returns null and the caller simply stays
     * on the shared installation secret: a registered keypair is an improvement
     * to identity, not a requirement for it.
     *
     * Concurrency: several PHP workers may activate at the same moment. The
     * private key is written to a unique temporary file and then *claimed* into
     * its final path with a primitive that fails if the path already exists, so
     * exactly one worker's key becomes the installation key. Every other worker
     * adopts the winner's key and registers that instead. Without this, two
     * workers could each register their own public key, leaving the server
     * holding one and the surviving private half being the other - an
     * installation that can never prove itself again.
     *
     * @return array{key:string,algorithm:string}|null The public key and its
     *         algorithm to send to the server, or null if no keypair could be
     *         created or stored.
     */
    protected function generateInstallKey(): ?array
    {
        $file = $this->installSecretFile();
        if ($file === '' || $this->installPrivateKey() !== '') {
            return null;
        }

        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair    = sodium_crypto_sign_keypair();
            $private = base64_encode(sodium_crypto_sign_secretkey($pair));
            $public  = base64_encode(sodium_crypto_sign_publickey($pair));
            $algorithm = 'ed25519';
        } elseif (function_exists('openssl_pkey_new')) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($resource === false) {
                return null;
            }
            $private = '';
            if (!openssl_pkey_export($resource, $private)) {
                return null;
            }
            $details = openssl_pkey_get_details($resource);
            if (!is_array($details) || !isset($details['key'])) {
                return null;
            }
            $public    = (string) $details['key'];
            $algorithm = 'rsa-sha256';
        } else {
            return null;
        }

        $path = $file . '.key';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::protectDirectory($dir);

        // Write to a private temporary name first, so the final path never
        // exists in a half-written state for another worker to read.
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        // The temporary name is unique to this worker, so a failure here means
        // the directory is not writable - not that another worker won.
        $handle = @fopen($temp, 'x');
        if ($handle === false) {
            return null;
        }

        $written = fwrite($handle, $private) !== false;
        $flushed = $written && fflush($handle);
        fclose($handle);

        if (!$written || !$flushed) {
            @unlink($temp);

            return null;
        }

        @chmod($temp, 0600);

        // Fast path: another worker already finished the whole sequence and may
        // already have registered its key, so it wins and this one is discarded.
        if (is_file($path)) {
            return $this->adoptInstallKey($path, $temp);
        }

        // The claim is what actually decides the winner, and it also catches a
        // worker that finished between the check above and this line.
        if (!self::claimPath($temp, $path)) {
            return $this->adoptInstallKey($path, $temp);
        }

        $this->installPrivateKey = $private;

        return ['key' => $public, 'algorithm' => $algorithm];
    }

    /**
     * Adopt the key another worker placed on disk, discarding this worker's own.
     *
     * Reached whenever this worker lost the first-activation race. The key
     * already on disk is the one the winner is registering with the server, so
     * signing with it is the only correct move - registering the key this
     * worker generated would leave the server holding a public half whose
     * private half exists nowhere.
     *
     * @param string $path The final installation key path, written by the winner.
     * @param string $temp This worker's discarded temporary key file, deleted here.
     *
     * @return array{key:string,algorithm:string}|null The winner's public key
     *         and algorithm, or null if it could not be read or parsed.
     */
    protected function adoptInstallKey(string $path, string $temp): ?array
    {
        @unlink($temp);

        $winner = trim((string) @file_get_contents($path));
        if ($winner === '') {
            return null;
        }

        $this->installPrivateKey = $winner;

        return self::publicKeyFromPrivate($winner);
    }

    /**
     * Move a temporary file into its final path, but only if nothing is there yet.
     *
     * Exclusivity is the requirement here, not merely atomicity. rename() is
     * atomic and still wrong: it overwrites, so every racing worker "succeeds"
     * and the last to land owns the disk while the last to register owns the
     * server - two independent orderings, and therefore a split identity.
     *
     * On POSIX, link() provides exactly what is needed: it fails outright if
     * the destination exists, and the linked content is visible whole or not at
     * all. The temporary name is then unlinked, leaving one entry.
     *
     * Where link() is unavailable or unsupported (Windows, and filesystems that
     * refuse hard links) the fallback holds an exclusive lock across the check
     * and the rename, reaching the same decision by serialising the workers
     * instead of racing them. flock() is advisory, but every writer goes
     * through this one function, so there is no unlocked writer to miss.
     * Readers take no lock and need none, since the rename is still atomic.
     *
     * @param string $temp Path to the fully written temporary file.
     * @param string $path The destination path to claim.
     *
     * @return bool True only when this caller placed the file.
     */
    protected static function claimPath(string $temp, string $path): bool
    {
        if (function_exists('link') && @link($temp, $path)) {
            @unlink($temp);

            return true;
        }

        $lockFile = $path . '.lock';
        $lock     = @fopen($lockFile, 'c');
        if ($lock === false) {
            return false;
        }

        $claimed = false;
        if (@flock($lock, LOCK_EX)) {
            // Re-checked under the lock: the winner may have placed the file
            // between this worker's earlier check and acquiring the lock.
            if (!is_file($path)) {
                $claimed = @rename($temp, $path);
            }
            @flock($lock, LOCK_UN);
        }

        fclose($lock);

        return $claimed;
    }

    /**
     * Derive the public half from a private key already on disk.
     *
     * Needed only by the loser of a first-activation race, which must register
     * the key that actually reached disk rather than the one it generated - and
     * all it holds at that point is the winner's private half.
     *
     * The key format is detected from its content: a PEM header means RSA,
     * anything else is treated as base64 Ed25519.
     *
     * @param string $privateKey The private key as stored on disk.
     *
     * @return array{key:string,algorithm:string}|null The public key and its
     *         algorithm, or null if the key was malformed or the required
     *         extension is unavailable.
     */
    protected static function publicKeyFromPrivate(string $privateKey): ?array
    {
        if (strpos($privateKey, '-----BEGIN') === 0) {
            if (!function_exists('openssl_pkey_get_private')) {
                return null;
            }
            $resource = openssl_pkey_get_private($privateKey);
            if ($resource === false) {
                return null;
            }
            $details = openssl_pkey_get_details($resource);
            if (!is_array($details) || !isset($details['key'])) {
                return null;
            }

            return ['key' => (string) $details['key'], 'algorithm' => 'rsa-sha256'];
        }

        if (!function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            return null;
        }
        $raw = base64_decode($privateKey, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return [
            'key'       => base64_encode(sodium_crypto_sign_publickey_from_secretkey($raw)),
            'algorithm' => 'ed25519',
        ];
    }

    /**
     * Sign a canonical string with this installation's private key.
     *
     * The result is base64url rather than hex: a 2048-bit RSA signature is 512
     * hex characters, and base64url keeps the HTTP header a reasonable size.
     * The server accepts either encoding, since HMAC proofs remain hex.
     *
     * @param string $canonical  The exact string to sign.
     * @param string $privateKey PEM (RSA) or base64 (Ed25519) private key.
     *
     * @return string|null The base64url signature, or null if signing was not
     *                     possible with the available extensions.
     */
    protected static function signWithInstallKey(string $canonical, string $privateKey): ?string
    {
        if (strpos($privateKey, '-----BEGIN') === 0) {
            if (!function_exists('openssl_sign')) {
                return null;
            }
            $resource = openssl_pkey_get_private($privateKey);
            $signature = '';
            if ($resource === false || !openssl_sign($canonical, $signature, $resource, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            return self::base64UrlEncodeStatic($signature);
        }

        if (!function_exists('sodium_crypto_sign_detached')) {
            return null;
        }
        $raw = base64_decode($privateKey, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        return self::base64UrlEncodeStatic(sodium_crypto_sign_detached($canonical, $raw));
    }

    /**
     * Encode raw bytes as unpadded base64url.
     *
     * @param string $raw The bytes to encode.
     *
     * @return string The base64url representation, without '=' padding.
     */
    private static function base64UrlEncodeStatic(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Verify a signature against a public key.
     *
     * Used only against this installation's *own* registered key, to confirm it
     * still holds the matching private half. It is never used to decide whether
     * a payload is genuine - that is the offline token signature's job, handled
     * by verifyOfflineToken().
     *
     * @param string $message   The signed message.
     * @param string $signature The raw signature bytes.
     * @param string $publicKey PEM (RSA) or base64 (Ed25519) public key.
     * @param string $algorithm "ed25519" or "rsa-sha256".
     *
     * @return bool True when the signature verifies.
     */
    protected static function verifyWithInstallKey(
        string $message,
        string $signature,
        string $publicKey,
        string $algorithm
    ): bool {
        if ($signature === '' || $publicKey === '') {
            return false;
        }

        if ($algorithm === 'ed25519') {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return false;
            }
            $raw = base64_decode($publicKey, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signature, $message, $raw);
        }

        if (!function_exists('openssl_verify')) {
            return false;
        }
        $resource = openssl_pkey_get_public($publicKey);

        return $resource !== false
            && openssl_verify($message, $signature, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The per-installation secret issued at activation.
     *
     * If `install_secret` is set in the configuration it wins, which lets you
     * manage installation identity yourself. Otherwise the secret is read from
     * its own file beside the cache.
     *
     * It is stored separately from the result cache because the two mean
     * different things: the cache is a disposable answer, this is identity.
     * clearCache() must be able to force a fresh check without de-activating
     * the installation and costing the customer a slot.
     *
     * @return string The secret, or '' if this installation is not activated.
     */
    protected function installSecret(): string
    {
        if (is_string($this->config['install_secret']) && $this->config['install_secret'] !== '') {
            return $this->config['install_secret'];
        }
        if ($this->installSecret !== null) {
            return $this->installSecret;
        }

        $file = $this->installSecretFile();
        $this->installSecret = '';
        if ($file !== '' && is_file($file)) {
            $raw = (string) @file_get_contents($file);
            // Same PHP guard as the cache file: strip the first line if present.
            if (strncmp($raw, '<?php', 5) === 0) {
                $end = strpos($raw, "\n");
                $raw = $end === false ? '' : substr($raw, $end + 1);
            }
            $this->installSecret = trim($raw);
        }

        return $this->installSecret;
    }

    /**
     * Persist the installation secret returned by a successful activation.
     *
     * Creates the containing directory with restrictive permissions if needed,
     * drops deny-all web-server rules into it, and writes the file 0600 behind
     * an exclusive lock. Silently does nothing if no cache path is configured
     * or the secret is empty.
     *
     * @param string $secret The secret issued by the server.
     *
     * @return void
     */
    protected function storeInstallSecret(string $secret): void
    {
        $file = $this->installSecretFile();
        if ($file === '' || $secret === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::protectDirectory($dir);

        $this->installSecret = $secret;
        @file_put_contents($file, self::CACHE_GUARD . $secret, LOCK_EX);
        @chmod($file, 0600);
    }

    /**
     * The path of the installation secret file, derived from the cache path.
     *
     * The private key file is this path with ".key" appended.
     *
     * @return string The absolute path, or '' if no cache file is configured.
     */
    protected function installSecretFile(): string
    {
        $cache = $this->config['cache_file'];

        return is_string($cache) && $cache !== '' ? $cache . '.install' : '';
    }

    /**
     * Discard the cached licensing answer and force the next call to go online.
     *
     * Call this when the customer enters a different license key, or from a
     * "refresh license" control.
     *
     * This deliberately does NOT remove the installation secret or private key.
     * Those are identity, not cache: deleting them would make the next check-in
     * look like a brand-new installation and consume another activation slot.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = null;
        $file = $this->config['cache_file'];
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }

    // =========================================================================
    // Transport
    // =========================================================================

    /**
     * Perform an API call, with retries, and fall back offline if it fails.
     *
     * Transport failures and 5xx responses are retried with a linear backoff;
     * a licensing refusal is never retried, because the answer will not change.
     * When every attempt fails, the signed offline token is used instead.
     *
     * A successful response that also reports `needs_activation` is deliberately
     * converted into an *invalid* result. The key may be genuine, but an
     * installation that has not claimed a slot is not a licensed installation,
     * and `if ($license->check()->isValid())` is the code integrators actually
     * write - it must not run unactivated copies.
     *
     * @param string              $endpoint The API endpoint: "activate" or "check".
     * @param array<string,mixed> $payload  The request body, before encoding.
     *
     * @return LicenseResult The server's decision, or the offline fallback.
     */
    protected function call(string $endpoint, array $payload): LicenseResult
    {
        $body     = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $attempts = max(1, (int) $this->config['retries'] + 1);
        $lastError = 'The licensing server could not be reached.';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->send($endpoint, (string) $body);

            if ($response['error'] !== null) {
                $lastError = $response['error'];
                if ($attempt < $attempts) {
                    usleep(max(0, (int) $this->config['retry_delay_ms']) * 1000 * $attempt);
                    continue;
                }
                break;
            }

            $decoded = json_decode((string) $response['body'], true);
            if (!is_array($decoded)) {
                $lastError = 'The licensing server returned an unreadable response.';
                if ($attempt < $attempts) {
                    continue;
                }
                break;
            }

            if (!empty($decoded['success'])) {
                if (!empty($decoded['needs_activation'])) {
                    return new LicenseResult(
                        false,
                        $decoded,
                        'ACTIVATION_NOT_FOUND',
                        'This installation is not activated for this license. Call activate() first.',
                        'remote'
                    );
                }

                return new LicenseResult(true, $decoded, null, null, 'remote');
            }

            if ($response['status'] >= 500 && $attempt < $attempts) {
                $lastError = 'The licensing server is temporarily unavailable.';
                continue;
            }

            return new LicenseResult(
                false,
                $decoded,
                (string) ($decoded['error']['code'] ?? 'INVALID_REQUEST'),
                (string) ($decoded['error']['message'] ?? 'The license could not be verified.'),
                'remote'
            );
        }

        return $this->offlineFallback($lastError);
    }

    /**
     * Send one signed HTTP request to the licensing server.
     *
     * Two independent proofs travel with every request:
     *
     *  - The *credential* signature (X-LF-Signature) proves the request came
     *    from your product, using the API key and secret you configured.
     *  - The *installation* proof (X-LF-Install-Proof) proves the request came
     *    from this specific installation. Where a keypair was registered at
     *    activation it is a signature over the canonical string; otherwise it
     *    is an HMAC keyed with the installation secret. Neither credential is
     *    ever transmitted. Without this proof the server treats the caller as
     *    an installation it has not seen, which is what makes the activation
     *    limit meaningful.
     *
     * Both signatures cover the same canonical string - method, endpoint,
     * timestamp, single-use nonce and a SHA-256 of the body - so the timestamp
     * and nonce headers are always sent, and both proofs inherit replay
     * protection from them.
     *
     * A registered keypair supersedes the shared secret rather than falling
     * back to it: once a key exists the server checks the signature first, so
     * presenting the superseded secret after a signing failure would simply be
     * refused.
     *
     * @param string $endpoint The API endpoint path segment.
     * @param string $body     The already-encoded JSON request body.
     *
     * @return array{status:int,body:?string,error:?string} The HTTP status, the
     *         raw response body, and a transport error message (null on success).
     */
    protected function send(string $endpoint, string $body): array
    {
        $url       = rtrim((string) $this->config['license_server'], '/') . '/license/' . $endpoint;
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $headers[] = 'X-LF-Timestamp: ' . $timestamp;
        $headers[] = 'X-LF-Nonce: ' . $nonce;

        if ($this->config['api_key'] !== '' && $this->config['api_secret'] !== '') {
            $signature = $this->sign('POST', $endpoint, $timestamp, $nonce, $body);
            $headers[] = 'X-LF-Key: ' . $this->config['api_key'];
            $headers[] = 'X-LF-Signature: ' . $signature;
        }

        $canonical = implode("\n", [
            'POST',
            strtolower($endpoint),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $signed     = null;
        $privateKey = $this->installPrivateKey();
        if ($privateKey !== '') {
            $signed = self::signWithInstallKey($canonical, $privateKey);
        }

        if ($signed !== null) {
            $headers[] = 'X-LF-Install-Proof: ' . $signed;
        } else {
            $installSecret = $this->installSecret();
            if ($installSecret !== '') {
                $headers[] = 'X-LF-Install-Proof: ' . hash_hmac('sha256', $canonical, $installSecret);
            }
        }

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'The cURL extension is required.'];
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER => (bool) $this->config['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_tls'] ? 2 : 0,
            CURLOPT_USERAGENT      => (string) $this->config['user_agent'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        ]);

        if (is_string($this->config['ca_bundle']) && $this->config['ca_bundle'] !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $this->config['ca_bundle']);
        }

        $responseBody = curl_exec($handle);
        $status       = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error        = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        // curl_close() is a no-op from PHP 8.0 and deprecated from 8.5; the
        // handle is released when $handle goes out of scope.
        unset($handle);

        if ($responseBody === false) {
            return ['status' => $status, 'body' => null, 'error' => $error ?? 'Request failed.'];
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'error' => null];
    }

    /**
     * Compute the API credential signature for a request.
     *
     * The canonical string is a newline-joined tuple of the uppercased method,
     * the lowercased endpoint, the timestamp, the nonce and the hex SHA-256 of
     * the body. This mirrors the server implementation exactly; if you port
     * this SDK to another language, this is the function to match first.
     *
     * @param string $method    The HTTP method.
     * @param string $endpoint  The endpoint path segment.
     * @param string $timestamp Unix seconds, as a string.
     * @param string $nonce     A single-use random hex value.
     * @param string $body      The exact request body being sent.
     *
     * @return string The hex HMAC-SHA256 signature.
     */
    protected function sign(string $method, string $endpoint, string $timestamp, string $nonce, string $body): string
    {
        $canonical = implode("\n", [
            strtoupper($method),
            strtolower($endpoint),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $canonical, (string) $this->config['api_secret']);
    }

    // =========================================================================
    // Offline validation
    // =========================================================================

    /**
     * Fall back to the signed offline token when the server cannot be reached.
     *
     * @param string $reason The transport error, appended to the message shown
     *                       to the customer when the fallback also fails.
     *
     * @return LicenseResult The offline decision.
     */
    protected function offlineFallback(string $reason): LicenseResult
    {
        return $this->resultFromSignedCache('offline', $reason);
    }

    /**
     * Detect whether the system clock has been wound back to revive a dead token.
     *
     * Every offline deadline is read against a clock on hardware the customer
     * owns, so an expired token can be made current again simply by setting the
     * date back. This cannot be *solved* on a machine its owner controls -
     * there is no trustworthy time source to appeal to - but it can be made
     * expensive and evident, in two layers.
     *
     * The first layer is free and unforgeable: `issued_at` sits inside the
     * signature, so it cannot be lowered without invalidating the token, and a
     * newer token can only come from the server. A clock reading earlier than
     * the moment the token was minted is therefore lying, with no legitimate
     * explanation beyond ordinary skew.
     *
     * That alone is not enough, since winding back to a point *after* issue but
     * before `offline_until` revives the token while satisfying the first
     * check. So the second layer keeps a high-water mark of the latest time
     * this installation has ever seen - signed `issued_at` values and the local
     * clock at each successful check - and refuses when the clock reads
     * meaningfully behind it.
     *
     * The mark lives in the cache file, which the customer can also edit. That
     * is a deliberate trade rather than an oversight: the mark sits beside the
     * token it guards, so deleting the file to clear the tripwire discards the
     * token too, and the installation must reach the server - which is exactly
     * the outcome the check exists to force. Editing the mark down in place is
     * the remaining hole, and no amount of local storage closes it. Treat this
     * as raising cost, not as a control.
     *
     * @param array<string,mixed> $payload The verified offline token payload.
     *
     * @return string|null A reason to refuse, or null when the clock is credible.
     */
    protected function clockRollback(array $payload): ?string
    {
        $now = time();

        $issuedAt = isset($payload['issued_at']) ? strtotime((string) $payload['issued_at']) : false;
        if ($issuedAt !== false && $now < $issuedAt - self::CLOCK_TOLERANCE) {
            return 'The system clock is set earlier than this license was issued. '
                . 'Correct the clock, or connect to the server to re-check.';
        }

        $cache = $this->readCache();
        $mark  = isset($cache['seen_at']) ? (int) $cache['seen_at'] : 0;
        if ($mark > 0 && $now < $mark - self::CLOCK_TOLERANCE) {
            return 'The system clock has moved backwards since this license was last checked. '
                . 'Correct the clock, or connect to the server to re-check.';
        }

        return null;
    }

    /**
     * Compute the latest moment this installation has evidence of.
     *
     * Written to the cache on every successful check and consumed by
     * clockRollback(). It never moves backwards, and it prefers the signed
     * `issued_at` over the local clock where that is ahead: the server's word
     * about the time is worth more than the customer's machine's, and it is the
     * half an attacker cannot forge.
     *
     * @param array<string,mixed>|null $payload The verified token payload, if any.
     *
     * @return int The new high-water mark, in Unix seconds.
     */
    protected function highWaterMark(?array $payload): int
    {
        $cache = $this->readCache();
        $mark  = isset($cache['seen_at']) ? (int) $cache['seen_at'] : 0;
        $mark  = max($mark, time());

        if ($payload !== null && isset($payload['issued_at'])) {
            $issuedAt = strtotime((string) $payload['issued_at']);
            if ($issuedAt !== false) {
                $mark = max($mark, $issuedAt);
            }
        }

        return $mark;
    }

    /**
     * Build a licensing result from the signed token held in the cache.
     *
     * This is the heart of offline validation, and both cache-reading paths
     * (a fresh cache hit, and the offline fallback) funnel through it so that
     * neither can drift into trusting unsigned data.
     *
     * The cache file lives on a machine its owner controls, so nothing read out
     * of it may be believed on its own - only the token can be, because only
     * the token is signed by a key the customer does not hold. Every field in
     * the returned result therefore comes from the verified `$payload`, never
     * from the surrounding cache entry. Editing `license` or `status` in the
     * file changes nothing; editing the token invalidates it.
     *
     * The checks applied here, in order:
     *
     *   1. A token exists and its signature verifies against `public_key`.
     *   2. It was issued for the configured license key.
     *   3. Its format version is within [minimum_token_version, SUPPORTED_TOKEN_VERSION].
     *   4. The system clock has not been wound back (see clockRollback()).
     *   5. The server-issued offline window has not closed.
     *   6. The license has not expired past its server-signed grace deadline.
     *   7. Its status is "active".
     *   8. It is not IP-locked - an IP binding can only be checked by the server.
     *   9. Product, version constraints, domain, directory and machine bindings
     *      all still hold.
     *  10. It is bound to this exact installation, and this installation can
     *      cryptographically prove it owns that binding.
     *
     * Everything the server would have checked online is re-checked here. An
     * offline path that enforces less than the online one is not a fallback, it
     * is a bypass: unplug the network and the weaker rules apply.
     *
     * @param string $source The source label for the result: "cache" or "offline".
     * @param string $reason The transport failure, appended to messages where
     *                       the customer needs to know the server was unreachable.
     *
     * @return LicenseResult The offline decision.
     */
    protected function resultFromSignedCache(string $source, string $reason = ''): LicenseResult
    {
        $cache = $this->readCache();
        if ($cache === null || empty($cache['offline_token'])) {
            return new LicenseResult(false, [], 'SERVICE_UNAVAILABLE', $reason, $source);
        }

        $payload = $this->verifyOfflineToken((string) $cache['offline_token']);
        if ($payload === null) {
            return new LicenseResult(false, [], 'SIGNATURE_INVALID', 'The cached license could not be verified.', $source);
        }

        if (!hash_equals((string) ($payload['license_key'] ?? ''), (string) $this->config['license_key'])) {
            return new LicenseResult(false, [], 'INVALID_LICENSE', 'The cached license belongs to a different key.', $source);
        }

        // Version floor. Each "field missing, so assume the old default" branch
        // further down is a place where an old token would quietly enforce the
        // policy in force when it was signed rather than the current one.
        // Refusing costs one round trip and yields a current token.
        $version = (int) ($payload['token_version'] ?? 1);
        $minimum = (int) $this->config['minimum_token_version'];
        if ($minimum > 0 && $version < $minimum) {
            return new LicenseResult(false, [], 'SERVICE_UNAVAILABLE',
                'This cached license predates the current licensing rules. Reconnect to refresh it.', $source);
        }

        // Version ceiling. A newer token carries semantics this build has never
        // seen; acting on it means guessing, and the guess that matters is
        // always "absent field, so the older rule applies".
        if ($version > self::SUPPORTED_TOKEN_VERSION) {
            return new LicenseResult(false, [], 'SERVICE_UNAVAILABLE',
                'This license was issued under newer rules than this software understands. '
                . 'Update the software, or reconnect for a compatible response.', $source);
        }

        // Every deadline below is read against a clock the customer owns, so
        // the clock is checked before any of them are trusted.
        $clock = $this->clockRollback($payload);
        if ($clock !== null) {
            return new LicenseResult(false, [], 'SERVICE_UNAVAILABLE', $clock, $source);
        }

        $offlineUntil = isset($payload['offline_until']) ? strtotime((string) $payload['offline_until']) : false;
        if ($offlineUntil === false || $offlineUntil < time()) {
            return new LicenseResult(false, [], 'SERVICE_UNAVAILABLE', trim('The offline validity period has ended. ' . $reason), $source);
        }

        // Expiry is measured against the boundary the *server* signed. The
        // local `grace_period` setting is only a fallback for tokens issued
        // before the server published grace_ends_at: how long an expired
        // license keeps working is licensing policy, and a local default would
        // grant grace to a product configured for none.
        if (isset($payload['grace_ends_at']) && $payload['grace_ends_at'] !== null) {
            $graceEnd = strtotime((string) $payload['grace_ends_at']);
            if ($graceEnd !== false && $graceEnd < time()) {
                return new LicenseResult(false, [], 'LICENSE_EXPIRED', 'The license expired.', $source);
            }
        } else {
            $expiresAt = isset($payload['expires_at']) && $payload['expires_at'] !== null
                ? strtotime((string) $payload['expires_at'])
                : null;
            if ($expiresAt !== null && $expiresAt !== false && $expiresAt < (time() - (int) $this->config['grace_period'])) {
                return new LicenseResult(false, [], 'LICENSE_EXPIRED', 'The license expired.', $source);
            }
        }

        if (($payload['status'] ?? '') !== 'active') {
            return new LicenseResult(false, [], 'INVALID_LICENSE', 'The license is not active.', $source);
        }

        // IP is the one binding that cannot be verified here: the token carries
        // the address the *server* observed, and behind NAT, a proxy or CGNAT
        // the client sees something different, so comparing locally would
        // refuse correct installations. Rather than silently skip it, an
        // IP-locked license is denied an offline answer altogether.
        if (!empty($payload['lock_ip'])) {
            return new LicenseResult(
                false,
                [],
                'SERVICE_UNAVAILABLE',
                trim('This license is bound to an IP address, which can only be verified by the licensing server. ' . $reason),
                $source
            );
        }

        $boundProduct   = (string) ($payload['product_id'] ?? '');
        $currentProduct = (string) $this->config['product_id'];
        if ($boundProduct !== '' && $currentProduct !== '' && !hash_equals($boundProduct, $currentProduct)) {
            return new LicenseResult(false, [], 'PRODUCT_MISMATCH', 'The cached license is for another product.', $source);
        }

        $versionProblem = $this->versionProblem(
            (string) $this->config['version'],
            $payload['min_version'] ?? null,
            $payload['max_version'] ?? null,
            $payload['allowed_versions'] ?? null
        );
        if ($versionProblem !== null) {
            return new LicenseResult(false, [], 'VERSION_NOT_SUPPORTED', 'This version is not covered: ' . $versionProblem, $source);
        }

        // Domain binding, re-checked locally so a cached payload cannot be
        // copied to another site. Subdomain and www tolerance are whatever the
        // server signed, so the offline answer matches the online one. An
        // absent flag means a token issued before that policy was signed, and
        // the permissive default keeps those working rather than refusing them.
        $stripWww = !array_key_exists('allow_www_normalisation', $payload)
            || !empty($payload['allow_www_normalisation']);

        $boundDomain = (string) ($payload['domain'] ?? '');
        $currentDomain = $this->detectDomain();
        if (!empty($payload['lock_domain']) && $currentDomain !== '') {
            if (array_key_exists('allow_local_domains', $payload)
                && empty($payload['allow_local_domains'])
                && $this->isLocalDomain($currentDomain)) {
                return new LicenseResult(false, [], 'DOMAIN_MISMATCH', 'Development and local domains are not permitted for this license.', $source);
            }

            if ($boundDomain !== ''
                && !$this->domainMatches($boundDomain, $currentDomain, !empty($payload['allow_subdomains']), $stripWww)) {
                return new LicenseResult(false, [], 'DOMAIN_MISMATCH', 'The cached license is bound to another domain.', $source);
            }
        }

        // Install path, compared explicitly rather than left to whatever each
        // SDK happens to fold into its machine identifier.
        $boundDirectory = (string) ($payload['directory'] ?? '');
        if (!empty($payload['lock_directory']) && $boundDirectory !== ''
            && !hash_equals($this->normalisePath($boundDirectory), $this->normalisePath($this->detectDirectory()))) {
            return new LicenseResult(false, [], 'DIRECTORY_MISMATCH', 'The cached license is bound to another directory.', $source);
        }

        // Machine binding, so a token cannot be moved to a different host that
        // happens to serve the same domain.
        $boundMachine = (string) ($payload['machine_id'] ?? '');
        if (!empty($payload['lock_machine']) && $boundMachine !== '' && !hash_equals($boundMachine, $this->machineId())) {
            return new LicenseResult(false, [], 'MACHINE_MISMATCH', 'The cached license is bound to another machine.', $source);
        }

        // The activation slot itself is checked always, not as a lockable
        // policy: it is the token's identity, so a payload issued for a
        // different installation is never usable here whatever the locks say.
        // A payload with no installation at all was issued to an unbound
        // caller, which is not a licensed installation.
        $boundInstallation = (string) ($payload['installation_id'] ?? '');
        if ($boundInstallation === '') {
            return new LicenseResult(false, [], 'ACTIVATION_NOT_FOUND', 'The cached license is not bound to an activation.', $source);
        }
        if (!hash_equals($boundInstallation, $this->installationId())) {
            return new LicenseResult(false, [], 'MACHINE_MISMATCH', 'The cached license is bound to another installation.', $source);
        }

        // Matching the installation id proves nothing on its own: the id is a
        // value this client derived and keeps in a file, so copying the cache
        // and that file to a second server would reproduce a "licensed"
        // installation that had never proved anything. Online, an installation
        // is recognised by a proof computed from the credential issued at
        // activation; the checks below are that same evidence, offline.
        //
        // An installation holding a registered keypair proves possession by
        // signing the token's own nonce and verifying the result against the
        // public key the server signed into the token. That is exactly as
        // strong offline as the HMAC form (this machine is the verifier either
        // way) while leaving the server nothing that could forge the binding.
        $bindingKey = (string) ($payload['installation_key'] ?? '');
        $binding    = (string) ($payload['installation_binding'] ?? '');

        // Assert what token version 3 means, rather than assuming it.
        //
        // The two checks below each fire only when their own field is present,
        // so together they say "verify whichever binding you were given" - not
        // "a binding was given". The server always mints exactly one, and the
        // default version floor rejects the formats predating that guarantee.
        // But that leaves the invariant living in the floor rather than in the
        // version: lowering the floor is a deliberate choice to accept older
        // *known* shapes, and it should not also make a malformed v3 token
        // acceptable. Exactly one binding, never both, never neither.
        if ($version >= 3 && ($bindingKey === '') === ($binding === '')) {
            return new LicenseResult(false, [], 'ACTIVATION_NOT_FOUND',
                'This cached license is missing its installation binding. Reconnect to refresh it.', $source);
        }

        if ($bindingKey !== '') {
            $privateKey = $this->installPrivateKey();
            if ($privateKey === '') {
                return new LicenseResult(false, [], 'ACTIVATION_NOT_FOUND',
                    'This installation cannot prove it owns the cached license. Reconnect to activate.', $source);
            }

            $nonce  = (string) ($payload['nonce'] ?? '');
            $signed = self::signWithInstallKey($nonce, $privateKey);
            $algorithm = (string) ($payload['installation_key_algorithm'] ?? 'ed25519');

            if ($signed === null
                || !self::verifyWithInstallKey($nonce, $this->base64UrlDecode($signed), $bindingKey, $algorithm)) {
                return new LicenseResult(false, [], 'MACHINE_MISMATCH',
                    'The cached license belongs to another installation.', $source);
            }
        }

        if ($bindingKey === '' && $binding !== '') {
            $secret = $this->installSecret();
            if ($secret === '') {
                return new LicenseResult(false, [], 'ACTIVATION_NOT_FOUND',
                    'This installation cannot prove it owns the cached license. Reconnect to activate.', $source);
            }
            $expected = hash_hmac('sha256', (string) ($payload['nonce'] ?? ''), $secret);
            if (!hash_equals($binding, $expected)) {
                return new LicenseResult(false, [], 'MACHINE_MISMATCH',
                    'The cached license belongs to another installation.', $source);
            }
        }

        return new LicenseResult(true, [
            'status'  => 'active',
            'license' => [
                'key'        => (string) $payload['license_key'],
                'status'     => 'active',
                'product_id' => (string) ($payload['product_id'] ?? ''),
                'expires_at' => $payload['expires_at'] ?? null,
                'domain'     => $payload['domain'] ?? null,
                'features'   => self::liveFeatures($payload),
            ],
            'offline_until' => $payload['offline_until'] ?? null,
        ], null, null, $source);
    }

    /**
     * Filter a token's feature list down to those that have not themselves expired.
     *
     * A feature can expire before the license does. The server drops an already
     * lapsed feature when it mints a token, but a token minted while a feature
     * was still live carries it for the token's entire offline window - so
     * without the signed per-feature dates, a feature would keep working for
     * days after the server stopped granting it.
     *
     * Tokens with no `feature_expiry` map are read as "nothing expires", which
     * is what the flat feature list meant before the map existed.
     *
     * @param array<string,mixed> $payload The verified token payload.
     *
     * @return list<string> The still-live feature slugs.
     */
    protected static function liveFeatures(array $payload): array
    {
        $features = $payload['features'] ?? [];
        if (!is_array($features)) {
            return [];
        }

        $expiry = isset($payload['feature_expiry']) && is_array($payload['feature_expiry'])
            ? $payload['feature_expiry']
            : [];

        $now  = time();
        $live = [];
        foreach ($features as $slug) {
            $slug = (string) $slug;
            $ends = $expiry[$slug] ?? null;
            if ($ends !== null && $ends !== '') {
                $at = strtotime((string) $ends);
                if ($at !== false && $at < $now) {
                    continue;
                }
            }
            $live[] = $slug;
        }

        return $live;
    }

    /**
     * Verify a signed offline token and return its payload.
     *
     * The token format is `base64url(payload_json).base64url(signature)`, and
     * the signature covers the *encoded* payload segment, so re-encoding is
     * never required and cannot introduce a mismatch.
     *
     * This method is public so that you can verify a token you have obtained by
     * other means - for example one pasted in by a customer performing a manual
     * air-gapped activation.
     *
     * If `public_key` is not configured, this always returns null. That is
     * deliberate: with no key there is no way to tell a genuine token from a
     * forged one, and returning the payload unverified would make the whole
     * offline path trivially bypassable.
     *
     * @param string $token The token in `payload.signature` form.
     *
     * @return array<string,mixed>|null The decoded payload if the signature is
     *                                  genuine, or null otherwise.
     */
    public function verifyOfflineToken(string $token): ?array
    {
        $publicKey = (string) $this->config['public_key'];
        if ($publicKey === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $signatureEncoded] = $parts;

        $payloadJson = $this->base64UrlDecode($encoded);
        $signature   = $this->base64UrlDecode($signatureEncoded);
        $payload     = json_decode($payloadJson, true);
        if (!is_array($payload) || $signature === '') {
            return null;
        }

        $algorithm = (string) ($payload['_algorithm'] ?? $this->config['public_key_algorithm']);

        if ($algorithm === 'ed25519') {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return null;
            }
            $raw = base64_decode($publicKey, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            $ok = sodium_crypto_sign_verify_detached($signature, $encoded, $raw);
        } else {
            $resource = openssl_pkey_get_public($publicKey);
            if ($resource === false) {
                return null;
            }
            $ok = openssl_verify($encoded, $signature, $resource, OPENSSL_ALGO_SHA256) === 1;
        }

        return $ok ? $payload : null;
    }

    // =========================================================================
    // Cache
    // =========================================================================

    /**
     * Record the outcome of a server call.
     *
     * The installation secret, which is returned exactly once by activate(), is
     * persisted before anything else can fail. Losing it would make the next
     * check-in look like a new installation and spend another activation slot.
     *
     * Only a valid result updates the cache, and the fresh offline token is
     * carried over from the previous cache entry if this response did not
     * include one.
     *
     * @param LicenseResult $result The result to record.
     *
     * @return void
     */
    protected function storeResult(LicenseResult $result): void
    {
        $this->lastResult = $result;

        $data = $result->toArray();
        if (isset($data['installation']['secret']) && is_string($data['installation']['secret'])) {
            $this->storeInstallSecret($data['installation']['secret']);
        }

        if (!$result->isValid() || !is_string($this->config['cache_file'])) {
            return;
        }

        $token = $data['offline']['token'] ?? ($this->readCache()['offline_token'] ?? null);

        $this->writeCache([
            'checked_at'    => time(),
            // Advanced on every successful check and never lowered, so a clock
            // wound back afterwards is detectable. See clockRollback().
            'seen_at'       => $this->highWaterMark(
                is_string($token) ? $this->verifyOfflineToken($token) : null
            ),
            'status'        => $result->getStatus(),
            'license'       => $data['license'] ?? [],
            'offline_token' => $token,
        ]);
    }

    /**
     * Return the cached result if it is still fresh, or null to go to the network.
     *
     * `checked_at` decides only *whether* to skip the network call. It cannot
     * decide the answer: that always comes from the signed payload, through the
     * same verification the offline path uses. Returning the cache entry's own
     * `license` array here would make the signature decorative - edit
     * `expires_at` or `features` in the file, leave the genuine token in place,
     * and the forged values would be handed straight back.
     *
     * A cache that fails verification returns null rather than a failure, so
     * the caller simply contacts the server instead of hard-failing on a stale
     * or damaged file.
     *
     * @return LicenseResult|null A verified fresh result, or null.
     */
    protected function freshCachedResult(): ?LicenseResult
    {
        $cache = $this->readCache();
        if ($cache === null || empty($cache['checked_at'])) {
            return null;
        }
        $age = time() - (int) $cache['checked_at'];
        if ($age < 0 || $age > (int) $this->config['cache_ttl']) {
            return null;
        }

        $result = $this->resultFromSignedCache('cache');

        return $result->isValid() ? $result : null;
    }

    /**
     * Read and decode the cache file, memoising the result.
     *
     * Every failure mode - no file, unreadable, malformed JSON - returns null,
     * because a missing or damaged cache is an ordinary condition that should
     * send the caller to the network, not an error.
     *
     * @return array<string,mixed>|null The decoded cache, or null.
     */
    protected function readCache(): ?array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $file = $this->config['cache_file'];
        if (!is_string($file) || !is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        // Tolerate a cache written before the guard line existed, so upgrading
        // the SDK does not force every installation back to the server.
        if (strncmp($raw, '<?php', 5) === 0) {
            $end = strpos($raw, "\n");
            $raw = $end === false ? '' : substr($raw, $end + 1);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $this->cache = $decoded;

        return $this->cache;
    }

    /**
     * Write the cache file, creating and protecting its directory if needed.
     *
     * The file is written 0600 under an exclusive lock, and prefixed with a
     * guard line that makes it inert if it is ever executed as PHP and non-JSON
     * if it is ever served raw. That guard is belt-and-braces behind the
     * constructor's location check: the only real protection is the file not
     * being reachable by URL.
     *
     * @param array<string,mixed> $data The cache entry to persist.
     *
     * @return void
     */
    protected function writeCache(array $data): void
    {
        $file = $this->config['cache_file'];
        if (!is_string($file)) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::protectDirectory($dir);

        $this->cache = $data;

        $payload = self::CACHE_GUARD . json_encode($data, JSON_UNESCAPED_SLASHES);

        @file_put_contents($file, $payload, LOCK_EX);
        @chmod($file, 0600);
    }

    /**
     * Drop deny-all rules into the cache directory, for servers that read them.
     *
     * Both Apache syntaxes are written because 2.4 dropped Allow/Deny and 2.2
     * has no Require; a web.config covers IIS, and an empty index.html defeats
     * directory listing.
     *
     * Existing files are never overwritten, so your own rules always win.
     *
     * @param string $dir The directory to protect.
     *
     * @return void
     */
    private static function protectDirectory(string $dir): void
    {
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $files = [
            '.htaccess'  => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                          . "    <security><authorization>\n      <deny users=\"*\" />\n"
                          . "    </authorization></security>\n  </system.webServer>\n</configuration>\n",
            'index.html' => '',
        ];

        foreach ($files as $name => $contents) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $contents);
            }
        }
    }

    // =========================================================================
    // Environment detection
    // =========================================================================

    /**
     * Assemble everything the server needs in order to make a decision.
     *
     * Sent with both activate() and check(). Anything you pass in the
     * `metadata` config option is merged over the defaults, so you can attach
     * your own diagnostics - a build number, a deployment name - and read them
     * back on the activation record in the admin UI.
     *
     * @return array<string,mixed> The request payload.
     */
    protected function environment(): array
    {
        return [
            'license_key'     => (string) $this->config['license_key'],
            'product_id'      => (string) $this->config['product_id'],
            'domain'          => $this->detectDomain(),
            'directory'       => $this->detectDirectory(),
            'machine_id'      => $this->machineId(),
            'installation_id' => $this->installationId(),
            'version'         => (string) $this->config['version'],
            'metadata'        => array_merge([
                'php'    => PHP_VERSION,
                'os'     => PHP_OS_FAMILY,
                'sdk'    => self::VERSION,
                'server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            ], (array) $this->config['metadata']),
        ];
    }

    /**
     * The hostname this installation reports, normalised.
     *
     * Scheme, port and path are stripped and the result is lowercased. The
     * configured `domain` wins if set; otherwise SERVER_NAME, then HTTP_HOST,
     * then the system hostname are tried in that order.
     *
     * Set `domain` explicitly for CLI scripts, cron jobs and queue workers,
     * which have no HTTP host to detect and would otherwise report the machine
     * hostname - a different value from the web requests on the same server.
     *
     * A leading "www." is deliberately preserved here. Whether it is
     * significant is the server's `allow_www_normalisation` policy; stripping
     * it at this point would decide that question locally, and the server would
     * never see the difference.
     *
     * @return string The normalised hostname, or '' if none could be determined.
     */
    public function detectDomain(): string
    {
        if (is_string($this->config['domain']) && $this->config['domain'] !== '') {
            return $this->normaliseDomain($this->config['domain'], false);
        }

        $candidates = [
            $_SERVER['SERVER_NAME'] ?? '',
            $_SERVER['HTTP_HOST'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $domain = $this->normaliseDomain((string) $candidate, false);
            if ($domain !== '') {
                return $domain;
            }
        }

        $hostname = gethostname();

        return $hostname === false ? '' : $this->normaliseDomain($hostname, false);
    }

    /**
     * Reduce a hostname to a canonical comparable form.
     *
     * Removes any scheme, any path or query, a trailing port, a trailing dot,
     * and lowercases the result. IPv6 literals are left alone, since they
     * legitimately contain several colons.
     *
     * @param string $domain   The raw hostname, URL or host:port value.
     * @param bool   $stripWww Whether a leading "www." is removed. This mirrors
     *                         the server's `allow_www_normalisation` setting, as
     *                         signed into the offline token. Stripping it
     *                         unconditionally would let www.example.com satisfy a
     *                         binding to example.com offline even where the
     *                         server refuses it online.
     *
     * @return string The normalised hostname, or '' if the input was empty.
     */
    protected function normaliseDomain(string $domain, bool $stripWww = true): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (strpos($domain, '//') !== false) {
            $domain = (string) preg_replace('#^[a-z0-9+.\-]*://#i', '', $domain);
        }
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);
        if (substr_count($domain, ':') === 1) {
            $domain = (string) preg_replace('/:\d+$/', '', $domain);
        }
        $domain = strtolower(rtrim(trim($domain), '.'));

        return $stripWww && strncmp($domain, 'www.', 4) === 0 ? substr($domain, 4) : $domain;
    }

    /**
     * Decide whether the current hostname satisfies the bound one.
     *
     * Three forms of binding are understood:
     *
     *   example.com     matches exactly; also matches subdomains when the
     *                   license permits them
     *   *.example.com   matches any subdomain, and deliberately excludes the
     *                   apex, mirroring the server's matching rules
     *
     * An empty bound or current domain matches, since there is nothing to
     * enforce against.
     *
     * @param string $bound           The domain the license is bound to.
     * @param string $current         The domain this installation reports.
     * @param bool   $allowSubdomains Whether subdomains satisfy an apex binding.
     *                                Comes from the signed payload, never a guess:
     *                                accepting them unconditionally would enforce
     *                                a stricter policy online than offline.
     * @param bool   $stripWww        Whether "www." is insignificant.
     *
     * @return bool True when the binding is satisfied.
     */
    protected function domainMatches(
        string $bound,
        string $current,
        bool $allowSubdomains = false,
        bool $stripWww = true
    ): bool {
        $bound   = $this->normaliseDomain($bound, $stripWww);
        $current = $this->normaliseDomain($current, $stripWww);
        if ($bound === '' || $current === '') {
            return true;
        }

        if (strncmp($bound, '*.', 2) === 0) {
            $suffix = substr($bound, 1);

            return $current !== substr($suffix, 1)
                && substr($current, -strlen($suffix)) === $suffix;
        }

        if (hash_equals($bound, $current)) {
            return true;
        }

        return $allowSubdomains
            && substr($current, -(strlen($bound) + 1)) === '.' . $bound;
    }

    /**
     * The resolved installation path, used for directory binding.
     *
     * The configured `directory` wins if set. Otherwise WordPress's ABSPATH is
     * used when defined, falling back to the parent of the directory holding
     * this file.
     *
     * IMPORTANT for deploy systems: a timestamped release path such as
     * /var/www/releases/20240115120000/ changes on every deploy, so every
     * release presents as a brand-new installation and consumes an activation
     * slot. Set `directory` to the stable shared path, or set `installation_id`
     * explicitly, on Capistrano/Deployer/Envoyer-style setups.
     *
     * @return string The absolute install path, with forward slashes and no
     *                trailing separator.
     */
    public function detectDirectory(): string
    {
        if (is_string($this->config['directory']) && $this->config['directory'] !== '') {
            return $this->config['directory'];
        }

        $dir = defined('ABSPATH') ? (string) constant('ABSPATH') : dirname(__DIR__);
        $real = realpath($dir);

        return str_replace('\\', '/', rtrim($real === false ? $dir : $real, '/\\'));
    }

    /**
     * A stable, non-invasive machine identifier.
     *
     * Derived by hashing the system hostname, the OS name and the installation
     * path - enough to tell two installations apart without collecting hardware
     * serials, MAC addresses or any user information, and therefore safe to use
     * without additional privacy disclosure.
     *
     * Override it with the `machine_id` config option if your product already
     * has a better notion of machine identity.
     *
     * @return string A 40-character hex identifier.
     */
    public function machineId(): string
    {
        if (is_string($this->config['machine_id']) && $this->config['machine_id'] !== '') {
            return $this->config['machine_id'];
        }

        $seed = implode('|', [
            (string) gethostname(),
            PHP_OS,
            $this->detectDirectory(),
        ]);

        return substr(hash('sha256', $seed), 0, 40);
    }

    /**
     * The identifier for this installation's activation slot.
     *
     * Derived from the machine id and the reported domain, so one physical
     * server hosting the same product on two domains occupies two slots - which
     * is normally what you want to sell.
     *
     * Set `installation_id` explicitly to keep one logical installation across
     * a hostname change, a path change or a server migration.
     *
     * @return string The activation slot identifier, prefixed "inst-".
     */
    public function installationId(): string
    {
        if (is_string($this->config['installation_id']) && $this->config['installation_id'] !== '') {
            return $this->config['installation_id'];
        }

        return 'inst-' . substr(hash('sha256', $this->machineId() . '|' . $this->detectDomain()), 0, 36);
    }

    /**
     * Decide whether a hostname looks like a development or local environment.
     *
     * Treated as local: private and reserved IP addresses, single-label hosts
     * such as "localhost" or a Docker container name, the reserved development
     * TLDs (.local, .test, .example, .invalid, .internal), and the conventional
     * development prefixes (dev., staging., test., sandbox., qa. and so on).
     *
     * This mirrors the server's own detection, so a license whose policy forbids
     * local domains is refused offline for exactly the hostnames it is refused
     * online.
     *
     * @param string $domain The hostname to classify.
     *
     * @return bool True when the hostname looks like a development environment.
     */
    protected function isLocalDomain(string $domain): bool
    {
        $domain = $this->normaliseDomain($domain);
        if ($domain === '') {
            return false;
        }

        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $domain,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (strpos($domain, '.') === false) {
            return true;
        }

        foreach (['.local', '.localhost', '.test', '.example', '.invalid', '.internal', '.dev.local'] as $suffix) {
            if (substr($domain, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        foreach (['dev.', 'staging.', 'stage.', 'test.', 'sandbox.', 'local.', 'qa.'] as $prefix) {
            if (strncmp($domain, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise an install path so two spellings of the same path compare equal.
     *
     * Backslashes become forward slashes, repeated separators collapse, any
     * trailing separator is dropped, and a Windows drive letter is uppercased
     * because it is case-insensitive while the rest of a POSIX path is not.
     *
     * This mirrors the server's own path normalisation, so the same two paths
     * compare equal on both sides of the binding check.
     *
     * @param string $path The path to normalise.
     *
     * @return string The normalised path; '/' for a path that reduces to empty.
     */
    protected function normalisePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        if (preg_match('#^([a-zA-Z]):/#', $path, $m)) {
            $path = strtoupper($m[1]) . substr($path, 1);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Decode unpadded base64url back to raw bytes.
     *
     * @param string $data The base64url string.
     *
     * @return string The decoded bytes, or '' if the input was not valid base64.
     */
    protected function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    // =========================================================================
    // Version constraints
    // =========================================================================

    /**
     * Explain why the running version is not covered by the license, or return null.
     *
     * Version constraints let you sell a license that covers, say, the 2.x line
     * only, and have older or newer builds refuse to run on it. The constraints
     * are set per license on the server and signed into the offline token, so
     * they are enforced identically online and offline.
     *
     * This mirrors the server's own version comparison. It is duplicated rather
     * than shared because this file ships to your customers on its own; if the
     * constraint syntax changes on the server, it must change here too.
     *
     * An empty running version means you did not configure one, and the server
     * treats that as unrestricted, so this does too.
     *
     * @param string      $version The running software version.
     * @param string|null $min     Minimum supported version, or null.
     * @param string|null $max     Maximum supported version, or null.
     * @param string|null $allowed A constraint expression, e.g. "1.x, 2.0 - 2.4, 3.0+".
     *
     * @return string|null A readable reason, or null when the version is covered.
     */
    protected function versionProblem(string $version, ?string $min, ?string $max, ?string $allowed): ?string
    {
        $version = trim($version);
        if ($version === '') {
            return null;
        }

        if ($min !== null && trim($min) !== '' && $this->versionCompare($version, trim($min)) < 0) {
            return 'minimum supported version is ' . trim($min);
        }
        if ($max !== null && trim($max) !== '' && $this->versionCompare($version, trim($max)) > 0) {
            return 'maximum supported version is ' . trim($max);
        }
        if ($allowed !== null && trim($allowed) !== '' && !$this->versionSatisfies($version, trim($allowed))) {
            return 'version does not match the allowed set (' . trim($allowed) . ')';
        }

        return null;
    }

    /**
     * Split a version string into its numeric components.
     *
     * A leading "v" is dropped, and any pre-release or build suffix ("-beta",
     * "+build.7") is discarded, so "v2.1.0-rc1" and "2.1.0" compare equal.
     * Non-numeric leading text in a component is stripped.
     *
     * @param string $version The version string.
     *
     * @return list<int> The numeric components, never empty.
     */
    protected function versionParts(string $version): array
    {
        $version = strtolower(trim($version));
        $version = (string) preg_replace('/^v/', '', $version);
        $version = (string) preg_replace('/[+\-].*$/', '', $version);

        $numbers = [];
        foreach (preg_split('/[._]/', $version) ?: [] as $part) {
            if ($part !== '') {
                $numbers[] = (int) preg_replace('/\D.*$/', '', $part);
            }
        }

        return $numbers === [] ? [0] : $numbers;
    }

    /**
     * Compare two version strings component by component.
     *
     * Missing components are treated as zero, so "2.1" and "2.1.0" are equal.
     *
     * @param string $a The left version.
     * @param string $b The right version.
     *
     * @return int -1 if $a is lower, 1 if higher, 0 if equal.
     */
    protected function versionCompare(string $a, string $b): int
    {
        $left  = $this->versionParts($a);
        $right = $this->versionParts($b);

        for ($i = 0, $len = max(count($left), count($right)); $i < $len; $i++) {
            $l = $left[$i] ?? 0;
            $r = $right[$i] ?? 0;
            if ($l !== $r) {
                return $l < $r ? -1 : 1;
            }
        }

        return 0;
    }

    /**
     * Test a version against a constraint expression.
     *
     * The expression is a comma- or pipe-separated list of constraints, and the
     * version satisfies the expression if it matches ANY of them. "*" or an
     * empty expression matches everything.
     *
     * @param string $version    The version to test.
     * @param string $expression The constraint expression.
     *
     * @return bool True when at least one constraint matches.
     */
    protected function versionSatisfies(string $version, string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '*') {
            return true;
        }
        if (trim($version) === '') {
            return false;
        }

        foreach (preg_split('/[,|]/', $expression) ?: [] as $constraint) {
            $constraint = trim($constraint);
            if ($constraint !== '' && $this->versionMatchesOne($version, $constraint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test a version against one individual constraint.
     *
     * Supported forms:
     *
     *     *              anything
     *     1.0 - 1.9      an inclusive range
     *     3.0+           that version or higher
     *     >=2.1  <=3.0   comparisons: >=, <=, >, <, !=, =
     *     >2.1   !=2.5
     *     1.x  2.*       a wildcard on the trailing component
     *     2.1.0          an exact match
     *
     * @param string $constraint A single trimmed constraint.
     * @param string $version    The version to test.
     *
     * @return bool True when the version satisfies the constraint.
     */
    protected function versionMatchesOne(string $version, string $constraint): bool
    {
        if ($constraint === '*') {
            return true;
        }

        // Inclusive range, e.g. "1.0 - 1.9". The guard keeps "<=1.0 - 2.0" from
        // being read as a range.
        if (preg_match('/^(\S+)\s*-\s*(\S+)$/', $constraint, $m) && !preg_match('/^[<>=!]/', $m[1])) {
            return $this->versionCompare($version, $m[1]) >= 0 && $this->versionCompare($version, $m[2]) <= 0;
        }

        // "3.0+"
        if (preg_match('/^(.+)\+$/', $constraint, $m)) {
            return $this->versionCompare($version, trim($m[1])) >= 0;
        }

        // Comparison operators.
        if (preg_match('/^(>=|<=|!=|>|<|=)\s*(.+)$/', $constraint, $m)) {
            $cmp = $this->versionCompare($version, trim($m[2]));
            switch ($m[1]) {
                case '>=': return $cmp >= 0;
                case '<=': return $cmp <= 0;
                case '>':  return $cmp > 0;
                case '<':  return $cmp < 0;
                case '!=': return $cmp !== 0;
                case '=':  return $cmp === 0;
            }

            return false;
        }

        // Wildcards: "1.x", "2.*", "1.2.x".
        if (preg_match('/^(.*?)\.[x*]$/i', $constraint, $m)) {
            $prefix    = $this->versionParts($m[1]);
            $candidate = $this->versionParts($version);
            foreach ($prefix as $i => $value) {
                if (($candidate[$i] ?? 0) !== $value) {
                    return false;
                }
            }

            return true;
        }

        return $this->versionCompare($version, $constraint) === 0;
    }
}
