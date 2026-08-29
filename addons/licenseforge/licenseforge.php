<?php
/**
 * LicenseForge - Software Licensing Platform for WHMCS.
 * =====================================================
 *
 * The addon module entry point. WHMCS discovers a module by this file's name
 * matching its directory, then calls the `licenseforge_*` functions below at
 * fixed points in the module lifecycle. The prefix is the contract: rename the
 * directory and every one of these functions has to be renamed with it.
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS MODULE DOES
 * -----------------------------------------------------------------------------
 *
 * Issues, activates, validates, reissues, suspends and revokes software
 * licences tied to WHMCS products and services. This addon is the administrative
 * half - the admin UI, the schema, the settings, the API credentials. It has
 * three companions:
 *
 *   modules/servers/licenseforge     provisioning, and the customer's panel
 *   modules/addons/licenseforge/api  the public endpoint SDKs call
 *   sdk/                             the client libraries you ship
 *
 * -----------------------------------------------------------------------------
 * THE LIFECYCLE HOOKS WHMCS CALLS HERE
 * -----------------------------------------------------------------------------
 *
 *   licenseforge_config()      Metadata and the Configure screen's fields.
 *                              Called on every admin addon page load.
 *   licenseforge_activate()    Once, when the addon is first activated.
 *   licenseforge_deactivate()  When it is deactivated.
 *   licenseforge_upgrade()     On every version change.
 *   licenseforge_output()      To render the admin UI.
 *
 * There is deliberately no `licenseforge_clientarea()`. Customers see their
 * licence on the product details page, rendered by the provisioning module,
 * because that is where a licence belongs - beside the service it came with,
 * not on a separate page they have to find.
 *
 * -----------------------------------------------------------------------------
 * WHERE THE REAL WORK LIVES
 * -----------------------------------------------------------------------------
 *
 * These functions stay thin on purpose. Each validates its situation and hands
 * off to a class under lib/. WHMCS calls them as plain global functions, which
 * cannot be unit-tested in isolation or reused, so keeping logic out of them is
 * what makes the rest of the module testable.
 *
 * @package LicenseForge
 * @author  Ahmad Abu Assab (Fast Hive) <https://fasthive.com>
 */

declare(strict_types=1);

use LicenseForge\Admin\Controller as AdminController;
use LicenseForge\Api\Credentials;
use LicenseForge\Database\Schema;
use LicenseForge\Licensing\EmailTemplates;
use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Lang;
use LicenseForge\Support\Settings;
use LicenseForge\Support\View;

// WHMCS defines this before including any module file. Its absence means the
// file was requested directly over HTTP, with no session, no configuration and
// no database - so there is nothing to do but stop.
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Module metadata, and the fields shown on the addon's Configure screen.
 *
 * WHMCS calls this on every admin addon page load, so it must stay cheap and
 * side-effect free.
 *
 * Only a handful of settings appear here. The rest live on the module's own
 * Settings page, which is a deliberate split rather than an oversight: WHMCS
 * stores these fields with no validation and no audit trail, and it stores them
 * positionally, so inserting a field shifts every value after it. The module's
 * own page can validate what it is given, record who changed it, and name its
 * settings rather than counting them.
 *
 * What remains here is the minimum needed to get a fresh install running before
 * the admin has visited the Settings page - these values seed the real settings
 * store during activation.
 *
 * @return array<string,mixed> WHMCS module configuration.
 */
function licenseforge_config(): array
{
    return [
        'name'        => 'LicenseForge',
        'description' => Lang::get('cfg_description'),
        'author'      => 'Ahmad Abu Assab (Fast Hive)',
        'language'    => 'english',
        'version'     => LICENSEFORGE_VERSION,
        'fields'      => [
            'license_server_url' => [
                'FriendlyName' => Lang::get('cfg_api_url'),
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => Lang::get('cfg_api_url_help'),
            ],
            'require_api_auth' => [
                'FriendlyName' => Lang::get('cfg_require_auth'),
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => Lang::get('cfg_require_auth_help'),
            ],
            'default_duration_days' => [
                'FriendlyName' => Lang::get('cfg_duration'),
                'Type'         => 'text',
                'Size'         => '6',
                'Default'      => '365',
                'Description'  => Lang::get('cfg_duration_help'),
            ],
            'default_max_activations' => [
                'FriendlyName' => Lang::get('cfg_activations'),
                'Type'         => 'text',
                'Size'         => '6',
                'Default'      => '1',
                'Description'  => Lang::get('cfg_activations_help'),
            ],
            'download_protection' => [
                'FriendlyName' => Lang::get('cfg_downloads'),
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => Lang::get('cfg_downloads_help'),
            ],
        ],
    ];
}

/**
 * Activate the module: build the schema and make the install usable.
 *
 * Called when an administrator activates the addon. It may also be called again
 * after a deactivation, so every step below is written to be safe on a fresh
 * install *and* on a re-activation over existing data - the two are told apart
 * by inspecting what is already there, never by assuming.
 *
 * In order:
 *
 *   1. Run migrations to create or update the schema.
 *   2. Seed settings from the Configure fields, without overwriting any that
 *      already hold a value.
 *   3. Decide the installation-proof default (see below).
 *   4. Generate the first offline signing key.
 *   5. Install the licensing email templates into WHMCS.
 *   6. Issue a first API credential, disabled.
 *
 * The returned description is rendered as HTML by WHMCS and is the only chance
 * to show the API secret, which is not recoverable afterwards.
 *
 * @return array{status:string,description:string} WHMCS activation result.
 */
function licenseforge_activate(): array
{
    try {
        $applied = Schema::migrate();

        // Seed the settings store from the Configure screen's defaults, but
        // only where nothing is stored yet - a re-activation must not discard
        // values an administrator has since changed on the Settings page.
        $config = licenseforge_config();
        foreach ($config['fields'] as $key => $field) {
            if (Settings::get($key) === null || Settings::get($key) === '') {
                $default = $field['Default'] ?? '';
                if ($default !== '') {
                    // WHMCS yesno fields default to the strings 'yes'/'no';
                    // the settings store keeps booleans as '1'/'0'.
                    Settings::set($key, $default === 'yes' ? '1' : ($default === 'no' ? '0' : $default));
                }
            }
        }

        /*
         * Installation proofs: closed on a fresh install, open on an upgrade.
         *
         * Requiring per-installation proof is the stronger setting, but turning
         * it on over an existing install locks out every activation that
         * predates per-installation credentials - those installs hold nothing
         * to prove themselves with, and would start failing immediately. That
         * is why the shipped default is off.
         *
         * A fresh install has no such activations to strand, so it can start
         * closed and never go through that migration at all.
         *
         * The two are distinguished here rather than in Settings::defaults()
         * because only here is it knowable: migrate() has just run, so the
         * tables exist, and an empty activations table means nobody has ever
         * activated against this server.
         *
         * The stored row is checked directly rather than through
         * Settings::get(), which merges defaults and so can never report
         * "nobody has chosen". Without that distinction, re-activating the
         * module would silently close the setting again, overriding an
         * administrator who had deliberately turned it off.
         */
        $proofChosen = Db::table('settings')
            ->where('setting_key', 'require_install_proof')
            ->exists();

        if (!$proofChosen && !Db::table('activations')->exists()) {
            Settings::set('require_install_proof', '1');
        }

        // The first offline signing key, so SDKs can verify offline tokens from
        // the very first activation rather than after a separate setup step.
        if (!Db::table('signing_keys')->exists()) {
            Crypto::generateSigningKey();
        }

        // Record what the master key looks like now, so a later change is
        // reported rather than discovered when an installation stops
        // authenticating. Done here because this is the one moment the key is
        // known to be the right one: it has just encrypted the signing key above.
        // Recording lazily on first read would instead adopt whatever key was
        // found, including a wrong one on an installation already in trouble.
        Crypto::checkKeyIntegrity(true);

        // Install the licensing emails as real WHMCS templates. Without this
        // the notifications resolve to template names that were never created
        // and fail silently - nothing errors, customers simply never hear
        // anything, which is the hardest kind of fault to notice.
        $emails = EmailTemplates::install();

        $notes = [];
        if ($emails['installed'] !== []) {
            $notes[] = count($emails['installed']) . ' email template(s) installed';
        }
        if (!Db::table('api_credentials')->exists()) {
            /*
             * The first API credential: created inactive and unscoped.
             *
             * No product exists at install time, so there is nothing to scope
             * this to - which is precisely why it must not be born authorised
             * for everything. A credential ships inside the software you
             * distribute, and client software can always be unpacked; one that
             * may act on every product offers no second boundary when it leaks.
             *
             * So the secret is issued now, while there is a screen to show it
             * on, and authorising it is a separate deliberate act on the API tab
             * once products exist. Nothing can authenticate with it before then,
             * and nothing needs to: there are no licences yet either.
             */
            $credential = Credentials::create([
                'name'               => 'Default SDK credential',
                'scopes'             => 'activate,check',
                'is_active'          => false,
                'allow_all_products' => false,
            ]);
            /*
             * Plain text, no markup.
             *
             * WHMCS escapes this description before printing it, so tags arrive on
             * screen as literal `<code>` rather than as formatting - and they land
             * in the middle of the one credential the administrator has to copy
             * accurately, which is the worst place to put noise. Escaping the
             * values here was likewise pointless: WHMCS escapes the whole string
             * again, so a key would have been double-encoded.
             */
            $notes[] = 'API key: ' . $credential['api_key'];
            $notes[] = 'API secret: ' . $credential['api_secret'] . ' (shown once - copy it now)';
            $notes[] = 'This credential is DISABLED. Configure your products, '
                . 'then scope it to them and enable it on the API tab.';
        }

        Audit::log('module.activated', null, Audit::RESULT_SUCCESS, [
            'version' => LICENSEFORGE_VERSION, 'migrations' => $applied,
        ], Audit::ACTOR_ADMIN);

        return [
            'status'      => 'success',
            'description' => Lang::get('cfg_installed') . ' ' . implode(' · ', $notes),
        ];
    } catch (\Throwable $e) {
        // The message reaches the administrator through the return value; the
        // log entry carries it for whoever debugs a failed install later, since
        // WHMCS does not retain the activation screen's output.
        error_log('[LicenseForge] activation failed: ' . $e->getMessage());

        return [
            'status'      => 'error',
            'description' => Lang::get('cfg_install_failed', '', ['error' => $e->getMessage()]),
        ];
    }
}

/**
 * Deactivate the module, preserving all licensing data.
 *
 * Deactivation is not uninstallation. Licences, activations and history all
 * survive, so the module can be switched off and back on - during an
 * investigation, a migration, or by accident - without a single customer losing
 * their licence.
 *
 * Destroying the data is possible but is a separate, explicit act: it requires
 * a POST carrying `licenseforge_destroy_data` set to the exact confirmation
 * phrase. See the uninstall section of documentation/index.html.
 *
 * @return array{status:string,description:string} WHMCS deactivation result.
 */
function licenseforge_deactivate(): array
{
    try {
        // Logged before anything is dropped. If the destructive path runs, the
        // audit table goes with it, so this entry has to be written while it
        // still exists.
        Audit::log('module.deactivated', null, Audit::RESULT_SUCCESS, [], Audit::ACTOR_ADMIN);

        /*
         * Read the confirmation from POST only, never $_REQUEST.
         *
         * $_REQUEST also reads the query string, which would make the single
         * most destructive operation in this module reachable by URL - and a
         * URL can be followed by a link, a redirect, a browser prefetch or a
         * security scanner, none of which involve anyone deciding to do it.
         *
         * WHMCS drives deactivation through a form, so requiring POST costs
         * nothing and removes a shape this operation should never have had.
         */
        $destroy = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            ? (string) ($_POST['licenseforge_destroy_data'] ?? '')
            : '';

        // An exact-match phrase rather than a truthy flag: this cannot be
        // triggered by a stray `1` arriving from anywhere.
        if ($destroy === 'DELETE-ALL-LICENSING-DATA') {
            Schema::dropAll();

            return [
                'status'      => 'success',
                'description' => Lang::get('cfg_removed'),
            ];
        }

        return [
            'status'      => 'success',
            'description' => Lang::get('cfg_deactivated'),
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Deactivation failed: ' . $e->getMessage()];
    }
}

/**
 * Upgrade the module, called by WHMCS on every version change.
 *
 * Migrations are idempotent and track what has already been applied, so this is
 * safe to run repeatedly and safe across a skipped version - upgrading from an
 * old release applies everything in between rather than only the last step.
 *
 * Failures are logged rather than thrown. WHMCS runs this during an admin page
 * load with no error handling of its own, so throwing would replace the admin
 * area with a fatal error and leave no route back in to fix it. The applied
 * migrations are listed on the Settings page, which is where a partial upgrade
 * becomes visible.
 *
 * @param array<string,mixed> $vars WHMCS module variables; `version` holds the
 *                                  version being upgraded from.
 *
 * @return void
 */
function licenseforge_upgrade(array $vars): void
{
    try {
        $applied = Schema::migrate();
        Audit::log('module.upgraded', null, Audit::RESULT_SUCCESS, [
            'from'       => $vars['version'] ?? 'unknown',
            'to'         => LICENSEFORGE_VERSION,
            'migrations' => $applied,
        ], Audit::ACTOR_ADMIN);
    } catch (\Throwable $e) {
        error_log('[LicenseForge] upgrade failed: ' . $e->getMessage());
    }
}

/**
 * Render the admin interface.
 *
 * The entry point for every admin page in the module. It echoes rather than
 * returns, which is WHMCS's contract for addon output.
 *
 * Two values are taken from WHMCS rather than reconstructed, because WHMCS owns
 * both and either could change without this module being told:
 *
 *   modulelink  how WHMCS wants to be linked back to. Building this by hand
 *               produces links that work until an installation is reached by a
 *               different path or the admin directory is renamed.
 *   _lang       the language file WHMCS chose for *this* administrator, which
 *               is what lets the module follow a per-admin language setting.
 *
 * ensureSchema() runs first so that an install whose migrations did not
 * complete - an upgrade that failed, a manually restored database - repairs
 * itself on the next admin visit rather than presenting errors about missing
 * columns.
 *
 * @param array<string,mixed> $vars WHMCS module variables.
 *
 * @return void Output is echoed.
 */
function licenseforge_output(array $vars): void
{
    AdminController::ensureSchema();

    View::useModuleLink((string) ($vars['modulelink'] ?? ''));

    if (!empty($vars['_lang']) && is_array($vars['_lang'])) {
        Lang::useWhmcsStrings($vars['_lang']);
    }

    $controller = new AdminController($vars);
    echo $controller->handle();
}
