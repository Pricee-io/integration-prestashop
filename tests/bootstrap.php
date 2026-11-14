<?php

/*
 * Integration tests run inside the PrestaShop container (see `make test`):
 * they boot the real PrestaShop install, whose autoloader is registered last
 * so its own Symfony and dependencies take precedence over the dev tools'.
 *
 * PrestaShop's test mode makes it use the `test_<database>` database, a
 * disposable copy rebuilt by `make test`, so the dev data is never modified.
 */

$psRootDir = getenv('PS_ROOT_DIR') ?: '/var/www/html';

if (!is_file($psRootDir.'/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop not found in {$psRootDir}: run the tests with `make test`.\n");

    exit(1);
}

// Module and test classes, without the root Composer autoloader: it holds the
// PHP 8 dev tools, while older PrestaShop versions run the tests on PHP 7.4
// with a standalone PHPUnit (see the compatibility CI job)
spl_autoload_register(function (string $class): void {
    foreach (['PriceeIO\Tests\\' => __DIR__.'/', 'PriceeIO\\' => __DIR__.'/../pricee/src/'] as $prefix => $directory) {
        if (0 === strpos($class, $prefix)) {
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

define('_PS_IN_TEST_', true);

require_once $psRootDir.'/config/config.inc.php';

if (0 !== strpos(_DB_NAME_, 'test_')) {
    fwrite(STDERR, 'Refusing to run the tests on the non-test database '._DB_NAME_.".\n");

    exit(1);
}

// Module tables, in case the dev database the test one is copied from was
// installed with an older version of the module (idempotent; run outside of
// the test transactions, as creating a table commits)
if (!include __DIR__.'/../pricee/sql/install.php') {
    fwrite(STDERR, "Could not create the module tables.\n");

    exit(1);
}
