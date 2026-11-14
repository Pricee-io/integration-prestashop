<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Webhook settings added in 1.1.0, disabled until configured.
 *
 * PrestaShop only calls a function named after the file's version: any
 * other name counts as a failed upgrade and disables the module.
 *
 * @param Pricee $module
 */
function upgrade_module_1_1_0($module)
{
    Configuration::updateValue('PRICEE_WEBHOOK_ENABLED', 0);
    Configuration::updateValue('PRICEE_WEBHOOK_SECRET', '');

    return true;
}
