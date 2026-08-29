<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Reading request input, escaping output, and CSRF protection.
 *
 * Everything arriving from a browser passes through here so that values are
 * type-coerced and length-bounded on the way in, output is escaped on the way
 * out, and every state-changing POST is CSRF-checked.
 *
 * The read helpers never fail: a missing or unusable value yields the caller's
 * default, so no call site needs a guard.
 *
 * Note the asymmetry in the CSRF helpers - {@see verifyCsrf()} reads the token
 * from `$_POST` only, never the query string. A token in a URL lands in Referer
 * headers, proxy logs and browser history, where it outlives its session.
 */
final class Input
{
    /**
     * Session key holding this session's CSRF token, namespaced so it cannot
     * collide with WHMCS's own session data or another addon's.
     */
    public const CSRF_SESSION_KEY = 'licenseforge_csrf';

    /**
     * A raw query-string value. Uncoerced; prefer {@see str()} or {@see int()}.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * A raw POST value.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * A value from POST, falling back to the query string.
     *
     * POST wins so a form submission is not overridden by a stale parameter
     * left in the URL it posted to. Deliberately not used for CSRF
     * verification - see the note on the class.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function any(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * A trimmed, bounded, control-character-free string.
     *
     * Control characters are stripped rather than escaped: none has a
     * legitimate use here, and they are how a value smuggles a newline into a
     * log line, a header or a stored record. Tab, carriage return and newline
     * survive, since textarea fields legitimately contain them.
     *
     * Non-scalar input yields the default rather than being cast. Truncation is
     * by characters, so a multi-byte value is never cut mid-character.
     */
    public static function str(string $key, string $default = '', int $maxLength = 255): string
    {
        $value = self::any($key, $default);
        if (!is_scalar($value)) {
            return $default;
        }
        $value = trim((string) $value);

        // preg_replace returns null on invalid UTF-8 under /u. Casting that to
        // a string would blank the field, turning malformed input into silent
        // data loss on a value the caller believes it saved.
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if ($stripped === null) {
            return $default;
        }

        return mb_substr($stripped, 0, $maxLength);
    }

    /**
     * An integer.
     *
     * Non-numeric input yields the default rather than casting to zero, so a
     * malformed id cannot become `0` and match a record that treats zero as
     * meaningful.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::any($key, null);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A checkbox or flag.
     *
     * An absent value yields the default rather than false, because a checkbox
     * that is not on the form differs from one present and clear. The admin
     * controller's boolean-settings handling relies on that distinction to
     * record an unticked box as off.
     *
     * Accepts `1`, `on`, `yes` and `true`, covering what browsers and API
     * clients actually send.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::any($key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * A list of record ids, from a checkbox array or a comma-separated string.
     *
     * Both shapes are accepted because bulk-action forms post an array while a
     * hand-built request may send a string. Zero and non-numeric entries are
     * dropped, so a malformed selection narrows the operation rather than
     * widening it.
     *
     * @return list<int> Unique, in submitted order.
     */
    public static function idList(string $key): array
    {
        $value = self::any($key, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    /**
     * Escape a value for HTML output.
     *
     * Quotes are escaped as well as tags, so the result is safe inside an
     * attribute as well as in text. Invalid UTF-8 is substituted rather than
     * returning the empty string `htmlspecialchars()` would otherwise produce,
     * since silently blanking a value is worse than a replacement character.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * This session's CSRF token, generating one on first use.
     *
     * Per session rather than per form, so several forms on a page and several
     * tabs open at once all validate against the same value; a per-form token
     * would invalidate whichever tab was not submitted first.
     *
     * A session is started if none exists, since this runs during rendering and
     * may precede anything else that needed one. Skipped on the command line,
     * where there is no session and no CSRF risk.
     */
    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            @session_start();
        }
        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * The hidden form field carrying the CSRF token.
     *
     * Escaped even though the token is hex, so that anything interpolated into
     * markup is escaped without the reader having to reason about whether it
     * needed to be.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="lfg_token" value="' . self::e(self::csrfToken()) . '">';
    }

    /**
     * Does the submitted token match this session's?
     *
     * Read from `$_POST` only - never {@see any()}, which would also accept the
     * query string and let a valid token travel in a URL. Compared with
     * `hash_equals()` for constant time.
     */
    public static function verifyCsrf(?string $token = null): bool
    {
        $token = $token ?? (string) ($_POST['lfg_token'] ?? '');
        if ($token === '') {
            return false;
        }

        return hash_equals(self::csrfToken(), $token);
    }

    /**
     * Enforce CSRF protection, or refuse the request.
     *
     * The method check is not redundant: it is what stops a state-changing
     * action being reachable by a URL alone, which no token could protect once
     * that URL was shared or prefetched.
     *
     * Rejections are audited before the exception, so an attempted CSRF leaves
     * a record rather than only an error the user sees.
     *
     * @throws \RuntimeException When the request does not qualify.
     */
    public static function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \RuntimeException('This action requires a POST request.');
        }
        if (!self::verifyCsrf()) {
            Audit::log('csrf.rejected', null, Audit::RESULT_DENIED, [
                'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            ]);
            throw new \RuntimeException('Security token mismatch. Please reload the page and try again.');
        }
    }

    /** Is this a syntactically valid email address? */
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Is this an acceptable machine identifier?
     *
     * Shape and length only. The value is opaque to this module - whatever a
     * vendor's software derives from the hardware it runs on - so anything
     * stricter would reject identifiers valid to the client that produced them.
     *
     * An empty value passes; requiring one is the product policy's decision
     * rather than this validator's.
     */
    public static function isMachineId(string $value): bool
    {
        return $value === '' || (bool) preg_match('/^[A-Za-z0-9._:\-]{4,128}$/', $value);
    }

    /**
     * Is this a date this module can store?
     *
     * Checks the shape and that it parses, since a well-formed string can still
     * be an impossible date. An empty value passes, meaning "not set".
     */
    public static function isDate(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)
            && strtotime($value) !== false;
    }

    /**
     * Convert an admin-supplied date into a storable UTC datetime.
     *
     * Parsed in the server's zone, since that is what an administrator typing a
     * date means, then written as UTC to match everything else stored.
     *
     * @return string|null Null for an empty or unparseable value, which callers
     *   treat as "no date" rather than as an error.
     */
    public static function toDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Convert a stored datetime to ISO-8601 for an API response.
     *
     * ' UTC' is appended before parsing because stored values carry no zone;
     * without it PHP applies the server's and the timestamp handed to a client
     * drifts by the server's offset.
     *
     * MySQL's zero date is treated as absent rather than converted, since it
     * means "never" and would otherwise serialise as a date in year zero.
     */
    public static function toIso(?string $mysqlDateTime): ?string
    {
        if ($mysqlDateTime === null || $mysqlDateTime === '' || strncmp($mysqlDateTime, '0000', 4) === 0) {
            return null;
        }
        $timestamp = strtotime($mysqlDateTime . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Split a textarea or comma-separated field into a list.
     *
     * Newlines and commas both separate, so an administrator may type a list
     * either way, or mix them as pasting usually does.
     *
     * Blanks are dropped, duplicates removed, and the result bounded: these
     * lists become allowed domains and addresses, and an unbounded one would
     * let a single paste turn every later comparison into a long loop.
     *
     * @return list<string>
     */
    public static function toList(?string $value, int $maxItems = 100): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));

        return array_slice(array_values(array_unique($parts)), 0, $maxItems);
    }
}
