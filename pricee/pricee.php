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
use PriceeIO\Service\SyncService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        $this->version = '1.3.5';
        $this->author = 'Pricee.io';
        $this->need_instance = 0;

        // Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Pricee.io');
        $this->description = $this->l('PrestaShop integration with Pricee.io');

        $this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => '9.2.1'];
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
        Configuration::updateValue('PRICEE_WEBHOOK_SECRET', null);

        include __DIR__.'/sql/install.php';

        return parent::install()
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PRICEE_CLIENT_ID');
        Configuration::deleteByName('PRICEE_API_KEY');
        Configuration::deleteByName('PRICEE_WEBHOOK_ENABLED');
        Configuration::deleteByName('PRICEE_WEBHOOK_SECRET');
        Configuration::deleteByName('PRICEE_WEBHOOK_NONCES');

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

        $router = $this->get('router');
        $ajaxSyncLink = $router instanceof UrlGeneratorInterface
            ? $router->generate('priceeio_sync_admin')
            : '';
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'id_lang' => $this->context->language->id,
            'shops' => $this->getShops(),
            'current_shop_id' => $this->getCurrentShopId(),
            'ajax_sync_link' => $ajaxSyncLink,
            'sync_token' => Tools::getAdminTokenLite('AdminModules'),
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
                        'desc' => $this->getWebhookUrlHelp(),
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
            // Read-only display field, not real configuration to persist
            if ('PRICEE_WEBHOOK_URL_DISPLAY' === $key) {
                continue;
            }

            $value = trim((string) Tools::getValue($key));

            // Password inputs are not repopulated by the form: an empty
            // submitted value means "keep the stored one", not "erase it".
            if ('' === $value && 'PRICEE_API_KEY' === $key) {
                continue;
            }

            Configuration::updateValue($key, $value);
        }
    }

    /**
     * Help of the webhook URL field: in multistore every shop receives its
     * webhook on its own URL, so each shop of the context is listed.
     *
     * @return array<int, string>
     */
    private function getWebhookUrlHelp(): array
    {
        $help = [$this->l('Copiez cette URL pour configurer le webhook dans Pricee.io.')];

        $shopIds = Shop::getContextListShopID();
        if (!Shop::isFeatureActive() || count($shopIds) < 2) {
            return $help;
        }

        $help[] = $this->l('Chaque boutique a sa propre URL de webhook :');
        foreach ($shopIds as $idShop) {
            $shop = new Shop((int) $idShop);
            if (!Validate::isLoadedObject($shop)) {
                continue;
            }

            $url = $this->context->link->getModuleLink($this->name, 'webhook', [], true, null, (int) $shop->id);
            $help[] = htmlspecialchars($shop->name.' : '.$url, ENT_QUOTES, 'UTF-8');
        }

        return $help;
    }

    /**
     * Shops of the current back-office context, for the sync shop selector:
     * in "all shops" context every shop is listed, in "group" context the
     * shops of the group, in "shop" context only the current shop. Each
     * shop comes with its own categories, as they differ between shops.
     *
     * @return array<int, array{id_shop: int, name: string, categories: array<int, array<string, mixed>>}>
     */
    private function getShops(): array
    {
        $shops = [];
        $idLang = (int) $this->context->language->id;

        foreach (Shop::getContextListShopID() as $idShop) {
            $shop = new Shop((int) $idShop);

            if (!Validate::isLoadedObject($shop)) {
                continue;
            }

            $shops[] = [
                'id_shop' => (int) $shop->id,
                'name' => $shop->name,
                'categories' => $this->getCategories($shop, $idLang),
            ];
        }

        return $shops;
    }

    /**
     * Shop preselected in the sync form: the current shop in "shop" context,
     * otherwise the first shop of the context.
     */
    private function getCurrentShopId(): int
    {
        if (Shop::CONTEXT_SHOP === Shop::getContext()) {
            return (int) $this->context->shop->id;
        }

        $shopIds = Shop::getContextListShopID();

        return [] !== $shopIds ? (int) reset($shopIds) : (int) $this->context->shop->id;
    }

    /**
     * Categories of a shop offered for sync, in tree order, with the number
     * of products a sync would take from each.
     *
     * @return array<int, array{id_category: int, name: string, product_count: int, active: bool}>
     */
    private function getCategories(Shop $shop, int $idLang): array
    {
        $idShop = (int) $shop->id;

        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name, c.active
            FROM '._DB_PREFIX_.'category c
            INNER JOIN '._DB_PREFIX_.'category_shop cs ON cs.id_category = c.id_category AND cs.id_shop = '.$idShop.'
            INNER JOIN '._DB_PREFIX_.'category_lang cl ON cl.id_category = c.id_category
                AND cl.id_lang = '.$idLang.' AND cl.id_shop = '.$idShop.'
            WHERE c.id_category > 1
            AND c.id_category != '.(int) $shop->id_category.'
            ORDER BY c.nleft'
        );

        $counts = [];
        $countRows = Db::getInstance()->executeS(
            'SELECT cp.id_category, COUNT(DISTINCT cp.id_product) AS product_count
            FROM '.SyncService::shopProductsSql($idShop).'
            GROUP BY cp.id_category'
        );
        foreach ($countRows ?: [] as $countRow) {
            $counts[(int) $countRow['id_category']] = (int) $countRow['product_count'];
        }

        // The global root (id 1) and the shop home category are not offered:
        // every product belongs to its home category, so it would sync the whole catalog
        $categories = [];
        foreach ($rows ?: [] as $row) {
            $idCategory = (int) $row['id_category'];
            $categories[] = [
                'id_category' => $idCategory,
                'name' => (string) $row['name'],
                'product_count' => $counts[$idCategory] ?? 0,
                'active' => (bool) $row['active'],
            ];
        }

        return $categories;
    }
}
