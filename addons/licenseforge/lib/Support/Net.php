<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Domain, IP address and path normalisation, and the proxy trust model.
 *
 * Every binding comparison in the licensing engine goes through this class,
 * which is what makes a domain or IP lock mean anything: a licence tied to
 * `example.com` must not be defeated by `https://WWW.Example.com:8443/` or a
 * Unicode homograph. Everything reduces to one canonical form before any
 * comparison happens; normalising at each call site would leave the bypasses to
 * be rediscovered one at a time.
 *
 * The other half of the class is the proxy trust model. `X-Forwarded-For` and
 * `X-Forwarded-Proto` are client-supplied strings, honoured only when the
 * machine actually connected is a configured trusted proxy. Without that rule a
 * caller could claim any source address - voiding every IP lock and per-IP rate
 * limit - or claim TLS over the plaintext connection an attacker is reading.
 *
 * {@see clientIp()} and {@see peerIp()} are a pair for that reason: one answers
 * "who is the client", honouring proxies; the other answers "who is connected",
 * which no header can rewrite. Decisions about the connection must use the
 * second.
 */
final class Net
{
    /**
     * Reduce any domain-ish string to a bare, lowercase hostname.
     *
     * Accepts what customers actually paste - a full URL, a host with a port,
     * a trailing dot, credentials - and returns just the host:
     * `https://WWW.Example.com:8443/install/` becomes `example.com`.
     *
     * Unicode domains are converted to punycode where the runtime supports it,
     * so a homograph cannot present as a visually identical but different domain
     * and pass a comparison it should fail.
     *
     * IPv6 literals are recognised and normalised as addresses rather than being
     * cut at the first colon by the port-stripping.
     *
     * @param bool|null $stripWww Whether to drop a leading `www.`; defaults to
     *   the configured setting, since whether those are the same site is a
     *   policy question rather than a technical one.
     */
    public static function normaliseDomain(string $domain, ?bool $stripWww = null): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        if (strpos($domain, '//') !== false) {
            $domain = (string) preg_replace('#^[a-z0-9+.\-]*://#i', '', $domain);
        }
        $domain = (string) preg_replace('#^[^/@]*@#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);

        if (preg_match('/^\[(.+)\]/', $domain, $m)) {
            $ip = self::normaliseIp($m[1]);

            return $ip !== '' ? $ip : '';
        }

        // One colon means host:port. Two or more means an unbracketed IPv6
        // literal, which must keep its colons.
        if (substr_count($domain, ':') === 1) {
            $domain = (string) preg_replace('/:\d+$/', '', $domain);
        }

        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($domain === '') {
            return '';
        }

        if (preg_match('/[^\x20-\x7E]/', $domain) && function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $domain = strtolower($ascii);
            }
        }

        if ($stripWww === null) {
            $stripWww = Settings::bool('allow_www_normalisation', true);
        }
        if ($stripWww && strncmp($domain, 'www.', 4) === 0) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    /**
     * Is this a development or internal domain rather than a live site?
     *
     * Used to keep production licences off staging installations where a product
     * policy asks for that.
     *
     * Recognises private and reserved addresses, single-label hosts such as
     * `localhost` or a container name, and the reserved suffixes and
     * conventional development prefixes. Necessarily a heuristic, which is why
     * it gates an optional policy rather than a hard rule.
     */
    public static function isLocalDomain(string $domain): bool
    {
        $domain = self::normaliseDomain($domain);
        if ($domain === '') {
            return false;
        }
        if (self::isIp($domain)) {
            return self::isPrivateIp($domain);
        }
        if (strpos($domain, '.') === false) {
            return true;
        }

        $localSuffixes = ['.local', '.localhost', '.test', '.example', '.invalid', '.internal', '.dev.local'];
        foreach ($localSuffixes as $suffix) {
            if (substr($domain, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        $devPrefixes = ['dev.', 'staging.', 'stage.', 'test.', 'sandbox.', 'local.', 'qa.'];
        foreach ($devPrefixes as $prefix) {
            if (strncmp($domain, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a candidate domain satisfy a pattern?
     *
     * Three forms: an exact host, an explicit `*.example.com` wildcard, and -
     * when the product allows it - implicit subdomain coverage.
     *
     * An explicit wildcard covers subdomains only, never the apex:
     * `*.example.com` does not match `example.com`. Treating it loosely would
     * silently widen every licence using it.
     *
     * Both sides are normalised first, so a pattern stored with a scheme or in
     * mixed case still matches. The exact comparison uses `hash_equals()`,
     * keeping it constant-time alongside every other credential comparison.
     */
    public static function domainMatches(string $pattern, string $candidate, bool $allowSubdomains = false): bool
    {
        $candidate = self::normaliseDomain($candidate);
        if ($candidate === '') {
            return false;
        }

        $pattern = trim(strtolower($pattern));
        $wildcard = false;
        if (strncmp($pattern, '*.', 2) === 0) {
            $wildcard = true;
            $pattern  = substr($pattern, 2);
        }
        $pattern = self::normaliseDomain($pattern);
        if ($pattern === '') {
            return false;
        }

        if ($wildcard) {
            $suffix = '.' . $pattern;

            return substr($candidate, -strlen($suffix)) === $suffix;
        }

        if (hash_equals($pattern, $candidate)) {
            return true;
        }

        if ($allowSubdomains) {
            $suffix = '.' . $pattern;

            return substr($candidate, -strlen($suffix)) === $suffix;
        }

        return false;
    }

    /**
     * Does a candidate satisfy any of these patterns?
     *
     * Empty patterns are skipped rather than matched, so a blank entry in a
     * stored list does not accidentally match everything.
     *
     * @param list<string> $patterns
     */
    public static function domainMatchesAny(array $patterns, string $candidate, bool $allowSubdomains = false): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && self::domainMatches($pattern, $candidate, $allowSubdomains)) {
                return true;
            }
        }

        return false;
    }

    /** Is this a valid IPv4 or IPv6 address? */
    public static function isIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Reduce an address to one canonical text form.
     *
     * The same address can be written many ways - IPv6 compressed or expanded,
     * bracketed, with a port, or IPv4-mapped - and comparing raw strings would
     * treat those as different hosts. Each is packed to binary and printed back.
     *
     * IPv4-mapped IPv6 (`::ffff:1.2.3.4`) is unwrapped to plain IPv4, because a
     * dual-stack server sees the same client in both forms depending on how the
     * connection arrived, and a licence bound to one must match the other.
     *
     * @return string The canonical form, or '' if it is not an address at all.
     */
    public static function normaliseIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (preg_match('/^\[(.+)\]:?\d*$/', $ip, $m)) {
            $ip = $m[1];
        }

        // A single colon alongside dots is IPv4:port; IPv6 has several colons.
        if (substr_count($ip, ':') === 1 && strpos($ip, '.') !== false) {
            $ip = explode(':', $ip)[0];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $packed = substr($packed, 12);
        }

        $normalised = @inet_ntop($packed);

        return is_string($normalised) ? strtolower($normalised) : '';
    }

    /**
     * Is this address private, reserved or otherwise not publicly routable?
     *
     * Covers the RFC1918 ranges, loopback, link-local and the reserved blocks.
     * Used to recognise a development environment, and to avoid binding a
     * licence to an address that means something different on every network.
     */
    public static function isPrivateIp(string $ip): bool
    {
        $ip = self::normaliseIp($ip);
        if ($ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Does an address fall inside a CIDR range?
     *
     * Works for IPv4 and IPv6 by comparing packed binary rather than text, since
     * a string comparison cannot express a prefix that ends mid-byte.
     *
     * A range with no `/` is treated as a single address, so a list may mix
     * exact addresses and ranges freely.
     *
     * Mismatched families return false rather than erroring, and a prefix length
     * outside the address's range is refused rather than clamped. The whole-byte
     * portion is compared with `hash_equals()`.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $ip = self::normaliseIp($ip);
        if ($ip === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            return hash_equals(self::normaliseIp($cidr), $ip);
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $subnet = self::normaliseIp($subnet);
        if ($subnet === '') {
            return false;
        }

        $ipPacked     = inet_pton($ip);
        $subnetPacked = inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false || strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $bits = (int) $bits;
        $max  = strlen($ipPacked) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && !hash_equals(substr($subnetPacked, 0, $fullBytes), substr($ipPacked, 0, $fullBytes))) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        // Compare only the leading bits of the byte the prefix ends inside.
        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($subnetPacked[$fullBytes]) & $mask);
    }

    /**
     * Does an address match any of these addresses or ranges?
     *
     * The check behind both IP-locked licences and credential IP restrictions.
     *
     * @param list<string> $allowed Exact addresses or CIDR ranges.
     */
    public static function ipMatchesAny(array $allowed, string $ip): bool
    {
        foreach ($allowed as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && self::ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the real client address for this request.
     *
     * Proxy headers are honoured only when the immediate peer is a configured
     * trusted proxy; otherwise `REMOTE_ADDR` is authoritative. A client that
     * could spoof its source address with `X-Forwarded-For` would defeat every
     * IP-locked licence and every per-IP rate limit at once.
     *
     * With trusted proxies configured, the forwarded chain is walked right to
     * left, skipping addresses that are themselves trusted proxies. The first
     * untrusted hop is the real client: a client may append anything to the left
     * of the chain, so only the portion added by trusted infrastructure counts.
     *
     * @param array<string,mixed>|null $server         Defaults to $_SERVER.
     * @param list<string>|null        $trustedProxies Defaults to the setting.
     *   Both injectable so the trust model is testable without a live request.
     */
    public static function clientIp(?array $server = null, ?array $trustedProxies = null): string
    {
        $server         = $server ?? $_SERVER;
        $trustedProxies = $trustedProxies ?? Settings::trustedProxies();

        $remote = self::normaliseIp((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '' || $trustedProxies === []) {
            return $remote;
        }
        if (!self::ipMatchesAny($trustedProxies, $remote)) {
            return $remote;
        }

        $header = (string) Settings::get('trusted_proxy_header', 'X-Forwarded-For');
        $key    = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $value  = (string) ($server[$key] ?? '');
        if ($value === '') {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', $value)));
        foreach ($chain as $candidate) {
            $candidate = self::normaliseIp($candidate);
            if ($candidate === '') {
                continue;
            }
            if (!self::ipMatchesAny($trustedProxies, $candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * Was this request made over TLS?
     *
     * Same trust model as {@see clientIp()}, and for a sharper reason: without
     * it any client could claim `X-Forwarded-Proto: https` and defeat the API's
     * TLS requirement over exactly the plaintext connection an attacker reads.
     *
     * The two direct signals are checked first and are conclusive, since both
     * are set by the web server rather than the client. Only then is a forwarded
     * header considered, and only behind a trusted proxy.
     *
     * A chain such as `https, http` records the original client scheme leftmost,
     * which is the hop this cares about.
     */
    public static function isSecure(?array $server = null, ?array $trustedProxies = null): bool
    {
        $server = $server ?? $_SERVER;

        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ((int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $trustedProxies = $trustedProxies ?? Settings::trustedProxies();
        if ($trustedProxies === []) {
            return false;
        }

        $remote = self::normaliseIp((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '' || !self::ipMatchesAny($trustedProxies, $remote)) {
            return false;
        }

        $forwarded = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');
        $first     = strtolower(trim(explode(',', $forwarded)[0]));

        return $first === 'https';
    }

    /**
     * The address of the machine actually connected.
     *
     * Unlike {@see clientIp()} this is never rewritten by a forwarded header, so
     * it is what decisions about the connection itself must use - such as the
     * loopback exemption from the TLS requirement, where using the forwarded
     * value would let a caller claim to be loopback and skip it entirely.
     */
    public static function peerIp(?array $server = null): string
    {
        $server = $server ?? $_SERVER;

        return self::normaliseIp((string) ($server['REMOTE_ADDR'] ?? ''));
    }

    /**
     * Reduce an installation path to a comparable form.
     *
     * Separators are unified to forward slashes, repeats collapsed and any
     * trailing slash removed, so the same directory reported by a Windows and a
     * POSIX client compares equal.
     *
     * A Windows drive letter is uppercased because those are case-insensitive,
     * while the rest of the path is left alone because POSIX paths are not - a
     * blanket lowercase would make two genuinely different directories look like
     * one.
     */
    public static function normalisePath(string $path): string
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
}
