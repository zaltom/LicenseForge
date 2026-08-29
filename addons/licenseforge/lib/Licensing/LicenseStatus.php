<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Lang;

/**
 * The licence lifecycle: the states a licence can be in, and the moves between
 * them.
 *
 * Seven states rather than a usable/unusable flag, because the distinctions
 * matter to everyone who sees them: a customer whose licence is suspended has a
 * different problem from one whose licence expired, and staff take different
 * action for each.
 *
 * Exactly one state is usable - {@see ACTIVE}. Everything else refuses, and the
 * specific state determines the error the caller is told.
 *
 * The state itself is only half of enforcement. An installation holding a
 * signed offline token is not asking the server anything, so a status change
 * alone does not reach it - see the offline hold in {@see LicenseManager}.
 */
final class LicenseStatus
{
    /*
     * The stored values. These appear in the database and in API responses, so
     * they are part of the contract and must not be renamed.
     *
     *   pending     Issued but not yet in force - provisioning incomplete, or
     *               the service not yet active.
     *   active      The only usable state.
     *   suspended   Temporarily withdrawn and expected to return. Usually
     *               mirrors a suspended service.
     *   expired     Its term ended. Distinct from suspended because the remedy
     *               is renewal rather than a support conversation.
     *   revoked     Withdrawn deliberately and permanently.
     *   terminated  Ended with its service.
     *   reissued    The key was replaced; this record is history.
     */
    public const PENDING    = 'pending';
    public const ACTIVE     = 'active';
    public const SUSPENDED  = 'suspended';
    public const EXPIRED    = 'expired';
    public const REVOKED    = 'revoked';
    public const TERMINATED = 'terminated';
    public const REISSUED   = 'reissued';

    /**
     * The states with their display names.
     *
     * Translated through the language file, so a state reads in the
     * installation's language wherever it is shown. Also the single source of
     * which states exist - {@see all()} and {@see exists()} both derive from it,
     * so a state cannot be valid but unnamed.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::PENDING    => Lang::get('status_pending', 'Pending'),
            self::ACTIVE     => Lang::get('status_active', 'Active'),
            self::SUSPENDED  => Lang::get('status_suspended', 'Suspended'),
            self::EXPIRED    => Lang::get('status_expired', 'Expired'),
            self::REVOKED    => Lang::get('status_revoked', 'Revoked'),
            self::TERMINATED => Lang::get('status_terminated', 'Terminated'),
            self::REISSUED   => Lang::get('status_reissued', 'Reissued'),
        ];
    }

    /**
     * Every valid state.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    /**
     * The display name for a state.
     *
     * An unrecognised value is title-cased rather than replaced or hidden, so a
     * state written directly into the database still renders as something
     * legible instead of a blank cell.
     */
    public static function label(string $status): string
    {
        return self::labels()[$status] ?? ucfirst($status);
    }

    /**
     * Is this a state the module recognises?
     *
     * Used to validate anything arriving from a form or a settings mapping, so
     * an unknown value is refused at the edge rather than stored.
     */
    public static function exists(string $status): bool
    {
        return isset(self::labels()[$status]);
    }

    /**
     * The states under which a licence actually works.
     *
     * A list rather than a comparison against ACTIVE, so the definition lives in
     * one place. Grace periods and holds are handled elsewhere and deliberately
     * do not widen this.
     *
     * @return list<string>
     */
    public static function usable(): array
    {
        return [self::ACTIVE];
    }

    /** Does a licence in this state entitle its holder to anything? */
    public static function isUsable(string $status): bool
    {
        return in_array($status, self::usable(), true);
    }

    /**
     * Is this state the end of the licence's life?
     *
     * Revoked and terminated licences cannot be reissued: reissuing frees a
     * licence to be used elsewhere, which is what these two states exist to
     * prevent. They can still be reactivated by an administrator - see
     * {@see transitions()} - because a mistaken revocation must be recoverable.
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::REVOKED, self::TERMINATED], true);
    }

    /**
     * Which states each state may move to.
     *
     * Enforced on every status change, so a licence cannot be driven into a
     * sequence the rest of the module does not expect - and the admin form
     * offers only the moves that are permitted rather than presenting a choice
     * that will then be refused.
     *
     * Two entries deserve note. Revoked and terminated lead only back to active:
     * an administrator can undo them, but cannot move a dead licence sideways
     * into suspended or expired, which would assert a history that did not
     * happen. And reissued behaves like a live state because it is one - the
     * licence still exists, it simply carries a new key.
     *
     * @return array<string,list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::PENDING    => [self::ACTIVE, self::SUSPENDED, self::REVOKED, self::TERMINATED, self::EXPIRED],
            self::ACTIVE     => [self::SUSPENDED, self::EXPIRED, self::REVOKED, self::TERMINATED, self::REISSUED],
            self::SUSPENDED  => [self::ACTIVE, self::EXPIRED, self::REVOKED, self::TERMINATED],
            self::EXPIRED    => [self::ACTIVE, self::REVOKED, self::TERMINATED],
            self::REISSUED   => [self::ACTIVE, self::SUSPENDED, self::REVOKED, self::TERMINATED],
            self::REVOKED    => [self::ACTIVE],
            self::TERMINATED => [self::ACTIVE],
        ];
    }

    /**
     * May a licence move from one state to another?
     *
     * A move to the same state is allowed and is a no-op, so a caller
     * re-asserting the current state - a service sync repeating itself, a form
     * submitted twice - is not treated as an error.
     *
     * Unknown states on either side are refused rather than passed through.
     */
    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        if (!self::exists($from) || !self::exists($to)) {
            return false;
        }

        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * The Bootstrap contextual class for a state.
     *
     * Used in the client area, which is rendered by the installation's own theme
     * and therefore has to speak Bootstrap's vocabulary. Its four colours cannot
     * express seven states - suspended and expired share one, as do revoked and
     * terminated - which is why the admin console uses {@see tone()} instead.
     */
    public static function badgeClass(string $status): string
    {
        switch ($status) {
            case self::ACTIVE:
                return 'success';
            case self::PENDING:
                return 'info';
            case self::SUSPENDED:
            case self::EXPIRED:
                return 'warning';
            case self::REVOKED:
            case self::TERMINATED:
                return 'danger';
            default:
                return 'default';
        }
    }

    /**
     * The admin console's own colour name for a state.
     *
     * One tone per state, unlike {@see badgeClass()}, so suspended and expired
     * are visually distinct - staff scan these lists all day and the difference
     * decides what they do next.
     *
     * Returns an empty string for an unrecognised state, which renders as the
     * neutral default rather than miscolouring it as something meaningful.
     */
    public static function tone(string $status): string
    {
        switch ($status) {
            case self::ACTIVE:
                return 'ok';
            case self::PENDING:
                return 'wait';
            case self::SUSPENDED:
                return 'warn';
            case self::EXPIRED:
                return 'lapsed';
            case self::REVOKED:
                return 'bad';
            case self::TERMINATED:
                return 'done';
            case self::REISSUED:
                return 'past';
            default:
                return '';
        }
    }
}
