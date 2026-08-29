# LicenseForge

Professional software licensing, activation, validation, and entitlement management for WHMCS.

LicenseForge is a self-contained licensing platform built around WHMCS. It connects licensing to WHMCS products and services, provisions licenses from orders, binds licenses to installations, exposes a small machine-facing API, and provides administration for suspension, revocation, reissuing, entitlements, version rules, abuse detection, notifications, and auditing.

> **Version:** 1.0.0  
> **Platform:** WHMCS 9.x  
> **PHP:** 8.2+ (8.3 recommended)

## Features

- WHMCS-native license provisioning
- License activation and periodic validation
- Installation binding by:
  - Domain
  - IP
  - Directory
  - Machine ID
- Configurable activation and reissue limits
- Billing-cycle, lifetime, and fixed-day license terms
- Grace periods and renewal behavior
- Feature entitlements
- Minimum/maximum/latest version policies
- Customer self-service reissues with optional administrator approval
- Protected WHMCS product downloads
- WHMCS email notifications
- Abuse detection and installation-churn signals
- Audit logging for licensing and security events
- Signed offline validation payloads
- API credentials with scopes and rate limits
- PHP, Python, and .NET client SDKs
- MySQL/MariaDB-backed migrations and transactional activation limits
- Multilingual UI through language files

## Architecture

LicenseForge consists of two cooperating WHMCS modules plus the client/API layer:

| Component | Purpose |
|---|---|
| **LicenseForge Addon** | Licensing engine, administration, API, security, audit log, maintenance, and signing infrastructure |
| **License Forge Provisioning** | Per-product licensing configuration, WHMCS service lifecycle integration, and customer license panel |
| **PHP SDK** | Redistributable client for activating and periodically checking licenses |
| **Licensing API** | Machine-facing `activate` and `check` operations |

The normal flow is:

```text
WHMCS Product
    ↓
Customer Order
    ↓
License Issued
    ↓
Software Activation
    ↓
Periodic Check
    ↓
Entitlements / Version Rules
```

WHMCS remains the commercial authority: a license is associated with the WHMCS service. A service suspension can restrict the license, while suspending a license does not automatically suspend billing.

## Requirements

### Supported environment

- WHMCS **9.x**
- PHP **8.2+**; PHP 8.3 recommended
- Smarty 4
- MySQL or MariaDB; MySQL 8.0 recommended
- `openssl`
- `json`
- `curl` for SDK clients
- `sodium` when Ed25519 offline signatures are used
- HTTPS

MySQL/MariaDB is a requirement rather than a generic database recommendation. LicenseForge's rate limiter relies on MySQL/MariaDB `INSERT ... ON DUPLICATE KEY UPDATE` behavior, and activation limits rely on transactional row locking with InnoDB.

Check the PHP environment with:

```bash
php -r 'printf("PHP %s\nopenssl:%d json:%d curl:%d sodium:%d\n",
  PHP_VERSION,
  extension_loaded("openssl"),
  extension_loaded("json"),
  extension_loaded("curl"),
  extension_loaded("sodium"));'
```

## Installation

Both modules are required for the complete WHMCS workflow.

### 1. Copy the modules

```bash
cp -r modules/addons/licenseforge /path/to/whmcs/modules/addons/
cp -r modules/servers/licenseforge /path/to/whmcs/modules/servers/
```

Set ownership and restrictive permissions appropriate for the web user:

```bash
cd /path/to/whmcs/modules/addons/licenseforge

chown -R www-data:www-data . ../../servers/licenseforge
find . -type d -exec chmod 750 {} \;
find . -type f -exec chmod 640 {} \;
```

### 2. Protect module internals

LicenseForge writes a deny-all `.htaccess` for protected content. Nginx does not process `.htaccess`, so explicitly deny access to internal directories:

```nginx
location ~ ^/modules/addons/licenseforge/(lib|templates|tests|sdk|storage)/ {
    deny all;
    return 404;
}
```

Keep the `api/` path reachable because it is the public licensing endpoint.

> **Security:** Never expose `storage/master-key.php`, signing keys, API secrets, or application internals over HTTP.

### 3. Activate the addon.

In WHMCS:

**System Settings → Addon Modules → LicenseForge → Activate**

Activation is transactional and idempotent. It:

1. Runs database migrations and creates the `lfg_*` tables.
2. Seeds default settings.
3. Seeds the built-in feature catalogue.
4. Generates the first offline signing key pair.
5. Creates a default API credential.
6. Installs the eight LicenseForge email templates.

### 4. Save the API secret

The API key and secret are shown on the activation confirmation.

**Copy the secret immediately.** The secret is only displayed at that point; if it is lost, rotate the credential. Rotation invalidates the old secret for clients using it.

### 5. Grant administrator access

Open:

**Configure → Access Control**

Select the WHMCS administrator roles allowed to manage licensing.

Then open the LicenseForge **Settings** tab and verify:

- **Applied migrations** lists the expected migrations.
- **Master key** reports that it matches the installation.

## First-Time Setup

After installation:

1. Confirm the WHMCS, PHP, database, and extension requirements.
2. Activate the LicenseForge addon.
3. Save the generated API credential.
4. Configure global defaults under **Addons → LicenseForge → Settings**.
5. Open **System Settings → Products/Services → Product → Module Settings**.
6. Set **Module Name** to **License Forge**.
7. Configure the product's licensing policy.
8. Create an API credential using only the scopes required by the integration.
9. Integrate the SDK.
10. Place a test order and verify license provisioning.
11. Activate the license from the application.
12. Verify that an installation record is created.
13. Test periodic validation and offline validation.
14. Test failure conditions before production.

Recommended failure-path tests include:

- Invalid license key
- Activation limit reached
- Domain mismatch
- Expired license
- Version rejection
- Replayed API request
- Offline signature failure

## Configuration

LicenseForge has three configuration levels:

1. **Addon Modules → LicenseForge → Configure** — bootstrap fields
2. **Addons → LicenseForge → Settings** — global defaults and operational policies
3. **Product → Module Settings** — product-specific licensing policy

Configuration inheritance is:

```text
Per-license override
        ↓
Product setting
        ↓
Global setting
        ↓
Built-in default
```

### Important product options

- Product slug
- Key prefix and key format
- License term
- Fixed term duration
- Trial period
- Maximum activations
- Maximum reissues
- Grace period
- Validation interval
- Offline validity
- Domain/IP/directory/machine binding
- Subdomain handling
- Local/development domain handling
- Customer reissue
- Reissue approval
- Minimum version
- Maximum version
- Latest version
- Upgrade behavior
- Renewal behavior
- Default feature entitlements

### License terms

| Term | Expiry | Typical use |
|---|---|---|
| `billing_cycle` | Associated service's next due date | Subscription products |
| `lifetime` | Never | Perpetual purchases |
| `fixed_days` | Fixed duration from issue date | Trials and fixed-term bundles |

For `billing_cycle`, paid renewal moves the license expiry to the new service due date.

## License Lifecycle

LicenseForge tracks a license through states such as:

```text
Pending
   ↓
Active
   ↓
Suspended ↔ Active
   ↓
Expired
   ↓
Revoked / Terminated
```

Typical transitions:

| Event | Result |
|---|---|
| Order accepted, invoice unpaid | `Pending` |
| Invoice paid | `Active` |
| WHMCS service suspended | `Suspended` |
| Service unsuspended | `Active`, unless an explicit hold prevents restoration |
| Expiry plus grace period | `Expired` |
| Administrator revokes | `Revoked` |
| Service terminated | `Terminated` |

Licensing suspension is deliberately separate from billing. A license may be suspended for administrative or abuse reasons without automatically changing the WHMCS service.

## Installation Binding

A license can be bound to one or more installation properties:

| Binding | Purpose |
|---|---|
| Domain | Restrict activation to a normalized host/domain |
| IP | Restrict activation to an observed source IP |
| Directory | Bind activation to an installation path |
| Machine ID | Bind activation to an application-generated machine identifier |

Activation slots are independent installation records. When the configured maximum is reached, a new activation returns `ACTIVATION_LIMIT`.

The final slot is allocated under a database row lock, preventing concurrent activations from exceeding the configured limit.

> **IP binding caution:** Dynamic IPs, load balancers, and CDNs can make IP locking unreliable. Domain or machine binding is generally more stable for installations that move between networks.

## Reissuing Licenses

Reissuing is the supported mechanism for moving a license to a new environment, such as during a domain or server migration.

Controls include:

- Maximum reissue count
- Global reissue cooldown
- Customer self-service
- Optional administrator approval
- Complete reissue history

Typical flow:

```text
New environment
      ↓
Activation detects old binding
      ↓
Customer requests/performs reissue
      ↓
Previous installation/key state is recorded
      ↓
New installation activates
```

## Feature Entitlements

LicenseForge supports per-license feature flags with optional expiry.

Example:

```php
if ($license->hasFeature('api_access')) {
    // Enable the premium API module.
}
```

Example feature catalogue:

```text
core
api_access
advanced_reports
white_label
```

These are examples only; define the feature catalogue according to the features exposed by your product.

## Version Restrictions

LicenseForge evaluates software version rules during validation.

Supported policies include:

- Minimum version
- Maximum version
- Latest/allowed version

Version rules are evaluated as versions rather than arbitrary strings.

## Product Downloads

LicenseForge integrates with WHMCS's existing product download system.

Add release files under:

**Support → Downloads**

Then associate them with the licensed product.

When download protection is enabled, downloads are hidden when the license is:

- Expired
- Suspended
- Revoked

A license within its grace period remains usable and continues to see its associated downloads.

LicenseForge controls access to the WHMCS downloads; it does not replace WHMCS download storage.

## Email Notifications

Activation installs eight WHMCS Product email templates:

| Template | Trigger |
|---|---|
| `LicenseForge License Created` | License issued for a new order |
| `LicenseForge License Activated` | First installation activation |
| `LicenseForge License Expiring` | Configured expiry threshold reached |
| `LicenseForge License Expired` | Expiry and grace period passed |
| `LicenseForge License Suspended` | License suspended |
| `LicenseForge License Reissued` | Reset/reissue completed |
| `LicenseForge Activation Limit Reached` | Activation refused because all slots are used |
| `LicenseForge Suspicious Activity` | Abuse signal raised |

Templates are ordinary WHMCS templates after installation and can be edited and translated normally.

Common merge fields:

```text
{$license_key}
{$license_status}
{$license_product}
{$license_expires}
{$license_domain}
{$license_activations}
{$license_reissues}
{$days_remaining}
{$previous_key}
{$activation_domain}
{$activation_ip}
{$status_reason}
{$abuse_signal}
{$abuse_summary}
```

Notifications are de-duplicated so the same reminder is not repeatedly sent for the same expiry date.

## Abuse Detection

LicenseForge analyzes licensing traffic already recorded by the module for signals associated with:

- Key sharing
- Key enumeration
- Excessive installation churn

Signals are generated from existing licensing traffic; the documentation specifies that nothing is sent to an external service.

Review abuse signals before enabling automatic suspension.

## Audit Log

The Audit Log is the primary place to investigate licensing decisions.

It records important state transitions with actor, IP, result, and metadata, including rejected lifecycle transitions.

Use it to answer questions such as:

- Why was an activation refused?
- Who suspended or revoked a license?
- Which API request failed?
- What abuse signal was raised?
- What happened during a reissue?

## Licensing API

The machine-facing API is intentionally small.

| Endpoint | Method | Scope | Purpose |
|---|---|---|---|
| `activate` | POST | `activate` | Bind a license to an installation |
| `check` | POST | `check` | Periodically validate an existing installation |

Both operations use POST requests. Request parameters are read from the body, and the signature covers `sha256(body)`.

The endpoint is named using `?action=`; the action is itself signed.

### Transport

HTTPS is mandatory for normal API traffic. Plain HTTP is refused except for loopback traffic.

If TLS terminates at a trusted reverse proxy, configure `trusted_proxies` so forwarded HTTPS information is accepted only from the configured proxy.

### API credentials

Use the smallest possible scopes.

For a normal product integration, use:

```text
activate
check
```

Do **not** distribute an `admin` credential to client software.

## Client SDKs

The repository includes client SDKs for:

```text
sdk/
├── LicenseClient.php
├── python/
│   └── licenseforge.py
└── dotnet/
    └── LicenseClient.cs
```

All clients use the same two-call protocol, request signing, caching behavior, and fail-closed validation model.

### PHP

Copy `sdk/LicenseClient.php` into the product. It is intended to be redistributed with your software and requires `ext-curl` and `ext-json`.

```php
require __DIR__ . '/vendor/licenseforge/LicenseClient.php';

use LicenseForge\SDK\LicenseClient;

$license = new LicenseClient([
    'license_key'          => $settings->get('license_key'),
    'product_id'           => 'my-product',
    'license_server'       => 'https://billing.example.com/modules/addons/licenseforge/api/index.php',

    'api_key'              => 'lfk_...',
    'api_secret'           => 'lfs_...',

    'public_key'           => 'A1b2C3...',
    'public_key_algorithm' => 'ed25519',

    'version'              => '2.4.0',

    // Must not be web-accessible.
    // Keep it across deployments; the per-installation secret
    // lives beside it as <cache_file>.install.
    'cache_file'           => '/var/lib/myproduct/license.cache',

    'cache_ttl'            => 86400,
    'grace_period'         => 259200,
    'timeout'              => 10,
    'retries'              => 2,
    'verify_tls'           => true,
]);
```

Keep `verify_tls` enabled.

### Python

```python
from licenseforge import LicenseClient

license = LicenseClient(
    license_server="https://billing.example.com/modules/addons/licenseforge/api/index.php",
    license_key=key_the_customer_entered,
    product_id="my-product",
    api_key="lfk_...",
    api_secret="...",
    public_key="...",
    version="2.4.0",
    cache_file="~/.myproduct/license.cache",
)

if not license.check().is_valid:
    raise SystemExit("This copy is not licensed.")

if license.has_feature("api_access"):
    enable_api()
```

### .NET

```csharp
using var license = new LicenseClient(new LicenseOptions {
    LicenseServer = "https://billing.example.com/modules/addons/licenseforge/api/index.php",
    LicenseKey    = keyTheCustomerEntered,
    ProductId     = "my-product",
    ApiKey        = "lfk_...",
    ApiSecret     = "...",
    PublicKey     = "<PEM RSA public key>",
    PublicKeyAlgorithm = "rsa-sha256",
    Version       = "2.4.0",
    CacheFile     = Path.Combine(appData, "license.cache"),
});

var result = await license.CheckAsync();

if (!result.IsValid)
{
    // Degrade or disable licensed functionality.
}
```

For .NET deployments, RSA-SHA256 is recommended when you want built-in public-key verification without supplying an Ed25519 verifier.

## Offline Validation

LicenseForge can return signed offline payloads so clients can continue validating within the configured offline validity period.

Supported signature algorithms include:

- Ed25519
- RSA-SHA256

Client support differs by platform. When verification is unavailable, the license is treated as **invalid**. The clients do not use a fail-open mode.

## Security

LicenseForge includes:

- CSRF protection for administrative actions
- Normalized and validated API input
- Encrypted storage of sensitive secrets
- Protected storage directory
- Audit logging of security-sensitive events
- Signed offline payloads
- Nonce and timestamp validation against request replay
- Credential scopes, expiry, and optional IP allow lists
- Rate limiting and abuse detection
- Timing-resistant authentication
- Installation binding
- Ed25519 or RSA signature verification

### Protect these assets

Never expose:

```text
storage/master-key.php
signing keys
API secrets
application internals
```

The master key is particularly important: encrypted signing keys and API secrets depend on it together with WHMCS's `CCEncryptionHash`.

## Database

LicenseForge stores its data in tables prefixed with `lfg_` and manages the schema through migrations.

Important table groups include:

- `lfg_migrations` — applied schema versions
- `lfg_settings` — global configuration
- `lfg_signing_keys` — offline signing key material and fingerprints
- `lfg_products` — per-product licensing policy
- License and activation tables
- Validation history and counter tables
- Audit tables
- Notification de-duplication tables

> **Do not edit the licensing schema manually.** Use the module migrations for upgrades.

## Cron & Maintenance

WHMCS daily cron already runs LicenseForge maintenance. For shorter grace periods or faster expiry enforcement, the standalone runner can be scheduled more frequently:

```cron
*/15 * * * * php /path/to/whmcs/modules/addons/licenseforge/cron.php --quiet
```

Available tasks:

| Task | Purpose |
|---|---|
| `expire` | Move due licenses to expired |
| `grace` | Open and close grace windows |
| `reminders` | Process expiry reminder emails |
| `abuse` | Run concurrent-installation/abuse sweeps |
| `cleanup` | Retention, token, and nonce cleanup |
| `stale` | Release installations that stopped checking in |
| `all` | Run all maintenance tasks |

Maintenance tasks are designed to be idempotent.

## Upgrading

Before upgrading:

1. Back up the database, including all `lfg_*` tables.
2. Back up the entire LicenseForge module directory.
3. Back up `storage/master-key.php`.

> **Do not lose `storage/master-key.php`.** Without the matching master key and WHMCS `CCEncryptionHash`, encrypted signing keys and API secrets cannot be decrypted.

Upgrade procedure:

1. Replace the module files.
2. Keep the existing `storage/` directory.
3. Open LicenseForge in the WHMCS admin area.
4. Allow outstanding migrations to run.
5. Check **Settings → Applied Migrations**.

Migrations are additive and should not be edited after release.

## Troubleshooting

### Activation fails with a database error

Verify that the MySQL user has sufficient permissions, including:

- `CREATE`
- `ALTER`
- `INDEX`

Then inspect **Settings → Applied Migrations** to identify where activation stopped.

### Signing key generation fails

Check:

```bash
php -m | grep -E 'openssl|sodium'
ls -la modules/addons/licenseforge/storage
```

Make sure `storage/` is writable by the web user.

### Licenses are not being created

Check:

1. Product **Module Name** is `License Forge`.
2. The provisioning module ran for the service.
3. `module_enabled` is enabled.
4. The Audit Log contains `service.license_provisioned`.

### Activation limit is reached unexpectedly

Old installations may still occupy slots. Inspect the license's installation records or release stale installations:

```bash
php cron.php --task=stale
```

### Machine mismatch after migration

Machine IDs can change when the environment changes. Use the documented reissue/reset activation workflow.

### IP mismatch

Dynamic IPs, load balancers, and CDNs can make IP locking unreliable. Prefer domain or machine binding where possible.

### Version is not supported

Compare the client version with the product's configured minimum, maximum, and allowed-version rules.

### Customer license panel is missing

The license is displayed on the customer's WHMCS product details page. Verify that:

- The license `client_id` matches the logged-in client.
- A customized `clientareaproductdetails.tpl` preserves the module output variable, such as `{$moduleclientarea}`.

### Downloads are missing

Verify that release files are associated with the correct WHMCS product. Expired, suspended, and revoked licenses hide protected downloads by design.

### Emails are not sending

Check:

1. Notifications are enabled.
2. Template configuration matches existing WHMCS templates.
3. The license has a customer/client association.
4. The WHMCS Email Message Log.

For server-side diagnostics, inspect the PHP error log. LicenseForge prefixes its server-side messages with `[LicenseForge]`.

```bash
grep LicenseForge /var/log/php-fpm/error.log | tail -50
```

## Production Checklist

Before enabling real customer traffic:

- [ ] HTTPS is enabled and verified.
- [ ] Supported WHMCS/PHP/database versions are installed.
- [ ] Both LicenseForge modules are installed.
- [ ] `storage/` is protected from web access.
- [ ] The activation API secret is securely stored.
- [ ] Administrator access is restricted with Access Control.
- [ ] Global licensing defaults are configured.
- [ ] Each licensed product uses **License Forge**.
- [ ] License term and renewal behavior are tested.
- [ ] Activation limits and binding rules are tested.
- [ ] Reissue behavior is tested.
- [ ] Version restrictions are tested.
- [ ] Feature entitlements are tested.
- [ ] Product downloads are associated correctly.
- [ ] Email templates are verified.
- [ ] Cron/maintenance is running.
- [ ] API credentials use only required scopes.
- [ ] Offline validation is tested.
- [ ] Audit logging has been reviewed.
- [ ] Abuse detection has been observed before automatic suspension is enabled.
- [ ] Database and module backups exist, including `storage/master-key.php`.

## Project Structure

```text
modules/
├── addons/
│   └── licenseforge/
│       ├── licenseforge.php
│       ├── bootstrap.php
│       ├── hooks.php
│       ├── cron.php
│       ├── whmcs.json
│       ├── logo.png
│       ├── api/
│       ├── lib/
│       ├── templates/
│       │   └── admin/
│       ├── assets/
│       │   ├── admin.css
│       │   ├── admin.js
│       │   ├── client.css
│       │   └── client-panel.js
│       ├── lang/
│       │   └── english.php
│       └── sdk/
│           ├── LicenseClient.php
│           ├── python/
│           │   └── licenseforge.py
│           └── dotnet/
│               └── LicenseClient.cs
│
└── servers/
    └── licenseforge/
        ├── licenseforge.php
        ├── whmcs.json
        ├── logo.png
        └── templates/
            └── clientarea.tpl
```

There is no build step or compilation requirement. The module's stylesheets and scripts are plain files.

## Languages & Translation

Human-readable LicenseForge strings are stored in language files.

```text
modules/addons/licenseforge/lang/
└── english.php
```

Translations can be created by copying the language file and editing its strings; PHP code changes are not required.

## License / Support

Built and supported by **Ahmad Abu Assab** and **Ovidiu Tufan**.
