<?php

declare(strict_types=1);

namespace LicenseForge\Api;

use LicenseForge\Support\Net;

/**
 * An immutable snapshot of one inbound API request.
 *
 * Captured once at the entry point and passed down, so every later decision -
 * routing, rate limiting, authentication, the licensing check - sees exactly
 * the same values. Reading the superglobals repeatedly would leave each layer
 * free to disagree about what was asked.
 *
 * The raw body is kept verbatim alongside the parsed parameters, because the
 * request signature covers a hash of those exact bytes; re-encoding the parsed
 * form would produce a different hash and fail every signature.
 *
 * Parameters are read from the request body only. The signature covers the
 * body, so a value taken from the query string would be authenticated by
 * nothing: an attacker holding one genuine signed request could append or alter
 * `license_key`, `domain` or `product_id` and the signature would still verify.
 * The nonce prevents a request being replayed, not rewritten. Keeping every
 * input inside the signed body is what makes the signature mean what it claims.
 */
final class Request
{
    private string $method;
    private string $endpoint;
    private string $rawBody;

    /** @var array<string,mixed> Parameters parsed from the request body. */
    private array $params;

    /** @var array<string,string> Request headers, keyed by lowercase name. */
    private array $headers;
    private string $clientIp;

    /**
     * Public so that tests can build a request directly rather than going
     * through the superglobals.
     *
     * @param array<string,mixed>  $params
     * @param array<string,string> $headers Keys must already be lowercase.
     * @param string               $rawBody Exactly as received; the signature
     *   covers a hash of these bytes.
     */
    public function __construct(string $method, string $endpoint, array $params, array $headers, string $rawBody, string $clientIp)
    {
        $this->method   = strtoupper($method);
        $this->endpoint = $endpoint;
        $this->params   = $params;
        $this->headers  = $headers;
        $this->rawBody  = $rawBody;
        $this->clientIp = $clientIp;
    }

    /**
     * Build a request from the current PHP superglobals.
     *
     * The body is read once from php://input, which is not re-readable, and both
     * the raw bytes and the parsed parameters are kept.
     *
     * JSON and form encoding are both accepted, chosen by Content-Type. A body
     * that declares JSON but does not parse yields no parameters rather than an
     * error: the request then fails on its missing parameters, which is a
     * clearer answer to the caller than a parse error.
     */
    public static function capture(): self
    {
        $method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawBody = (string) file_get_contents('php://input');
        $headers = self::captureHeaders();

        $contentType = strtolower($headers['content-type'] ?? '');

        /*
         * multipart/form-data carries no signable body. PHP populates $_POST
         * from it but leaves php://input empty, so the signature would cover a
         * hash of '' while every parameter arrived outside it - the one encoding
         * under which this class cannot keep the promise made above. No SDK
         * sends it, so it yields no parameters and the request fails on what it
         * is missing.
         */
        if (strpos($contentType, 'multipart/form-data') !== false) {
            return new self($method, self::detectEndpoint($_GET), [], $headers, $rawBody, Net::clientIp());
        }

        $params = [];
        if ($rawBody !== '') {
            if (strpos($contentType, 'application/json') !== false) {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            } else {
                parse_str($rawBody, $form);
                $params = $form;
            }
        }
        if ($_POST !== []) {
            $params = array_merge($params, $_POST);
        }

        $endpoint = self::detectEndpoint($params + $_GET);

        return new self($method, $endpoint, $params, $headers, $rawBody, Net::clientIp());
    }

    /**
     * Collect request headers from $_SERVER, keyed by lowercase name.
     *
     * PHP exposes headers as HTTP_* with underscores and no case, so they are
     * normalised here and read case-insensitively everywhere else - HTTP header
     * names are case-insensitive and clients vary.
     *
     * Content-Type and Content-Length are added separately because PHP does not
     * give them the HTTP_ prefix.
     *
     * @return array<string,string>
     */
    private static function captureHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp((string) $key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Work out which endpoint was requested.
     *
     * Three forms are accepted, in order of precedence, because installations
     * route differently depending on their web server:
     *
     *   1. PATH_INFO           /api/index.php/license/activate
     *   2. an `action` field   /api/?action=activate
     *   3. the URL's last path segment, for a rewritten URL
     *
     * The endpoint may legitimately come from the query string even though
     * parameters may not: it is part of the canonical string the signature
     * covers, so naming a different endpoint there changes the signature and
     * fails verification - the value cannot be substituted the way an unsigned
     * parameter could.
     *
     * `index` and `api` are ignored as trailing segments, being the script and
     * directory names rather than endpoints. An empty return means no endpoint
     * was named, which the router answers with UNSUPPORTED_ENDPOINT.
     *
     * @param array<string,mixed> $params Body parameters merged with the query
     *   string, since form 2 may appear in either.
     */
    private static function detectEndpoint(array $params): string
    {
        $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($pathInfo !== '') {
            $segments = array_values(array_filter(explode('/', $pathInfo)));
            if ($segments !== []) {
                return strtolower((string) end($segments));
            }
        }

        $action = (string) ($params['action'] ?? $params['endpoint'] ?? '');
        if ($action !== '') {
            return strtolower(trim($action));
        }

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (is_string($path)) {
            $segments = array_values(array_filter(explode('/', $path)));
            $last     = $segments === [] ? '' : (string) end($segments);
            $last     = preg_replace('/\.php$/i', '', $last) ?? '';
            if ($last !== '' && $last !== 'index' && $last !== 'api') {
                return strtolower($last);
            }
        }

        return '';
    }

    /** The HTTP method, uppercased. */
    public function method(): string
    {
        return $this->method;
    }

    /** The endpoint name, lowercased. Empty when none was named. */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * The request body exactly as received.
     *
     * Used to compute the signature hash, so it must never be re-encoded or
     * normalised.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The client's IP address, resolved once at capture.
     *
     * Honours forwarded headers only behind a configured trusted proxy. Use
     * {@see peerIp()} for decisions about the connection itself.
     */
    public function clientIp(): string
    {
        return $this->clientIp;
    }

    /** @return array<string,mixed> Every parameter from the request body. */
    public function all(): array
    {
        return $this->params;
    }

    /**
     * A parameter with no coercion, for values that may be arrays.
     *
     * @param mixed $default
     * @return mixed
     */
    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * A parameter as a trimmed string.
     *
     * A non-scalar value yields the default rather than being cast, since a
     * client sending an array where a string belongs has sent something this API
     * cannot act on, and converting it would invent a value nobody supplied.
     */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->params[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** A request header, looked up case-insensitively. */
    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> Every request header, keyed by lowercase name. */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Did this request arrive over TLS?
     *
     * A forwarded protocol header is trusted only behind a configured trusted
     * proxy - see {@see Net::isSecure()}. Never decide this from the header
     * alone: any client can claim `X-Forwarded-Proto: https` over a plaintext
     * connection, which is precisely the connection an attacker can read.
     */
    public function isSecure(): bool
    {
        return Net::isSecure();
    }

    /**
     * The address of the machine actually connected.
     *
     * Unlike {@see clientIp()} this is never rewritten by a forwarded header, so
     * it is what decisions about the connection itself must use - such as the
     * loopback exemption from the TLS requirement.
     */
    public function peerIp(): string
    {
        return Net::peerIp();
    }
}
