<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Db;
use LicenseForge\Support\Lang;

/**
 * The licensing settings that appear on a WHMCS product's Module Settings tab.
 *
 * WHMCS stores a server module's configuration as `configoption1` through
 * `configoption24` on the product row - positional columns, with no record
 * anywhere of which option each position holds. {@see definitions()} is that
 * record, which is why the order of that array is part of the data format
 * rather than a matter of presentation.
 *
 * Reordering or removing an entry silently reassigns every option after it: a
 * grace period becomes an activation limit, a version constraint becomes a
 * prefix. Nothing detects that, because every value is a string in a column
 * whose name says nothing. New options are therefore only ever appended.
 *
 * This class reads and translates; it does not store. What it produces is
 * handed to {@see ProductConfig}, which mirrors it into the module's own table
 * for the licensing engine to query - see {@see toProductData()}.
 *
 * WHMCS's `tblproducts` is read directly throughout, so every access is
 * guarded: a product configured for another module, or a schema difference,
 * must not raise inside a billing hook.
 */
final class ModuleOptions
{
    /**
     * The module's directory name, which WHMCS records as a product's
     * `servertype`. How a licensed product is recognised - see
     * {@see licensedProducts()}.
     */
    public const MODULE_NAME = 'licenseforge';

    /**
     * Every option shown on the product's Module Settings tab, in column order.
     *
     * The array's order is the storage format: position N maps to
     * `configoptionN`. Append only - see the note on the class.
     *
     * Boolean-ish options are three-state (Inherit, Yes, No) rather than
     * checkboxes, because inheriting the global setting has to be
     * distinguishable from an explicit No. A checkbox cannot express that, and
     * collapsing the two would silently disable settings on every product that
     * had not overridden them.
     *
     * Help text comes from the language file, so a vendor's own translation
     * reaches the product form.
     *
     * @return array<string,array<string,string>>
     */
    public static function definitions(): array
    {
        $triState = ['Type' => 'dropdown', 'Options' => 'Inherit,Yes,No', 'Default' => 'Inherit'];

        return [
            'Product Slug' => [
                'Type'        => 'text',
                'Size'        => '30',
                'Description' => Lang::get('opt_product_slug_help',
                    'API identifier sent by your software as <code>product_id</code>. Leave blank to derive it from the product ID.'),
            ],
            'Key Prefix' => [
                'Type'        => 'text',
                'Size'        => '10',
                'Description' => Lang::get('opt_key_prefix_help',
                    'Optional prefix for generated license keys, e.g. <code>ACME</code>.'),
            ],
            'License Term' => [
                'Type'        => 'dropdown',
                'Options'     => 'billing_cycle,lifetime,fixed_days',
                'Default'     => 'billing_cycle',
                'Description' => Lang::get('opt_license_term_help',
                    'How long a license lasts. <strong>billing_cycle</strong> (recommended) ties it to what the customer pays for - the license expires on the service\'s next due date and is pushed out on every renewal, so monthly, quarterly and annual terms all work with no extra configuration. <strong>lifetime</strong> never expires. <strong>fixed_days</strong> uses the term below, independently of billing.'),
            ],
            'Fixed Term (Days)' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_fixed_term_days_help',
                    'Only used when License Term is <code>fixed_days</code>. Blank inherits the global default duration.'),
            ],
            'Trial Period (Days)' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_trial_period_days_help',
                    'Term applied to licenses issued as trials. Blank inherits the global default.'),
            ],
            'Max Activations' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_max_activations_help',
                    'Concurrent installations allowed per license. Blank inherits the global default.'),
            ],
            'Max Reissues' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_max_reissues_help',
                    'How many times a license may be moved to a new installation.'),
            ],
            'Grace Period (Days)' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_grace_period_days_help',
                    'Days an expired license keeps working before it stops validating.'),
            ],
            'Validation Interval (Hours)' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_validation_interval_hours_help',
                    'How often an installation should call home.'),
            ],
            'Offline Validity (Days)' => [
                'Type'        => 'text',
                'Size'        => '6',
                'Description' => Lang::get('opt_offline_validity_days_help',
                    'Lifetime of the signed offline token handed to the installation.'),
            ],
            'Lock To Domain' => $triState + ['Description' => Lang::get('opt_lock_to_domain_help',
                'Bind each activation to the domain it was activated on.')],
            'Lock To IP' => $triState + ['Description' => Lang::get('opt_lock_to_ip_help',
                'Bind each activation to its server IP address.')],
            'Lock To Directory' => $triState + ['Description' => Lang::get('opt_lock_to_directory_help',
                'Bind each activation to its installation path.')],
            'Lock To Machine ID' => $triState + ['Description' => Lang::get('opt_lock_to_machine_id_help',
                'Bind each activation to a hardware/machine identifier.')],
            'Allow Subdomains' => $triState + ['Description' => Lang::get('opt_allow_subdomains_help',
                'Treat subdomains of the licensed domain as the same installation.')],
            'Allow Local Domains' => $triState + ['Description' => Lang::get('opt_allow_local_domains_help',
                'Permit activation on localhost, .test, .local and private IPs.')],
            'Customer Reissue' => $triState + ['Description' => Lang::get('opt_customer_reissue_help',
                'Let the customer move the license themselves from the client area.')],
            'Reissue Approval' => $triState + ['Description' => Lang::get('opt_reissue_approval_help',
                'Hold customer reissue requests until an administrator approves them.')],
            'Minimum Version' => [
                'Type'        => 'text',
                'Size'        => '12',
                'Description' => Lang::get('opt_minimum_version_help',
                    'Oldest software version allowed to validate. Blank for no minimum.'),
            ],
            'Maximum Version' => [
                'Type'        => 'text',
                'Size'        => '12',
                'Description' => Lang::get('opt_maximum_version_help',
                    'Newest software version allowed to validate. Blank for no maximum.'),
            ],
            'Latest Version' => [
                'Type'        => 'text',
                'Size'        => '12',
                'Description' => Lang::get('opt_latest_version_help',
                    'Current release, reported back to installations so they can prompt to update.'),
            ],
            'Upgrade Behaviour' => [
                'Type'        => 'dropdown',
                'Options'     => 'carry_over,extend,new_license',
                'Default'     => 'carry_over',
                'Description' => Lang::get('opt_upgrade_behaviour_help',
                    'What happens to the license when the customer changes package.'),
            ],
            'Renewal Behaviour' => [
                'Type'        => 'dropdown',
                'Options'     => 'extend,reset,none',
                'Default'     => 'extend',
                'Description' => Lang::get('opt_renewal_behaviour_help',
                    'How the expiry moves when a renewal invoice is paid - only consulted for a <code>fixed_days</code> term. A <code>billing_cycle</code> term always follows the new due date.'),
            ],
            'Default Features' => [
                'Type'        => 'textarea',
                'Rows'        => '3',
                'Cols'        => '40',
                'Description' => Lang::get('opt_default_features_help',
                    'Feature slugs granted to every license for this product, one per line or comma separated.'),
            ],
        ];
    }

    /**
     * The option names in column order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Read the options out of the parameters WHMCS hands a module function.
     *
     * WHMCS supplies these two ways depending on which hook is running: named,
     * in `configoptions`, and positionally as `configoption1`… Both are
     * accepted, with the named form preferred - it cannot be thrown out of
     * alignment by a definition change, whereas the positional one can.
     *
     * @param  array<string,mixed> $params
     * @return array<string,string> Option name => value.
     */
    public static function fromParams(array $params): array
    {
        $values = [];
        foreach (self::names() as $index => $name) {
            $positional = $params['configoption' . ($index + 1)] ?? null;
            $named      = $params['configoptions'][$name] ?? null;

            $values[$name] = (string) ($named ?? $positional ?? '');
        }

        return $values;
    }

    /**
     * Read a product's options straight from the WHMCS product row.
     *
     * Used when there is no request to take them from - the cron, the admin
     * pages, a sync - where WHMCS is not passing a module any parameters.
     *
     * Returns null when the product is not configured for this module, which
     * {@see ProductConfig} treats as "no longer licensed". The check is
     * deliberately on `servertype` rather than on whether the columns happen to
     * hold anything.
     *
     * @return array<string,string>|null
     */
    public static function readForProduct(int $whmcsProductId): ?array
    {
        if ($whmcsProductId <= 0) {
            return null;
        }

        try {
            $product = Db::connection()->table('tblproducts')->where('id', $whmcsProductId)->first();
        } catch (\Throwable $e) {
            return null;
        }

        if ($product === null || (string) ($product->servertype ?? '') !== self::MODULE_NAME) {
            return null;
        }

        $values = [];
        foreach (self::names() as $index => $name) {
            $column        = 'configoption' . ($index + 1);
            $values[$name] = (string) ($product->$column ?? '');
        }

        return $values;
    }

    /**
     * A product's name, for display.
     *
     * Falls back to the id rather than an empty string, so a product row that
     * has gone missing still identifies itself in a list or an audit entry.
     */
    public static function productName(int $whmcsProductId): string
    {
        try {
            $product = Db::connection()->table('tblproducts')->where('id', $whmcsProductId)->first();
        } catch (\Throwable $e) {
            $product = null;
        }

        return $product !== null && (string) $product->name !== ''
            ? (string) $product->name
            : 'Product #' . $whmcsProductId;
    }

    /**
     * Translate the form's values into the shape {@see ProductConfig} mirrors.
     *
     * Where free text from an admin form becomes something the licensing engine
     * can rely on. Enumerated options are checked against their permitted values
     * and fall back to the documented default rather than being stored as typed;
     * a malformed value would otherwise reach the engine as a policy nobody
     * chose.
     *
     * The slug is normalised to a URL-safe form and defaults to `product-<id>`,
     * so a product always has an identifier a client can name even if nobody set
     * one.
     *
     * Three-state options pass through {@see triState()}, which preserves the
     * distinction between inheriting and being switched off.
     *
     * @param  array<string,string> $values
     * @return array<string,mixed>
     */
    public static function toProductData(int $whmcsProductId, array $values): array
    {
        $get = static fn (string $name): string => trim((string) ($values[$name] ?? ''));

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9._\-]/', '-', $get('Product Slug')));
        if ($slug === '') {
            $slug = 'product-' . $whmcsProductId;
        }

        return [
            'product_slug'              => $slug,
            'name'                      => self::productName($whmcsProductId),
            'licensing_enabled'         => true,
            'key_prefix'                => $get('Key Prefix'),
            'license_term'              => in_array($get('License Term'), self::TERMS, true)
                ? $get('License Term')
                : 'billing_cycle',
            'duration_days'             => $get('Fixed Term (Days)'),
            'trial_days'                => $get('Trial Period (Days)'),
            'max_activations'           => $get('Max Activations'),
            'max_reissues'              => $get('Max Reissues'),
            'grace_days'                => $get('Grace Period (Days)'),
            'validation_interval_hours' => $get('Validation Interval (Hours)'),
            'offline_validity_days'     => $get('Offline Validity (Days)'),
            'reissue_cooldown_hours'    => '',

            // Blank rather than absent, so ProductConfig stores null and the
            // product inherits the global setting.
            'lock_domain'               => self::triState($get('Lock To Domain')),
            'lock_ip'                   => self::triState($get('Lock To IP')),
            'lock_directory'            => self::triState($get('Lock To Directory')),
            'lock_machine'              => self::triState($get('Lock To Machine ID')),
            'allow_subdomains'          => self::triState($get('Allow Subdomains')),
            'allow_local_domains'       => self::triState($get('Allow Local Domains')),
            'reissue_self_service'      => self::triState($get('Customer Reissue')),
            'reissue_requires_approval' => self::triState($get('Reissue Approval')),
            'min_version'               => $get('Minimum Version'),
            'max_version'               => $get('Maximum Version'),
            'allowed_versions'          => '',
            'latest_version'            => $get('Latest Version'),
            'upgrade_behaviour'         => in_array($get('Upgrade Behaviour'), ['carry_over', 'extend', 'new_license'], true)
                ? $get('Upgrade Behaviour')
                : 'carry_over',
            'renewal_behaviour'         => in_array($get('Renewal Behaviour'), ['extend', 'reset', 'none'], true)
                ? $get('Renewal Behaviour')
                : 'extend',
            'default_features'          => \LicenseForge\Support\Input::toList($get('Default Features')),
            'notes'                     => '',

            // Not exposed on the product tab; the lifecycle hooks rely on them.
            'auto_activate'             => true,
            'auto_suspend'              => true,
            'auto_expire'               => true,
            'download_protection'       => \LicenseForge\Support\Settings::bool('download_protection', true),
        ];
    }

    /**
     * Translate an Inherit/Yes/No dropdown into its stored form.
     *
     * Returns the literal string 'inherit' rather than an empty value, so the
     * intent survives into {@see ProductConfig::save()}, which turns it into a
     * null column. An empty string would be indistinguishable from an option
     * that was never on the form.
     */
    private static function triState(string $value): string
    {
        $value = strtolower($value);
        if ($value === 'yes') {
            return '1';
        }
        if ($value === 'no') {
            return '0';
        }

        return 'inherit';
    }

    /** The licence terms a product may be configured with. */
    public const TERMS = ['billing_cycle', 'lifetime', 'fixed_days'];

    /**
     * Products whose stored options no longer mean what they say.
     *
     * A specific upgrade hazard, not a general validation. The licence-term
     * option changed from free text to a dropdown, and WHMCS stores dropdown
     * values by their label - so a product configured before that change holds a
     * value the current code does not recognise and silently treats as the
     * default, with nothing to indicate it.
     *
     * Detected by finding a term that is neither empty nor one of the permitted
     * values, and surfaced on the dashboard with a link straight to the tab that
     * fixes it; re-saving the product is all that is required.
     *
     * @return list<array{id:int,name:string,found:string,editUrl:string}>
     */
    public static function productsNeedingResave(): array
    {
        $stale = [];

        foreach (self::licensedProducts() as $product) {
            $values = self::readForProduct($product['id']);
            if ($values === null) {
                continue;
            }

            $term = trim((string) ($values['License Term'] ?? ''));

            // Empty is a product that predates the option entirely, which the
            // default covers correctly and needs no attention.
            if ($term === '' || in_array($term, self::TERMS, true)) {
                continue;
            }

            $stale[] = [
                'id'      => $product['id'],
                'name'    => $product['name'],
                'found'   => $term,
                'editUrl' => 'configproducts.php?action=edit&id=' . $product['id'] . '&tab=3',
            ];
        }

        return $stale;
    }

    /**
     * Every WHMCS product configured to use this module.
     *
     * Identified by `servertype`, which is how WHMCS records a product's server
     * module. Returns an empty list if the table cannot be read, so the pages
     * that call this degrade to showing nothing rather than failing.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function licensedProducts(): array
    {
        try {
            $rows = Db::connection()->table('tblproducts')
                ->where('servertype', self::MODULE_NAME)
                ->orderBy('name')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'gid' => (int) $row->gid];
        }

        return $out;
    }
}
