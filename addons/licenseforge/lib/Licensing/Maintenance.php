<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Lang;
use LicenseForge\Support\Db;
use LicenseForge\Support\RateLimiter;
use LicenseForge\Support\Settings;

/**
 * Scheduled processing: everything time makes true rather than a request.
 *
 * A licence expires because a date passed, not because anyone asked. Nothing
 * would notice that on its own, so this runs on the cron and applies it -
 * expiries, grace periods, expiry reminders, abuse sweeps, abandoned reissue
 * claims, and log pruning.
 *
 * Every task is idempotent: running the cycle twice in a row produces the same
 * state as running it once, which makes a duplicated cron entry, a manual run
 * from the admin console, or a retry after a timeout harmless.
 *
 * Every task is also bounded - each processes at most a fixed number of rows
 * per run - so a backlog is worked through over successive runs rather than in
 * one pass that exhausts the time limit and completes nothing.
 *
 * The counts each task returns are what the run reports, and what
 * {@see describe()} turns into a sentence for whoever triggered it.
 */
final class Maintenance
{
    /**
     * Most licences one sweep will consider in a single run.
     *
     * A cap rather than a page: work left over is picked up by the next run,
     * which keeps a cron pass bounded on an installation with a large backlog
     * instead of running until the process is killed part-way.
     */
    private const BATCH_LICENSES = 1000;

    /** The same cap for activation sweeps, which do more work per row. */
    private const BATCH_ACTIVATIONS = 500;

    /**
     * Sweeps that filled their batch during this request.
     *
     * @var list<string>
     */
    private static array $saturated = [];

    /**
     * Record that a sweep took as many candidates as it was allowed.
     *
     * A full batch is the honest signal that work remains, and the only one
     * available cheaply. Counting the rows the query would return over-reports:
     * two of these sweeps skip candidates inside the loop - a product with
     * automatic expiry disabled, a licence still inside its grace period - and
     * those rows match the predicate on every run forever. A count would
     * therefore show a backlog that never clears and would train the operator to
     * ignore it.
     *
     * A short batch means the queue drained, however many rows were skipped.
     */
    private static function noteBatch(string $task, int $taken, int $limit): void
    {
        if ($taken >= $limit && !in_array($task, self::$saturated, true)) {
            self::$saturated[] = $task;
        }
    }

    /**
     * Did the last full cycle leave work behind?
     *
     * Persisted rather than returned, because the run happens on cron and the
     * question is asked later by the admin console, in a different request.
     * Cleared by the first cycle that drains everything, so it cannot stick on.
     */
    public static function hasBacklog(): bool
    {
        return Settings::get('maintenance_backlog', '') !== '';
    }

    /**
     * Which sweeps were still saturated at the end of the last full cycle.
     *
     * @return list<string>
     */
    public static function backlogTasks(): array
    {
        $stored = (string) (Settings::get('maintenance_backlog', '') ?? '');

        return $stored === '' ? [] : explode(',', $stored);
    }

    /**
     * Run the full maintenance cycle.
     *
     * Order matters in two places. The product policies are synced first, so
     * every task that follows reads the current grace periods and expiry rules
     * rather than yesterday's. And grace periods are started before they are
     * ended, so a licence that expired and whose grace has already elapsed is
     * handled completely within one run rather than waiting for the next.
     *
     * The whole report is written to the audit log, so a run that changed
     * nothing is still evidence the cron is alive - which is the failure that
     * otherwise goes unnoticed until licences stop expiring.
     *
     * @return array<string,int> Task name => rows processed.
     */
    public static function run(): array
    {
        $report = [];

        self::$saturated = [];

        /*
         * Cap outbound email for the duration of the sweeps.
         *
         * A batch that expires a thousand licences would otherwise attempt a
         * thousand emails in one pass, with nothing bounding the total - dedupe
         * limits repeats per licence, not the number of licences. Anything
         * refused here is left unclaimed and goes out on a later run, so the
         * ceiling delays mail rather than dropping it.
         */
        Notifier::budget(Settings::int('notify_max_per_run', 200));

        ProductConfig::syncAll();

        $report['expired']       = self::processExpirations();
        $report['grace_started'] = self::startGracePeriods();
        $report['grace_ended']   = self::endGracePeriods();
        $report['reminders']     = Notifier::sendExpiryReminders();
        $report['abuse_flagged'] = AbuseDetector::sweep();

        $report['reissue_stalled'] = ReissueService::sweepStalled();
        $report['cleanup']         = self::cleanup();

        // Reported so a run that held mail back says so, rather than leaving the
        // operator to infer it from a customer asking why they were not told.
        $deferredMail = Notifier::deferred();
        if ($deferredMail > 0) {
            $report['mail_deferred'] = $deferredMail;
        }
        Notifier::budget(null);

        /*
         * Recorded so the admin console can say so. Without this a capped run is
         * indistinguishable from a complete one - the operator sees the same
         * "1000 licences expired" each day and no indication that four thousand
         * more are still being served as active.
         *
         * Written on every cycle, including the empty string, so a run that
         * finally drains the queue clears the flag rather than leaving it set.
         */
        Settings::set('maintenance_backlog', implode(',', self::$saturated));

        Audit::log('cron.completed', null, Audit::RESULT_SUCCESS, $report + [
            'backlog' => self::$saturated,
        ], Audit::ACTOR_SYSTEM);

        return $report;
    }

    /**
     * Turn a report into a sentence for an administrator.
     *
     * Only tasks that actually changed something are mentioned. A run where
     * nothing was due is the normal case, and listing seven zeroes for it tells
     * nobody anything.
     *
     * Singular and plural are separate language keys rather than an appended
     * "s", so a translator can use whatever forms their language needs.
     *
     * A task with no registered phrase still reports, as a readable form of its
     * own name. That way a counter added later appears in the summary
     * immediately rather than silently vanishing from it.
     *
     * @param array<string,int> $report
     */
    public static function describe(array $report): string
    {
        $phrases = [
            'expired'       => ['maint_expired_one', 'maint_expired_many'],
            'grace_started' => ['maint_grace_started_one', 'maint_grace_started_many'],
            'grace_ended'   => ['maint_grace_ended_one', 'maint_grace_ended_many'],
            'reminders'     => ['maint_reminders_one', 'maint_reminders_many'],
            'abuse_flagged' => ['maint_abuse_one', 'maint_abuse_many'],
            'reissue_stalled' => ['maint_reissue_stalled_one', 'maint_reissue_stalled_many'],
            'cleanup'       => ['maint_cleanup_one', 'maint_cleanup_many'],
            'mail_deferred' => ['maint_mail_deferred_one', 'maint_mail_deferred_many'],
        ];

        $done = [];
        foreach ($report as $task => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            $done[] = isset($phrases[$task])
                ? Lang::get($phrases[$task][$count === 1 ? 0 : 1], '', ['count' => $count])

                : $count . ' ' . str_replace('_', ' ', (string) $task);
        }

        if ($done === []) {
            return Lang::get('maint_nothing_due', 'Nothing was due - every license is already up to date.');
        }

        return self::joinPhrases($done) . '.';
    }

    /**
     * Join phrases into a list reading "a, b and c".
     *
     * The conjunction comes from the language file rather than being hard-coded,
     * so the sentence still reads correctly once translated.
     *
     * @param list<string> $phrases
     */
    private static function joinPhrases(array $phrases): string
    {
        if (count($phrases) === 1) {
            return $phrases[0];
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases) . ' ' . Lang::get('maint_and', 'and') . ' ' . $last;
    }

    /**
     * Expire licences whose term has ended.
     *
     * Two conditions spare a licence that has technically passed its date. The
     * product's policy may disable automatic expiry, leaving the decision to
     * staff; and a licence inside its grace period is left alone, since that
     * period exists precisely to postpone this.
     *
     * Lifetime licences are excluded by the query rather than checked per row -
     * they have no expiry date to have passed.
     *
     * @return int Licences expired.
     */
    public static function processExpirations(): int
    {
        $now       = Db::now();
        $processed = 0;

        $licenses = Db::table('licenses')
            ->whereNull('deleted_at')
            ->where('is_lifetime', 0)
            ->whereIn('status', [LicenseStatus::ACTIVE, LicenseStatus::PENDING, LicenseStatus::REISSUED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->limit(self::BATCH_LICENSES)
            ->get();

        self::noteBatch('expired', count($licenses), self::BATCH_LICENSES);

        foreach ($licenses as $license) {
            $product = ProductConfig::find((int) $license->product_id);
            $policy  = ProductConfig::policy($product);
            if (!$policy['auto_expire']) {
                continue;
            }
            if (LicenseManager::inGracePeriod($license)) {
                continue;
            }

            if (LicenseManager::setStatus((int) $license->id, LicenseStatus::EXPIRED, 'automatic expiry', ['automatic' => true])) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Open a grace period for licences that have just expired.
     *
     * The window runs from the expiry date, not from when this happened to
     * notice, so a cron that did not run for two days does not hand out two
     * extra days of grace.
     *
     * Only licences that are still active and have no window yet are considered,
     * so a grace period is opened once and never extended by a later run.
     *
     * @return int Grace periods started.
     */
    public static function startGracePeriods(): int
    {
        $started = 0;
        $now     = Db::now();

        $licenses = Db::table('licenses')
            ->whereNull('deleted_at')
            ->where('is_lifetime', 0)
            ->where('status', LicenseStatus::ACTIVE)
            ->whereNull('grace_until')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->limit(self::BATCH_LICENSES)
            ->get();

        self::noteBatch('grace_started', count($licenses), self::BATCH_LICENSES);

        foreach ($licenses as $license) {
            $policy = ProductConfig::policyForLicense($license);
            $days   = (int) $policy['grace_days'];
            if ($days <= 0) {
                continue;
            }
            $expiry = strtotime((string) $license->expires_at . ' UTC');
            if ($expiry === false) {
                continue;
            }

            Db::table('licenses')->where('id', (int) $license->id)->update([
                'grace_until' => gmdate('Y-m-d H:i:s', $expiry + ($days * 86400)),
                'updated_at'  => Db::now(),
            ]);
            Audit::log('license.grace_started', (int) $license->id, Audit::RESULT_SUCCESS, ['days' => $days], Audit::ACTOR_SYSTEM);
            $started++;
        }

        return $started;
    }

    /**
     * Expire licences whose grace period has run out.
     *
     * Separate from {@see processExpirations()} because the two answer different
     * questions - one is "the term ended", the other "the reprieve ended" - and
     * the audit trail records which of them applied.
     *
     * @return int Licences expired at the end of grace.
     */
    public static function endGracePeriods(): int
    {
        $ended = 0;

        $licenses = Db::table('licenses')
            ->whereNull('deleted_at')
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', Db::now())
            ->whereIn('status', [LicenseStatus::ACTIVE, LicenseStatus::REISSUED])
            ->limit(self::BATCH_LICENSES)
            ->get();

        self::noteBatch('grace_ended', count($licenses), self::BATCH_LICENSES);

        foreach ($licenses as $license) {
            if (LicenseManager::setStatus((int) $license->id, LicenseStatus::EXPIRED, 'grace period ended', ['automatic' => true])) {
                $ended++;
            }
        }

        return $ended;
    }

    /**
     * Prune what has outlived its usefulness.
     *
     * Retention differs by kind, and deliberately. The validation log is the
     * highest-volume table the module owns and is only interesting recently, so
     * it defaults to 90 days. The audit log records who did what and defaults to
     * two years, because that is the one people ask about long after the fact.
     * Rate limit counters and nonces are working state and go as soon as their
     * windows close. Resolved abuse events keep a year.
     *
     * Nothing here touches a licence, an activation or a reissue: those are the
     * record, and they are never pruned.
     *
     * @return int Rows removed across all of them.
     */
    public static function cleanup(): int
    {
        $removed = 0;

        $removed += ValidationService::pruneValidationLog(Settings::int('validation_log_retention', 90));
        $removed += Audit::prune(Settings::int('audit_log_retention', 730));
        $removed += RateLimiter::prune();
        $removed += RateLimiter::pruneNonces();

        $removed += (int) Db::table('abuse_events')
            ->where('resolved', 1)
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - (365 * 86400)))
            ->delete();

        return $removed;
    }

    /**
     * Free activation slots held by installations that stopped checking in.
     *
     * A customer who reinstalls a server without releasing the old installation
     * leaves a slot consumed by something that no longer exists. This reclaims
     * those, so the limit measures installations that are actually running.
     *
     * The default threshold is four times the offline validity window, the
     * shortest interval that cannot mistake a legitimately offline installation
     * for a dead one - such an installation stops calling home for the length of
     * its token and is entitled to.
     *
     * The cutoff is passed through to the release rather than only used to
     * select candidates. Between choosing the batch and acting on it an
     * installation may check in, and {@see ActivationService} re-checks under the
     * licence lock so the one that just proved it is alive is left alone.
     *
     * Not part of {@see run()}: reclaiming a slot is a judgement about a
     * customer's estate, so it is invoked deliberately rather than on a
     * schedule.
     *
     * @param  int $inactiveDays Override for the staleness threshold.
     * @return int Activations released.
     */
    public static function releaseStaleActivations(int $inactiveDays = 0): int
    {
        $inactiveDays = $inactiveDays > 0 ? $inactiveDays : (Settings::int('offline_validity_days', 7) * 4);
        if ($inactiveDays <= 0) {
            return 0;
        }

        $cutoff   = gmdate('Y-m-d H:i:s', time() - ($inactiveDays * 86400));
        $released = 0;

        $rows = Db::table('activations')
            ->where('status', 'active')
            ->whereNotNull('last_validated_at')
            ->where('last_validated_at', '<', $cutoff)
            ->limit(self::BATCH_ACTIVATIONS)
            ->get();

        foreach ($rows as $row) {
            if (ActivationService::release((int) $row->id, 'inactive for ' . $inactiveDays . ' days', $cutoff)) {
                $released++;
            }
        }

        return $released;
    }
}
