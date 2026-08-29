<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\KeyGenerator;
use LicenseForge\Support\Net;

/**
 * One licensing request, normalised into the form the engine compares against.
 *
 * Sits between the transport and the licensing rules. Everything a client sends
 * arrives as free text, in whatever shape and casing its SDK produced, so it is
 * cleaned once here rather than at each of the dozens of places that later
 * compare it.
 *
 * That single point of normalisation is what makes the binding checks
 * trustworthy: a licence locked to `example.com` is not defeated by
 * `https://WWW.Example.com:8443/install/`, because both reduce to the same
 * string before anything compares them - see {@see Net}.
 *
 * Field names are accepted in several spellings, because integrations were
 * written against different examples over time and breaking them to enforce one
 * name would buy nothing.
 *
 * A plain value object: public properties, no behaviour beyond normalisation
 * and a few derived values. Two fields are filled in by the transport layer
 * after construction rather than by the client - see `installProof` and
 * `credentialProducts`.
 */
final class LicenseRequest
{
    public string $licenseKey = '';
    public string $productIdentifier = '';
    public string $domain = '';
    public string $ip = '';
    public string $directory = '';
    public string $machineId = '';
    public string $installationId = '';
    public string $version = '';

    /** @var array<string,string> Free-form client detail, bounded and sanitised. */
    public array $metadata = [];

    /**
     * The address the request actually came from.
     *
     * Distinct from `$ip`, which the client may declare. A client behind NAT
     * legitimately reports an address that differs from the one observed, so
     * both are kept: the declared one for binding, the observed one for abuse
     * detection and for the record.
     */
    public string $observedIp = '';

    /**
     * Proof that this caller is the installation it claims to be.
     *
     * Filled in by {@see \LicenseForge\Api\Server} from a header, not by the
     * client's body, together with the canonical string the proof covers. Empty
     * means the caller presented none, which is not itself a refusal: the caller
     * is treated as an installation the server has not seen, and faces the
     * activation limit like any newcomer.
     */
    public string $installProof = '';
    public string $installCanonical = '';

    /**
     * Products the authenticated credential may act on.
     *
     * Filled in by {@see \LicenseForge\Api\Server} from the credential, never
     * from the request. Null means unrestricted; an empty array authorises
     * nothing, which is why the two are deliberately different values here.
     *
     * @var list<int>|null
     */
    public ?array $credentialProducts = null;

    /**
     * A public key the installation wishes to be identified by from now on.
     *
     * Optional, and offered only by clients able to generate a keypair.
     * Registering one removes the server-side liability of the shared secret:
     * verifying a signature needs only the public half.
     */
    public string $installPublicKey = '';
    public string $installKeyAlgorithm = '';

    /**
     * Build a request from a client's parameters.
     *
     * Every field is normalised on the way in: the domain loses its scheme,
     * port, path, credentials and case; the directory loses its separator
     * differences; the licence key loses its whitespace and case. Nothing
     * downstream re-cleans these, so the comparisons the engine performs are
     * exactly against these values.
     *
     * The declared IP falls back to the observed one when the client sends none,
     * so an integration that does not know its own address still has something
     * consistent to bind against.
     *
     * @param array<string,mixed> $input      Parameters from the request body.
     * @param string|null         $observedIp The connecting address; resolved
     *   from the request when omitted.
     */
    public static function fromArray(array $input, ?string $observedIp = null): self
    {
        $request = new self();

        $request->licenseKey        = KeyGenerator::normalise((string) self::pick($input, ['license_key', 'key', 'licensekey']));
        $request->productIdentifier = self::clean((string) self::pick($input, ['product_id', 'product', 'product_slug']), 100);
        $request->directory         = Net::normalisePath(self::clean((string) self::pick($input, ['directory', 'path', 'install_path']), 500));
        $request->machineId         = self::clean((string) self::pick($input, ['machine_id', 'machine', 'hardware_id']), 128);
        $request->installationId    = self::clean((string) self::pick($input, ['installation_id', 'install_id']), 128);
        $request->version           = self::clean((string) self::pick($input, ['version', 'software_version']), 32);

        $request->installPublicKey    = self::clean((string) self::pick($input, ['install_public_key', 'installation_public_key']), 4000);
        $request->installKeyAlgorithm = self::clean((string) self::pick($input, ['install_key_algorithm', 'installation_key_algorithm']), 32);

        $domain = (string) self::pick($input, ['domain', 'host', 'hostname', 'url']);
        $request->domain = Net::normaliseDomain($domain);

        $request->observedIp = Net::normaliseIp((string) ($observedIp ?? Net::clientIp()));

        $declaredIp    = Net::normaliseIp((string) self::pick($input, ['ip', 'ip_address', 'server_ip']));
        $request->ip   = $declaredIp !== '' ? $declaredIp : $request->observedIp;

        $metadata = self::pick($input, ['metadata', 'meta', 'server']);
        if (is_array($metadata)) {
            $request->metadata = self::sanitiseMetadata($metadata);
        }

        return $request;
    }

    /**
     * The first non-empty value among several accepted field names.
     *
     * Empty is treated as absent, so a client sending `domain=""` alongside
     * `host="example.com"` is understood rather than taken at its word about the
     * blank.
     *
     * @param  array<string,mixed> $input
     * @param  list<string>        $keys Preferred name first.
     * @return mixed
     */
    private static function pick(array $input, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                return $input[$key];
            }
        }

        return '';
    }

    /**
     * Trim a value, strip control characters, and bound its length.
     *
     * Control characters are removed rather than escaped because none of these
     * fields has a legitimate use for them, and they are how a value smuggles a
     * newline into a log line or a header.
     *
     * Truncation is by characters, not bytes, so a multi-byte value is not cut
     * mid-character into something invalid.
     */
    private static function clean(string $value, int $maxLength): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value));

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Reduce client-supplied metadata to something safe to store.
     *
     * Bounded in three directions at once, because this is the one field with no
     * fixed shape and it is written to the database and the audit log: at most
     * 25 entries, keys to 40 characters, values to 190.
     *
     * Non-scalar values are dropped rather than serialised - a client sending a
     * nested structure is sending something this field is not for, and
     * flattening it would invent content nobody supplied.
     *
     * @param  array<mixed,mixed> $metadata
     * @return array<string,string>
     */
    private static function sanitiseMetadata(array $metadata): array
    {
        $clean = [];
        $count = 0;
        foreach ($metadata as $key => $value) {
            if ($count++ >= 25) {
                break;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $clean[self::clean((string) $key, 40)] = self::clean((string) $value, 190);
        }

        return $clean;
    }

    /**
     * The identifier this installation is tracked under.
     *
     * Uses the client's own installation id when it sent one; otherwise derives
     * a stable identifier by hashing whatever does identify the installation -
     * its machine id if known, or its domain, directory and address together.
     *
     * Deriving rather than generating is what matters: the same installation
     * must produce the same id on every request, or each check-in would look
     * like a new installation and consume another activation slot. The `auto-`
     * prefix marks ids the server derived, so they are distinguishable from ones
     * a client chose.
     *
     * This is an identifier, not a credential. It appears in API responses and
     * is not secret - proving an installation is what `installProof` is for.
     */
    public function resolvedInstallationId(): string
    {
        if ($this->installationId !== '') {
            return $this->installationId;
        }

        $seed = $this->machineId !== ''
            ? 'machine:' . $this->machineId
            : 'site:' . $this->domain . '|' . $this->directory . '|' . $this->ip;

        return 'auto-' . substr(hash('sha256', $seed), 0, 40);
    }

    /**
     * Required parameters this request has not supplied.
     *
     * Returned as a list rather than refused one at a time, so an integrator
     * fixes everything in one pass instead of discovering the next missing field
     * on each retry.
     *
     * Activation additionally needs the product, because it decides which policy
     * applies. A check-in does not: the licence already knows its product.
     *
     * @return list<string> Empty when the request is complete.
     */
    public function missingFor(string $endpoint): array
    {
        $missing = [];
        if ($this->licenseKey === '') {
            $missing[] = 'license_key';
        }
        if ($endpoint === 'activate' && $this->productIdentifier === '') {
            $missing[] = 'product_id';
        }

        return $missing;
    }

    /**
     * The request as context for an audit or abuse record.
     *
     * Deliberately omits the licence key and the installation proof. Both are
     * credentials, and the log is read by staff and retained far longer than any
     * request - the resolved installation id identifies the caller adequately
     * without either.
     *
     * @return array<string,string>
     */
    public function toLogContext(): array
    {
        return [
            'product'         => $this->productIdentifier,
            'domain'          => $this->domain,
            'ip'              => $this->ip,
            'observed_ip'     => $this->observedIp,
            'directory'       => $this->directory,
            'machine_id'      => $this->machineId,
            'installation_id' => $this->resolvedInstallationId(),
            'version'         => $this->version,
        ];
    }
}
