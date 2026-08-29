<?php

declare(strict_types=1);

namespace LicenseForge\Admin;

use LicenseForge\Api\Auth;
use LicenseForge\Api\Credentials;
use LicenseForge\Database\Schema;
use LicenseForge\Licensing\AbuseDetector;
use LicenseForge\Licensing\ActivationService;
use LicenseForge\Licensing\EmailTemplates;
use LicenseForge\Licensing\LicenseManager;
use LicenseForge\Licensing\LicenseStatus;
use LicenseForge\Licensing\Maintenance;
use LicenseForge\Licensing\ModuleOptions;
use LicenseForge\Licensing\Notifier;
use LicenseForge\Licensing\ProductConfig;
use LicenseForge\Licensing\Provisioner;
use LicenseForge\Licensing\ReissueService;
use LicenseForge\Licensing\ReleaseService;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Input;
use LicenseForge\Support\KeyGenerator;
use LicenseForge\Support\Lang;
use LicenseForge\Support\Settings;
use LicenseForge\Support\View;

/**
 * Request handler for the LicenseForge admin pages.
 *
 * WHMCS calls a single addon entry point for every page of the module, so this
 * class is both the router and the controller: {@see handle()} dispatches the
 * POST action first, then renders whichever page was requested.
 *
 * Responsibilities
 * ----------------
 *   - Validate and coerce request input, which arrives untrusted.
 *   - Call the licensing services that own the actual business rules.
 *   - Assemble view data and render a Smarty template.
 *   - Record every state change in the audit log.
 *
 * Deliberately holds no licensing logic of its own. Decisions about whether a
 * licence may be reissued, activated or released live in the classes under
 * LicenseForge\Licensing, so the same rules apply to the API, the client area
 * and the cron run. This class only decides what an administrator asked for and
 * what to show them afterwards.
 *
 * Conventions
 * -----------
 *   action*()   Handles one POST. Returns a URL to redirect to on success, or
 *               null to fall through and re-render the current page with an
 *               error attached.
 *   *Page()     Assembles view data and returns rendered HTML.
 *   Everything else is a private helper shared between the two.
 *
 * Security
 * --------
 * Every POST passes Input::requireCsrf() in handle() before dispatch, so no
 * individual action repeats that check. Output escaping happens when a message
 * is queued rather than in the template - see {@see success()} - so a message
 * cannot reach a view unescaped by omission.
 *
 * Note that access to this class is all-or-nothing: WHMCS gates the addon as a
 * whole through its own admin role permissions, and there is no finer
 * distinction here between viewing licences and rotating API credentials.
 */
final class Controller
{
    /** Rows per page on every paginated listing. */
    private const PER_PAGE = 25;

    /**
     * Most licences one bulk action will touch.
     *
     * Generous against a page of {@see PER_PAGE}, so it never interferes with
     * the UI - it bounds a request built by hand, and a future "select
     * everything matching this filter" control, from becoming a long-running
     * write holding row locks serially.
     */
    private const BULK_LIMIT = 500;

    /**
     * Phrase an administrator must type to turn request signing off.
     *
     * Disabling signing is the most consequential change this screen can make,
     * so it cannot be done by unticking a box. This is a guard against mistakes,
     * not against attack: a script can post the phrase as easily as a person can
     * type it, which is why the setting is additionally gated on a constant
     * defined outside the module - see {@see Auth::unsignedPermitted()}.
     */
    public const UNSIGNED_CONFIRMATION = 'ALLOW UNSIGNED REQUESTS';

    /**
     * Flash messages queued for the next render.
     *
     * @var list<array{type:string,text:string}> `text` is already escaped, or is
     *   trusted HTML queued through successHtml().
     */
    private array $messages = [];
    private int $adminId;
    private string $adminName;

    /**
     * @param array<string,mixed> $vars Addon variables supplied by WHMCS.
     *   Accepted for interface compatibility; the controller reads what it needs
     *   from the session instead.
     *
     * The administrator's identity is captured once, for audit attribution only.
     * It is never used to authorise anything - WHMCS has already decided whether
     * this person may open the addon before the request reaches here.
     */
    public function __construct(array $vars = [])
    {
        $this->adminId   = (int) ($_SESSION['adminid'] ?? 0);
        $this->adminName = (string) ($_SESSION['adminusername'] ?? 'admin');
    }

    /**
     * Entry point: run any submitted action, then render the requested page.
     *
     * POST handling comes first so that a successful action can redirect rather
     * than render, which is what stops a browser reload from repeating it.
     * Messages survive that redirect through the session - see {@see redirect()}.
     *
     * Every exception thrown by an action is caught and shown as an error on the
     * page, so a failure never leaves the administrator facing a blank screen or
     * a stack trace inside the WHMCS admin layout.
     *
     * @return string Rendered HTML for the WHMCS admin area to print.
     */
    public function handle(): string
    {
        $this->loadFlash();
        $page = Input::str('page', 'dashboard', 40);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                Input::requireCsrf();
                $redirect = $this->dispatchAction(Input::str('do', '', 60));
                if ($redirect !== null) {
                    return $this->redirect($redirect);
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
        }

        switch ($page) {
            case 'licenses':    return $this->licensesPage();
            case 'license':     return $this->licensePage();
            case 'products':    return $this->productsPage();
            case 'credentials': return $this->credentialsPage();
            case 'logs':        return $this->logsPage();
            case 'abuse':       return $this->abusePage();
            case 'reissues':    return $this->reissuesPage();
            case 'settings':    return $this->settingsPage();
            case 'dashboard':
            default:            return $this->dashboardPage();
        }
    }

    /**
     * Route one POST to its handler.
     *
     * The whitelist is the authorisation boundary for actions: an unrecognised
     * value produces an error rather than reaching any code, so nothing can be
     * invoked by naming it in the request.
     *
     * @param string $action Value of the `do` field.
     * @return string|null URL to redirect to, or null to re-render the page.
     */
    private function dispatchAction(string $action): ?string
    {
        switch ($action) {
            case 'license.create':      return $this->actionCreateLicense();
            case 'license.update':      return $this->actionUpdateLicense();
            case 'license.status':      return $this->actionLicenseStatus();
            case 'license.reissue':     return $this->actionLicenseReissue();
            case 'license.reset':       return $this->actionResetActivations();
            case 'license.features':    return $this->actionSyncFeatures();
            case 'license.delete':      return $this->actionDeleteLicense();
            case 'license.hold_release': return $this->actionReleaseHold();
            case 'license.email':       return $this->actionSendLicenseEmail();
            case 'service.suspend':     return $this->actionSuspendService();
            case 'license.bulk':        return $this->actionBulk();
            case 'activation.release':  return $this->actionReleaseActivation();
            case 'release.create':      return $this->actionCreateRelease();
            case 'release.delete':      return $this->actionDeleteRelease();
            case 'release.verify':      return $this->actionVerifyReleases();
            case 'credential.create':   return $this->actionCreateCredential();
            case 'credential.update':   return $this->actionUpdateCredential();
            case 'credential.rotate':   return $this->actionRotateCredential();
            case 'credential.delete':   return $this->actionDeleteCredential();
            case 'reissue.approve':     return $this->actionApproveReissue();
            case 'reissue.reject':      return $this->actionRejectReissue();
            case 'abuse.resolve':       return $this->actionResolveAbuse();
            case 'settings.save':       return $this->actionSaveSettings();
            case 'keys.generate':       return $this->actionGenerateKey();
            case 'keys.activate':       return $this->actionActivateKey();
            case 'maintenance.run':     return $this->actionRunMaintenance();
            case 'emails.install':      return $this->actionInstallEmailTemplates();
            default:
                $this->error(Lang::get('msg_unknown_action'));

                return null;
        }
    }

    /**
     * Issue a licence for an existing service that has none.
     *
     * A backfill tool, not a way to mint free-floating licences. A licence only
     * means anything attached to a service: that is what gives it an owner, a
     * product, a billing term and a page in the client area. So the service is
     * validated first, and everything else is derived from it by the same
     * {@see Provisioner} path that runs during normal provisioning - the licence
     * is identical to one issued automatically.
     *
     * Refuses when the service does not exist, its client has been deleted, its
     * product is not licensed, or it already holds a licence.
     */
    private function actionCreateLicense(): ?string
    {
        $serviceId = Input::int('service_id');
        $service   = Provisioner::service($serviceId);

        if ($service === null) {
            $this->error(Lang::get('msg_service_missing', '', ['id' => $serviceId]));

            return null;
        }

        if (!$this->clientExists((int) $service->userid)) {
            $this->error(Lang::get('msg_service_no_client', '', ['id' => $serviceId]));

            return null;
        }

        $product = ProductConfig::findByWhmcsProduct((int) $service->packageid);
        if ($product === null || !(bool) $product->licensing_enabled) {
            $this->error(Lang::get('msg_product_not_licensed'));

            return null;
        }

        if (LicenseManager::findByService($serviceId) !== null) {
            $this->error(Lang::get('msg_service_has_license', '', ['id' => $serviceId]));

            return null;
        }

        $license = Provisioner::provision($serviceId, 'admin.manual');
        if ($license === null) {
            $this->error(Lang::get('msg_issue_failed'));

            return null;
        }

        $this->applyIssueOverrides($license);

        Audit::log('license.issued_manually', (int) $license->id, Audit::RESULT_SUCCESS, [
            'service_id' => $serviceId,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_license_issued', '', ['key' => $license->license_key, 'id' => $serviceId]));

        return View::moduleLink(['page' => 'license', 'id' => (int) $license->id]);
    }

    /**
     * Apply the optional overrides from the issue form to a freshly created
     * licence.
     *
     * Only fields the administrator actually filled in are written, so anything
     * left blank keeps the value the product policy produced. Applied after
     * provisioning rather than passed into it, so the provisioning path stays
     * identical whether or not a human was involved.
     */
    private function applyIssueOverrides(object $license): void
    {
        $updates = [];

        if (Input::str('max_activations', '', 10) !== '') {
            $updates['max_activations'] = max(0, Input::int('max_activations'));
        }
        if (Input::str('max_reissues', '', 10) !== '') {
            $updates['max_reissues'] = max(0, Input::int('max_reissues'));
        }
        if (Input::str('admin_notes', '', 2000) !== '') {
            $updates['admin_notes'] = Input::str('admin_notes', '', 2000);
        }

        if (Input::bool('is_lifetime')) {
            $updates['expires_at']  = null;
            $updates['is_lifetime'] = 1;
        } elseif (Input::str('expires_at', '', 20) !== '') {
            $updates['expires_at']  = Input::toDateTime(Input::str('expires_at', '', 20));
            $updates['is_lifetime'] = 0;
        }

        if ($updates !== []) {
            $updates['updated_at'] = Db::now();
            Db::table('licenses')->where('id', (int) $license->id)->update($updates);
        }
    }

    /**
     * Does this WHMCS client still exist?
     *
     * A service can outlive its client row, and issuing a licence owned by
     * nobody produces a record that cannot be viewed, emailed or supported.
     * Treats a query failure as "no", since issuing on an unverified owner is
     * the worse outcome.
     */
    private function clientExists(int $clientId): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        try {
            return Db::connection()->table('tblclients')->where('id', $clientId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Save the licence edit form.
     *
     * Domains and IP addresses are normalised through {@see \LicenseForge\Support\Net}
     * rather than stored as typed, so the values written here match the form the
     * validation path compares against - otherwise a licence could be locked to
     * a domain that never matches.
     *
     * The activation count is recalculated afterwards because lowering the
     * activation limit does not itself retire any installation; the stored
     * counter has to be brought back in line with the rows that actually exist.
     */
    private function actionUpdateLicense(): ?string
    {
        $id      = Input::int('id');
        $license = LicenseManager::find($id);
        if ($license === null) {
            $this->error(Lang::get('msg_license_missing'));

            return null;
        }

        $expires = Input::str('expires_at', '', 20);
        $updates = [
            'max_activations'   => max(0, Input::int('max_activations', (int) $license->max_activations)),
            'max_reissues'      => max(0, Input::int('max_reissues', (int) $license->max_reissues)),
            'primary_domain'    => \LicenseForge\Support\Net::normaliseDomain(Input::str('primary_domain', '', 190)) ?: null,
            'primary_ip'        => \LicenseForge\Support\Net::normaliseIp(Input::str('primary_ip', '', 45)) ?: null,
            'allowed_domains'   => json_encode(ActivationService::normaliseDomainList(Input::toList(Input::str('allowed_domains', '', 2000)))),
            'allowed_ips'       => json_encode(Input::toList(Input::str('allowed_ips', '', 2000))),
            'min_version'       => Input::str('min_version', '', 32) ?: null,
            'max_version'       => Input::str('max_version', '', 32) ?: null,
            'allowed_versions'  => Input::str('allowed_versions', '', 191) ?: null,
            'notes'             => Input::str('notes', '', 2000),
            'admin_notes'       => Input::str('admin_notes', '', 2000),
            'is_lifetime'       => Input::bool('is_lifetime') ? 1 : 0,
            'updated_at'        => Db::now(),
        ];

        if (Input::bool('is_lifetime')) {
            $updates['expires_at'] = null;
        } elseif ($expires !== '') {
            $updates['expires_at'] = Input::toDateTime($expires);
        }

        Db::table('licenses')->where('id', $id)->update($updates);
        LicenseManager::recalculateActivationCount($id);

        Audit::log('license.updated', $id, Audit::RESULT_SUCCESS, [
            'fields' => array_keys($updates),
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_license_updated'));

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Change a licence's status, and bring the offline hold in line with it.
     *
     * A status change alone is not enough. An installation holding a signed
     * offline token does not ask the server anything, so it keeps working until
     * that token expires - exactly the wrong behaviour for a licence that was
     * just suspended or revoked. Restrictive statuses therefore also place a
     * hold, and permissive ones release it.
     *
     * The administrator is told the horizon: the date until which existing
     * offline tokens remain valid despite this change. That number is a property
     * of tokens already issued and cannot be shortened retrospectively, so
     * stating it is the only honest option.
     */
    private function actionLicenseStatus(): ?string
    {
        $id     = Input::int('id');
        $status = Input::str('status', '', 20);
        $reason = Input::str('reason', '', 500);

        if (!LicenseStatus::exists($status)) {
            $this->error(Lang::get('msg_unknown_status'));

            return null;
        }

        if (!LicenseManager::setStatus($id, $status, $reason, ['admin_id' => $this->adminId])) {
            $this->error(Lang::get('msg_status_not_allowed'));

            return View::moduleLink(['page' => 'license', 'id' => $id]);
        }

        // A restrictive status must also stop the offline path, or an installation
        // holding a signed token keeps running until that token expires.
        if (in_array($status, LicenseManager::restrictiveStatuses(), true)) {
            LicenseManager::hold($id, $reason !== '' ? $reason : 'set by ' . $this->adminName);

            $message = Lang::get('msg_status_changed_held', '', ['status' => LicenseStatus::label($status)]);
            $license = LicenseManager::find($id);
            // State the horizon plainly. Tokens already issued cannot be recalled, so
            // this is the date the change actually takes effect everywhere.
            $horizon = $license !== null ? LicenseManager::offlineHorizon($license) : null;
            if ($horizon !== null) {
                $message .= ' ' . Lang::get('msg_offline_until_horizon', '', [
                    'date' => gmdate('Y-m-d H:i', $horizon) . ' UTC',
                ]);
            }

            $this->success($message);
        } else {
            LicenseManager::releaseHold($id, 'status set to ' . $status . ' by ' . $this->adminName);
            $this->success(Lang::get('msg_status_changed', '', ['status' => LicenseStatus::label($status)]));
        }

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Reissue a licence on the administrator's behalf.
     *
     * Runs as BY_ADMIN, which bypasses the self-service policy and the customer
     * quota but not the licence's own admissibility - {@see ReissueService}
     * re-checks that under a row lock regardless of who asked.
     */
    private function actionLicenseReissue(): ?string
    {
        $id      = Input::int('id');
        $license = LicenseManager::find($id);
        if ($license === null) {
            $this->error(Lang::get('msg_license_missing'));

            return null;
        }

        $result = ReissueService::reissue($license, ReissueService::BY_ADMIN, [
            'reason'         => Input::str('reason', 'administrator reissue', 500),
            'activation_id'  => Input::int('activation_id'),
            'regenerate_key' => Input::bool('regenerate_key'),
            'new_domain'     => Input::str('new_domain', '', 190),
            'initiator_id'   => $this->adminId,
        ]);

        if ($result->isOk()) {
            $this->success($result->get('key_rotated')
                ? Lang::get('msg_reissued_new_key', '', ['key' => (string) $result->get('new_key')])
                : Lang::get('msg_reissued'));
        } else {
            $this->error((string) $result->message());
        }

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Release every installation bound to a licence, freeing all its slots.
     *
     * The customer's software will re-activate on its next check-in, so this is
     * the usual remedy when someone has moved servers and stranded their
     * activations.
     */
    private function actionResetActivations(): ?string
    {
        $id       = Input::int('id');
        $released = ActivationService::releaseAll($id, 'reset by ' . $this->adminName);
        $this->success(Lang::get('msg_activations_released', '', ['count' => $released]));

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Replace a licence's entitlements with the submitted set.
     *
     * Accepts either the checkbox array the form posts or a comma-separated
     * string, so the same action serves the UI and a hand-built request. Both
     * are bounded in length and count before reaching the database.
     */
    private function actionSyncFeatures(): ?string
    {
        $id  = Input::int('id');
        $raw = $_POST['features'] ?? [];
        $features = is_array($raw)
            ? array_slice(array_map(static fn ($v) => mb_substr(trim((string) $v), 0, 64), $raw), 0, 200)
            : Input::toList((string) $raw);

        LicenseManager::syncFeatures($id, $features);
        $this->success(Lang::get('msg_entitlements_updated'));

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Send one of the module's licence emails to the customer on demand.
     *
     * The template name is checked against the registered definitions, so only
     * the module's own emails can be triggered from here.
     */
    private function actionSendLicenseEmail(): ?string
    {
        $id       = Input::int('id');
        $template = Input::str('template', '', 64);
        $license  = LicenseManager::find($id);

        if ($license === null) {
            $this->error(Lang::get('msg_license_missing'));

            return View::moduleLink(['page' => 'licenses']);
        }

        if (!array_key_exists($template, EmailTemplates::definitions())) {
            $this->error(Lang::get('msg_unknown_template'));

            return View::moduleLink(['page' => 'license', 'id' => $id]);
        }

        if (Notifier::sendNow($license, $template)) {
            $this->success(Lang::get('msg_email_sent'));
        } else {
            $this->error(Lang::get('msg_email_failed'));
        }

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Lift the offline hold without changing the licence's status.
     *
     * Used when a hold was placed by a status change that has since been
     * reversed, or applied automatically and judged unnecessary.
     */
    private function actionReleaseHold(): ?string
    {
        $id = Input::int('id');
        if (LicenseManager::releaseHold($id, 'released by ' . $this->adminName)) {
            $this->success(Lang::get('msg_hold_released'));
        } else {
            $this->error(Lang::get('msg_not_held'));
        }

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Suspend the WHMCS service behind a licence.
     *
     * Suspending the licence stops the software working; suspending the service
     * is what stops the billing and the hosting alongside it. That belongs to
     * WHMCS, so it is delegated to the local API rather than reimplemented.
     *
     * localAPI() is checked for rather than assumed: the function is unavailable
     * in some execution contexts, and calling it there is a fatal error rather
     * than a failed request.
     */
    private function actionSuspendService(): ?string
    {
        $id      = Input::int('id');
        $license = LicenseManager::find($id);
        if ($license === null || (int) $license->service_id <= 0) {
            $this->error(Lang::get('msg_no_service'));

            return View::moduleLink(['page' => 'license', 'id' => $id]);
        }

        if (!function_exists('localAPI')) {
            $this->error(Lang::get('msg_api_unavailable'));

            return View::moduleLink(['page' => 'license', 'id' => $id]);
        }

        $reason = Input::str('reason', 'suspended alongside its license', 200);

        try {
            $result = localAPI('ModuleSuspend', [
                'serviceid'       => (int) $license->service_id,
                'suspendreason'   => $reason,
            ]);
        } catch (\Throwable $e) {
            error_log('[LicenseForge] ModuleSuspend failed: ' . $e->getMessage());
            $result = ['result' => 'error', 'message' => $e->getMessage()];
        }

        if ((string) ($result['result'] ?? '') === 'success') {
            Audit::log('service.suspended_by_admin', $id, Audit::RESULT_SUCCESS, [
                'service_id' => (int) $license->service_id, 'reason' => $reason,
            ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);
            $this->success(Lang::get('msg_service_suspended', '', ['id' => (int) $license->service_id]));
        } else {
            $this->error(Lang::get('msg_suspend_refused', '', ['reason' => (string) ($result['message'] ?? 'unknown error')]));
        }

        return View::moduleLink(['page' => 'license', 'id' => $id]);
    }

    /**
     * Soft-delete a licence.
     *
     * The row is retained and excluded from every listing rather than removed,
     * so the audit trail, the validation history and the reissue history stay
     * meaningful - all of which reference a licence that would otherwise vanish.
     */
    private function actionDeleteLicense(): ?string
    {
        $id = Input::int('id');
        LicenseManager::softDelete($id, Input::str('reason', '', 500));
        $this->success(Lang::get('msg_license_deleted'));

        return View::moduleLink(['page' => 'licenses']);
    }

    /**
     * Apply one operation to a set of selected licences.
     *
     * Each licence is processed independently and failures are skipped rather
     * than aborting the batch, so one licence in a state that forbids the
     * transition does not silently discard the rest of the work.
     *
     * The status operations pair with the offline hold for the same reason as
     * {@see actionLicenseStatus()}: without it, suspended installations keep
     * running on tokens already issued.
     *
     * Only the batch is audited, not each licence - the per-licence records are
     * written by the LicenseManager calls themselves.
     */
    private function actionBulk(): ?string
    {
        $ids       = Input::idList('license_ids');
        $operation = Input::str('bulk_action', '', 30);
        if ($ids === [] || $operation === '') {
            $this->error(Lang::get('msg_select_licenses'));

            return null;
        }

        /*
         * Capped, and the caller is told when the cap applied.
         *
         * Each licence here costs several queries, and `reset` opens a
         * transaction and takes a row lock per activation - so a large selection
         * is a long request holding locks serially. Exceeding max_execution_time
         * part-way leaves the batch half applied: every individual licence is
         * atomic, so nothing is corrupt, but without this the administrator
         * would have no way to tell which half. The listing pages 25 at a time,
         * so this bounds a hand-built request rather than anything the UI can
         * produce.
         */
        $requested = count($ids);
        if ($requested > self::BULK_LIMIT) {
            $ids = array_slice($ids, 0, self::BULK_LIMIT);
        }

        $affected = 0;
        foreach ($ids as $id) {
            switch ($operation) {
                case 'suspend':
                    if (LicenseManager::suspend((int) $id, 'bulk action')) {
                        LicenseManager::hold((int) $id, 'bulk suspend by ' . $this->adminName);
                        $affected++;
                    }
                    break;
                case 'activate':
                    if (LicenseManager::reactivate((int) $id, 'bulk action')) {
                        LicenseManager::releaseHold((int) $id, 'bulk activate by ' . $this->adminName);
                        $affected++;
                    }
                    break;
                case 'revoke':
                    if (LicenseManager::revoke((int) $id, 'bulk action')) {
                        LicenseManager::hold((int) $id, 'bulk revoke by ' . $this->adminName);
                        $affected++;
                    }
                    break;
                case 'reset':
                    $affected += ActivationService::releaseAll((int) $id, 'bulk reset') > 0 ? 1 : 0;
                    break;
                case 'delete':
                    $affected += LicenseManager::softDelete((int) $id, 'bulk delete') ? 1 : 0;
                    break;
                default:
                    // Reached only by a hand-built request: the form offers a fixed
                    // list. Refused rather than looped over silently, which would
                    // report "0 updated" and look like the licences were the problem.
                    $this->error(Lang::get('msg_unknown_action'));

                    return null;
            }
        }

        Audit::log('license.bulk_action', null, Audit::RESULT_SUCCESS, [
            'operation' => $operation,
            'requested' => $requested,
            'processed' => count($ids),
            'affected'  => $affected,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_licenses_updated', '', ['count' => $affected]));

        if ($requested > count($ids)) {
            $this->warning(Lang::get('msg_bulk_capped', '', [
                'processed' => count($ids),
                'requested' => $requested,
            ]));
        }

        return View::moduleLink(['page' => 'licenses']);
    }

    /**
     * Release a single installation, freeing its activation slot.
     *
     * {@see ActivationService::release()} re-reads the row under the licence
     * lock and refuses one that is no longer active, so a double submission or
     * two administrators acting at once cannot release the same slot twice.
     */
    private function actionReleaseActivation(): ?string
    {
        $activationId = Input::int('activation_id');
        $licenseId    = Input::int('id');

        if (ActivationService::release($activationId, 'released by ' . $this->adminName)) {
            $this->success(Lang::get('msg_installation_deactivated'));
        } else {
            $this->error(Lang::get('msg_installation_not_active'));
        }

        return View::moduleLink(['page' => 'license', 'id' => $licenseId]);
    }

    /**
     * Register a downloadable release file.
     *
     * The path is stored relative to the configured release directory and
     * resolved through {@see ReleaseService}, which refuses anything escaping
     * that directory. An absolute path is never accepted from the form.
     *
     * Size and SHA-256 are recorded at registration. The size is what the
     * download endpoint checks on every request; the hash is what
     * {@see actionVerifyReleases()} checks on demand.
     */
    private function actionCreateRelease(): ?string
    {
        if (ReleaseService::directory() === '') {
            $this->error(Lang::get('msg_release_dir_unset'));

            return View::moduleLink(['page' => 'settings']);
        }

        $productId = Input::int('product_id');
        $relative  = Input::str('file_path', '', 500);
        $label     = Input::str('label', '', 190);

        if ($productId <= 0 || $label === '' || $relative === '') {
            $this->error(Lang::get('msg_release_incomplete'));

            return View::moduleLink(['page' => 'products']);
        }

        $path = ReleaseService::resolvePath($relative);
        if ($path === null) {
            $this->error(Lang::get('msg_release_unreadable', '', ['path' => $relative]));

            return View::moduleLink(['page' => 'products']);
        }

        $id = (int) Db::table('releases')->insertGetId([
            'product_id'  => $productId,
            'version'     => Input::str('version', '', 32) ?: null,
            'label'       => $label,
            'file_path'   => $relative,
            'size_bytes'  => (int) (@filesize($path) ?: 0),
            'sha256'      => hash_file('sha256', $path) ?: null,
            'is_active'   => 1,
            'created_at'  => Db::now(),
            'updated_at'  => Db::now(),
        ]);

        Audit::log('release.added', null, Audit::RESULT_SUCCESS, [
            'release_id' => $id, 'product_id' => $productId, 'label' => $label,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_release_added', '', ['label' => $label]));

        return View::moduleLink(['page' => 'products']);
    }

    /**
     * Unregister a release.
     *
     * Removes the record only. The file itself is left on disk: this module did
     * not put it there, and deleting a customer's build because a row was
     * removed from a listing is not a recoverable mistake.
     */
    private function actionDeleteRelease(): ?string
    {
        $id = Input::int('release_id');
        Db::table('releases')->where('id', $id)->delete();

        Audit::log('release.removed', null, Audit::RESULT_SUCCESS, [
            'release_id' => $id,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_release_removed'));

        return View::moduleLink(['page' => 'products']);
    }

    /**
     * Re-hash every registered release and report what no longer matches.
     *
     * Run on demand rather than per download: hashing a multi-gigabyte archive
     * on every request would cost more than serving it. The per-download check
     * is the recorded file size, which costs a stat() - see
     * {@see ReleaseService::authorise()}.
     *
     * Releases registered without a hash are reported as skipped rather than
     * counted as passing, since there is nothing to compare them against.
     */
    private function actionVerifyReleases(): ?string
    {
        $checked = 0;
        $failed  = [];
        $skipped = 0;

        foreach (ReleaseService::verifyAll() as $row) {
            $result = $row['result'];

            if ($result['status'] === 'no_hash_recorded') {
                $skipped++;
                continue;
            }

            $checked++;
            if (!$result['ok']) {
                $failed[] = (string) $row['release']->label . ' (' . $result['status'] . ')';
            }
        }

        Audit::log('release.integrity_checked', null, $failed === [] ? Audit::RESULT_SUCCESS : Audit::RESULT_FAILURE, [
            'checked' => $checked,
            'failed'  => count($failed),
            'skipped' => $skipped,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        if ($failed !== []) {
            $this->error(Lang::get('msg_release_integrity_failed', '', [
                'count' => (string) count($failed),
                'list'  => implode(', ', $failed),
            ]));

            return View::moduleLink(['page' => 'products']);
        }

        $this->success($skipped > 0
            ? Lang::get('msg_release_integrity_ok_some', '', [
                'count' => (string) $checked, 'skipped' => (string) $skipped,
            ])
            : Lang::get('msg_release_integrity_ok', '', ['count' => (string) $checked]));

        return View::moduleLink(['page' => 'products']);
    }

    /**
     * Issue an API credential.
     *
     * The secret is displayed once, here, and never again: only a hash of it is
     * stored, so it cannot be shown later even to an administrator. Losing it
     * means rotating the credential.
     */
    private function actionCreateCredential(): ?string
    {
        $created = Credentials::create([
            'name'        => Input::str('name', 'API credential', 190),
            'scopes'      => Input::str('scopes', 'activate,check', 190),
            'allowed_ips' => Input::str('allowed_ips', '', 500),
            'allowed_products'   => Input::str('allowed_products', '', 500),
            'allow_all_products' => Input::bool('allow_all_products'),
            'rate_limit'  => Input::int('rate_limit'),
            'expires_at'  => Input::str('expires_at', '', 20),
            'is_active'   => true,
        ]);

        $this->successHtml(
            Input::e(Lang::get('msg_credential_created')) . '<br>'
            . '<code>' . Input::e(Lang::get('msg_api_key', 'API key')) . ': ' . Input::e($created['api_key']) . '</code><br>'
            . '<code>' . Input::e(Lang::get('msg_api_secret', 'API secret')) . ': ' . Input::e($created['api_secret']) . '</code>'
        );

        return View::moduleLink(['page' => 'credentials']);
    }

    /**
     * Save changes to a credential's scopes, restrictions and expiry.
     *
     * The secret is untouched; replacing it is {@see actionRotateCredential()}.
     */
    private function actionUpdateCredential(): ?string
    {
        Credentials::update(Input::int('credential_id'), [
            'name'        => Input::str('name', '', 190),
            'scopes'      => Input::str('scopes', '', 190),
            'allowed_ips' => Input::str('allowed_ips', '', 500),
            'allowed_products'   => Input::str('allowed_products', '', 500),
            'allow_all_products' => Input::bool('allow_all_products'),
            'rate_limit'  => Input::int('rate_limit'),
            'expires_at'  => Input::str('expires_at', '', 20),
            'is_active'   => Input::bool('is_active'),
        ]);
        $this->success(Lang::get('msg_credential_updated'));

        return View::moduleLink(['page' => 'credentials']);
    }

    /**
     * Replace a credential's secret, keeping its key and settings.
     *
     * Every client using the old secret stops authenticating immediately, so
     * this is the response to a leak rather than routine maintenance. The
     * replacement is displayed once, as at creation.
     */
    private function actionRotateCredential(): ?string
    {
        $secret = Credentials::rotate(Input::int('credential_id'));
        if ($secret === null) {
            $this->error(Lang::get('msg_credential_missing'));
        } else {
            $this->successHtml(Input::e(Lang::get('msg_secret_rotated')) . ' <code>' . Input::e($secret) . '</code>');
        }

        return View::moduleLink(['page' => 'credentials']);
    }

    /**
     * Delete a credential permanently, refusing every client that holds it.
     */
    private function actionDeleteCredential(): ?string
    {
        Credentials::delete(Input::int('credential_id'));
        $this->success(Lang::get('msg_credential_deleted'));

        return View::moduleLink(['page' => 'credentials']);
    }

    /**
     * Approve a customer's pending reissue request.
     *
     * Approval is not the same as success: {@see ReissueService} re-validates
     * the licence under a row lock and can still refuse, in which case the
     * request is recorded as failed and the reason is shown here.
     */
    private function actionApproveReissue(): ?string
    {
        $result = ReissueService::approve(Input::int('request_id'), $this->adminId);
        $result->isOk() ? $this->success(Lang::get('msg_reissue_approved')) : $this->error((string) $result->message());

        return View::moduleLink(['page' => 'reissues']);
    }

    /**
     * Reject a pending reissue request, with an optional reason for the record.
     */
    private function actionRejectReissue(): ?string
    {
        ReissueService::reject(Input::int('request_id'), $this->adminId, Input::str('reason', '', 300));
        $this->success(Lang::get('msg_reissue_rejected'));

        return View::moduleLink(['page' => 'reissues']);
    }

    /**
     * Mark an abuse event as dealt with, removing it from the open list.
     */
    private function actionResolveAbuse(): ?string
    {
        AbuseDetector::resolve(Input::int('event_id'), $this->adminId);
        $this->success(Lang::get('msg_event_resolved'));

        return View::moduleLink(['page' => 'abuse']);
    }

    /**
     * Save the settings form.
     *
     * Iterates the known defaults rather than the posted fields, so an
     * unrecognised key cannot introduce a setting and an unchecked box is still
     * recorded as off - a checkbox posts nothing when clear, which would
     * otherwise read as "leave unchanged" and make it impossible to switch
     * anything off.
     *
     * A setting that fails validation is added to `$frozen` and skipped, leaving
     * its stored value intact while the rest of the form still saves. That
     * matters for the security-relevant ones: a rejected key format or an
     * unconfirmed disabling of request signing must not take effect just because
     * it was submitted alongside valid changes.
     */
    private function actionSaveSettings(): ?string
    {
        $changed = [];
        $frozen  = $this->unsafeKeyFormatSettings();

        if ($this->unconfirmedAuthDisable()) {
            $frozen[] = 'require_api_auth';
        }

        if (isset($_POST['s_release_dir'])) {
            $candidate = Input::str('s_release_dir', '', 500);
            $problem   = $candidate === '' ? '' : ReleaseService::problemWith($candidate);

            if ($problem !== '') {
                $frozen[] = 'release_dir';

                $replace  = ['path' => $candidate];
                $messages = [
                    'missing'         => Lang::get('msg_release_dir_missing', '', $replace),
                    'unreadable'      => Lang::get('msg_release_dir_unreadable', '', $replace),
                    'inside_whmcs'    => Lang::get('msg_release_dir_inside_whmcs', '', $replace),
                    'inside_web_root' => Lang::get('msg_release_dir_inside_web_root', '', $replace),
                    'not_set'         => Lang::get('msg_release_dir_not_set', '', $replace),
                ];

                $this->error($messages[$problem] ?? $messages['missing']);
            }
        }

        foreach (array_keys(Settings::defaults()) as $key) {
            if (in_array($key, $frozen, true)) {
                continue;
            }
            if (!isset($_POST['s_' . $key])) {

                // Absent means either "cleared checkbox" or "not on this form";
                // only the former may be written as off.
                if (in_array($key, $this->booleanSettings(), true)) {
                    Settings::set($key, '0');
                    $changed[] = $key;
                }
                continue;
            }
            $value = Input::str('s_' . $key, '', 2000);

            // A status mapping naming a status that does not exist would fail silently
            // at provisioning time, long after this screen was saved.
            if (strncmp($key, 'map_', 4) === 0 && !LicenseStatus::exists($value)) {
                $this->error(Lang::get('msg_bad_mapping', '', ['setting' => Input::e($key), 'value' => Input::e($value)]));
                continue;
            }

            Settings::set($key, $value);
            $changed[] = $key;
        }

        Audit::log('settings.updated', null, Audit::RESULT_SUCCESS, [
            'keys' => $changed,
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

        $this->success(Lang::get('msg_settings_saved'));

        return View::moduleLink(['page' => 'settings']);
    }

    /**
     * Is this request trying to disable request signing without confirming it?
     *
     * Returns true when the setting should be frozen. Confirming it does not
     * necessarily make unsigned requests possible: the API additionally requires
     * a constant defined in the WHMCS configuration file, which this module
     * cannot write. When that constant is absent the setting still saves, the
     * API keeps enforcing signatures, and the administrator is warned - because
     * a setting that says one thing while the server does another is a
     * misconfiguration someone needs to be told about.
     *
     * Both outcomes are audited, including which one actually took effect.
     */
    private function unconfirmedAuthDisable(): bool
    {
        $currentlyOn = Settings::bool('require_api_auth', true);
        $stayingOn   = isset($_POST['s_require_api_auth']);

        if (!$currentlyOn || $stayingOn) {
            return false;
        }

        if (Input::str('unsigned_confirm', '', 60) === self::UNSIGNED_CONFIRMATION) {

            // The setting and the server can disagree; record which one governs.
            $permitted = Auth::unsignedPermitted();

            Audit::log('settings.api_auth_disabled', null, Audit::RESULT_SUCCESS, [
                'note'      => $permitted
                    ? 'The licensing API now accepts unsigned requests.'
                    : 'Setting saved, but ' . Auth::UNSIGNED_CONSTANT . ' is not defined, so signing is still enforced.',
                'effective' => $permitted,
            ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);

            if (!$permitted) {
                $this->warning(Lang::get('msg_unsigned_needs_constant', '', [
                    'constant' => Auth::UNSIGNED_CONSTANT,
                ]));
            }

            return false;
        }

        $this->error(Lang::get('msg_unsigned_confirm', '', ['phrase' => self::UNSIGNED_CONFIRMATION]));

        return true;
    }

    /**
     * Reject a licence key format that would not be unguessable.
     *
     * Key length, segment count and alphabet together determine how much entropy
     * a generated key carries, and a weak combination is not visibly wrong - the
     * keys still look like keys. The candidate format is therefore built and
     * measured before it is stored.
     *
     * Only the fields actually posted are overridden; the rest come from the
     * current settings, so the format is measured as it would really be rather
     * than as the form fragment describes it.
     *
     * @return list<string> Field names to freeze, empty when the format is sound.
     */
    private function unsafeKeyFormatSettings(): array
    {
        $fields = ['key_segments', 'key_segment_length', 'key_alphabet'];

        // Nothing to check when the key-format section was not on the form.
        $posted = array_filter($fields, static fn (string $f): bool => isset($_POST['s_' . $f]));
        if ($posted === []) {
            return [];
        }

        $candidate = new KeyGenerator([
            'segments' => isset($_POST['s_key_segments'])
                ? Input::int('s_key_segments')
                : Settings::int('key_segments', 4),
            'segment_length' => isset($_POST['s_key_segment_length'])
                ? Input::int('s_key_segment_length')
                : Settings::int('key_segment_length', 4),
            'alphabet' => isset($_POST['s_key_alphabet'])
                ? Input::str('s_key_alphabet', '', 20)
                : (string) Settings::get('key_alphabet', 'crockford'),
        ]);

        $bits = $candidate->entropyBits();
        if ($bits >= KeyGenerator::MINIMUM_ENTROPY_BITS) {
            return [];
        }

        $this->error(Lang::get('msg_weak_key_format', '', [
            'bits'    => number_format($bits, 1),
            'minimum' => number_format(KeyGenerator::MINIMUM_ENTROPY_BITS, 0),
        ]));

        return $fields;
    }

    /**
     * Settings rendered as checkboxes.
     *
     * Needed because a clear checkbox posts nothing at all, which is
     * indistinguishable from a field that was never on the form. Anything listed
     * here is written as "0" when absent instead of being left unchanged.
     *
     * @return list<string>
     */
    private function booleanSettings(): array
    {
        return [
            'module_enabled', 'key_uppercase', 'lock_domain', 'lock_ip', 'lock_directory', 'lock_machine',
            'allow_subdomains', 'allow_www_normalisation', 'allow_local_domains', 'reissue_self_service',
            'reissue_requires_approval', 'require_api_auth', 'require_install_proof',
            'abuse_auto_suspend', 'download_protection',
            'rate_limit_fail_closed',
            'show_key_in_service_list',
            'log_validations', 'notify_enabled',
        ];
    }

    /**
     * Generate a signing key pair and make it active.
     *
     * Previous keys are retained so that offline tokens already issued under
     * them continue to verify; the key id travels inside each token.
     */
    private function actionGenerateKey(): ?string
    {
        $key = Crypto::generateSigningKey(Input::str('algorithm', '', 20) ?: null, true);
        Audit::log('signing_key.generated', null, Audit::RESULT_SUCCESS, [
            'key_id' => $key['id'], 'algorithm' => $key['algorithm'],
        ], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);
        $this->success(Lang::get('msg_key_generated', '', ['algorithm' => $key['algorithm']]));

        return View::moduleLink(['page' => 'settings']);
    }

    /**
     * Make an existing signing key the active one, for rotation or rollback.
     */
    private function actionActivateKey(): ?string
    {
        $id = Input::int('key_id');
        Crypto::activateSigningKey($id);
        Audit::log('signing_key.activated', null, Audit::RESULT_SUCCESS, ['key_id' => $id], Audit::ACTOR_ADMIN, $this->adminId, $this->adminName);
        $this->success(Lang::get('msg_key_activated'));

        return View::moduleLink(['page' => 'settings']);
    }

    /**
     * The module's email templates, as select options for the licence page.
     *
     * @return list<array{value:string,label:string}>
     */
    private function emailOptions(): array
    {
        $options = [];
        foreach (EmailTemplates::definitions() as $setting => $definition) {
            $options[] = ['value' => $setting, 'label' => $definition['label']];
        }

        return $options;
    }

    /**
     * Create the module's email templates in WHMCS, or restore them to default.
     *
     * Templates an administrator has customised are kept rather than overwritten
     * unless a reset was explicitly requested. The outcome is summarised per
     * category, so it is clear what was created, repaired, reset and left alone.
     */
    private function actionInstallEmailTemplates(): ?string
    {
        $reset  = Input::bool('reset');
        $report = EmailTemplates::install($reset);

        $parts = [];
        if ($report['installed'] !== []) {
            $parts[] = Lang::get('msg_templates_created', '', ['count' => count($report['installed'])]);
        }
        if ($report['retyped'] !== []) {
            $parts[] = Lang::get('msg_templates_retyped', '', ['count' => count($report['retyped'])]);
        }
        if ($report['reset'] !== []) {
            $parts[] = Lang::get('msg_templates_reset', '', ['count' => count($report['reset'])]);
        }
        if ($report['kept'] !== []) {
            $parts[] = Lang::get('msg_templates_kept', '', ['count' => count($report['kept'])]);
        }
        if ($report['failed'] !== []) {
            $this->error(Lang::get('msg_templates_failed', '', ['names' => implode(', ', $report['failed'])]));
        }

        if ($parts !== []) {
            $this->success(Lang::get('msg_templates_result', '', ['summary' => implode(', ', $parts)]));
        }

        return View::moduleLink(['page' => 'settings']);
    }

    /**
     * Run the scheduled maintenance cycle immediately.
     *
     * The same work the cron performs - expiries, grace periods, reminders,
     * abuse sweeps and cleanup - so an administrator can see its effect without
     * waiting for the next run. A link to the audit log is offered only when
     * something actually changed.
     */
    private function actionRunMaintenance(): ?string
    {
        $report  = Maintenance::run();
        $changed = array_sum(array_map('intval', $report)) > 0;

        $message = Input::e(Lang::get('msg_maintenance_done')) . ' ' . Input::e(Maintenance::describe($report));
        if ($changed) {

            // Filtered to the entry this run just wrote, so the link lands on the
            // evidence rather than on the whole log.
            $message .= ' <a href="' . View::link('logs', ['action_filter' => 'cron.completed']) . '">'
                . Input::e(Lang::get('msg_see_audit_log', 'See the audit log')) . '</a>';
        }

        $this->successHtml($message);

        return View::moduleLink(['page' => 'dashboard']);
    }

    /**
     * Build the dashboard's list of things needing an administrator's attention.
     *
     * Everything here is a condition that is silently wrong: the module keeps
     * working, nothing raises an error, and the consequence surfaces later as a
     * support ticket. Conditions that would be obvious on their own are not
     * listed.
     *
     * Ordering is by consequence, not by discovery. The two most serious - the
     * module being switched off entirely, and the API accepting unsigned
     * requests - are unshifted to the front so they appear above the routine
     * queue items.
     *
     * Each entry is a tone, what is wrong, why it matters, and a link to where
     * it is fixed. Counts are read behind try/catch because this runs on the
     * dashboard: a table missing after a partial upgrade should cost one row of
     * the list, not the whole page.
     *
     * @return list<array{tone:string,what:string,why:string,action:string,url:string}>
     */
    private function attention(): array
    {
        $items = [];

        // First, and unconditionally. A master key that no longer matches makes
        // every stored API secret and signing key unreadable, so it outranks
        // anything else on this list and must not wait for someone to open the
        // settings page. Only the mismatch is surfaced: the other states are
        // ordinary and belong on the page that explains them.
        if (Crypto::checkKeyIntegrity()['status'] === 'changed') {
            $items[] = [
                'tone'   => 'bad',
                'what'   => Lang::get('att_master_key_changed'),
                'why'    => Lang::get('att_master_key_changed_why'),
                'action' => Lang::get('att_review'),
                'url'    => View::moduleLink(['page' => 'settings']),
            ];
        }

        // A capped run leaves licences in a state the operator believes has already
        // been resolved - an expired one still being served as active - so it is
        // reported rather than left to be inferred from a count that never falls.
        if (Maintenance::hasBacklog()) {
            $items[] = [
                'tone'   => 'warn',
                'what'   => Lang::get('att_maintenance_backlog'),
                'why'    => Lang::get('att_maintenance_backlog_why'),
                'action' => Lang::get('att_run_now'),
                'url'    => View::moduleLink(['page' => 'dashboard']),
            ];
        }

        $pending = count(ReissueService::pending());
        if ($pending > 0) {
            $items[] = [
                'tone'   => 'warn',
                'what'   => $pending === 1
                    ? Lang::get('att_resets_waiting_one')
                    : Lang::get('att_resets_waiting', '', ['count' => $pending]),
                'why'    => Lang::get('att_resets_why'),
                'action' => Lang::get('att_review'),
                'url'    => View::moduleLink(['page' => 'reissues']),
            ];
        }

        $abuse = (int) Db::table('abuse_events')->where('resolved', 0)->count();
        if ($abuse > 0) {
            $items[] = [
                'tone'   => 'warn',
                'what'   => $abuse === 1
                    ? Lang::get('att_abuse_one')
                    : Lang::get('att_abuse', '', ['count' => $abuse]),
                'why'    => Lang::get('att_abuse_why'),
                'action' => Lang::get('att_investigate'),
                'url'    => View::moduleLink(['page' => 'abuse']),
            ];
        }

        // Only meaningful while the compatibility path is still open. Once proof is
        // required there is nothing left to migrate, so the item is not shown.
        $active = Settings::bool('require_install_proof', false)
            ? 0
            : (int) Db::table('activations')->where('status', 'active')->count();

        if ($active > 0) {
            $unproven = (int) Db::table('activations')
                ->where('status', 'active')
                ->where(static function ($query): void {
                    $query->whereNull('install_secret')->orWhere('install_secret', '');
                })
                ->count();

            // Two shapes: work remaining, or the migration complete and the
            // requirement ready to be switched on.
            $items[] = $unproven > 0
                ? [
                    'tone'   => 'warn',
                    'what'   => $unproven === 1
                        ? Lang::get('att_unproven_one')
                        : Lang::get('att_unproven', '', ['count' => $unproven]),
                    'why'    => Lang::get('att_unproven_why'),
                    'action' => Lang::get('att_review'),
                    'url'    => View::link('logs', ['action_filter' => 'activation.unproven']),
                ]
                : [
                    'tone'   => 'warn',
                    'what'   => Lang::get('att_unproven_clear'),
                    'why'    => Lang::get('att_unproven_clear_why'),
                    'action' => Lang::get('att_require_proof'),
                    'url'    => View::moduleLink(['page' => 'settings']),
                ];
        }

        try {
            $unscoped = (int) Db::table('api_credentials')
                ->where('is_active', 1)
                ->where('allow_all_products', 1)
                ->count();
        } catch (\Throwable $e) {
            $unscoped = 0;
        }

        try {
            $usable = (int) Db::table('api_credentials')->where('is_active', 1)->count();
        } catch (\Throwable $e) {
            // Assume one exists rather than reporting a problem that may not be
            // there: an unreadable table is not evidence of a missing credential.
            $usable = 1;
        }

        if ($usable === 0) {
            $items[] = [
                'tone'   => 'warn',
                'what'   => Lang::get('att_no_credential'),
                'why'    => Lang::get('att_no_credential_why'),
                'action' => Lang::get('att_review'),
                'url'    => View::moduleLink(['page' => 'credentials']),
            ];
        }

        if ($unscoped > 0) {
            $items[] = [
                'tone'   => 'warn',
                'what'   => $unscoped === 1
                    ? Lang::get('att_credential_open_one')
                    : Lang::get('att_credential_open', '', ['count' => $unscoped]),
                'why'    => Lang::get('att_credential_open_why'),
                'action' => Lang::get('att_review'),
                'url'    => View::moduleLink(['page' => 'credentials']),
            ];
        }

        $stale = ModuleOptions::productsNeedingResave();
        if ($stale !== []) {
            $items[] = [
                'tone'   => 'bad',
                'what'   => count($stale) === 1
                    ? Lang::get('att_products_one')
                    : Lang::get('att_products', '', ['count' => count($stale)]),
                'why'    => Lang::get('att_products_why'),
                'action' => Lang::get('att_fix'),
                'url'    => View::moduleLink(['page' => 'products']),
            ];
        }

        $missing = 0;
        foreach (EmailTemplates::status() as $email) {
            $missing += $email['exists'] ? 0 : 1;
        }
        if ($missing > 0) {
            $items[] = [
                'tone'   => 'bad',
                'what'   => $missing === 1
                    ? Lang::get('att_emails_one')
                    : Lang::get('att_emails', '', ['count' => $missing]),
                'why'    => Lang::get('att_emails_why'),
                'action' => Lang::get('att_install'),
                'url'    => View::moduleLink(['page' => 'settings']),
            ];
        }

        if (!Settings::bool('require_api_auth', true)) {

            // The setting alone does not decide: without the constant the API
            // still enforces signing, which is a milder - but still reportable -
            // state than genuinely open.
            if (Auth::unsignedPermitted()) {
                $tone = 'bad';
                $what = Lang::get('att_unsigned');
                $why  = Lang::get('att_unsigned_why');
            } else {
                $tone = 'warn';
                $what = Lang::get('att_unsigned_blocked');
                $why  = Lang::get('att_unsigned_blocked_why');
            }

            array_unshift($items, [
                'tone'   => $tone,
                'what'   => $what,
                'why'    => $why,
                'action' => Lang::get('att_require_signing'),
                'url'    => View::moduleLink(['page' => 'settings']),
            ]);
        }

        if (!Settings::bool('module_enabled', true)) {
            array_unshift($items, [
                'tone'   => 'bad',
                'what'   => Lang::get('att_disabled'),
                'why'    => Lang::get('att_disabled_why'),
                'action' => Lang::get('att_turn_on'),
                'url'    => View::moduleLink(['page' => 'settings']),
            ]);
        }

        return $items;
    }

    /**
     * Render the dashboard: overall counts, recent activity and open problems.
     */
    private function dashboardPage(): string
    {
        $stats = LicenseManager::statistics();

        $recentValidations = Db::table('validations')
            ->orderBy('id', 'desc')->limit(15)->get();

        $recentFailures = Db::table('validations')
            ->where('success', 0)->orderBy('id', 'desc')->limit(15)->get();

        $recentLicenses = $this->decorate(
            Db::table('licenses')->whereNull('deleted_at')->orderBy('id', 'desc')->limit(10)->get()
        );

        return View::renderAdmin('dashboard', [
            'messages'          => $this->messages,
            'stats'             => $stats,
            'statusLabels'      => LicenseStatus::labels(),
            'recentValidations' => $recentValidations,
            'recentFailures'    => $recentFailures,
            'recentLicenses'    => $recentLicenses,
            'abuseEvents'       => AbuseDetector::open(10),
            'attention'         => $this->attention(),
        ]);
    }

    /**
     * Render the licence list, with search, filtering, sorting and pagination.
     *
     * The search term is matched against the licence's own columns and,
     * separately, against WHMCS client records, so searching a customer's name
     * or email finds their licences. LIKE wildcards in the term are escaped so a
     * search for "%" does not match everything.
     *
     * Sort column and direction are whitelisted rather than passed through,
     * since both reach the query builder's ORDER BY clause.
     */
    private function licensesPage(): string
    {
        $page    = max(1, Input::int('p', 1));
        $filters = [
            'search'  => Input::str('search', '', 190),
            'status'  => Input::str('status', '', 20),
            'product' => Input::int('product'),
        ];

        $query = Db::table('licenses')->whereNull('deleted_at');

        if ($filters['status'] !== '' && LicenseStatus::exists($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($filters['product'] > 0) {
            $query->where('product_id', $filters['product']);
        }
        if ($filters['search'] !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $clientIds = $this->clientIdsMatching($filters['search']);

            $query->where(static function ($q) use ($term, $clientIds, $filters): void {
                $q->where('license_key', 'like', $term)
                  ->orWhere('primary_domain', 'like', $term)
                  ->orWhere('primary_ip', 'like', $term)
                  ->orWhere('notes', 'like', $term);
                if (ctype_digit($filters['search'])) {
                    $q->orWhere('service_id', (int) $filters['search'])
                      ->orWhere('id', (int) $filters['search']);
                }
                if ($clientIds !== []) {
                    $q->orWhereIn('client_id', $clientIds);
                }
            });
        }

        $sort      = in_array(Input::str('sort', 'id', 30), ['id', 'license_key', 'status', 'expires_at', 'created_at', 'last_validated_at'], true)
            ? Input::str('sort', 'id', 30) : 'id';
        $direction = strtolower(Input::str('dir', 'desc', 4)) === 'asc' ? 'asc' : 'desc';

        $total   = (int) (clone $query)->count();
        $paging  = View::pagination($page, self::PER_PAGE, $total);
        $rows    = $query->orderBy($sort, $direction)
            ->forPage($paging['page'], self::PER_PAGE)
            ->get();

        return View::renderAdmin('licenses', [
            'messages'     => $this->messages,
            'licenses'     => $this->decorate($rows),
            'filters'      => $filters,
            'sort'         => $sort,
            'dir'          => $direction,
            'paging'       => $paging,
            'statusLabels' => LicenseStatus::labels(),
            'products'     => Db::table('products')->orderBy('name')->get(),
            'issuable'     => $this->servicesAwaitingLicense(),
        ]);
    }

    /**
     * Services on a licensed product that have no licence yet.
     *
     * Populates the issue form, and is the reason that form exists: these are
     * services provisioned before licensing was enabled for their product, or
     * ones whose provisioning failed. Terminated, cancelled and fraudulent
     * services are excluded - issuing a licence for those is never the intent.
     *
     * @return list<array{id:int,label:string}> Empty if the WHMCS tables cannot
     *   be read, so the page still renders without the issue form.
     */
    private function servicesAwaitingLicense(int $limit = 200): array
    {
        $productIds = [];
        foreach (ModuleOptions::licensedProducts() as $product) {
            $productIds[] = $product['id'];
        }
        if ($productIds === []) {
            return [];
        }

        try {
            $licensed = Db::table('licenses')
                ->whereNull('deleted_at')
                ->where('service_id', '>', 0)
                ->pluck('service_id')
                ->all();

            // The [0] placeholder keeps whereNotIn valid on an installation with
            // no licences yet, where an empty list would match nothing.
            $services = Db::connection()->table('tblhosting')
                ->whereIn('packageid', $productIds)
                ->whereNotIn('domainstatus', ['Terminated', 'Cancelled', 'Fraud'])
                ->whereNotIn('id', $licensed === [] ? [0] : $licensed)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($services as $service) {
            $client  = $this->clientSummary((int) $service->userid);
            $product = ProductConfig::findByWhmcsProduct((int) $service->packageid);

            $out[] = [
                'id'    => (int) $service->id,
                'label' => sprintf(
                    '#%d - %s - %s%s (%s)',
                    (int) $service->id,
                    $client['name'],
                    $product !== null ? (string) $product->name : 'Product #' . (int) $service->packageid,
                    ((string) ($service->domain ?? '')) !== '' ? ' - ' . (string) $service->domain : '',
                    (string) $service->domainstatus
                ),
            ];
        }

        return $out;
    }

    /**
     * WHMCS client ids whose name, company or email matches a search term.
     *
     * Kept as a separate query rather than joined, because tblclients is WHMCS's
     * table and may live on a different connection.
     *
     * @return list<int> Empty on failure, which narrows the search rather than
     *   breaking it.
     */
    private function clientIdsMatching(string $term): array
    {
        try {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

            return array_map('intval', Db::connection()->table('tblclients')
                ->where('email', 'like', $like)
                ->orWhere('firstname', 'like', $like)
                ->orWhere('lastname', 'like', $like)
                ->orWhere('companyname', 'like', $like)
                ->limit(200)
                ->pluck('id')
                ->all());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Render the single-licence page: everything known about one licence.
     *
     * Includes soft-deleted licences, since this is the only place their history
     * can still be examined. Falls back to the list page when the id is unknown.
     */
    private function licensePage(): string
    {
        $id      = Input::int('id');
        $license = LicenseManager::find($id, true);
        if ($license === null) {
            $this->error(Lang::get('msg_license_missing'));

            return $this->licensesPage();
        }

        $product = ProductConfig::find((int) $license->product_id);

        return View::renderAdmin('license', [
            'messages'       => $this->messages,
            'license'        => $license,
            'client'         => $this->clientSummary((int) $license->client_id),
            'product'        => $product,
            'policy'         => ProductConfig::policyForLicense($license),
            'statusLabel'    => LicenseStatus::label((string) $license->status),
            'allowedStatuses' => $this->statusOptions((string) $license->status),
            'badgeClass'     => LicenseStatus::badgeClass((string) $license->status),
            'tone'           => LicenseStatus::tone((string) $license->status),
            'activations'    => ActivationService::forLicense($id),
            'features'       => LicenseManager::features($id),
            'allFeatures'    => $this->featureChecklist($id),
            'allowedDomains' => implode("\n", \LicenseForge\Licensing\ValidationService::decodeList($license->allowed_domains)),
            'allowedIps'     => implode("\n", \LicenseForge\Licensing\ValidationService::decodeList($license->allowed_ips)),
            'validations'    => Db::table('validations')->where('license_id', $id)->orderBy('id', 'desc')->limit(50)->get(),
            'reissues'       => ReissueService::history($id),
            'auditLog'       => Audit::search(['license_id' => $id], 1, 50)['rows'],
            'daysToExpiry'   => LicenseManager::daysUntilExpiry($license),
            'inGrace'        => LicenseManager::inGracePeriod($license),
            'held'           => LicenseManager::isHeld($license),
            'emailOptions'   => $this->emailOptions(),

            // A licence can outlive its service, so this is a shape rather than null.
            'service'        => $this->serviceSummary((int) $license->service_id),
        ]);
    }

    /**
     * Release files registered against a product, prepared for display.
     *
     * `readable` reports whether the file currently resolves inside the release
     * directory, so a release whose file has been moved or removed is visibly
     * broken on the products page rather than only when a customer tries it.
     *
     * @return list<array<string,mixed>>
     */
    private function releasesFor(int $productId): array
    {
        $out = [];

        try {
            $rows = Db::table('releases')->where('product_id', $productId)->orderBy('id', 'desc')->get();
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($rows as $row) {
            $out[] = [
                'id'        => (int) $row->id,
                'label'     => (string) $row->label,
                'version'   => (string) ($row->version ?? ''),
                'path'      => (string) $row->file_path,
                'size'      => number_format(((int) $row->size_bytes) / 1024, 0) . ' KB',
                'downloads' => (int) $row->download_count,
                'readable'  => ReleaseService::resolvePath((string) $row->file_path) !== null,
            ];
        }

        return $out;
    }

    /**
     * Render the products page: licensing policy per product, and its releases.
     *
     * Products are listed from WHMCS and matched to the module's own mirror
     * rows, since the policy lives in WHMCS product configuration and is
     * mirrored here for the licensing engine to read. A product with no mirror
     * is skipped - it has never been saved with licensing enabled.
     *
     * Retired products are listed separately: licensing is off, but existing
     * licences still reference them and must remain reachable.
     */
    private function productsPage(): string
    {
        $counts   = $this->licenseCountsByProduct();
        $products = [];

        foreach (ModuleOptions::licensedProducts() as $whmcsProduct) {
            $mirror = ProductConfig::findByWhmcsProduct($whmcsProduct['id']);
            if ($mirror === null) {
                continue;
            }

            $policy = ProductConfig::policy($mirror);
            $locks  = [];
            foreach (['domain' => 'lock_domain', 'ip' => 'lock_ip', 'directory' => 'lock_directory', 'machine' => 'lock_machine'] as $label => $key) {
                if ($policy[$key]) {
                    $locks[] = $label;
                }
            }

            $products[] = [
                'id'          => (int) $mirror->id,
                'name'        => $whmcsProduct['name'],
                'slug'        => (string) $mirror->product_slug,
                'whmcsId'     => $whmcsProduct['id'],
                'editUrl'     => 'configproducts.php?action=edit&id=' . $whmcsProduct['id'],
                'duration'    => $this->describeTerm($policy),
                'activations' => (string) (int) $policy['max_activations'],
                'reissues'    => (string) (int) $policy['max_reissues'],
                'grace'       => (int) $policy['grace_days'] . 'd',
                'latest'      => (string) $policy['latest_version'],
                'features'    => implode(', ', $policy['default_features']),
                'locks'       => $locks === [] ? '-' : implode(', ', $locks),
                'licenses'    => $counts[(int) $mirror->id] ?? 0,
                'releases'    => $this->releasesFor((int) $mirror->id),
            ];
        }

        // Products with licensing switched off still own live licences, so they are
        // listed separately rather than dropped.
        $retired = [];
        foreach (Db::table('products')->where('licensing_enabled', 0)->orderBy('name')->get() as $mirror) {
            $retired[] = [
                'id'       => (int) $mirror->id,
                'name'     => (string) $mirror->name,
                'slug'     => (string) $mirror->product_slug,
                'whmcsId'  => (int) $mirror->whmcs_product_id,
                'licenses' => $counts[(int) $mirror->id] ?? 0,
            ];
        }

        return View::renderAdmin('products', [
            'messages'   => $this->messages,
            'products'   => $products,
            'retired'    => $retired,
            'newProduct' => 'configproducts.php',
            'needResave' => ModuleOptions::productsNeedingResave(),
            'releaseDir' => ReleaseService::directory(),
            'csrfToken'  => Input::csrfToken(),
        ]);
    }

    /**
     * Render the API credentials page.
     *
     * Each credential is flagged if it carries the `admin` scope, which the
     * template highlights: that scope is far broader than the activate/check
     * pair a shipped product needs, and one granted by accident is not otherwise
     * obvious in a list.
     */
    private function credentialsPage(): string
    {

        $credentials = Credentials::all();
        foreach ($credentials as $credential) {
            // Flagged for the template: the admin scope is far broader than a shipped
            // product needs, and is not obvious from a name in a list.
            $credential->is_admin_scoped = in_array('admin', Credentials::scopesOf($credential), true);
        }

        return View::renderAdmin('credentials', [
            'messages'    => $this->messages,
            'credentials' => $credentials,
            'scopes'      => Credentials::SCOPES,
            'apiUrl'      => $this->apiUrl(),
        ]);
    }

    /**
     * Render the audit log, with filtering and pagination.
     *
     * The action list offered as a filter is read from the log itself rather
     * than hard-coded, so actions added later appear without changing this page.
     */
    private function logsPage(): string
    {
        $page    = max(1, Input::int('p', 1));
        $filters = [
            'search'     => Input::str('search', '', 190),
            'action'     => Input::str('action_filter', '', 64),
            'result'     => Input::str('result', '', 16),
            'actor_type' => Input::str('actor_type', '', 16),
            'license_id' => Input::int('license_id'),
        ];

        $found  = Audit::search($filters, $page, self::PER_PAGE);
        $paging = View::pagination($page, self::PER_PAGE, $found['total']);

        return View::renderAdmin('logs', [
            'messages' => $this->messages,
            'logs'     => $found['rows'],
            'paging'   => $paging,
            'filters'  => $filters,
            'actions'  => Db::table('audit_logs')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * Render abuse events, open by default and resolved on request.
     */
    private function abusePage(): string
    {
        $showResolved = Input::bool('resolved');
        $events = Db::table('abuse_events')
            ->where('resolved', $showResolved ? 1 : 0)
            ->orderBy('id', 'desc')
            ->limit(200)
            ->get();

        return View::renderAdmin('abuse', [
            'messages'     => $this->messages,
            'events'       => $events,
            'showResolved' => $showResolved,
        ]);
    }

    /**
     * Render the reissue queue: requests awaiting a decision, and recent history.
     */
    private function reissuesPage(): string
    {
        return View::renderAdmin('reissues', [
            'messages' => $this->messages,
            'pending'  => ReissueService::pending(),
            'recent'   => Db::table('reissues')->orderBy('id', 'desc')->limit(100)->get(),
        ]);
    }

    /**
     * How far through the installation-proof migration this install is.
     *
     * Activations created before per-installation credentials existed carry no
     * secret and are honoured unproven, which is a standing exemption rather
     * than a migration until it is closed. `required` says whether it has been
     * closed; `unproven` counts the active installations still relying on it.
     * Zero unproven with the requirement off is the moment it can be switched on
     * with no customer affected.
     *
     * @return array{required:bool,unproven:int,active:int} Zeroes if the counts
     *   cannot be read, so the settings page still renders.
     */
    private function installProofState(): array
    {
        $required = Settings::bool('require_install_proof', false);

        try {
            $active = (int) Db::table('activations')->where('status', 'active')->count();
            $unproven = $active === 0 ? 0 : (int) Db::table('activations')
                ->where('status', 'active')
                ->where(static function ($query): void {
                    $query->whereNull('install_secret')->orWhere('install_secret', '');
                })
                ->count();
        } catch (\Throwable $e) {

            // Reported as "nothing known" rather than as a migration complete.
            $active   = 0;
            $unproven = 0;
        }

        return ['required' => $required, 'unproven' => $unproven, 'active' => $active];
    }

    /**
     * Render the settings page.
     *
     * Also surfaces the facts an administrator needs in order to judge the
     * settings rather than merely change them: whether libsodium is available
     * for Ed25519, which migrations have run, how much of the estate still lacks
     * installation proof, and whether the configured release directory is
     * usable.
     */
    private function settingsPage(): string
    {
        $emails  = EmailTemplates::status();
        $missing = 0;
        foreach ($emails as $email) {
            $missing += $email['exists'] ? 0 : 1;
        }

        return View::renderAdmin('settings', [
            'messages'       => $this->messages,
            'settings'       => Settings::all(),
            'defaults'       => Settings::defaults(),
            'signingKeys'    => Crypto::publicKeys(),
            'apiUrl'         => $this->apiUrl(),
            'sodium'         => function_exists('sodium_crypto_sign_keypair'),
            'migrations'     => Db::table('migrations')->orderBy('id')->get(),
            'emailTemplates' => $emails,
            'emailMissing'   => $missing,
            'mergeFields'    => EmailTemplates::mergeFields(),
            'statusMap'      => $this->statusMapFields(),

            'installProof'       => $this->installProofState(),

            // Recorded here rather than on the dashboard, which is a read path
            // an unattended browser tab hits. An administrator on the settings
            // page is the deliberate moment to establish the baseline.
            'keyIntegrity'       => Crypto::checkKeyIntegrity(true),

            // The stored value, not the sanitised one: the page has to show what
            // was typed in order to explain what is wrong with it.
            'releaseDirProblem'  => ReleaseService::configuredDirectory() === ''
                ? ''
                : ReleaseService::problemWith(ReleaseService::configuredDirectory()),
            'unsigned_permitted' => Auth::unsignedPermitted(),
        ]);
    }

    /**
     * The service-status to licence-status mapping, as form fields.
     *
     * Each WHMCS service status offers only the licence statuses that make sense
     * for it, so the mapping cannot be configured into a state the licensing
     * engine would refuse.
     *
     * The help text is fetched with literal Lang::get() keys rather than keys
     * built from the row: a key assembled at runtime cannot be found by
     * searching the language files, so a missing translation would go unnoticed.
     *
     * @return list<array{key:string,service:string,explain:string,options:list<array{value:string,label:string}>}>
     */
    private function statusMapFields(): array
    {
        $rows = [
            [
                'key'     => 'map_active',
                'service' => 'Active',
                'explain' => Lang::get('map_active_help'),
                'allow'   => [LicenseStatus::ACTIVE, LicenseStatus::PENDING],
            ],
            [
                'key'     => 'map_pending',
                'service' => 'Pending',
                'explain' => Lang::get('map_pending_help'),
                'allow'   => [LicenseStatus::PENDING, LicenseStatus::ACTIVE],
            ],
            [
                'key'     => 'map_suspended',
                'service' => 'Suspended',
                'explain' => Lang::get('map_suspended_help'),
                'allow'   => [LicenseStatus::SUSPENDED, LicenseStatus::EXPIRED],
            ],
            [
                'key'     => 'map_terminated',
                'service' => 'Terminated',
                'explain' => Lang::get('map_terminated_help'),
                'allow'   => [LicenseStatus::TERMINATED, LicenseStatus::REVOKED, LicenseStatus::EXPIRED],
            ],
            [
                'key'     => 'map_cancelled',
                'service' => 'Cancelled',
                'explain' => Lang::get('map_cancelled_help'),
                'allow'   => [LicenseStatus::REVOKED, LicenseStatus::TERMINATED, LicenseStatus::EXPIRED],
            ],
            [
                'key'     => 'map_fraud',
                'service' => 'Fraud',
                'explain' => Lang::get('map_fraud_help'),
                'allow'   => [LicenseStatus::REVOKED, LicenseStatus::TERMINATED],
            ],
        ];

        $fields = [];
        foreach ($rows as $row) {
            $options = [];
            foreach ($row['allow'] as $status) {
                $options[] = ['value' => $status, 'label' => LicenseStatus::label($status)];
            }

            $fields[] = [
                'key'     => $row['key'],
                'service' => $row['service'],
                'explain' => $row['explain'],
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * Prepare licence rows for a listing template.
     *
     * Product names are loaded once up front rather than per row, and client
     * lookups are memoised by {@see clientSummary()}, so rendering a page of
     * licences costs a bounded number of queries regardless of page size.
     *
     * @param iterable<object> $rows
     * @return list<array<string,mixed>>
     */
    private function decorate(iterable $rows): array
    {
        $decorated = [];
        $products  = [];
        foreach (Db::table('products')->get() as $product) {
            $products[(int) $product->id] = (string) $product->name;
        }

        foreach ($rows as $row) {
            $decorated[] = [
                'id'          => (int) $row->id,
                'key'         => (string) $row->license_key,
                'product'     => $products[(int) $row->product_id] ?? '-',
                'client'      => $this->clientSummary((int) $row->client_id),
                'badge'       => LicenseStatus::badgeClass((string) $row->status),
                'tone'        => LicenseStatus::tone((string) $row->status),
                'statusLabel' => LicenseStatus::label((string) $row->status),
                'domain'      => (string) ($row->primary_domain ?? ''),
                'activations' => (int) $row->activation_count . ' / ' . (int) $row->max_activations,
                'isLifetime'  => (bool) $row->is_lifetime,
                'isTrial'     => (bool) $row->is_trial,
                'flagged'     => (bool) $row->flagged,
                'expires'     => (string) ($row->expires_at ?? ''),
                'lastCheck'   => (string) ($row->last_validated_at ?? ''),
                'days'        => LicenseManager::daysUntilExpiry($row),
            ];
        }

        return $decorated;
    }

    /**
     * Licence statuses reachable from the current one.
     *
     * Offered from the transition table so the form cannot present a change the
     * licensing layer would then refuse.
     *
     * @return list<array{value:string,label:string}>
     */
    private function statusOptions(string $currentStatus): array
    {
        $options = [];
        foreach (LicenseStatus::transitions()[$currentStatus] ?? [] as $status) {
            $options[] = ['value' => $status, 'label' => LicenseStatus::label($status)];
        }

        return $options;
    }

    /**
     * Every known feature, flagged with whether this licence holds it.
     *
     * @return list<array{slug:string,name:string,enabled:bool}>
     */
    private function featureChecklist(int $licenseId): array
    {
        $held = LicenseManager::features($licenseId);
        $list = [];

        foreach (Db::table('features')->orderBy('name')->get() as $feature) {
            $list[] = [
                'slug'    => (string) $feature->slug,
                'name'    => (string) $feature->name,
                'enabled' => in_array((string) $feature->slug, $held, true),
            ];
        }

        return $list;
    }

    /**
     * A licence's WHMCS service, reduced to what the licence page displays.
     *
     * Returns a shape with `exists` false rather than null when the service is
     * missing, so the template needs no null handling - a licence can outlive
     * the service it was issued for.
     *
     * @return array{exists:bool,id:int,status:string,suspended:bool,domain:string}
     */
    private function serviceSummary(int $serviceId): array
    {
        // A licence can outlive its service, so absence is a shape rather than null.
        $empty = ['exists' => false, 'id' => $serviceId, 'status' => '', 'suspended' => false, 'domain' => ''];
        if ($serviceId <= 0) {
            return $empty;
        }

        $service = Provisioner::service($serviceId);
        if ($service === null) {
            return $empty;
        }

        $status = (string) $service->domainstatus;

        return [
            'exists'    => true,
            'id'        => $serviceId,
            'status'    => $status,
            'suspended' => in_array($status, ['Suspended', 'Terminated', 'Cancelled', 'Fraud'], true),
            'domain'    => (string) ($service->domain ?? ''),
        ];
    }

    /**
     * A WHMCS client, reduced to what the admin pages display.
     *
     * Memoised for the request: a listing shows many licences belonging to the
     * same few clients, and this would otherwise query once per row.
     *
     * A missing or unreadable client yields a placeholder name rather than an
     * error, so one deleted client cannot break a whole page.
     *
     * @return array{id:int,name:string,email:string}
     */
    private function clientSummary(int $clientId): array
    {
        static $cache = [];
        if ($clientId <= 0) {
            return ['id' => 0, 'name' => '-', 'email' => ''];
        }
        if (isset($cache[$clientId])) {
            return $cache[$clientId];
        }

        try {
            $client = Db::connection()->table('tblclients')->where('id', $clientId)->first();
            $cache[$clientId] = $client === null
                ? ['id' => $clientId, 'name' => 'Client #' . $clientId, 'email' => '']
                : [
                    'id'    => $clientId,
                    'name'  => trim(((string) $client->firstname) . ' ' . ((string) $client->lastname)),
                    'email' => (string) $client->email,
                ];
        } catch (\Throwable $e) {
            $cache[$clientId] = ['id' => $clientId, 'name' => 'Client #' . $clientId, 'email' => ''];
        }

        return $cache[$clientId];
    }

    /**
     * A product's licence term in words, for the products table.
     *
     * A fixed term of zero days means no expiry, and is described as lifetime
     * rather than as "0 days", which would read as already expired.
     *
     * @param array<string,mixed> $policy
     */
    private function describeTerm(array $policy): string
    {
        switch ((string) $policy['license_term']) {
            case 'lifetime':
                return 'lifetime';
            case 'fixed_days':
                return (int) $policy['duration_days'] <= 0
                    ? 'lifetime'
                    : (int) $policy['duration_days'] . ' days';
            default:
                return 'billing cycle';
        }
    }

    /**
     * Live licence count per product, as one grouped query rather than per
     * product.
     *
     * @return array<int,int> Product id => count.
     */
    private function licenseCountsByProduct(): array
    {
        $counts = [];
        $rows   = Db::table('licenses')
            ->select(Db::raw('product_id, COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->groupBy('product_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->product_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The public API URL to show customers, for pasting into their integration.
     *
     * The configured override wins where an installation is reached on a
     * different hostname than WHMCS believes - behind a proxy, or on a dedicated
     * API domain. Otherwise it is derived from the WHMCS system URL.
     */
    private function apiUrl(): string
    {
        $configured = trim((string) Settings::get('license_server_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        try {
            $systemUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        } catch (\Throwable $e) {
            $systemUrl = '';
        }

        return rtrim($systemUrl, '/') . '/modules/addons/' . LICENSEFORGE_MODULE . '/api/index.php';
    }

    /**
     * Redirect after a successful action, carrying the flash messages across.
     *
     * Redirecting rather than rendering is what stops a browser reload from
     * repeating the action. The messages move through the session because they
     * would otherwise be discarded with the current request.
     *
     * A link is returned as well as the header, so the outcome is still
     * reachable if headers have already been sent by the surrounding WHMCS page.
     */
    private function redirect(string $url): string
    {

        // Messages would be lost with this request otherwise.
        $_SESSION['licenseforge_flash'] = $this->messages;
        header('Location: ' . $url);

        return '<p>Redirecting… <a href="' . Input::e($url) . '">continue</a></p>';
    }

    /**
     * Queue a success message.
     *
     * Escaped here rather than in the template, so a message cannot reach a view
     * unescaped through an oversight in one of many templates.
     */
    private function success(string $text): void
    {
        $this->messages[] = ['type' => 'success', 'text' => Input::e($text)];
    }

    /**
     * Queue a success message that contains markup.
     *
     * The only path that queues unescaped content. Callers must escape every
     * interpolated value themselves with Input::e() before building the
     * fragment; this exists for messages that need a link or a <code> block, not
     * for passing through anything that came from a request.
     */
    private function successHtml(string $html): void
    {
        $this->messages[] = ['type' => 'success', 'text' => $html];
    }

    /** Queue a warning message. Escaped as in {@see success()}. */
    private function warning(string $text): void
    {
        $this->messages[] = ['type' => 'warning', 'text' => Input::e($text)];
    }

    /** Queue an error message. Escaped as in {@see success()}. */
    private function error(string $text): void
    {
        $this->messages[] = ['type' => 'danger', 'text' => Input::e($text)];
    }

    /**
     * Adopt any messages left in the session by a previous redirect.
     *
     * Consumed on read, so a message is shown once and does not reappear on the
     * next page.
     */
    public function loadFlash(): void
    {
        if (!empty($_SESSION['licenseforge_flash']) && is_array($_SESSION['licenseforge_flash'])) {
            $this->messages = array_merge($_SESSION['licenseforge_flash'], $this->messages);
            unset($_SESSION['licenseforge_flash']);
        }
    }

    /**
     * Bring the database and the product mirror up to date.
     *
     * Called on activation and on admin page loads, and safe to repeat:
     * migrations are skipped once applied, and the product sync is idempotent.
     *
     * Failures are logged rather than thrown. This runs inside the WHMCS admin
     * request, and a migration error should not take the admin area down with it
     * - the settings page lists the migrations that have actually run, which is
     * where the problem becomes visible.
     */
    public static function ensureSchema(): void
    {
        try {
            Schema::migrate();
            ProductConfig::syncAll();
        } catch (\Throwable $e) {
            error_log('[LicenseForge] migration error: ' . $e->getMessage());
        }
    }
}
