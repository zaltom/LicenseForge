<?php
/**
 * LicenseForge - public licensing API entry point.
 *
 * This is the only file in the module that customer software talks to. It is a
 * thin front controller: it boots WHMCS, takes a snapshot of the HTTP request,
 * enforces the two conditions that apply to every call regardless of endpoint
 * (transport security and a supported method), and hands the request to
 * {@see \LicenseForge\Api\Server}, which routes, authenticates and answers it.
 * No licensing logic lives here.
 *
 * URLs
 * ----
 * The endpoint may be named in the path or in a parameter; both forms are
 * equivalent, and both are covered by the request signature.
 *
 *   POST /modules/addons/licenseforge/api/index.php/license/activate
 *   POST /modules/addons/licenseforge/api/?action=check
 *
 * Requests
 * --------
 * POST only, with parameters in the body as JSON or form encoding. Parameters
 * are never read from the query string: the signature covers a hash of the
 * body, so anything outside it would be authenticated by nothing.
 *
 * Callers authenticate with an HMAC-SHA256 signature over a canonical string,
 * carried in the `X-LF-Key`, `X-LF-Timestamp`, `X-LF-Nonce` and
 * `X-LF-Signature` headers. An installation additionally proves its own
 * identity with `X-LF-Install-Proof`.
 *
 * Responses
 * ---------
 * Always JSON, always with a `success` boolean; failures carry an `error`
 * object holding a stable machine-readable `code` and a human-readable
 * `message`. Internal detail is written to the PHP error log and never
 * returned to the caller.
 *
 * The full endpoint list, parameters and error codes are in the manual that
 * ships with this module: documentation/index.html.
 *
 * @see \LicenseForge\Api\Server    Routing, rate limiting and the licensing decision
 * @see \LicenseForge\Api\Auth      The signing scheme and its headers
 * @see \LicenseForge\Api\ErrorCodes Every code this API can return
 * @see \LicenseForge\Api\Request   How the request snapshot is built
 */

declare(strict_types=1);

use LicenseForge\Api\ErrorCodes;
use LicenseForge\Api\Request;
use LicenseForge\Api\Response;
use LicenseForge\Api\Server;

/*
 * Locate the WHMCS installation.
 *
 * This file sits four levels below the WHMCS root
 * (whmcs/modules/addons/licenseforge/api). WHMCS supplies the configured
 * database connection the whole module runs on, so if it cannot be found there
 * is nothing to serve, answered as a service outage rather than an error,
 * because it says nothing about the caller's request.
 */
$whmcsRoot = dirname(__DIR__, 4);
if (!is_file($whmcsRoot . '/init.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'The licensing service is temporarily unavailable.'],
    ]);
    exit;
}

/*
 * Boot WHMCS, then the module.
 *
 * init.php establishes the database connection and the WHMCS runtime;
 * bootstrap.php registers the module's autoloader and constants. The order
 * matters the module's classes expect WHMCS to already be available.
 */
require_once $whmcsRoot . '/init.php';
require_once dirname(__DIR__) . '/bootstrap.php';

/*
 * Keep diagnostics out of the response body.
 *
 * Every reply is JSON, and a warning or notice printed before it would both
 * corrupt the document and disclose server paths. Errors are still reported at
 * full strength so they reach the PHP error log; they are simply not displayed.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

/*
 * Answer CORS and capability preflights without doing any work.
 *
 * An OPTIONS request carries no body to sign, so it cannot be authenticated and
 * must not be routed. It is answered with the methods this endpoint accepts.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, POST, OPTIONS');
    exit;
}

/*
 * Take an immutable snapshot of the request.
 *
 * Reads the method, endpoint, headers, raw body and resolved client IP once, so
 * every later decision sees the same values. In particular the raw body is
 * captured verbatim, because the signature is computed over a hash of exactly
 * these bytes.
 */
$request = Request::capture();

/*
 * Require TLS.
 *
 * Licensing traffic carries licence keys and signatures, both of which are
 * replayable by anyone who can read them. Loopback is exempt so local
 * development and health checks work over plain HTTP.
 *
 * The exemption deliberately tests peerIp(), the address actually connected,
 * rather than clientIp(), which honours forwarded headers behind a trusted
 * proxy. Using the latter would let a caller claim to be loopback and skip the
 * requirement. An unreadable REMOTE_ADDR yields an empty string, which matches
 * neither loopback address, so an unknown peer is required to use TLS rather
 * than excused from it.
 */
if (!$request->isSecure() && !in_array($request->peerIp(), ['127.0.0.1', '::1'], true)) {
    Response::error(
        ErrorCodes::INVALID_REQUEST,
        'The licensing API must be accessed over HTTPS.'
    )->send();
    exit;
}

/*
 * Hand off to the API server and send whatever it decides.
 *
 * Server::handle() routes the endpoint, applies rate limits, authenticates the
 * caller, makes the licensing decision and returns a Response. It catches its
 * own exceptions, so any failure past this point still leaves the caller with a
 * well-formed JSON error rather than a blank page.
 */
Server::handle($request)->send();
