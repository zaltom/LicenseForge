<?php

declare(strict_types=1);

namespace LicenseForge\Api;

/**
 * The API's error vocabulary: every code it can return, with the message and
 * HTTP status that accompany it.
 *
 * These codes are the stable part of the API contract. Client software branches
 * on the code, never on the message: messages are prose for a human reading a
 * log or a support ticket and may be reworded in any release, while a code that
 * has shipped does not change meaning.
 *
 * The codes are deliberately specific. A client that can distinguish an expired
 * licence from a suspended one, or a domain mismatch from an activation limit,
 * can tell its user what to do about it.
 *
 * That specificity is safe because every response is authenticated: the caller
 * already holds a valid credential and the licence key it is asking about, so
 * naming the reason discloses nothing it could not already determine. The one
 * deliberate exception is the download endpoint, reached by a browser session
 * rather than a credential, which answers uniformly - see download.php.
 */
final class ErrorCodes
{
    /*
     * Licensing decisions: the request was understood and refused on its merits.
     * These describe the state of the licence rather than a fault in the
     * request, so a client meeting one should report it rather than retry.
     */
    public const INVALID_LICENSE      = 'INVALID_LICENSE';
    public const LICENSE_EXPIRED      = 'LICENSE_EXPIRED';
    public const LICENSE_SUSPENDED    = 'LICENSE_SUSPENDED';
    public const LICENSE_REVOKED      = 'LICENSE_REVOKED';
    public const LICENSE_TERMINATED   = 'LICENSE_TERMINATED';
    public const LICENSE_PENDING      = 'LICENSE_PENDING';
    public const PRODUCT_MISMATCH     = 'PRODUCT_MISMATCH';
    public const DOMAIN_MISMATCH      = 'DOMAIN_MISMATCH';
    public const IP_MISMATCH          = 'IP_MISMATCH';
    public const DIRECTORY_MISMATCH   = 'DIRECTORY_MISMATCH';
    public const MACHINE_MISMATCH     = 'MACHINE_MISMATCH';
    public const CREDENTIAL_SCOPE     = 'CREDENTIAL_SCOPE';
    public const ACTIVATION_LIMIT     = 'ACTIVATION_LIMIT';
    public const ACTIVATION_NOT_FOUND = 'ACTIVATION_NOT_FOUND';
    public const VERSION_NOT_SUPPORTED = 'VERSION_NOT_SUPPORTED';
    public const REISSUE_LIMIT        = 'REISSUE_LIMIT';
    public const REISSUE_COOLDOWN     = 'REISSUE_COOLDOWN';
    public const REISSUE_NOT_ALLOWED  = 'REISSUE_NOT_ALLOWED';
    public const REISSUE_PENDING      = 'REISSUE_PENDING';

    /*
     * Malformed requests: the caller sent something this API cannot act on.
     * Retrying unchanged will fail identically.
     */
    public const INVALID_REQUEST      = 'INVALID_REQUEST';
    public const MISSING_PARAMETER    = 'MISSING_PARAMETER';
    public const UNSUPPORTED_ENDPOINT = 'UNSUPPORTED_ENDPOINT';
    public const METHOD_NOT_ALLOWED   = 'METHOD_NOT_ALLOWED';

    /*
     * Authentication failures, covering both the credential and the signature.
     * Kept distinct because they call for different fixes: an expired credential
     * needs reissuing, an invalid signature means the signing code is wrong, and
     * a rejected timestamp usually means the client's clock has drifted rather
     * than anything being wrong with the integration.
     */
    public const AUTH_REQUIRED        = 'AUTH_REQUIRED';
    public const AUTH_INVALID         = 'AUTH_INVALID';
    public const AUTH_EXPIRED         = 'AUTH_EXPIRED';
    public const SIGNATURE_INVALID    = 'SIGNATURE_INVALID';
    public const TIMESTAMP_INVALID    = 'TIMESTAMP_INVALID';
    public const REPLAY_DETECTED      = 'REPLAY_DETECTED';
    public const IP_NOT_ALLOWED       = 'IP_NOT_ALLOWED';
    public const SCOPE_DENIED         = 'SCOPE_DENIED';

    /*
     * Throttling and server-side conditions. The only codes a client should
     * retry after: RATE_LIMITED and SERVICE_UNAVAILABLE are temporary, and both
     * arrive with a Retry-After header where one applies.
     */
    public const RATE_LIMITED         = 'RATE_LIMITED';
    public const ABUSE_DETECTED       = 'ABUSE_DETECTED';
    public const SERVICE_UNAVAILABLE  = 'SERVICE_UNAVAILABLE';
    public const INTERNAL_ERROR       = 'INTERNAL_ERROR';

    /**
     * Every code, with its message and HTTP status.
     *
     * The single source for both, so a code cannot exist with a status but no
     * message, or answer differently on two paths.
     *
     * Status choices worth noting: a refused licence is 403 rather than 402 or
     * 200, because the request was authenticated and denied. A replayed nonce
     * and a pending reissue are 409, since both conflict with state the server
     * already holds. Cooldowns are 429 alongside rate limits, so a client with
     * generic retry-after handling treats them correctly without special cases.
     *
     * @return array<string,array{message:string,status:int}>
     */
    public static function catalogue(): array
    {
        return [
            self::INVALID_LICENSE       => ['message' => 'The license key is not valid.', 'status' => 403],
            self::LICENSE_EXPIRED       => ['message' => 'The license has expired.', 'status' => 403],
            self::LICENSE_SUSPENDED     => ['message' => 'The license is suspended.', 'status' => 403],
            self::LICENSE_REVOKED       => ['message' => 'The license has been revoked.', 'status' => 403],
            self::LICENSE_TERMINATED    => ['message' => 'The license has been terminated.', 'status' => 403],
            self::LICENSE_PENDING       => ['message' => 'The license is not yet active.', 'status' => 403],
            self::PRODUCT_MISMATCH      => ['message' => 'The license is not valid for this product.', 'status' => 403],
            self::DOMAIN_MISMATCH       => ['message' => 'The license is not authorised for this domain.', 'status' => 403],
            self::IP_MISMATCH           => ['message' => 'The license is not authorised for this IP address.', 'status' => 403],
            self::DIRECTORY_MISMATCH    => ['message' => 'The license is not authorised for this installation directory.', 'status' => 403],
            self::MACHINE_MISMATCH      => ['message' => 'The license is not authorised for this machine.', 'status' => 403],
            self::CREDENTIAL_SCOPE      => ['message' => 'This API credential is not authorised for that product.', 'status' => 403],
            self::ACTIVATION_LIMIT      => ['message' => 'The activation limit for this license has been reached.', 'status' => 403],
            self::ACTIVATION_NOT_FOUND  => ['message' => 'No matching activation was found.', 'status' => 404],
            self::VERSION_NOT_SUPPORTED => ['message' => 'This software version is not covered by the license.', 'status' => 403],
            self::REISSUE_LIMIT         => ['message' => 'The reissue limit for this license has been reached.', 'status' => 403],
            self::REISSUE_COOLDOWN      => ['message' => 'This license was reissued too recently. Please try again later.', 'status' => 429],
            self::REISSUE_NOT_ALLOWED   => ['message' => 'Reissuing is not permitted for this license.', 'status' => 403],
            self::REISSUE_PENDING       => ['message' => 'A reissue request is awaiting approval.', 'status' => 409],

            self::INVALID_REQUEST       => ['message' => 'The request could not be processed.', 'status' => 400],
            self::MISSING_PARAMETER     => ['message' => 'A required parameter is missing.', 'status' => 400],
            self::UNSUPPORTED_ENDPOINT  => ['message' => 'Unknown endpoint.', 'status' => 404],
            self::METHOD_NOT_ALLOWED    => ['message' => 'HTTP method not allowed for this endpoint.', 'status' => 405],

            self::AUTH_REQUIRED         => ['message' => 'API authentication is required.', 'status' => 401],
            self::AUTH_INVALID          => ['message' => 'API authentication failed.', 'status' => 401],
            self::AUTH_EXPIRED          => ['message' => 'The API credential has expired.', 'status' => 401],
            self::SIGNATURE_INVALID     => ['message' => 'The request signature is invalid.', 'status' => 401],
            self::TIMESTAMP_INVALID     => ['message' => 'The request timestamp is outside the permitted window.', 'status' => 401],
            self::REPLAY_DETECTED       => ['message' => 'This request has already been processed.', 'status' => 409],
            self::IP_NOT_ALLOWED        => ['message' => 'This credential may not be used from this IP address.', 'status' => 403],
            self::SCOPE_DENIED          => ['message' => 'The credential does not permit this operation.', 'status' => 403],

            self::RATE_LIMITED          => ['message' => 'Too many requests. Please slow down.', 'status' => 429],
            self::ABUSE_DETECTED        => ['message' => 'This request was blocked by abuse protection.', 'status' => 429],
            self::SERVICE_UNAVAILABLE   => ['message' => 'The licensing service is temporarily unavailable.', 'status' => 503],
            self::INTERNAL_ERROR        => ['message' => 'An unexpected error occurred.', 'status' => 500],
        ];
    }

    /**
     * The human-readable message for a code.
     *
     * Falls back to the internal-error message rather than returning the raw
     * code, so an unknown value cannot reach a customer as a bare identifier.
     */
    public static function message(string $code): string
    {
        return self::catalogue()[$code]['message'] ?? self::catalogue()[self::INTERNAL_ERROR]['message'];
    }

    /**
     * The HTTP status for a code.
     *
     * Defaults to 400 for anything unrecognised: a code this class does not know
     * came from a caller's mistake rather than a server fault, and 400 says so
     * without implying the service is broken.
     */
    public static function httpStatus(string $code): int
    {
        return self::catalogue()[$code]['status'] ?? 400;
    }

    /** Is this a code the API can actually return? */
    public static function exists(string $code): bool
    {
        return isset(self::catalogue()[$code]);
    }

    /**
     * The code that explains why a licence in this status was refused.
     *
     * Anything unmapped becomes INVALID_LICENSE, the safe default: it refuses
     * without asserting a reason the server cannot substantiate.
     *
     * `reissued` maps there deliberately. The key really is no longer valid - it
     * was replaced - and naming the replacement as a distinct condition would
     * confirm to whoever holds the old key that a newer one exists.
     */
    public static function forStatus(string $status): string
    {
        $map = [
            'expired'    => self::LICENSE_EXPIRED,
            'suspended'  => self::LICENSE_SUSPENDED,
            'revoked'    => self::LICENSE_REVOKED,
            'terminated' => self::LICENSE_TERMINATED,
            'pending'    => self::LICENSE_PENDING,
            'reissued'   => self::INVALID_LICENSE,
        ];

        return $map[$status] ?? self::INVALID_LICENSE;
    }
}
