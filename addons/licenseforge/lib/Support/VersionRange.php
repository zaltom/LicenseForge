<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Compares software versions and tests them against constraints.
 *
 * Lets a vendor scope a licence to particular versions of their product - a
 * major-version licence, a maintenance window, or a specific build.
 *
 * Deliberately lenient about what a version looks like. The strings arrive from
 * a customer's own software and follow whatever convention that vendor chose:
 * `2.1`, `v3.0.1`, `1.4.2-beta`, `10.0.0+build77`. Refusing anything that is
 * not strict semver would reject perfectly ordinary version numbers, so the
 * comparison extracts the numeric sequence and ignores the rest.
 *
 * One consequence: pre-release and build metadata are discarded, not ordered.
 * `1.4.2-beta` and `1.4.2` compare as equal here, where semver would rank the
 * beta lower. Licensing cares whether a version is covered, not which of two
 * builds is newer, and treating a customer's beta as outside their licence
 * would be the more surprising outcome.
 */
final class VersionRange
{
    /**
     * Reduce a version string to its numeric components.
     *
     * Strips a leading `v`, discards anything from the first `-` or `+`
     * (pre-release and build metadata), and splits on dots or underscores. A
     * component with trailing letters keeps its leading digits, so `1.2rc` reads
     * as 2 rather than as nothing.
     *
     * Never returns an empty list: an unparseable string becomes `[0]`, so
     * comparisons still work rather than having to guard for it.
     *
     * @return list<int>
     */
    public static function parse(string $version): array
    {
        $version = strtolower(trim($version));
        $version = (string) preg_replace('/^v/', '', $version);

        $version = (string) preg_replace('/[+\-].*$/', '', $version);
        $parts   = preg_split('/[._]/', $version) ?: [];

        $numbers = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $numbers[] = (int) preg_replace('/\D.*$/', '', $part);
        }

        return $numbers === [] ? [0] : $numbers;
    }

    /**
     * Compare two versions.
     *
     * Missing components count as zero, so `1.4` and `1.4.0` are equal - a
     * customer reporting the short form is not treated as running something
     * older than the long one.
     *
     * @return int -1 if $a is lower, 0 if equal, 1 if higher.
     */
    public static function compare(string $a, string $b): int
    {
        $left  = self::parse($a);
        $right = self::parse($b);
        $len   = max(count($left), count($right));

        for ($i = 0; $i < $len; $i++) {
            $l = $left[$i] ?? 0;
            $r = $right[$i] ?? 0;
            if ($l !== $r) {
                return $l < $r ? -1 : 1;
            }
        }

        return 0;
    }

    /**
     * Is this a version string at all?
     *
     * Accepts digits and dots with an optional `v` prefix and optional
     * pre-release or build suffix. Used only to reject input that is not a
     * version in any convention - the comparison itself is far more tolerant -
     * so this is about catching a client sending a product name or an empty
     * placeholder.
     */
    public static function isValid(string $version): bool
    {
        return (bool) preg_match('/^v?\d+(\.\d+)*([.\-+][0-9a-z.\-]+)?$/i', trim($version));
    }

    /**
     * Does a version match a constraint expression?
     *
     * The expression is a list of constraints separated by commas or pipes, and
     * any one matching is enough - the list is an "or", so a vendor writes
     * `1.x, 2.0 - 2.4` to cover two supported lines.
     *
     * An empty expression or `*` matches everything, which is what makes the
     * setting optional: leaving it blank places no restriction rather than
     * permitting nothing. An empty version matches nothing, since there is no
     * basis on which to say it qualifies.
     */
    public static function satisfies(string $version, string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '*') {
            return true;
        }
        $version = trim($version);
        if ($version === '') {
            return false;
        }

        foreach (preg_split('/[,|]/', $expression) ?: [] as $constraint) {
            $constraint = trim($constraint);
            if ($constraint !== '' && self::matchesSingle($version, $constraint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test one constraint.
     *
     * Five forms, tried in order:
     *
     *   *              anything
     *   1.0 - 2.0      an inclusive range
     *   1.4+           that version or later
     *   >= 1.4         an explicit comparison (>=, <=, >, <, !=, =)
     *   1.4.x          a prefix match, on any number of components
     *
     * Anything else is an exact version and must compare equal.
     *
     * The range form is checked before the comparison form and explicitly
     * refuses a left side beginning with an operator, so `>=1.0` is not misread
     * as a range whose lower bound happens to contain a hyphen.
     */
    private static function matchesSingle(string $version, string $constraint): bool
    {
        if ($constraint === '*') {
            return true;
        }

        if (preg_match('/^(\S+)\s*-\s*(\S+)$/', $constraint, $m) && !preg_match('/^[<>=!]/', $m[1])) {
            return self::compare($version, $m[1]) >= 0 && self::compare($version, $m[2]) <= 0;
        }

        if (preg_match('/^(.+)\+$/', $constraint, $m)) {
            return self::compare($version, trim($m[1])) >= 0;
        }

        if (preg_match('/^(>=|<=|!=|>|<|=)\s*(.+)$/', $constraint, $m)) {
            $cmp = self::compare($version, trim($m[2]));
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

        if (preg_match('/^(.*?)\.[x*]$/i', $constraint, $m)) {
            // Only the components named by the prefix are compared; anything
            // beyond them is what the wildcard covers.
            $prefix        = self::parse($m[1]);
            $candidate     = self::parse($version);
            foreach ($prefix as $i => $value) {
                if (($candidate[$i] ?? 0) !== $value) {
                    return false;
                }
            }

            return true;
        }

        return self::compare($version, $constraint) === 0;
    }

    /**
     * Apply every version rule and describe the first failure.
     *
     * The three rules are independent and all apply: a minimum, a maximum, and
     * an explicit allowed set.
     *
     * A client that reports no version passes. Older SDKs do not send one, and
     * refusing them would break upgrades rather than enforce anything - the
     * check only bites once a client is new enough to say what it is.
     *
     * Returns the reason rather than a boolean, because the caller shows it to a
     * customer: "unsupported version" alone leaves them with nothing to act on,
     * whereas "minimum supported version is 2.0" tells them what to do.
     *
     * @return string|null The problem, or null when the version is covered.
     */
    public static function check(string $version, ?string $min, ?string $max, ?string $allowed): ?string
    {
        $version = trim($version);
        if ($version === '') {
            return null;
        }
        if (!self::isValid($version)) {
            return 'malformed version string';
        }
        if ($min !== null && trim($min) !== '' && self::compare($version, $min) < 0) {
            return 'minimum supported version is ' . trim($min);
        }
        if ($max !== null && trim($max) !== '' && self::compare($version, $max) > 0) {
            return 'maximum supported version is ' . trim($max);
        }
        if ($allowed !== null && trim($allowed) !== '' && !self::satisfies($version, $allowed)) {
            return 'version does not match the allowed set (' . trim($allowed) . ')';
        }

        return null;
    }
}
