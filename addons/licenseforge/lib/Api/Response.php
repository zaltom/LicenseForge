<?php

declare(strict_types=1);

namespace LicenseForge\Api;

/**
 * An immutable JSON response from the licensing API.
 *
 * Every reply the API produces is built here, which makes the response shape a
 * guarantee rather than a convention: clients can rely on `success` being
 * present on every response, and on a failure always carrying `error.code` and
 * `error.message`.
 *
 * Shape:
 *
 *   { "success": true,  ...payload }
 *   { "success": false, "error": { "code": "...", "message": "...",
 *                                  "details": { ... } } }
 *
 * Instances are immutable: the constructor is private, objects are created
 * through {@see success()} or {@see error()}, and {@see withHeader()} returns a
 * copy. A response can therefore be built in one place and sent in another
 * without anything in between altering it.
 */
final class Response
{
    private array $body;
    private int $status;

    /** @var array<string,string> Extra headers, such as Retry-After. */
    private array $headers;

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    private function __construct(array $body, int $status, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    /**
     * Build a successful response.
     *
     * `success` is prepended with the union operator rather than appended, so it
     * appears first in the JSON and a caller reading a truncated response still
     * sees it. Union also means a payload cannot overwrite the flag with its own
     * `success` key.
     *
     * @param array<string,mixed> $data Payload merged into the response body.
     */
    public static function success(array $data = [], int $status = 200): self
    {
        return new self(['success' => true] + $data, $status);
    }

    /**
     * Build a failure response.
     *
     * Message and HTTP status both default to whatever {@see ErrorCodes}
     * registers for the code, so a caller normally passes the code alone and the
     * two stay consistent across every path that returns it. Overriding either
     * is for cases where the code is right but more can usefully be said.
     *
     * `details` is omitted entirely when empty rather than serialised as an
     * empty object, so its presence is meaningful. It carries the
     * machine-readable specifics a client can act on - which parameters were
     * missing, how many activations are in use, when a cooldown ends.
     *
     * @param string               $code    An ErrorCodes constant.
     * @param string|null          $message Overrides the registered message.
     * @param array<string,mixed>  $extra   Structured detail for the client.
     * @param int|null             $status  Overrides the registered HTTP status.
     */
    public static function error(string $code, ?string $message = null, array $extra = [], ?int $status = null): self
    {
        $body = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message ?? ErrorCodes::message($code),
            ],
        ];
        if ($extra !== []) {
            $body['error']['details'] = $extra;
        }

        return new self($body, $status ?? ErrorCodes::httpStatus($code));
    }

    /**
     * A copy of this response carrying one additional header.
     *
     * Returns a clone rather than mutating, so a response already handed to a
     * caller cannot change underneath it.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** @return array<string,mixed> The response body, before encoding. */
    public function body(): array
    {
        return $this->body;
    }

    /** The HTTP status this response will be sent with. */
    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> Extra headers beyond the standard set. */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Encode the body as JSON.
     *
     * Slashes and Unicode are left unescaped so URLs and non-ASCII text stay
     * readable in logs, and zero fractions are preserved so a value that is
     * conceptually a float does not arrive as an integer and change a client's
     * parsing.
     */
    public function toJson(): string
    {
        return (string) json_encode(
            $this->body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Write the response to the client.
     *
     * The headers are the security envelope every API reply carries:
     *
     *   Cache-Control: no-store   Responses contain licence state and, on
     *                             activation, the installation secret. None of
     *                             it belongs in a shared cache.
     *   X-Content-Type-Options    Stops a browser sniffing the body as anything
     *                             other than the JSON it is declared to be.
     *   Referrer-Policy           Licence keys can appear in a request URL; this
     *                             keeps them out of the Referer header on any
     *                             onward navigation.
     *
     * The body is emitted even when headers have already been sent - by a PHP
     * warning, or by the surrounding WHMCS bootstrap - because a client waiting
     * on JSON is better served by a body with the wrong status than by nothing.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->toJson();
    }
}
