<?php

declare(strict_types=1);

namespace LicenseForge\Api;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Crypto;
use LicenseForge\Support\Db;
use LicenseForge\Support\Input;

/**
 * API credential lifecycle: issue, look up, rotate, restrict and retire.
 *
 * A credential is the pair a client authenticates with - a public key naming it
 * and a secret proving it. The secret never travels on the wire; it is the HMAC
 * key for the request signature, so possession is proved without disclosure.
 *
 * The secret is stored twice, deliberately, because the two copies answer
 * different questions:
 *
 *   secret_hash        A one-way HMAC, for constant-time comparison.
 *   secret_encrypted   Reversible under the master key, because signature
 *                      verification must recompute an HMAC from the plaintext
 *                      and a hash cannot be used for that.
 *
 * The encrypted copy is why the master key matters as much as it does: a
 * database dump alone does not yield it, since the key is derived from an
 * on-disk secret combined with the WHMCS instance hash. See {@see Crypto}.
 *
 * Scoping matters more than it appears. Authentication only proves the caller
 * holds a valid credential, never that it was entitled to the licence in front
 * of it - so a credential shipped inside one product could otherwise act on
 * every product on the server, with the licence key as the only thing between
 * them. `allowed_products` closes that; `allow_all_products` is the explicit
 * opt-out for a server-side integration that genuinely needs it.
 */
final class Credentials
{
    /**
     * Every scope a credential may hold.
     *
     * `activate` and `check` are the pair a shipped product needs. `admin` is
     * far broader and belongs only to a server-side integration - a build going
     * out to customers should never carry it, which is why the credentials page
     * flags any credential that does.
     */
    public const SCOPES = ['activate', 'check', 'admin'];

    /**
     * Issue a credential and return its key and secret.
     *
     * This is the only moment the plaintext secret exists outside the encrypted
     * column, so the caller must show it to the administrator now; there is no
     * second chance short of {@see rotate()}.
     *
     * Requested scopes are intersected with the known set rather than trusted,
     * so a scope cannot be granted by naming one that does not exist. An empty
     * result falls back to the activate/check pair rather than to no scopes,
     * since a credential that can do nothing is a support ticket rather than a
     * safe default.
     *
     * Key and secret carry `lfk_`/`lfs_` prefixes so one pasted into the wrong
     * field is recognisable at a glance, and so a leaked value is identifiable
     * in a log or a public repository.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,api_key:string,api_secret:string}
     */
    public static function create(array $data): array
    {
        $apiKey    = 'lfk_' . Crypto::base64UrlEncode(random_bytes(18));
        $apiSecret = 'lfs_' . Crypto::base64UrlEncode(random_bytes(32));

        $scopes = array_values(array_intersect(
            Input::toList((string) ($data['scopes'] ?? 'activate,check')),
            self::SCOPES
        ));
        if ($scopes === []) {
            $scopes = ['activate', 'check'];
        }

        $id = (int) Db::table('api_credentials')->insertGetId([
            'name'             => mb_substr((string) ($data['name'] ?? 'API credential'), 0, 190),
            'api_key'          => $apiKey,
            'secret_hash'      => Crypto::hashSecret($apiSecret),
            'secret_encrypted' => Crypto::encrypt($apiSecret),
            'scopes'           => implode(',', $scopes),
            'allowed_ips'      => implode(',', Input::toList((string) ($data['allowed_ips'] ?? ''))),

            // Product scoping. Empty with allow_all_products off means the
            // credential can reach nothing, which is the safe default.
            'allowed_products'   => implode(',', array_map('intval', Input::toList((string) ($data['allowed_products'] ?? '')))),
            'allow_all_products' => !empty($data['allow_all_products']) ? 1 : 0,
            'rate_limit'       => (int) ($data['rate_limit'] ?? 0),

            // Absent is_active means "new and enabled"; present but empty is an
            // explicit disable.
            'is_active'        => array_key_exists('is_active', $data) && empty($data['is_active']) ? 0 : 1,
            'expires_at'       => Input::toDateTime((string) ($data['expires_at'] ?? '')),
            'created_at'       => Db::now(),
            'updated_at'       => Db::now(),
        ]);

        Audit::log('api_credential.created', null, Audit::RESULT_SUCCESS, [
            'credential_id' => $id, 'name' => $data['name'] ?? '', 'scopes' => $scopes,
        ]);

        return ['id' => $id, 'api_key' => $apiKey, 'api_secret' => $apiSecret];
    }

    /**
     * Look up a credential by its public key.
     *
     * Length is bounded before the query: the key arrives from an
     * unauthenticated request, and this runs on the hot path for every API call.
     *
     * Returns the row whether or not it is active or expired. {@see Auth} checks
     * those separately so it can answer with the specific reason rather than a
     * blanket failure.
     *
     * @return object|null
     */
    public static function findByKey(string $apiKey)
    {
        if ($apiKey === '' || strlen($apiKey) > 64) {
            return null;
        }

        return Db::table('api_credentials')->where('api_key', $apiKey)->first();
    }

    /**
     * Look up a credential by its internal id, for the admin pages.
     *
     * @return object|null
     */
    public static function find(int $id)
    {
        return Db::table('api_credentials')->where('id', $id)->first();
    }

    /**
     * Every credential, newest first, for the admin listing.
     *
     * @return iterable<object>
     */
    public static function all()
    {
        return Db::table('api_credentials')->orderBy('id', 'desc')->get();
    }

    /**
     * Recover a credential's plaintext secret.
     *
     * Audited before the attempt rather than after, so the record exists whether
     * or not decryption succeeds - the intent to read a secret is what needs
     * logging, not merely the successful reads.
     *
     * A failure to decrypt returns null rather than throwing: it means the
     * master key has changed or the storage file was lost, which is a
     * server-side fault that {@see rotate()} resolves and should not surface as
     * an exception in an admin page.
     */
    public static function revealSecret(int $id): ?string
    {
        $row = self::find($id);
        if ($row === null || $row->secret_encrypted === null) {
            return null;
        }

        Audit::log('api_credential.secret_revealed', null, Audit::RESULT_SUCCESS, ['credential_id' => $id]);

        try {
            return Crypto::decrypt((string) $row->secret_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Replace a credential's secret, keeping its key and settings.
     *
     * Every client holding the old secret stops authenticating the moment this
     * commits, so it is the response to a leak rather than routine maintenance.
     * Keeping the public key means only the secret has to be redistributed.
     *
     * @return string|null The new plaintext secret, shown once, or null if the
     *   credential does not exist.
     */
    public static function rotate(int $id): ?string
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }

        $secret = 'lfs_' . Crypto::base64UrlEncode(random_bytes(32));
        Db::table('api_credentials')->where('id', $id)->update([
            'secret_hash'      => Crypto::hashSecret($secret),
            'secret_encrypted' => Crypto::encrypt($secret),
            'updated_at'       => Db::now(),
        ]);

        Audit::log('api_credential.rotated', null, Audit::RESULT_SUCCESS, ['credential_id' => $id]);

        return $secret;
    }

    /**
     * Save changes to a credential's scopes, restrictions and expiry.
     *
     * The secret is untouched - replacing it is {@see rotate()}.
     *
     * Scopes are intersected with the known set as at creation, so an
     * unrecognised scope is dropped rather than stored. Note this rewrites every
     * field from the payload: a partial array clears what it omits, which is
     * correct for a form submission and wrong for a targeted change.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        $scopes = array_values(array_intersect(Input::toList((string) ($data['scopes'] ?? '')), self::SCOPES));

        $payload = [
            'name'        => mb_substr((string) ($data['name'] ?? ''), 0, 190),
            'scopes'      => implode(',', $scopes),
            'allowed_ips' => implode(',', Input::toList((string) ($data['allowed_ips'] ?? ''))),
            'allowed_products'   => implode(',', array_map('intval', Input::toList((string) ($data['allowed_products'] ?? '')))),
            'allow_all_products' => !empty($data['allow_all_products']) ? 1 : 0,
            'rate_limit'  => (int) ($data['rate_limit'] ?? 0),
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'expires_at'  => Input::toDateTime((string) ($data['expires_at'] ?? '')),
            'updated_at'  => Db::now(),
        ];

        Db::table('api_credentials')->where('id', $id)->update($payload);
        Audit::log('api_credential.updated', null, Audit::RESULT_SUCCESS, ['credential_id' => $id] + $payload);

        return true;
    }

    /**
     * Delete a credential permanently.
     *
     * Its nonces go first. They are only meaningful as replay protection for a
     * credential that still exists, and leaving them behind would keep rows
     * nothing will ever prune - the nonce sweep works by expiry, not by owner.
     */
    public static function delete(int $id): bool
    {
        Db::table('api_nonces')->where('credential_id', $id)->delete();
        $deleted = (int) Db::table('api_credentials')->where('id', $id)->delete() > 0;
        if ($deleted) {
            Audit::log('api_credential.deleted', null, Audit::RESULT_SUCCESS, ['credential_id' => $id]);
        }

        return $deleted;
    }

    /**
     * Note that a credential was just used successfully.
     *
     * Gives an administrator the two facts needed to retire a credential safely:
     * whether anything still uses it, and from where.
     *
     * Failures are swallowed deliberately. This is bookkeeping on the hot path
     * of every authenticated request, and a licence check must not fail because
     * a statistics column could not be written.
     */
    public static function recordUse(int $id, string $ip): void
    {
        try {
            Db::table('api_credentials')->where('id', $id)->update([
                'last_used_at' => Db::now(),
                'last_used_ip' => $ip,
            ]);
            Db::table('api_credentials')->where('id', $id)->increment('request_count');
        } catch (\Throwable $e) {
        }
    }

    /**
     * The scopes a credential holds.
     *
     * @return list<string>
     */
    public static function scopesOf(object $credential): array
    {
        return Input::toList((string) $credential->scopes);
    }

    /**
     * May this credential perform an operation requiring `$scope`?
     *
     * `admin` satisfies every scope, which is exactly why it should not travel
     * inside a distributed build: it is not one more permission but a bypass of
     * the whole check.
     */
    public static function hasScope(object $credential, string $scope): bool
    {
        $scopes = self::scopesOf($credential);

        return in_array('admin', $scopes, true) || in_array($scope, $scopes, true);
    }
}

