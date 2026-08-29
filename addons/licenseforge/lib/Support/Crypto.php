<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Every cryptographic operation the module performs.
 *
 * Four responsibilities: the master key that protects stored secrets,
 * authenticated encryption for those secrets, the signing keys that make
 * offline licence tokens verifiable, and the constant-time comparisons used
 * wherever a credential is checked.
 *
 * The master key is the root of the scheme. It is derived from two independent
 * inputs - a random secret on disk and the WHMCS instance hash - so a database
 * dump alone recovers nothing; reading a single stored API secret or signing
 * key requires both the database and filesystem access. See {@see masterKey()}.
 *
 * Signing prefers Ed25519 via libsodium and falls back to RSA-2048 through
 * OpenSSL, so an installation without libsodium still issues offline tokens.
 * Private keys never leave the server; only the public halves are distributed.
 *
 * Losing `storage/master-key.php` is unrecoverable: every stored API secret and
 * private signing key becomes undecryptable. It is not in the shipped package
 * and must be excluded from version control but included in backups.
 */
final class Crypto
{
    /*
     * The two signature algorithms. Both are stored with every key and travel
     * inside every signed token, so a client selects the right verifier during
     * rotation rather than assuming one.
     */
    public const ALG_ED25519 = 'ed25519';
    public const ALG_RSA     = 'rsa-sha256';

    /**
     * The derived master key, cached for the request. Derivation reads a file
     * and a WHMCS setting, encryption happens many times per request, and the
     * key cannot change mid-request.
     */
    private static ?string $masterKey = null;

    /**
     * The active signing key, cached for the request.
     *
     * Null means "not looked up yet", not "none exists": an installation with
     * no key generates one on first use, so the lookup never legitimately
     * yields null.
     *
     * @var object|null
     */
    private static $activeKey = null;

    /**
     * The directory holding the master key, created if absent.
     *
     * The `.htaccess` written alongside is defence in depth for installations
     * inside the web root, and is not relied upon - nginx ignores `.htaccess`
     * entirely, which is why the key itself is stored as a PHP file. See
     * {@see readOrCreateKeyMaterial()}.
     *
     * `LICENSEFORGE_STORAGE_DIR` relocates it. Anything exercising this class
     * outside a real installation should set it: creating key material as a
     * side effect is unwanted on a fresh server, and on one already holding
     * secrets this is the file every one of them depends on.
     */
    private static function storageDir(): string
    {
        $dir = defined('LICENSEFORGE_STORAGE_DIR')
            ? (string) constant('LICENSEFORGE_STORAGE_DIR')
            : LICENSEFORGE_DIR . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        return $dir;
    }

    /**
     * The 32-byte key wrapping every private key and API secret.
     *
     * Derived with HKDF from a random secret stored on disk and the WHMCS
     * instance's own encryption hash. Both are required, so a stolen database
     * yields only ciphertext.
     *
     * Changing the WHMCS encryption hash changes this key, and everything
     * encrypted under the old one becomes unreadable - the same failure as
     * losing the key file.
     */
    public static function masterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }

        $raw = self::readOrCreateKeyMaterial();

        self::$masterKey = hash_hkdf(
            'sha256',
            (string) base64_decode(trim($raw), true),
            32,
            'licenseforge:master:v1',
            self::instanceSecret()
        );

        return self::$masterKey;
    }

    /**
     * The WHMCS half of the key derivation.
     *
     * Refuses rather than substituting a default. An unreadable hash does not
     * mean "no salt", it means a different key - under which reads fail loudly
     * but writes succeed and are then unrecoverable once the value is readable
     * again. A transient database fault would otherwise silently corrupt every
     * secret encrypted during that request.
     *
     * Outside WHMCS there is no hash to read, so the empty salt is correct and
     * consistent for the life of that installation. An empty hash under WHMCS
     * is likewise accepted: it is a stable state, and refusing it would brick an
     * installation whose stored secrets are already wrapped under that key.
     * Should WHMCS later populate the hash, the derived key changes - which is
     * what {@see checkKeyIntegrity()} exists to report.
     *
     * @throws \RuntimeException When the hash cannot be read at all.
     */
    private static function instanceSecret(): string
    {
        if (!defined('WHMCS')) {
            return '';
        }

        try {
            return (string) (\WHMCS\Config\Setting::getValue('CCEncryptionHash') ?? '');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'LicenseForge cannot derive its master key because the WHMCS encryption hash '
                . 'could not be read. Refusing to continue rather than encrypt under a key that '
                . 'would not open the secrets already stored. Original error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * A non-reversible fingerprint of the derived master key.
     *
     * Recorded on first use and compared thereafter, so a changed key - a key
     * file restored from another installation, an altered WHMCS encryption hash
     * - is reported rather than discovered when a customer's activation stops
     * authenticating.
     */
    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', 'fingerprint:' . self::masterKey()), 0, 32);
    }

    /**
     * Compare the current master key against the one this installation recorded.
     *
     * Deliberately not called from {@see masterKey()}, which runs before the
     * settings table exists during activation and on every request thereafter.
     * The check belongs where an operator will see the answer.
     *
     * Read-only unless `$record` is set, so a caller that only inspects is safe
     * against production and no check adopts whatever key it happens to find as
     * the baseline. Recording belongs where a write is expected and the state is
     * known good: module activation, and the settings page.
     *
     * @param  bool $record Persist the fingerprint when none is stored yet.
     * @return array{status:string,message:string} status is `ok`, `unrecorded`,
     *   `changed` or `unavailable`.
     */
    public static function checkKeyIntegrity(bool $record = false): array
    {
        try {
            $current = self::keyFingerprint();
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'message' => $e->getMessage()];
        }

        try {
            $recorded = (string) (Settings::get('master_key_fingerprint', '') ?? '');

            if ($recorded === '') {
                if (!$record) {
                    return [
                        'status'  => 'unrecorded',
                        'message' => 'No fingerprint has been recorded for this installation yet, '
                            . 'so a change cannot be detected. Open the settings page to record one.',
                    ];
                }

                Settings::set('master_key_fingerprint', $current);

                return ['status' => 'recorded', 'message' => 'The master key fingerprint has been recorded.'];
            }

            if (!hash_equals($recorded, $current)) {
                return [
                    'status'  => 'changed',
                    'message' => 'The master key has changed since this installation recorded it. '
                        . 'Every stored API secret and signing key encrypted under the previous key '
                        . 'is now unreadable. Restore storage/master-key.php and the original WHMCS '
                        . 'encryption hash before issuing anything further.',
                ];
            }

            return ['status' => 'ok', 'message' => 'The master key matches the one recorded for this installation.'];
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'message' => $e->getMessage()];
        }
    }

    /**
     * The on-disk half of the master key, created on first use.
     *
     * Stored inside a PHP file rather than a plain one: nginx ignores
     * `.htaccess`, and this module lives under the web root, so on those servers
     * a plain key file would be downloadable. A PHP file is executed instead of
     * served, and this one prints nothing.
     *
     * Creation uses exclusive-create (`fopen` mode `x`) rather than
     * check-then-write. With the latter, two simultaneous first requests could
     * both see no file, each generate a different key, and each go on using its
     * own while the file ends up holding whichever wrote last - everything the
     * loser encrypted in that request would be undecryptable forever, with
     * nothing to report it, since each request was internally consistent.
     *
     * The written file is read back and compared before being trusted; a
     * half-written key file would be adopted by the next request and silently
     * produce a different key.
     *
     * An installation still holding the older plain key file keeps working: it
     * is read, rewritten in the safe form, and only then removed, so the derived
     * key is byte-for-byte identical and nothing needs re-encrypting.
     *
     * @throws \RuntimeException When no usable key can be written.
     */
    private static function readOrCreateKeyMaterial(): string
    {
        $dir    = self::storageDir();
        $file   = $dir . '/master-key.php';
        $legacy = $dir . '/master.key';

        if (is_file($file)) {
            $raw = (string) @include $file;
            if ($raw !== '') {
                return $raw;
            }
        }

        if (is_file($legacy)) {
            $raw = trim((string) file_get_contents($legacy));
            if ($raw !== '' && self::writeKeyMaterial($file, $raw)) {
                @unlink($legacy);
            }

            return $raw;
        }

        $handle = @fopen($file, 'x');
        if ($handle === false) {
            // Lost the create race, or the directory is not writable. Re-read
            // before concluding the latter.
            clearstatcache(true, $file);
            if (is_file($file)) {
                $raw = (string) @include $file;
                if ($raw !== '') {
                    return $raw;
                }
            }

            throw new \RuntimeException(
                'LicenseForge cannot write its master key to ' . $dir
                . '. Make that directory writable by the web server.'
            );
        }

        $raw = base64_encode(random_bytes(32));
        $ok  = fwrite($handle, self::keyFileContents($raw)) !== false;
        fclose($handle);

        if (!$ok || !self::keyFileReadsBack($file, $raw)) {
            @unlink($file);

            throw new \RuntimeException(
                'LicenseForge could not write a usable master key to ' . $dir . '.'
            );
        }

        @chmod($file, 0600);

        return $raw;
    }

    /**
     * The PHP wrapper the key material is stored inside.
     *
     * Carries its warning in the file itself, since the consequences are severe
     * and whoever finds it may not know what it is.
     */
    private static function keyFileContents(string $raw): string
    {
        return "<?php\n"
             . "// LicenseForge master key. Do not edit, and keep it out of version control.\n"
             . "// Losing this file makes every stored API secret and signing key unreadable.\n"
             . "return '" . $raw . "';\n";
    }

    /**
     * Confirm the key file yields exactly what was written.
     *
     * The assignment cannot be inlined: `include` consumes the rest of the
     * expression, so comparing directly would include the comparison rather
     * than the file.
     */
    private static function keyFileReadsBack(string $file, string $raw): bool
    {
        clearstatcache(true, $file);

        return (string) (@include $file) === $raw;
    }

    /**
     * Write the key file and prove it reads back.
     *
     * Used only for the legacy migration, where the key already exists and is
     * being moved into the safe wrapper. First creation goes through the
     * exclusive-create path in {@see readOrCreateKeyMaterial()}.
     */
    private static function writeKeyMaterial(string $file, string $raw): bool
    {
        if (@file_put_contents($file, self::keyFileContents($raw), LOCK_EX) === false) {
            return false;
        }

        @chmod($file, 0600);

        return self::keyFileReadsBack($file, $raw);
    }

    /**
     * Encrypt a secret for storage.
     *
     * AES-256-GCM, so tampering with stored ciphertext is detected on
     * decryption rather than yielding plausible garbage. A fresh random nonce
     * per call, never reused - nonce reuse under GCM is catastrophic, which is
     * why one is generated here rather than derived from anything.
     *
     * The output carries a version marker so a future change of algorithm can
     * be recognised and migrated rather than silently misread.
     *
     * @return string Base64: version marker, nonce, tag, ciphertext.
     * @throws \RuntimeException When encryption fails.
     */
    public static function encrypt(string $plaintext): string
    {
        $key   = self::masterKey();
        $nonce = random_bytes(12);
        $tag   = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'lfg', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode('v1' . $nonce . $tag . $cipher);
    }

    /**
     * Decrypt a stored secret, verifying it has not been altered.
     *
     * Length and version marker are checked before anything is unpacked, so a
     * truncated or foreign value fails cleanly rather than being sliced into
     * nonsense.
     *
     * A failure means tampering, a lost key file, or a changed WHMCS encryption
     * hash. Callers must not treat it as "no value" - see the handling in
     * ActivationService.
     *
     * @throws \RuntimeException When the payload is malformed or does not verify.
     */
    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 2 + 12 + 16 || substr($raw, 0, 2) !== 'v1') {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $nonce  = substr($raw, 2, 12);
        $tag    = substr($raw, 14, 16);
        $cipher = substr($raw, 30);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $nonce, $tag, 'lfg');
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    /**
     * A cryptographically random, URL-safe token.
     *
     * Used for per-installation secrets and anything else that must be
     * unguessable. Drawn from `random_bytes()`, never a weaker source.
     */
    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    /**
     * Base64 without characters that need escaping in a URL or header.
     *
     * `+` and `/` become `-` and `_` and the padding is dropped, so a value can
     * be carried in a header or query string without being re-encoded - which
     * is where a signature would otherwise be corrupted in transit.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode base64url, returning '' rather than false on malformed input.
     *
     * Saves callers from distinguishing false from a legitimately empty value;
     * every caller here treats empty as "does not verify".
     */
    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Constant-time string comparison, tolerating null.
     *
     * Every credential comparison in the module goes through here. A normal
     * comparison returns as soon as two bytes differ, so the time taken reveals
     * how much of a secret was guessed correctly - enough to recover it one byte
     * at a time.
     *
     * Nulls are cast rather than rejected so a caller need not guard a nullable
     * column, which is exactly where the check would otherwise be skipped.
     */
    public static function secureEquals(?string $known, ?string $given): bool
    {
        return hash_equals((string) $known, (string) $given);
    }

    /**
     * A one-way lookup hash for a secret stored beside a public identifier.
     *
     * Keyed with the master key rather than a plain hash, so a leaked table
     * cannot be attacked offline with a wordlist.
     */
    public static function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::masterKey());
    }

    /**
     * The signature algorithm to use for new keys.
     *
     * Ed25519 where libsodium is available: shorter keys and signatures, faster
     * verification, no parameter choices to get wrong. RSA-2048 otherwise.
     * An operator may pin either explicitly, which matters when client software
     * cannot verify one of them.
     */
    public static function preferredAlgorithm(): string
    {
        $configured = strtolower((string) Settings::get('signature_algorithm', 'auto'));
        if ($configured === 'ed25519' || ($configured === 'auto' && function_exists('sodium_crypto_sign_keypair'))) {
            return self::ALG_ED25519;
        }

        return self::ALG_RSA;
    }

    /**
     * Generate a signing key pair and store it.
     *
     * The private half is encrypted under the master key before storage and
     * never leaves the server. The public half is stored in clear - it is meant
     * to be distributed, and is what client SDKs verify offline tokens against.
     *
     * The fingerprint is a truncated hash of the public key, for identifying a
     * key in the admin console without displaying the whole thing.
     *
     * @return array{id:int,algorithm:string,public_key:string}
     * @throws \RuntimeException When the runtime cannot generate the requested type.
     */
    public static function generateSigningKey(?string $algorithm = null, bool $activate = true): array
    {
        $algorithm = $algorithm ?: self::preferredAlgorithm();

        if ($algorithm === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_keypair')) {
                throw new \RuntimeException('libsodium is not available for Ed25519 key generation.');
            }
            $pair       = sodium_crypto_sign_keypair();
            $privateKey = base64_encode(sodium_crypto_sign_secretkey($pair));
            $publicKey  = base64_encode(sodium_crypto_sign_publickey($pair));
        } else {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($resource === false) {
                throw new \RuntimeException('OpenSSL key generation failed.');
            }
            $privatePem = '';
            openssl_pkey_export($resource, $privatePem);
            $details    = openssl_pkey_get_details($resource);
            $privateKey = $privatePem;
            $publicKey  = (string) ($details['key'] ?? '');
        }

        $now = Db::now();
        $id  = (int) Db::table('signing_keys')->insertGetId([
            'algorithm'           => $algorithm,
            'public_key'          => $publicKey,
            'private_key_encrypted' => self::encrypt($privateKey),
            'fingerprint'         => substr(hash('sha256', $publicKey), 0, 32),
            'is_active'           => 0,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        if ($activate) {
            self::activateSigningKey($id);
        }

        return ['id' => $id, 'algorithm' => $algorithm, 'public_key' => $publicKey];
    }

    /**
     * Make one key the active signer.
     *
     * Clears every row and sets one, inside a transaction. The blanket UPDATE
     * takes exclusive locks on all rows and holds them to commit, so two
     * concurrent activations serialise rather than interleaving into two active
     * keys.
     *
     * Previous keys are retained, not deleted: tokens already issued name the
     * key that signed them, so rotation has an overlap by design.
     */
    public static function activateSigningKey(int $id): void
    {
        Db::transaction(static function () use ($id): void {
            Db::table('signing_keys')->update(['is_active' => 0, 'updated_at' => Db::now()]);
            Db::table('signing_keys')->where('id', $id)->update(['is_active' => 1, 'updated_at' => Db::now()]);
        });

        // The request that rotates must not go on signing with the key it just
        // retired, which the memo in activeSigningKey() would otherwise let it do.
        self::$activeKey = null;
    }

    /**
     * The key currently signing, generating one if none exists.
     *
     * Self-healing on purpose: an installation that has never generated a key
     * still issues offline tokens on first use rather than failing until an
     * administrator notices.
     *
     * Memoised for the request. Issuing one offline token asks for the active
     * key twice, and the answer cannot change mid-request: activation runs in a
     * transaction that serialises against itself, so a rotation is either wholly
     * before this request or wholly after it. {@see activateSigningKey()} clears
     * the cache so the process performing a rotation sees its own result.
     *
     * @return object|null
     */
    public static function activeSigningKey()
    {
        if (self::$activeKey !== null) {
            return self::$activeKey;
        }

        $key = Db::table('signing_keys')->where('is_active', 1)->orderBy('id', 'desc')->first();
        if ($key === null) {
            self::generateSigningKey();
            $key = Db::table('signing_keys')->where('is_active', 1)->orderBy('id', 'desc')->first();
        }

        return self::$activeKey = $key;
    }

    /**
     * Every public key, for distribution and for the admin console.
     *
     * All of them, not only the active one, because a client verifying a token
     * signed before a rotation needs the key that signed it. Private halves are
     * never included.
     *
     * @return list<array<string,mixed>>
     */
    public static function publicKeys(): array
    {
        $keys = [];
        foreach (Db::table('signing_keys')->orderBy('id', 'desc')->get() as $row) {
            $keys[] = [
                'id'          => (int) $row->id,
                'algorithm'   => (string) $row->algorithm,
                'public_key'  => (string) $row->public_key,
                'fingerprint' => (string) $row->fingerprint,
                'active'      => (bool) $row->is_active,
                'created_at'  => (string) $row->created_at,
            ];
        }

        return $keys;
    }

    /**
     * Produce a signed envelope for an offline licence token.
     *
     * Format: `base64url(json payload) . "." . base64url(signature)`.
     *
     * The key id and algorithm are placed inside the payload before signing, so
     * they are covered by the signature - a client selects the right public key
     * during rotation without that selection being something an attacker can
     * influence.
     *
     * The signature covers the encoded form rather than the raw JSON, so
     * verifiers need not reproduce this module's exact encoding to check it.
     *
     * @param array<string,mixed> $payload
     * @throws \RuntimeException When no key is available or signing fails.
     */
    public static function signPayload(array $payload): string
    {
        $key = self::activeSigningKey();
        if ($key === null) {
            throw new \RuntimeException('No signing key available.');
        }

        $payload['_key_id']    = (int) $key->id;
        $payload['_algorithm'] = (string) $key->algorithm;

        $json    = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $encoded = self::base64UrlEncode((string) $json);

        $privateKey = self::decrypt((string) $key->private_key_encrypted);

        if ($key->algorithm === self::ALG_ED25519) {
            $signature = sodium_crypto_sign_detached($encoded, (string) base64_decode($privateKey, true));
        } else {
            $signature = '';
            $resource  = openssl_pkey_get_private($privateKey);
            if ($resource === false || !openssl_sign($encoded, $signature, $resource, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Signing failed.');
            }
        }

        return $encoded . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Could this string actually verify a signature later?
     *
     * Checked when a key is registered rather than when it is first used. A
     * malformed key stored now becomes an installation that authenticates
     * against nothing later: it would present good signatures, fail every one,
     * and be metered as a new installation each time - which looks like a
     * licensing bug rather than a bad key.
     *
     * RSA keys must be at least 2048 bits. A shorter key verifies happily and
     * proves nothing, so the floor is enforced here rather than left to whatever
     * the client generated.
     */
    public static function isUsablePublicKey(string $publicKey, string $algorithm): bool
    {
        if ($algorithm === self::ALG_ED25519) {
            if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
                return false;
            }
            $raw = base64_decode($publicKey, true);

            return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        }

        if ($algorithm !== self::ALG_RSA) {
            return false;
        }

        $resource = openssl_pkey_get_public($publicKey);
        if ($resource === false) {
            return false;
        }

        $details = openssl_pkey_get_details($resource);

        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && (int) ($details['bits'] ?? 0) >= 2048;
    }

    /**
     * Verify a detached signature over an arbitrary message.
     *
     * Used for installation proofs from clients that registered a public key
     * rather than taking a shared secret. Same primitives as
     * {@see verifyPayload()}, but over a caller-supplied message, because the
     * installation proof covers the request's own canonical string.
     *
     * Key and signature lengths are checked before verification, so malformed
     * input is refused rather than reaching the primitive.
     *
     * @param string $signature Raw signature bytes, already decoded.
     */
    public static function verifyDetached(
        string $message,
        string $signature,
        string $publicKey,
        string $algorithm
    ): bool {
        if ($publicKey === '' || $signature === '') {
            return false;
        }

        if ($algorithm === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return false;
            }
            $raw = base64_decode($publicKey, true);
            if ($raw === false
                || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signature, $message, $raw);
        }

        $resource = openssl_pkey_get_public($publicKey);

        return $resource !== false
            && openssl_verify($message, $signature, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Verify a signed envelope and return its payload.
     *
     * Server-side verification, used by the admin verifier tool. Client SDKs
     * implement their own so they can check a token without contacting the
     * server, which is the purpose of offline tokens.
     *
     * With no key supplied, the key named in the payload is used; the fallback
     * matches {@see activeSigningKey()}'s ordering so signing and verification
     * cannot disagree about which key is current.
     *
     * The payload is decoded before the signature is checked, in order to read
     * the key id from it. Nothing acts on that content until verification
     * passes, and a null return is the only success-free outcome.
     *
     * @return array<string,mixed>|null The payload, or null when it does not verify.
     */
    public static function verifyPayload(string $envelope, ?string $publicKey = null, ?string $algorithm = null): ?array
    {
        $parts = explode('.', $envelope);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $signatureB64] = $parts;

        $payload = json_decode(self::base64UrlDecode($encoded), true);
        if (!is_array($payload)) {
            return null;
        }

        if ($publicKey === null) {
            $keyId = (int) ($payload['_key_id'] ?? 0);

            $row   = $keyId > 0
                ? Db::table('signing_keys')->where('id', $keyId)->first()
                : Db::table('signing_keys')->where('is_active', 1)->orderBy('id', 'desc')->first();
            if ($row === null) {
                return null;
            }
            $publicKey = (string) $row->public_key;
            $algorithm = (string) $row->algorithm;
        }
        $algorithm = $algorithm ?: (string) ($payload['_algorithm'] ?? self::ALG_ED25519);
        $signature = self::base64UrlDecode($signatureB64);

        if ($algorithm === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return null;
            }
            $raw = base64_decode($publicKey, true);
            if ($raw === false
                || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return null;
            }
            $ok = sodium_crypto_sign_verify_detached($signature, $encoded, $raw);
        } else {
            $resource = openssl_pkey_get_public($publicKey);
            $ok       = $resource !== false && openssl_verify($encoded, $signature, $resource, OPENSSL_ALGO_SHA256) === 1;
        }

        return $ok ? $payload : null;
    }
}
