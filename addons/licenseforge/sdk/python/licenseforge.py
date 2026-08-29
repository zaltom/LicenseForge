"""
LicenseForge client SDK for Python.
===================================

A single-module client for validating LicenseForge licenses from inside your
Python product. Drop it next to your code, or vendor it into your package.

---------------------------------------------------------------------------
QUICK START
---------------------------------------------------------------------------

    from licenseforge import LicenseClient

    lic = LicenseClient(
        license_server="https://billing.example.com/modules/addons/licenseforge/api/index.php",
        license_key=key_the_customer_entered,
        product_id="your-product-slug",
        api_key="lfk_...",
        api_secret="lfs_...",
        public_key="<base64 Ed25519 or PEM RSA public key from your server>",
        version="2.4.0",
        cache_file="~/.yourapp/license.cache",
    )

    # Run once, when the customer first enters their key.
    result = lic.activate()
    if not result.is_valid:
        sys.exit(result.error_message)

    # Run on every start. Cached, so this is normally just a file read.
    if not lic.check().is_valid:
        sys.exit("This copy is not licensed.")

There are only two calls you need: ``activate()`` once, and ``check()`` from
then on. Moving a license to a new machine, or releasing an activation slot, is
done by the customer from their product page in your client area.

---------------------------------------------------------------------------
DEPENDENCIES
---------------------------------------------------------------------------

The online path uses only the standard library, so your product gains no hard
dependency from this SDK.

Verifying a signed offline token needs real public-key cryptography, which the
standard library does not provide. That part is therefore pluggable:

  * ``cryptography`` is used automatically when it is installed;
  * anything else can be supplied through the ``verifier=`` argument.

Without either, offline validation always fails, and a network outage will stop
your customers' copies. Install ``cryptography`` unless you have a reason not
to. It is also what enables the per-installation keypair (see ``activate``).

---------------------------------------------------------------------------
HOW A DECISION IS REACHED
---------------------------------------------------------------------------

1. A cached answer, while it is younger than ``cache_ttl``.
2. The licensing server.
3. The signed offline token saved by the last successful call, used only when
   the server is unreachable, and only while its signature verifies, its key
   matches, the server-issued offline window is open, and every binding still
   holds.

Requests are signed with HMAC-SHA256 over a canonical string::

    METHOD \\n endpoint \\n timestamp \\n nonce \\n sha256hex(body)

There is no fail-open path anywhere in this file. When a signature cannot be
verified the result is invalid: an installation that cannot be *proven* licensed
is never treated as licensed.

---------------------------------------------------------------------------
ERRORS
---------------------------------------------------------------------------

Licensing outcomes, including refusals, are always returned as a
``LicenseResult``. A raised ``LicenseError`` means the SDK was configured
incorrectly and is a bug in your integration, not a customer-facing state.

---------------------------------------------------------------------------
REDISTRIBUTION
---------------------------------------------------------------------------

You may copy this file into software you license with LicenseForge, modify it
to suit your product, and distribute it to your own customers as part of that
software.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import ipaddress
import json
import os
import platform
import re
import socket
import time
import urllib.error
import urllib.request
import uuid
from typing import Any, Callable, Dict, List, Optional

__version__ = "1.0.0"

#: How far the system clock may read behind evidence this installation has
#: already seen before an offline answer is refused, in seconds.
#:
#: One day absorbs ordinary drift, a VM resuming with a stale clock, and NTP
#: stepping backwards. It does not absorb a deliberate rollback intended to
#: revive an expired token. See :meth:`LicenseClient._clock_rollback`.
CLOCK_TOLERANCE = 86400

#: The newest offline-token format this client understands.
#:
#: A token claiming a higher version is refused rather than interpreted,
#: because unknown fields would be read as "absent, so the older and looser
#: rule applies". See :meth:`LicenseClient._result_from_signed_cache`.
SUPPORTED_TOKEN_VERSION = 3

__all__ = ["LicenseClient", "LicenseResult", "LicenseError"]


class LicenseError(Exception):
    """
    Raised when the SDK itself is misconfigured.

    Never used to report a licensing decision: a refused, expired or
    unrecognised license always comes back as a :class:`LicenseResult` whose
    ``is_valid`` is False. If you see this exception, the fix is in your own
    integration code - a missing license key, a non-HTTPS server URL, a cache
    path under a web root - and it should surface during your testing rather
    than in a customer's log.
    """


class LicenseResult:
    """
    The outcome of an ``activate()`` or ``check()`` call.

    An immutable value object: inspect it, branch on it, discard it. It never
    raises. The two questions you will ask most often are::

        result.is_valid      may this copy run?
        result.error_code    if not, why not?

    :ivar bool valid: Whether the software may run. Prefer :attr:`is_valid`.
    :ivar dict data: The full decoded response, or the fields reconstructed
        from a verified offline token.
    :ivar str error_code: A stable machine-readable refusal code, or None.
        Branch on this rather than on ``error_message``, which is prose and may
        be reworded. Codes you should expect to handle:

        ``INVALID_LICENSE``
            the key is not recognised, or is not active
        ``LICENSE_EXPIRED``
            past its expiry and past any grace period
        ``ACTIVATION_NOT_FOUND``
            this installation has never been activated - call ``activate()``
        ``ACTIVATION_LIMIT``
            no free activation slots remain
        ``DOMAIN_MISMATCH`` / ``DIRECTORY_MISMATCH`` / ``MACHINE_MISMATCH``
            running somewhere the license is not bound to
        ``PRODUCT_MISMATCH``
            the key belongs to a different product
        ``VERSION_NOT_SUPPORTED``
            this software version is not covered by the license
        ``SIGNATURE_INVALID``
            the cached offline token could not be verified
        ``SERVICE_UNAVAILABLE``
            the server could not be reached and no valid offline token was
            available. This says nothing about the license itself - treat it as
            "unknown" and decide for your product whether that warrants a hard
            stop or a warning.

    :ivar str error_message: A human-readable explanation, safe to show the
        user verbatim, or None on success.
    :ivar str source: Where the answer came from - ``"remote"`` from the server,
        ``"cache"`` from a still-fresh earlier answer, ``"offline"`` from a
        locally verified token. A transport failure reports ``"offline"``, so
        callers can tell a refusal apart from an unreachable server.
    """

    def __init__(
        self,
        valid: bool,
        data: Optional[Dict[str, Any]] = None,
        error_code: Optional[str] = None,
        error_message: Optional[str] = None,
        source: str = "remote",
    ) -> None:
        """
        Build a result.

        You will not normally construct one of these yourself; the client does
        it for you. It is public so you can fabricate results in your own unit
        tests, or wrap the SDK behind your own interface.

        :param valid: Whether the software may run.
        :param data: The decoded response payload.
        :param error_code: Stable refusal code, or None.
        :param error_message: Readable refusal reason, or None.
        :param source: ``"remote"``, ``"cache"`` or ``"offline"``.
        """
        self.valid = bool(valid)
        self.data = data or {}
        self.error_code = error_code
        self.error_message = error_message
        self.source = source

    # -- convenience ------------------------------------------------------

    @property
    def is_valid(self) -> bool:
        """
        Whether this copy of the software is permitted to run.

        This is the single question the SDK exists to answer. True only when
        the license was positively proven valid - by the server, or by a signed
        offline token that passed every check. Anything else, including an
        unreachable server with no usable token, is False.

        :rtype: bool
        """
        return self.valid

    @property
    def license(self) -> Dict[str, Any]:
        """
        The license record: key, status, product, expiry, domain and features.

        Individual keys may be absent depending on how the product is
        configured, so guard your reads.

        :rtype: dict
        """
        payload = self.data.get("license")
        return payload if isinstance(payload, dict) else {}

    @property
    def status(self) -> str:
        """
        The license state as a lowercase word.

        One of ``"active"``, ``"expired"``, ``"suspended"``, ``"revoked"`` or
        ``"unknown"``. Useful for telling the customer what is wrong; use
        :attr:`error_code` when you need to branch in code.

        :rtype: str
        """
        return str(self.license.get("status", "unknown"))

    @property
    def features(self) -> List[str]:
        """
        The entitlement slugs granted to this license.

        Features are the mechanism for selling tiers or add-ons from one
        product: define slugs such as ``"premium-reports"`` on the server, and
        gate the matching code paths on them. Expired features are already
        filtered out for you, both online and offline.

        :rtype: list[str]
        """
        found = self.license.get("features", self.data.get("features", []))
        return [str(f) for f in found] if isinstance(found, list) else []

    def has_feature(self, slug: str) -> bool:
        """
        Whether a single entitlement is granted.

        :param slug: The feature slug, exactly as configured on the server.
        :rtype: bool
        """
        return slug in self.features

    @property
    def expires_at(self) -> Optional[str]:
        """
        When the license expires, as the raw server-supplied timestamp string.

        :return: The expiry, or None for a lifetime license.
        :rtype: str | None
        """
        value = self.license.get("expires_at")
        return str(value) if value else None

    @property
    def needs_activation(self) -> bool:
        """
        Whether this installation still needs to claim an activation slot.

        True when the key itself is fine but this particular copy has never
        been bound to it - a fresh install, or a restore onto a new machine.
        The correct response is to call :meth:`LicenseClient.activate`, or to
        prompt the customer to do so.

        :rtype: bool
        """
        if self.data.get("needs_activation"):
            return True
        return self.error_code in ("ACTIVATION_NOT_FOUND", "LICENSE_PENDING")

    @property
    def in_grace_period(self) -> bool:
        """
        Whether the license has lapsed but is still being honoured.

        A result that is both valid and in grace means the customer's license
        has expired and the product's configured grace period is keeping them
        running for now. Show a renewal warning; do not block.

        :rtype: bool
        """
        grace = self.data.get("grace")
        return bool(isinstance(grace, dict) and grace.get("active"))

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        """A compact single-line summary, for logs and interactive debugging."""
        return "<LicenseResult valid={0} status={1} source={2} error={3}>".format(
            self.valid, self.status, self.source, self.error_code
        )


class LicenseClient:
    """
    The LicenseForge licensing client. One instance per licensed installation.

    Construct it once with your configuration, then call :meth:`activate` when
    the customer supplies their key and :meth:`check` from then on. Every other
    method is a convenience built on those two.

    All constructor arguments are documented on :meth:`__init__`.

    **Thread and process safety.** The client is safe to use from several
    processes starting simultaneously. The first-activation keypair is claimed
    with a filesystem primitive that lets exactly one process' key become the
    installation key, and the rest adopt it.
    """

    #: Sent as the User-Agent on every request, and useful in your server logs
    #: for spotting installations running an old SDK.
    USER_AGENT = "LicenseForge-SDK/{0} Python/{1}".format(
        __version__, platform.python_version()
    )

    def __init__(
        self,
        license_server: str,
        license_key: str,
        product_id: str = "",
        api_key: str = "",
        api_secret: str = "",
        public_key: str = "",
        public_key_algorithm: str = "ed25519",
        version: str = "",
        domain: Optional[str] = None,
        directory: Optional[str] = None,
        machine_id: Optional[str] = None,
        installation_id: Optional[str] = None,
        install_secret: str = "",
        cache_file: Optional[str] = None,
        cache_ttl: int = 86400,
        minimum_token_version: int = 3,
        cache_allow_web_root: bool = False,
        grace_period: int = 259200,
        timeout: int = 10,
        retries: int = 2,
        retry_delay: float = 0.3,
        metadata: Optional[Dict[str, Any]] = None,
        verifier: Optional[Callable[[bytes, bytes, str, str], bool]] = None,
    ) -> None:
        """
        Create a client and validate its configuration.

        Configuration problems are raised here, immediately, so that you meet
        them while integrating rather than in a customer's log.

        :param license_server: Base URL of your LicenseForge API endpoint. Must
            be HTTPS; plain HTTP is permitted only for ``127.0.0.1`` and
            ``localhost``, for local testing. The request carries a license key
            and a signature, and plain HTTP would hand both to anyone on the
            network path.
        :param license_key: The customer's license key. Required.
        :param product_id: Your product's slug on the server. Strongly
            recommended - it lets the SDK reject a key issued for another
            product.
        :param api_key: Your product's API key (``lfk_...``).
        :param api_secret: Your product's API secret (``lfs_...``).
        :param public_key: The server's offline-token public key, base64 for
            Ed25519 or PEM for RSA. Without it, offline validation can never
            succeed and the first network outage stops every customer's copy.
        :param public_key_algorithm: ``"ed25519"`` (default) or ``"rsa-sha256"``.
            Only used when a token does not name its own algorithm.
        :param version: Your software's version, so the server can enforce the
            license's version constraints. Leave empty for unrestricted.
        :param domain: The hostname to report. Auto-detected when None. Set it
            explicitly for CLI tools, cron jobs and workers, which have no
            reliable host to detect.
        :param directory: The install path to report. Auto-detected when None.
            Set it to a stable path on deploy systems that use timestamped
            release directories - otherwise every deploy looks like a new
            installation and consumes an activation slot.
        :param machine_id: Override the derived machine fingerprint.
        :param installation_id: Override the derived activation-slot id. Set it
            to keep one logical installation across a hostname or path change.
        :param install_secret: Normally left blank - activation issues one and
            stores it beside the cache. Pass it only if you manage installation
            identity yourself, e.g. from a container secret store.
        :param cache_file: Where to store the cached answer and the signed
            offline token. Must NOT be reachable over HTTP. ``~`` is expanded.
            Three more files are created alongside it: ``.install`` holding the
            activation secret, ``.install.key`` holding the private key, and
            ``.id`` holding the installation identifier.

            Despite the name, this directory is **persistent state, not a
            cache**. Only the first file is disposable; the rest are this
            installation's identity. Put it where you would put a data
            directory, and never inside anything a deploy replaces. Losing the
            credentials while the installation id survives is a lockout rather
            than a silent extra activation - see :meth:`activate`.
        :param cache_ttl: Seconds a successful answer is reused before the
            server is contacted again. Default 86400 (one day).
        :param minimum_token_version: The oldest offline-token format this
            client will act on. Version 3 is the first that carries a
            cryptographic proof binding the token to one specific installation;
            older formats fall back to weaker defaults, so they are refused by
            default. The cost of refusing is a single round trip, after which
            the server issues a current token. Lower this only for a deployment
            that genuinely cannot reconnect - an air-gapped install being
            upgraded offline is the case it exists for. 0 disables the check.
        :param cache_allow_web_root: Skip the "is this cache path web-served?"
            heuristic. Set True only if you have confirmed the directory is not
            served over HTTP.
        :param grace_period: Fallback grace in seconds, used only for legacy
            tokens that carry no server-signed grace deadline. Default 259200.
        :param timeout: Request timeout in seconds. Default 10.
        :param retries: Extra attempts on transport failure. Default 2.
        :param retry_delay: Base backoff in seconds between attempts, scaled by
            the attempt number. Default 0.3.
        :param metadata: Extra key/value pairs sent with every call and recorded
            against the activation. Useful for a build number or deploy name.
        :param verifier: A custom signature verifier, called as
            ``verifier(message_bytes, signature_bytes, public_key, algorithm)``
            and returning a bool. Supply this if you cannot install
            ``cryptography`` but have another crypto library available. It must
            return False rather than raising, and must never return True for a
            check it could not actually perform.

        :raises LicenseError: If the license key or server URL is missing, the
            server URL is not HTTPS, or the cache path looks web-served.
        """
        if not license_key:
            raise LicenseError("A license_key is required.")
        if not license_server:
            raise LicenseError("A license_server URL is required.")

        if not license_server.startswith("https://") and not license_server.startswith(
            ("http://127.0.0.1", "http://localhost")
        ):
            raise LicenseError("The license_server URL must use HTTPS.")

        self.license_server = license_server.rstrip("/")
        self.license_key = license_key
        self.product_id = product_id
        self.api_key = api_key
        self.api_secret = api_secret
        self.public_key = public_key
        self.public_key_algorithm = public_key_algorithm
        self.version = version
        self.cache_file = os.path.expanduser(cache_file) if cache_file else None
        self.cache_ttl = int(cache_ttl)
        self.minimum_token_version = int(minimum_token_version)
        self.grace_period = int(grace_period)
        self.timeout = int(timeout)
        self.retries = int(retries)
        self.retry_delay = float(retry_delay)
        self.metadata = metadata or {}
        self._verifier = verifier

        self._domain = domain
        self._directory = directory
        self._machine_id = machine_id
        self._installation_id = installation_id
        self._install_secret = install_secret or ""
        self._install_key: Optional[str] = None
        self.cache_allow_web_root = bool(cache_allow_web_root)
        self._assert_cache_is_private()
        self._last: Optional[LicenseResult] = None

    # -- public API -------------------------------------------------------

    def activate(self) -> LicenseResult:
        """
        Bind this installation to the license and claim an activation slot.

        Call this once, when the customer first enters their key. It always
        contacts the server; the cache is never consulted.

        Calling it again from the same installation re-binds the existing slot
        rather than consuming another, because the installation identity is
        stable across calls. It is therefore safe to call from an installer
        that may be re-run, or from a "re-check my license" button.

        That holds only while this installation can still prove itself. If the
        credential files beside the cache are lost but the installation id
        resolves to the same value, the server refuses rather than re-binding -
        ``ACTIVATION_NOT_FOUND``, or ``ACTIVATION_LIMIT`` if that slot was the
        last free one - because handing a live installation's record to a
        caller that cannot prove it owns it would make the installation id a
        credential, and it is not one. Recovery is a reset by the customer or
        an administrator.

        On success the server issues this installation a credential, stored
        beside the cache file, and every later request proves its own identity
        with it - which is what makes the activation limit enforceable.

        Where ``cryptography`` is installed, a keypair is generated locally and
        only the public half is ever transmitted, so the server holds nothing
        capable of impersonating this installation. Without it the client stays
        on the shared secret, which is why this SDK still has no hard
        dependency for the online path.

        :return: The activation outcome. Check ``is_valid``.
        :rtype: LicenseResult
        """
        environment = self._environment()

        registered = self._generate_install_key()
        if registered is not None:
            environment["install_public_key"] = registered[0]
            environment["install_key_algorithm"] = registered[1]

        result = self._call("activate", environment)
        self._store(result)
        return result

    def check(self, force: bool = False) -> LicenseResult:
        """
        The recurring licensing check-in. Call this on every run.

        A successful answer is reused until ``cache_ttl`` elapses, so in normal
        operation this costs a single file read rather than a network round
        trip. When the cache is stale the server is contacted; when the server
        is unreachable the signed offline token is verified locally instead.

        This is the call you should gate your application on::

            if not lic.check().is_valid:
                sys.exit("This copy is not licensed.")

        :param force: Skip the cache and always contact the server. Suitable
            for an explicit "refresh license" control. Do not force on every
            run - you will hit the server's rate limit.
        :return: The licensing outcome. Check ``is_valid``.
        :rtype: LicenseResult
        """
        if not force:
            cached = self._fresh_cached_result()
            if cached is not None:
                self._last = cached
                return cached

        result = self._call("check", self._environment())
        self._store(result)
        return result

    #: Alias for :meth:`check`, for call sites that read better as "validate".
    validate = check

    def has_feature(self, slug: str) -> bool:
        """
        Whether an entitlement is granted, checking in first if needed.

        Convenient for a one-off feature gate. If you are testing several
        features in the same run, call :meth:`check` once and use
        :meth:`LicenseResult.has_feature` on the returned object instead.

        :param slug: The feature slug configured on the server.
        :rtype: bool
        """
        return self.current().has_feature(slug)

    def is_expired(self) -> bool:
        """
        Whether the license has passed its expiry date.

        :rtype: bool
        """
        result = self.current()
        return result.error_code == "LICENSE_EXPIRED" or result.status == "expired"

    def features(self) -> List[str]:
        """
        The entitlement slugs on the current license, checking in if needed.

        :rtype: list[str]
        """
        return self.current().features

    def status(self) -> str:
        """
        The current license state, checking in first if nothing is known yet.

        :rtype: str
        """
        return self.current().status

    def current(self) -> LicenseResult:
        """
        The most recent result, performing a check first if none exists.

        All the convenience accessors above funnel through here, so several of
        them in one run cost at most one check between them.

        :rtype: LicenseResult
        """
        if self._last is None:
            return self.check()
        return self._last

    def clear_cache(self) -> None:
        """
        Discard the cached answer and its offline token.

        Call this when the customer enters a different license key, so the
        previous license's cached answer cannot be reused, or from a "refresh
        license" control.

        This deliberately does NOT remove the installation secret, private key
        or installation id. Those are identity, not cache: deleting them would
        make the next check-in look like a brand-new installation and consume
        another activation slot.

        :rtype: None
        """
        if self.cache_file and os.path.isfile(self.cache_file):
            try:
                os.unlink(self.cache_file)
            except OSError:
                pass

    # -- transport --------------------------------------------------------

    def _call(self, endpoint: str, payload: Dict[str, Any]) -> LicenseResult:
        """
        Perform an API call, with retries, falling back offline if it fails.

        Transport failures and 5xx responses are retried with a linear backoff;
        a licensing refusal is never retried, because the answer will not
        change. When every attempt fails, the signed offline token is used.

        A successful response that also reports ``needs_activation`` is
        deliberately converted into an *invalid* result. The key may be
        genuine, but an installation that has not claimed a slot is not a
        licensed installation, and ``if lic.check().is_valid`` is the code
        integrators actually write - it must not run unactivated copies.

        :param endpoint: The API endpoint: ``"activate"`` or ``"check"``.
        :param payload: The request body, before encoding.
        :return: The server's decision, or the offline fallback.
        :rtype: LicenseResult
        """
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        attempts = max(1, self.retries + 1)
        last_error = "The licensing server could not be reached."

        for attempt in range(1, attempts + 1):
            status, raw, error = self._send(endpoint, body)

            if error is not None:
                last_error = error
                if attempt < attempts:
                    time.sleep(self.retry_delay * attempt)
                    continue
                break

            try:
                decoded = json.loads(raw.decode("utf-8"))
            except (ValueError, UnicodeDecodeError):
                last_error = "The licensing server returned an unreadable response."
                if attempt < attempts:
                    continue
                break

            if not isinstance(decoded, dict):
                last_error = "The licensing server returned an unreadable response."
                break

            if decoded.get("success"):
                if decoded.get("needs_activation"):
                    return LicenseResult(
                        False, decoded, "ACTIVATION_NOT_FOUND",
                        "This installation is not activated for this license. "
                        "Call activate() first.",
                        "remote",
                    )

                return LicenseResult(True, decoded, source="remote")

            if status >= 500 and attempt < attempts:
                last_error = "The licensing server is temporarily unavailable."
                continue

            error_block = decoded.get("error") or {}
            return LicenseResult(
                False,
                decoded,
                str(error_block.get("code", "INVALID_REQUEST")),
                str(error_block.get("message", "The license could not be verified.")),
                "remote",
            )

        return self._offline_fallback(last_error)

    def _send(self, endpoint: str, body: bytes):
        """
        Send one signed HTTP request to the licensing server.

        Two independent proofs travel with every request:

        * The **credential** signature (``X-LF-Signature``) proves the request
          came from your product, using the API key and secret you configured.
        * The **installation** proof (``X-LF-Install-Proof``) proves it came
          from this specific installation. Where a keypair was registered at
          activation it is a signature over the canonical string; otherwise it
          is an HMAC keyed with the installation secret. Neither credential is
          ever transmitted. Without this proof the server treats the caller as
          an installation it has not seen, which is what makes the activation
          limit meaningful.

        Both proofs cover the same canonical string - method, endpoint,
        timestamp, single-use nonce and a SHA-256 of the body - so the
        timestamp and nonce headers are always sent, and both inherit replay
        protection from them.

        A registered keypair supersedes the shared secret rather than falling
        back to it: once a key exists the server checks the signature first, so
        presenting the superseded secret after a signing failure would simply
        be refused.

        An HTTP error status is not a transport failure - the server answered,
        and its body still carries the error envelope - so it is returned as a
        normal response for the caller to decode.

        :param endpoint: The API endpoint path segment.
        :param body: The already-encoded JSON request body.
        :return: ``(status_code, body_bytes, transport_error_or_None)``.
        :rtype: tuple[int, bytes, str | None]
        """
        url = "{0}/license/{1}".format(self.license_server, endpoint)
        timestamp = str(int(time.time()))
        nonce = uuid.uuid4().hex

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": self.USER_AGENT,
            "X-LF-Timestamp": timestamp,
            "X-LF-Nonce": nonce,
        }

        if self.api_key and self.api_secret:
            headers["X-LF-Key"] = self.api_key
            headers["X-LF-Signature"] = self._sign(
                "POST", endpoint, timestamp, nonce, body
            )

        install_secret = self.install_secret()
        install_key = self.install_private_key()
        if install_secret or install_key:
            canonical = "\n".join([
                "POST",
                endpoint.lower(),
                timestamp,
                nonce,
                hashlib.sha256(body).hexdigest(),
            ])

            proof = _sign_with_install_key(canonical, install_key) if install_key else None

            if proof is None and install_secret:
                proof = hmac.new(
                    install_secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256
                ).hexdigest()

            if proof is not None:
                headers["X-LF-Install-Proof"] = proof

        request = urllib.request.Request(url, data=body, headers=headers, method="POST")

        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                return response.getcode(), response.read(), None
        except urllib.error.HTTPError as exc:
            return exc.code, exc.read(), None
        except urllib.error.URLError as exc:
            return 0, b"", "Could not reach the licensing server: {0}".format(exc.reason)
        except (socket.timeout, OSError) as exc:
            return 0, b"", "Could not reach the licensing server: {0}".format(exc)

    def _sign(
        self, method: str, endpoint: str, timestamp: str, nonce: str, body: bytes
    ) -> str:
        """
        Compute the API credential signature for a request.

        The canonical string is a newline-joined tuple of the uppercased
        method, the lowercased endpoint, the timestamp, the nonce and the hex
        SHA-256 of the body. This mirrors the server implementation exactly; if
        you port this SDK to another language, it is the function to match
        first.

        :param method: The HTTP method.
        :param endpoint: The endpoint path segment.
        :param timestamp: Unix seconds, as a string.
        :param nonce: A single-use random hex value.
        :param body: The exact request body being sent.
        :return: The hex HMAC-SHA256 signature.
        :rtype: str
        """
        canonical = "\n".join(
            [
                method.upper(),
                endpoint.lower(),
                timestamp,
                nonce,
                hashlib.sha256(body).hexdigest(),
            ]
        )

        return hmac.new(
            self.api_secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256
        ).hexdigest()

    # -- offline ----------------------------------------------------------

    def _offline_fallback(self, reason: str) -> LicenseResult:
        """
        Fall back to the last signed token when the server cannot be reached.

        The token is honoured for exactly as long as it allows, and no longer.

        :param reason: The transport error, appended to the message shown to
            the customer when the fallback also fails.
        :rtype: LicenseResult
        """
        return self._result_from_signed_cache("offline", reason)

    def _result_from_signed_cache(
        self, source: str, reason: str = ""
    ) -> LicenseResult:
        """
        Build a licensing result from the signed token held in the cache.

        This is the heart of offline validation, and both cache-reading paths
        (a fresh cache hit, and the offline fallback) funnel through it so that
        neither can drift into trusting unsigned data.

        The cache file sits on a machine its owner controls, so nothing read
        out of it can be believed on its own - only the token can be, because
        only the token is signed by a key the customer does not hold. Every
        field in the returned result therefore comes from ``payload``, never
        from the cache entry around it. Editing ``license`` or ``status`` in
        the file changes nothing; editing the token invalidates it.

        The checks applied here, in order:

        1. A token exists and its signature verifies against ``public_key``.
        2. It was issued for the configured license key - a signed payload for
           somebody else's key is still correctly signed, so the key it was
           issued for has to be checked separately.
        3. Its format version is within
           ``[minimum_token_version, SUPPORTED_TOKEN_VERSION]``.
        4. The system clock has not been wound back (see
           :meth:`_clock_rollback`).
        5. The server-issued offline window has not closed.
        6. The license has not expired past its server-signed grace deadline.
        7. Its status is ``"active"``.
        8. It is not IP-locked - an IP binding can only be checked by the
           server, because the token carries the address the *server* observed
           and behind NAT, a proxy or CGNAT the client sees something else.
           Rather than silently skip it, an IP-locked license is denied an
           offline answer altogether.
        9. Product, version constraints, domain, directory and machine bindings
           all still hold.
        10. It is bound to this exact installation, and this installation can
            cryptographically prove it owns that binding.

        Everything the server would have checked online is re-checked here,
        under the policy the payload itself carries. An offline path that
        enforces less than the online one is not a fallback, it is a bypass:
        pull the network cable and the weaker rules apply.

        :param source: The source label for the result: ``"cache"`` or
            ``"offline"``.
        :param reason: The transport failure, appended to messages where the
            customer needs to know the server was unreachable.
        :rtype: LicenseResult
        """
        cached = self._read_cache()
        token = (cached or {}).get("offline_token")
        if not token:
            return LicenseResult(False, {}, "SERVICE_UNAVAILABLE", reason, source)

        payload = self.verify_offline_token(token)
        if payload is None:
            return LicenseResult(
                False, {}, "SIGNATURE_INVALID",
                "The cached license could not be verified.", source,
            )

        if not hmac.compare_digest(
            str(payload.get("license_key", "")), self.license_key
        ):
            return LicenseResult(
                False, {}, "INVALID_LICENSE",
                "The cached license belongs to a different key.", source,
            )

        now = int(time.time())

        # Version floor. Each "field missing, so assume the old default" branch
        # further down is a place where an old token would quietly enforce the
        # policy in force when it was signed rather than the current one.
        try:
            version = int(payload.get("token_version", 1))
        except (TypeError, ValueError):
            version = 1

        if self.minimum_token_version > 0 and version < self.minimum_token_version:
            return LicenseResult(
                False, {}, "SERVICE_UNAVAILABLE",
                "This cached license predates the current licensing rules. "
                "Reconnect to refresh it.", source,
            )

        # Version ceiling. A newer token carries semantics this build has never
        # seen; acting on it means guessing, and the guess that matters is
        # always "absent field, so the older rule applies".
        if version > SUPPORTED_TOKEN_VERSION:
            return LicenseResult(
                False, {}, "SERVICE_UNAVAILABLE",
                "This license was issued under newer rules than this software "
                "understands. Update the software, or reconnect for a compatible "
                "response.", source,
            )

        # Every deadline below is read against a clock the customer owns, so
        # the clock is judged before any of them are trusted.
        rollback = self._clock_rollback(payload)
        if rollback is not None:
            return LicenseResult(False, {}, "SERVICE_UNAVAILABLE", rollback, source)

        # offline_until is a hard deadline set by the server, and a payload
        # without one has no offline permission at all.
        offline_until = _parse_iso8601(payload.get("offline_until"))
        if offline_until is None or offline_until < now:
            return LicenseResult(
                False, {}, "SERVICE_UNAVAILABLE",
                ("The offline validity period has ended. " + reason).strip(), source,
            )

        # Expiry is measured against the boundary the *server* signed.
        # self.grace_period is only a fallback for tokens issued before the
        # server published grace_ends_at: how long an expired license keeps
        # working is licensing policy, and a local default would grant grace to
        # a product configured for none.
        grace_ends_at = _parse_iso8601(payload.get("grace_ends_at"))
        if grace_ends_at is not None:
            if grace_ends_at < now:
                return LicenseResult(
                    False, {}, "LICENSE_EXPIRED", "The license expired.", source
                )
        else:
            expires_at = _parse_iso8601(payload.get("expires_at"))
            if expires_at is not None and expires_at < now - self.grace_period:
                return LicenseResult(
                    False, {}, "LICENSE_EXPIRED", "The license expired.", source
                )

        if str(payload.get("status", "")) != "active":
            return LicenseResult(
                False, {}, "INVALID_LICENSE", "The license is not active.", source
            )

        # The one binding this side cannot verify. See the class docstring for
        # why an IP-locked license is refused offline rather than waved through.
        if payload.get("lock_ip"):
            return LicenseResult(
                False, {}, "SERVICE_UNAVAILABLE",
                (
                    "This license is bound to an IP address, which only the "
                    "licensing server can verify. " + reason
                ).strip(),
                source,
            )

        bound_product = str(payload.get("product_id") or "")
        if bound_product and self.product_id and not hmac.compare_digest(
            bound_product, self.product_id
        ):
            return LicenseResult(
                False, {}, "PRODUCT_MISMATCH",
                "The cached license is for another product.", source,
            )

        version_problem = _version_problem(
            self.version,
            payload.get("min_version"),
            payload.get("max_version"),
            payload.get("allowed_versions"),
        )
        if version_problem:
            return LicenseResult(
                False, {}, "VERSION_NOT_SUPPORTED",
                "This version is not covered: " + version_problem, source,
            )

        # Domain binding, re-checked locally so a cached payload copied
        # elsewhere does not work there. Subdomain and www tolerance are
        # whatever the server signed, so the offline answer matches the online
        # one. An absent flag means a token issued before that policy was
        # signed, and the permissive default keeps those working.
        strip_www = bool(payload.get("allow_www_normalisation", True))

        bound_domain = str(payload.get("domain") or "")
        current_domain = self.detect_domain()
        if payload.get("lock_domain") and current_domain:
            if "allow_local_domains" in payload and not payload.get(
                "allow_local_domains"
            ) and _is_local_domain(current_domain):
                return LicenseResult(
                    False, {}, "DOMAIN_MISMATCH",
                    "Development and local domains are not permitted for this license.",
                    source,
                )

            if bound_domain and not _domain_matches(
                bound_domain,
                current_domain,
                bool(payload.get("allow_subdomains")),
                strip_www,
            ):
                return LicenseResult(
                    False, {}, "DOMAIN_MISMATCH",
                    "The cached license is bound to another domain.", source,
                )

        # Install path, compared explicitly rather than left to whatever each
        # SDK happens to fold into its machine identifier. This one folds in
        # nothing: machine_id here is host name and MAC, with no path in it.
        bound_directory = str(payload.get("directory") or "")
        if payload.get("lock_directory") and bound_directory and not hmac.compare_digest(
            _normalise_path(bound_directory), _normalise_path(self.detect_directory())
        ):
            return LicenseResult(
                False, {}, "DIRECTORY_MISMATCH",
                "The cached license is bound to another directory.", source,
            )

        # Machine binding, so a token cannot be moved to a different host that
        # happens to serve the same domain.
        bound_machine = str(payload.get("machine_id") or "")
        if payload.get("lock_machine") and bound_machine and not hmac.compare_digest(
            bound_machine, self.machine_id()
        ):
            return LicenseResult(
                False, {}, "MACHINE_MISMATCH",
                "The cached license is bound to another machine.", source,
            )

        # The activation slot itself is checked always, not as a lockable
        # policy: it is the token's identity. A payload with no installation at
        # all was issued to an unbound caller, which is not a licensed
        # installation.
        bound_installation = str(payload.get("installation_id") or "")
        if not bound_installation:
            return LicenseResult(
                False, {}, "ACTIVATION_NOT_FOUND",
                "The cached license is not bound to an activation.", source,
            )
        if not hmac.compare_digest(bound_installation, self.installation_id()):
            return LicenseResult(
                False, {}, "MACHINE_MISMATCH",
                "The cached license is bound to another installation.", source,
            )

        # Matching the installation id proves nothing on its own: the id is a
        # value this client generated and keeps in a file, so copying the cache
        # and that file to a second machine would reproduce a "licensed"
        # installation that had never proved anything. Online, an installation
        # is recognised by a proof computed from the credential issued at
        # activation; the checks below are that same evidence, offline.
        #
        # An installation holding a registered keypair proves possession by
        # signing the token's own nonce and verifying the result against the
        # public key the server signed in - exactly as strong offline as the
        # HMAC form (this machine is the verifier either way) while leaving the
        # server nothing that could forge the binding.
        binding_key = str(payload.get("installation_key") or "")
        binding = str(payload.get("installation_binding") or "")

        # Assert what token version 3 means, rather than assuming it.
        #
        # The two checks below each fire only when their own field is present,
        # so together they say "verify whichever binding you were given" - not
        # "a binding was given". The server always mints exactly one, and the
        # default floor rejects the formats predating that guarantee. But that
        # leaves the invariant living in the floor rather than in the version:
        # lowering the floor is a deliberate choice to accept older *known*
        # shapes, and it should not also make a malformed v3 token acceptable.
        # Exactly one binding, never both, never neither.
        if version >= 3 and bool(binding_key) == bool(binding):
            return LicenseResult(
                False, {}, "ACTIVATION_NOT_FOUND",
                "This cached license is missing its installation binding. "
                "Reconnect to refresh it.", source,
            )

        if binding_key:
            private_key = self.install_private_key()
            if not private_key:
                return LicenseResult(
                    False, {}, "ACTIVATION_NOT_FOUND",
                    "This installation cannot prove it owns the cached license. "
                    "Reconnect to activate.", source,
                )

            nonce = str(payload.get("nonce", ""))
            signed = _sign_with_install_key(nonce, private_key)
            algorithm = str(payload.get("installation_key_algorithm") or "ed25519")

            if signed is None or not _verify_with_install_key(
                nonce, _b64url_decode(signed), binding_key, algorithm
            ):
                return LicenseResult(
                    False, {}, "MACHINE_MISMATCH",
                    "The cached license belongs to another installation.", source,
                )

        if binding and not binding_key:
            secret = self.install_secret()
            if not secret:
                return LicenseResult(
                    False, {}, "ACTIVATION_NOT_FOUND",
                    "This installation cannot prove it owns the cached license. "
                    "Reconnect to activate.", source,
                )
            expected = hmac.new(
                secret.encode("utf-8"),
                str(payload.get("nonce", "")).encode("utf-8"),
                hashlib.sha256,
            ).hexdigest()
            if not hmac.compare_digest(binding, expected):
                return LicenseResult(
                    False, {}, "MACHINE_MISMATCH",
                    "The cached license belongs to another installation.", source,
                )

        return LicenseResult(
            True,
            {
                "status": "active",
                "license": {
                    "key": str(payload.get("license_key", "")),
                    "status": "active",
                    "product_id": str(payload.get("product_id", "")),
                    "expires_at": payload.get("expires_at"),
                    "domain": payload.get("domain"),
                    "features": _live_features(payload),
                },
                "offline_until": payload.get("offline_until"),
            },
            source=source,
        )

    def verify_offline_token(self, token: str) -> Optional[Dict[str, Any]]:
        """
        Verify a signed offline token and return its payload.

        The token format is ``base64url(payload_json).base64url(signature)``,
        and the signature covers the *encoded* payload segment, so re-encoding
        is never required and cannot introduce a mismatch.

        This method is public so you can verify a token obtained by other
        means - for example one pasted in by a customer performing a manual
        air-gapped activation.

        Returns None whenever the token cannot be *proven* genuine, which
        includes the case where no verifier is available at all. Refusing to
        check is never treated as passing: with no verifier there is no way to
        tell a genuine token from a forged one, and returning the payload
        unverified would make the whole offline path trivially bypassable.

        :param token: The token in ``payload.signature`` form.
        :return: The decoded payload if the signature is genuine, else None.
        :rtype: dict | None
        """
        if not self.public_key:
            return None

        parts = token.split(".")
        if len(parts) != 2:
            return None

        encoded, signature_encoded = parts
        try:
            payload_json = _b64url_decode(encoded)
            signature = _b64url_decode(signature_encoded)
            payload = json.loads(payload_json.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            return None

        if not isinstance(payload, dict) or not signature:
            return None

        algorithm = str(payload.get("_algorithm", self.public_key_algorithm))
        verifier = self._verifier or _default_verifier()
        if verifier is None:
            return None

        try:
            ok = verifier(encoded.encode("ascii"), signature, self.public_key, algorithm)
        except Exception:
            return None

        return payload if ok else None

    # -- per-installation identity ----------------------------------------

    def install_secret(self) -> str:
        """
        The per-installation secret issued at activation.

        If a secret was passed to the constructor it wins, which lets you
        manage installation identity yourself. Otherwise it is read from its
        own file beside the cache, and memoised.

        It is stored separately from the result cache because the two mean
        different things: the cache is a disposable answer, this is identity.
        :meth:`clear_cache` must be able to force a fresh check without
        de-activating the installation and costing the customer a slot.

        :return: The secret, or ``""`` if this installation is not activated.
        :rtype: str
        """
        if self._install_secret:
            return self._install_secret

        path = self._install_secret_file()
        self._install_secret = ""
        if path and os.path.isfile(path):
            try:
                with open(path, "r", encoding="utf-8") as handle:
                    self._install_secret = handle.read().strip()
            except OSError:
                self._install_secret = ""

        return self._install_secret

    def _store_install_secret(self, secret: str) -> None:
        """
        Persist the installation secret returned by a successful activation.

        Creates the containing directory if needed and writes the file mode
        0600. Failure is swallowed: there is nothing useful to do about it here
        beyond not crashing, though the consequence is real - an unstored
        secret means the next check-in looks like a new installation and spends
        another activation slot.

        :param secret: The secret issued by the server.
        :rtype: None
        """
        path = self._install_secret_file()
        if not path or not secret:
            return

        self._install_secret = secret
        try:
            directory = os.path.dirname(path)
            if directory and not os.path.isdir(directory):
                os.makedirs(directory, exist_ok=True)
            with open(path, "w", encoding="utf-8") as handle:
                handle.write(secret)
            os.chmod(path, 0o600)
        except OSError:
            pass

    def _assert_cache_is_private(self) -> None:
        """
        Refuse a cache path that looks web-served.

        The cache holds the license key and the signed offline token, and
        beside it sit the installation secret and private key. A deployment
        that puts those under a document root publishes them, and an
        ``.htaccess`` file is no defence on nginx.

        Python has no equivalent of PHP's ``$_SERVER['DOCUMENT_ROOT']``, so the
        directory is matched against the paths web servers actually serve from.
        That is a heuristic, and it is why ``cache_allow_web_root`` exists: this
        check is meant to catch the obvious mistake, not to certify a path as
        safe. A path it accepts is not thereby proven private.

        :rtype: None
        :raises LicenseError: If the cache directory contains a marker of a
            commonly published location.
        """
        if not self.cache_file or self.cache_allow_web_root:
            return

        directory = os.path.abspath(os.path.dirname(self.cache_file))
        probe = directory.replace("\\", "/").lower().rstrip("/") + "/"

        # Evaluated in order, because the two kinds of marker mean different
        # things. These name a published directory outright.
        served = (
            "/var/www/", "/srv/www/", "/usr/share/nginx/", "/www/", "/htdocs/",
            "/public_html/", "/public/", "/wwwroot/", "/xampp/htdocs/",
        )

        hit = next((marker for marker in served if marker in probe), None)

        # "/home/" is far too broad to name on its own: it is only interesting
        # when the path also looks published. It is therefore checked *after*
        # the explicit markers, never as part of the same list - a combined
        # list takes the first match in list order, so /home/customer/wwwroot/
        # would match "/home/", fail the public_html test, and be waved through
        # despite containing a marker the list already knew about.
        if hit is None and "/home/" in probe and "public_html" in probe:
            hit = "/home/"

        if hit is None:
            return

        raise LicenseError(
            "cache_file ({0}) is inside a directory that is commonly web-served ({1}), "
            "where it may be downloadable. It holds the license key and the signed "
            "offline token, and the installation secret and private key sit beside it. "
            "Move it outside the document root, or pass cache_allow_web_root=True if you "
            "have confirmed the directory is not served.".format(self.cache_file, hit)
        )

    def install_private_key(self) -> str:
        """
        The private half of this installation's registered keypair.

        Stored beside the secret and treated the same way: identity rather than
        cache, so :meth:`clear_cache` deliberately leaves it alone. It never
        leaves the machine - only the public half was ever sent, and only once,
        at activation. The result is memoised.

        :return: The PEM private key, or ``""`` if none is registered.
        :rtype: str
        """
        if self._install_key is not None:
            return self._install_key

        self._install_key = ""
        path = self._install_key_file()
        if path and os.path.isfile(path):
            try:
                with open(path, "r", encoding="utf-8") as handle:
                    self._install_key = handle.read().strip()
            except OSError:
                self._install_key = ""

        return self._install_key

    def _generate_install_key(self) -> Optional[tuple]:
        """
        Generate this installation's keypair and return the public half.

        Called once, from :meth:`activate`, and only where there is somewhere
        durable to put the private key - an installation that cannot store it
        would register a key it could never sign with and lock itself out of
        its own activation.

        Returns None when it cannot be done, which is an ordinary case here:
        the standard library has no key generation, so this needs
        ``cryptography``. That is deliberate - the online path must keep
        working without third-party packages, so a keypair is an upgrade taken
        when available rather than a requirement. The caller then simply stays
        on the shared installation secret.

        **Concurrency.** Several processes may activate at the same moment. The
        private key is written to a unique temporary file and then *claimed*
        into its final path by :func:`_claim_path`, which places it only if
        nothing is there - so exactly one process' key becomes the installation
        key and every other adopts it. Without that, two processes could each
        register their own public key, leaving the server holding one and the
        surviving private half being the other: an installation that can never
        prove itself again, failing silently until the next check-in.

        The key is always written to disk *before* it is offered to the server,
        and only ever the key that actually reached disk. The reverse order
        loses the ability to sign for an installation the server has already
        moved onto keys, which is unrecoverable without an admin reset.

        :return: ``(public_pem, algorithm)``, or None.
        :rtype: tuple[str, str] | None
        """
        path = self._install_key_file()
        if not path or self.install_private_key():
            return None

        try:
            from cryptography.hazmat.primitives import serialization
            from cryptography.hazmat.primitives.asymmetric import rsa
        except ImportError:
            return None

        try:
            key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
            private_pem = key.private_bytes(
                encoding=serialization.Encoding.PEM,
                format=serialization.PrivateFormat.PKCS8,
                encryption_algorithm=serialization.NoEncryption(),
            ).decode("ascii")
            public_pem = key.public_key().public_bytes(
                encoding=serialization.Encoding.PEM,
                format=serialization.PublicFormat.SubjectPublicKeyInfo,
            ).decode("ascii")
        except Exception:
            return None

        directory = os.path.dirname(path)
        if directory and not os.path.isdir(directory):
            try:
                os.makedirs(directory, mode=0o700, exist_ok=True)
            except OSError:
                return None

        # Written to a private temporary name first, so the final path never
        # exists half-written. A reader deriving a public key from half a PEM
        # gets nothing, and the activation would fail intermittently under
        # concurrency - the worst way for it to fail.
        temp = "{0}.{1}.tmp".format(path, os.urandom(6).hex())

        try:
            handle = os.open(temp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except OSError:
            # The temporary name is unique to this process, so a failure here
            # means the directory is not writable - not that someone else won.
            return None

        try:
            with os.fdopen(handle, "w", encoding="utf-8") as fh:
                fh.write(private_pem)
                fh.flush()
                os.fsync(fh.fileno())
        except OSError:
            try:
                os.remove(temp)
            except OSError:
                pass

            return None

        # Fast path: another process already finished the whole sequence and
        # may already have registered its key, so it wins and this one is
        # discarded.
        if os.path.isfile(path):
            return self._adopt_install_key(path, temp)

        # The claim is what actually decides the winner, and it also catches a
        # process that finished between the check above and this line.
        if not _claim_path(temp, path):
            return self._adopt_install_key(path, temp)

        self._install_key = private_pem

        return (public_pem, "rsa-sha256")

    def _adopt_install_key(self, path: str, temp: str) -> Optional[tuple]:
        """
        Adopt the key another process placed, discarding this process' own.

        Reached whenever this process lost the first-activation race. The key
        already on disk is the one the winner is registering with the server,
        so signing with it is the only correct move - registering the key this
        process generated would leave the server holding a public half whose
        private half exists nowhere.

        :param path: The final installation key path, written by the winner.
        :param temp: This process' discarded temporary key file, deleted here.
        :return: ``(public_pem, algorithm)`` derived from the winner's key, or
            None if it could not be read or parsed.
        :rtype: tuple[str, str] | None
        """
        try:
            os.remove(temp)
        except OSError:
            pass

        try:
            with open(path, "r", encoding="utf-8") as fh:
                winner = fh.read().strip()
        except OSError:
            return None

        if not winner:
            return None

        self._install_key = winner
        derived = _public_key_from_private(winner)

        return None if derived is None else (derived, "rsa-sha256")

    def _install_key_file(self) -> str:
        """
        The path of the installation private key file.

        :return: The path, or ``""`` if no cache file is configured.
        :rtype: str
        """
        secret_file = self._install_secret_file()

        return secret_file + ".key" if secret_file else ""

    def _install_secret_file(self) -> str:
        """
        The path of the installation secret file, derived from the cache path.

        :return: The path, or ``""`` if no cache file is configured.
        :rtype: str
        """
        return self.cache_file + ".install" if self.cache_file else ""

    def _store(self, result: LicenseResult) -> None:
        """
        Record the outcome of a server call.

        The installation secret, which is returned exactly once by
        :meth:`activate`, is persisted before anything else can fail. Losing it
        would make the next check-in look like a new installation and spend
        another activation slot.

        Only a valid result updates the cache.

        :param result: The result to record.
        :rtype: None
        """
        self._last = result

        installation = result.data.get("installation") or {}
        if isinstance(installation, dict) and installation.get("secret"):
            self._store_install_secret(str(installation["secret"]))

        if not self.cache_file or not result.is_valid:
            return

        offline = result.data.get("offline") or {}
        # Keep the previous token if this response carried none, so a server
        # that briefly cannot sign does not strip the installation of its
        # offline capability - and, since the cache is only honoured when a
        # token verifies, of its cache along with it.
        previous = (self._read_cache() or {}).get("offline_token")
        token = offline.get("token") or previous
        self._write_cache(
            {
                "checked_at": int(time.time()),
                # Advanced on every successful check and never lowered, so a
                # clock wound back afterwards is detectable. See
                # _clock_rollback().
                "seen_at": self._high_water_mark(
                    self.verify_offline_token(token) if isinstance(token, str) else None
                ),
                "valid": True,
                "data": result.data,
                "offline_token": token,
            }
        )

    def _clock_rollback(self, payload: Dict[str, Any]) -> Optional[str]:
        """
        Detect a system clock wound back to revive an expired token.

        Every offline deadline is read against a clock on hardware the customer
        owns, so an expired token can be made current again simply by setting
        the date back. This cannot be *solved* on a machine its owner controls
        - there is no trustworthy time source to appeal to - but it can be made
        expensive and evident, in two layers.

        The first layer is free and unforgeable: ``issued_at`` sits inside the
        signature, so it cannot be lowered without invalidating the token, and
        a newer token can only come from the server. A clock reading earlier
        than the moment the token was minted is therefore lying, with no
        legitimate explanation beyond ordinary skew.

        That alone is not enough, since winding back to a point *after* issue
        but before ``offline_until`` revives the token while satisfying the
        first check. So the second layer keeps a high-water mark of the latest
        time this installation has ever seen - signed ``issued_at`` values and
        the local clock at each successful check - and refuses when the clock
        reads meaningfully behind it.

        The mark lives in the cache file, which the customer can also edit.
        That is a deliberate trade rather than an oversight: the mark sits
        *beside the token it guards*, so deleting the file to clear the
        tripwire discards the token too, and the installation must reach the
        server - which is exactly the outcome the check exists to force.
        Editing the mark down in place is the remaining hole, and no amount of
        local storage closes it. Treat this as raising cost, not as a control.

        :param payload: The verified offline token payload.
        :return: A reason to refuse, or None when the clock is credible.
        :rtype: str | None
        """
        now = int(time.time())

        issued_at = _parse_iso8601(payload.get("issued_at"))
        if issued_at is not None and now < issued_at - CLOCK_TOLERANCE:
            return (
                "The system clock is set earlier than this license was issued. "
                "Correct the clock, or connect to the server to re-check."
            )

        mark = int((self._read_cache() or {}).get("seen_at", 0) or 0)
        if mark > 0 and now < mark - CLOCK_TOLERANCE:
            return (
                "The system clock has moved backwards since this license was "
                "last checked. Correct the clock, or connect to the server to "
                "re-check."
            )

        return None

    def _high_water_mark(self, payload: Optional[Dict[str, Any]]) -> int:
        """
        Compute the latest moment this installation has evidence of.

        Written to the cache on every successful check and consumed by
        :meth:`_clock_rollback`. It never moves backwards, and it prefers the
        signed ``issued_at`` over the local clock where that is ahead: the
        server's word about the time is worth more than the customer's
        machine's, and it is the half an attacker cannot forge.

        :param payload: The verified token payload, if any.
        :return: The new high-water mark, in Unix seconds.
        :rtype: int
        """
        mark = max(int((self._read_cache() or {}).get("seen_at", 0) or 0), int(time.time()))

        if payload is not None:
            issued_at = _parse_iso8601(payload.get("issued_at"))
            if issued_at is not None:
                mark = max(mark, issued_at)

        return mark

    def _fresh_cached_result(self) -> Optional[LicenseResult]:
        """
        Return the cached answer if it is still fresh, else None.

        ``checked_at`` and ``valid`` decide only *whether* to skip the network
        call. They cannot decide the answer: returning ``cached["data"]`` would
        hand back whatever the file says, so setting ``valid`` to true and
        writing your own ``data`` would be a complete bypass with no forgery
        required. The answer always comes from the signed payload instead,
        through the same verification the offline path uses.

        A cache that fails verification returns None rather than a failure, so
        the caller contacts the server instead of hard-failing on a stale or
        damaged file.

        :return: A verified fresh result, or None.
        :rtype: LicenseResult | None
        """
        cached = self._read_cache()
        if not cached:
            return None

        try:
            checked_at = int(cached.get("checked_at", 0))
        except (TypeError, ValueError):
            return None

        age = int(time.time()) - checked_at
        if checked_at <= 0 or age < 0 or age > self.cache_ttl:
            return None

        result = self._result_from_signed_cache("cache")

        return result if result.is_valid else None

    def _read_cache(self) -> Optional[Dict[str, Any]]:
        """
        Read and decode the cache file.

        Every failure mode - no file, unreadable, malformed JSON - returns
        None, because a missing or damaged cache is an ordinary condition that
        should send the caller to the network, not an error.

        :return: The decoded cache, or None.
        :rtype: dict | None
        """
        if not self.cache_file or not os.path.isfile(self.cache_file):
            return None
        try:
            with open(self.cache_file, "r", encoding="utf-8") as handle:
                data = json.load(handle)
        except (OSError, ValueError):
            return None

        return data if isinstance(data, dict) else None

    def _write_cache(self, data: Dict[str, Any]) -> None:
        """
        Write the cache file, creating its directory if needed, mode 0600.

        Failure is swallowed: a cache that cannot be written costs a network
        round trip on the next run, and nothing more.

        :param data: The cache entry to persist.
        :rtype: None
        """
        if not self.cache_file:
            return
        try:
            directory = os.path.dirname(self.cache_file)
            if directory and not os.path.isdir(directory):
                os.makedirs(directory, exist_ok=True)
            with open(self.cache_file, "w", encoding="utf-8") as handle:
                json.dump(data, handle, separators=(",", ":"))
            os.chmod(self.cache_file, 0o600)
        except OSError:
            pass

    # -- environment ------------------------------------------------------

    def _environment(self) -> Dict[str, Any]:
        """
        Assemble everything the server needs in order to make a decision.

        Sent with both :meth:`activate` and :meth:`check`. Anything passed as
        ``metadata`` to the constructor is merged over the defaults, so you can
        attach your own diagnostics - a build number, a deployment name - and
        read them back on the activation record in the admin UI.

        :rtype: dict
        """
        metadata = {
            "python": platform.python_version(),
            "os": platform.system(),
            "sdk": __version__,
        }
        metadata.update(self.metadata)

        return {
            "license_key": self.license_key,
            "product_id": self.product_id,
            "domain": self.detect_domain(),
            "directory": self.detect_directory(),
            "machine_id": self.machine_id(),
            "installation_id": self.installation_id(),
            "version": self.version,
            "metadata": metadata,
        }

    def detect_domain(self) -> str:
        """
        The hostname this installation reports, normalised.

        The ``domain`` constructor argument wins if it was given; otherwise the
        system's fully-qualified domain name is used. Pass ``domain``
        explicitly for CLI tools and worker processes, where there is no
        reliable host to detect.

        A leading ``www.`` is deliberately preserved here. Whether it is
        significant is the server's ``allow_www_normalisation`` policy;
        stripping it at this point would decide that question locally, and the
        server would never see the difference.

        :return: The normalised hostname, or ``""`` if none could be found.
        :rtype: str
        """
        if self._domain is not None:
            return _normalise_domain(self._domain, False)
        try:
            return _normalise_domain(socket.getfqdn(), False)
        except OSError:
            return ""

    def detect_directory(self) -> str:
        """
        The resolved installation path, used for directory binding.

        The ``directory`` constructor argument wins if it was given; otherwise
        the current working directory is used.

        IMPORTANT for deploy systems: a timestamped release path changes on
        every deploy, so every release presents as a brand-new installation and
        consumes an activation slot. Pass a stable ``directory``, or a fixed
        ``installation_id``, on such setups.

        :return: The absolute path, or ``""`` if it could not be determined.
        :rtype: str
        """
        if self._directory is not None:
            return self._directory
        try:
            return os.path.abspath(os.getcwd())
        except OSError:
            return ""

    def machine_id(self) -> str:
        """
        A stable identifier for this machine.

        Derived from the host name, the primary MAC address and the machine
        architecture, then hashed, so no raw hardware detail ever leaves the
        installation. Memoised after the first call.

        Note that unlike the PHP SDK this does not fold in the install path, so
        moving the product to a different directory on the same host keeps the
        same machine id.

        Override it with the ``machine_id`` constructor argument if your
        product already has a better notion of machine identity.

        :return: A 32-character hex identifier.
        :rtype: str
        """
        if self._machine_id is not None:
            return self._machine_id

        parts = [platform.node(), str(uuid.getnode()), platform.machine()]
        self._machine_id = hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()[:32]
        return self._machine_id

    def installation_id(self) -> str:
        """
        The identifier for this installation's activation slot.

        Generated once as a random UUID and stored beside the cache file (with
        a ``.id`` suffix) so it survives restarts. Without a cache file - or if
        the file cannot be written - it falls back to the machine id, which
        keeps behaviour sane for single-installation deployments.

        Pass ``installation_id`` to the constructor to keep one logical
        installation across a machine change or migration.

        :return: The activation slot identifier.
        :rtype: str
        """
        if self._installation_id is not None:
            return self._installation_id

        if not self.cache_file:
            self._installation_id = self.machine_id()
            return self._installation_id

        path = self.cache_file + ".id"
        try:
            if os.path.isfile(path):
                with open(path, "r", encoding="utf-8") as handle:
                    existing = handle.read().strip()
                if existing:
                    self._installation_id = existing
                    return existing

            generated = uuid.uuid4().hex
            directory = os.path.dirname(path)
            if directory and not os.path.isdir(directory):
                os.makedirs(directory, exist_ok=True)
            with open(path, "w", encoding="utf-8") as handle:
                handle.write(generated)
            os.chmod(path, 0o600)
            self._installation_id = generated
        except OSError:
            self._installation_id = self.machine_id()

        return self._installation_id


# =========================================================================
# Module-level helpers
#
# These are internal to the SDK. They are module functions rather than
# methods because none of them needs client state, which also makes them
# straightforward to unit-test in isolation.
# =========================================================================


def _normalise_domain(domain: str, strip_www: bool = True) -> str:
    """
    Reduce a hostname to a canonical comparable form.

    Removes any scheme, any path, a trailing port and a trailing dot, and
    lowercases the result.

    :param domain: The raw hostname, URL or ``host:port`` value.
    :param strip_www: Whether a leading ``www.`` is removed. This mirrors the
        server's ``allow_www_normalisation`` setting, as signed into the offline
        token. Stripping it unconditionally would let ``www.example.com``
        satisfy a binding to ``example.com`` offline even where the server
        refuses it online.
    :return: The normalised hostname.
    :rtype: str
    """
    domain = str(domain or "").strip().lower()
    if "//" in domain:
        domain = domain.split("//", 1)[1]
    domain = domain.split("/", 1)[0]
    # Only a single colon can be a port: more than one means an IPv6 literal,
    # which must survive intact. Splitting unconditionally reduces "::1" to "".
    if domain.count(":") == 1:
        domain = domain.split(":", 1)[0]
    domain = domain.rstrip(".")
    if strip_www and domain.startswith("www."):
        return domain[4:]
    return domain


def _is_local_domain(domain: str) -> bool:
    """
    Decide whether a hostname looks like a development or local environment.

    Treated as local: private and reserved IP addresses, single-label hosts
    such as ``localhost`` or a Docker container name, the reserved development
    TLDs (``.local``, ``.test``, ``.example``, ``.invalid``, ``.internal``), and
    the conventional development prefixes (``dev.``, ``staging.``, ``test.``,
    ``sandbox.``, ``qa.`` and so on).

    This mirrors the server's own detection, so a license whose policy forbids
    development hostnames is refused offline for exactly the hosts it is
    refused online.

    :param domain: The hostname to classify.
    :rtype: bool
    """
    domain = _normalise_domain(domain)
    if not domain:
        return False

    try:
        addr = ipaddress.ip_address(domain)
    except ValueError:
        addr = None

    if addr is not None:
        # These networks are exactly PHP's FILTER_FLAG_NO_PRIV_RANGE and
        # FILTER_FLAG_NO_RES_RANGE sets, which is what the server tests
        # against. Python's own ``is_private`` is broader - it counts the
        # documentation ranges such as 203.0.113.0/24 - so using it would make
        # this client refuse addresses the server accepts.
        for network in (
            "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",           # private v4
            "0.0.0.0/8", "127.0.0.0/8", "169.254.0.0/16", "240.0.0.0/4",  # reserved v4
            "fc00::/7", "fe80::/10",                                    # private v6
            "::/128", "::1/128", "::ffff:0:0/96", "100::/64",           # reserved v6
        ):
            net = ipaddress.ip_network(network)
            if addr.version == net.version and addr in net:
                return True

        return False

    if "." not in domain:
        return True  # single-label host, e.g. "localhost" or a container name

    for suffix in (".local", ".localhost", ".test", ".example", ".invalid",
                   ".internal", ".dev.local"):
        if domain.endswith(suffix):
            return True

    for prefix in ("dev.", "staging.", "stage.", "test.", "sandbox.", "local.", "qa."):
        if domain.startswith(prefix):
            return True

    return False


def _version_parts(version: str) -> List[int]:
    """
    Split a version string into its numeric components.

    A leading ``v`` is dropped, and any pre-release or build suffix (``-beta``,
    ``+build.7``) is discarded, so ``v2.1.0-rc1`` and ``2.1.0`` compare equal.

    :param version: The version string.
    :return: The numeric components, never empty.
    :rtype: list[int]
    """
    version = re.sub(r"^v", "", str(version).strip().lower())
    version = re.sub(r"[+\-].*$", "", version)

    numbers = []
    for part in re.split(r"[._]", version):
        if part:
            numbers.append(int(re.sub(r"\D.*$", "", part) or 0))

    return numbers or [0]


def _version_compare(a: str, b: str) -> int:
    """
    Compare two version strings component by component.

    Missing components are treated as zero, so ``2.1`` and ``2.1.0`` are equal.

    :return: -1 if ``a`` is lower, 1 if higher, 0 if equal.
    :rtype: int
    """
    left, right = _version_parts(a), _version_parts(b)
    for i in range(max(len(left), len(right))):
        l = left[i] if i < len(left) else 0
        r = right[i] if i < len(right) else 0
        if l != r:
            return -1 if l < r else 1

    return 0


def _version_matches_one(version: str, constraint: str) -> bool:
    """
    Test a version against one individual constraint.

    Supported forms::

        *              anything
        1.0 - 1.9      an inclusive range
        3.0+           that version or higher
        >=2.1          comparisons: >=, <=, >, <, !=, =
        1.x  2.*       a wildcard on the trailing component
        2.1.0          an exact match

    :param version: The version to test.
    :param constraint: A single trimmed constraint.
    :rtype: bool
    """
    if constraint == "*":
        return True

    # Inclusive range, e.g. "1.0 - 1.9". The guard keeps "<=1.0 - 2.0" from
    # being read as a range.
    m = re.match(r"^(\S+)\s*-\s*(\S+)$", constraint)
    if m and not re.match(r"^[<>=!]", m.group(1)):
        return (
            _version_compare(version, m.group(1)) >= 0
            and _version_compare(version, m.group(2)) <= 0
        )

    # "3.0+"
    m = re.match(r"^(.+)\+$", constraint)
    if m:
        return _version_compare(version, m.group(1).strip()) >= 0

    # Comparison operators.
    m = re.match(r"^(>=|<=|!=|>|<|=)\s*(.+)$", constraint)
    if m:
        cmp = _version_compare(version, m.group(2).strip())
        return {
            ">=": cmp >= 0, "<=": cmp <= 0, ">": cmp > 0,
            "<": cmp < 0, "!=": cmp != 0, "=": cmp == 0,
        }[m.group(1)]

    # Wildcards: "1.x", "2.*", "1.2.x"
    m = re.match(r"^(.*?)\.[x*]$", constraint, re.I)
    if m:
        prefix = _version_parts(m.group(1))
        candidate = _version_parts(version)
        return all(
            (candidate[i] if i < len(candidate) else 0) == value
            for i, value in enumerate(prefix)
        )

    return _version_compare(version, constraint) == 0


def _version_satisfies(version: str, expression: str) -> bool:
    """
    Test a version against a constraint expression.

    The expression is a comma- or pipe-separated list of constraints, and the
    version satisfies it if it matches ANY of them. ``"*"`` or an empty
    expression matches everything.

    :param version: The version to test.
    :param expression: The constraint expression, e.g. ``"1.x, 2.0 - 2.4, 3.0+"``.
    :rtype: bool
    """
    expression = str(expression).strip()
    if expression in ("", "*"):
        return True
    if not str(version).strip():
        return False

    return any(
        _version_matches_one(version, c.strip())
        for c in re.split(r"[,|]", expression)
        if c.strip()
    )


def _version_problem(
    version: str,
    minimum: Optional[str],
    maximum: Optional[str],
    allowed: Optional[str],
) -> Optional[str]:
    """
    Explain why a version is not covered by the signed constraints, or None.

    Version constraints let you sell a license that covers, say, the 2.x line
    only, and have older or newer builds refuse to run on it. They are set per
    license on the server and signed into the offline token, so they are
    enforced identically online and offline.

    This mirrors the server's own version comparison. It is duplicated rather
    than shared because this module ships to your customers on its own; a
    change to the constraint syntax on the server has to be made here too.

    An empty version means the caller configured none, which the server treats
    as unrestricted, so this does too.

    :param version: The running software version.
    :param minimum: Minimum supported version, or None.
    :param maximum: Maximum supported version, or None.
    :param allowed: A constraint expression, or None.
    :return: A readable reason, or None when the version is covered.
    :rtype: str | None
    """
    version = str(version or "").strip()
    if not version:
        return None

    if minimum and str(minimum).strip() and _version_compare(version, str(minimum).strip()) < 0:
        return "minimum supported version is " + str(minimum).strip()
    if maximum and str(maximum).strip() and _version_compare(version, str(maximum).strip()) > 0:
        return "maximum supported version is " + str(maximum).strip()
    if allowed and str(allowed).strip() and not _version_satisfies(version, str(allowed).strip()):
        return "version does not match the allowed set (%s)" % str(allowed).strip()

    return None


def _domain_matches(
    bound: str, current: str, allow_subdomains: bool = False, strip_www: bool = True
) -> bool:
    """
    Decide whether the current hostname satisfies the bound one.

    Two forms of binding are understood::

        example.com     matches exactly; also matches subdomains when the
                        license permits them
        *.example.com   matches any subdomain, and deliberately excludes the
                        apex, mirroring the server's matching rules

    An empty bound or current domain matches, since there is nothing to enforce
    against.

    :param bound: The domain the license is bound to.
    :param current: The domain this installation reports.
    :param allow_subdomains: Whether subdomains satisfy an apex binding. Comes
        from the signed payload, never from a guess, so the offline verdict
        matches the online one. The three SDKs used to disagree here - one
        accepted subdomains unconditionally, the others never - which meant the
        same token was enforced differently depending on which language the
        customer's product happened to be written in.
    :param strip_www: Whether ``www.`` is insignificant.
    :rtype: bool
    """
    bound = _normalise_domain(bound, strip_www)
    current = _normalise_domain(current, strip_www)
    if not bound or not current:
        return True

    if bound.startswith("*."):
        suffix = bound[1:]
        return current != suffix[1:] and current.endswith(suffix)

    if bound == current:
        return True

    return allow_subdomains and current.endswith("." + bound)


def _normalise_path(path: str) -> str:
    """
    Normalise an install path so two spellings of it compare equal.

    Backslashes become forward slashes, repeated separators collapse, any
    trailing separator is dropped, and a Windows drive letter is uppercased
    because it is case-insensitive while the rest of a POSIX path is not.

    This mirrors the server's own path normalisation, so the same two paths
    compare equal on both sides of the binding check.

    :param path: The path to normalise.
    :return: The normalised path; ``"/"`` for a path that reduces to empty.
    :rtype: str
    """
    path = str(path or "").strip()
    if not path:
        return ""

    path = re.sub(r"/+", "/", path.replace("\\", "/")).rstrip("/")

    m = re.match(r"^([a-zA-Z]):/", path)
    if m:
        path = m.group(1).upper() + path[1:]

    return path or "/"


def _claim_path(temp: str, path: str) -> bool:
    """
    Move ``temp`` to ``path``, but only if nothing is there yet.

    Exclusivity is the requirement here, not merely atomicity. ``os.replace()``
    is atomic and still wrong: it overwrites, so every racing process
    "succeeds" and the last to land silently discards the key another process
    is registering. What is needed is a primitive that fails when the
    destination exists, so exactly one process decides which private key
    becomes the installation key and the rest adopt it.

    Three routes, because no single one covers every platform and filesystem:

    Windows
        ``os.rename()`` fails if the destination exists - precisely the
        behaviour ``os.replace()`` was introduced to bypass, so here it is the
        one that is wanted.

    POSIX
        ``os.link()`` fails with ``FileExistsError`` if the destination exists,
        and the link makes the content appear at ``path`` complete or not at
        all. The temporary name is then unlinked, leaving one entry.

    POSIX without hard links
        :func:`_claim_path_locked` serialises the processes under ``flock()``
        instead. Reached only when ``os.link()`` fails for a reason *other* than
        the destination existing - which is why those two failures must be told
        apart rather than both read as a lost race.

    :param temp: Path to the fully written temporary file.
    :param path: The destination path to claim.
    :return: True only when this caller placed the file.
    :rtype: bool
    """
    if os.name == "nt":
        try:
            os.rename(temp, path)
        except OSError:
            return False

        return True

    try:
        os.link(temp, path)
    except FileExistsError:
        # Genuinely lost: someone else placed the key. The caller adopts it.
        return False
    except OSError:
        # Something other than a lost race - the filesystem does not support
        # hard links at all. Some NFS exports, SMB mounts, and a few FUSE and
        # overlay filesystems refuse os.link() outright.
        #
        # Treating that as a loss would be wrong in a way nothing would report:
        # there is no winner to adopt on a first activation, so
        # _adopt_install_key() would find no file, return None, and the
        # installation would stay on the shared secret permanently. Not an
        # outage - the secret path still authenticates - but the keypair
        # upgrade would silently never happen on those installs, which is the
        # one outcome the whole keypair path exists to produce.
        #
        # Falling back to a lock reaches the same guarantee a different way:
        # serialise the processes rather than racing them, so the check and the
        # placement remain one decision. The PHP SDK needs the same fallback
        # for the same reason.
        return _claim_path_locked(temp, path)

    try:
        os.remove(temp)
    except OSError:
        pass

    return True


def _claim_path_locked(temp: str, path: str) -> bool:
    """
    Place ``temp`` at ``path`` under an exclusive lock.

    The fallback for filesystems that cannot do it with a hard link.

    ``flock()`` is advisory, but every writer reaches the final path through
    :func:`_claim_path`, so there is no unlocked writer for it to miss. Readers
    take no lock and need none: the rename is still atomic, so they see a whole
    file or no file.

    The lock file is deliberately kept rather than unlinked. Removing it would
    let a process that already holds the descriptor and a process that creates
    a fresh one hold locks on two different inodes and both proceed, which is
    the exact race this function exists to close.

    :param temp: Path to the fully written temporary file.
    :param path: The destination path to claim.
    :return: True only when this caller placed the file. Also False when
        ``fcntl`` is unavailable - with neither flock nor hard links there is
        nothing left that makes this exclusive, and guessing would be worse
        than declining.
    :rtype: bool
    """
    try:
        import fcntl
    except ImportError:
        return False

    try:
        handle = os.open(path + ".lock", os.O_CREAT | os.O_RDWR, 0o600)
    except OSError:
        return False

    try:
        fcntl.flock(handle, fcntl.LOCK_EX)

        # Re-checked under the lock: the winner may have placed the file
        # between this process' earlier check and acquiring the lock.
        if os.path.isfile(path):
            return False

        os.replace(temp, path)
    except OSError:
        return False
    finally:
        try:
            fcntl.flock(handle, fcntl.LOCK_UN)
        except OSError:
            pass
        os.close(handle)

    return True


def _public_key_from_private(private_key_pem: str) -> Optional[str]:
    """
    Derive the public half of a private key already on disk, as PEM.

    Needed only by the loser of a first-activation race, which must register
    the key that actually reached disk rather than the one it generated - and
    all it holds at that point is the winner's private half.

    :param private_key_pem: The PEM private key.
    :return: The PEM public key, or None if it could not be derived.
    :rtype: str | None
    """
    if not private_key_pem:
        return None

    try:
        from cryptography.hazmat.primitives import serialization
    except ImportError:
        return None

    try:
        key = serialization.load_pem_private_key(
            private_key_pem.encode("ascii"), password=None
        )

        return key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        ).decode("ascii")
    except Exception:
        return None


def _sign_with_install_key(canonical: str, private_key_pem: str) -> Optional[str]:
    """
    Sign a canonical string with this installation's private key.

    The result is base64url rather than hex: a 2048-bit RSA signature is 512
    hex characters, and base64url keeps the HTTP header a reasonable size. The
    server accepts either encoding, since HMAC proofs remain hex.

    :param canonical: The exact string to sign.
    :param private_key_pem: The PEM private key.
    :return: The base64url signature, or None if it could not sign - which the
        caller treats as "no key" rather than as a failure, since the shared
        secret is still there.
    :rtype: str | None
    """
    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import padding
    except ImportError:
        return None

    try:
        key = serialization.load_pem_private_key(
            private_key_pem.encode("ascii"), password=None
        )
        signature = key.sign(
            canonical.encode("utf-8"), padding.PKCS1v15(), hashes.SHA256()
        )
    except Exception:
        return None

    return base64.urlsafe_b64encode(signature).decode("ascii").rstrip("=")


def _b64url_decode(value: str) -> bytes:
    """
    Decode base64url that may have had its padding stripped.

    :param value: The base64url string.
    :return: The decoded bytes, or ``b""`` if the input was not valid base64.
    :rtype: bytes
    """
    padded = value + "=" * (-len(value) % 4)
    try:
        return base64.urlsafe_b64decode(padded.encode("ascii"))
    except Exception:
        return b""


def _verify_with_install_key(
    message: str, signature: bytes, public_key: str, algorithm: str
) -> bool:
    """
    Verify a signature against a public key.

    Used only against this installation's *own* registered key, to confirm it
    still holds the matching private half. It is never used to decide whether a
    payload is genuine - that is the offline token signature's job, handled by
    :meth:`LicenseClient.verify_offline_token`.

    :param message: The signed message.
    :param signature: The raw signature bytes.
    :param public_key: Base64 (Ed25519) or PEM (RSA) public key.
    :param algorithm: ``"ed25519"`` or ``"rsa-sha256"``.
    :return: True only when the signature verifies. Any failure, including a
        missing ``cryptography`` package, returns False.
    :rtype: bool
    """
    if not signature or not public_key:
        return False

    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import ed25519, padding
    except ImportError:
        return False

    try:
        if algorithm == "ed25519":
            key = ed25519.Ed25519PublicKey.from_public_bytes(
                base64.b64decode(public_key)
            )
            key.verify(signature, message.encode("utf-8"))
        else:
            key = serialization.load_pem_public_key(public_key.encode("ascii"))
            key.verify(
                signature, message.encode("utf-8"), padding.PKCS1v15(), hashes.SHA256()
            )
    except Exception:
        return False

    return True


def _live_features(payload: Dict[str, Any]) -> List[str]:
    """
    Filter a token's feature list down to those that have not themselves expired.

    A feature can expire before the license does. The server drops an already
    lapsed feature when it mints a token, but a token minted while a feature
    was still live carries it for the token's entire offline window - so
    without the signed per-feature dates, a feature would keep working for days
    after the server stopped granting it.

    A token with no ``feature_expiry`` map is read as "nothing expires", which
    is what the flat feature list meant before the map existed.

    :param payload: The verified token payload.
    :return: The still-live feature slugs.
    :rtype: list[str]
    """
    features = payload.get("features") or []
    if not isinstance(features, list):
        return []

    expiry = payload.get("feature_expiry")
    if not isinstance(expiry, dict):
        expiry = {}

    now = int(time.time())
    live: List[str] = []
    for slug in features:
        slug = str(slug)
        ends = _parse_iso8601(expiry.get(slug))
        if ends is not None and ends < now:
            continue
        live.append(slug)

    return live


def _parse_iso8601(value: Any) -> Optional[int]:
    """
    Convert an ISO-8601 timestamp to seconds since the epoch.

    Accepts a trailing ``Z``, an explicit numeric offset, or no zone at all -
    a value with no zone is read as UTC, matching what the server emits.

    :param value: The timestamp, or any falsy value.
    :return: Unix seconds, or None if it was empty or unparseable.
    :rtype: int | None
    """
    if not value:
        return None
    text = str(value).replace("Z", "+0000")
    for fmt in ("%Y-%m-%dT%H:%M:%S%z", "%Y-%m-%d %H:%M:%S%z", "%Y-%m-%dT%H:%M:%S"):
        try:
            import datetime

            parsed = datetime.datetime.strptime(text, fmt)
            if parsed.tzinfo is None:
                parsed = parsed.replace(tzinfo=datetime.timezone.utc)
            return int(parsed.timestamp())
        except ValueError:
            continue
    return None


def _default_verifier() -> Optional[Callable[[bytes, bytes, str, str], bool]]:
    """
    Build an offline-token signature verifier backed by ``cryptography``.

    The standard library cannot verify Ed25519 or RSA signatures, so this is
    the one place the SDK looks for outside help. If the package is not
    installed the caller gets None, and offline verification fails closed -
    a check that cannot be performed is never mistaken for one that passed.

    Pass your own ``verifier=`` to the client if you have a different crypto
    library available.

    :return: A callable ``(message, signature, public_key, algorithm) -> bool``,
        or None if ``cryptography`` is not installed.
    :rtype: callable | None
    """
    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import ed25519, padding
        from cryptography.exceptions import InvalidSignature
    except ImportError:
        return None

    def verify(message: bytes, signature: bytes, public_key: str, algorithm: str) -> bool:
        """
        Check one signature.

        Returns False rather than raising on any failure, so a verification
        that cannot be performed is never mistaken for one that passed.

        :param message: The signed bytes.
        :param signature: The raw signature bytes.
        :param public_key: Base64 (Ed25519) or PEM (RSA) public key.
        :param algorithm: ``"ed25519"`` or ``"rsa-sha256"``.
        :rtype: bool
        """
        try:
            if algorithm == "ed25519":
                raw = base64.b64decode(public_key)
                if len(raw) != 32:
                    return False
                ed25519.Ed25519PublicKey.from_public_bytes(raw).verify(signature, message)
                return True

            key = serialization.load_pem_public_key(public_key.encode("utf-8"))
            key.verify(signature, message, padding.PKCS1v15(), hashes.SHA256())
            return True
        except (InvalidSignature, ValueError, TypeError):
            return False

    return verify
