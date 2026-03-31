<?php

function upgradeModule($version)
{
    if (version_compare($version, '1.1.0', '<')) {
        Configuration::updateValue('PRICEE_WEBHOOK_ENABLED', 0);
        Configuration::updateValue('PRICEE_WEBHOOK_SECRET', '');
    }

    return true;
}
