<?php
/**
 * LicenseForge - release download endpoint.
 * =========================================
 *
 *     GET /modules/addons/licenseforge/download.php?release=12
 *
 * Serves a release file to a signed-in client who currently holds a usable
 * licence for the product it belongs to.
 *
 * -----------------------------------------------------------------------------
 * WHY THIS EXISTS RATHER THAN WHMCS PRODUCT DOWNLOADS
 * -----------------------------------------------------------------------------
 *
 * WHMCS authorises its own download endpoint on service ownership, and offers
 * no hook between that request and the file being sent. There is therefore no
 * point at which licence state can be consulted.
 *
 * Hiding the link on the product page does not close that: it changes what is
 * *rendered*, not what is *served*. A customer whose licence was revoked for
 * non-payment - or anyone they passed the URL to - could still fetch the
 * software from a link saved while the licence was healthy.
 *
 * This endpoint re-checks entitlement on every request, so a revoked licence
 * stops working immediately rather than at the next page load.
 *
 * -----------------------------------------------------------------------------
 * HOW THE DECISION IS MADE
 * -----------------------------------------------------------------------------
 *
 * Nothing here decides anything. ReleaseService::authorise() does, and it says
 * yes only when all of the following hold:
 *
 *   1. A client is signed in.
 *   2. That client holds a licence for the release's product.
 *   3. The licence is currently usable - not expired, suspended or revoked.
 *   4. The file resolves inside the configured release directory and still
 *      matches what was recorded for it.
 *
 * Every path through this file is a refusal until that verdict comes back
 * positive. Adding a new way to reach the file means adding it to authorise(),
 * not here.
 *
 * -----------------------------------------------------------------------------
 * WHAT THE CALLER IS TOLD
 * -----------------------------------------------------------------------------
 *
 * Refusals are deliberately vague. A customer whose licence lapsed, a customer
 * who never had one, and a stranger walking release ids all get the same page.
 * Distinguishing them would confirm that a given release id exists and whether
 * a given account holds a licence for it - both useful to someone enumerating,
 * neither useful to a legitimate customer, whose next step is a support ticket
 * either way.
 *
 * The audit log records the real reason, so support can answer that ticket
 * without the response itself having to leak anything.
 *
 * @package LicenseForge
 */

declare(strict_types=1);

use LicenseForge\Licensing\ReleaseService;
use LicenseForge\Support\Input;
use LicenseForge\Support\Settings;

/*
 * Locate WHMCS. Derived from this file's position rather than configured, so
 * the endpoint works wherever the installation lives.
 *
 * The failure message here says nothing about paths. Unlike cron.php, which is
 * addressing an operator at a shell, this responds to the public web.
 */
$whmcsRoot = dirname(__DIR__, 3);
if (!is_file($whmcsRoot . '/init.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The download service is unavailable.';
    exit;
}

// init.php establishes the client session this endpoint authorises against;
// without it there is no $_SESSION['uid'] to check and every request would be
// anonymous.
require_once $whmcsRoot . '/init.php';
require_once __DIR__ . '/bootstrap.php';

/*
 * Errors are logged, never displayed.
 *
 * This response body is a binary file. A warning or notice printed into it is
 * not a cosmetic problem - it is prepended to the bytes the customer receives,
 * producing an archive that fails to open with no indication why. Reporting
 * stays at E_ALL so the error still reaches the log.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * Send a refusal page and stop.
 *
 * Every rejection in this file goes through here, so no path can accidentally
 * fall through to serving the file: the function exits rather than returning.
 *
 * The message deliberately does not identify which check failed - see the
 * header note. Callers pass a message chosen for what it tells the *customer*
 * about their next step, not for what it reveals about the system's state.
 *
 * @param int    $status  HTTP status code.
 * @param string $message Text shown to the visitor, escaped before output.
 *
 * @return void This function never returns; it exits.
 */
$refuse = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><meta charset="utf-8"><title>Download unavailable</title>'
        . '<p style="font:14px/1.5 system-ui,sans-serif;margin:3em">'
        . Input::e($message) . '</p>';
    exit;
};

// GET only. This endpoint has no side effects a POST would imply, and
// restricting the method removes a class of cross-origin request outright.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $refuse(405, 'This endpoint serves files over GET.');
}

// The module-wide kill switch. Turning licensing off for maintenance must also
// stop downloads, or the one thing licensing protects stays reachable while the
// checks are down. 503 rather than 403: this is temporary and not about the
// caller.
if (!Settings::bool('module_enabled', true)) {
    $refuse(503, 'Downloads are temporarily unavailable.');
}

$releaseId = Input::int('release');
$clientId  = (int) ($_SESSION['uid'] ?? 0);

// The single authorisation point. A signed-out visitor arrives here with
// $clientId of 0, which authorise() rejects; there is no separate early exit
// for that case, so anonymous requests are logged like any other denial.
$verdict = ReleaseService::authorise($releaseId, $clientId);

if (!$verdict['ok']) {
    // Recorded before responding, and before the branches below, so every
    // refusal is logged with its true reason regardless of which vague message
    // the visitor ends up seeing.
    ReleaseService::recordDenial($releaseId, $clientId, (string) $verdict['reason'], $verdict['license']);

    // Not signed in. Worth distinguishing because the fix is entirely in the
    // visitor's hands and telling them so reveals nothing - an anonymous
    // visitor learns only that the endpoint requires an account.
    if ($verdict['reason'] === ReleaseService::DENY_NO_CLIENT) {
        $refuse(403, 'Please sign in to your account to download this file.');
    }

    // A file that no longer matches what was recorded for it is a server-side
    // fault, not a judgement about this customer.
    //
    // Separated out because the generic message would send someone with a
    // perfectly valid licence to support to argue about entitlement, which
    // wastes their time and yours on a problem that is neither of your
    // making. It leaks nothing: this is the same answer everyone gets for that
    // release, entitled or not. The audit entry above carries the real reason.
    if ($verdict['reason'] === ReleaseService::DENY_ALTERED) {
        $refuse(503, 'This download is temporarily unavailable. Please contact support.');
    }

    // Everything else - no licence, expired, suspended, revoked, wrong product,
    // unknown release id - collapses to one message.
    $refuse(403, 'This download is not available on your account. If your licence has expired or been suspended, renew it and try again.');
}

/** @var object $release */
$release = $verdict['release'];

// Taken from the verdict, never from the request. authorise() resolved this
// path and confirmed it sits inside the configured release directory; using
// anything derived from user input here would discard that guarantee.
$path = (string) $verdict['path'];

$size = @filesize($path);
$name = basename($path);

// Reduce the filename to characters that cannot terminate or extend the
// Content-Disposition header. A filename containing a quote or a newline would
// otherwise let the stored path inject headers into the response.
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'download';

// Counted once the download is authorised and about to begin. A client that
// disconnects mid-transfer still counts, which is the honest reading: the file
// was released to them.
ReleaseService::recordDownload($release, $verdict['license']);

/*
 * Discard any output buffering WHMCS left open.
 *
 * init.php may have started one. Anything sitting in it is flushed ahead of the
 * file bytes, appending the archive to a partial HTML page - the same corrupted
 * download as a stray warning, from a different direction. Looped because
 * buffers nest.
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
// Never let a browser sniff a content type out of the bytes and decide to
// render this instead of saving it.
header('X-Content-Type-Options: nosniff');
// Licensed content must not be cached by a shared proxy, where the next person
// through could be served it without ever passing the checks above.
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
// Only when known and non-zero: an inaccurate Content-Length is worse than
// none, because the client trusts it and truncates or hangs accordingly.
if (is_int($size) && $size > 0) {
    header('Content-Length: ' . $size);
}

/*
 * Streamed in chunks rather than read whole.
 *
 * A release archive is precisely the kind of file that does not fit inside
 * memory_limit. readfile() or file_get_contents() on one either fails outright
 * or, worse, succeeds - and a handful of concurrent downloads then exhausts the
 * server's memory. Streaming holds 256KB at a time regardless of file size.
 */
$handle = @fopen($path, 'rb');
if ($handle === false) {
    $refuse(404, 'That file could not be read.');
}

// A large file over a slow connection legitimately outlasts max_execution_time,
// and being killed part-way produces a truncated archive the customer has no
// way to recognise as incomplete.
set_time_limit(0);
while (!feof($handle)) {
    $chunk = fread($handle, 262144);
    // A read error mid-file leaves the transfer short. Breaking is all that can
    // be done - the headers are already sent, so there is no way to turn this
    // into an error response now.
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    // Flushed each chunk so the bytes move as they are read rather than
    // accumulating in PHP's buffer, which would reintroduce the memory problem
    // the chunking exists to avoid.
    flush();
}
fclose($handle);
exit;
