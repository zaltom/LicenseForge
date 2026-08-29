<?php
/**
 * WHMCS hooks - the licensing events the provisioning module cannot see.
 *
 * Service creation, suspension, unsuspension, termination and package
 * changes all arrive through the License Forge provisioning module
 * (modules/servers/licenseforge). These hooks only cover what WHMCS does
 * outside the module call chain: billing, manual service edits, deletion
 * and the daily maintenance pass.
 *
 * Every handler is wrapped in its own try/catch. A hook throwing would break
 * the WHMCS operation it is attached to, and no licensing fault justifies
 * failing an invoice payment or a service deletion.
 */

declare(strict_types=1);

use LicenseForge\Client\ServicesList;
use LicenseForge\Licensing\LicenseManager;
use LicenseForge\Licensing\LicenseStatus;
use LicenseForge\Licensing\Maintenance;
use LicenseForge\Licensing\Notifier;
use LicenseForge\Licensing\ProductConfig;
use LicenseForge\Licensing\Provisioner;
use LicenseForge\Support\Assets;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\Settings;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------- billing

/**
 * Extend every licence on a paid invoice.
 *
 * Keyed on the invoice's hosting line items, so an invoice covering several
 * services renews each of them. Provisioner::renew() is idempotent and
 * provisions a licence first if the service somehow has none.
 */
add_hook('InvoicePaid', 1, static function (array $vars): void {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0 || !Settings::bool('module_enabled', true)) {
        return;
    }

    try {
        $items = Db::connection()->table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->get();

        foreach ($items as $item) {
            Provisioner::renew((int) $item->relid, 'InvoicePaid');
        }
    } catch (\Throwable $e) {
        error_log('[LicenseForge] InvoicePaid hook failed: ' . $e->getMessage());
    }
});

// ---------------------------------------------------------------- services

/**
 * Follow a service status changed by hand in the admin area.
 *
 * The provisioning module sees status changes it performs itself; an
 * administrator editing the service directly bypasses it entirely, which is
 * the gap this closes.
 */
add_hook('ServiceEdit', 1, static function (array $vars): void {
    try {
        $serviceId = (int) ($vars['serviceid'] ?? 0);
        $service   = Provisioner::service($serviceId);
        if ($service === null || LicenseManager::findByService($serviceId) === null) {
            return;
        }

        // Keep licensing state aligned with a manually changed service status.
        $map = [
            'Active'     => 'map_active',
            'Pending'    => 'map_pending',
            'Suspended'  => 'map_suspended',
            'Terminated' => 'map_terminated',
            'Cancelled'  => 'map_cancelled',
            'Fraud'      => 'map_fraud',
        ];
        $key = $map[(string) $service->domainstatus] ?? null;
        if ($key !== null) {
            Provisioner::mapStatus($serviceId, $key, 'service edited');
        }
    } catch (\Throwable $e) {
        error_log('[LicenseForge] ServiceEdit hook failed: ' . $e->getMessage());
    }
});

/**
 * Retire a licence whose service has been deleted.
 *
 * Terminated first, then soft-deleted: the status change is what stops the
 * software working, and the soft delete is what removes it from the listings
 * while keeping its history.
 */
add_hook('ServiceDelete', 1, static function (array $vars): void {
    // A licensing fault must not stop WHMCS deleting the service the operator
    // asked it to delete.
    try {
        $license = LicenseManager::findByService((int) ($vars['serviceid'] ?? 0));
        if ($license !== null) {
            // The service is gone, but licensing history is retained for audit.
            LicenseManager::setStatus((int) $license->id, LicenseStatus::TERMINATED, 'service deleted');
            LicenseManager::softDelete((int) $license->id, 'service deleted');
        }
    } catch (\Throwable $e) {
        error_log('[LicenseForge] ServiceDelete hook failed: ' . $e->getMessage());
    }
});

/**
 * Note a cancellation request against the licence.
 *
 * Records only - the licence is not touched. A request is not a cancellation,
 * and acting on it early would withdraw software the customer has still paid
 * for until the end of the term.
 */
add_hook('CancellationRequest', 1, static function (array $vars): void {
    $license = LicenseManager::findByService((int) ($vars['relid'] ?? 0));
    if ($license !== null) {
        Audit::log('hook.cancellation_requested', (int) $license->id, Audit::RESULT_SUCCESS, [
            'type' => $vars['type'] ?? '',
        ], Audit::ACTOR_SYSTEM);
    }
});

// ---------------------------------------------------------------- assets

/**
 * Load the console's stylesheet and script on the module's own admin pages.
 *
 * Scoped to this addon so no other admin page pays for CSS it will not use.
 */
add_hook('AdminAreaHeadOutput', 1, static function (array $vars): string {
    if (strtolower((string) ($_GET['module'] ?? '')) !== LICENSEFORGE_MODULE) {
        return '';
    }

    try {
        return Assets::tags(['admin.css', 'admin.js']);
    } catch (\Throwable $e) {
        error_log('[LicenseForge] admin assets failed: ' . $e->getMessage());

        return '';
    }
});

/**
 * Load the client-area assets on the two pages that render licensing: the
 * product details page, and the services list.
 *
 * Both the template name and the query action are tested, because themes
 * differ in which of the two they present.
 */
add_hook('ClientAreaHeadOutput', 1, static function (array $vars): string {
    $page = (string) ($vars['templatefile'] ?? '');
    $action = strtolower((string) ($_GET['action'] ?? ''));

    $wanted = in_array($page, ['clientareaproductdetails', 'clientareaproducts'], true)
        || in_array($action, ['productdetails', 'services', 'products'], true);

    if (!$wanted) {
        return '';
    }

    try {
        return Assets::tags(['client.css', 'client-panel.js']);
    } catch (\Throwable $e) {
        error_log('[LicenseForge] client assets failed: ' . $e->getMessage());

        return '';
    }
});

// ---------------------------------------------------------------- emails

/**
 * Give every product email the license merge fields.
 *
 * LicenseForge supplies these itself when it sends, but WHMCS sends the same
 * templates too - from the Email dropdown on a client's product page, and as
 * a product's welcome email on a new order. WHMCS knows nothing about
 * licensing, so without this those emails arrive with the license key and
 * everything around it blank.
 *
 * Because it keys on the service rather than the template name, the fields
 * are also available in your own product emails: put {$license_key} in the
 * standard welcome email and it will be filled in.
 */
add_hook('EmailPreSend', 1, static function (array $vars): array {
    $serviceId = (int) ($vars['relid'] ?? 0);
    if ($serviceId <= 0) {
        return [];
    }

    try {
        $license = LicenseManager::findByService($serviceId);
        if ($license === null) {
            return [];
        }

        return Notifier::mergeFields($license);
    } catch (\Throwable $e) {
        // An email that is missing a merge field is better than no email.
        error_log('[LicenseForge] EmailPreSend merge fields failed: ' . $e->getMessage());

        return [];
    }
});

// ---------------------------------------------------------------- client area

/**
 * Show each license key under its product name on the services list, so a
 * customer with several products can find the right key without opening
 * each one.
 */
add_hook('ClientAreaFooterOutput', 1, static function (array $vars): string {
    if (!Settings::bool('module_enabled', true) || !Settings::bool('show_key_in_service_list', true)) {
        return '';
    }

    try {
        return ServicesList::render($vars, (int) ($_SESSION['uid'] ?? 0));
    } catch (\Throwable $e) {
        error_log('[LicenseForge] service list decoration failed: ' . $e->getMessage());

        return '';
    }
});

/**
 * Gate the product's WHMCS downloads on the state of its license.
 *
 * Software downloads are ordinary WHMCS product downloads (Setup > Support >
 * Downloads, associated with the product). WHMCS shows them to anyone who
 * owns the service; licensing is stricter than ownership, so an expired,
 * suspended or revoked license hides them again. A product with no license
 * is left completely alone.
 */
add_hook('ClientAreaPageProductDetails', 1, static function (array $vars): array {
    $serviceId = (int) ($vars['id'] ?? 0);
    if ($serviceId <= 0 || empty($vars['downloads'])) {
        return [];
    }

    try {
        $license = LicenseManager::findByService($serviceId);
        if ($license === null || (int) $license->client_id !== (int) ($_SESSION['uid'] ?? 0)) {
            return [];
        }

        // The product's own policy, not the global switch.
        //
        // Both layers exist - every product carries a download_protection
        // column that falls back to the global default - but this hook read
        // only the global one, so the per-product setting did nothing. It was
        // wrong in both directions: a product with protection turned off was
        // gated anyway, and a product that turned it *on* while the global was
        // off was not gated at all. The second is the one that matters, and it
        // fails silently: the product page looks configured and protects
        // nothing.
        //
        // Resolved after the license is found because the policy is per
        // product, and the license is what says which product this is.
        if (!ProductConfig::policyForLicense($license)['download_protection']) {
            return [];
        }

        // A license inside its grace period is still a working license.
        if (LicenseStatus::isUsable((string) $license->status)
            && (!LicenseManager::isExpired($license) || LicenseManager::inGracePeriod($license))) {
            return [];
        }

        return [
            'downloads'          => [],
            'licenseforgeLocked' => 'Downloads are unavailable while this license is '
                . strtolower(LicenseStatus::label((string) $license->status)) . '.',
        ];
    } catch (\Throwable $e) {
        // Fail closed. Returning [] here would leave the page alone, which is
        // itself a decision: the downloads stay visible, so a database hiccup
        // does what a revoked license could not. This gate is only reached when
        // the service has downloads, so the choice is between showing a lapsed
        // customer the files and withholding them from a paying one during an
        // outage - and only the second is reversible by waiting.
        //
        // Deliberately unlike the rate limiter, which fails open: that guards a
        // threshold, and denying it turns one fault into an estate-wide outage.
        // This guards an entitlement boundary.
        error_log('[LicenseForge] download gating failed: ' . $e->getMessage());

        return [
            'downloads'          => [],
            'licenseforgeLocked' => 'Downloads are temporarily unavailable while the license '
                . 'is being verified. Please try again shortly.',
        ];
    }
});

// ---------------------------------------------------------------- cron

/**
 * The scheduled maintenance pass, on WHMCS's own daily schedule.
 *
 * cron.php runs the same work on a finer interval where a day is too coarse;
 * every task is idempotent, so running both is safe.
 */
add_hook('DailyCronJob', 1, static function (): void {
    if (!Settings::bool('module_enabled', true)) {
        return;
    }

    try {
        Maintenance::run();
    } catch (\Throwable $e) {
        error_log('[LicenseForge] daily cron failed: ' . $e->getMessage());
    }
});
