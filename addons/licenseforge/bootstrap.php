<?php
/**
 * LicenseForge - shared bootstrap.
 * ================================
 *
 * Establishes the module's constants and registers its PSR-4 autoloader. Every
 * entry point includes this file before touching anything else:
 *
 *     modules/addons/licenseforge/licenseforge.php   the admin addon
 *     modules/addons/licenseforge/hooks.php          WHMCS hook handlers
 *     modules/addons/licenseforge/cron.php           scheduled maintenance
 *     modules/addons/licenseforge/download.php       release file delivery
 *     modules/addons/licenseforge/api/index.php      the public licensing API
 *     modules/servers/licenseforge/licenseforge.php  the provisioning module
 *
 * Including it more than once is safe and cheap: the second and subsequent
 * includes return immediately. That matters because WHMCS loads addon and
 * server modules independently and in an order this module does not control,
 * so any of the entry points above may turn out to be the first one.
 *
 * The file deliberately does nothing beyond defining constants and registering
 * the autoloader - no database access, no settings lookup, no side effects. It
 * runs on every request that touches the module, including ones that go on to
 * do nothing, so anything expensive placed here is paid for everywhere.
 *
 * @package LicenseForge
 * @version 1.0.0
 */

declare(strict_types=1);

/*
 * The PHP version gate runs before the autoloader is registered, and that
 * order is the point of it.
 *
 * The module's classes use typed properties, which are a parse error before
 * PHP 7.4. A parse error is raised when the file is *compiled*, not when the
 * offending line runs, so on an older PHP the failure surfaces as a syntax
 * error inside whichever class happened to be autoloaded first - naming a file
 * and a line number that have nothing to do with the real problem.
 *
 * Checking here converts that into a sentence saying which version is required
 * and which one is installed.
 */
if (PHP_VERSION_ID < 70400) {
    $message = 'LicenseForge requires PHP 7.4 or later. This server is running PHP ' . PHP_VERSION . '.';

    // CLI callers such as cron get the message on stderr and a
    // non-zero exit status, so a scheduled run fails loudly instead of looking
    // like it completed. Web callers get a 500 rather than a blank page.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    http_response_code(500);
    exit($message);
}

/*
 * Re-entry guard. `return` at file scope exits the include and hands control
 * back to the caller, leaving everything defined by the first pass intact.
 * Using it rather than wrapping the whole file in a condition keeps the guard
 * next to the constant it checks.
 */
if (defined('LICENSEFORGE_BOOTSTRAPPED')) {
    return;
}

/** Marks the bootstrap as complete; see the re-entry guard above. */
define('LICENSEFORGE_BOOTSTRAPPED', true);

/** The WHMCS module directory name, used to build module links and hook names. */
define('LICENSEFORGE_MODULE', 'licenseforge');

/**
 * The module version.
 *
 * Reported in the admin footer and to the licensing API, and used by the
 * upgrade path to decide which migrations still need to run. Keep it in step
 * with the version declared in the addon's config.
 */
define('LICENSEFORGE_VERSION', '1.0.0');

/** Absolute path to the addon directory. Everything else resolves from here. */
define('LICENSEFORGE_DIR', __DIR__);

/**
 * Prefix for every database table this module owns.
 *
 * Kept as a constant so the module's tables are recognisable in a WHMCS
 * database it shares with core tables and other addons.
 */
define('LICENSEFORGE_TABLE_PREFIX', 'lfg_');

/**
 * PSR-4 autoloader for the LicenseForge\ namespace, mapped onto lib/.
 *
 * `LicenseForge\Support\Net` resolves to lib/Support/Net.php.
 *
 * Registered rather than shipped as a Composer autoloader because a WHMCS
 * addon is dropped into an existing installation and cannot assume a
 * `composer install` step, or that its dependencies would survive one.
 *
 * Two details are deliberate:
 *
 *  - Classes outside the prefix return immediately, so this stays out of the
 *    way of WHMCS's own autoloader and any other addon's.
 *  - A missing file returns quietly instead of raising. That is what the
 *    autoloader contract requires: another registered autoloader may still be
 *    able to resolve the class, and throwing here would deny it the chance.
 *    A class that genuinely does not exist still fails, with PHP's own
 *    "class not found" error, which names the class rather than a file path.
 *
 * @param string $class Fully-qualified class name, as supplied by PHP.
 *
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'LicenseForge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = LICENSEFORGE_DIR . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
