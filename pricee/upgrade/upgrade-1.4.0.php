<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Webhook nonces move from a configuration value to their own table, so
 * concurrent calls cannot both consume the same nonce. The unused `pricee`
 * table created by earlier versions is dropped.
 *
 * @param Pricee $module
 */
function upgrade_module_1_4_0($module)
{
    if (!include __DIR__.'/../sql/install.php') {
        return false;
    }

    Configuration::deleteByName('PRICEE_WEBHOOK_NONCES');

    return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'pricee`');
}
