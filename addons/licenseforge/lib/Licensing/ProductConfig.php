<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Db;
use LicenseForge\Support\Input;
use LicenseForge\Support\Settings;

/**
 * A product's licensing policy: how it is stored, kept current, and resolved.
 *
 * The policy is configured by an administrator against a WHMCS product, and
 * WHMCS keeps that in its own module-options storage - a key/value table that
 * is awkward to query and impossible to index. The licensing engine needs it on
 * every request, so this class maintains a mirror in the module's own
 * `products` table and keeps the two in step.
 *
 * Three layers decide any given setting, narrowest first:
 *
 *   licence   Overrides recorded against one licence, for a customer given
 *             different terms from everyone else.
 *   product   The product's own configuration.
 *   global    The module setting, used wherever the product says "inherit".
 *
 * That is what {@see policy()} and {@see policyForLicense()} resolve, and why
 * the mirror stores nullable columns: null means "inherit", which is different
 * from a stored zero.
 *
 * Reads are memoised per request. The policy is consulted repeatedly while
 * evaluating a single licensing decision, and it cannot change mid-request.
 */
final class ProductConfig
{
    /** @var array<int,object|null> Product rows by id, for this request only. */
    private static array $cache = [];

    /**
     * @var array<int,true> WHMCS product ids already synced this request.
     *
     * Keeps the mirror refresh to once per product per request, however many
     * times a lookup happens to ask for it.
     */
    private static array $synced = [];

    /**
     * A mirrored product by the module's own id.
     *
     * Caches the miss as well as the hit, so a licence whose product row has
     * been removed does not re-query on every lookup.
     *
     * @return object|null
     */
    public static function find(int $productId)
    {
        if (!array_key_exists($productId, self::$cache)) {
            self::$cache[$productId] = Db::table('products')->where('id', $productId)->first();
        }

        return self::$cache[$productId];
    }

    /**
     * A mirrored product by its WHMCS product id, refreshing it first.
     *
     * The refresh is what keeps the mirror honest: an administrator editing a
     * product changes WHMCS's copy, and nothing tells this module about it.
     * Syncing on first access each request means a policy change takes effect
     * immediately rather than at the next cron.
     *
     * @return object|null
     */
    public static function findByWhmcsProduct(int $whmcsProductId)
    {
        if ($whmcsProductId > 0 && !isset(self::$synced[$whmcsProductId])) {
            self::sync($whmcsProductId);
        }

        return Db::table('products')->where('whmcs_product_id', $whmcsProductId)->first();
    }

    /**
     * Refresh one product's mirror from its WHMCS configuration.
     *
     * A product whose module options can no longer be read is marked as not
     * licensed rather than deleted: existing licences still reference it and
     * must remain viewable. What stops is the issuing of new ones.
     *
     * The row cache is cleared on any write, since the values just changed
     * underneath it.
     *
     * @param  array<string,mixed>|null $values Pre-read options, to avoid a
     *   second read where the caller already has them.
     * @return object|null The refreshed row, or null when the product is no
     *   longer licensed.
     */
    public static function sync(int $whmcsProductId, ?array $values = null)
    {
        if ($whmcsProductId <= 0) {
            return null;
        }

        self::$synced[$whmcsProductId] = true;
        $values = $values ?? ModuleOptions::readForProduct($whmcsProductId);

        if ($values === null) {
            Db::table('products')
                ->where('whmcs_product_id', $whmcsProductId)
                ->update(['licensing_enabled' => 0, 'updated_at' => Db::now()]);
            self::$cache = [];

            return null;
        }

        $id = self::save($whmcsProductId, ModuleOptions::toProductData($whmcsProductId, $values));

        return self::find($id);
    }

    /**
     * A mirrored product by its slug, syncing everything if it is not found.
     *
     * The fallback matters for a product configured but never yet touched by a
     * request: its mirror does not exist, so a client naming it by slug would be
     * refused for a product that is perfectly well configured. Syncing on the
     * miss turns that into a one-off cost rather than a failure.
     *
     * @return object|null
     */
    public static function findBySlug(string $slug)
    {
        $product = Db::table('products')->where('product_slug', $slug)->first();
        if ($product !== null) {
            return $product;
        }

        self::syncAll();

        return Db::table('products')->where('product_slug', $slug)->first();
    }

    /**
     * Refresh every licensed product's mirror.
     *
     * Guarded so it runs at most once per request whatever calls it: it reads
     * WHMCS's module options for every product, which is far too expensive to
     * repeat.
     */
    public static function syncAll(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        foreach (ModuleOptions::licensedProducts() as $product) {
            self::sync($product['id']);
        }
    }

    /**
     * Find a product from whatever a client called it.
     *
     * Numeric identifiers are tried as a WHMCS product id first, then everything
     * falls through to the slug. Both forms are accepted because integrations
     * were written against both, and a numeric-looking slug still resolves
     * through the fallback rather than being lost to the first branch.
     *
     * @return object|null
     */
    public static function resolve(string $identifier)
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        if (ctype_digit($identifier)) {
            $product = self::findByWhmcsProduct((int) $identifier);
            if ($product !== null) {
                return $product;
            }
        }

        return self::findBySlug($identifier);
    }

    /** Does this WHMCS product issue licences? */
    public static function isLicensed(int $whmcsProductId): bool
    {
        $product = self::findByWhmcsProduct($whmcsProductId);

        return $product !== null && (bool) $product->licensing_enabled;
    }

    /**
     * Resolve a product's effective policy, filling gaps from the global settings.
     *
     * Every value the licensing engine consults comes from here already resolved,
     * so no caller has to know which layer supplied it or repeat the inheritance
     * rule.
     *
     * Null and empty string both mean "inherit", which is why the check is not a
     * simple null test: WHMCS returns an unset option as an empty string, and
     * treating that as a configured zero would silently disable activation
     * limits and grace periods on every product that had not overridden them.
     *
     * A missing product resolves to the global defaults throughout rather than
     * failing, so a licence whose product row has gone still evaluates.
     *
     * @param  object|null $product
     * @return array<string,mixed>
     */
    public static function policy($product): array
    {
        $pick = static function ($value, string $settingKey, string $type) {
            if ($value === null || $value === '') {
                $raw = Settings::get($settingKey);

                return $type === 'bool'
                    ? in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true)
                    : (int) $raw;
            }

            return $type === 'bool' ? (bool) $value : (int) $value;
        };

        return [
            'license_term'              => self::term($product),
            'duration_days'             => $pick($product->duration_days ?? null, 'default_duration_days', 'int'),
            'trial_days'                => $pick($product->trial_days ?? null, 'default_trial_days', 'int'),
            'max_activations'           => $pick($product->max_activations ?? null, 'default_max_activations', 'int'),
            'max_reissues'              => $pick($product->max_reissues ?? null, 'default_max_reissues', 'int'),
            'grace_days'                => $pick($product->grace_days ?? null, 'default_grace_days', 'int'),
            'validation_interval_hours' => $pick($product->validation_interval_hours ?? null, 'validation_interval_hours', 'int'),
            'offline_validity_days'     => $pick($product->offline_validity_days ?? null, 'offline_validity_days', 'int'),
            'reissue_cooldown_hours'    => $pick($product->reissue_cooldown_hours ?? null, 'reissue_cooldown_hours', 'int'),

            'lock_domain'               => $pick($product->lock_domain ?? null, 'lock_domain', 'bool'),
            'lock_ip'                   => $pick($product->lock_ip ?? null, 'lock_ip', 'bool'),
            'lock_directory'            => $pick($product->lock_directory ?? null, 'lock_directory', 'bool'),
            'lock_machine'              => $pick($product->lock_machine ?? null, 'lock_machine', 'bool'),
            'allow_subdomains'          => $pick($product->allow_subdomains ?? null, 'allow_subdomains', 'bool'),
            'allow_local_domains'       => $pick($product->allow_local_domains ?? null, 'allow_local_domains', 'bool'),
            'reissue_self_service'      => $pick($product->reissue_self_service ?? null, 'reissue_self_service', 'bool'),
            'reissue_requires_approval' => $pick($product->reissue_requires_approval ?? null, 'reissue_requires_approval', 'bool'),

            // Not inheritable: an empty constraint means no restriction, which
            // is a meaningful answer rather than an unset one.
            'min_version'               => (string) ($product->min_version ?? ''),
            'max_version'               => (string) ($product->max_version ?? ''),
            'allowed_versions'          => (string) ($product->allowed_versions ?? ''),
            'latest_version'            => (string) ($product->latest_version ?? ''),

            'auto_activate'             => (bool) ($product->auto_activate ?? true),
            'auto_suspend'              => (bool) ($product->auto_suspend ?? true),
            'auto_expire'               => (bool) ($product->auto_expire ?? true),
            'download_protection'       => (bool) ($product->download_protection ?? Settings::bool('download_protection', true)),
            'upgrade_behaviour'         => (string) ($product->upgrade_behaviour ?? 'carry_over'),
            'renewal_behaviour'         => (string) ($product->renewal_behaviour ?? 'extend'),
            'key_prefix'                => (string) ($product->key_prefix ?? ''),
            'default_features'          => self::decodeFeatures($product->default_features ?? null),
        ];
    }

    /**
     * How long a licence for this product lasts.
     *
     * One of `billing_cycle`, `lifetime` or `fixed_days`. Anything unrecognised
     * falls back to the billing cycle, which is the behaviour that predates the
     * setting and the safe reading of a corrupted value.
     */
    public static function term($product): string
    {
        $term = (string) ($product->license_term ?? 'billing_cycle');

        return in_array($term, ['billing_cycle', 'lifetime', 'fixed_days'], true) ? $term : 'billing_cycle';
    }

    /**
     * Read a stored list of default entitlements.
     *
     * Accepts an array, a JSON array or a comma-separated string, because the
     * column has held all three across versions. An installation upgraded from
     * an early release still has comma-separated values here, and reading them
     * as one malformed entry would quietly grant a feature nobody defined.
     *
     * @param  mixed $raw
     * @return list<string>
     */
    public static function decodeFeatures($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return Input::toList($raw);
    }

    /**
     * The product policy with this licence's own overrides applied.
     *
     * The narrowest layer. Activation and reissue limits always come from the
     * licence, because provisioning copies the product's values onto it at
     * issue - so a licence keeps the terms it was sold under even if the
     * product's are later changed.
     *
     * Version constraints override only when set on the licence, since the empty
     * case means "no opinion" rather than "no restriction" and must fall through
     * to the product.
     *
     * @return array<string,mixed>
     */
    public static function policyForLicense(object $license): array
    {
        $policy = self::policy(self::find((int) $license->product_id));

        $policy['max_activations'] = (int) $license->max_activations;
        $policy['max_reissues']    = (int) $license->max_reissues;
        if (($license->min_version ?? '') !== '') {
            $policy['min_version'] = (string) $license->min_version;
        }
        if (($license->max_version ?? '') !== '') {
            $policy['max_version'] = (string) $license->max_version;
        }
        if (($license->allowed_versions ?? '') !== '') {
            $policy['allowed_versions'] = (string) $license->allowed_versions;
        }

        return $policy;
    }

    /**
     * Write a product's mirror row, creating it if needed.
     *
     * Every enumerated field is validated against its permitted values rather
     * than stored as given, so a malformed option cannot put the mirror into a
     * state the licensing engine does not understand.
     *
     * Nullable fields pass through {@see nullableInt()} and
     * {@see nullableBool()}, which is what preserves the distinction between
     * "inherit" and a configured zero. Collapsing the two here would break the
     * inheritance in {@see policy()} for every product using defaults.
     *
     * @param  array<string,mixed> $data
     * @return int The mirror row's id.
     */
    public static function save(int $whmcsProductId, array $data): int
    {
        $now      = Db::now();
        $existing = self::findByWhmcsProduct($whmcsProductId);

        $payload = [
            'whmcs_product_id'          => $whmcsProductId,
            'product_slug'              => $data['product_slug'] ?? ('product-' . $whmcsProductId),
            'name'                      => $data['name'] ?? ('Product #' . $whmcsProductId),
            'licensing_enabled'         => !empty($data['licensing_enabled']) ? 1 : 0,
            'key_prefix'                => $data['key_prefix'] ?? null,
            'license_term'              => in_array($data['license_term'] ?? '', ['billing_cycle', 'lifetime', 'fixed_days'], true)
                ? $data['license_term']
                : 'billing_cycle',
            'duration_days'             => self::nullableInt($data['duration_days'] ?? null),
            'trial_days'                => self::nullableInt($data['trial_days'] ?? null),
            'max_activations'           => self::nullableInt($data['max_activations'] ?? null),
            'max_reissues'              => self::nullableInt($data['max_reissues'] ?? null),
            'grace_days'                => self::nullableInt($data['grace_days'] ?? null),
            'validation_interval_hours' => self::nullableInt($data['validation_interval_hours'] ?? null),
            'offline_validity_days'     => self::nullableInt($data['offline_validity_days'] ?? null),
            'reissue_cooldown_hours'    => self::nullableInt($data['reissue_cooldown_hours'] ?? null),
            'lock_domain'               => self::nullableBool($data['lock_domain'] ?? null),
            'lock_ip'                   => self::nullableBool($data['lock_ip'] ?? null),
            'lock_directory'            => self::nullableBool($data['lock_directory'] ?? null),
            'lock_machine'              => self::nullableBool($data['lock_machine'] ?? null),
            'allow_subdomains'          => self::nullableBool($data['allow_subdomains'] ?? null),
            'allow_local_domains'       => self::nullableBool($data['allow_local_domains'] ?? null),
            'reissue_self_service'      => self::nullableBool($data['reissue_self_service'] ?? null),
            'reissue_requires_approval' => self::nullableBool($data['reissue_requires_approval'] ?? null),
            'min_version'               => $data['min_version'] ?? null,
            'max_version'               => $data['max_version'] ?? null,
            'allowed_versions'          => $data['allowed_versions'] ?? null,
            'latest_version'            => $data['latest_version'] ?? null,
            'auto_activate'             => !empty($data['auto_activate']) ? 1 : 0,
            'auto_suspend'              => !empty($data['auto_suspend']) ? 1 : 0,
            'auto_expire'               => !empty($data['auto_expire']) ? 1 : 0,
            'download_protection'       => !empty($data['download_protection']) ? 1 : 0,

            'upgrade_behaviour'         => in_array($data['upgrade_behaviour'] ?? '', ['carry_over', 'extend', 'new_license'], true)
                ? $data['upgrade_behaviour']
                : 'carry_over',
            'renewal_behaviour'         => in_array($data['renewal_behaviour'] ?? '', ['extend', 'reset', 'none'], true)
                ? $data['renewal_behaviour']
                : 'extend',
            'default_features'          => json_encode(array_values((array) ($data['default_features'] ?? []))),
            'notes'                     => $data['notes'] ?? null,
            'updated_at'                => $now,
        ];

        if ($existing !== null) {
            Db::table('products')->where('id', $existing->id)->update($payload);
            self::$cache = [];

            return (int) $existing->id;
        }

        $payload['created_at'] = $now;
        $id = (int) Db::table('products')->insertGetId($payload);
        self::$cache = [];

        return $id;
    }

    /**
     * An integer setting, or null where the product inherits.
     *
     * @param mixed $value
     */
    private static function nullableInt($value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * A boolean setting as 1, 0 or null where the product inherits.
     *
     * Stored as an integer rather than a boolean because the column is nullable
     * and has three meaningful states, which a PHP bool cannot carry.
     *
     * The literal 'inherit' is recognised because that is the value the WHMCS
     * product form submits for an unset dropdown; without it, choosing "inherit"
     * would store a false and silently disable the setting.
     *
     * @param mixed $value
     */
    private static function nullableBool($value): ?int
    {
        if ($value === null || $value === '' || $value === 'inherit') {
            return null;
        }

        return in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
    }
}
