<?php
/**
 * LicenseForge - standalone maintenance runner.
 * =============================================
 *
 * Runs licensing housekeeping from the command line: expiring licenses,
 * opening and closing grace periods, sending expiry reminders, sweeping for
 * abuse, releasing stale activations and pruning old log rows.
 *
 * -----------------------------------------------------------------------------
 * WHEN YOU NEED THIS
 * -----------------------------------------------------------------------------
 *
 * You may well not. The DailyCronJob hook already performs exactly this work on
 * WHMCS's own schedule, and for most installations once a day is enough.
 *
 * Reach for this script when a day is too coarse. The usual reason is a short
 * grace period: if a product grants two days of grace, a daily sweep can leave
 * a license running up to a day past the point it should have stopped, because
 * nothing looked at it in between. The same applies to expiry reminders - "7
 * days before expiry" is only accurate to within a day if it is checked daily.
 *
 * Running it alongside the daily hook is safe. Each task is idempotent: work
 * that has already been done is skipped rather than repeated, so a license is
 * not expired twice and a reminder is not sent twice.
 *
 * -----------------------------------------------------------------------------
 * USAGE
 * -----------------------------------------------------------------------------
 *
 *     php /path/to/whmcs/modules/addons/licenseforge/cron.php [options]
 *
 * Every fifteen minutes, quietly, from crontab:
 *
 *     0,15,30,45 * * * * php /path/to/whmcs/modules/addons/licenseforge/cron.php --quiet
 *
 * Options:
 *
 *     --task=<name>   Which work to do. Default `all`.
 *
 *                       all        everything below, in the correct order
 *                       expire     mark licenses past their expiry date
 *                       grace      open and close grace periods
 *                       reminders  send expiry reminder emails
 *                       abuse      sweep for key sharing and enumeration
 *                       cleanup    prune logs past their retention window
 *                       stale      release activations that stopped checking in
 *
 *     --quiet         Suppress progress output. Errors still go to stderr.
 *
 * Run the individual tasks only when you have a reason to - a heavy cleanup you
 * want on its own schedule, or reproducing one step while debugging. `all` is
 * not merely the sum of the others: it runs them in an order that matters, and
 * expiring a license before opening its grace period is not the same as doing
 * it the other way round.
 *
 * -----------------------------------------------------------------------------
 * EXIT STATUS
 * -----------------------------------------------------------------------------
 *
 *     0   completed
 *     1   refused to run, or a task threw
 *
 * cron mails the operator on non-zero exit, so a failure is visible without
 * anyone thinking to check. This is also why failures are never swallowed here.
 *
 * @package LicenseForge
 */

declare(strict_types=1);

use LicenseForge\Licensing\AbuseDetector;
use LicenseForge\Licensing\Maintenance;
use LicenseForge\Licensing\Notifier;
use LicenseForge\Support\Audit;

/*
 * CLI only.
 *
 * This script takes no authentication and runs maintenance that suspends
 * licenses and emails customers. Reachable over HTTP it would be an unguarded
 * endpoint anyone could trigger repeatedly, so the check is a refusal rather
 * than a warning.
 *
 * It is the first statement in the file for that reason: nothing else runs
 * before the caller has been established as local.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script may only be run from the command line.';
    exit(1);
}

/*
 * Locate WHMCS.
 *
 * The path is derived from this file's own location - three levels up from
 * modules/addons/licenseforge - rather than configured, so the script works
 * wherever the installation lives with nothing to set up. init.php is what
 * gives the module its database connection and WHMCS's own helpers.
 *
 * Checked before including, so a script run from a copied or moved directory
 * reports what it was looking for and where, instead of a fatal error about a
 * missing include.
 */
$whmcsRoot = dirname(__DIR__, 3);
if (!is_file($whmcsRoot . '/init.php')) {
    fwrite(STDERR, "Unable to locate the WHMCS installation (expected {$whmcsRoot}/init.php).\n");
    exit(1);
}

require_once $whmcsRoot . '/init.php';
require_once __DIR__ . '/bootstrap.php';

/*
 * Arguments. getopt() ignores anything it does not recognise, so a typo such as
 * `--tasks=expire` silently falls through to the `all` default rather than
 * erroring - worth knowing when a scheduled run does more than expected.
 */
$options = getopt('', ['task::', 'quiet']);
$task    = (string) ($options['task'] ?? 'all');
$quiet   = array_key_exists('quiet', $options);

/**
 * Write a timestamped progress line to stdout, unless --quiet was given.
 *
 * Timestamps are UTC and marked with a trailing Z. Cron output is usually read
 * long after the fact, often next to logs from other systems, and a bare local
 * time is ambiguous in a way that wastes time during an incident.
 *
 * Progress goes to stdout while errors go to stderr, so a crontab entry can
 * discard the former and still receive the latter.
 *
 * @param string $line The message to print.
 *
 * @return void
 */
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $line . PHP_EOL);
    }
};

$started = microtime(true);
$report  = [];

try {
    /*
     * Dispatch. Each task collects its counts into $report under a name that
     * describes what was done, so the output reads the same whether one task
     * ran or all of them.
     *
     * An unrecognised --task falls through to `all` rather than failing. A
     * scheduled job that does the full maintenance sweep is a better response
     * to a typo than one that silently does nothing at all until somebody
     * notices licenses are not expiring.
     */
    switch ($task) {
        case 'expire':
            $report['expired'] = Maintenance::processExpirations();
            break;
        case 'grace':
            // Started before ended: a license can begin and finish its grace
            // period within one interval on a short grace setting, and doing
            // these in the other order would leave it running an extra cycle.
            $report['grace_started'] = Maintenance::startGracePeriods();
            $report['grace_ended']   = Maintenance::endGracePeriods();
            break;
        case 'reminders':
            $report['reminders'] = Notifier::sendExpiryReminders();
            break;
        case 'abuse':
            $report['abuse_flagged'] = AbuseDetector::sweep();
            break;
        case 'cleanup':
            $report['cleanup'] = Maintenance::cleanup();
            break;
        case 'stale':
            $report['stale_released'] = Maintenance::releaseStaleActivations();
            break;
        case 'all':
        default:
            $report = Maintenance::run();
            break;
    }

    // Fixed-width labels so several runs stacked in a log file line up and can
    // be compared down the column.
    foreach ($report as $key => $value) {
        $say(str_pad((string) $key, 18) . (string) $value);
    }
    $say(sprintf('completed in %.2fs', microtime(true) - $started));

    exit(0);
} catch (\Throwable $e) {
    /*
     * Reported twice, deliberately, because the two audiences are different and
     * neither reliably sees the other's copy.
     *
     * stderr reaches whoever receives cron's mail, immediately. The audit entry
     * survives in the database for whoever investigates later - by which time
     * the cron mail has usually been deleted, and the only evidence that a
     * maintenance run failed at all is this row.
     *
     * Catching \Throwable rather than \Exception is intentional: a TypeError or
     * an Error is exactly the kind of failure that must not vanish into a
     * silent non-zero exit with no explanation of what broke.
     */
    fwrite(STDERR, 'LicenseForge maintenance failed: ' . $e->getMessage() . PHP_EOL);
    Audit::log('cron.failed', null, Audit::RESULT_FAILURE, ['error' => $e->getMessage()], Audit::ACTOR_SYSTEM);

    exit(1);
}
