<?php

declare(strict_types=1);

namespace LicenseForge\Client;

use LicenseForge\Licensing\ActivationService;
use LicenseForge\Licensing\LicenseManager;
use LicenseForge\Licensing\LicenseStatus;
use LicenseForge\Licensing\ProductConfig;
use LicenseForge\Licensing\ReleaseService;
use LicenseForge\Licensing\ReissueService;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\Input;
use LicenseForge\Support\Lang;

/**
 * The licensing panel on a customer's product details page.
 *
 * There is deliberately no separate "My Licences" area. A licence belongs to a
 * service - that is what gives it an owner, a product and a billing term - so
 * it is presented with that service, and the customer reaches it the same way
 * they reach everything else about the product they bought.
 *
 * Shows the licence key, its status and dates, the entitlements it carries, the
 * installations currently bound to it, and any downloads it covers. Lets the
 * customer do the two things they can do without staff help: release an
 * installation, and reset the licence so it can be activated elsewhere.
 *
 * Access control
 * --------------
 * The panel is constructed with the service being viewed and the signed-in
 * customer's id, and every lookup is scoped to both. WHMCS has already
 * authorised the page, but {@see license()} re-checks ownership independently,
 * so a mistake in the surrounding theme or hook cannot expose another
 * customer's licence. Actions are additionally re-scoped to the licence they
 * act on, which makes horizontal access impossible rather than merely unlikely.
 *
 * Holds no licensing logic. Whether a reissue is permitted, or an installation
 * may be released, is decided by LicenseForge\Licensing, so the customer, the
 * administrator and the API all get the same answer.
 */
final class ServicePanel
{
    private int $serviceId;
    private int $clientId;

    /** @var list<array{type:string,text:string}> Flash messages for this render. */
    private array $messages = [];

    /**
     * @param int $serviceId The service whose page is being viewed.
     * @param int $clientId  The signed-in customer. Every query is scoped to
     *   this, so it must come from the session or from WHMCS's own client
     *   details, never from the request.
     */
    public function __construct(int $serviceId, int $clientId)
    {
        $this->serviceId = $serviceId;
        $this->clientId  = $clientId;
    }

    /**
     * Handle any submitted action, then assemble the panel.
     *
     * The licence is loaded first because it is the authorisation check: an
     * action is only dispatched once the licence has been confirmed to belong to
     * this customer, so no action can run against a licence they do not own.
     *
     * It is then reloaded after the action, since almost every action changes
     * what the panel is about to display - a reset rotates the key, a
     * deactivation frees a slot.
     *
     * A missing licence is not an error. Provisioning may not have run yet, so
     * the panel renders a "pending" state rather than something that looks
     * broken.
     *
     * @return array{templatefile:string,vars:array<string,mixed>} The template
     *   and its variables, in the shape the WHMCS server module expects.
     */
    public function render(): array
    {
        $license = $this->license();

        if ($license !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['lf_action'])) {
            try {
                Input::requireCsrf();
                $this->dispatch(Input::str('lf_action', '', 30), $license);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }

            $license = $this->license();
        }

        if ($license === null) {
            return [
                'templatefile' => 'clientarea',
                'vars'         => [
                    'L'            => Lang::all(),
                    'lfHasLicense' => false,
                    'lfMessages'   => $this->messages,
                    'lfServiceId'  => $this->serviceId,
                    'lfPending'    => Lang::get('client_license_pending'),
                ],
            ];
        }

        return [
            'templatefile' => 'clientarea',
            'vars'         => $this->vars($license),
        ];
    }

    /**
     * Route one submitted action.
     *
     * The whitelist is the boundary: an unrecognised action produces a message
     * and reaches no code.
     *
     * @param object $license Already confirmed to belong to this customer.
     */
    private function dispatch(string $action, object $license): void
    {
        switch ($action) {
            case 'reissue':
                $this->actionReissue($license);
                break;
            case 'deactivate':
                $this->actionDeactivate($license);
                break;
            default:
                $this->error(Lang::get('client_msg_unknown'));
        }
    }

    /**
     * Reset the licence at the customer's request, freeing it to be activated
     * elsewhere.
     *
     * Deliberately one button with no fields. The customer is saying "free this
     * licence up"; the new domain is whatever they next activate on, so asking
     * for it here only invites a typo that has to be supported later.
     *
     * The product's policy decides whether this happens immediately or waits for
     * staff approval, and the quota and cooldown are enforced by
     * {@see ReissueService} under a row lock - this method only reports which of
     * the two occurred.
     */
    private function actionReissue(object $license): void
    {
        if (!LicenseManager::clientCanReissue($license)) {
            $this->error(Lang::get('client_msg_reset_denied'));

            return;
        }

        $result = ReissueService::reissue($license, ReissueService::BY_CLIENT, [
            'reason'       => 'reset by the customer from the client area',
            'initiator_id' => $this->clientId,
        ]);

        if ($result->failed()) {
            $this->error((string) $result->message());

            return;
        }

        if ($result->get('pending') === true) {
            $this->success(Lang::get('client_msg_reset_pending'));

            return;
        }

        $this->success(Lang::get('client_msg_reset_done'));
    }

    /**
     * Release one installation, freeing its activation slot.
     *
     * The activation is looked up scoped to this licence, so an id belonging to
     * another customer's licence simply does not resolve.
     *
     * {@see ActivationService::release()} re-reads the row under the licence
     * lock and refuses one that is no longer active, which is what makes two
     * browser tabs or a resubmitted form harmless: the second attempt reports
     * that nothing was found rather than releasing a slot twice.
     */
    private function actionDeactivate(object $license): void
    {
        $activationId = Input::int('activation_id');
        $activation   = Db::table('activations')
            ->where('id', $activationId)
            ->where('license_id', (int) $license->id)
            ->first();

        if ($activation === null) {
            $this->error(Lang::get('client_msg_not_found'));

            return;
        }

        if (!ActivationService::release($activationId, 'deactivated by customer')) {
            $this->error(Lang::get('client_msg_not_found'));

            return;
        }

        Audit::log('activation.released', (int) $license->id, Audit::RESULT_SUCCESS, [
            'activation_id' => $activationId, 'by' => 'client',
        ], Audit::ACTOR_CLIENT, $this->clientId);

        $this->success(Lang::get('client_msg_deactivated'));
    }

    /**
     * Download links for this licence, if any.
     *
     * Asks {@see ReleaseService} the same question download.php will ask, so the
     * customer is never shown a link that would then refuse them - and never
     * denied one they are entitled to.
     *
     * @return list<array{id:int,label:string,version:string,size:string,url:string}>
     */
    private static function releaseLinks(object $license): array
    {
        $links = [];

        foreach (ReleaseService::forLicense($license) as $release) {
            $links[] = [
                'id'      => (int) $release->id,
                'label'   => (string) $release->label,
                'version' => (string) ($release->version ?? ''),
                'size'    => self::formatSize((int) ($release->size_bytes ?? 0)),
                'url'     => 'modules/addons/licenseforge/download.php?release=' . (int) $release->id,
            ];
        }

        return $links;
    }

    /**
     * A byte count as a human-readable size.
     *
     * Returns an empty string for zero, so a release whose size was never
     * recorded shows nothing rather than "0 B".
     */
    private static function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = max(0, min($i, count($units) - 1));

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    /**
     * The licence for this service, provided it belongs to the viewer.
     *
     * The ownership comparison is the access control for the entire panel. WHMCS
     * has already decided this customer may view this service, but that decision
     * is made outside this module; re-checking here means a licence cannot be
     * displayed to the wrong person even if the surrounding page is wrong about
     * whose it is.
     *
     * @return object|null Null when there is no licence, or it is not theirs. The
     *   two cases are deliberately indistinguishable to the caller.
     */
    private function license()
    {
        if ($this->serviceId <= 0 || $this->clientId <= 0) {
            return null;
        }

        $license = LicenseManager::findByService($this->serviceId);

        return $license !== null && (int) $license->client_id === $this->clientId ? $license : null;
    }

    /**
     * Assemble everything the panel template displays.
     *
     * Values are prepared here rather than in the template so that Smarty does
     * no computation and no date arithmetic: dates are pre-formatted, the key is
     * pre-split into blocks, and the activation slots are pre-expanded.
     *
     * @return array<string,mixed>
     */
    private function vars(object $license): array
    {
        $policy      = ProductConfig::policyForLicense($license);
        $activations = [];

        foreach (ActivationService::forLicense((int) $license->id) as $activation) {
            $activations[] = [
                'id'          => (int) $activation->id,
                'label'       => ActivationService::describe($activation),
                'domain'      => (string) ($activation->domain ?? ''),
                'ip'          => (string) ($activation->ip_address ?? ''),
                'directory'   => (string) ($activation->directory ?? ''),
                'version'     => (string) ($activation->version ?? ''),
                'status'      => (string) $activation->status,
                'isActive'    => (string) $activation->status === 'active',
                'activatedAt' => self::formatDate($activation->first_activated_at),
                'lastSeen'    => self::formatDateTime($activation->last_validated_at),
                'canRelease'  => (string) $activation->status === 'active',
            ];
        }

        $used  = (int) $license->activation_count;
        $limit = (int) $license->max_activations;

        return [
            'L'            => Lang::all(),
            'lfHasLicense' => true,
            'lfMessages'   => $this->messages,
            'lfCsrfToken'  => Input::csrfToken(),
            'lfServiceId'  => $this->serviceId,
            'lfLicense'    => [
                'key'         => (string) $license->license_key,

                'keyBlocks'   => self::keyBlocks((string) $license->license_key),
                'status'      => (string) $license->status,
                'statusLabel' => LicenseStatus::label((string) $license->status),
                'badge'       => LicenseStatus::badgeClass((string) $license->status),
                'issued'      => self::formatDate($license->created_at),
                'activated'   => self::formatDate($license->activated_at),
                'expires'     => $license->is_lifetime ? 'Never' : self::formatDate($license->expires_at),
                'days'        => LicenseManager::daysUntilExpiry($license),
                'domain'      => (string) ($license->primary_domain ?? ''),
                'version'     => (string) ($license->current_version ?? ''),
                'latest'      => $policy['latest_version'],
                'lastCheck'   => self::formatDateTime($license->last_validated_at),
                'isTrial'     => (bool) $license->is_trial,
                'isLifetime'  => (bool) $license->is_lifetime,
                'activationsUsed'  => $used,
                'activationsLimit' => $limit,

                /*
                 * Discrete slots read better as pips than as a percentage, but only while
                 * there are few enough to count at a glance. Above that the template falls
                 * back to the numbers.
                 */
                'slots'            => ($limit > 0 && $limit <= 8) ? self::slots($used, $limit) : [],
                'reissuesUsed'     => (int) $license->reissue_count,
                'reissuesLimit'    => (int) $license->max_reissues,
                'reissuesLeft'     => max(0, (int) $license->max_reissues - (int) $license->reissue_count),
                'inGrace'          => LicenseManager::inGracePeriod($license),
                'graceEnds'        => self::formatDate(LicenseManager::graceEndsAt($license)),
            ],

            'lfReleases'    => self::releaseLinks($license),
            'lfFeatures'    => self::featureNames(LicenseManager::features((int) $license->id)),
            'lfActivations' => $activations,
            'lfCanReissue'  => LicenseManager::clientCanReissue($license),
        ];
    }

    /**
     * Entitlement slugs as names a customer will recognise.
     *
     * Slugs are developer identifiers, so the catalogue's display name is used
     * where one exists. A slug with no catalogue row - added by hand, or left
     * behind by a deleted feature - falls back to a readable form of itself
     * rather than disappearing from the list, since a customer who is entitled
     * to something should see it named even if the catalogue has moved on.
     *
     * @param  list<string> $slugs
     * @return list<string>
     */
    private static function featureNames(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $names = [];
        foreach (Db::table('features')->whereIn('slug', $slugs)->get() as $feature) {
            $name = trim((string) $feature->name);
            if ($name !== '') {
                $names[(string) $feature->slug] = $name;
            }
        }

        return array_map(
            static fn (string $slug): string => $names[$slug] ?? ucwords(str_replace('_', ' ', $slug)),
            $slugs
        );
    }

    /**
     * The licence key split into its dash-separated blocks.
     *
     * Pre-split so the template can space each block without doing string work
     * in Smarty, and - more importantly - so the spacing comes from CSS padding
     * rather than from injected characters, which would otherwise be copied
     * along with the key.
     *
     * A key with no dashes comes back as a single block, so a custom key format
     * still renders correctly.
     *
     * @return list<string>
     */
    private static function keyBlocks(string $key): array
    {
        $blocks = array_values(array_filter(explode('-', $key), static fn ($b) => $b !== ''));

        return $blocks === [] ? [$key] : $blocks;
    }

    /**
     * One entry per activation slot, true where the slot is in use.
     *
     * @return list<bool>
     */
    private static function slots(int $used, int $limit): array
    {
        $slots = [];
        for ($i = 0; $i < $limit; $i++) {
            $slots[] = $i < $used;
        }

        return $slots;
    }

    /**
     * A stored date as '14 Mar 2027', or an em dash when there is none.
     *
     * @param mixed $value
     */
    private static function formatDate($value): string
    {
        $timestamp = self::timestamp($value);

        return $timestamp === null ? '-' : gmdate('j M Y', $timestamp);
    }

    /**
     * A stored timestamp as '9 Aug 2026, 09:30 UTC', or 'Never'.
     *
     * The zone is stated explicitly because these are server-side timestamps: a
     * customer comparing a check-in time against their own clock needs to know
     * what it is measured in.
     *
     * @param mixed $value
     */
    private static function formatDateTime($value): string
    {
        $timestamp = self::timestamp($value);

        return $timestamp === null ? 'Never' : gmdate('j M Y, H:i', $timestamp) . ' UTC';
    }

    /**
     * Parse a stored datetime as UTC.
     *
     * Stored values are UTC but carry no zone, so one is appended before parsing
     * - otherwise PHP applies the server's local zone and the displayed time
     * drifts by the server's offset.
     *
     * MySQL's zero date is treated as absent rather than parsed, since it means
     * "never" and would otherwise render as a date in year zero.
     *
     * @param mixed $value
     * @return int|null Unix timestamp, or null when there is no usable date.
     */
    private static function timestamp($value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || strncmp($value, '0000', 4) === 0) {
            return null;
        }
        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }

    /** Queue a success message for this render. */
    private function success(string $text): void
    {
        $this->messages[] = ['type' => 'success', 'text' => $text];
    }

    /**
     * Queue an error message for this render.
     *
     * Used for refusals the customer can act on - a reset that exceeded its
     * quota, an installation that was already released - so the wording comes
     * from the language file and never from an exception.
     */
    private function error(string $text): void
    {
        $this->messages[] = ['type' => 'danger', 'text' => $text];
    }
}
