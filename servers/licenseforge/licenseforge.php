<?php
/**
 * License Forge - WHMCS provisioning module.
 * ==========================================
 *
 * Assign this module to a product to make that product licensed. From then on,
 * ordering the product issues a licence, suspending the service suspends the
 * licence, terminating it revokes the licence, and the customer sees their key
 * on the product details page.
 *
 * -----------------------------------------------------------------------------
 * WHERE THINGS LIVE
 * -----------------------------------------------------------------------------
 *
 * This module is the *bridge* between WHMCS's service lifecycle and the
 * licensing engine. It holds no licensing logic of its own - the engine lives
 * in the LicenseForge addon module (modules/addons/licenseforge), which must be
 * installed and activated for this to do anything at all.
 *
 * The licensing policy for a product - term, activation limits, binding rules,
 * version constraints, entitlements - is configured on the product's Module
 * Settings tab in WHMCS, and mirrored into the engine by
 * licenseforge_syncProduct(). The customer's own panel is rendered from
 * templates/clientarea.tpl.
 *
 * -----------------------------------------------------------------------------
 * THE FUNCTIONS WHMCS CALLS HERE
 * -----------------------------------------------------------------------------
 *
 * WHMCS discovers a provisioning module by this file's name matching its
 * directory, then calls `licenseforge_<Action>` for each lifecycle event. The
 * names are WHMCS's, not this module's, and their capitalisation is part of the
 * contract:
 *
 *   MetaData / ConfigOptions        describe the module and its settings
 *   CreateAccount                   a service was provisioned → issue a licence
 *   SuspendAccount / UnsuspendAccount / TerminateAccount
 *                                   service state changed → map to licence state
 *   ChangePackage                   upgraded or downgraded → re-apply policy
 *   Renew                           renewed → extend the licence
 *   ClientArea                      render the customer's panel
 *   AdminServicesTabFields          summary on the service's Module tab
 *   AdminCustomButtonArray          plus the two admin actions it exposes
 *
 * -----------------------------------------------------------------------------
 * THE RETURN CONTRACT
 * -----------------------------------------------------------------------------
 *
 * Every lifecycle function returns the exact string 'success' or an error
 * message. WHMCS treats anything other than 'success' as a failure and displays
 * it to staff, so an accidental 'Success', a true, or a stray empty return all
 * read as errors.
 *
 * None of them throws. WHMCS calls these from automation with no error handling
 * of its own, so an uncaught exception during a nightly run would abort whatever
 * batch it was part of. Every one therefore catches \Throwable and routes it
 * through licenseforge_fail(), which logs the detail and returns a short
 * message.
 *
 * @package LicenseForge
 * @author  Ahmad Abu Assab (Fast Hive) <https://fasthive.com>
 */

declare(strict_types=1);

use LicenseForge\Client\ServicePanel;
use LicenseForge\Database\Schema;
use LicenseForge\Licensing\ActivationService;
use LicenseForge\Licensing\LicenseManager;
use LicenseForge\Licensing\LicenseStatus;
use LicenseForge\Licensing\ModuleOptions;
use LicenseForge\Licensing\ProductConfig;
use LicenseForge\Licensing\Provisioner;
use LicenseForge\Licensing\ReissueService;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Input;
use LicenseForge\Support\Lang;
use LicenseForge\Support\View;

// Defined by WHMCS before it includes any module file; its absence means this
// was requested directly over HTTP.
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/*
 * Load the engine from the addon module.
 *
 * Returning quietly when the addon is absent is deliberate. WHMCS scans and
 * loads every server module it finds, including on pages that have nothing to
 * do with licensing, so a hard failure here would break unrelated admin screens
 * for an installation that merely has this directory present without the addon.
 *
 * The visible consequence is that the module's functions are never defined, so
 * assigning it to a product does nothing - which is the correct outcome, since
 * without the engine there is nothing it could do.
 */
$licenseforgeEngine = __DIR__ . '/../../addons/licenseforge/bootstrap.php';
if (!is_file($licenseforgeEngine)) {
    return;
}
require_once $licenseforgeEngine;

/**
 * Module metadata.
 *
 * Only what the provisioning system needs at runtime. The richer listing detail
 * - tagline, description, feature list, logo, author and support links - lives
 * in whmcs.json beside this file, which is where modern WHMCS reads it from.
 *
 * Several values below are empty on purpose rather than by omission; see the
 * inline notes.
 *
 * @return array<string,mixed> WHMCS module metadata.
 */
function licenseforge_MetaData(): array
{
    return [
        'DisplayName'   => 'License Forge',
        'APIVersion'    => '1.1',

        // Licensing is answered by this WHMCS installation itself. There is no
        // remote box to connect to, so WHMCS must not require a server to be
        // assigned to the product or ask staff to configure credentials for one.
        'RequiresServer' => false,

        // No ports, for the same reason: nothing is being connected to.
        'DefaultNonSSLPort' => '',
        'DefaultSSLPort'    => '',

        // No single sign-on - there is nothing to log in to. Empty labels are
        // what keeps the SSO buttons off the service and client area pages;
        // omitting these keys entirely would let WHMCS render its defaults.
        'ServiceSingleSignOnLabel' => '',
        'AdminSingleSignOnLabel'   => '',

        // How one of these accounts is identified in the admin service list.
        // The licence key is stored in the service's `domain` field, which is
        // WHMCS's general-purpose per-service identifier for modules that have
        // no actual domain.
        'ListAccountsUniqueIdentifierDisplayName' => 'License Key',
        'ListAccountsUniqueIdentifierField'       => 'domain',
        'ListAccountsProductField'                => 'configoption1',
    ];
}

/**
 * The licensing policy fields shown on the product's Module Settings tab.
 *
 * The definitions live in ModuleOptions rather than here so that one
 * declaration serves every reader: this tab, the API, cron, and the admin area
 * all resolve a product's policy from the same source and cannot drift apart.
 *
 * Note that WHMCS stores these values *positionally* (configoption1, 2, 3 …),
 * not by name. Inserting or reordering an option therefore shifts every value
 * after it, silently, on every product already configured. The addon's Products
 * page detects the resulting mismatch and lists the products needing a re-save.
 * Append new options at the end.
 *
 * @return array<string,array<string,string>> WHMCS config option definitions.
 */
function licenseforge_ConfigOptions(): array
{
    return ModuleOptions::definitions();
}

/**
 * Mirror the product's Module Settings into the licensing engine.
 *
 * WHMCS hands module settings to this file only as `$params` during a lifecycle
 * call, and offers no event for "an administrator saved the product". So the
 * mirror is refreshed opportunistically, from the calls that do arrive with
 * current settings - provisioning and package changes.
 *
 * That is why a settings change does not reach the engine until the next such
 * event for that product. Editing the policy in WHMCS and expecting existing
 * licences to change immediately is the usual surprise here.
 *
 * Silently does nothing without a product id, since there is nothing to key the
 * mirror on.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return void
 */
function licenseforge_syncProduct(array $params): void
{
    $productId = (int) ($params['pid'] ?? 0);
    if ($productId > 0) {
        ProductConfig::sync($productId, ModuleOptions::fromParams($params));
    }
}

/**
 * Issue the licence for a newly provisioned service.
 *
 * Called when WHMCS creates the account - on order acceptance, on first
 * payment, or when staff click Create.
 *
 * Migrations run first because this can be the module's very first execution on
 * an installation where the addon was activated but never opened, and issuing a
 * licence into tables that do not exist yet would fail for a reason with no
 * obvious connection to the order.
 *
 * A null licence means the product has no licensing policy configured, which is
 * a configuration mistake rather than a fault: the message says so, and the
 * service is left for staff to correct and retry.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_CreateAccount(array $params): string
{
    try {
        Schema::migrate();
        licenseforge_syncProduct($params);

        $license = Provisioner::provision((int) ($params['serviceid'] ?? 0), 'CreateAccount');
        if ($license === null) {
            return Lang::get('mod_not_configured', 'Licensing is not configured for this product.');
        }

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('CreateAccount', $e);
    }
}

/**
 * Suspend the licence when WHMCS suspends the service.
 *
 * Usually triggered by an overdue invoice. What "suspended" actually does to
 * the licence is not decided here - Provisioner::mapStatus() resolves it
 * through the `map_suspended` setting, because sellers differ on whether a
 * suspended service should stop the software at once or let it run out its
 * term.
 *
 * A licence marked *held* is exempt from this mapping, which is what stops an
 * administrator's deliberate decision being reverted by an automated billing
 * event.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_SuspendAccount(array $params): string
{
    try {
        Provisioner::mapStatus((int) ($params['serviceid'] ?? 0), 'map_suspended', 'service suspended');

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('SuspendAccount', $e);
    }
}

/**
 * Restore the licence when WHMCS unsuspends the service.
 *
 * The counterpart to suspension, typically after payment. Mapped through
 * `map_active` rather than assuming "active": a seller may want an unsuspended
 * service to return to a pending state pending re-activation.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_UnsuspendAccount(array $params): string
{
    try {
        Provisioner::mapStatus((int) ($params['serviceid'] ?? 0), 'map_active', 'service unsuspended');

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('UnsuspendAccount', $e);
    }
}

/**
 * Handle the service being terminated.
 *
 * The licence record and its history are not deleted - only its status changes,
 * through `map_terminated`. Keeping the record is what allows a terminated
 * service to be reinstated, and what preserves the audit trail for a licence
 * that is later disputed.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_TerminateAccount(array $params): string
{
    try {
        Provisioner::mapStatus((int) ($params['serviceid'] ?? 0), 'map_terminated', 'service terminated');

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('TerminateAccount', $e);
    }
}

/**
 * Re-apply licensing policy after an upgrade or downgrade.
 *
 * The product's settings are synced first, because the customer has moved to a
 * different product and the policy now governing this licence is the new one.
 * changePackage() then applies it to the existing licence - activation limits,
 * entitlements and term all follow the new tier while the key itself is kept,
 * so an upgrade does not force the customer to reconfigure their software.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_ChangePackage(array $params): string
{
    try {
        licenseforge_syncProduct($params);
        Provisioner::changePackage((int) ($params['serviceid'] ?? 0));

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('ChangePackage', $e);
    }
}

/**
 * Extend the licence when the service is renewed.
 *
 * Called on renewal payment. The extension is measured from the licence's
 * existing expiry rather than from today, so renewing early does not cost the
 * customer the remaining days they already paid for.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_Renew(array $params): string
{
    try {
        Provisioner::renew((int) ($params['serviceid'] ?? 0), 'Renew');

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('Renew', $e);
    }
}

// =============================================================================
// Client area
// =============================================================================

/**
 * Render the licensing panel inside the customer's product details page.
 *
 * Returns a template name and its variables rather than markup, so WHMCS
 * renders it within the active theme. The template is templates/clientarea.tpl
 * beside this file.
 *
 * The client id is taken from the WHMCS-supplied parameters where available and
 * falls back to the session. ServicePanel confirms the service actually belongs
 * to that client - this function passes an id, it does not grant access, and
 * the ownership check is not optional.
 *
 * The catch is more important here than elsewhere. This runs inside a page the
 * customer is already looking at, so an uncaught exception would replace their
 * product details page with a fatal error. Instead it degrades to the same
 * template carrying a single message, which keeps the surrounding page intact
 * and tells them to contact support rather than showing them a stack trace.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return array{templatefile:string,vars:array<string,mixed>} Template and vars.
 */
function licenseforge_ClientArea(array $params): array
{
    try {
        $panel = new ServicePanel(
            (int) ($params['serviceid'] ?? 0),
            (int) ($params['clientsdetails']['userid'] ?? ($_SESSION['uid'] ?? 0))
        );

        return $panel->render();
    } catch (\Throwable $e) {
        error_log('[LicenseForge] client area render failed: ' . $e->getMessage());

        return [
            'templatefile' => 'clientarea',
            'vars'         => [
                'lfHasLicense' => false,
                'lfMessages'   => [['type' => 'danger', 'text' => Lang::get('client_msg_unavailable')]],
            ],
        ];
    }
}

// =============================================================================
// Admin area
// =============================================================================

/**
 * Licence summary for the service's Module Information tab.
 *
 * Gives staff answering a ticket the four facts they need without leaving the
 * service page, plus a link into the addon for the full record.
 *
 * Every value is HTML-escaped before being returned. WHMCS prints these rows
 * without escaping them itself, and the values include a licence key and an
 * expiry date read from the database, so escaping here is the only thing
 * standing between stored data and the admin page.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return array<string,string> Row label => HTML value.
 */
function licenseforge_AdminServicesTabFields(array $params): array
{
    $license = LicenseManager::findByService((int) ($params['serviceid'] ?? 0));
    if ($license === null) {
        return [Lang::get('mod_license', 'License') => '<em>'
            . Input::e(Lang::get('mod_none_issued', 'No license has been issued for this service yet.')) . '</em>'];
    }

    $manage = View::moduleLink(['page' => 'license', 'id' => (int) $license->id]);

    // These array keys are the row labels WHMCS prints on the tab. Unlike the
    // Module Settings option names - which WHMCS uses as storage keys, and
    // which must therefore never be translated - these are display text only,
    // so passing them through Lang is safe.
    return [
        Lang::get('svc_key', 'License Key')
            => '<code>' . htmlspecialchars((string) $license->license_key, ENT_QUOTES, 'UTF-8') . '</code>',
        Lang::get('svc_status', 'License Status')
            => htmlspecialchars(LicenseStatus::label((string) $license->status), ENT_QUOTES, 'UTF-8')
            . ' &nbsp; <a href="' . htmlspecialchars($manage, ENT_QUOTES, 'UTF-8') . '">'
            . Input::e(Lang::get('svc_manage', 'Manage license')) . '</a>',
        Lang::get('svc_activations', 'Activations')
            => (int) $license->activation_count . ' / ' . (int) $license->max_activations,
        Lang::get('svc_expires', 'License Expires') => (bool) $license->is_lifetime
            ? Input::e(Lang::get('svc_never', 'Never'))
            : htmlspecialchars((string) ($license->expires_at ?? '-'), ENT_QUOTES, 'UTF-8'),
    ];
}

/**
 * The custom action buttons WHMCS shows on the service page.
 *
 * The array key is the button caption; the value is the suffix of the function
 * WHMCS calls, so 'reissueLicense' invokes licenseforge_reissueLicense().
 *
 * Only the caption is translated. The value is a function name and must stay
 * exactly as written - translating it would have WHMCS look for a function that
 * does not exist.
 *
 * @return array<string,string> Button caption => function suffix.
 */
function licenseforge_AdminCustomButtonArray(): array
{
    return [
        Lang::get('svc_btn_reissue', 'Reissue License') => 'reissueLicense',
        Lang::get('svc_btn_reset', 'Reset Activations') => 'resetActivations',
    ];
}

/**
 * Reissue the licence from the service page.
 *
 * Releases every installation and mints a new key. Note that `regenerate_key`
 * is true here, unlike the addon's own reissue form where it is a choice: this
 * button exists for the case where the key itself is the problem - leaked,
 * shared, or pasted somewhere public.
 *
 * The customer must update their software with the new key afterwards, so this
 * is not the right tool for "I need to move servers". Reset activations is.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or a message explaining the refusal.
 */
function licenseforge_reissueLicense(array $params): string
{
    try {
        $license = LicenseManager::findByService((int) ($params['serviceid'] ?? 0));
        if ($license === null) {
            return Lang::get('mod_no_license', 'No license exists for this service.');
        }

        $result = ReissueService::reissue($license, ReissueService::BY_ADMIN, [
            'reason'         => 'administrator reissue from the service page',
            'regenerate_key' => true,
            'initiator_id'   => (int) ($_SESSION['adminid'] ?? 0),
        ]);

        // ReissueService can decline for policy reasons - a cooldown still
        // running, or the reissue limit reached - which is a legitimate answer
        // rather than an error, so its message is returned as-is.
        return $result->isOk() ? 'success' : (string) $result->message();
    } catch (\Throwable $e) {
        return licenseforge_fail('reissueLicense', $e);
    }
}

/**
 * Release every installation on the licence, keeping the key.
 *
 * The usual answer to "I have run out of activations" - after a server
 * migration, a rebuild, or a customer who reinstalled without deactivating
 * first. The customer re-activates their existing key and needs to change
 * nothing in their configuration.
 *
 * Audited explicitly rather than relying on the release itself being logged,
 * because the fact that an administrator did this by hand from the service page
 * is the part worth recording.
 *
 * @param array<string,mixed> $params WHMCS module parameters.
 *
 * @return string 'success', or an error message shown to staff.
 */
function licenseforge_resetActivations(array $params): string
{
    try {
        $license = LicenseManager::findByService((int) ($params['serviceid'] ?? 0));
        if ($license === null) {
            return Lang::get('mod_no_license', 'No license exists for this service.');
        }

        ActivationService::releaseAll((int) $license->id, 'reset from the service page');
        Audit::log('activation.reset', (int) $license->id, Audit::RESULT_SUCCESS, [
            'service_id' => (int) ($params['serviceid'] ?? 0),
        ], Audit::ACTOR_ADMIN, (int) ($_SESSION['adminid'] ?? 0));

        return 'success';
    } catch (\Throwable $e) {
        return licenseforge_fail('resetActivations', $e);
    }
}

/**
 * Log a failure in full and return a short message for WHMCS to display.
 *
 * The single failure path for every lifecycle function above, so that none of
 * them can let an exception escape into WHMCS's automation.
 *
 * The split is deliberate: file and line go to the error log for whoever
 * debugs it, while the returned string carries only the message. WHMCS renders
 * that return value on the service page and stores it in the module log, and a
 * full server path there is of no use to the member of staff reading it.
 *
 * @param string     $operation The lifecycle function that failed, for the log.
 * @param \Throwable $e         The exception caught.
 *
 * @return string Short message for WHMCS to display to staff.
 */
function licenseforge_fail(string $operation, \Throwable $e): string
{
    error_log(sprintf('[LicenseForge] %s failed: %s in %s:%d', $operation, $e->getMessage(), $e->getFile(), $e->getLine()));

    return 'LicenseForge: ' . $e->getMessage();
}
