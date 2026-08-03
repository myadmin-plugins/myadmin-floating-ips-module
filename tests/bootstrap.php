<?php

/**
 * PHPUnit bootstrap file for myadmin-floating-ips-module tests.
 *
 * Defines constants and stubs required by the Plugin class that are
 * normally provided by the MyAdmin framework at runtime.
 */

// Define constants used in Plugin::$settings static initialization
if (!defined('PRORATE_BILLING')) {
    define('PRORATE_BILLING', 1);
}

// Result-set mode flag the plugin passes to $db->next_record()
if (!defined('MYSQL_ASSOC')) {
    define('MYSQL_ASSOC', 1);
}

// Autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Stand-ins for the MyAdmin services the hook handlers call out to, so the
// handlers can be dispatched for real and asserted on by their effects.
require_once __DIR__ . '/stubs/framework.php';

// MyAdmin registers each module's settings on every request, and hands modules
// their database handle out of $GLOBALS['<module>_dbh'].
register_module(\Detain\MyAdminFloatingIps\Plugin::$module, \Detain\MyAdminFloatingIps\Plugin::$settings);
$GLOBALS['floating_ips_dbh'] = new \Detain\MyAdminFloatingIps\Tests\Support\DbDouble();
