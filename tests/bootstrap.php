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

define('_PS_IN_TEST_', true);

require_once $psRootDir.'/config/config.inc.php';

if (0 !== strpos(_DB_NAME_, 'test_')) {
    fwrite(STDERR, 'Refusing to run the tests on the non-test database '._DB_NAME_.".\n");

    exit(1);
}
