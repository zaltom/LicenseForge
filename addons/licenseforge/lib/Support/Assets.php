<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Builds the <link> and <script> tags for the module's stylesheets and scripts.
 *
 * The assets are served as static files by the web server rather than inlined
 * into a template, so a browser caches them across page loads and a vendor can
 * override one without editing markup.
 *
 * Two problems come with that, and this class solves both: the URL has to be
 * absolute, because the module renders inside pages served from several
 * different paths; and it has to change when the file does, or an upgraded
 * installation keeps serving last version's CSS from cache - see
 * {@see version()}.
 */
final class Assets
{
    /**
     * The module version, used as a cache-busting query string.
     *
     * Tying the parameter to the release rather than to a file timestamp means
     * every installation of a given version requests the same URL, so the asset
     * stays cacheable, while an upgrade changes it and retires the old copy.
     *
     * Falls back to '0' rather than failing: an asset served from a stale cache
     * is a far smaller problem than a page that will not render.
     */
    private static function version(): string
    {
        return defined('LICENSEFORGE_VERSION') ? (string) LICENSEFORGE_VERSION : '0';
    }

    /**
     * The absolute URL for one of the module's assets.
     *
     * Absolute because the admin console, the client area and the product pages
     * are served from different paths, and a relative URL would resolve
     * differently in each.
     *
     * Both filename and version are URL-encoded. Neither is caller-supplied in
     * practice, but encoding keeps a filename with a space or a plus sign from
     * producing a URL that silently does not resolve.
     */
    public static function url(string $file): string
    {
        return self::baseUrl() . '/modules/addons/' . LICENSEFORGE_MODULE . '/assets/'
            . rawurlencode($file) . '?v=' . rawurlencode(self::version());
    }

    /**
     * The filesystem path for an asset, if the name is safe.
     *
     * The name is whitelisted rather than sanitised: letters, digits, dots,
     * hyphens and underscores only, and no `..` anywhere. Nothing in this module
     * passes a caller-supplied name here, but a path-building helper that would
     * accept one is what a later change turns into a traversal, so it refuses by
     * construction.
     *
     * @return string The path, or '' when the name is not acceptable.
     */
    public static function path(string $file): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $file) !== 1 || strpos($file, '..') !== false) {
            return '';
        }

        return LICENSEFORGE_DIR . '/assets/' . $file;
    }

    /**
     * Is this asset present and safely named?
     *
     * Both conditions, since an unsafe name yields no path at all.
     */
    public static function exists(string $file): bool
    {
        $path = self::path($file);

        return $path !== '' && is_file($path);
    }

    /**
     * Render the HTML tags for a list of assets.
     *
     * A missing file is logged and skipped rather than emitted: a tag pointing
     * at nothing produces a 404 on every page load and a browser console error
     * that looks like a bug in the surrounding theme, whereas the log names the
     * file that is actually absent.
     *
     * Scripts are emitted with `defer` so they execute after the document is
     * parsed. The module's scripts bind to markup WHMCS renders, and none needs
     * to run before it exists.
     *
     * The extension decides the tag, so anything that is neither CSS nor
     * JavaScript is ignored rather than guessed at.
     *
     * @param list<string> $files
     */
    public static function tags(array $files): string
    {
        $html = '';

        foreach ($files as $file) {
            if (!self::exists($file)) {
                error_log('[LicenseForge] missing asset: ' . self::path($file));
                continue;
            }

            $url = Input::e(self::url($file));

            if (substr($file, -4) === '.css') {
                $html .= '<link rel="stylesheet" href="' . $url . '">' . "\n";
            } elseif (substr($file, -3) === '.js') {
                $html .= '<script src="' . $url . '" defer></script>' . "\n";
            }
        }

        return $html;
    }

    /**
     * The installation's own base URL, from the WHMCS system setting.
     *
     * Taken from configuration rather than from the request, so the URL is the
     * one the administrator declared - which is what makes it correct behind a
     * proxy, on a secondary hostname, or during a request that arrived some
     * other way.
     *
     * An unreadable setting yields an empty string, producing a root-relative
     * URL. That is wrong in some contexts but works in most, and is a better
     * failure than an exception raised while rendering a page.
     */
    private static function baseUrl(): string
    {
        try {
            $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        } catch (\Throwable $e) {
            $url = '';
        }

        return rtrim($url, '/');
    }
}
