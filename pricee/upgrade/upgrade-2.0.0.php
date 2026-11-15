<?php

use PriceeIO\Settings\SettingsService;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pricee API keys are now scoped to one website, so in multistore the
 * credentials are set per shop only: the ones set for all shops or a group
 * are copied to every shop without its own, then removed.
 *
 * @param Pricee $module
 */
function upgrade_module_2_0_0($module)
{
    require_once __DIR__.'/../vendor/autoload.php';

    (new SettingsService())->moveSharedCredentialsToShops();

    return true;
}
