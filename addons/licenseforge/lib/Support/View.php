<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Smarty rendering and link building for the admin console.
 *
 * Covers the admin area only. Client-area templates are rendered by WHMCS
 * itself - the server module returns a template name and WHMCS handles the rest
 * - so nothing here touches them.
 *
 * Two guarantees for every admin page. A render failure never produces a broken
 * page: a missing template, an unavailable engine or a Smarty error each yield
 * a visible message inside the WHMCS layout, with the detail in the error log,
 * because a licensing addon must not take down the admin area it is embedded
 * in. And every template receives the same base variables - module link, CSRF
 * token, navigation counts, translations - so no page can forget one.
 */
final class View
{
    /**
     * Render an admin template.
     *
     * The template name is reduced with `basename()`, so a path cannot escape
     * the admin template directory even though every current caller passes a
     * literal.
     *
     * Base variables are merged with `+=`, so a caller's own value for a key
     * wins: a page can override the nav counts or supply its own token without
     * this method having to know about it.
     *
     * The two failure paths are separated deliberately - an unavailable engine
     * and a failed render are different problems with different fixes.
     *
     * @param array<string,mixed> $vars
     * @return string Rendered HTML, or a visible error block.
     */
    public static function renderAdmin(string $template, array $vars = []): string
    {
        $file = LICENSEFORGE_DIR . '/templates/admin/' . basename($template) . '.tpl';
        if (!is_file($file)) {
            return '<div class="alert alert-danger">Template not found: ' . Input::e($template) . '</div>';
        }

        $vars += [
            'activePage' => basename($template),
            'moduleLink' => self::moduleLink(),
            'csrfToken'  => Input::csrfToken(),
            'version'    => LICENSEFORGE_VERSION,

            // Badge counts for the nav tabs.
            'nav'        => self::navCounts(),

            // Every translated string, referenced in templates as {$L.key}.
            'L'          => Lang::all(),
        ];

        try {
            $smarty = self::smarty();
        } catch (\Throwable $e) {
            error_log('[LicenseForge] template engine unavailable: ' . $e->getMessage());

            return '<div class="alert alert-danger">The template engine is unavailable. '
                . 'See the server error log for details.</div>';
        }

        foreach ($vars as $key => $value) {
            $smarty->assign($key, $value);
        }

        try {
            return (string) $smarty->fetch($file);
        } catch (\Throwable $e) {
            error_log('[LicenseForge] template render failed (' . $template . '): ' . $e->getMessage());

            return '<div class="alert alert-danger">This page could not be rendered. '
                . 'See the server error log for details.</div>';
        }
    }

    /**
     * The pending counts shown as badges on the navigation tabs.
     *
     * Loaded for every admin page so a queue waiting on someone is visible from
     * whichever page they happen to be on; a reissue request would otherwise
     * only be noticed by someone who thought to look.
     *
     * Zeroes on failure, so a missing table after a partial upgrade costs the
     * badges rather than the page.
     *
     * @return array{reissues:int,abuse:int}
     */
    private static function navCounts(): array
    {
        try {
            return [
                'reissues' => (int) Db::table('reissues')->where('status', 'pending')->count(),
                'abuse'    => (int) Db::table('abuse_events')->where('resolved', 0)->count(),
            ];
        } catch (\Throwable $e) {
            return ['reissues' => 0, 'abuse' => 0];
        }
    }

    /**
     * A configured Smarty instance.
     *
     * WHMCS's own subclass is preferred where available, since it arrives with
     * the installation's plugins, security settings and configuration already
     * applied - including its compile directory, which is why that is not set
     * here. A plain Smarty is the fallback for contexts where the WHMCS class is
     * not loaded, and needs its compile directory supplied.
     *
     * @throws \RuntimeException When neither class exists.
     */
    private static function smarty()
    {
        $templateDir = LICENSEFORGE_DIR . '/templates/admin';

        if (class_exists('\\WHMCS\\Smarty')) {
            $smarty = new \WHMCS\Smarty(true);
            $smarty->setTemplateDir($templateDir);

            return $smarty;
        }

        if (!class_exists('\\Smarty')) {
            throw new \RuntimeException('Neither \\WHMCS\\Smarty nor \\Smarty is available.');
        }

        $smarty = new \Smarty();
        $smarty->setTemplateDir($templateDir);
        $smarty->setCompileDir(self::compileDir());

        return $smarty;
    }

    /**
     * A writable directory for Smarty's compiled templates.
     *
     * WHMCS's own `templates_c` is preferred, so compiled output lives where an
     * administrator expects it and is cleared by the usual means. The system
     * temporary directory is the fallback for installations where that is not
     * writable, which is common on hardened hosting.
     *
     * Each candidate is tested for writability rather than mere existence, since
     * an unwritable directory fails at render time with a far less obvious
     * error.
     *
     * @throws \RuntimeException When no candidate can be used.
     */
    private static function compileDir(): string
    {
        $candidates = [];
        if (defined('ROOTDIR')) {
            $candidates[] = constant('ROOTDIR') . '/templates_c';
        }
        $candidates[] = sys_get_temp_dir() . '/licenseforge_templates_c';

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_writable($candidate)) {
                return $candidate;
            }
            if (!is_dir($candidate) && @mkdir($candidate, 0750, true)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No writable template compile directory is available.');
    }

    /**
     * The addon's base URL, as WHMCS reports it for this request.
     *
     * Empty until supplied - see {@see useModuleLink()}.
     */
    private static string $moduleLink = '';

    /**
     * Adopt the module link WHMCS supplied for this request.
     *
     * WHMCS passes the addon its own URL, which is authoritative: it carries
     * whatever query parameters that installation's admin area requires, and
     * those differ between versions and configurations. Using it rather than a
     * constructed URL is what keeps links working across them.
     *
     * A blank value is ignored, so a caller passing nothing cannot erase a link
     * already set and leave every subsequent link broken.
     */
    public static function useModuleLink(string $link): void
    {
        $link = trim($link);
        if ($link !== '') {
            self::$moduleLink = $link;
        }
    }

    /**
     * Build a URL into the addon, with optional query parameters.
     *
     * Falls back to constructing the standard addon URL when WHMCS has not
     * supplied one, which covers contexts where a link is built outside a normal
     * admin request.
     *
     * The separator is chosen by inspecting the base rather than assumed, since
     * the supplied link may already carry parameters - appending `?` to a URL
     * that has one produces a link that silently loses everything after it.
     *
     * Not escaped: this returns a URL, and escaping belongs at the point it is
     * placed into markup. Use {@see link()} where the result goes straight into
     * a template.
     *
     * @param array<string,mixed> $params
     */
    public static function moduleLink(array $params = []): string
    {
        $base = self::$moduleLink !== ''
            ? self::$moduleLink
            : 'addonmodules.php?module=' . LICENSEFORGE_MODULE;

        if ($params === []) {
            return $base;
        }

        return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($params);
    }

    /**
     * An escaped link to one of the addon's pages.
     *
     * The escaped counterpart to {@see moduleLink()}, for use directly in
     * markup - `&` in a query string must be encoded to be valid HTML, and the
     * two methods exist separately so it is always clear which form a caller is
     * getting.
     *
     * @param array<string,mixed> $params
     */
    public static function link(string $page, array $params = []): string
    {
        return Input::e(self::moduleLink(['page' => $page] + $params));
    }

    /**
     * Work out the pagination state for a listing.
     *
     * The requested page is clamped into range, so a URL naming page 500 of a
     * three-page list shows the last page rather than an empty one - which is
     * what happens after a filter narrows a result set someone had already paged
     * through.
     *
     * `from` and `to` are the human-readable range ("showing 26–50"), with
     * `from` reported as zero on an empty set rather than one, since there is no
     * first row to point at.
     *
     * @return array{page:int,per_page:int,total:int,pages:int,from:int,to:int}
     */
    public static function pagination(int $page, int $perPage, int $total): array
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page  = min(max(1, $page), $pages);

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => $pages,
            'from'     => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to'       => min($total, $page * $perPage),
        ];
    }
}
