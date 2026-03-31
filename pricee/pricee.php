<?php

/**
 * 2007-2026 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2026 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/vendor/autoload.php';

class Pricee extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'pricee';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Pricee.io';
        $this->need_instance = 0;

        // Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Pricee.io');
        $this->description = $this->l('PrestaShop integration with Pricee.io');

        $this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => '9.1.0'];
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('PRICEE_CLIENT_ID', null);
        Configuration::updateValue('PRICEE_API_KEY', null);
        Configuration::updateValue('PRICEE_WEBHOOK_ENABLED', null);
        Configuration::updateValue('PRICEE_WEBOOK_SECRET', null);

        include __DIR__.'/sql/install.php';

        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PRICEE_CLIENT_ID');
        Configuration::deleteByName('PRICEE_API_KEY');
        Configuration::deleteByName('PRICEE_WEBHOOK_ENABLED');
        Configuration::deleteByName('PRICEE_WEBOOK_SECRET');

        include __DIR__.'/sql/uninstall.php';

        return parent::uninstall();
    }

    /**
     * Load the configuration form.
     */
    public function getContent()
    {
        // If values have been submitted in the form, process.
        if (((bool) Tools::isSubmit('submitPriceeModule')) == true) {
            $this->postProcess();
        }

        $ajaxSyncLink = $this->get('router')->generate('priceeio_sync_admin');
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'id_lang' => $this->context->language->id,
            'categories' => $this->getCategories(),
            'ajax_sync_link' => $ajaxSyncLink,
        ]);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        $output .= $this->renderForm();

        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/sync.tpl');

        return $output;
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPriceeModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), // Add values for your inputs
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Structure of form.
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'name' => 'PRICEE_CLIENT_ID',
                        'label' => $this->l('ID Client'),
                    ],
                    [
                        'col' => 3,
                        'type' => 'password',
                        'name' => 'PRICEE_API_KEY',
                        'label' => $this->l('Clé API'),
                    ],
                    [
                        'col' => 3,
                        'type' => 'switch',
                        'label' => $this->l('Activer la synchronisation webhook'),
                        'name' => 'PRICEE_WEBHOOK_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'enabled_on',
                                'value' => 1,
                                'label' => $this->l('Oui'),
                            ],
                            [
                                'id' => 'enabled_off',
                                'value' => 0,
                                'label' => $this->l('Non'),
                            ],
                        ],
                        'desc' => $this->l('Active la mise à jour automatique des prix de vos produits depuis Pricee.'),
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'label' => $this->l('URL du Webhook'),
                        'name' => 'PRICEE_WEBHOOK_URL_DISPLAY',
                        'readonly' => true,
                        'prefix' => '<i class="icon icon-link"></i>',
                        'desc' => $this->l('Copiez cette URL pour configurer le webhook dans Pricee.io.'),
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'PRICEE_WEBHOOK_SECRET',
                        'label' => $this->l('Clé secrète webhook'),
                        'desc' => $this->l('Clé secrète pour valider la provenance des webhooks.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
            ],
        ];
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        $webhookUrl = $this->context->link->getModuleLink($this->name, 'webhook', [], true);

        return [
            'PRICEE_CLIENT_ID' => Configuration::get('PRICEE_CLIENT_ID', null),
            'PRICEE_API_KEY' => Configuration::get('PRICEE_API_KEY', null),
            'PRICEE_WEBHOOK_ENABLED' => Configuration::get('PRICEE_WEBHOOK_ENABLED', null),
            'PRICEE_WEBHOOK_SECRET' => Configuration::get('PRICEE_WEBHOOK_SECRET', null),
            'PRICEE_WEBHOOK_URL_DISPLAY' => $webhookUrl,
        ];
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Get all categories for selection.
     */
    private function getCategories()
    {
        $id_lang = $this->context->language->id;
        $rawCategories = Category::getCategories($id_lang, false, true);
        $categories = [];

        foreach ($rawCategories as $levelArray) {
            foreach ($levelArray as $catData) {
                if (!isset($catData['infos']['id_category'])) {
                    continue;
                }

                $catId = (int) $catData['infos']['id_category'];
                // Skip root category (id=1)
                if ($catId <= 1) {
                    continue;
                }

                // Load category
                $category = new Category($catId, $id_lang);

                // Make sure the object is fully loaded
                if (!Validate::isLoadedObject($category) || !$category->active) {
                    continue;
                }

                // Get product count
                $productCount = 0;

                try {
                    $productCount = $category->getProducts($id_lang, 0, 1, null, null, true, true, false, 1, false);
                } catch (Exception $e) {
                    $productCount = 0;
                }

                $categories[] = [
                    'id_category' => $category->id,
                    'name' => $category->name,
                    'product_count' => $productCount,
                ];
            }
        }

        return $categories;
    }
}
