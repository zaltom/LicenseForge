<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\Net;
use LicenseForge\Support\Settings;

/**
 * Watches licensing traffic for patterns that suggest a licence is being shared
 * or attacked.
 *
 * Everything here is a signal, not a verdict. Each pattern has an innocent
 * explanation as well as a guilty one - a developer moving between staging
 * domains looks like a shared licence, a laptop on hotel wifi looks like a
 * cloned installation, a load-balanced deployment looks like both. So the
 * default response is to record the observation, flag the licence and tell the
 * vendor, leaving the judgement to a person.
 *
 * The one exception is automatic suspension, which is off by default and even
 * when enabled applies only to the single signal that is hard to explain away -
 * see {@see AUTO_SUSPENDABLE}.
 *
 * Detection runs on two paths. Live checks fire on the request that produced
 * the evidence ({@see onFailedValidation()}, {@see onActivation()},
 * {@see onReissue()}); population-wide checks run on the cron ({@see sweep()}),
 * where a query across every licence is affordable.
 *
 * Every threshold is configurable, because what counts as suspicious depends
 * entirely on the product. A hosting control panel licence legitimately sees
 * many domains; a desktop application does not.
 */
final class AbuseDetector
{
    /*
     * Severity, which orders the admin queue rather than triggering anything by
     * itself. High means the pattern is hard to explain innocently.
     */
    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    /**
     * Signals permitted to suspend a licence automatically.
     *
     * Deliberately one. Concurrent installations is a direct count of
     * installations checking in against the limit the customer bought - a
     * measurement rather than an inference, with few innocent explanations.
     *
     * The others are all inferences from behaviour and have ordinary causes.
     * Suspending a paying customer's software because they redeployed across a
     * few addresses is a worse outcome than briefly under-enforcing a limit, so
     * those signals only ever raise a flag.
     *
     * Even this one requires `abuse_auto_suspend`, which is off by default.
     */
    private const AUTO_SUSPENDABLE = ['concurrent_installations'];

    /**
     * Record an abuse observation, notify the vendor, and mark the licence.
     *
     * Deduplicated within the configured window: the same signal on the same
     * licence produces one open event however many times it is observed.
     * Without that, a licence checking in every few minutes would generate an
     * event per request and bury the queue it is meant to populate.
     *
     * Deduplication is against unresolved events, so once staff have dealt with
     * one, a recurrence raises a fresh event rather than being silently
     * swallowed as a duplicate of something already closed.
     *
     * Flagging the licence is what surfaces it in the admin list; the flag is
     * cleared automatically once every event on it is resolved - see
     * {@see resolve()}.
     *
     * @param int|null            $licenseId Null for signals not attributable to
     *   a licence, such as enumeration of keys that do not exist.
     * @param array<string,mixed> $metadata  Evidence, stored with the event.
     */
    public static function flag(
        ?int $licenseId,
        string $signal,
        string $summary,
        string $severity = self::SEVERITY_LOW,
        array $metadata = []
    ): bool {
        $windowStart = gmdate('Y-m-d H:i:s', time() - (Settings::int('abuse_window_hours', 24) * 3600));

        $duplicate = Db::table('abuse_events')
            ->where('signal', $signal)
            ->where('resolved', 0)
            ->where('created_at', '>=', $windowStart)
            ->when($licenseId !== null, static fn ($q) => $q->where('license_id', $licenseId))
            ->exists();

        if ($duplicate) {
            return false;
        }

        Db::table('abuse_events')->insert([
            'license_id' => $licenseId,
            'signal'     => mb_substr($signal, 0, 48),
            'severity'   => $severity,
            'summary'    => mb_substr($summary, 0, 500),
            'metadata'   => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'ip_address' => Net::clientIp(),
            'resolved'   => 0,
            'created_at' => Db::now(),
        ]);

        Audit::log('abuse.flagged', $licenseId, Audit::RESULT_FAILURE, [
            'signal' => $signal, 'severity' => $severity, 'summary' => $summary,
        ]);

        if ($licenseId !== null) {
            Db::table('licenses')->where('id', $licenseId)->update(['flagged' => 1]);

            $license = LicenseManager::find($licenseId);
            if ($license !== null) {
                Notifier::suspiciousActivity($license, $signal, $summary);

                // Three conditions, all required: the signal is one of the few
                // that can justify it, it is high severity, and the operator has
                // opted in.
                if ($severity === self::SEVERITY_HIGH
                    && in_array($signal, self::AUTO_SUSPENDABLE, true)
                    && Settings::bool('abuse_auto_suspend', false)) {
                    // Held as well as suspended, so the suspension also reaches
                    // installations running offline and no billing event undoes it.
                    if (LicenseManager::suspend($licenseId, 'automatic suspension: ' . $signal)) {
                        LicenseManager::hold($licenseId, 'abuse detected: ' . $signal, Audit::ACTOR_SYSTEM);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Examine a refused request for signs of an attack.
     *
     * Two distinct patterns, which need separating because they mean different
     * things.
     *
     * Repeated failures from one address suggest a broken integration as often
     * as an attack, so severity rises with volume rather than being fixed -
     * three times the threshold is no longer plausibly a misconfiguration.
     *
     * Many distinct unknown keys from one address is the other, and is not
     * ambiguous: a legitimate installation knows its own key and does not try
     * others. That is key enumeration, so it is high severity immediately and is
     * recorded against no licence, because none of the keys tried existed.
     */
    public static function onFailedValidation(LicenseRequest $request, ?object $license, string $errorCode): void
    {
        $windowHours = max(1, Settings::int('abuse_window_hours', 24));
        $since       = gmdate('Y-m-d H:i:s', time() - ($windowHours * 3600));
        $threshold   = Settings::int('abuse_failed_threshold', 15);

        if ($threshold <= 0) {
            return;
        }

        if ($request->observedIp !== '') {
            $failures = (int) Db::table('validations')
                ->where('ip_address', $request->observedIp)
                ->where('success', 0)
                ->where('created_at', '>=', $since)
                ->count();

            if ($failures >= $threshold) {
                self::flag(
                    $license !== null ? (int) $license->id : null,
                    'repeated_failures',
                    sprintf('%d failed licensing requests from %s in %dh.', $failures, $request->observedIp, $windowHours),
                    $failures >= $threshold * 3 ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM,
                    ['ip' => $request->observedIp, 'failures' => $failures, 'last_code' => $errorCode]
                );
            }
        }

        if ($license === null && $request->observedIp !== '') {
            $distinctKeys = (int) Db::table('validations')
                ->where('ip_address', $request->observedIp)
                ->whereNull('license_id')
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count('license_key_hash');

            // Floored at 5 so a low failure threshold cannot make this fire on a
            // couple of typos.
            if ($distinctKeys >= max(5, (int) ($threshold / 2))) {
                self::flag(
                    null,
                    'key_enumeration',
                    sprintf('%d distinct unknown license keys tried from %s.', $distinctKeys, $request->observedIp),
                    self::SEVERITY_HIGH,
                    ['ip' => $request->observedIp, 'distinct_keys' => $distinctKeys]
                );
            }
        }
    }

    /**
     * Examine a successful activation for signs of sharing.
     *
     * Activations well beyond the licence's own limit within the window are the
     * signal - three times the limit, rather than merely exceeding it, because
     * releasing and re-activating is a legitimate thing customers do while
     * moving servers.
     *
     * Note this counts activations created in the window, not slots in use. The
     * slot limit is enforced elsewhere and cannot be exceeded; what this catches
     * is a licence being cycled through many installations in turn while never
     * exceeding the count at any instant.
     */
    public static function onActivation(object $license, LicenseRequest $request): void
    {
        $windowHours = max(1, Settings::int('abuse_window_hours', 24));
        $since       = gmdate('Y-m-d H:i:s', time() - ($windowHours * 3600));

        $recentActivations = (int) Db::table('activations')
            ->where('license_id', (int) $license->id)
            ->where('first_activated_at', '>=', $since)
            ->count();

        $limit = max(1, (int) $license->max_activations);
        if ($recentActivations > $limit * 3) {
            self::flag(
                (int) $license->id,
                'excessive_activations',
                sprintf('%d activations in %dh against a limit of %d.', $recentActivations, $windowHours, $limit),
                self::SEVERITY_MEDIUM,
                ['recent' => $recentActivations, 'limit' => $limit]
            );
        }

        self::checkChurn($license, $windowHours, $since);
    }

    /**
     * Look for a licence moving between too many domains or addresses.
     *
     * Domain churn is the stronger signal and is rated high: a licence
     * legitimately bound to one deployment does not appear on five domains in a
     * day, and that pattern is what a resold or shared key looks like.
     *
     * Address churn is rated medium because the innocent explanations are common
     * - dynamic addressing, a CDN, a multi-region deployment, an office on a
     * rotating pool.
     *
     * Either threshold set to zero disables that check, for products where the
     * behaviour is expected.
     */
    private static function checkChurn(object $license, int $windowHours, string $since): void
    {
        $domainThreshold = Settings::int('abuse_domain_changes', 5);
        $ipThreshold     = Settings::int('abuse_ip_changes', 10);

        if ($domainThreshold > 0) {
            $domains = (int) Db::table('validations')
                ->where('license_id', (int) $license->id)
                ->where('created_at', '>=', $since)
                ->whereNotNull('domain')
                ->distinct()
                ->count('domain');

            if ($domains >= $domainThreshold) {
                self::flag(
                    (int) $license->id,
                    'rapid_domain_changes',
                    sprintf('License used on %d distinct domains in %dh.', $domains, $windowHours),
                    self::SEVERITY_HIGH,
                    ['distinct_domains' => $domains]
                );
            }
        }

        if ($ipThreshold > 0) {
            $ips = (int) Db::table('validations')
                ->where('license_id', (int) $license->id)
                ->where('created_at', '>=', $since)
                ->whereNotNull('ip_address')
                ->distinct()
                ->count('ip_address');

            if ($ips >= $ipThreshold) {
                self::flag(
                    (int) $license->id,
                    'rapid_ip_changes',
                    sprintf('License validated from %d distinct IP addresses in %dh.', $ips, $windowHours),
                    self::SEVERITY_MEDIUM,
                    ['distinct_ips' => $ips]
                );
            }
        }
    }

    /**
     * Note a licence being reissued unusually often.
     *
     * Reissuing is how a customer moves their software, and the quota and
     * cooldown already bound how often it can happen. Repeated use inside the
     * window suggests the licence is being passed around rather than moved,
     * which is worth a vendor's attention even though every individual reissue
     * was permitted.
     */
    public static function onReissue(object $license): void
    {
        $windowHours = max(1, Settings::int('abuse_window_hours', 24));
        $since       = gmdate('Y-m-d H:i:s', time() - ($windowHours * 3600));

        $recent = (int) Db::table('reissues')
            ->where('license_id', (int) $license->id)
            ->where('created_at', '>=', $since)
            ->count();

        if ($recent >= 3) {
            self::flag(
                (int) $license->id,
                'excessive_reissues',
                sprintf('%d reissues in %dh.', $recent, $windowHours),
                self::SEVERITY_MEDIUM,
                ['recent_reissues' => $recent]
            );
        }
    }

    /**
     * Find installations that appear to be running in more than one place.
     *
     * The signal is alternation, not variety. An installation that moves from
     * one address to another has moved; one whose requests alternate back and
     * forth between addresses is two copies sharing an identity, each checking
     * in from its own host.
     *
     * Only installations holding a per-installation secret are considered.
     * Without one, an installation id is merely a value the client chose and two
     * clients sharing it prove nothing - the pattern only means duplication once
     * the identity is something that had to be proved.
     *
     * Candidates are narrowed by the database to activations seen at more than
     * one address, so the per-activation analysis runs over a small set rather
     * than the whole validation log.
     *
     * @return int Installations flagged.
     */
    private static function checkClonedInstallations(): int
    {
        $threshold = Settings::int('abuse_install_ip_flips', 4);
        if ($threshold <= 0) {
            return 0;
        }

        $windowHours = max(1, Settings::int('abuse_window_hours', 24));
        $since       = gmdate('Y-m-d H:i:s', time() - ($windowHours * 3600));

        $candidates = Db::table('validations')
            ->select('activation_id')
            ->whereNotNull('activation_id')
            ->whereNotNull('ip_address')
            ->where('success', 1)
            ->where('created_at', '>=', $since)
            ->groupBy('activation_id')
            ->havingRaw('COUNT(DISTINCT ip_address) > 1')
            ->limit(500)
            ->get();

        $flagged = 0;

        foreach ($candidates as $candidate) {
            $activationId = (int) $candidate->activation_id;

            $activation = Db::table('activations')->where('id', $activationId)->first();
            if ($activation === null || (string) ($activation->install_secret ?? '') === '') {
                continue;
            }

            $addresses = array_map('strval', Db::table('validations')
                ->where('activation_id', $activationId)
                ->whereNotNull('ip_address')
                ->where('success', 1)
                ->where('created_at', '>=', $since)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('ip_address')
                ->all());

            $flips = self::countFlips($addresses);

            if ($flips < $threshold) {
                continue;
            }

            // Counted only when an event was actually raised. flag() suppresses a
            // duplicate that is still unresolved, so counting the call instead of
            // the outcome reported the same figure on every run for as long as the
            // events stayed open - which reads as a recurring incident rather than
            // one that has already been reported and is waiting to be dealt with.
            if (self::flag(
                (int) $activation->license_id,
                'cloned_installation',
                sprintf(
                    'Installation %s alternated between %d addresses %d times in %dh.',
                    (string) $activation->installation_id,
                    count(array_unique($addresses)),
                    $flips,
                    $windowHours
                ),
                self::SEVERITY_HIGH,
                [
                    'activation_id'   => $activationId,
                    'installation_id' => (string) $activation->installation_id,
                    'flips'           => $flips,
                ]
            )) {
                $flagged++;
            }
        }

        return $flagged;
    }

    /**
     * Count how many times consecutive requests came from different addresses.
     *
     * Distinguishes a move from a duplication. Two addresses seen once each is
     * one flip - a relocation. Two addresses seen alternately twenty times is
     * twenty flips, which nothing but two concurrent copies produces.
     *
     * @param list<string> $addresses In chronological order.
     */
    private static function countFlips(array $addresses): int
    {
        $flips    = 0;
        $previous = null;

        foreach ($addresses as $address) {
            if ($previous !== null && $address !== $previous) {
                $flips++;
            }
            $previous = $address;
        }

        return $flips;
    }

    /**
     * The scheduled population-wide pass.
     *
     * Two checks that cannot run on a single request: cloned installations,
     * which needs each installation's history; and concurrent installations,
     * which compares live check-ins against each licence's limit across every
     * licence at once.
     *
     * "Live" means checked in within the validation interval, not merely marked
     * active. An activation whose software was uninstalled without releasing it
     * stays active in the table indefinitely, and counting those would flag
     * honest customers for installations that no longer exist.
     *
     * This is the only signal that can suspend automatically, and only when the
     * operator has enabled it.
     *
     * @return int Licences and installations flagged.
     */
    public static function sweep(): int
    {
        $flagged = self::checkClonedInstallations();
        $cutoff  = gmdate('Y-m-d H:i:s', time() - (max(1, Settings::int('validation_interval_hours', 24)) * 3600));

        /*
         * The limit is joined in and the comparison made in SQL rather than read
         * back per licence. Grouping alone returns every licence with a recent
         * check-in, not only those over their limit, so looking each one up cost
         * a query per actively-licensed customer on every sweep.
         */
        $rows = Db::table('activations as a')
            ->join(Db::name('licenses') . ' as l', 'l.id', '=', 'a.license_id')
            ->select(Db::raw('a.license_id as license_id, COUNT(*) as live, l.max_activations as lim'))
            ->where('a.status', 'active')
            ->where('a.last_validated_at', '>=', $cutoff)
            ->whereNull('l.deleted_at')
            ->where('l.max_activations', '>', 0)
            ->groupBy('a.license_id', 'l.max_activations')
            ->havingRaw('COUNT(*) > l.max_activations')
            ->get();

        foreach ($rows as $row) {
            if (self::flag(
                (int) $row->license_id,
                'concurrent_installations',
                sprintf('%d installations checking in against a limit of %d.', (int) $row->live, (int) $row->lim),
                self::SEVERITY_HIGH,
                ['live' => (int) $row->live, 'limit' => (int) $row->lim]
            )) {
                $flagged++;
            }
        }

        return $flagged;
    }

    /**
     * Mark an event as dealt with.
     *
     * The licence's flag is cleared only once no unresolved events remain
     * against it, so resolving one of several does not make a licence look clean
     * while other concerns are still open.
     */
    public static function resolve(int $eventId, int $adminId): bool
    {
        $event = Db::table('abuse_events')->where('id', $eventId)->first();
        if ($event === null) {
            return false;
        }

        Db::table('abuse_events')->where('id', $eventId)->update([
            'resolved'    => 1,
            'resolved_by' => $adminId,
            'resolved_at' => Db::now(),
        ]);

        if ($event->license_id !== null) {
            $open = Db::table('abuse_events')
                ->where('license_id', (int) $event->license_id)
                ->where('resolved', 0)
                ->exists();
            if (!$open) {
                Db::table('licenses')->where('id', (int) $event->license_id)->update(['flagged' => 0]);
            }
        }

        Audit::log('abuse.resolved', $event->license_id !== null ? (int) $event->license_id : null, Audit::RESULT_SUCCESS, [
            'event_id' => $eventId,
        ]);

        return true;
    }

    /**
     * Unresolved events, most serious first.
     *
     * Ordered by severity before recency, so a high-severity event from
     * yesterday outranks a low-severity one from this morning - the queue is
     * read top-down and the ordering is what makes that safe.
     *
     * @return iterable<object>
     */
    public static function open(int $limit = 100)
    {
        return Db::table('abuse_events')
            ->where('resolved', 0)
            ->orderByRaw("FIELD(severity,'high','medium','low')")
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
