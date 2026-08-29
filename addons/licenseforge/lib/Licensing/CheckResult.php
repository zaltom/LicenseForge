<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Api\ErrorCodes;

/**
 * The outcome of a licensing decision.
 *
 * Every check in this namespace returns one of these rather than a boolean or
 * an exception. A licensing refusal is an ordinary, expected answer - an
 * expired licence, an exhausted activation limit, a domain that does not match
 * - and the caller almost always needs to know why in order to say something
 * useful, so the reason travels with the verdict instead of being thrown away
 * or raised.
 *
 * Failures carry an {@see ErrorCodes} constant, which is what the API returns
 * and what client software branches on, plus a message for a human. Both
 * success and failure can carry arbitrary data: the licence and activation rows
 * on the way out, or the specifics a client can act on.
 *
 * Immutable. Constructed through {@see ok()} or {@see fail()}, and
 * {@see with()} returns a copy, so a result passed up through several layers
 * cannot be altered by any of them on the way.
 */
final class CheckResult
{
    private bool $ok;
    private ?string $code;
    private ?string $message;

    /** @var array<string,mixed> Context for the caller: rows, counts, deadlines. */
    private array $data;

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(bool $ok, ?string $code, ?string $message, array $data)
    {
        $this->ok      = $ok;
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    /**
     * A successful outcome, optionally carrying the rows it resolved.
     *
     * @param array<string,mixed> $data
     */
    public static function ok(array $data = []): self
    {
        return new self(true, null, null, $data);
    }

    /**
     * A refusal, with the reason.
     *
     * The message defaults to whatever {@see ErrorCodes} registers for the code,
     * so the same refusal reads identically wherever it is produced. Overriding
     * it is for cases where the code is right but more can usefully be said.
     *
     * @param string              $code    An ErrorCodes constant.
     * @param string|null         $message Overrides the registered message.
     * @param array<string,mixed> $data    Detail the caller can act on.
     */
    public static function fail(string $code, ?string $message = null, array $data = []): self
    {
        return new self(false, $code, $message ?? ErrorCodes::message($code), $data);
    }

    /** Did the check pass? */
    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * Did the check fail?
     *
     * The inverse of {@see isOk()}, provided because most call sites are guard
     * clauses and reading `if ($result->failed())` is clearer than negating.
     */
    public function failed(): bool
    {
        return !$this->ok;
    }

    /** The ErrorCodes constant for a refusal, or null on success. */
    public function code(): ?string
    {
        return $this->code;
    }

    /** The human-readable reason for a refusal, or null on success. */
    public function message(): ?string
    {
        return $this->message;
    }

    /** @return array<string,mixed> Everything the result carries. */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * One value from the result's data.
     *
     * Callers must check the type of what comes back rather than assuming: a key
     * is present only on the paths that set it, so an absent one is normal
     * rather than an error.
     *
     * @param  mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * A copy of this result carrying additional data.
     *
     * Used where an outer layer knows something the inner check did not - most
     * often attaching the licence row to a refusal produced before it was
     * resolved, so the caller can still report which licence was refused.
     *
     * @param array<string,mixed> $data Merged over the existing data.
     */
    public function with(array $data): self
    {
        return new self($this->ok, $this->code, $this->message, array_merge($this->data, $data));
    }

    /**
     * The HTTP status this outcome corresponds to.
     *
     * Success is always 200; a refusal takes whatever {@see ErrorCodes}
     * registers for its code, so the status and the code cannot disagree.
     */
    public function httpStatus(): int
    {
        return $this->ok ? 200 : ErrorCodes::httpStatus((string) $this->code);
    }
}
