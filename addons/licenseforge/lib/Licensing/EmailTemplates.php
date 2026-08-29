<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;

/**
 * The module's email templates: their content, and installing them into WHMCS.
 *
 * The templates live in WHMCS's own `tblemailtemplates`, not in this module.
 * That is what lets a vendor edit them where they edit every other customer
 * email, in their own branding and language, with WHMCS handling delivery and
 * logging.
 *
 * The definitions here are therefore starting points, not the live content.
 * Once installed, the copy in WHMCS is authoritative and this class will not
 * overwrite it unless explicitly asked to reset - a vendor's edits surviving an
 * upgrade is the whole point of that rule.
 *
 * Installation is idempotent and can be re-run safely: templates already
 * present are left alone and reported as kept.
 *
 * Writes to a WHMCS table rather than the module's own, so every access is
 * guarded and column-checked - see {@see insert()}.
 */
final class EmailTemplates
{
    /**
     * The WHMCS template type these are registered under.
     *
     * Product templates are addressed by service, which is what allows an email
     * to carry the licence's own merge fields - a general template has no
     * service to resolve them against.
     */
    private const TYPE = 'product';

    /**
     * The type earlier versions registered these under.
     *
     * Recognised so an installation upgraded from one of those is found and
     * corrected rather than having a second copy created alongside the first -
     * see {@see install()} and {@see findByName()}.
     */
    private const LEGACY_TYPE = 'general';

    /**
     * Every template the module ships, keyed by the setting that names it.
     *
     * The key is the setting rather than the template name, because a vendor may
     * point any of these at a template of their own; the setting is the
     * indirection that makes that possible.
     *
     * Each definition also carries `label`, `when` and `fields` - displayed on
     * the settings page so an administrator can see what each email is for and
     * which merge fields it may use, without opening WHMCS to find out.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        $footer = "\n\n<p>{\$signature}</p>";

        return [
            'email_license_created' => [
                'name'    => 'LicenseForge License Created',
                'label'   => 'License issued',
                'when'    => 'A license is issued for a new order.',
                'fields'  => ['license_key', 'license_product', 'license_expires', 'license_activation_limit'],
                'subject' => 'Your license key for {$license_product}',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> is ready.</p>\n"
                    . "<p style=\"font-size:16px\"><strong>License key:</strong> <code>{\$license_key}</code></p>\n"
                    . "<p>Enter this key when you install the software. It covers up to "
                    . "{\$license_activation_limit} installation(s) and is valid until {\$license_expires}.</p>\n"
                    . "<p>You can always find your key, see where it is in use and manage your installations "
                    . "from your account: <a href=\"{\$whmcs_url}/clientarea.php?action=services\">{\$whmcs_url}</a></p>"
                    . $footer,
            ],

            'email_license_activated' => [
                'name'    => 'LicenseForge License Activated',
                'label'   => 'Installation activated',
                'when'    => 'A new installation activates the license.',
                'fields'  => ['license_key', 'license_product', 'activation_domain', 'activation_ip', 'license_activations'],
                'subject' => '{$license_product} activated on {$activation_domain}',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> has just been activated on "
                    . "<strong>{\$activation_domain}</strong> ({\$activation_ip}).</p>\n"
                    . "<p>You are now using {\$license_activations} available installations.</p>\n"
                    . "<p>If this was not you, please reply to this email straight away - someone else may have "
                    . "your license key.</p>"
                    . $footer,
            ],

            'email_license_expiring' => [
                'name'    => 'LicenseForge License Expiring',
                'label'   => 'Expiry reminder',
                'when'    => 'Sent at each reminder threshold before expiry.',
                'fields'  => ['license_key', 'license_product', 'license_expires', 'days_remaining'],
                'subject' => 'Your {$license_product} license expires in {$days_remaining} days',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> expires on "
                    . "<strong>{\$license_expires}</strong> - that is {\$days_remaining} day(s) from now.</p>\n"
                    . "<p>Renewing keeps the software running without interruption. You can renew from your "
                    . "account: <a href=\"{\$whmcs_url}/clientarea.php\">{\$whmcs_url}</a></p>\n"
                    . "<p>If the licence lapses the software will stop validating and stop working.</p>"
                    . $footer,
            ],

            'email_license_expired' => [
                'name'    => 'LicenseForge License Expired',
                'label'   => 'License expired',
                'when'    => 'The license passes its expiry date and any grace period.',
                'fields'  => ['license_key', 'license_product', 'license_expires'],
                'subject' => 'Your {$license_product} license has expired',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> expired on "
                    . "{\$license_expires} and the software will no longer validate.</p>\n"
                    . "<p>Renewing reactivates the same license key - you will not need to reinstall or "
                    . "re-enter anything: <a href=\"{\$whmcs_url}/clientarea.php\">{\$whmcs_url}</a></p>"
                    . $footer,
            ],

            'email_license_suspended' => [
                'name'    => 'LicenseForge License Suspended',
                'label'   => 'License suspended',
                'when'    => 'The license is suspended, usually with its service.',
                'fields'  => ['license_key', 'license_product', 'status_reason'],
                'subject' => 'Your {$license_product} license has been suspended',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> has been suspended, and the "
                    . "software will stop working at its next check.</p>\n"
                    . "<p><strong>Reason:</strong> {\$status_reason}</p>\n"
                    . "<p>If you think this is a mistake, reply to this email and we will look into it.</p>"
                    . $footer,
            ],

            'email_license_reissued' => [
                'name'    => 'LicenseForge License Reissued',
                'label'   => 'License reset',
                'when'    => 'The customer or an administrator resets the license.',
                'fields'  => ['license_key', 'license_product', 'previous_key'],
                'subject' => 'Your {$license_product} license has been reset',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Your license for <strong>{\$license_product}</strong> has been reset. Every previous "
                    . "installation has been deactivated and can no longer use it.</p>\n"
                    . "<p style=\"font-size:16px\"><strong>Your license key:</strong> <code>{\$license_key}</code></p>\n"
                    . "<p>Use it to activate the software wherever you need it now.</p>\n"
                    . "<p>If you did not ask for this, reply to this email immediately.</p>"
                    . $footer,
            ],

            'email_activation_limit' => [
                'name'    => 'LicenseForge Activation Limit Reached',
                'label'   => 'Activation limit reached',
                'when'    => 'An activation is refused because every slot is in use.',
                'fields'  => ['license_key', 'license_product', 'license_activations'],
                'subject' => 'Activation limit reached for {$license_product}',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>Someone tried to activate <strong>{\$license_product}</strong> but every installation "
                    . "slot is in use ({\$license_activations}).</p>\n"
                    . "<p>You can free a slot by deactivating an installation you no longer use, on your product "
                    . "page: <a href=\"{\$whmcs_url}/clientarea.php?action=services\">{\$whmcs_url}</a></p>\n"
                    . "<p>If you need more installations, reply and we will upgrade your license.</p>"
                    . $footer,
            ],

            'email_suspicious_activity' => [
                'name'    => 'LicenseForge Suspicious Activity',
                'label'   => 'Suspicious activity',
                'when'    => 'Abuse detection flags unusual use of a license.',
                'fields'  => ['license_key', 'license_product', 'abuse_signal', 'abuse_summary'],
                'subject' => 'Unusual activity on your {$license_product} license',
                'body'    => "<p>Hi {\$client_name},</p>\n"
                    . "<p>We noticed unusual activity on your <strong>{\$license_product}</strong> license.</p>\n"
                    . "<p>{\$abuse_summary}</p>\n"
                    . "<p>If this was you, no action is needed. If not, your license key may have been shared or "
                    . "stolen - reply to this email and we will reset it for you.</p>"
                    . $footer,
            ],
        ];
    }

    /**
     * Create the templates in WHMCS, or restore them to their shipped content.
     *
     * Four outcomes per template, all reported separately because they mean
     * different things to whoever ran this:
     *
     *   installed  Did not exist; created.
     *   retyped    Existed under the legacy type; corrected in place, keeping
     *              the vendor's edits.
     *   reset      Content restored to the shipped version, on request.
     *   kept       Existed and was left exactly as it was.
     *
     * Retyping deliberately preserves content: an installation upgrading from an
     * older version has the right template with the wrong type, and replacing it
     * would discard edits made over its lifetime to fix a metadata field.
     *
     * Each template is handled independently and a failure is recorded rather
     * than thrown, so one problem template does not prevent the rest installing.
     *
     * @param  bool $reset Overwrite existing content with the shipped version.
     * @return array{installed:list<string>,retyped:list<string>,reset:list<string>,kept:list<string>,failed:list<string>}
     */
    public static function install(bool $reset = false): array
    {
        $report = ['installed' => [], 'retyped' => [], 'reset' => [], 'kept' => [], 'failed' => []];

        foreach (self::definitions() as $definition) {
            try {
                $existing = self::findByName($definition['name']);

                if ($existing === null) {
                    self::insert($definition);
                    $report['installed'][] = $definition['name'];
                    continue;
                }

                if ((string) $existing->type === self::LEGACY_TYPE) {
                    Db::connection()->table('tblemailtemplates')
                        ->where('id', (int) $existing->id)
                        ->update(['type' => self::TYPE]);
                    $report['retyped'][] = $definition['name'];

                    if (!$reset) {
                        continue;
                    }
                }

                if ($reset) {
                    Db::connection()->table('tblemailtemplates')
                        ->where('id', (int) $existing->id)
                        ->update([
                            'subject' => $definition['subject'],
                            'message' => $definition['body'],
                        ]);
                    $report['reset'][] = $definition['name'];
                    continue;
                }

                $report['kept'][] = $definition['name'];
            } catch (\Throwable $e) {
                error_log('[LicenseForge] email template "' . $definition['name'] . '": ' . $e->getMessage());
                $report['failed'][] = $definition['name'];
            }
        }

        if ($report['installed'] !== [] || $report['reset'] !== [] || $report['retyped'] !== []) {
            Audit::log('email_templates.installed', null, Audit::RESULT_SUCCESS, $report, Audit::ACTOR_ADMIN);
        }

        return $report;
    }

    /**
     * Which templates exist, for the settings page.
     *
     * Resolves each through its setting first, so the status reflects the
     * template actually in use - a vendor pointing a setting at their own
     * template should see that one reported, not the shipped default marked
     * missing.
     *
     * Each row carries a link straight to the template in WHMCS, since editing
     * them is what an administrator is here to do.
     *
     * @return list<array<string,mixed>>
     */
    public static function status(): array
    {
        $rows = [];

        foreach (self::definitions() as $setting => $definition) {
            $name     = trim((string) \LicenseForge\Support\Settings::get($setting, $definition['name']));
            $name     = $name !== '' ? $name : $definition['name'];
            $existing = self::findByName($name);

            $rows[] = [
                'setting' => $setting,
                'label'   => $definition['label'],
                'when'    => $definition['when'],
                'name'    => $name,
                'fields'  => $definition['fields'],
                'exists'  => $existing !== null,
                'id'      => $existing !== null ? (int) $existing->id : 0,
                'editUrl' => $existing !== null
                    ? 'configemailtemplates.php?action=edit&id=' . (int) $existing->id
                    : 'configemailtemplates.php',
            ];
        }

        return $rows;
    }

    /**
     * Find a WHMCS email template by name.
     *
     * Matches both the current and legacy types, ordered so the current one wins
     * where an installation somehow has both - otherwise the outcome would
     * depend on insertion order, and an upgrade could operate on the stale copy.
     *
     * Failure is treated as absence: this reads a WHMCS table whose shape varies
     * by version, and the callers all handle a missing template.
     *
     * @return object|null
     */
    private static function findByName(string $name)
    {
        if ($name === '') {
            return null;
        }

        try {
            return Db::connection()->table('tblemailtemplates')
                ->where('name', $name)
                ->whereIn('type', [self::TYPE, self::LEGACY_TYPE])
                ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [self::TYPE])
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Insert a template into WHMCS.
     *
     * Every column is checked for existence before being written.
     * `tblemailtemplates` differs across WHMCS versions, and naming a column
     * that does not exist fails the whole insert - so the payload is narrowed to
     * what this installation actually has rather than assuming a schema.
     *
     * `custom` marks it as vendor-owned, which is what stops a WHMCS upgrade
     * reverting or removing it.
     */
    private static function insert(array $definition): void
    {
        $candidate = [
            'type'         => self::TYPE,
            'name'         => $definition['name'],
            'subject'      => $definition['subject'],
            'message'      => $definition['body'],
            'attachments'  => '',
            'fromname'     => '',
            'fromemail'    => '',
            'disabled'     => 0,
            'custom'       => 1,
            'language'     => '',
            'copyto'       => '',
            'blindcopyto'  => '',
            'plaintext'    => 0,
        ];

        $payload = [];
        foreach ($candidate as $column => $value) {
            if (Db::schema()->hasColumn('tblemailtemplates', $column)) {
                $payload[$column] = $value;
            }
        }

        Db::connection()->table('tblemailtemplates')->insert($payload);
    }

    /**
     * Every merge field available to these templates, with what it holds.
     *
     * Displayed on the settings page so a vendor editing a template knows what
     * they can reference. The fields noted as belonging to one email are
     * populated only there - they exist on every message as empty strings, so
     * referencing one in the wrong template renders a blank rather than the
     * literal placeholder text reaching a customer.
     *
     * @return array<string,string>
     */
    public static function mergeFields(): array
    {
        return [
            'license_key'              => 'The license key itself',
            'license_status'           => 'Active, Suspended, Expired …',
            'license_product'          => 'Product name',
            'license_expires'          => 'Expiry date, or "Never"',
            'license_domain'           => 'Domain the license is bound to',
            'license_activations'      => 'Used of allowed, e.g. "2 of 3"',
            'license_activation_limit' => 'How many installations are allowed',
            'license_reissues'         => 'Resets used of allowed',
            'license_version'          => 'Version last seen checking in',
            'days_remaining'           => 'Days until expiry',
            'status_reason'            => 'Suspension emails only',
            'previous_key'             => 'Reset emails only',
            'activation_domain'        => 'Activation emails only',
            'activation_ip'            => 'Activation emails only',
            'abuse_signal'             => 'Suspicious activity emails only',
            'abuse_summary'            => 'Suspicious activity emails only',
        ];
    }
}
