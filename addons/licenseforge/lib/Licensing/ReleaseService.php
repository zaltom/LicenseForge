<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Settings;

/**
 * Decides who may download a release file, and finds it safely on disk.
 *
 * This exists because WHMCS's own download handling cannot enforce a licence.
 * WHMCS authorises its download endpoint on service ownership and offers no
 * hook between that request and the file - so removing a link from a product
 * page changes what is rendered, not what is served, and a customer holding a
 * URL from when their licence was healthy keeps the software after it is
 * revoked. Serving through this module's own endpoint is what closes that.
 *
 * Everything here refuses by default. A download requires a signed-in client
 * who holds a currently usable licence for that release's product, and a file
 * that resolves inside the configured release directory.
 *
 * The release directory must sit outside every served tree, or the web server
 * hands the files over on its own URL without ever consulting this class.
 * {@see problemWith()} refuses a directory inside WHMCS or the document root,
 * but it cannot detect an alias, a CDN origin or a symlink mapping onto one
 * from elsewhere - that is a web-server question PHP has no way to ask, and it
 * remains the operator's responsibility.
 *
 * Integrity is checked at two depths: the recorded file size on every download,
 * which costs a stat(); and the full SHA-256 on demand, which does not belong
 * on the hot path - see {@see verifyIntegrity()}.
 */
final class ReleaseService
{
    /*
     * Refusal reasons. These are recorded in the audit log, never returned to
     * the customer: the endpoint answers uniformly so that a stranger guessing
     * release ids and a customer whose licence lapsed cannot tell each other's
     * case apart.
     */
    public const DENY_NO_CLIENT   = 'not_signed_in';
    public const DENY_NOT_FOUND   = 'no_such_release';
    public const DENY_NO_LICENSE  = 'no_licence_for_product';
    public const DENY_UNUSABLE    = 'licence_not_usable';
    public const DENY_UNREADABLE  = 'file_missing';
    public const DENY_NOT_CONFIGURED = 'release_dir_not_set';
    public const DENY_ALTERED     = 'file_altered_since_registration';

    /**
     * Decide whether this client may download this release.
     *
     * The licence is looked up by (client, product) rather than taken from the
     * request, so a customer with several services on the same product needs
     * only one usable licence to be entitled to the file - and cannot nominate a
     * licence that is not theirs.
     *
     * "No licence at all" and "a licence that is not usable" are separate
     * reasons: one is someone who never bought this product, the other a
     * customer whose licence lapsed. The customer sees the same page either way,
     * but support needs the audit log to tell them apart.
     *
     * @return array{ok:bool,reason:string,release:?object,license:?object,path:?string}
     *   `path` is the resolved absolute file, set only on success.
     */
    public static function authorise(int $releaseId, int $clientId): array
    {
        $deny = static function (string $reason, ?object $release = null, ?object $license = null): array {
            return ['ok' => false, 'reason' => $reason, 'release' => $release, 'license' => $license, 'path' => null];
        };

        if ($clientId <= 0) {
            return $deny(self::DENY_NO_CLIENT);
        }

        if (self::directory() === '') {
            return $deny(self::DENY_NOT_CONFIGURED);
        }

        $release = $releaseId > 0
            ? Db::table('releases')->where('id', $releaseId)->where('is_active', 1)->first()
            : null;

        if ($release === null) {
            return $deny(self::DENY_NOT_FOUND);
        }

        $licenses = Db::table('licenses')
            ->where('client_id', $clientId)
            ->where('product_id', (int) $release->product_id)
            ->whereNull('deleted_at')
            ->get();

        if (count($licenses) === 0) {
            return $deny(self::DENY_NO_LICENSE, $release);
        }

        $usable = null;
        foreach ($licenses as $license) {
            if (self::licenseAllows($license)) {
                $usable = $license;
                break;
            }
        }

        if ($usable === null) {
            return $deny(self::DENY_UNUSABLE, $release, $licenses[0] ?? null);
        }

        $path = self::resolvePath((string) $release->file_path);
        if ($path === null) {
            return $deny(self::DENY_UNREADABLE, $release, $usable);
        }

        /*
         * The size recorded at registration, checked on every download.
         *
         * Not a substitute for the hash - anything altering the file while
         * preserving its length passes - but it costs a stat() rather than
         * reading the file, so it can run here, where a full SHA-256 of a
         * multi-gigabyte archive cannot. The realistic failure it catches is a
         * release overwritten by a truncated or partial upload, which is both
         * the likeliest way this goes wrong and the one needing no privilege at
         * all.
         */
        if ((int) $release->size_bytes > 0) {
            $actual = @filesize($path);
            if ($actual !== false && $actual !== (int) $release->size_bytes) {
                return $deny(self::DENY_ALTERED, $release, $usable);
            }
        }

        return ['ok' => true, 'reason' => '', 'release' => $release, 'license' => $usable, 'path' => $path];
    }

    /**
     * Does this licence currently entitle its holder to downloads?
     *
     * A licence inside its grace period still does. Grace exists so a customer
     * keeps working while renewal is arranged, and cutting off their downloads
     * first would defeat that at exactly the wrong moment.
     */
    public static function licenseAllows(object $license): bool
    {
        if (!LicenseStatus::isUsable((string) $license->status)) {
            return false;
        }

        return !LicenseManager::isExpired($license) || LicenseManager::inGracePeriod($license);
    }

    /**
     * Resolve a stored relative path to a real file inside the release directory.
     *
     * The containment check is what makes the stored path safe. Both sides are
     * passed through realpath() first, so `..` segments and symlinks are
     * collapsed before comparison rather than being matched textually - a check
     * on the raw string would be defeated by either.
     *
     * The prefix comparison includes a trailing separator deliberately. Without
     * it, `/releases-old` would satisfy a check against `/releases`, since one is
     * a string prefix of the other while being an entirely different directory.
     *
     * @return string|null The absolute path, or null if it does not resolve, is
     *   not a readable file, or escapes the base directory.
     */
    public static function resolvePath(string $relative): ?string
    {
        $base = self::directory();
        if ($base === '' || $relative === '') {
            return null;
        }

        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }

        $candidate = realpath(rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));
        if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) {
            return null;
        }

        $prefix = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
        if (strncmp($candidate, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return $candidate;
    }

    /**
     * The release directory, but only if it is safe to serve from.
     *
     * Returns an empty string for a directory that fails validation, which
     * disables downloads entirely rather than serving from somewhere unsafe.
     * Enforced here, in the service, rather than only at the settings screen -
     * so a value written straight into the database is refused too.
     */
    public static function directory(): string
    {
        $configured = trim((string) Settings::get('release_dir', ''));

        return self::problemWith($configured) === '' ? $configured : '';
    }

    /**
     * The configured directory as stored, valid or not.
     *
     * For the settings screen, which must show what an administrator typed in
     * order to explain what is wrong with it. Never use this to serve a file.
     */
    public static function configuredDirectory(): string
    {
        return trim((string) Settings::get('release_dir', ''));
    }

    /**
     * What, if anything, is wrong with a release directory.
     *
     * The decisive test is containment. WHMCS is served from its own directory,
     * so anything beneath it is reachable by URL whatever this module does; the
     * document root is a second, weaker test covering an installation in a
     * subdirectory of a larger served tree, and is only known during a web
     * request.
     *
     * What this cannot test is the reverse direction: a directory nowhere near
     * either tree that a web-server alias, a CDN origin or a symlink nonetheless
     * maps a URL onto. PHP cannot enumerate the server's routing table, so that
     * case is accepted here and must be verified externally.
     *
     * @return string A reason code, or '' when the directory is usable.
     */
    public static function problemWith(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return 'not_set';
        }

        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            return 'missing';
        }

        if (!is_readable($real)) {
            return 'unreadable';
        }

        // Three levels up from lib/: modules/addons/licenseforge -> WHMCS root.
        $whmcsRoot = realpath(dirname(LICENSEFORGE_DIR, 3));
        if ($whmcsRoot !== false && self::isBeneath($real, $whmcsRoot)) {
            return 'inside_whmcs';
        }

        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($docRoot !== false && $docRoot !== '' && self::isBeneath($real, $docRoot)) {
            return 'inside_web_root';
        }

        return '';
    }

    /**
     * Is one path inside another, or the same path?
     *
     * Compares with a trailing separator appended, so `/var/www-data` is not
     * treated as being inside `/var/www` - a plain prefix test would say it was.
     */
    private static function isBeneath(string $path, string $parent): bool
    {
        $path   = rtrim($path, '/\\');
        $parent = rtrim($parent, '/\\');

        if ($path === $parent) {
            return true;
        }

        return strncmp($path, $parent . DIRECTORY_SEPARATOR, strlen($parent) + 1) === 0;
    }

    /**
     * Does a release still hash to what was recorded when it was registered?
     *
     * Turns "the file on disk is the file you published" from an assumption into
     * something checkable.
     *
     * Deliberately not run per download: hashing a multi-gigabyte archive on
     * every request would cost more than serving it, and the threat does not
     * warrant it - altering the file requires write access to a directory
     * outside the web root, which is already a serious compromise. What this
     * defends is the gap between that compromise and noticing it, during which a
     * substituted build ships to every customer with nothing to signal it.
     *
     * A release with no recorded hash reports as unverifiable rather than as
     * passing: rows predating this, or registered while hashing failed, have
     * nothing to check against and must not read as clean.
     *
     * @return array{ok:bool,status:string,expected:string,actual:string}
     */
    public static function verifyIntegrity(object $release): array
    {
        $result = static function (bool $ok, string $status, string $expected = '', string $actual = ''): array {
            return ['ok' => $ok, 'status' => $status, 'expected' => $expected, 'actual' => $actual];
        };

        $expected = trim((string) ($release->sha256 ?? ''));
        if ($expected === '') {
            return $result(false, 'no_hash_recorded');
        }

        $path = self::resolvePath((string) $release->file_path);
        if ($path === null) {
            return $result(false, 'file_missing', $expected);
        }

        $actual = @hash_file('sha256', $path);
        if (!is_string($actual) || $actual === '') {
            return $result(false, 'unreadable', $expected);
        }

        if (!Crypto::secureEquals($expected, $actual)) {
            Audit::log('release.integrity_failed', null, Audit::RESULT_FAILURE, [
                'release_id' => (int) $release->id,
                'label'      => (string) $release->label,
                'expected'   => $expected,
                'actual'     => $actual,
            ], Audit::ACTOR_SYSTEM);

            return $result(false, 'mismatch', $expected, $actual);
        }

        return $result(true, 'ok', $expected, $actual);
    }

    /**
     * Verify every registered release, newest first.
     *
     * Includes inactive releases: one withdrawn from customers is still a file
     * on disk, and an integrity check that skipped it would miss exactly the
     * release nobody is watching.
     *
     * @return list<array{release:object,result:array{ok:bool,status:string,expected:string,actual:string}}>
     */
    public static function verifyAll(): array
    {
        $out = [];
        foreach (Db::table('releases')->orderBy('id', 'desc')->get() as $release) {
            $out[] = ['release' => $release, 'result' => self::verifyIntegrity($release)];
        }

        return $out;
    }

    /**
     * The releases a licence entitles its holder to download.
     *
     * Asks the same questions {@see authorise()} will ask - the licence must be
     * usable, and each file must actually resolve - so the customer is never
     * shown a link that would then refuse them, and never denied one they are
     * entitled to. A release whose file has been moved or removed is omitted
     * rather than listed as broken.
     *
     * @return list<object>
     */
    public static function forLicense(object $license): array
    {
        if (self::directory() === '' || !self::licenseAllows($license)) {
            return [];
        }

        $rows = Db::table('releases')
            ->where('product_id', (int) $license->product_id)
            ->where('is_active', 1)
            ->orderBy('id', 'desc')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (self::resolvePath((string) $row->file_path) !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Note that a release was served.
     *
     * Failures are swallowed: the file is about to be streamed, and a download
     * the customer is entitled to must not fail because a counter could not be
     * incremented.
     */
    public static function recordDownload(object $release, object $license): void
    {
        try {
            Db::table('releases')->where('id', (int) $release->id)->increment('download_count');
            Audit::log('release.downloaded', (int) $license->id, Audit::RESULT_SUCCESS, [
                'release_id' => (int) $release->id,
                'label'      => (string) $release->label,
                'version'    => (string) ($release->version ?? ''),
            ], Audit::ACTOR_CLIENT, (int) $license->client_id);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Record a refused download, with the reason the customer was not told.
     *
     * The endpoint answers every refusal identically, so this log is the only
     * place the actual cause exists - it is what lets support answer "why can't
     * I download this?" without guessing.
     *
     * Failures are swallowed for the same reason as {@see recordDownload()}: the
     * refusal must still be delivered.
     */
    public static function recordDenial(int $releaseId, int $clientId, string $reason, ?object $license): void
    {
        try {
            Audit::log('release.denied', $license !== null ? (int) $license->id : null, Audit::RESULT_DENIED, [
                'release_id' => $releaseId,
                'client_id'  => $clientId,
                'reason'     => $reason,
            ], Audit::ACTOR_CLIENT, $clientId);
        } catch (\Throwable $e) {
        }
    }
}
