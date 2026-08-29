<?php

declare(strict_types=1);

namespace LicenseForge\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Database access for the module.
 *
 * A thin wrapper over the Eloquent capsule WHMCS has already booted, so the
 * module shares WHMCS's configured connection rather than opening its own.
 * Connection and credential handling are complete before any of this runs.
 *
 * The wrapper exists to centralise two invariants: every module table name is
 * prefixed via {@see name()}, so the module cannot collide with WHMCS's own
 * tables; and every timestamp is UTC via {@see now()}. WHMCS's tables carry no
 * prefix of ours and are reached through {@see connection()} directly.
 */
final class Db
{
    /**
     * A query builder for one of the module's tables, with the prefix applied.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function table(string $table)
    {
        return Capsule::table(self::name($table));
    }

    /**
     * The full, prefixed table name.
     *
     * Needed directly only where a builder cannot be used - schema operations
     * and the few raw statements.
     */
    public static function name(string $table): string
    {
        return LICENSEFORGE_TABLE_PREFIX . $table;
    }

    /**
     * The schema builder, for migrations.
     *
     * Unlike {@see table()}, names are not prefixed automatically here; callers
     * must wrap them in {@see name()}.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    public static function schema()
    {
        return Capsule::schema();
    }

    /**
     * The underlying connection, for WHMCS's own (unprefixed) tables and for
     * raw statements the query builder cannot express.
     *
     * @return \Illuminate\Database\Connection
     */
    public static function connection()
    {
        return Capsule::connection();
    }

    /**
     * Run a callback inside a database transaction.
     *
     * Used wherever several writes must land together - allocating an
     * activation slot, reissuing a licence. Combined with `lockForUpdate()`
     * inside the callback, this is how concurrent requests against the same
     * licence are serialised.
     *
     * Gives no atomicity over schema changes: MySQL commits DDL implicitly,
     * which is why the migrations rely on idempotence instead.
     *
     * @return mixed Whatever the callback returns.
     */
    public static function transaction(callable $callback)
    {
        return Capsule::connection()->transaction($callback);
    }

    /**
     * A raw SQL fragment for a query builder.
     *
     * Not escaped or parameterised, so it must never carry a caller-supplied
     * value. Every use in this module is a fixed fragment such as an aggregate
     * or a column-relative increment.
     *
     * @return \Illuminate\Database\Query\Expression
     */
    public static function raw(string $expression)
    {
        return Capsule::raw($expression);
    }

    /**
     * Does one of the module's tables exist?
     *
     * The guard that keeps migrations idempotent and lets code tolerate a
     * partial upgrade rather than raising on a table that does not exist yet.
     */
    public static function hasTable(string $table): bool
    {
        return self::schema()->hasTable(self::name($table));
    }

    /**
     * Does a column exist on one of the module's tables?
     *
     * The other half of migration idempotence: a migration that adds a column
     * checks first and returns early, so re-running is harmless.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        return self::schema()->hasColumn(self::name($table), $column);
    }

    /**
     * The current time as a MySQL datetime, in UTC.
     *
     * Every timestamp the module writes goes through here. Licence expiry,
     * grace periods and rate-limit windows are compared across requests and
     * sometimes across servers, so a stored local time would drift whenever
     * either changed. Stored values carry no zone, so anything parsing one back
     * must supply UTC explicitly rather than letting PHP apply the server's.
     */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
