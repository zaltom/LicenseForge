// =============================================================================
// LicenseForge client SDK for .NET
// =============================================================================
//
// A single-file client for validating LicenseForge licenses from inside your
// .NET product. Add it to your project and you are done - there are no NuGet
// dependencies.
//
// -----------------------------------------------------------------------------
// QUICK START
// -----------------------------------------------------------------------------
//
//     var license = new LicenseClient(new LicenseOptions {
//         LicenseServer = "https://billing.example.com/modules/addons/licenseforge/api/index.php",
//         LicenseKey    = keyTheCustomerEntered,
//         ProductId     = "your-product-slug",
//         ApiKey        = "lfk_...",
//         ApiSecret     = "lfs_...",
//         PublicKey     = "<PEM RSA public key from your licensing server>",
//         PublicKeyAlgorithm = "rsa-sha256",
//         Version       = "2.4.0",
//         CacheFile     = Path.Combine(appData, "license.cache"),
//     });
//
//     // Run once, when the customer first enters their key.
//     var activation = await license.ActivateAsync();
//     if (!activation.IsValid) { /* show activation.ErrorMessage and stop */ }
//
//     // Run on every start. Cached, so this is normally just a file read.
//     if (!(await license.CheckAsync()).IsValid) { /* degrade or exit */ }
//
// Two calls are all you need: ActivateAsync once, CheckAsync from then on.
// Moving a license to a new machine, or releasing an activation slot, is done
// by the customer from their product page in your client area.
//
// -----------------------------------------------------------------------------
// REQUIREMENTS
// -----------------------------------------------------------------------------
//
// .NET 6.0 or later. Two things set that floor: RSA.ImportFromPem, which
// arrived in .NET 5, and the pattern syntax used below, which needs C# 9.
//
// The online path uses only the base class library. RSA signature verification
// is built in, so offline validation works out of the box when your licensing
// server is configured for RSA.
//
// .NET has no Ed25519 primitive, so that algorithm is pluggable: set
// LicenseOptions.Ed25519Verifier and hand it to whichever library you already
// use (BouncyCastle, NSec, libsodium bindings). Left null, an Ed25519 token
// cannot be checked and is therefore refused - a verification that cannot be
// performed is never treated as one that passed.
//
// -----------------------------------------------------------------------------
// HOW A DECISION IS REACHED
// -----------------------------------------------------------------------------
//
// 1. A cached answer, while it is younger than CacheTtl.
// 2. The licensing server.
// 3. The signed offline token saved by the last successful call, used only when
//    the server is unreachable, and only while its signature verifies, its key
//    matches, the server-issued offline window is open, and every binding still
//    holds.
//
// Requests are signed with HMAC-SHA256 over a canonical string:
//
//     METHOD \n endpoint \n timestamp \n nonce \n sha256hex(body)
//
// There is no fail-open path in this file. An installation that cannot be
// *proven* licensed is never treated as licensed.
//
// -----------------------------------------------------------------------------
// ERRORS
// -----------------------------------------------------------------------------
//
// Licensing outcomes, including refusals, always come back as a LicenseResult.
// A thrown LicenseException means the SDK was configured incorrectly and is a
// bug in your integration, not a customer-facing state.
//
// -----------------------------------------------------------------------------
// REDISTRIBUTION
// -----------------------------------------------------------------------------
//
// You may copy this file into software you license with LicenseForge, modify it
// to suit your product, and distribute it to your own customers as part of that
// software.
// =============================================================================

using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.NetworkInformation;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace LicenseForge
{
    /// <summary>
    /// Thrown when the SDK itself is misconfigured.
    /// </summary>
    /// <remarks>
    /// Never used to report a licensing decision: a refused, expired or
    /// unrecognised license always comes back as a <see cref="LicenseResult"/>
    /// whose <see cref="LicenseResult.IsValid"/> is false. If you see this
    /// exception, the fix is in your own integration code - a missing license
    /// key, a non-HTTPS server URL, a cache path under a web root - and it
    /// should surface during your testing rather than in a customer's log.
    /// </remarks>
    public class LicenseException : Exception
    {
        /// <summary>Create the exception with an explanatory message.</summary>
        /// <param name="message">What is wrong with the configuration, and how to fix it.</param>
        public LicenseException(string message) : base(message) { }
    }

    /// <summary>
    /// Everything the client needs. Only <see cref="LicenseServer"/> and
    /// <see cref="LicenseKey"/> are required, but you will almost always want
    /// <see cref="ProductId"/>, <see cref="ApiKey"/>, <see cref="ApiSecret"/>,
    /// <see cref="PublicKey"/> and <see cref="CacheFile"/> as well.
    /// </summary>
    public class LicenseOptions
    {
        /// <summary>
        /// Base URL of your LicenseForge API endpoint. Required.
        /// </summary>
        /// <remarks>
        /// Must be HTTPS. Plain HTTP is permitted only for 127.0.0.1 and
        /// localhost, for local testing: the request carries a license key and
        /// a signature, and plain HTTP would hand both to anyone on the network
        /// path.
        /// </remarks>
        public string LicenseServer { get; set; } = "";

        /// <summary>The customer's license key. Required.</summary>
        public string LicenseKey { get; set; } = "";

        /// <summary>
        /// Your product's slug on the server. Strongly recommended - it lets
        /// the SDK reject a key issued for a different product.
        /// </summary>
        public string ProductId { get; set; } = "";

        /// <summary>Your product's API key ("lfk_...").</summary>
        public string ApiKey { get; set; } = "";

        /// <summary>Your product's API secret ("lfs_...").</summary>
        public string ApiSecret { get; set; } = "";

        /// <summary>
        /// The licensing server's offline-token public key: base64 for Ed25519,
        /// PEM for RSA.
        /// </summary>
        /// <remarks>
        /// Without it, offline validation can never succeed and the first
        /// network outage stops every customer's copy.
        /// </remarks>
        public string PublicKey { get; set; } = "";

        /// <summary>
        /// "ed25519" or "rsa-sha256". Only consulted when a token does not name
        /// its own algorithm. Prefer "rsa-sha256" on .NET, which needs no
        /// external library - see <see cref="Ed25519Verifier"/>.
        /// </summary>
        public string PublicKeyAlgorithm { get; set; } = "ed25519";

        /// <summary>
        /// Your software's version, so the server can enforce the license's
        /// version constraints. Leave empty for unrestricted.
        /// </summary>
        public string Version { get; set; } = "";

        /// <summary>
        /// The hostname to report. Auto-detected when null. Set it explicitly
        /// for services and console tools where the detected host name is not
        /// the one the license is bound to.
        /// </summary>
        public string? Domain { get; set; }

        /// <summary>
        /// The install path to report. Auto-detected from the application base
        /// directory when null. Set it to a stable path on deployment systems
        /// that use versioned install folders - otherwise every update looks
        /// like a new installation and consumes an activation slot.
        /// </summary>
        public string? Directory { get; set; }

        /// <summary>Override the derived machine fingerprint.</summary>
        public string? MachineId { get; set; }

        /// <summary>
        /// Override the derived activation-slot id. Set it to keep one logical
        /// installation across a machine change or migration.
        /// </summary>
        public string? InstallationId { get; set; }

        /// <summary>
        /// Normally left null: activation issues one and it is stored beside
        /// the cache. Set it only if you manage installation identity yourself
        /// (an image build, a container secret store).
        /// </summary>
        public string? InstallSecret { get; set; }

        /// <summary>
        /// Where to store the cached answer and the signed offline token.
        /// </summary>
        /// <remarks>
        /// Must NOT be reachable over HTTP. Three more files are created
        /// alongside it: <c>.install</c> holds the activation secret,
        /// <c>.install.key</c> holds the private key, and <c>.id</c> holds the
        /// installation identifier.
        /// <para>
        /// Despite the name, this directory is <em>persistent state, not a
        /// cache</em>. Only the first file is disposable; the rest are this
        /// installation's identity. Put it where you would put a data directory
        /// - under <c>ApplicationData</c> or <c>CommonApplicationData</c>, not
        /// beside the binaries - and never inside anything an update replaces.
        /// Losing the credentials while the installation id survives is a
        /// lockout rather than a silent extra activation; see
        /// <see cref="LicenseClient.ActivateAsync"/>.
        /// </para>
        /// </remarks>
        public string? CacheFile { get; set; }

        /// <summary>
        /// The oldest offline-token format this client will act on.
        /// </summary>
        /// <remarks>
        /// Version 3 is the first that carries a cryptographic proof binding
        /// the token to one specific installation; older formats fall back to
        /// weaker defaults, so they are refused by default. The cost of
        /// refusing is a single round trip, after which the server issues a
        /// current token. Lower this only for a deployment that genuinely
        /// cannot reconnect - an air-gapped install being upgraded offline is
        /// the case it exists for. 0 disables the check.
        /// </remarks>
        public int MinimumTokenVersion { get; set; } = 3;

        /// <summary>
        /// Allow a <see cref="CacheFile"/> inside a directory that looks
        /// web-served. Off by default: the cache holds the license key and the
        /// signed offline token, and the installation secret and private key
        /// sit beside it.
        /// </summary>
        public bool CacheAllowWebRoot { get; set; }

        /// <summary>
        /// How long a successful answer is reused before the server is asked
        /// again. Default one day.
        /// </summary>
        public TimeSpan CacheTtl { get; set; } = TimeSpan.FromDays(1);

        /// <summary>
        /// Fallback grace period, used only for legacy tokens that carry no
        /// server-signed grace deadline. Default three days.
        /// </summary>
        public TimeSpan GracePeriod { get; set; } = TimeSpan.FromDays(3);

        /// <summary>
        /// Request timeout. Applied only to an <see cref="HttpClient"/> the SDK
        /// creates itself; if you supply your own, configure its timeout there.
        /// </summary>
        public TimeSpan Timeout { get; set; } = TimeSpan.FromSeconds(10);

        /// <summary>Extra attempts on transport failure. Default 2.</summary>
        public int Retries { get; set; } = 2;

        /// <summary>
        /// Base backoff between attempts, scaled by the attempt number.
        /// </summary>
        public TimeSpan RetryDelay { get; set; } = TimeSpan.FromMilliseconds(300);

        /// <summary>
        /// Extra key/value pairs sent with every call and recorded against the
        /// activation. Useful for a build number or deployment name, which you
        /// can then read back in the admin UI.
        /// </summary>
        public Dictionary<string, string> Metadata { get; set; } = new Dictionary<string, string>();

        /// <summary>
        /// Ed25519 verification, if your licensing server signs with Ed25519:
        /// <c>(message, signature, publicKey) =&gt; valid</c>.
        /// </summary>
        /// <remarks>
        /// .NET has no Ed25519 primitive, so this must be supplied from a
        /// library of your choice. Left null, an Ed25519 token cannot be
        /// verified and is therefore refused. Your implementation must return
        /// false rather than throwing, and must never return true for a check
        /// it could not actually perform.
        /// </remarks>
        public Func<byte[], byte[], string, bool>? Ed25519Verifier { get; set; }
    }

    /// <summary>
    /// The outcome of an activate or check call.
    /// </summary>
    /// <remarks>
    /// An immutable value object: inspect it, branch on it, discard it. It
    /// never throws. The two questions you will ask most often are
    /// <see cref="IsValid"/> ("may this copy run?") and <see cref="ErrorCode"/>
    /// ("if not, why not?").
    /// </remarks>
    public class LicenseResult
    {
        /// <summary>
        /// Whether this copy of the software is permitted to run.
        /// </summary>
        /// <remarks>
        /// This is the single question the SDK exists to answer. True only when
        /// the license was positively proven valid - by the server, or by a
        /// signed offline token that passed every check. Anything else,
        /// including an unreachable server with no usable token, is false.
        /// </remarks>
        public bool IsValid { get; }

        /// <summary>
        /// Where the answer came from: "remote" from the server, "cache" from a
        /// still-fresh earlier answer, "offline" from a locally verified token.
        /// </summary>
        /// <remarks>
        /// A transport failure reports "offline", so a caller can tell an
        /// unreachable server apart from a refusal. Useful for a "working
        /// offline" indicator in your UI.
        /// </remarks>
        public string Source { get; }

        /// <summary>
        /// Why the license was refused, as a stable code, or null on success.
        /// </summary>
        /// <remarks>
        /// Branch on this rather than on <see cref="ErrorMessage"/>, which is
        /// prose and may be reworded. Codes you should expect to handle:
        /// <list type="table">
        /// <item><term>INVALID_LICENSE</term><description>the key is not recognised, or is not active</description></item>
        /// <item><term>LICENSE_EXPIRED</term><description>past its expiry and past any grace period</description></item>
        /// <item><term>ACTIVATION_NOT_FOUND</term><description>never activated - call ActivateAsync</description></item>
        /// <item><term>ACTIVATION_LIMIT</term><description>no free activation slots remain</description></item>
        /// <item><term>DOMAIN_MISMATCH</term><description>running on a host the license is not bound to</description></item>
        /// <item><term>DIRECTORY_MISMATCH</term><description>running from a different install path</description></item>
        /// <item><term>MACHINE_MISMATCH</term><description>running on a different machine or installation</description></item>
        /// <item><term>PRODUCT_MISMATCH</term><description>the key belongs to a different product</description></item>
        /// <item><term>VERSION_NOT_SUPPORTED</term><description>this software version is not covered</description></item>
        /// <item><term>SIGNATURE_INVALID</term><description>the cached token could not be verified</description></item>
        /// <item><term>SERVICE_UNAVAILABLE</term><description>server unreachable and no valid offline token</description></item>
        /// </list>
        /// SERVICE_UNAVAILABLE says nothing about the license itself. Treat it
        /// as "unknown", and decide for your product whether that warrants a
        /// hard stop or a warning.
        /// </remarks>
        public string? ErrorCode { get; }

        /// <summary>
        /// A human-readable explanation of the refusal, safe to show the user
        /// verbatim, or null on success.
        /// </summary>
        public string? ErrorMessage { get; }

        /// <summary>
        /// The raw decoded payload, for anything the typed members do not
        /// expose - custom fields you have added on the server, subscription
        /// metadata, and so on.
        /// </summary>
        public IReadOnlyDictionary<string, JsonElement> Data { get; }

        /// <summary>
        /// Build a result.
        /// </summary>
        /// <remarks>
        /// You will not normally construct one of these yourself; the client
        /// does it for you. It is public so you can fabricate results in your
        /// own unit tests, or wrap the SDK behind your own interface.
        /// </remarks>
        /// <param name="isValid">Whether the software may run.</param>
        /// <param name="data">The decoded response payload.</param>
        /// <param name="errorCode">Stable refusal code, or null.</param>
        /// <param name="errorMessage">Readable refusal reason, or null.</param>
        /// <param name="source">"remote", "cache" or "offline".</param>
        public LicenseResult(
            bool isValid,
            IReadOnlyDictionary<string, JsonElement>? data = null,
            string? errorCode = null,
            string? errorMessage = null,
            string source = "remote")
        {
            IsValid = isValid;
            Data = data ?? new Dictionary<string, JsonElement>();
            ErrorCode = errorCode;
            ErrorMessage = errorMessage;
            Source = source;
        }

        /// <summary>
        /// The license sub-object of the payload, or null if it is absent or is
        /// not an object.
        /// </summary>
        private JsonElement? License =>
            Data.TryGetValue("license", out var value) && value.ValueKind == JsonValueKind.Object
                ? value
                : (JsonElement?)null;

        /// <summary>
        /// The license state: "active", "expired", "suspended", "revoked" or
        /// "unknown". Use <see cref="ErrorCode"/> when you need to branch in
        /// code; this is for telling the customer what is wrong.
        /// </summary>
        public string Status => ReadString("status") ?? "unknown";

        /// <summary>
        /// When the license expires, as the raw server-supplied timestamp
        /// string, or null for a lifetime license.
        /// </summary>
        public string? ExpiresAt => ReadString("expires_at");

        /// <summary>
        /// Whether this installation still needs to claim an activation slot.
        /// </summary>
        /// <remarks>
        /// True when the key itself is fine but this particular copy has never
        /// been bound to it - a fresh install, or a restore onto a new machine.
        /// The correct response is to call
        /// <see cref="LicenseClient.ActivateAsync"/>, or to prompt the customer
        /// to do so. Signalled at the top level of a successful reply, and by
        /// error code when the check-in itself was refused.
        /// </remarks>
        public bool NeedsActivation =>
            (Data.TryGetValue("needs_activation", out var needs)
                && needs.ValueKind == JsonValueKind.True)
            || ErrorCode == "ACTIVATION_NOT_FOUND"
            || ErrorCode == "LICENSE_PENDING";

        /// <summary>
        /// Whether the license has lapsed but is still being honoured.
        /// </summary>
        /// <remarks>
        /// A result that is both valid and in grace means the customer's
        /// license has expired and the product's configured grace period is
        /// keeping them running for now. Show a renewal warning; do not block.
        /// </remarks>
        public bool InGracePeriod =>
            Data.TryGetValue("grace", out var grace)
            && grace.ValueKind == JsonValueKind.Object
            && grace.TryGetProperty("active", out var flag)
            && flag.ValueKind == JsonValueKind.True;

        /// <summary>
        /// The entitlement slugs granted to this license, with any that have
        /// reached their own expiry already filtered out.
        /// </summary>
        /// <remarks>
        /// Features are the mechanism for selling tiers or add-ons from one
        /// product: define slugs such as "premium-reports" on the server, and
        /// gate the matching code paths on them.
        /// <para>
        /// A feature can expire before the license does. The server drops an
        /// already lapsed feature when it mints a token, but a token minted
        /// while a feature was still live carries it for the token's entire
        /// offline window - so without the signed per-feature dates, a feature
        /// would keep working for days after the server stopped granting it. A
        /// payload with no <c>feature_expiry</c> map means "nothing expires",
        /// which is what the flat list meant before the map existed.
        /// </para>
        /// </remarks>
        public IReadOnlyList<string> Features
        {
            get
            {
                // Read the property out first: C# forbids declaring a variable
                // inside a negated pattern, since it would not be definitely
                // assigned on the branch that follows.
                var lic = License;
                if (lic is null
                    || !lic.Value.TryGetProperty("features", out var list)
                    || list.ValueKind != JsonValueKind.Array)
                {
                    return Array.Empty<string>();
                }

                var expiry = lic.Value.TryGetProperty("feature_expiry", out var map)
                             && map.ValueKind == JsonValueKind.Object
                    ? map
                    : (JsonElement?)null;

                var now = DateTimeOffset.UtcNow;

                return list.EnumerateArray()
                    .Where(e => e.ValueKind == JsonValueKind.String)
                    .Select(e => e.GetString()!)
                    .Where(slug =>
                    {
                        if (expiry == null
                            || !expiry.Value.TryGetProperty(slug, out var ends)
                            || ends.ValueKind != JsonValueKind.String)
                        {
                            return true;
                        }

                        return !(DateTimeOffset.TryParse(
                                     ends.GetString(),
                                     CultureInfo.InvariantCulture,
                                     DateTimeStyles.AdjustToUniversal | DateTimeStyles.AssumeUniversal,
                                     out var at)
                                 && at < now);
                    })
                    .ToList();
            }
        }

        /// <summary>Whether a single entitlement is granted.</summary>
        /// <param name="slug">The feature slug, exactly as configured on the server.</param>
        /// <returns>True when the license grants that feature.</returns>
        public bool HasFeature(string slug) => Features.Contains(slug);

        /// <summary>Read a string property from the license sub-object, or null.</summary>
        /// <param name="property">The JSON property name.</param>
        private string? ReadString(string property) =>
            License is { } lic
            && lic.TryGetProperty(property, out var value)
            && value.ValueKind == JsonValueKind.String
                ? value.GetString()
                : null;

        /// <summary>A compact single-line summary, for logs and debugging.</summary>
        public override string ToString() =>
            $"LicenseResult(valid={IsValid}, status={Status}, source={Source}, error={ErrorCode})";
    }

    /// <summary>
    /// The LicenseForge licensing client. One instance per licensed installation.
    /// </summary>
    /// <remarks>
    /// Construct it once with your <see cref="LicenseOptions"/>, then call
    /// <see cref="ActivateAsync"/> when the customer supplies their key and
    /// <see cref="CheckAsync"/> from then on. Every other method is a
    /// convenience built on those two.
    /// <para>
    /// Dispose the client when you are done. If you supplied your own
    /// <see cref="HttpClient"/> it is left alone; only one the SDK created is
    /// disposed.
    /// </para>
    /// <para>
    /// The client is safe to use from several processes starting at once: the
    /// first-activation keypair is placed with an exclusive file operation, so
    /// exactly one process' key becomes the installation key and the rest adopt
    /// it. A single instance is not, however, intended for concurrent use from
    /// several threads - construct one per unit of work, or serialise access.
    /// </para>
    /// </remarks>
    public class LicenseClient : IDisposable
    {
        /// <summary>
        /// The SDK version, reported to the server in metadata and User-Agent.
        /// </summary>
        public const string SdkVersion = "1.0.0";

        /// <summary>
        /// How far the system clock may read behind evidence this installation
        /// has already seen before an offline answer is refused.
        /// </summary>
        /// <remarks>
        /// One day absorbs ordinary drift, a VM resuming with a stale clock,
        /// and NTP stepping backwards. It does not absorb a deliberate rollback
        /// intended to revive an expired token. See <see cref="ClockRollback"/>.
        /// </remarks>
        public static readonly TimeSpan ClockTolerance = TimeSpan.FromDays(1);

        /// <summary>
        /// The newest offline-token format this client understands.
        /// </summary>
        /// <remarks>
        /// A token claiming a higher version is refused rather than
        /// interpreted, because unknown fields would be read as "absent, so the
        /// older and looser rule applies". See <see cref="ResultFromSignedCache"/>.
        /// </remarks>
        public const int SupportedTokenVersion = 3;

        private readonly LicenseOptions _options;
        private readonly HttpClient _http;

        /// <summary>True when the SDK created the HttpClient and must dispose it.</summary>
        private readonly bool _ownsHttpClient;

        /// <summary>The most recent result, so accessors do not re-check.</summary>
        private LicenseResult? _last;

        /// <summary>Memoised machine fingerprint.</summary>
        private string? _machineId;

        /// <summary>Memoised installation secret; "" means "looked, none stored".</summary>
        private string? _installSecret;

        /// <summary>Memoised activation-slot identifier.</summary>
        private string? _installationId;

        /// <summary>
        /// Create a client and validate its configuration.
        /// </summary>
        /// <remarks>
        /// Configuration problems are raised here, immediately, so that you
        /// meet them while integrating rather than in a customer's log.
        /// </remarks>
        /// <param name="options">Your configuration. See <see cref="LicenseOptions"/>.</param>
        /// <param name="httpClient">
        /// An HttpClient to reuse. Supply one from IHttpClientFactory in an
        /// ASP.NET Core application, or from your own long-lived instance;
        /// otherwise the SDK creates and owns one. A supplied client's timeout
        /// is left untouched - setting it would change behaviour you did not
        /// ask for, and throws outright once that client has sent a request.
        /// </param>
        /// <exception cref="ArgumentNullException">If options is null.</exception>
        /// <exception cref="LicenseException">
        /// If the license key or server URL is missing, the server URL is not
        /// HTTPS, or the cache path looks web-served.
        /// </exception>
        public LicenseClient(LicenseOptions options, HttpClient? httpClient = null)
        {
            _options = options ?? throw new ArgumentNullException(nameof(options));

            if (string.IsNullOrEmpty(_options.LicenseKey))
            {
                throw new LicenseException("A LicenseKey is required.");
            }
            if (string.IsNullOrEmpty(_options.LicenseServer))
            {
                throw new LicenseException("A LicenseServer URL is required.");
            }

            var server = _options.LicenseServer.TrimEnd('/');
            if (!server.StartsWith("https://", StringComparison.OrdinalIgnoreCase)
                && !server.StartsWith("http://127.0.0.1", StringComparison.OrdinalIgnoreCase)
                && !server.StartsWith("http://localhost", StringComparison.OrdinalIgnoreCase))
            {
                throw new LicenseException("The LicenseServer URL must use HTTPS.");
            }

            AssertCacheIsPrivate();
            _options.LicenseServer = server;

            _ownsHttpClient = httpClient == null;
            _http = httpClient ?? new HttpClient();

            if (_ownsHttpClient)
            {
                _http.Timeout = _options.Timeout;
            }
        }

        // ---------------------------------------------------------------------
        // Public API
        // ---------------------------------------------------------------------

        /// <summary>
        /// Bind this installation to the license and claim an activation slot.
        /// </summary>
        /// <remarks>
        /// Call this once, when the customer first enters their key. It always
        /// contacts the server; the cache is never consulted.
        /// <para>
        /// Calling it again from the same installation re-binds the existing
        /// slot rather than consuming another, because the installation
        /// identity is stable across calls. It is therefore safe to call from
        /// an installer that may be re-run, or from a "re-check my license"
        /// button.
        /// </para>
        /// <para>
        /// That holds only while this installation can still prove itself. If
        /// the credential files beside the cache are lost but the installation
        /// id resolves to the same value, the server refuses rather than
        /// re-binding - ACTIVATION_NOT_FOUND, or ACTIVATION_LIMIT if that slot
        /// was the last free one - because handing a live installation's record
        /// to a caller that cannot prove it owns it would make the installation
        /// id a credential, and it is not one. Recovery is a reset by the
        /// customer or an administrator.
        /// </para>
        /// <para>
        /// A keypair is generated locally first and only the public half is
        /// ever transmitted, so from here on the server holds nothing capable
        /// of impersonating this installation. Registration happens only at
        /// activation, which is the one point where identity is being
        /// established anyway.
        /// </para>
        /// </remarks>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        /// <returns>The activation outcome. Check <see cref="LicenseResult.IsValid"/>.</returns>
        public Task<LicenseResult> ActivateAsync(CancellationToken cancellationToken = default)
        {
            GenerateInstallKey();

            return CallAndStoreAsync("activate", cancellationToken);
        }

        /// <summary>
        /// The recurring licensing check-in. Call this on every start.
        /// </summary>
        /// <remarks>
        /// A successful answer is reused until
        /// <see cref="LicenseOptions.CacheTtl"/> elapses, so in normal
        /// operation this costs a single file read rather than a network round
        /// trip. When the cache is stale the server is contacted; when the
        /// server is unreachable the signed offline token is verified locally.
        /// </remarks>
        /// <param name="force">
        /// Skip the cache and always contact the server. Suitable for an
        /// explicit "refresh license" control. Do not force on every call - you
        /// will hit the server's rate limit.
        /// </param>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        /// <returns>The licensing outcome. Check <see cref="LicenseResult.IsValid"/>.</returns>
        public async Task<LicenseResult> CheckAsync(
            bool force = false, CancellationToken cancellationToken = default)
        {
            if (!force)
            {
                var cached = FreshCachedResult();
                if (cached != null)
                {
                    _last = cached;
                    return cached;
                }
            }

            return await CallAndStoreAsync("check", cancellationToken).ConfigureAwait(false);
        }

        /// <summary>
        /// Alias for <see cref="CheckAsync"/>, for call sites that read better
        /// as "validate".
        /// </summary>
        /// <param name="force">Skip the cache and always contact the server.</param>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        /// <returns>The licensing outcome.</returns>
        public Task<LicenseResult> ValidateAsync(
            bool force = false, CancellationToken cancellationToken = default) =>
            CheckAsync(force, cancellationToken);

        /// <summary>
        /// Whether an entitlement is granted, checking in first if needed.
        /// </summary>
        /// <remarks>
        /// Convenient for a one-off feature gate. If you are testing several
        /// features in the same operation, await <see cref="CurrentAsync"/>
        /// once and call <see cref="LicenseResult.HasFeature"/> on the result.
        /// </remarks>
        /// <param name="slug">The feature slug configured on the server.</param>
        /// <returns>True when the current license grants that feature.</returns>
        public async Task<bool> HasFeatureAsync(string slug) =>
            (await CurrentAsync().ConfigureAwait(false)).HasFeature(slug);

        /// <summary>
        /// Whether the license has passed its expiry date, checking in if needed.
        /// </summary>
        /// <returns>True when the license is expired.</returns>
        public async Task<bool> IsExpiredAsync()
        {
            var result = await CurrentAsync().ConfigureAwait(false);
            return result.ErrorCode == "LICENSE_EXPIRED" || result.Status == "expired";
        }

        /// <summary>
        /// The most recent result, performing a check first if none exists.
        /// </summary>
        /// <remarks>
        /// The convenience helpers above funnel through here, so several of
        /// them in one operation cost at most one check between them.
        /// </remarks>
        /// <returns>The current licensing outcome.</returns>
        public async Task<LicenseResult> CurrentAsync() =>
            _last ??= await CheckAsync().ConfigureAwait(false);

        /// <summary>
        /// Discard the cached answer and its offline token.
        /// </summary>
        /// <remarks>
        /// Call this when the customer enters a different license key, so the
        /// previous license's cached answer cannot be reused, or from a
        /// "refresh license" control.
        /// <para>
        /// This deliberately does NOT remove the installation secret, private
        /// key or installation id. Those are identity, not cache: deleting them
        /// would make the next check-in look like a brand-new installation and
        /// consume another activation slot.
        /// </para>
        /// </remarks>
        public void ClearCache()
        {
            if (!string.IsNullOrEmpty(_options.CacheFile) && File.Exists(_options.CacheFile))
            {
                try { File.Delete(_options.CacheFile); } catch (IOException) { }
            }
        }

        // ---------------------------------------------------------------------
        // Transport
        // ---------------------------------------------------------------------

        /// <summary>Perform a call and persist whatever it returns.</summary>
        /// <param name="endpoint">"activate" or "check".</param>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        private async Task<LicenseResult> CallAndStoreAsync(
            string endpoint, CancellationToken cancellationToken)
        {
            var result = await CallAsync(endpoint, cancellationToken).ConfigureAwait(false);
            Store(result);
            return result;
        }

        /// <summary>
        /// Perform an API call, with retries, falling back offline if it fails.
        /// </summary>
        /// <remarks>
        /// Transport failures and 5xx responses are retried with a linear
        /// backoff; a licensing refusal is never retried, because the answer
        /// will not change. When every attempt fails, the signed offline token
        /// is used instead.
        /// <para>
        /// A successful response that also reports <c>needs_activation</c> is
        /// deliberately converted into an <em>invalid</em> result. The key may
        /// be genuine, but an installation that has not claimed a slot is not a
        /// licensed installation, and <c>if (result.IsValid)</c> is the code
        /// integrators actually write - it must not run unactivated copies.
        /// </para>
        /// </remarks>
        /// <param name="endpoint">"activate" or "check".</param>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        /// <returns>The server's decision, or the offline fallback.</returns>
        private async Task<LicenseResult> CallAsync(
            string endpoint, CancellationToken cancellationToken)
        {
            var body = JsonSerializer.Serialize(BuildEnvironment());
            var attempts = Math.Max(1, _options.Retries + 1);
            var lastError = "The licensing server could not be reached.";

            for (var attempt = 1; attempt <= attempts; attempt++)
            {
                var (status, raw, error) =
                    await SendAsync(endpoint, body, cancellationToken).ConfigureAwait(false);

                if (error != null)
                {
                    lastError = error;
                    if (attempt < attempts)
                    {
                        await Task.Delay(
                            TimeSpan.FromMilliseconds(_options.RetryDelay.TotalMilliseconds * attempt),
                            cancellationToken).ConfigureAwait(false);
                        continue;
                    }
                    break;
                }

                Dictionary<string, JsonElement>? decoded;
                try
                {
                    decoded = JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(raw);
                }
                catch (JsonException)
                {
                    lastError = "The licensing server returned an unreadable response.";
                    if (attempt < attempts) { continue; }
                    break;
                }

                if (decoded == null)
                {
                    lastError = "The licensing server returned an unreadable response.";
                    break;
                }

                if (decoded.TryGetValue("success", out var success)
                    && success.ValueKind == JsonValueKind.True)
                {
                    if (decoded.TryGetValue("needs_activation", out var unbound)
                        && unbound.ValueKind == JsonValueKind.True)
                    {
                        return new LicenseResult(false, decoded, "ACTIVATION_NOT_FOUND",
                            "This installation is not activated for this license. "
                            + "Call ActivateAsync() first.", "remote");
                    }

                    return new LicenseResult(true, decoded, source: "remote");
                }

                if (status >= 500 && attempt < attempts)
                {
                    lastError = "The licensing server is temporarily unavailable.";
                    continue;
                }

                var code = "INVALID_REQUEST";
                var message = "The license could not be verified.";
                if (decoded.TryGetValue("error", out var errorBlock)
                    && errorBlock.ValueKind == JsonValueKind.Object)
                {
                    if (errorBlock.TryGetProperty("code", out var c) && c.ValueKind == JsonValueKind.String)
                    {
                        code = c.GetString()!;
                    }
                    if (errorBlock.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String)
                    {
                        message = m.GetString()!;
                    }
                }

                return new LicenseResult(false, decoded, code, message, "remote");
            }

            return OfflineFallback(lastError);
        }

        /// <summary>
        /// Send one signed HTTP request to the licensing server.
        /// </summary>
        /// <remarks>
        /// Two independent proofs travel with every request:
        /// <list type="bullet">
        /// <item><description>
        /// The <em>credential</em> signature (X-LF-Signature) proves the
        /// request came from your product, using the API key and secret you
        /// configured.
        /// </description></item>
        /// <item><description>
        /// The <em>installation</em> proof (X-LF-Install-Proof) proves it came
        /// from this specific installation. Where a keypair was registered at
        /// activation it is a signature over the canonical string; otherwise it
        /// is an HMAC keyed with the installation secret. Neither credential is
        /// ever transmitted. Without this proof the server treats the caller as
        /// an installation it has not seen, which is what makes the activation
        /// limit meaningful.
        /// </description></item>
        /// </list>
        /// Both proofs cover the same canonical string - method, endpoint,
        /// timestamp, single-use nonce and a SHA-256 of the body - so the
        /// timestamp and nonce headers are always sent, and both inherit replay
        /// protection from them.
        /// <para>
        /// A registered keypair supersedes the shared secret rather than
        /// falling back to it: once a key exists the server checks the
        /// signature first, so presenting the superseded secret after a signing
        /// failure would simply be refused.
        /// </para>
        /// <para>
        /// A non-2xx status is not a transport failure - the server answers
        /// refusals with a body too - so it is returned as a normal response
        /// for the caller to decode.
        /// </para>
        /// </remarks>
        /// <param name="endpoint">The API endpoint path segment.</param>
        /// <param name="body">The already-serialised JSON request body.</param>
        /// <param name="cancellationToken">Cancels the HTTP call.</param>
        /// <returns>
        /// The HTTP status, the response body, and a transport error message
        /// (null when the server answered at all).
        /// </returns>
        private async Task<(int Status, string Body, string? Error)> SendAsync(
            string endpoint, string body, CancellationToken cancellationToken)
        {
            var url = $"{_options.LicenseServer}/license/{endpoint}";
            var timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds()
                .ToString(CultureInfo.InvariantCulture);
            var nonce = Guid.NewGuid().ToString("N");

            using var request = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json"),
            };
            request.Headers.TryAddWithoutValidation(
                "User-Agent", $"LicenseForge-SDK/{SdkVersion} .NET/{Environment.Version}");

            request.Headers.TryAddWithoutValidation("X-LF-Timestamp", timestamp);
            request.Headers.TryAddWithoutValidation("X-LF-Nonce", nonce);

            if (!string.IsNullOrEmpty(_options.ApiKey) && !string.IsNullOrEmpty(_options.ApiSecret))
            {
                request.Headers.TryAddWithoutValidation("X-LF-Key", _options.ApiKey);
                request.Headers.TryAddWithoutValidation(
                    "X-LF-Signature", Sign("POST", endpoint, timestamp, nonce, body));
            }

            var installSecret = InstallSecret();
            var installKey = InstallPrivateKey();
            if (!string.IsNullOrEmpty(installSecret) || !string.IsNullOrEmpty(installKey))
            {
                using var sha = SHA256.Create();
                var canonical = string.Join("\n", new[]
                {
                    "POST",
                    endpoint.ToLowerInvariant(),
                    timestamp,
                    nonce,
                    ToHex(sha.ComputeHash(Encoding.UTF8.GetBytes(body))),
                });

                var proof = string.IsNullOrEmpty(installKey)
                    ? null
                    : SignWithInstallKey(canonical, installKey!);

                if (proof == null && !string.IsNullOrEmpty(installSecret))
                {
                    using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(installSecret));
                    proof = ToHex(hmac.ComputeHash(Encoding.UTF8.GetBytes(canonical)));
                }

                if (proof != null)
                {
                    request.Headers.TryAddWithoutValidation("X-LF-Install-Proof", proof);
                }
            }

            try
            {
                using var response = await _http.SendAsync(request, cancellationToken)
                    .ConfigureAwait(false);
                var text = await response.Content.ReadAsStringAsync().ConfigureAwait(false);

                return ((int)response.StatusCode, text, null);
            }
            catch (HttpRequestException exception)
            {
                return (0, "", $"Could not reach the licensing server: {exception.Message}");
            }
            catch (TaskCanceledException)
            {
                return (0, "", "The licensing server did not respond in time.");
            }
        }

        /// <summary>
        /// Compute the API credential signature for a request.
        /// </summary>
        /// <remarks>
        /// The canonical string is a newline-joined tuple of the uppercased
        /// method, the lowercased endpoint, the timestamp, the nonce and the
        /// hex SHA-256 of the body. This mirrors the server implementation
        /// exactly; if you port this SDK to another language, it is the
        /// function to match first.
        /// </remarks>
        /// <param name="method">The HTTP method.</param>
        /// <param name="endpoint">The endpoint path segment.</param>
        /// <param name="timestamp">Unix seconds, as a string.</param>
        /// <param name="nonce">A single-use random hex value.</param>
        /// <param name="body">The exact request body being sent.</param>
        /// <returns>The hex HMAC-SHA256 signature.</returns>
        private string Sign(string method, string endpoint, string timestamp, string nonce, string body)
        {
            using var sha = SHA256.Create();
            var bodyHash = ToHex(sha.ComputeHash(Encoding.UTF8.GetBytes(body)));

            var canonical = string.Join("\n", new[]
            {
                method.ToUpperInvariant(),
                endpoint.ToLowerInvariant(),
                timestamp,
                nonce,
                bodyHash,
            });

            using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(_options.ApiSecret));
            return ToHex(hmac.ComputeHash(Encoding.UTF8.GetBytes(canonical)));
        }

        /// <summary>Render bytes as lowercase hexadecimal.</summary>
        /// <param name="bytes">The bytes to encode.</param>
        private static string ToHex(byte[] bytes)
        {
            var builder = new StringBuilder(bytes.Length * 2);
            foreach (var b in bytes)
            {
                builder.Append(b.ToString("x2", CultureInfo.InvariantCulture));
            }
            return builder.ToString();
        }

        // ---------------------------------------------------------------------
        // Offline validation
        // ---------------------------------------------------------------------

        /// <summary>
        /// Fall back to the last signed token when the server cannot be reached.
        /// The token is honoured for exactly as long as it allows, no longer.
        /// </summary>
        /// <param name="reason">
        /// The transport error, appended to the message shown to the customer
        /// when the fallback also fails.
        /// </param>
        private LicenseResult OfflineFallback(string reason)
        {
            return ResultFromSignedCache("offline", reason);
        }

        /// <summary>
        /// Build a licensing result from the <em>signed</em> token in the cache.
        /// </summary>
        /// <remarks>
        /// This is the heart of offline validation, and both cache-reading
        /// paths (a fresh cache hit, and the offline fallback) funnel through
        /// it so that neither can drift into trusting unsigned data.
        /// <para>
        /// The cache file sits on a machine its owner controls, so nothing read
        /// out of it may be believed on its own - only the token can be,
        /// because only the token is signed by a key the customer does not
        /// hold. Every field returned here comes from the payload, never from
        /// the cache entry around it.
        /// </para>
        /// <para>The checks applied, in order:</para>
        /// <list type="number">
        /// <item><description>A token exists and its signature verifies against PublicKey.</description></item>
        /// <item><description>It was issued for the configured license key - a payload signed for somebody else's key is still correctly signed, so the key it names is checked separately.</description></item>
        /// <item><description>Its format version is within [MinimumTokenVersion, SupportedTokenVersion].</description></item>
        /// <item><description>The system clock has not been wound back (see <see cref="ClockRollback"/>).</description></item>
        /// <item><description>The server-issued offline window has not closed.</description></item>
        /// <item><description>The license has not expired past its server-signed grace deadline.</description></item>
        /// <item><description>Its status is "active".</description></item>
        /// <item><description>It is not IP-locked - an IP binding can only be checked by the server, because the token carries the address the <em>server</em> observed and behind NAT, a proxy or CGNAT the client sees something else. Rather than silently skip it, an IP-locked license is denied an offline answer altogether.</description></item>
        /// <item><description>Product, version constraints, domain, directory and machine bindings all still hold.</description></item>
        /// <item><description>It is bound to this exact installation, and this installation can cryptographically prove it owns that binding.</description></item>
        /// </list>
        /// <para>
        /// Everything the server would have checked online is re-checked here,
        /// under the policy the payload itself carries. An offline path that
        /// enforces less than the online one is not a fallback, it is a bypass:
        /// pull the network cable and the weaker rules apply.
        /// </para>
        /// </remarks>
        /// <param name="source">The source label: "cache" or "offline".</param>
        /// <param name="reason">
        /// The transport failure, appended to messages where the customer needs
        /// to know the server was unreachable.
        /// </param>
        private LicenseResult ResultFromSignedCache(string source, string reason = "")
        {
            var cached = ReadCache();
            var token = cached != null
                        && cached.TryGetValue("offline_token", out var t)
                        && t.ValueKind == JsonValueKind.String
                ? t.GetString()
                : null;

            if (string.IsNullOrEmpty(token))
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE", reason, source);
            }

            var payload = VerifyOfflineToken(token!);
            if (payload == null)
            {
                return new LicenseResult(false, null, "SIGNATURE_INVALID",
                    "The cached license could not be verified.", source);
            }

            var payloadKey = ReadString(payload.Value, "license_key") ?? "";
            if (!FixedTimeEquals(payloadKey, _options.LicenseKey))
            {
                return new LicenseResult(false, null, "INVALID_LICENSE",
                    "The cached license belongs to a different key.", source);
            }

            var now = DateTimeOffset.UtcNow;

            // Version floor. Each "field missing, so assume the old default"
            // branch further down is a place where an old token would quietly
            // enforce the policy in force when it was signed rather than the
            // current one. Refusing costs one round trip and yields a current
            // token; the fallbacks stay in the code for deployments that lower
            // the floor deliberately.
            var version = payload.Value.TryGetProperty("token_version", out var declared)
                          && declared.ValueKind == JsonValueKind.Number
                          && declared.TryGetInt32(out var parsed)
                ? parsed
                : 1;

            if (_options.MinimumTokenVersion > 0 && version < _options.MinimumTokenVersion)
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE",
                    "This cached license predates the current licensing rules. "
                    + "Reconnect to refresh it.", source);
            }

            // Version ceiling, deliberately outside the floor's opt-out.
            // Lowering the floor is a choice to accept known older rules; it is
            // not a claim to understand newer ones.
            if (version > SupportedTokenVersion)
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE",
                    "This license was issued under newer rules than this software "
                    + "understands. Update the software, or reconnect for a compatible "
                    + "response.", source);
            }

            // Every deadline below is read against a clock the customer owns,
            // so the clock is judged before any of them are trusted.
            if (ClockRollback(payload.Value) is { } rollback)
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE", rollback, source);
            }

            // offline_until is a hard deadline set by the server, and a payload
            // without one has no offline permission at all.
            var offlineUntil = ReadTimestamp(payload.Value, "offline_until");
            if (offlineUntil == null || offlineUntil.Value < now)
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE",
                    ("The offline validity period has ended. " + reason).Trim(), source);
            }

            // Expiry is measured against the boundary the *server* signed.
            // _options.GracePeriod is only a fallback for tokens issued before
            // the server published grace_ends_at: how long an expired license
            // keeps working is licensing policy, and a local default would
            // grant grace to a product configured for none.
            var graceEndsAt = ReadTimestamp(payload.Value, "grace_ends_at");
            if (graceEndsAt != null)
            {
                if (graceEndsAt.Value < now)
                {
                    return new LicenseResult(false, null, "LICENSE_EXPIRED",
                        "The license expired.", source);
                }
            }
            else
            {
                var expiresAt = ReadTimestamp(payload.Value, "expires_at");
                if (expiresAt != null && expiresAt.Value < now.Subtract(_options.GracePeriod))
                {
                    return new LicenseResult(false, null, "LICENSE_EXPIRED",
                        "The license expired.", source);
                }
            }

            if (ReadString(payload.Value, "status") != "active")
            {
                return new LicenseResult(false, null, "INVALID_LICENSE",
                    "The license is not active.", source);
            }

            // The one binding this side cannot verify. See the remarks above
            // for why an IP-locked license is refused rather than waved through.
            if (ReadBool(payload.Value, "lock_ip"))
            {
                return new LicenseResult(false, null, "SERVICE_UNAVAILABLE",
                    ("This license is bound to an IP address, which only the licensing "
                     + "server can verify. " + reason).Trim(), source);
            }

            var boundProduct = ReadString(payload.Value, "product_id");
            if (!string.IsNullOrEmpty(boundProduct)
                && !string.IsNullOrEmpty(_options.ProductId)
                && !FixedTimeEquals(boundProduct!, _options.ProductId))
            {
                return new LicenseResult(false, null, "PRODUCT_MISMATCH",
                    "The cached license is for another product.", source);
            }

            var versionProblem = VersionProblem(
                _options.Version,
                ReadString(payload.Value, "min_version"),
                ReadString(payload.Value, "max_version"),
                ReadString(payload.Value, "allowed_versions"));
            if (versionProblem != null)
            {
                return new LicenseResult(false, null, "VERSION_NOT_SUPPORTED",
                    "This version is not covered: " + versionProblem, source);
            }

            // Domain binding, re-checked locally so a cached payload copied
            // elsewhere does not work there. Subdomain and www tolerance are
            // whatever the server signed. An absent flag means a token issued
            // before that policy was signed, and the permissive default keeps
            // those working.
            var stripWww = !payload.Value.TryGetProperty("allow_www_normalisation", out var wwwFlag)
                           || wwwFlag.ValueKind != JsonValueKind.False;

            var boundDomain = ReadString(payload.Value, "domain");
            var currentDomain = DetectDomain();
            if (ReadBool(payload.Value, "lock_domain") && !string.IsNullOrEmpty(currentDomain))
            {
                if (payload.Value.TryGetProperty("allow_local_domains", out var localFlag)
                    && localFlag.ValueKind == JsonValueKind.False
                    && IsLocalDomain(currentDomain))
                {
                    return new LicenseResult(false, null, "DOMAIN_MISMATCH",
                        "Development and local domains are not permitted for this license.", source);
                }

                if (!string.IsNullOrEmpty(boundDomain)
                    && !DomainMatches(boundDomain!, currentDomain,
                        ReadBool(payload.Value, "allow_subdomains"), stripWww))
                {
                    return new LicenseResult(false, null, "DOMAIN_MISMATCH",
                        "The cached license is bound to another domain.", source);
                }
            }

            // Install path, compared explicitly rather than left to whatever
            // each SDK folds into its machine identifier. This one folds in
            // nothing: MachineId() here is host name, MAC and OS, no path.
            var boundDirectory = ReadString(payload.Value, "directory");
            if (ReadBool(payload.Value, "lock_directory")
                && !string.IsNullOrEmpty(boundDirectory)
                && !FixedTimeEquals(NormalisePath(boundDirectory!), NormalisePath(DetectDirectory())))
            {
                return new LicenseResult(false, null, "DIRECTORY_MISMATCH",
                    "The cached license is bound to another directory.", source);
            }

            // Machine binding, so a token cannot be moved to a different host
            // that happens to serve the same domain.
            var boundMachine = ReadString(payload.Value, "machine_id");
            if (ReadBool(payload.Value, "lock_machine")
                && !string.IsNullOrEmpty(boundMachine)
                && !FixedTimeEquals(boundMachine!, MachineId()))
            {
                return new LicenseResult(false, null, "MACHINE_MISMATCH",
                    "The cached license is bound to another machine.", source);
            }

            // The activation slot itself is checked always, not as a lockable
            // policy: it is the token's identity. A payload with no
            // installation at all was issued to an unbound caller, which is not
            // a licensed installation.
            var boundInstallation = ReadString(payload.Value, "installation_id");
            if (string.IsNullOrEmpty(boundInstallation))
            {
                return new LicenseResult(false, null, "ACTIVATION_NOT_FOUND",
                    "The cached license is not bound to an activation.", source);
            }
            if (!FixedTimeEquals(boundInstallation!, InstallationId()))
            {
                return new LicenseResult(false, null, "MACHINE_MISMATCH",
                    "The cached license is bound to another installation.", source);
            }

            // Matching the installation id proves nothing on its own: the id is
            // a value this client generated and keeps in a file, so copying the
            // cache and that file to a second machine would reproduce a
            // "licensed" installation that had never proved anything. Online,
            // an installation is recognised by a proof computed from the
            // credential issued at activation; the checks below are that same
            // evidence, offline.
            //
            // An installation holding a registered keypair proves possession by
            // signing the token's own nonce and verifying the result against
            // the public key the server signed in - exactly as strong offline
            // as the HMAC form (this machine is the verifier either way) while
            // leaving the server nothing that could forge the binding.
            var bindingKey = ReadString(payload.Value, "installation_key");
            var binding = ReadString(payload.Value, "installation_binding");

            // Assert what token version 3 means, rather than assuming it.
            //
            // The two checks below each fire only when their own field is
            // present, so together they say "verify whichever binding you were
            // given" - not "a binding was given". The server always mints
            // exactly one, and the default floor rejects the formats predating
            // that guarantee. But that leaves the invariant living in the floor
            // rather than in the version: lowering the floor is a deliberate
            // choice to accept older *known* shapes, and it should not also
            // make a malformed v3 token acceptable. Exactly one binding, never
            // both, never neither.
            if (version >= 3
                && string.IsNullOrEmpty(bindingKey) == string.IsNullOrEmpty(binding))
            {
                return new LicenseResult(false, null, "ACTIVATION_NOT_FOUND",
                    "This cached license is missing its installation binding. "
                    + "Reconnect to refresh it.", source);
            }

            if (!string.IsNullOrEmpty(bindingKey))
            {
                var privateKey = InstallPrivateKey();
                if (string.IsNullOrEmpty(privateKey))
                {
                    return new LicenseResult(false, null, "ACTIVATION_NOT_FOUND",
                        "This installation cannot prove it owns the cached license. "
                        + "Reconnect to activate.", source);
                }

                var nonce = ReadString(payload.Value, "nonce") ?? "";
                var signed = SignWithInstallKey(nonce, privateKey);

                if (signed == null || !VerifyWithInstallKey(nonce, signed, bindingKey!))
                {
                    return new LicenseResult(false, null, "MACHINE_MISMATCH",
                        "The cached license belongs to another installation.", source);
                }
            }

            if (string.IsNullOrEmpty(bindingKey) && !string.IsNullOrEmpty(binding))
            {
                var secret = InstallSecret();
                if (string.IsNullOrEmpty(secret))
                {
                    return new LicenseResult(false, null, "ACTIVATION_NOT_FOUND",
                        "This installation cannot prove it owns the cached license. "
                        + "Reconnect to activate.", source);
                }

                using var mac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
                var expected = ToHex(mac.ComputeHash(
                    Encoding.UTF8.GetBytes(ReadString(payload.Value, "nonce") ?? "")));

                if (!FixedTimeEquals(binding!, expected))
                {
                    return new LicenseResult(false, null, "MACHINE_MISMATCH",
                        "The cached license belongs to another installation.", source);
                }
            }

            var data = new Dictionary<string, JsonElement> { ["license"] = payload.Value };
            return new LicenseResult(true, data, source: source);
        }

        /// <summary>
        /// Verify a signed offline token and return its payload.
        /// </summary>
        /// <remarks>
        /// The token format is
        /// <c>base64url(payloadJson).base64url(signature)</c>, and the
        /// signature covers the <em>encoded</em> payload segment, so
        /// re-encoding is never required and cannot introduce a mismatch.
        /// <para>
        /// This method is public so you can verify a token obtained by other
        /// means - for example one pasted in by a customer performing a manual
        /// air-gapped activation.
        /// </para>
        /// <para>
        /// Returns null whenever the token cannot be <em>proven</em> genuine,
        /// which includes the case where no verifier is available for the
        /// algorithm (Ed25519 without an
        /// <see cref="LicenseOptions.Ed25519Verifier"/>) and the case where no
        /// public key is configured at all. Refusing to check is never treated
        /// as passing: returning the payload unverified would make the whole
        /// offline path trivially bypassable.
        /// </para>
        /// </remarks>
        /// <param name="token">The token in <c>payload.signature</c> form.</param>
        /// <returns>The decoded payload if the signature is genuine, else null.</returns>
        public JsonElement? VerifyOfflineToken(string token)
        {
            if (string.IsNullOrEmpty(_options.PublicKey))
            {
                return null;
            }

            var parts = token.Split('.');
            if (parts.Length != 2)
            {
                return null;
            }

            byte[] payloadBytes;
            byte[] signature;
            JsonElement payload;
            try
            {
                payloadBytes = Base64UrlDecode(parts[0]);
                signature = Base64UrlDecode(parts[1]);
                payload = JsonSerializer.Deserialize<JsonElement>(payloadBytes);
            }
            catch (Exception exception) when (exception is FormatException or JsonException)
            {
                return null;
            }

            if (signature.Length == 0 || payload.ValueKind != JsonValueKind.Object)
            {
                return null;
            }

            var algorithm = ReadString(payload, "_algorithm") ?? _options.PublicKeyAlgorithm;
            var message = Encoding.ASCII.GetBytes(parts[0]);

            bool ok;
            try
            {
                if (algorithm == "ed25519")
                {
                    ok = _options.Ed25519Verifier?.Invoke(message, signature, _options.PublicKey)
                         ?? false;
                }
                else
                {
                    using var rsa = RSA.Create();
                    rsa.ImportFromPem(_options.PublicKey.ToCharArray());
                    ok = rsa.VerifyData(message, signature,
                        HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
                }
            }
            catch (Exception)
            {
                return null;
            }

            return ok ? payload : (JsonElement?)null;
        }

        // ---------------------------------------------------------------------
        // Per-installation identity
        // ---------------------------------------------------------------------

        /// <summary>
        /// The per-installation secret issued at activation, or "" if this
        /// installation is not activated.
        /// </summary>
        /// <remarks>
        /// If <see cref="LicenseOptions.InstallSecret"/> is set it wins, which
        /// lets you manage installation identity yourself. Otherwise the secret
        /// is read from its own file beside the cache, and memoised.
        /// <para>
        /// It is stored separately from the result cache because the two mean
        /// different things: the cache is a disposable answer, this is
        /// identity. <see cref="ClearCache"/> must be able to force a fresh
        /// check without de-activating the installation and costing the
        /// customer a slot.
        /// </para>
        /// </remarks>
        public string InstallSecret()
        {
            if (!string.IsNullOrEmpty(_options.InstallSecret))
            {
                return _options.InstallSecret!;
            }
            if (_installSecret != null)
            {
                return _installSecret;
            }

            _installSecret = "";
            var path = InstallSecretFile();
            if (path.Length > 0 && File.Exists(path))
            {
                try
                {
                    _installSecret = File.ReadAllText(path).Trim();
                }
                catch (IOException)
                {
                    _installSecret = "";
                }
            }

            return _installSecret;
        }

        /// <summary>
        /// Persist the installation secret returned by a successful activation.
        /// </summary>
        /// <remarks>
        /// Failure is swallowed: there is nothing useful to do about it beyond
        /// not crashing the caller, though the consequence is real - an
        /// unstored secret means the next check-in looks like a new
        /// installation and spends another activation slot.
        /// </remarks>
        /// <param name="secret">The secret issued by the server.</param>
        private void StoreInstallSecret(string secret)
        {
            var path = InstallSecretFile();
            if (path.Length == 0 || string.IsNullOrEmpty(secret))
            {
                return;
            }

            _installSecret = secret;
            try
            {
                var directory = Path.GetDirectoryName(path);
                if (!string.IsNullOrEmpty(directory) && !System.IO.Directory.Exists(directory))
                {
                    System.IO.Directory.CreateDirectory(directory!);
                }
                File.WriteAllText(path, secret);
            }
            catch (IOException)
            {
            }
        }

        /// <summary>
        /// Refuse a cache path that looks web-served.
        /// </summary>
        /// <remarks>
        /// The cache holds the license key and the signed offline token, and
        /// beside it sit the installation secret and private key. A deployment
        /// that puts those under a document root publishes them, and an
        /// .htaccess file is no defence on nginx.
        /// <para>
        /// .NET has no equivalent of PHP's DOCUMENT_ROOT, so the directory is
        /// matched against the paths web servers actually serve from. That is a
        /// heuristic, and it is why
        /// <see cref="LicenseOptions.CacheAllowWebRoot"/> exists: this check
        /// catches the obvious mistake rather than certifying a path as safe. A
        /// path it accepts is not thereby proven private.
        /// </para>
        /// </remarks>
        /// <exception cref="LicenseException">
        /// If the cache directory contains a marker of a commonly published
        /// location.
        /// </exception>
        private void AssertCacheIsPrivate()
        {
            if (string.IsNullOrEmpty(_options.CacheFile) || _options.CacheAllowWebRoot)
            {
                return;
            }

            var directory = Path.GetDirectoryName(Path.GetFullPath(_options.CacheFile!)) ?? "";
            var probe = directory.Replace('\\', '/').ToLowerInvariant().TrimEnd('/') + "/";

            // Evaluated in order, because the two kinds of marker mean
            // different things. These name a published directory outright.
            var served = new[]
            {
                "/var/www/", "/srv/www/", "/usr/share/nginx/", "/www/", "/htdocs/",
                "/public_html/", "/public/", "/wwwroot/", "/inetpub/",
            };

            var hit = served.FirstOrDefault(candidate => probe.Contains(candidate));

            // "/home/" is far too broad to name on its own: it is only
            // interesting when the path also looks published. It is therefore
            // checked *after* the explicit markers, never as part of the same
            // list - a combined list takes the first match in list order, so
            // /home/customer/wwwroot/ would match "/home/", fail the
            // public_html test, and be waved through despite containing a
            // marker the list already knew about.
            if (hit == null && probe.Contains("/home/") && probe.Contains("public_html"))
            {
                hit = "/home/";
            }

            if (hit == null)
            {
                return;
            }

            throw new LicenseException(
                $"CacheFile ({_options.CacheFile}) is inside a directory that is commonly "
                + $"web-served ({hit}), where it may be downloadable. It holds the license key "
                + "and the signed offline token, and the installation secret and private key sit "
                + "beside it. Move it outside the document root, or set CacheAllowWebRoot to true "
                + "if you have confirmed the directory is not served.");
        }

        /// <summary>
        /// The path of the installation secret file, derived from the cache
        /// path, or "" when no cache file is configured.
        /// </summary>
        private string InstallSecretFile() =>
            string.IsNullOrEmpty(_options.CacheFile) ? "" : _options.CacheFile + ".install";

        /// <summary>
        /// The path of the installation private key file, or "" when no cache
        /// file is configured.
        /// </summary>
        private string InstallKeyFile() =>
            InstallSecretFile() is { Length: > 0 } file ? file + ".key" : "";

        /// <summary>Memoised installation private key; "" means "looked, none stored".</summary>
        private string? _installKey;

        /// <summary>
        /// The private half of this installation's registered keypair, or "".
        /// </summary>
        /// <remarks>
        /// Stored beside the secret and treated the same way: identity rather
        /// than cache, so <see cref="ClearCache"/> deliberately leaves it
        /// alone. It never leaves the machine - only the public half was ever
        /// sent, and only once, at activation.
        /// </remarks>
        private string InstallPrivateKey()
        {
            if (_installKey != null)
            {
                return _installKey;
            }

            _installKey = "";
            var path = InstallKeyFile();
            if (path.Length > 0 && File.Exists(path))
            {
                try { _installKey = File.ReadAllText(path).Trim(); }
                catch (IOException) { _installKey = ""; }
            }

            return _installKey;
        }

        /// <summary>
        /// Generate this installation's keypair, ready for the public half to
        /// be registered with the next request.
        /// </summary>
        /// <remarks>
        /// RSA-2048, because .NET has no Ed25519 primitive - the same reason
        /// offline Ed25519 verification is pluggable here. Called once, at
        /// activation, and only where there is somewhere durable to put the
        /// private key: an installation that cannot store it would register a
        /// key it could never sign with and lock itself out of its own
        /// activation.
        /// <para>
        /// Failure is not fatal. The client simply stays on the shared secret;
        /// a keypair improves identity rather than being required for it.
        /// </para>
        /// <para>
        /// <strong>Concurrency.</strong> Several processes may activate at the
        /// same moment. The key is written to a unique temporary file and then
        /// placed with the two-argument <c>File.Move</c>, which throws when the
        /// destination exists - so exactly one process places the key and the
        /// rest adopt it. Without that, two processes could each register their
        /// own public key, leaving the server holding one and the surviving
        /// private half being the other: an installation that can never prove
        /// itself again, failing silently until the next check-in.
        /// </para>
        /// <para>
        /// The key is always written to disk <em>before</em> it is offered to
        /// the server, and only ever the key that actually reached disk. The
        /// reverse order loses the ability to sign for an installation the
        /// server has already moved onto keys, which is unrecoverable without
        /// an admin reset.
        /// </para>
        /// </remarks>
        private void GenerateInstallKey()
        {
            var path = InstallKeyFile();
            if (path.Length == 0 || InstallPrivateKey().Length > 0)
            {
                return;
            }

            try
            {
                using var rsa = RSA.Create(2048);
                var privatePem = new string(PemEncode(rsa.ExportPkcs8PrivateKey(), "PRIVATE KEY"));
                var publicPem = new string(PemEncode(rsa.ExportSubjectPublicKeyInfo(), "PUBLIC KEY"));

                var directory = Path.GetDirectoryName(path);
                if (!string.IsNullOrEmpty(directory) && !System.IO.Directory.Exists(directory))
                {
                    System.IO.Directory.CreateDirectory(directory!);
                }

                // Written to a private temporary name first, so the final path
                // never exists half-written. A reader deriving a public key
                // from half a PEM gets nothing, and the activation would fail
                // intermittently under concurrency - the worst way to fail.
                var temp = path + "." + Guid.NewGuid().ToString("N").Substring(0, 12) + ".tmp";

                try
                {
                    using (var stream = new FileStream(
                        temp, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                    using (var writer = new StreamWriter(stream))
                    {
                        writer.Write(privatePem);
                        writer.Flush();
                        stream.Flush(true);
                    }
                }
                catch (IOException)
                {
                    // The temporary name is unique to this process, so this
                    // means the directory is not writable rather than that
                    // someone else won.
                    try { File.Delete(temp); } catch (IOException) { }

                    return;
                }

                // Fast path: another process finished while this one was
                // writing. Its key is on disk and possibly already registered,
                // so it wins and this one is discarded.
                if (File.Exists(path))
                {
                    try { File.Delete(temp); } catch (IOException) { }

                    var existing = File.ReadAllText(path).Trim();
                    _installKey = existing;
                    _pendingPublicKey = PublicKeyFromPrivate(existing);

                    return;
                }

                // The two-argument overload is load-bearing and must NOT be
                // changed to File.Move(temp, path, overwrite: true).
                //
                // This overload throws when the destination exists, and that is
                // what makes the placement exclusive rather than merely atomic:
                // exactly one process places the key and the rest land in the
                // catch below and adopt it. An overwriting move would let every
                // racing process succeed, so the last to land would own the
                // disk while the last to register owned the server - and those
                // orders are independent, so the surviving private key and the
                // registered public key could come from different processes.
                // The PHP and Python SDKs reach the same guarantee through
                // link()/os.link(), for the same reason.
                try
                {
                    File.Move(temp, path);
                }
                catch (IOException)
                {
                    try { File.Delete(temp); } catch (IOException) { }

                    // Lost the move: adopt whatever landed there, because that
                    // is the key the winner is registering with the server.
                    if (File.Exists(path))
                    {
                        var winner = File.ReadAllText(path).Trim();
                        _installKey = winner;
                        _pendingPublicKey = PublicKeyFromPrivate(winner);
                    }

                    return;
                }

                _installKey = privatePem;
                _pendingPublicKey = publicPem;
            }
            catch (Exception exception) when (exception is IOException or CryptographicException)
            {
                _installKey = "";
                _pendingPublicKey = null;
            }
        }

        /// <summary>
        /// The public key awaiting registration, set by
        /// <see cref="GenerateInstallKey"/> and sent with the activation
        /// request that follows it. Null on every other call.
        /// </summary>
        private string? _pendingPublicKey;

        /// <summary>
        /// Wrap DER bytes in a PEM envelope with the given label, wrapped at
        /// 64 characters per line.
        /// </summary>
        /// <param name="der">The DER-encoded key bytes.</param>
        /// <param name="label">The PEM label, e.g. "PUBLIC KEY".</param>
        private static char[] PemEncode(byte[] der, string label)
        {
            var body = Convert.ToBase64String(der);
            var builder = new StringBuilder();
            builder.Append("-----BEGIN ").Append(label).Append("-----\n");
            for (var i = 0; i < body.Length; i += 64)
            {
                builder.Append(body, i, Math.Min(64, body.Length - i)).Append('\n');
            }
            builder.Append("-----END ").Append(label).Append("-----\n");

            return builder.ToString().ToCharArray();
        }

        /// <summary>
        /// Verify a signature against a public key.
        /// </summary>
        /// <remarks>
        /// Only ever used against this installation's own key, to confirm it
        /// still holds the matching private half - never to decide whether a
        /// payload is genuine, which is the offline token signature's job,
        /// handled by <see cref="VerifyOfflineToken"/>.
        /// <para>
        /// RSA only: .NET has no Ed25519 primitive, and a token bound to an
        /// Ed25519 installation key was issued to a client that is not this one.
        /// </para>
        /// </remarks>
        /// <param name="message">The signed message.</param>
        /// <param name="signatureB64Url">The signature, unpadded base64url.</param>
        /// <param name="publicKeyPem">The PEM public key to verify against.</param>
        /// <returns>True only when the signature verifies; any failure is false.</returns>
        private static bool VerifyWithInstallKey(string message, string signatureB64Url, string publicKeyPem)
        {
            try
            {
                var padded = signatureB64Url.Replace('-', '+').Replace('_', '/');
                padded += new string('=', (4 - (padded.Length % 4)) % 4);
                var signature = Convert.FromBase64String(padded);

                using var rsa = RSA.Create();
                rsa.ImportFromPem(publicKeyPem.ToCharArray());

                return rsa.VerifyData(
                    Encoding.UTF8.GetBytes(message),
                    signature,
                    HashAlgorithmName.SHA256,
                    RSASignaturePadding.Pkcs1);
            }
            catch (Exception exception) when (exception is CryptographicException or ArgumentException or FormatException)
            {
                return false;
            }
        }

        /// <summary>
        /// Derive the public half of a private key already on disk, as PEM.
        /// </summary>
        /// <remarks>
        /// Needed only by the loser of a first-activation race, which must
        /// register the key that actually reached disk rather than the one it
        /// generated - and all it holds at that point is the winner's private
        /// half.
        /// </remarks>
        /// <param name="privateKeyPem">The PEM private key.</param>
        /// <returns>The PEM public key, or null if it could not be derived.</returns>
        private static string? PublicKeyFromPrivate(string privateKeyPem)
        {
            if (string.IsNullOrEmpty(privateKeyPem))
            {
                return null;
            }

            try
            {
                using var rsa = RSA.Create();
                rsa.ImportFromPem(privateKeyPem.ToCharArray());

                return new string(PemEncode(rsa.ExportSubjectPublicKeyInfo(), "PUBLIC KEY"));
            }
            catch (Exception exception) when (exception is CryptographicException or ArgumentException)
            {
                return null;
            }
        }

        /// <summary>
        /// Sign a canonical string with this installation's private key.
        /// </summary>
        /// <remarks>
        /// The result is base64url rather than hex: a 2048-bit RSA signature is
        /// 512 hex characters, and base64url keeps the HTTP header a reasonable
        /// size. The server accepts either encoding, since HMAC proofs remain
        /// hex.
        /// </remarks>
        /// <param name="canonical">The exact string to sign.</param>
        /// <param name="privateKeyPem">The PEM private key.</param>
        /// <returns>
        /// The unpadded base64url signature, or null if it could not sign -
        /// which the caller treats as "no key" rather than as a failure.
        /// </returns>
        private static string? SignWithInstallKey(string canonical, string privateKeyPem)
        {
            try
            {
                using var rsa = RSA.Create();
                rsa.ImportFromPem(privateKeyPem.ToCharArray());
                var signature = rsa.SignData(
                    Encoding.UTF8.GetBytes(canonical),
                    HashAlgorithmName.SHA256,
                    RSASignaturePadding.Pkcs1);

                return Convert.ToBase64String(signature)
                    .TrimEnd('=').Replace('+', '-').Replace('/', '_');
            }
            catch (Exception exception) when (exception is CryptographicException or ArgumentException)
            {
                return null;
            }
        }

        // ---------------------------------------------------------------------
        // Cache
        // ---------------------------------------------------------------------

        /// <summary>
        /// Record the outcome of a server call.
        /// </summary>
        /// <remarks>
        /// The installation secret, which is returned exactly once by
        /// <see cref="ActivateAsync"/>, is persisted before anything else can
        /// fail. Losing it would make the next check-in look like a new
        /// installation and spend another activation slot.
        /// <para>Only a valid result updates the cache.</para>
        /// </remarks>
        /// <param name="result">The result to record.</param>
        private void Store(LicenseResult result)
        {
            _last = result;

            if (result.Data.TryGetValue("installation", out var installation)
                && installation.ValueKind == JsonValueKind.Object
                && installation.TryGetProperty("secret", out var secret)
                && secret.ValueKind == JsonValueKind.String)
            {
                StoreInstallSecret(secret.GetString() ?? "");
            }

            if (string.IsNullOrEmpty(_options.CacheFile) || !result.IsValid)
            {
                return;
            }

            string? offlineToken = null;
            if (result.Data.TryGetValue("offline", out var offline)
                && offline.ValueKind == JsonValueKind.Object
                && offline.TryGetProperty("token", out var token)
                && token.ValueKind == JsonValueKind.String)
            {
                offlineToken = token.GetString();
            }

            // Keep the previous token if this response carried none, so a
            // server that briefly cannot sign does not strip the installation
            // of its offline capability - and, since the cache is only honoured
            // when a token verifies, of its cache along with it.
            if (string.IsNullOrEmpty(offlineToken)
                && ReadCache() is { } previous
                && previous.TryGetValue("offline_token", out var prior)
                && prior.ValueKind == JsonValueKind.String)
            {
                offlineToken = prior.GetString();
            }

            var record = new Dictionary<string, object?>
            {
                ["checked_at"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
                // Advanced on every successful check and never lowered, so a
                // clock wound back afterwards is detectable. See ClockRollback.
                ["seen_at"] = HighWaterMark(
                    string.IsNullOrEmpty(offlineToken) ? null : VerifyOfflineToken(offlineToken!)),
                ["valid"] = true,
                ["data"] = result.Data,
                ["offline_token"] = offlineToken,
            };

            WriteCache(record);
        }

        /// <summary>
        /// Detect a system clock wound back to revive an expired token.
        /// </summary>
        /// <remarks>
        /// Every offline deadline is read against a clock on hardware the
        /// customer owns, so an expired token can be made current again simply
        /// by setting the date back. This cannot be <em>solved</em> on a
        /// machine its owner controls - there is no trustworthy time source to
        /// appeal to - but it can be made expensive and evident, in two layers.
        /// <para>
        /// The first layer is free and unforgeable: "issued_at" sits inside the
        /// signature, so it cannot be lowered without invalidating the token,
        /// and a newer token can only come from the server. A clock reading
        /// earlier than the moment the token was minted is therefore lying,
        /// with no legitimate explanation beyond ordinary skew.
        /// </para>
        /// <para>
        /// That alone is not enough, since winding back to a point
        /// <em>after</em> issue but before "offline_until" revives the token
        /// while satisfying the first check. So the second layer keeps a
        /// high-water mark of the latest time this installation has ever seen -
        /// signed "issued_at" values and the local clock at each successful
        /// check - and refuses when the clock reads meaningfully behind it.
        /// </para>
        /// <para>
        /// The mark lives in the cache file, which the customer can also edit.
        /// That is a deliberate trade rather than an oversight: the mark sits
        /// beside the token it guards, so deleting the file to clear the
        /// tripwire discards the token too, and the installation must reach the
        /// server - which is exactly the outcome the check exists to force.
        /// Editing the mark down in place is the remaining hole, and no amount
        /// of local storage closes it. Treat this as raising cost, not as a
        /// control.
        /// </para>
        /// </remarks>
        /// <param name="payload">The verified offline token payload.</param>
        /// <returns>A reason to refuse, or null when the clock is credible.</returns>
        private string? ClockRollback(JsonElement payload)
        {
            var now = DateTimeOffset.UtcNow;

            var issuedAt = ReadTimestamp(payload, "issued_at");
            if (issuedAt != null && now < issuedAt.Value.Subtract(ClockTolerance))
            {
                return "The system clock is set earlier than this license was issued. "
                    + "Correct the clock, or connect to the server to re-check.";
            }

            var mark = CachedHighWaterMark();
            if (mark != null && now < mark.Value.Subtract(ClockTolerance))
            {
                return "The system clock has moved backwards since this license was last "
                    + "checked. Correct the clock, or connect to the server to re-check.";
            }

            return null;
        }

        /// <summary>
        /// Compute the latest moment this installation has evidence of.
        /// </summary>
        /// <remarks>
        /// Written to the cache on every successful check and consumed by
        /// <see cref="ClockRollback"/>. It never moves backwards, and it
        /// prefers the signed "issued_at" over the local clock where that is
        /// ahead: the server's word about the time is worth more than the
        /// customer's machine's, and it is the half an attacker cannot forge.
        /// </remarks>
        /// <param name="payload">The verified token payload, if any.</param>
        /// <returns>The new high-water mark, in Unix seconds.</returns>
        private long HighWaterMark(JsonElement? payload)
        {
            var mark = DateTimeOffset.UtcNow.ToUnixTimeSeconds();

            if (CachedHighWaterMark() is { } stored)
            {
                mark = Math.Max(mark, stored.ToUnixTimeSeconds());
            }

            if (payload != null && ReadTimestamp(payload.Value, "issued_at") is { } issuedAt)
            {
                mark = Math.Max(mark, issuedAt.ToUnixTimeSeconds());
            }

            return mark;
        }

        /// <summary>
        /// The high-water mark stored in the cache, or null if it is absent,
        /// malformed or non-positive.
        /// </summary>
        private DateTimeOffset? CachedHighWaterMark()
        {
            if (ReadCache() is { } cached
                && cached.TryGetValue("seen_at", out var seenAt)
                && seenAt.ValueKind == JsonValueKind.Number
                && seenAt.TryGetInt64(out var seconds)
                && seconds > 0)
            {
                return DateTimeOffset.FromUnixTimeSeconds(seconds);
            }

            return null;
        }

        /// <summary>
        /// Return the cached answer if it is still fresh, else null.
        /// </summary>
        /// <remarks>
        /// "checked_at" and "valid" decide only <em>whether</em> to skip the
        /// network call. They cannot decide the answer: returning the cache
        /// entry's own "data" would hand back whatever the file says, so
        /// setting "valid" to true and writing your own "data" would be a
        /// complete bypass requiring no forgery at all. The answer always comes
        /// from the signed payload instead, through the same verification the
        /// offline path uses.
        /// <para>
        /// A cache that fails verification returns null rather than a failure,
        /// so the caller goes to the network instead of hard-failing on a stale
        /// or damaged file.
        /// </para>
        /// </remarks>
        private LicenseResult? FreshCachedResult()
        {
            var cached = ReadCache();
            if (cached == null
                || !cached.TryGetValue("checked_at", out var checkedAt)
                || !checkedAt.TryGetInt64(out var seconds)
                || seconds <= 0)
            {
                return null;
            }

            var age = DateTimeOffset.UtcNow - DateTimeOffset.FromUnixTimeSeconds(seconds);
            if (age < TimeSpan.Zero || age > _options.CacheTtl)
            {
                return null;
            }

            var result = ResultFromSignedCache("cache");

            return result.IsValid ? result : null;
        }

        /// <summary>
        /// Read and decode the cache file.
        /// </summary>
        /// <remarks>
        /// Every failure mode - no file, unreadable, malformed JSON - returns
        /// null, because a missing or damaged cache is an ordinary condition
        /// that should send the caller to the network, not an error.
        /// </remarks>
        private Dictionary<string, JsonElement>? ReadCache()
        {
            if (string.IsNullOrEmpty(_options.CacheFile) || !File.Exists(_options.CacheFile))
            {
                return null;
            }

            try
            {
                return JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(
                    File.ReadAllText(_options.CacheFile));
            }
            catch (Exception exception) when (exception is IOException or JsonException)
            {
                return null;
            }
        }

        /// <summary>
        /// Write the cache file, creating its directory if needed.
        /// </summary>
        /// <remarks>
        /// Failure is swallowed: a cache that cannot be written costs a network
        /// round trip on the next call, and nothing more.
        /// </remarks>
        /// <param name="data">The cache entry to persist.</param>
        private void WriteCache(Dictionary<string, object?> data)
        {
            if (string.IsNullOrEmpty(_options.CacheFile))
            {
                return;
            }

            try
            {
                var directory = Path.GetDirectoryName(_options.CacheFile);
                if (!string.IsNullOrEmpty(directory) && !System.IO.Directory.Exists(directory))
                {
                    System.IO.Directory.CreateDirectory(directory!);
                }

                File.WriteAllText(_options.CacheFile, JsonSerializer.Serialize(data));
            }
            catch (IOException)
            {
            }
        }

        // ---------------------------------------------------------------------
        // Environment detection
        // ---------------------------------------------------------------------

        /// <summary>
        /// Assemble everything the server needs in order to make a decision.
        /// </summary>
        /// <remarks>
        /// Sent with both activate and check. Anything in
        /// <see cref="LicenseOptions.Metadata"/> is merged over the defaults,
        /// so you can attach your own diagnostics - a build number, a
        /// deployment name - and read them back on the activation record in the
        /// admin UI.
        /// </remarks>
        private Dictionary<string, object?> BuildEnvironment()
        {
            var metadata = new Dictionary<string, string>
            {
                ["dotnet"] = Environment.Version.ToString(),
                ["os"] = Environment.OSVersion.Platform.ToString(),
                ["sdk"] = SdkVersion,
            };
            foreach (var pair in _options.Metadata)
            {
                metadata[pair.Key] = pair.Value;
            }

            return new Dictionary<string, object?>
            {
                ["license_key"] = _options.LicenseKey,
                ["product_id"] = _options.ProductId,
                ["domain"] = DetectDomain(),
                ["directory"] = DetectDirectory(),
                ["machine_id"] = MachineId(),
                ["installation_id"] = InstallationId(),
                ["version"] = _options.Version,
                ["metadata"] = metadata,

                // Only ever non-null on the activation that generated it: the
                // server honours registration where identity is established,
                // and sending it on every check-in would be noise.
                ["install_public_key"] = _pendingPublicKey,
                ["install_key_algorithm"] = _pendingPublicKey == null ? null : "rsa-sha256",
            };
        }

        /// <summary>
        /// The hostname this installation reports, normalised.
        /// </summary>
        /// <remarks>
        /// <see cref="LicenseOptions.Domain"/> wins if set; otherwise the
        /// machine's fully-qualified name is used, falling back to the plain
        /// machine name if the network stack cannot be queried.
        /// <para>
        /// A leading "www." is deliberately preserved. Whether it is
        /// significant is the server's allow_www_normalisation policy;
        /// stripping it here would decide that question locally, and the server
        /// would never see the difference - so turning normalisation off would
        /// achieve nothing.
        /// </para>
        /// </remarks>
        public string DetectDomain()
        {
            if (_options.Domain != null)
            {
                return NormaliseDomain(_options.Domain, false);
            }

            try
            {
                var properties = IPGlobalProperties.GetIPGlobalProperties();
                var name = string.IsNullOrEmpty(properties.DomainName)
                    ? properties.HostName
                    : $"{properties.HostName}.{properties.DomainName}";

                return NormaliseDomain(name, false);
            }
            catch (Exception)
            {
                return NormaliseDomain(Environment.MachineName, false);
            }
        }

        /// <summary>
        /// The resolved installation path, used for directory binding.
        /// </summary>
        /// <remarks>
        /// <see cref="LicenseOptions.Directory"/> wins if set; otherwise the
        /// application base directory is used.
        /// <para>
        /// IMPORTANT for deployment systems: a versioned install folder changes
        /// on every update, so every release presents as a brand-new
        /// installation and consumes an activation slot. Set a stable
        /// <see cref="LicenseOptions.Directory"/>, or a fixed
        /// <see cref="LicenseOptions.InstallationId"/>, on such setups.
        /// </para>
        /// </remarks>
        public string DetectDirectory()
        {
            if (_options.Directory != null)
            {
                return _options.Directory;
            }

            try
            {
                return AppDomain.CurrentDomain.BaseDirectory ?? "";
            }
            catch (Exception)
            {
                return "";
            }
        }

        /// <summary>
        /// A stable identifier for this machine.
        /// </summary>
        /// <remarks>
        /// Derived from the host name, the first non-loopback adapter's MAC
        /// address and the OS platform, then hashed, so no raw hardware detail
        /// ever leaves the installation - which makes it safe to use without
        /// additional privacy disclosure. Memoised after the first call.
        /// <para>
        /// Some hosts refuse to enumerate network adapters; the host name alone
        /// is still a usable, if weaker, identifier, so that failure is
        /// tolerated rather than fatal.
        /// </para>
        /// <para>
        /// Override it with <see cref="LicenseOptions.MachineId"/> if your
        /// product already has a better notion of machine identity.
        /// </para>
        /// </remarks>
        /// <returns>A 32-character hex identifier.</returns>
        public string MachineId()
        {
            if (_options.MachineId != null)
            {
                return _options.MachineId;
            }
            if (_machineId != null)
            {
                return _machineId;
            }

            var mac = "";
            try
            {
                mac = NetworkInterface.GetAllNetworkInterfaces()
                    .Where(n => n.OperationalStatus == OperationalStatus.Up
                                && n.NetworkInterfaceType != NetworkInterfaceType.Loopback)
                    .Select(n => n.GetPhysicalAddress().ToString())
                    .FirstOrDefault(a => !string.IsNullOrEmpty(a)) ?? "";
            }
            catch (NetworkInformationException)
            {
            }

            var seed = $"{Environment.MachineName}|{mac}|{Environment.OSVersion.Platform}";
            using var sha = SHA256.Create();
            _machineId = ToHex(sha.ComputeHash(Encoding.UTF8.GetBytes(seed))).Substring(0, 32);

            return _machineId;
        }

        /// <summary>
        /// The identifier for this installation's activation slot.
        /// </summary>
        /// <remarks>
        /// Generated once as a GUID and stored beside the cache file (with a
        /// ".id" suffix) so it survives restarts. Without a cache file - or if
        /// the file cannot be written - it falls back to
        /// <see cref="MachineId"/>, which keeps behaviour sane for
        /// single-installation deployments.
        /// <para>
        /// Set <see cref="LicenseOptions.InstallationId"/> to keep one logical
        /// installation across a machine change or migration.
        /// </para>
        /// </remarks>
        public string InstallationId()
        {
            if (_options.InstallationId != null)
            {
                return _options.InstallationId;
            }
            if (_installationId != null)
            {
                return _installationId;
            }
            if (string.IsNullOrEmpty(_options.CacheFile))
            {
                return _installationId = MachineId();
            }

            var path = _options.CacheFile + ".id";
            try
            {
                if (File.Exists(path))
                {
                    var existing = File.ReadAllText(path).Trim();
                    if (!string.IsNullOrEmpty(existing))
                    {
                        return _installationId = existing;
                    }
                }

                var generated = Guid.NewGuid().ToString("N");
                var directory = Path.GetDirectoryName(path);
                if (!string.IsNullOrEmpty(directory) && !System.IO.Directory.Exists(directory))
                {
                    System.IO.Directory.CreateDirectory(directory!);
                }
                File.WriteAllText(path, generated);

                return _installationId = generated;
            }
            catch (IOException)
            {
                return _installationId = MachineId();
            }
        }

        // ---------------------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------------------

        /// <summary>Decode base64url that may have had its padding stripped.</summary>
        /// <param name="value">The base64url string.</param>
        /// <exception cref="FormatException">If the input is not valid base64.</exception>
        private static byte[] Base64UrlDecode(string value)
        {
            var padded = value.Replace('-', '+').Replace('_', '/');
            switch (padded.Length % 4)
            {
                case 2: padded += "=="; break;
                case 3: padded += "="; break;
            }

            return Convert.FromBase64String(padded);
        }

        /// <summary>
        /// Reduce a hostname to a canonical comparable form: scheme, path, port
        /// and trailing dot removed, lowercased.
        /// </summary>
        /// <param name="domain">The raw hostname, URL or host:port value.</param>
        /// <param name="stripWww">
        /// Whether a leading "www." is removed. This is the server's
        /// allow_www_normalisation setting, signed into the payload. Stripping
        /// it unconditionally would let www.example.com satisfy a binding to
        /// example.com offline even where the server refuses it online.
        /// </param>
        private static string NormaliseDomain(string domain, bool stripWww = true)
        {
            domain = (domain ?? "").Trim().ToLowerInvariant();

            var scheme = domain.IndexOf("//", StringComparison.Ordinal);
            if (scheme >= 0)
            {
                domain = domain.Substring(scheme + 2);
            }

            domain = domain.Split('/')[0];

            // Only a single colon can be a port; more than one means an IPv6
            // literal, which must survive intact.
            if (domain.Split(':').Length == 2)
            {
                domain = domain.Split(':')[0];
            }
            domain = domain.TrimEnd('.');

            return stripWww && domain.StartsWith("www.", StringComparison.Ordinal)
                ? domain.Substring(4)
                : domain;
        }

        /// <summary>
        /// Decide whether a hostname looks like a development or local environment.
        /// </summary>
        /// <remarks>
        /// Treated as local: private and reserved IP addresses, single-label
        /// hosts such as "localhost" or a container name, the reserved
        /// development TLDs, and the conventional development prefixes.
        /// <para>
        /// This mirrors the server's own detection, so a license whose policy
        /// forbids development hostnames is refused offline for exactly the
        /// hosts it is refused online.
        /// </para>
        /// </remarks>
        /// <param name="domain">The hostname to classify.</param>
        private static bool IsLocalDomain(string domain)
        {
            domain = NormaliseDomain(domain);
            if (domain.Length == 0)
            {
                return false;
            }

            if (IPAddress.TryParse(domain, out var address))
            {
                // Exactly PHP's FILTER_FLAG_NO_PRIV_RANGE and
                // FILTER_FLAG_NO_RES_RANGE sets, which is what the server tests
                // against - not a broader "is private" notion, which would
                // refuse addresses the server accepts.
                string[] networks =
                {
                    "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",
                    "0.0.0.0/8", "127.0.0.0/8", "169.254.0.0/16", "240.0.0.0/4",
                    "fc00::/7", "fe80::/10",
                    "::/128", "::1/128", "::ffff:0:0/96", "100::/64",
                };
                foreach (var cidr in networks)
                {
                    if (IpInCidr(address, cidr))
                    {
                        return true;
                    }
                }

                return false;
            }

            if (!domain.Contains("."))
            {
                return true; // single-label host, e.g. "localhost" or a container name
            }

            string[] suffixes = { ".local", ".localhost", ".test", ".example", ".invalid", ".internal", ".dev.local" };
            foreach (var suffix in suffixes)
            {
                if (domain.EndsWith(suffix, StringComparison.Ordinal))
                {
                    return true;
                }
            }

            string[] prefixes = { "dev.", "staging.", "stage.", "test.", "sandbox.", "local.", "qa." };
            foreach (var prefix in prefixes)
            {
                if (domain.StartsWith(prefix, StringComparison.Ordinal))
                {
                    return true;
                }
            }

            return false;
        }

        /// <summary>
        /// Whether an address falls inside a CIDR block. Works for both IPv4
        /// and IPv6; an address of a different family never matches.
        /// </summary>
        /// <param name="address">The address to test.</param>
        /// <param name="cidr">The network, e.g. "10.0.0.0/8".</param>
        private static bool IpInCidr(IPAddress address, string cidr)
        {
            var parts = cidr.Split('/');
            if (!IPAddress.TryParse(parts[0], out var network)
                || !int.TryParse(parts[1], NumberStyles.Integer, CultureInfo.InvariantCulture, out var bits))
            {
                return false;
            }
            if (address.AddressFamily != network.AddressFamily)
            {
                return false;
            }

            var a = address.GetAddressBytes();
            var n = network.GetAddressBytes();
            for (var i = 0; i < a.Length && bits > 0; i++, bits -= 8)
            {
                var mask = bits >= 8 ? (byte)0xFF : (byte)(0xFF << (8 - bits));
                if ((a[i] & mask) != (n[i] & mask))
                {
                    return false;
                }
            }

            return true;
        }

        /// <summary>
        /// Decide whether the current hostname satisfies the bound one.
        /// </summary>
        /// <remarks>
        /// Two forms of binding are understood: "example.com" matches exactly,
        /// and also matches subdomains when the license permits them;
        /// "*.example.com" matches any subdomain and deliberately excludes the
        /// apex, mirroring the server's matching rules. An empty bound or
        /// current domain matches, since there is nothing to enforce against.
        /// </remarks>
        /// <param name="bound">The domain the license is bound to.</param>
        /// <param name="current">The domain this installation reports.</param>
        /// <param name="allowSubdomains">
        /// Whether subdomains satisfy an apex binding. Comes from the signed
        /// payload, never from a guess, so the offline verdict matches the
        /// online one. The three SDKs used to disagree here - one accepted
        /// subdomains unconditionally, the others never - which meant the same
        /// token was enforced differently depending on which language the
        /// customer's product was written in.
        /// </param>
        /// <param name="stripWww">Whether "www." is insignificant.</param>
        private static bool DomainMatches(string bound, string current, bool allowSubdomains = false, bool stripWww = true)
        {
            bound = NormaliseDomain(bound, stripWww);
            current = NormaliseDomain(current, stripWww);

            if (bound.Length == 0 || current.Length == 0)
            {
                return true;
            }

            if (bound.StartsWith("*.", StringComparison.Ordinal))
            {
                var suffix = bound.Substring(1);

                return current != suffix.Substring(1)
                       && current.EndsWith(suffix, StringComparison.Ordinal);
            }

            if (bound == current)
            {
                return true;
            }

            return allowSubdomains
                   && current.EndsWith("." + bound, StringComparison.Ordinal);
        }

        /// <summary>
        /// Normalise an install path so two spellings of it compare equal.
        /// </summary>
        /// <remarks>
        /// Backslashes become forward slashes, repeated separators collapse,
        /// any trailing separator is dropped, and a Windows drive letter is
        /// uppercased because it is case-insensitive while the rest of a POSIX
        /// path is not. This mirrors the server's own path normalisation, so
        /// the same two paths compare equal on both sides of the check.
        /// </remarks>
        /// <param name="path">The path to normalise.</param>
        private static string NormalisePath(string path)
        {
            path = (path ?? "").Trim();
            if (path.Length == 0)
            {
                return "";
            }

            path = Regex.Replace(path.Replace('\\', '/'), "/+", "/").TrimEnd('/');

            var drive = Regex.Match(path, "^([a-zA-Z]):/");
            if (drive.Success)
            {
                path = drive.Groups[1].Value.ToUpperInvariant() + path.Substring(1);
            }

            return path.Length == 0 ? "/" : path;
        }

        /// <summary>
        /// Compare two strings without leaking their contents through timing.
        /// </summary>
        /// <remarks>
        /// Used for every comparison against a value an attacker might be
        /// probing - license keys, product ids, installation bindings - so that
        /// how long the comparison takes says nothing about how many leading
        /// characters were correct.
        /// </remarks>
        private static bool FixedTimeEquals(string left, string right)
        {
            var a = Encoding.UTF8.GetBytes(left);
            var b = Encoding.UTF8.GetBytes(right);
            if (a.Length != b.Length)
            {
                return false;
            }

            var difference = 0;
            for (var i = 0; i < a.Length; i++)
            {
                difference |= a[i] ^ b[i];
            }

            return difference == 0;
        }

        /// <summary>
        /// Read a signed boolean flag.
        /// </summary>
        /// <remarks>
        /// Absent, null or a non-boolean reads as false, so a payload predating
        /// these flags enforces nothing rather than throwing - the flags gate
        /// extra checks, so the safe default for a missing one is the behaviour
        /// that existed before it.
        /// </remarks>
        /// <param name="element">The token payload.</param>
        /// <param name="property">The JSON property name.</param>
        private static bool ReadBool(JsonElement element, string property) =>
            element.TryGetProperty(property, out var value)
            && value.ValueKind == JsonValueKind.True;

        /// <summary>Read a string property, or null if it is absent or not a string.</summary>
        /// <param name="element">The JSON object to read from.</param>
        /// <param name="property">The JSON property name.</param>
        private static string? ReadString(JsonElement element, string property) =>
            element.TryGetProperty(property, out var value)
            && value.ValueKind == JsonValueKind.String
                ? value.GetString()
                : null;

        // ---------------------------------------------------------------------
        // Version constraints
        // ---------------------------------------------------------------------

        /// <summary>
        /// Explain why the running version is not covered by the signed
        /// constraints, or return null.
        /// </summary>
        /// <remarks>
        /// Version constraints let you sell a license that covers, say, the 2.x
        /// line only, and have older or newer builds refuse to run on it. They
        /// are set per license on the server and signed into the offline token,
        /// so they are enforced identically online and offline.
        /// <para>
        /// This mirrors the server's own version comparison. It is duplicated
        /// rather than shared because this file ships to your customers on its
        /// own; a change to the constraint syntax on the server has to be made
        /// here too.
        /// </para>
        /// <para>
        /// An empty running version means you configured none, which the server
        /// treats as unrestricted, so this does too.
        /// </para>
        /// </remarks>
        /// <param name="version">The running software version.</param>
        /// <param name="min">Minimum supported version, or null.</param>
        /// <param name="max">Maximum supported version, or null.</param>
        /// <param name="allowed">A constraint expression, e.g. "1.x, 2.0 - 2.4, 3.0+".</param>
        /// <returns>A readable reason, or null when the version is covered.</returns>
        private static string? VersionProblem(string version, string? min, string? max, string? allowed)
        {
            version = (version ?? "").Trim();
            if (version.Length == 0)
            {
                return null;
            }

            if (!string.IsNullOrWhiteSpace(min) && VersionCompare(version, min!.Trim()) < 0)
            {
                return "minimum supported version is " + min.Trim();
            }
            if (!string.IsNullOrWhiteSpace(max) && VersionCompare(version, max!.Trim()) > 0)
            {
                return "maximum supported version is " + max.Trim();
            }
            if (!string.IsNullOrWhiteSpace(allowed) && !VersionSatisfies(version, allowed!.Trim()))
            {
                return "version does not match the allowed set (" + allowed.Trim() + ")";
            }

            return null;
        }

        /// <summary>
        /// Split a version string into its numeric components.
        /// </summary>
        /// <remarks>
        /// A leading "v" is dropped, and any pre-release or build suffix
        /// ("-beta", "+build.7") is discarded, so "v2.1.0-rc1" and "2.1.0"
        /// compare equal. Never returns an empty list.
        /// </remarks>
        /// <param name="version">The version string.</param>
        private static List<int> VersionParts(string version)
        {
            var text = Regex.Replace((version ?? "").Trim().ToLowerInvariant(), "^v", "");
            text = Regex.Replace(text, @"[+\-].*$", "");

            var numbers = new List<int>();
            foreach (var part in Regex.Split(text, "[._]"))
            {
                if (part.Length == 0)
                {
                    continue;
                }
                var digits = Regex.Replace(part, @"\D.*$", "");
                numbers.Add(digits.Length == 0 ? 0 : int.Parse(digits, CultureInfo.InvariantCulture));
            }

            return numbers.Count == 0 ? new List<int> { 0 } : numbers;
        }

        /// <summary>
        /// Compare two version strings component by component. Missing
        /// components are treated as zero, so "2.1" and "2.1.0" are equal.
        /// </summary>
        /// <returns>-1 if a is lower, 1 if higher, 0 if equal.</returns>
        private static int VersionCompare(string a, string b)
        {
            var left = VersionParts(a);
            var right = VersionParts(b);

            for (var i = 0; i < Math.Max(left.Count, right.Count); i++)
            {
                var l = i < left.Count ? left[i] : 0;
                var r = i < right.Count ? right[i] : 0;
                if (l != r)
                {
                    return l < r ? -1 : 1;
                }
            }

            return 0;
        }

        /// <summary>
        /// Test a version against a constraint expression.
        /// </summary>
        /// <remarks>
        /// The expression is a comma- or pipe-separated list of constraints,
        /// and the version satisfies it if it matches ANY of them. "*" or an
        /// empty expression matches everything.
        /// </remarks>
        /// <param name="version">The version to test.</param>
        /// <param name="expression">The constraint expression.</param>
        private static bool VersionSatisfies(string version, string expression)
        {
            expression = (expression ?? "").Trim();
            if (expression.Length == 0 || expression == "*")
            {
                return true;
            }
            if (version.Trim().Length == 0)
            {
                return false;
            }

            foreach (var raw in Regex.Split(expression, "[,|]"))
            {
                var constraint = raw.Trim();
                if (constraint.Length > 0 && VersionMatchesOne(version, constraint))
                {
                    return true;
                }
            }

            return false;
        }

        /// <summary>
        /// Test a version against one individual constraint.
        /// </summary>
        /// <remarks>
        /// Supported forms: "*" (anything), "1.0 - 1.9" (an inclusive range),
        /// "3.0+" (that version or higher), the comparisons "&gt;=", "&lt;=",
        /// "&gt;", "&lt;", "!=" and "=", wildcards such as "1.x" or "2.*", and
        /// a bare version for an exact match.
        /// </remarks>
        /// <param name="version">The version to test.</param>
        /// <param name="constraint">A single trimmed constraint.</param>
        private static bool VersionMatchesOne(string version, string constraint)
        {
            if (constraint == "*")
            {
                return true;
            }

            // Inclusive range, e.g. "1.0 - 1.9". The guard keeps "<=1.0 - 2.0"
            // from being read as a range.
            var range = Regex.Match(constraint, @"^(\S+)\s*-\s*(\S+)$");
            if (range.Success && !Regex.IsMatch(range.Groups[1].Value, "^[<>=!]"))
            {
                return VersionCompare(version, range.Groups[1].Value) >= 0
                       && VersionCompare(version, range.Groups[2].Value) <= 0;
            }

            // "3.0+"
            var orNewer = Regex.Match(constraint, @"^(.+)\+$");
            if (orNewer.Success)
            {
                return VersionCompare(version, orNewer.Groups[1].Value.Trim()) >= 0;
            }

            // Comparison operators.
            var comparison = Regex.Match(constraint, @"^(>=|<=|!=|>|<|=)\s*(.+)$");
            if (comparison.Success)
            {
                var cmp = VersionCompare(version, comparison.Groups[2].Value.Trim());
                return comparison.Groups[1].Value switch
                {
                    ">=" => cmp >= 0,
                    "<=" => cmp <= 0,
                    ">" => cmp > 0,
                    "<" => cmp < 0,
                    "!=" => cmp != 0,
                    "=" => cmp == 0,
                    _ => false,
                };
            }

            // Wildcards: "1.x", "2.*", "1.2.x".
            var wildcard = Regex.Match(constraint, @"^(.*?)\.[x*]$", RegexOptions.IgnoreCase);
            if (wildcard.Success)
            {
                var prefix = VersionParts(wildcard.Groups[1].Value);
                var candidate = VersionParts(version);
                for (var i = 0; i < prefix.Count; i++)
                {
                    if ((i < candidate.Count ? candidate[i] : 0) != prefix[i])
                    {
                        return false;
                    }
                }

                return true;
            }

            return VersionCompare(version, constraint) == 0;
        }

        /// <summary>
        /// Read an ISO-8601 timestamp property as a UTC
        /// <see cref="DateTimeOffset"/>, or null if it is absent or
        /// unparseable. A value with no zone is read as UTC, matching what the
        /// server emits.
        /// </summary>
        /// <param name="element">The token payload.</param>
        /// <param name="property">The JSON property name.</param>
        private static DateTimeOffset? ReadTimestamp(JsonElement element, string property)
        {
            var text = ReadString(element, property);
            if (string.IsNullOrEmpty(text))
            {
                return null;
            }

            return DateTimeOffset.TryParse(
                text, CultureInfo.InvariantCulture,
                DateTimeStyles.AdjustToUniversal | DateTimeStyles.AssumeUniversal,
                out var parsed)
                ? parsed
                : (DateTimeOffset?)null;
        }

        /// <summary>
        /// Release the <see cref="HttpClient"/>, but only if the SDK created
        /// it. One you supplied is left for you to manage.
        /// </summary>
        public void Dispose()
        {
            if (_ownsHttpClient)
            {
                _http.Dispose();
            }
            GC.SuppressFinalize(this);
        }
    }
}
