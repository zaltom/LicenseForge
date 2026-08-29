<?php

declare(strict_types=1);

namespace LicenseForge\Database;

use Illuminate\Database\Schema\Blueprint;
use LicenseForge\Support\Db;

/**
 * The module's database schema and its migration ledger.
 *
 * Owns every table LicenseForge creates, and the ordered list of changes that
 * brings any installation - new or years old - to the current shape.
 *
 * How migrations work here
 * ------------------------
 * {@see migrations()} maps a permanent name to the method that performs it.
 * {@see migrate()} runs any name not already recorded in the `migrations` table
 * and records it. There is no rollback: a customer's licensing data is not
 * something to undo automatically, and a failed upgrade is repaired forward.
 *
 * Two rules make that safe, and both must hold for every migration added here:
 *
 *   1. Idempotent. Each guards itself with hasTable()/hasColumn() and returns
 *      early if its change is already present, so re-running one is harmless.
 *   2. Append-only names. A name that has shipped is never renamed or reordered
 *      - the ledger matches on the string, so changing one makes an installation
 *      re-run work it has already done.
 *
 * Migrations run on activation and on admin page loads, inside a normal WHMCS
 * request. Failures are logged rather than thrown by the caller, so a schema
 * problem does not take the admin area down with it - the settings page lists
 * what has actually been applied, which is where it becomes visible.
 *
 * Table naming goes through {@see Db::name()}, which applies the module's
 * prefix, so nothing here collides with WHMCS's own tables.
 */
final class Schema
{
    /**
     * Every migration, in the order it must run.
     *
     * The keys are permanent identifiers, not descriptions, and are what the
     * ledger stores. The date prefix exists only to fix the order and to read in
     * the conventional Laravel shape; it is not the date anything happened.
     *
     * These five are the 1.0 baseline: they describe the schema as shipped, not
     * the sequence it was arrived at. Development produced fourteen further
     * steps that added columns, indexes and constraints to these same tables;
     * since no installation outside development ever ran them, they were folded
     * into the creates above before release rather than shipped as history a
     * buyer would replay against tables that never lacked those columns.
     *
     * The removed names are not reused. An installation that did apply them
     * keeps those ledger rows, which are then inert - the work is already
     * present in its tables, and no entry here claims it again.
     *
     * From 1.0 onward, appending is the only safe edit. Renaming or reordering
     * an entry that has shipped makes every installation holding the old name
     * re-run that work, which the idempotence guards survive but which also
     * duplicates the ledger.
     *
     * @return array<string,callable>
     */
    private static function migrations(): array
    {
        return [
            '2026_01_01_000001_create_core_tables'      => [self::class, 'createCoreTables'],
            '2026_01_01_000002_create_license_tables'   => [self::class, 'createLicenseTables'],
            '2026_01_01_000003_create_activity_tables'  => [self::class, 'createActivityTables'],
            '2026_01_01_000004_create_security_tables'  => [self::class, 'createSecurityTables'],
            '2026_01_01_000005_seed_defaults'           => [self::class, 'seedDefaults'],
        ];
    }

    /**
     * Run every migration that has not been applied yet.
     *
     * The ledger is read once up front rather than queried per migration, so
     * this costs one query on the common path where there is nothing to do - and
     * it runs on admin page loads.
     *
     * Each migration is recorded immediately after it succeeds rather than in a
     * batch at the end, so an exception part-way through leaves the completed
     * ones marked and the next run resumes rather than restarting.
     *
     * Deliberately not wrapped in a transaction: MySQL commits DDL implicitly,
     * so a transaction around schema changes would give a false impression of
     * atomicity. Idempotence is what makes a partial run recoverable instead.
     *
     * @return list<string> Names of the migrations that ran this time.
     */
    public static function migrate(): array
    {
        self::ensureMigrationsTable();

        $applied = [];
        foreach (Db::table('migrations')->pluck('migration') as $name) {
            $applied[(string) $name] = true;
        }

        $ran = [];
        foreach (self::migrations() as $name => $callback) {
            if (isset($applied[$name])) {
                continue;
            }
            $callback();
            Db::table('migrations')->insert([
                'migration' => $name,
                'applied_at' => Db::now(),
            ]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Create the ledger itself, on a database that has never seen this module.
     *
     * The unique index on `migration` is the backstop for the whole scheme: even
     * if two requests raced into migrate() together, the same migration cannot
     * be recorded twice.
     */
    private static function ensureMigrationsTable(): void
    {
        if (Db::hasTable('migrations')) {
            return;
        }
        Db::schema()->create(Db::name('migrations'), static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration', 191)->unique();
            $table->dateTime('applied_at');
        });
    }

    /**
     * Settings, signing keys, the product mirror and the feature catalogue.
     *
     * `products` mirrors the licensing policy configured against a WHMCS
     * product. It exists because that configuration lives in WHMCS's own
     * module-options storage, which is awkward to query and not indexable; the
     * licensing engine reads this copy instead, and {@see \LicenseForge\Licensing\ProductConfig}
     * keeps it in step.
     */
    public static function createCoreTables(): void
    {
        if (!Db::hasTable('settings')) {
            Db::schema()->create(Db::name('settings'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('setting_key', 100)->unique();
                $table->text('setting_value')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Db::hasTable('signing_keys')) {
            Db::schema()->create(Db::name('signing_keys'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('algorithm', 32);
                $table->text('public_key');
                $table->text('private_key_encrypted');
                $table->string('fingerprint', 64)->index();
                $table->boolean('is_active')->default(false)->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });

            self::addSingleActiveSigningKeyConstraint();
        }

        if (!Db::hasTable('products')) {
            Db::schema()->create(Db::name('products'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('whmcs_product_id')->unique();
                $table->string('product_slug', 100)->index();
                $table->string('name', 190);
                $table->boolean('licensing_enabled')->default(true)->index();
                $table->string('key_prefix', 20)->nullable();

                // Nullable throughout: null means "inherit the global setting",
                // which is a different state from a configured zero.
                $table->integer('duration_days')->nullable();
                $table->integer('trial_days')->nullable();
                $table->integer('max_activations')->nullable();
                $table->integer('max_reissues')->nullable();
                $table->integer('grace_days')->nullable();
                $table->integer('validation_interval_hours')->nullable();
                $table->integer('offline_validity_days')->nullable();
                $table->integer('reissue_cooldown_hours')->nullable();

                $table->boolean('lock_domain')->nullable();
                $table->boolean('lock_ip')->nullable();
                $table->boolean('lock_directory')->nullable();
                $table->boolean('lock_machine')->nullable();
                $table->boolean('allow_subdomains')->nullable();
                $table->boolean('allow_local_domains')->nullable();

                $table->string('min_version', 32)->nullable();
                $table->string('max_version', 32)->nullable();
                $table->string('allowed_versions', 191)->nullable();
                $table->string('latest_version', 32)->nullable();

                $table->boolean('auto_activate')->default(true);
                $table->boolean('auto_suspend')->default(true);
                $table->boolean('auto_expire')->default(true);
                $table->boolean('download_protection')->default(true);
                $table->boolean('reissue_self_service')->nullable();
                $table->boolean('reissue_requires_approval')->nullable();

                $table->string('upgrade_behaviour', 32)->default('carry_over');
                $table->string('renewal_behaviour', 32)->default('extend');

                // Defaults to following the service's billing cycle, which is what
                // a vendor expects before configuring anything.
                $table->string('license_term', 20)->default('billing_cycle');

                $table->text('default_features')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Db::hasTable('features')) {
            Db::schema()->create(Db::name('features'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('slug', 64)->unique();
                $table->string('name', 190);
                $table->string('description', 500)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        self::createReleases();
    }

    /**
     * Licences, the installations bound to them, entitlements, and reissue
     * history.
     *
     * `activations` is the table the activation limit is actually enforced
     * against - `licenses.activation_count` is a cached count kept beside it,
     * which is why {@see \LicenseForge\Licensing\LicenseManager::recalculateActivationCount()}
     * exists to bring the two back into line after anything that changes rows
     * directly.
     */
    public static function createLicenseTables(): void
    {
        if (!Db::hasTable('licenses')) {
            Db::schema()->create(Db::name('licenses'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('license_key', 128)->unique();
                $table->string('status', 20)->default('pending')->index();

                $table->unsignedInteger('product_id')->index();
                $table->unsignedInteger('whmcs_product_id')->default(0)->index();
                $table->unsignedInteger('client_id')->default(0)->index();
                $table->unsignedInteger('service_id')->default(0)->index();
                $table->unsignedInteger('order_id')->default(0)->index();

                $table->boolean('is_trial')->default(false)->index();
                $table->boolean('is_lifetime')->default(false);

                $table->dateTime('created_at')->index();
                $table->dateTime('updated_at')->nullable();
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->dateTime('grace_until')->nullable();
                $table->dateTime('last_validated_at')->nullable()->index();
                $table->dateTime('last_success_at')->nullable();
                $table->dateTime('last_failure_at')->nullable();
                $table->string('last_failure_code', 32)->nullable();
                $table->dateTime('suspended_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->dateTime('deleted_at')->nullable()->index();

                /*
                 * The offline hold. A licence's status alone cannot stop an
                 * installation holding a signed offline token, because such an
                 * installation is not asking the server anything - it keeps working
                 * until the token expires. The hold suppresses issuing further
                 * tokens, so a suspension takes effect at the offline horizon
                 * rather than never.
                 */
                $table->dateTime('held_at')->nullable()->index();
                $table->string('held_by', 16)->nullable();
                $table->string('held_reason', 190)->nullable();

                // The bindings recorded from the first installation to supply
                // each, plus any extra domains or ranges staff have permitted.
                $table->string('primary_domain', 190)->nullable()->index();
                $table->string('primary_ip', 45)->nullable()->index();
                $table->string('primary_directory', 500)->nullable();
                $table->string('primary_machine_id', 128)->nullable()->index();
                $table->text('allowed_domains')->nullable();
                $table->text('allowed_ips')->nullable();

                // Copied from the product at issue, so a licence keeps the terms
                // it was sold under.
                $table->unsignedInteger('max_activations')->default(1);
                $table->unsignedInteger('activation_count')->default(0);
                $table->unsignedInteger('reissue_count')->default(0);
                $table->unsignedInteger('max_reissues')->default(3);
                $table->dateTime('last_reissued_at')->nullable();

                $table->string('min_version', 32)->nullable();
                $table->string('max_version', 32)->nullable();
                $table->string('allowed_versions', 191)->nullable();
                $table->string('current_version', 32)->nullable();

                $table->unsignedInteger('validation_count')->default(0);
                $table->unsignedInteger('failed_validation_count')->default(0);
                $table->boolean('flagged')->default(false)->index();

                $table->text('notes')->nullable();
                $table->text('admin_notes')->nullable();

                // The two composites the admin listing actually filters on.
                $table->index(['client_id', 'status'], 'lfg_lic_client_status');
                $table->index(['product_id', 'status'], 'lfg_lic_product_status');
            });
        }

        if (!Db::hasTable('activations')) {
            Db::schema()->create(Db::name('activations'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->index();
                $table->string('installation_id', 128)->index();
                $table->string('status', 20)->default('active')->index();

                $table->string('domain', 190)->nullable()->index();
                $table->string('ip_address', 45)->nullable()->index();
                $table->string('directory', 500)->nullable();
                $table->string('machine_id', 128)->nullable()->index();
                $table->string('version', 32)->nullable();

                $table->string('last_domain', 190)->nullable();
                $table->string('last_ip', 45)->nullable();
                $table->text('metadata')->nullable();

                // The per-installation credential, encrypted under the master key.
                $table->text('install_secret')->nullable();
                $table->dateTime('install_secret_at')->nullable();

                // The stronger alternative: a public key the client registered,
                // so the server holds nothing that could impersonate it.
                $table->text('install_public_key')->nullable();
                $table->string('install_key_algorithm', 32)->nullable();
                $table->dateTime('install_key_at')->nullable();

                $table->dateTime('first_activated_at');
                $table->dateTime('last_validated_at')->nullable()->index();
                $table->dateTime('deactivated_at')->nullable();
                $table->string('deactivated_reason', 190)->nullable();
                $table->unsignedInteger('validation_count')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                // One row per installation per licence, which is why a released
                // row is re-used rather than a second one inserted.
                $table->unique(['license_id', 'installation_id'], 'lfg_act_unique_install');

                $table->index(['license_id', 'status'], 'lfg_act_license_status');
            });
        }

        if (!Db::hasTable('license_features')) {
            Db::schema()->create(Db::name('license_features'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->index();
                $table->string('feature_slug', 64)->index();
                $table->boolean('enabled')->default(true);
                $table->dateTime('expires_at')->nullable();
                $table->text('value')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['license_id', 'feature_slug'], 'lfg_licfeat_unique');
            });
        }

        if (!Db::hasTable('reissues')) {
            Db::schema()->create(Db::name('reissues'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->index();
                $table->unsignedInteger('old_activation_id')->nullable();
                $table->unsignedInteger('new_activation_id')->nullable();

                // Keys are stored masked; the hashes beside them are what
                // identifies a key exactly without the history holding one.
                $table->string('old_key', 128)->nullable();
                $table->string('new_key', 128)->nullable();
                $table->string('old_key_hash', 64)->nullable()->index();
                $table->string('new_key_hash', 64)->nullable()->index();
                $table->string('old_domain', 190)->nullable();
                $table->string('new_domain', 190)->nullable();

                $table->string('status', 20)->default('completed')->index();
                $table->dateTime('claimed_at')->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('initiated_by', 20)->default('client');
                $table->unsignedInteger('initiator_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->dateTime('created_at')->index();
                $table->dateTime('resolved_at')->nullable();
            });
        }
    }

    /**
     * The record of what happened: validations, audit log, abuse events, and the
     * notification ledger.
     *
     * `validations` is written on every API call and is the highest-volume table
     * the module owns. Its indexes are chosen for the two questions actually
     * asked of it - recent activity for one licence, and recent activity from
     * one IP - rather than for general querying.
     */
    public static function createActivityTables(): void
    {
        if (!Db::hasTable('validations')) {
            Db::schema()->create(Db::name('validations'), static function (Blueprint $table): void {
                /*
                 * Indexed sparingly, on purpose. Every check-in writes a row here,
                 * so an index that no query uses is a cost paid on the hot path for
                 * nothing - and they accumulate invisibly. An earlier version
                 * indexed almost every column, which on a 213,000-row installation
                 * produced 87MB of index against 41MB of data.
                 *
                 * The rule applied: index a column only where something filters or
                 * groups by it, and never single-column where a composite below
                 * already leads with it.
                 */
                $table->increments('id');
                $table->unsignedInteger('license_id')->nullable();
                $table->unsignedInteger('activation_id')->nullable()->index();
                $table->string('license_key_hash', 64);
                $table->string('endpoint', 32);
                $table->boolean('success')->default(false);
                $table->string('error_code', 32)->nullable();
                $table->string('domain', 190)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('machine_id', 128)->nullable();
                $table->string('version', 32)->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->dateTime('created_at')->index();

                $table->index(['ip_address', 'created_at'], 'lfg_val_ip_created');
                $table->index(['license_id', 'created_at'], 'lfg_val_license_created');
            });
        }

        if (!Db::hasTable('audit_logs')) {
            Db::schema()->create(Db::name('audit_logs'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->string('result', 16)->default('success')->index();
                $table->string('actor_type', 16)->default('system')->index();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 190)->nullable();
                $table->string('ip_address', 45)->nullable()->index();
                $table->text('metadata')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        if (!Db::hasTable('abuse_events')) {
            Db::schema()->create(Db::name('abuse_events'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->nullable()->index();
                $table->string('signal', 48)->index();
                $table->string('severity', 16)->default('low')->index();
                $table->string('summary', 500);
                $table->text('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->boolean('resolved')->default(false)->index();
                $table->unsignedInteger('resolved_by')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        if (!Db::hasTable('notifications')) {
            Db::schema()->create(Db::name('notifications'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('license_id')->index();
                $table->string('notification_key', 100)->index();
                $table->dateTime('sent_at');

                // The unique index is the send-once mechanism: the insert
                // succeeds for exactly one caller. See Notifier::claim().
                $table->unique(['license_id', 'notification_key'], 'lfg_notify_unique');
            });
        }
    }

    /**
     * API credentials, the replay-protection nonces, and the rate-limit counters.
     *
     * `api_nonces` and `rate_limits` are working state rather than records: both
     * are pruned by the maintenance run, and losing either costs replay
     * protection and throttling for one window, not any licensing data.
     */
    public static function createSecurityTables(): void
    {
        if (!Db::hasTable('api_credentials')) {
            Db::schema()->create(Db::name('api_credentials'), static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 190);
                $table->string('api_key', 64)->unique();

                // Both copies of the secret; see Api\Credentials for why each is
                // needed.
                $table->string('secret_hash', 64);
                $table->text('secret_encrypted')->nullable();
                $table->string('scopes', 190)->default('activate,check');
                $table->string('allowed_ips', 500)->nullable();

                $table->string('allowed_products', 500)->nullable();
                $table->boolean('allow_all_products')->default(false);
                $table->unsignedInteger('rate_limit')->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->dateTime('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->unsignedInteger('request_count')->default(0);
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Db::hasTable('api_nonces')) {
            Db::schema()->create(Db::name('api_nonces'), static function (Blueprint $table): void {
                $table->increments('id');
                // The unique index is the replay check itself, not a lookup.
                $table->string('nonce_hash', 64)->unique();
                $table->unsignedInteger('credential_id')->index();
                $table->unsignedInteger('expires_at')->index();
            });
        }

        if (!Db::hasTable('rate_limits')) {
            Db::schema()->create(Db::name('rate_limits'), static function (Blueprint $table): void {
                $table->increments('id');
                // The unique bucket is what makes the counter's upsert atomic.
                $table->string('bucket', 64)->unique();
                $table->string('scope', 64)->index();
                $table->unsignedInteger('hits')->default(0);
                $table->unsignedInteger('window_start')->default(0);
                $table->unsignedInteger('expires_at')->index();
            });
        }
    }

    /**
     * The registry of downloadable release files.
     *
     * Holds a path relative to the configured release directory, never an
     * absolute one, so a release cannot be pointed outside that directory by
     * editing a row. `size_bytes` is checked on every download and `sha256` on
     * demand - see {@see \LicenseForge\Licensing\ReleaseService}.
     */
    private static function createReleases(): void
    {
        if (Db::hasTable('releases')) {
            return;
        }

        Db::schema()->create(Db::name('releases'), static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id')->index();
            $table->string('version', 32)->nullable();
            $table->string('label', 190);

            $table->string('file_path', 500);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('download_count')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Make "one active signing key" a database rule rather than a convention.
     *
     * {@see \LicenseForge\Support\Crypto::activateSigningKey()} already gets this
     * right: it clears every row and sets one, in a transaction whose blanket
     * UPDATE holds locks on all rows until commit, so concurrent activations
     * serialise rather than interleave. The application is not the weak point.
     *
     * What this adds is a floor under everything that is not that method - a
     * support engineer running UPDATE by hand, a restored partial backup, a
     * future code path that sets is_active without clearing the others. Two
     * active keys do not enable forgery, since both were generated here, but
     * they make rotation ambiguous: signing and verification could disagree
     * about which key is current, and revoking "the old one" stops being well
     * defined.
     *
     * A partial unique index would express this directly; MySQL has none. A
     * generated column that is 1 only when the row is active and NULL otherwise
     * achieves the same, because unique indexes permit repeated NULLs.
     *
     * Applied to the table immediately after it is created, so no row can
     * already violate it. Failure is logged rather than thrown: MySQL before 5.7
     * has no generated columns, and losing the database-level floor is not a
     * reason to refuse the install when the application-level guarantee still
     * holds.
     */
    private static function addSingleActiveSigningKeyConstraint(): void
    {
        try {
            $table = Db::name('signing_keys');
            Db::connection()->statement(
                "ALTER TABLE `{$table}` "
                . 'ADD COLUMN `active_marker` TINYINT GENERATED ALWAYS AS (IF(`is_active` = 1, 1, NULL)) VIRTUAL, '
                . 'ADD UNIQUE KEY `lfg_one_active_signing_key` (`active_marker`)'
            );
        } catch (\Throwable $e) {
            error_log(
                '[LicenseForge] could not add the single-active-signing-key constraint: '
                . $e->getMessage()
                . ' - the application-level guarantee in Crypto::activateSigningKey() still applies.'
            );
        }
    }

    /**
     * Insert the starter feature catalogue.
     *
     * Examples to be edited or deleted, not a fixed vocabulary - entitlements
     * are whatever a vendor's product actually offers. Each is inserted only if
     * its slug is absent, so re-running adds nothing and a deleted example stays
     * deleted.
     */
    public static function seedDefaults(): void
    {
        $now = Db::now();

        $features = [
            ['slug' => 'premium_reports', 'name' => 'Premium Reports'],
            ['slug' => 'api_access',      'name' => 'API Access'],
            ['slug' => 'white_label',     'name' => 'White Label'],
            ['slug' => 'multi_user',      'name' => 'Multi User'],
            ['slug' => 'advanced_import', 'name' => 'Advanced Import'],
            ['slug' => 'priority_support', 'name' => 'Priority Support'],
        ];

        foreach ($features as $feature) {
            if (!Db::table('features')->where('slug', $feature['slug'])->exists()) {
                Db::table('features')->insert($feature + [
                    'description' => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    /**
     * Drop every table the module owns, including the migration ledger.
     *
     * Destructive and irreversible: this discards every licence, activation and
     * audit record. Deliberately not called by deactivation, which preserves
     * everything so the module can be switched off and back on - removal is a
     * separate, explicit action.
     *
     * Ordered so that a table is dropped before anything it references, and
     * includes two tables this version never creates, so an installation that
     * predates 1.0 is still cleaned up completely.
     */
    public static function dropAll(): void
    {
        /*
         * Every table the module has ever created, children before parents.
         *
         * `downloads` and `download_tokens` belonged to a token-based download
         * scheme replaced before 1.0 by checking the licence at the moment the
         * file is served - see ReleaseService. Nothing creates them now; they
         * stay listed so a pre-1.0 installation is cleaned out completely.
         *
         * Keep this in step with the create* methods. A table created but not
         * listed here survives a removal that told the administrator everything
         * was gone, and is then adopted by the next install - whose migration
         * skips creation because the table already exists, leaving rows that
         * reference ids belonging to a database that no longer exists.
         * `releases` was missing for exactly that reason.
         */
        $tables = [
            'api_nonces', 'rate_limits', 'api_credentials', 'notifications', 'abuse_events',
            'audit_logs', 'download_tokens', 'downloads', 'releases', 'validations', 'reissues',
            'license_features', 'activations', 'licenses', 'features', 'products',
            'signing_keys', 'settings', 'migrations',
        ];

        foreach ($tables as $table) {
            Db::schema()->dropIfExists(Db::name($table));
        }
    }
}
