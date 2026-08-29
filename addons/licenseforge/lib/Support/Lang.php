<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Translations for everything the module displays.
 *
 * Strings live in `lang/<language>.php`, each defining an `$_ADDONLANG` array.
 * A translation is layered over English rather than replacing it, so a partial
 * or outdated file yields English for the keys it lacks instead of blank
 * labels: a translation is useful from its first key and never breaks a page by
 * falling behind a release.
 *
 * The language is resolved from the viewer's session where possible, so staff
 * and customers each see the module in the language they have chosen rather
 * than one installation-wide setting.
 *
 * Loading is lazy and cached for the request - every accessor loads on demand,
 * so nothing has to be initialised in the right order.
 */
final class Lang
{
    /**
     * The language every other one falls back to. English is the reference
     * file: it defines the complete key set, and every other language is checked
     * against it.
     */
    private const FALLBACK = 'english';

    /**
     * @var array<string,string>|null The active translation over the fallback,
     *   or null before loading. Null rather than an empty array, so "not yet
     *   loaded" stays distinguishable from "loaded and empty".
     */
    private static ?array $strings = null;

    /** @var array<string,string>|null English, cached separately for re-layering. */
    private static ?array $fallback = null;

    /** The language currently loaded, so a repeat load can be skipped. */
    private static string $language = '';

    /**
     * Guards against recursion while reading the module's config.
     *
     * `licenseforge_config()` is itself translated, so calling it to discover
     * the configured language would re-enter this class and call it again. See
     * {@see configuredDefault()}.
     */
    private static bool $readingConfig = false;

    /**
     * Adopt the translations WHMCS has already loaded for this addon.
     *
     * WHMCS reads an addon's language file itself and hands the result to the
     * module. Using that is what makes the module honour the installation's own
     * language handling, including any overrides an administrator has placed in
     * WHMCS's override directory, which this class would not otherwise see.
     *
     * Still layered over English, so a WHMCS-supplied set missing newer keys
     * does not leave them blank. An empty array is ignored rather than adopted,
     * since that means WHMCS found nothing and adopting it would discard the
     * strings loaded here.
     *
     * @param array<string,mixed> $strings
     */
    public static function useWhmcsStrings(array $strings): void
    {
        if ($strings === []) {
            return;
        }

        self::$fallback = self::$fallback ?? self::read(self::FALLBACK);
        self::$strings  = array_map('strval', $strings) + (self::$fallback ?? []);
    }

    /**
     * Load a language, detecting one if not named.
     *
     * Re-loading the same language is a no-op, so the many accessors that call
     * this defensively cost nothing after the first.
     *
     * English skips the merge entirely - it is the fallback, and layering it
     * over itself would only duplicate the work.
     */
    public static function load(string $language = ''): void
    {
        $language = $language !== '' ? $language : self::detect();

        if (self::$strings !== null && self::$language === $language) {
            return;
        }

        self::$language = $language;
        self::$fallback = self::read(self::FALLBACK);
        self::$strings  = $language === self::FALLBACK
            ? self::$fallback
            : (self::read($language) + self::$fallback);
    }

    /**
     * A translated string, with optional placeholder substitution.
     *
     * Placeholders are `:name` in the text, replaced from the array. That form
     * is used rather than printf's `%s` because it is order-independent - a
     * translator can move `:count` anywhere in the sentence, which some
     * languages require.
     *
     * An unknown key returns the caller's default, or the key itself when there
     * is none. Returning the key is deliberate: a missing translation shows as a
     * visible identifier naming the exact key to add, rather than as a blank
     * that looks like a rendering fault.
     *
     * Substituted values are NOT escaped. Callers placing the result into markup
     * must escape it themselves - see {@see Input::e()}.
     *
     * @param array<string,mixed> $replace
     */
    public static function get(string $key, string $default = '', array $replace = []): string
    {
        if (self::$strings === null) {
            self::load();
        }

        $text = self::$strings[$key] ?? ($default !== '' ? $default : $key);

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Every string in the active language.
     *
     * Passed wholesale to the templates as `L`, so a template references
     * `{$L.key}` without each one being listed individually.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (self::$strings === null) {
            self::load();
        }

        return self::$strings ?? [];
    }

    /** The language currently in use, loading one if necessary. */
    public static function current(): string
    {
        if (self::$strings === null) {
            self::load();
        }

        return self::$language;
    }

    /**
     * Every language the module has a file for.
     *
     * Read from the directory rather than a list, so a translation dropped in by
     * a vendor is available without any registration step.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $found = [];
        foreach (glob(self::directory() . '/*.php') ?: [] as $file) {
            $found[] = basename($file, '.php');
        }
        sort($found);

        return $found;
    }

    /**
     * Work out which language to use for this request.
     *
     * Most specific first: the admin's session, then the client's, then the
     * WHMCS default, then the module's own configured default.
     *
     * The session takes precedence over the installation default so staff and
     * customers each see the module in the language they chose; a single
     * installation-wide language would be wrong for one of them on any
     * multilingual site.
     */
    private static function detect(): string
    {
        foreach ([$_SESSION['adminlang'] ?? null, $_SESSION['Language'] ?? null] as $candidate) {
            $name = strtolower(trim((string) $candidate));
            if ($name !== '') {
                return $name;
            }
        }

        try {
            $default = strtolower((string) \WHMCS\Config\Setting::getValue('Language'));
            if ($default !== '') {
                return $default;
            }
        } catch (\Throwable $e) {
        }

        return self::configuredDefault();
    }

    /**
     * The language named in the module's own configuration.
     *
     * Guarded against recursion, because `licenseforge_config()` returns
     * translated text and therefore calls back into this class. Without the
     * guard, resolving the language would call the config, which would resolve
     * the language, and so on; a re-entrant call short-circuits to English.
     */
    private static function configuredDefault(): string
    {
        if (self::$readingConfig || !function_exists('licenseforge_config')) {
            return self::FALLBACK;
        }

        self::$readingConfig = true;

        try {
            $config = licenseforge_config();
            $name   = strtolower(trim((string) ($config['language'] ?? '')));

            return $name !== '' ? $name : self::FALLBACK;
        } catch (\Throwable $e) {
            return self::FALLBACK;
        } finally {
            self::$readingConfig = false;
        }
    }

    /**
     * Read one language file.
     *
     * The name is whitelisted before it becomes a path - letters, underscores
     * and hyphens only - so a value reaching here from a session or a setting
     * cannot traverse out of the language directory and include an arbitrary
     * file.
     *
     * A missing file yields an empty array rather than an error, which is what
     * lets an unknown language fall back cleanly to English.
     *
     * @return array<string,string>
     */
    private static function read(string $language): array
    {
        if (preg_match('/^[a-z_\-]{2,30}$/', $language) !== 1) {
            return [];
        }

        $path = self::directory() . '/' . $language . '.php';
        if (!is_file($path)) {
            return [];
        }

        $_ADDONLANG = [];
        include $path;

        return is_array($_ADDONLANG) ? array_map('strval', $_ADDONLANG) : [];
    }

    /** Where the language files live. */
    private static function directory(): string
    {
        return LICENSEFORGE_DIR . '/lang';
    }
}
