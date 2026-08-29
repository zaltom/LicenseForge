<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * The module's global settings.
 *
 * Every setting has a default declared in {@see defaults()}, and stored values
 * are layered over those. Three consequences follow:
 *
 *   - A fresh installation is fully configured before anyone opens the settings
 *     page, so nothing depends on an administrator having visited it.
 *   - A setting introduced by an upgrade takes its default automatically, with
 *     no migration and no window where it reads as empty.
 *   - {@see defaults()} is the authoritative list of what a setting is. The
 *     admin form iterates it rather than the posted fields, so an unrecognised
 *     key cannot introduce a setting.
 *
 * Values are stored as strings, because the settings table holds text and a
 * form posts text. Callers should read through {@see int()} or {@see bool()}
 * rather than casting, so that "unset" and "zero" stay distinguishable.
 *
 * The whole table is read once per request and cached, since a licensing
 * decision consults many settings and they cannot change mid-request.
 */
final class Settings
{
    /**
     * @var array<string,string>|null Defaults merged with stored values, or
     *   null before the first read. Null rather than an empty array so an
     *   installation with no stored settings is not mistaken for one that has
     *   not loaded yet.
     */
    private static ?array $cache = null;

    /**
     * Every setting the module recognises, with its default value.
     *
     * More than a fallback list: this defines the settings that exist. The
     * admin form iterates these keys, so a setting absent here cannot be saved,
     * and one added here appears without any other change.
     *
     * Values are strings throughout, matching how they are stored and posted.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [

            // Module identity and master switch.
            'license_server_url'        => '',
            'module_enabled'            => '1',

            // Shape of a generated licence key.
            'key_prefix'                => 'LFG',
            'key_segments'              => '4',
            'key_segment_length'        => '4',
            'key_separator'             => '-',
            'key_uppercase'             => '1',
            'key_alphabet'              => 'crockford',

            // Licence lifetime defaults, applied when a product does not override.
            'default_duration_days'     => '365',
            'default_trial_days'        => '14',
            'default_max_activations'   => '1',
            'default_max_reissues'      => '3',
            'default_grace_days'        => '7',
            'validation_interval_hours' => '24',
            'offline_validity_days'     => '7',

            // Which installation attributes an activation is bound to.
            'lock_domain'               => '1',
            'lock_ip'                   => '0',
            'lock_directory'            => '0',
            'lock_machine'              => '0',
            'allow_subdomains'          => '1',
            'allow_www_normalisation'   => '1',
            'allow_local_domains'       => '1',

            // Customer-initiated key replacement.
            'reissue_self_service'      => '1',
            'reissue_cooldown_hours'    => '24',
            'reissue_requires_approval' => '0',

            // WHMCS service status to licence status. See ModuleOptions.
            'map_active'                => 'active',
            'map_pending'               => 'pending',
            'map_suspended'             => 'suspended',
            'map_terminated'            => 'terminated',
            'map_cancelled'             => 'revoked',
            'map_fraud'                 => 'revoked',

            'require_api_auth'          => '1',

            // Request authenticity: signing, replay window, and proxy trust.
            'require_install_proof'     => '0',
            'signature_algorithm'       => 'auto',
            'request_max_skew_seconds'  => '300',
            'trusted_proxies'           => '',
            'trusted_proxy_header'      => 'X-Forwarded-For',

            // Rate limits, in requests per window. 0 disables a limiter.
            'rate_window_seconds'       => '60',
            'rate_limit_validate_ip'    => '120',
            'rate_limit_activate_ip'    => '20',
            'rate_limit_activate_key'   => '10',
            'rate_limit_failed_ip'      => '30',
            'rate_limit_reissue_client' => '5',

            // Whether a limiter that cannot read its counter denies or allows.
            'rate_limit_fail_closed'    => '0',

            // Thresholds that raise an abuse flag within the window.
            'abuse_failed_threshold'    => '15',
            'abuse_domain_changes'      => '5',
            'abuse_ip_changes'          => '10',
            'abuse_install_ip_flips'    => '4',
            'abuse_window_hours'        => '24',
            'abuse_auto_suspend'        => '0',

            'show_key_in_service_list'  => '1',

            'download_protection'       => '1',

            'release_dir'               => '',

            // Log retention, in days.
            'log_validations'           => '1',
            'validation_log_retention'  => '90',
            'audit_log_retention'       => '730',

            // Notifications. The email values name WHMCS email templates.
            'notify_enabled'            => '1',
            'notify_expiry_days'        => '30,14,7,1',
            'notify_max_per_run'        => '200',
            'email_license_created'     => 'LicenseForge License Created',
            'email_license_activated'   => 'LicenseForge License Activated',
            'email_license_expiring'    => 'LicenseForge License Expiring',
            'email_license_expired'     => 'LicenseForge License Expired',
            'email_license_suspended'   => 'LicenseForge License Suspended',
            'email_license_reissued'    => 'LicenseForge License Reissued',
            'email_activation_limit'    => 'LicenseForge Activation Limit Reached',
            'email_suspicious_activity' => 'LicenseForge Suspicious Activity',
        ];
    }

    /**
     * Every setting, stored values layered over the defaults.
     *
     * Read once and cached for the request. A licensing decision consults a
     * dozen settings and runs on every API call, so querying per setting would
     * turn one request into many.
     *
     * A failure to read the table falls back to the defaults rather than
     * raising, which keeps the module working during a partial upgrade - on the
     * shipped defaults, which are the safe values - instead of failing every
     * request until the schema catches up.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = [];
            try {
                foreach (Db::table('settings')->get() as $row) {
                    $stored[(string) $row->setting_key] = (string) $row->setting_value;
                }
            } catch (\Throwable $e) {
                $stored = [];
            }
            self::$cache = array_merge(self::defaults(), $stored);
        }

        return self::$cache;
    }

    /**
     * A raw setting value.
     *
     * The caller's default applies only to a key that does not exist at all,
     * not to one that exists and is empty - an empty setting is a configured
     * value and is returned as such. {@see int()} and {@see bool()} treat empty
     * differently, on purpose.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * A setting as an integer.
     *
     * An empty value yields the caller's default rather than zero. The
     * distinction is load-bearing: for limits and windows zero usually means
     * "no limit", so casting an unset setting to zero would silently disable a
     * control rather than fall back to its intended value.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * A setting as a boolean.
     *
     * Empty falls back to the default for the same reason as {@see int()}: an
     * unset setting must not read as false and quietly switch off a protection.
     *
     * Accepts `1`, `true`, `yes` and `on`, covering what forms and hand-edited
     * rows actually contain.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Store one setting.
     *
     * Booleans are normalised to '1'/'0' rather than PHP's string cast, which
     * would write an empty string for false - indistinguishable from unset, and
     * therefore read back as the default rather than as off.
     *
     * The cache is discarded afterwards so a later read in the same request
     * sees the new value rather than the one it replaced.
     *
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        $exists = Db::table('settings')->where('setting_key', $key)->exists();
        if ($exists) {
            Db::table('settings')->where('setting_key', $key)->update([
                'setting_value' => $value,
                'updated_at'    => Db::now(),
            ]);
        } else {
            Db::table('settings')->insert([
                'setting_key'   => $key,
                'setting_value' => $value,
                'created_at'    => Db::now(),
                'updated_at'    => Db::now(),
            ]);
        }

        self::$cache = null;
    }

    /**
     * Store several settings.
     *
     * Deliberately not transactional: the settings form validates and skips
     * individual values, so a rejected one should leave the rest saved rather
     * than discarding the administrator's whole submission.
     *
     * @param array<string,mixed> $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * Discard the cached settings.
     *
     * For cases where the table changed outside {@see set()} - a migration, or
     * a test fixture - and the request must not go on using what it read first.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * The proxies whose forwarded headers may be believed.
     *
     * Decides whether `X-Forwarded-For` and `X-Forwarded-Proto` are honoured at
     * all. Empty - the default - means they never are, so a client cannot claim
     * another address or claim TLS over a plaintext connection. See
     * {@see Net::clientIp()} and {@see Net::isSecure()}.
     *
     * Entries may be addresses or CIDR ranges, separated by whitespace or
     * commas, so the value can be pasted from a load balancer's configuration
     * in whatever form it appears there.
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        $raw = (string) self::get('trusted_proxies', '');

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }
}
