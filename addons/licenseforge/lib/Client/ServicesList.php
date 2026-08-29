<?php

declare(strict_types=1);

namespace LicenseForge\Client;

use LicenseForge\Licensing\LicenseStatus;
use LicenseForge\Support\Db;
use LicenseForge\Support\Lang;

/**
 * Shows each licence key on the customer's services list.
 *
 * Saves a customer opening every product in turn to find the key they need. The
 * list is rendered by whatever WHMCS theme the installation uses, so the markup
 * is not this module's to change; instead the data is printed into the page and
 * assets/client-panel.js places it into the rows the theme produced.
 *
 * Nothing here emits HTML the browser will render. Both blocks are
 * `<script type="application/json">`, which a browser parses but never
 * executes, so the feature works under a Content-Security-Policy that forbids
 * inline script, and no theme's markup has to be patched or assumed.
 *
 * Only ever runs for the signed-in customer, and every query is scoped to their
 * client id - see {@see licensesByService()}.
 */
final class ServicesList
{
    /**
     * Produce the JSON blocks for the services list, or nothing.
     *
     * Returns an empty string on every path that is not this exact page for a
     * signed-in customer holding at least one licence, so the hook that calls
     * this adds nothing at all to any other page.
     *
     * Two blocks are printed: the licences keyed by service id, and the UI
     * labels. The labels travel with the data because the script must carry no
     * English of its own - they come from the module's language file, which is
     * what makes the feature translatable.
     *
     * @param array<string,mixed> $vars     Template variables supplied by WHMCS.
     * @param int                 $clientId The signed-in customer.
     * @return string HTML to append to the page, or '' to add nothing.
     */
    public static function render(array $vars, int $clientId): string
    {
        if ($clientId <= 0 || !self::isServiceList($vars)) {
            return '';
        }

        $licenses = self::licensesByService($clientId);
        if ($licenses === []) {
            return '';
        }

        /*
         * The HEX flags are what make this safe to place inside a <script>
         * element.
         *
         * A JSON block is not HTML-escaped by the browser, so an unescaped `<`
         * in the data could close the element early and turn the remainder into
         * markup. Hexing the four characters that could do so - < > & ' " -
         * means no value can escape the block, whatever a licence key or status
         * label happens to contain.
         *
         * An encoding failure yields nothing rather than a partial block, since
         * a truncated JSON document would break the page's parsing.
         */
        $payload = json_encode($licenses, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($payload === false) {
            return '';
        }

        $labels = json_encode([
            'copy'    => Lang::get('client_copy'),
            'copied'  => Lang::get('client_copied'),
            'copyKey' => Lang::get('client_copy_aria'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script type="application/json" id="lfg-services-data">' . $payload . '</script>'
            . '<script type="application/json" id="lfg-services-lang">'
            . ($labels !== false ? $labels : '{}') . '</script>';
    }

    /**
     * Is the page currently rendering the customer's services list?
     *
     * Two tests, because themes differ. The template name is authoritative where
     * WHMCS provides it; the query string is the fallback for themes that render
     * the list through a different template.
     *
     * Deliberately conservative in one direction only: a false negative costs
     * the feature on an unusual theme, while a false positive would print
     * licence data into a page it does not belong on.
     */
    private static function isServiceList(array $vars): bool
    {
        if (($vars['templatefile'] ?? '') === 'clientareaproducts') {
            return true;
        }

        $action = strtolower((string) ($_GET['action'] ?? ''));

        return $action === 'services' || $action === 'products';
    }

    /**
     * The customer's licences, keyed by the service each belongs to.
     *
     * Scoped to the client id and to their own services, so this cannot return a
     * licence belonging to anyone else. That scoping is the whole access control
     * for this feature - there is no second check downstream, because the data
     * is printed straight into the page.
     *
     * Where a service somehow has more than one licence, the newest wins: the
     * list has one row per service and can show only one key, and the most
     * recently issued is the one a customer is asking about.
     *
     * Bounded at 500 rows. A customer with more licences than that is better
     * served by the product pages than by a page that takes seconds to build.
     *
     * @return array<int,array{key:string,label:string,tone:string}> Service id =>
     *   licence. Empty if the query fails, so the list still renders undecorated.
     */
    private static function licensesByService(int $clientId): array
    {
        try {
            $rows = Db::table('licenses')
                ->where('client_id', $clientId)
                ->where('service_id', '>', 0)
                ->whereNull('deleted_at')
                ->orderBy('id', 'desc')
                ->limit(500)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $licenses = [];
        foreach ($rows as $row) {
            $serviceId = (int) $row->service_id;

            // Newest first from the query, so the first row seen for a service
            // is the one to keep.
            if (isset($licenses[$serviceId])) {
                continue;
            }

            $licenses[$serviceId] = [
                'key'   => (string) $row->license_key,
                'label' => LicenseStatus::label((string) $row->status),
                'tone'  => LicenseStatus::badgeClass((string) $row->status),
            ];
        }

        return $licenses;
    }

}
