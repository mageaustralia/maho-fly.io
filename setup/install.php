<?php
/**
 * Maho installer for Fly.io with SQLite
 *
 * Bypasses the CLI installer's initMaho() bug by bootstrapping with
 * is_installed => false, which skips DB store initialization on empty databases.
 *
 * Usage: php install.php [install options from CLI]
 */
chdir("/app");
require "vendor/autoload.php";

// Pass CLI args through to the console installer
$_SERVER["argv"] = array_merge(["maho", "install"], array_slice($argv, 1));
array_shift($_SERVER["argv"]);
array_shift($_SERVER["argv"]);

Mage::register("isSecureArea", true, true);

// Key fix: is_installed => false prevents Mage::app() from trying to
// query core_store/core_website on an empty SQLite database
$app = Mage::app("", "store", ["is_installed" => false]);

$installer = Mage::getSingleton("install/installer_console");

if ($installer->init($app) && $installer->setArgs() && $installer->install()) {
    echo "Installation completed successfully\n";
} else {
    foreach ($installer->getErrors() as $e) {
        echo "ERROR: $e\n";
    }
    exit(1);
}
