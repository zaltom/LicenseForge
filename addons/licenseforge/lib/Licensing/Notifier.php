<?php

declare(strict_types=1);

namespace LicenseForge\Licensing;

use LicenseForge\Support\Audit;
use LicenseForge\Support\Db;
use LicenseForge\Support\Settings;

/**
 * Sends the module's customer emails.
 *
 * Delivery is delegated to WHMCS rather than reimplemented. Emails are WHMCS
 * product templates sent through its API, so they inherit the installation's
 * sending configuration, branding, language and delivery log - and a vendor
 * edits them in the same place as every other email their customers receive.
 *
 * Two properties matter more than the sending itself.
 *
 * Nothing here can be sent twice. Anything triggered by a condition rather than
 * an event - an expiry reminder fires because a date is approaching, and the
 * cron may run hourly - claims a row before sending, so the condition being true
 * repeatedly does not mail the customer repeatedly. See {@see claim()}.
 *
 * Nothing here can fail a licensing operation. Every send returns a boolean and
 * logs its own failure; no caller is expected to handle it. A licence must be
 * issued, suspended or reissued whether or not the customer could be emailed
 * about it.
 */
final class Notifier
{
    /**
     * Emails still permitted this run, or null when no ceiling applies.
     *
     * Null is the default because a ceiling only makes sense for batch work. A
     * customer activating their software should never have their confirmation
     * withheld because a cron run earlier in the hour was busy - the cap belongs
     * to the sweep, not to the request path.
     *
     * @var int|null
     */
    private static ?int $budget = null;

    /** Messages refused by the ceiling this run; unclaimed, so they go next time. */
    private static int $deferred = 0;

    /**
     * Apply a ceiling for the current run.
     *
     * Called by {@see Maintenance::run()} around the sweeps. Passing null lifts
     * it again.
     */
    public static function budget(?int $limit): void
    {
        self::$budget   = $limit === null ? null : max(0, $limit);
        self::$deferred = 0;
    }

    /** How many messages the ceiling held back, for the run's report. */
    public static function deferred(): int
    {
        return self::$deferred;
    }

    /**
     * Send one licensing email through WHMCS.
     *
     * The template is named indirectly through a setting, so a vendor can point
     * any of these at a template of their own - or at nothing, which disables
     * that message.
     *
     * A licence with no service cannot be emailed about: WHMCS addresses a
     * product email by service, not by client. That is logged rather than
     * silently ignored, because it means a customer is missing notifications
     * they should be getting.
     *
     * The dedupe claim is taken before sending, not after. The wrong order would
     * let two concurrent runs both pass the check and both send.
     *
     * localAPI is checked for rather than assumed, since it is unavailable in
     * some execution contexts and calling it there is a fatal error rather than
     * a failed send.
     *
     * @param  string      $settingKey Setting naming the WHMCS template.
     * @param  array<string,mixed> $merge Extra merge fields for this message.
     * @param  string|null $dedupeKey Non-null makes the send once-only per licence.
     * @return bool        Whether the email was accepted for delivery.
     */
    private static function send(object $license, string $settingKey, array $merge = [], ?string $dedupeKey = null): bool
    {
        if (!Settings::bool('notify_enabled', true)) {
            return false;
        }

        $template  = trim((string) Settings::get($settingKey, ''));
        $clientId  = (int) $license->client_id;
        $serviceId = (int) $license->service_id;

        if ($template === '' || $clientId <= 0) {
            return false;
        }

        if ($serviceId <= 0) {
            error_log('[LicenseForge] license #' . (int) $license->id . ' has no service, so "' . $template . '" was not sent.');

            return false;
        }

        /*
         * The per-run ceiling, checked before the claim is taken.
         *
         * Order matters: a claim is what marks a notification as already sent,
         * so claiming and then declining to send would lose the message
         * permanently. Refusing first leaves it unclaimed and it goes out on the
         * next run.
         *
         * The ceiling exists because a mass state change is not rare - an annual
         * cohort reaching its expiry date puts every one of those licences
         * through here in a single cron pass. Dedupe bounds how often one
         * licence is emailed; nothing bounded how many licences, so the volume
         * was whatever the sweep happened to touch. On a shared host that is how
         * a domain gets rate-limited or blacklisted, and it fails silently:
         * localAPI returns an error, this returns false, and only the log knows.
         */
        if (self::$budget !== null) {
            if (self::$budget <= 0) {
                self::$deferred++;

                return false;
            }
            self::$budget--;
        }

        if ($dedupeKey !== null && !self::claim((int) $license->id, $dedupeKey)) {
            return false;
        }

        if (!function_exists('localAPI')) {
            return false;
        }

        try {
            $product = ProductConfig::find((int) $license->product_id);

            $response = localAPI('SendEmail', [
                'messagename' => $template,
                'id'          => $serviceId,
                'customvars'  => base64_encode(serialize(self::mergeFields($license, $merge))),
            ]);

            $ok = isset($response['result']) && $response['result'] === 'success';
            if (!$ok) {
                error_log('[LicenseForge] email send failed: ' . json_encode($response));
            }

            return $ok;
        } catch (\Throwable $e) {
            error_log('[LicenseForge] email send exception: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The merge fields available to every licensing email template.
     *
     * Every field is declared here, including the ones only some messages
     * populate - they default to an empty string rather than being absent. A
     * template referencing an undefined merge field renders the placeholder
     * literally, so a vendor who adds `{$abuse_signal}` to the wrong email gets
     * a blank rather than that text appearing in a customer's inbox.
     *
     * Extras override, but only when non-empty, so a caller passing a blank
     * cannot blank out a field the licence itself supplied.
     *
     * @param  array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function mergeFields(object $license, array $extra = []): array
    {
        try {
            $product = ProductConfig::find((int) $license->product_id);
        } catch (\Throwable $e) {
            $product = null;
        }

        $days = LicenseManager::daysUntilExpiry($license);

        $fields = [
            'license_key'         => (string) $license->license_key,
            'license_status'      => LicenseStatus::label((string) $license->status),
            'license_product'     => $product !== null ? (string) $product->name : '',
            'license_expires'     => self::formatDate($license->expires_at, (bool) $license->is_lifetime),
            'license_domain'      => (string) ($license->primary_domain ?? ''),
            'license_activations' => (int) $license->activation_count . ' of ' . (int) $license->max_activations,
            'license_activation_limit' => (int) $license->max_activations,
            'license_reissues'    => (int) $license->reissue_count . ' of ' . (int) $license->max_reissues,
            'license_version'     => (string) ($license->current_version ?? ''),

            // Populated only by the messages they belong to; declared here so an
            // unpopulated one renders blank rather than as a literal placeholder.
            'days_remaining'      => $days !== null ? max(0, $days) : '',
            'status_reason'       => '',
            'previous_key'        => '',
            'activation_domain'   => '',
            'activation_ip'       => '',
            'abuse_signal'        => '',
            'abuse_summary'       => '',
        ];

        foreach ($extra as $key => $value) {
            if ($value !== '' && $value !== null) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * A date for an email, or 'Never'.
     *
     * Lifetime licences and MySQL's zero date both render as 'Never' - one has
     * no expiry by design, the other has none recorded, and neither should reach
     * a customer as a date in year zero.
     *
     * @param mixed $value
     */
    private static function formatDate($value, bool $lifetime = false): string
    {
        if ($lifetime) {
            return 'Never';
        }

        $value = trim((string) ($value ?? ''));
        if ($value === '' || strncmp($value, '0000', 4) === 0) {
            return 'Never';
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? $value : gmdate('j F Y', $timestamp);
    }

    /**
     * Claim the right to send one notification, once.
     *
     * The claim is the unique index on (licence, key): the insert succeeds for
     * exactly one caller and fails for every other, so two cron runs overlapping
     * cannot both send. That is why failure is treated as "already sent" rather
     * than raised - a duplicate-key violation is the expected outcome here, not
     * an error.
     *
     * @return bool True if this caller may send.
     */
    private static function claim(int $licenseId, string $key): bool
    {
        try {
            Db::table('notifications')->insert([
                'license_id'       => $licenseId,
                'notification_key' => mb_substr($key, 0, 100),
                'sent_at'          => Db::now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Release notification claims so they can be sent again.
     *
     * Called when the situation that prompted them has genuinely changed - a
     * renewal, or a licence returning to active. Without this, a customer who
     * renews and later approaches expiry again would never be warned, because
     * the claim from the first cycle would still be held.
     *
     * The prefix narrows it to one family, so clearing expiry warnings does not
     * also re-send the licence-created email.
     */
    public static function resetNotifications(int $licenseId, string $prefix = ''): void
    {
        $query = Db::table('notifications')->where('license_id', $licenseId);
        if ($prefix !== '') {
            $query->where('notification_key', 'like', $prefix . '%');
        }
        $query->delete();
    }

    /**
     * Send a licensing email on demand, from the admin console.
     *
     * Deliberately takes no dedupe key: an administrator asking for an email
     * expects it to be sent, even if the same message went automatically
     * earlier.
     *
     * Audited either way, so a customer's claim that they received nothing can
     * be checked against what was actually attempted.
     */
    public static function sendNow(object $license, string $settingKey): bool
    {
        $merge = [];

        $days = LicenseManager::daysUntilExpiry($license);
        if ($days !== null) {
            $merge['days_remaining'] = max(0, $days);
        }

        $sent = self::send($license, $settingKey, $merge);

        Audit::log(
            'email.sent_manually',
            (int) $license->id,
            $sent ? Audit::RESULT_SUCCESS : Audit::RESULT_FAILURE,
            ['template' => $settingKey],
            Audit::ACTOR_ADMIN
        );

        return $sent;
    }

    /**
     * The licence has been issued - deliver the key.
     *
     * Sent once per licence. This is the email carrying the key itself, so a
     * duplicate would mean the key travelling again unnecessarily.
     */
    public static function licenseCreated(?object $license): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_license_created', [], 'created');
    }

    /**
     * The licence has been activated on an installation.
     *
     * Includes where it was activated, which is the point of the message: it is
     * what lets a customer notice an activation they did not perform.
     */
    public static function licenseActivated(?object $license, ?object $activation = null): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_license_activated', [
            'activation_domain' => $activation !== null ? (string) ($activation->domain ?? '') : '',
            'activation_ip'     => $activation !== null ? (string) ($activation->ip_address ?? '') : '',
        ]);
    }

    /**
     * The licence expires soon.
     *
     * Deduplicated per threshold rather than per licence, so a customer receives
     * the 30-day and 7-day warnings but not the same one repeatedly.
     */
    public static function licenseExpiring(?object $license, int $daysRemaining): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_license_expiring', [
            'days_remaining' => $daysRemaining,
        ], 'expiring:' . $daysRemaining);
    }

    /** The licence has expired. Keyed on the date, so a renewed-then-expired licence warns again. */
    public static function licenseExpired(?object $license): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_license_expired', [], 'expired:' . (string) $license->expires_at);
    }

    /**
     * The licence has been reissued, and may carry a new key.
     *
     * The previous key is included so the customer can recognise which licence
     * this concerns - theirs may be one of several, and the new key alone would
     * not identify it.
     */
    public static function licenseReissued(?object $license, string $oldKey = ''): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_license_reissued', [
            'previous_key' => $oldKey,
        ]);
    }

    /**
     * An activation was refused because the licence has no free slots.
     *
     * Addressed to the customer rather than the vendor: they are the one who can
     * resolve it, by releasing an installation or buying more.
     */
    public static function activationLimitReached(?object $license): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_activation_limit', [], 'limit:' . (int) $license->activation_count);
    }

    /**
     * An abuse signal has been raised against this licence.
     *
     * Carries the signal and its summary so the recipient can judge it - every
     * signal has an innocent explanation as well as a guilty one, and a message
     * without the evidence would be unactionable.
     */
    public static function suspiciousActivity(?object $license, string $signal, string $summary): void
    {
        if ($license === null) {
            return;
        }
        self::send($license, 'email_suspicious_activity', [
            'abuse_signal'  => $signal,
            'abuse_summary' => $summary,
        ], 'abuse:' . $signal);
    }

    /**
     * React to a licence changing status.
     *
     * Only the changes a customer needs to hear about produce an email. A return
     * to active sends nothing but clears the expiry claims, so the next approach
     * to expiry warns properly instead of being suppressed by claims from the
     * last cycle.
     *
     * @param string $from The previous status; a return to active is only
     *   meaningful relative to what it returned from.
     */
    public static function statusChanged(?object $license, string $from, string $to, string $reason = ''): void
    {
        if ($license === null) {
            return;
        }

        switch ($to) {
            case LicenseStatus::SUSPENDED:
                self::send($license, 'email_license_suspended', ['status_reason' => $reason]);
                break;
            case LicenseStatus::EXPIRED:
                self::licenseExpired($license);
                break;
            case LicenseStatus::ACTIVE:
                if ($from === LicenseStatus::EXPIRED || $from === LicenseStatus::SUSPENDED) {
                    self::resetNotifications((int) $license->id, 'expir');
                }
                break;
        }
    }

    /**
     * Warn customers whose licences expire soon.
     *
     * Thresholds are configurable, defaulting to 30, 14, 7 and 1 days.
     *
     * Each threshold selects licences expiring on that whole day rather than at
     * that instant, so a cron running at any hour finds them - an exact
     * comparison would only ever match licences expiring in the minute the cron
     * happened to run, and would miss almost everything.
     *
     * Only active, non-lifetime licences are considered: a suspended licence has
     * a more pressing problem than its expiry date, and a lifetime one has none.
     *
     * @return int Reminders sent.
     */
    public static function sendExpiryReminders(): int
    {
        $thresholds = array_filter(array_map('intval', explode(',', (string) Settings::get('notify_expiry_days', '30,14,7,1'))));
        if ($thresholds === []) {
            return 0;
        }

        $sent = 0;
        foreach ($thresholds as $days) {
            $windowStart = gmdate('Y-m-d 00:00:00', time() + ($days * 86400));
            $windowEnd   = gmdate('Y-m-d 23:59:59', time() + ($days * 86400));

            $licenses = Db::table('licenses')
                ->whereNull('deleted_at')
                ->where('status', LicenseStatus::ACTIVE)
                ->where('is_lifetime', 0)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [$windowStart, $windowEnd])
                ->get();

            foreach ($licenses as $license) {
                if (self::send($license, 'email_license_expiring', ['days_remaining' => $days], 'expiring:' . $days)) {
                    $sent++;
                    Audit::log('license.expiry_reminder', (int) $license->id, Audit::RESULT_SUCCESS, ['days' => $days], Audit::ACTOR_SYSTEM);
                }
            }
        }

        return $sent;
    }
}
