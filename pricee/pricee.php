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
use PriceeIO\Controller\Admin\SyncController;
use PriceeIO\Service\SyncService;
use PriceeIO\Settings\SettingsService;
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
        $this->version = '1.5.1';
        $this->author = 'Pricee.io';
        $this->need_instance = 0;

        // Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Pricee.io');
        $this->description = $this->l('PrestaShop integration with Pricee.io');

        // No upper bound: a bound refuses the module on every later PrestaShop release
        $this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => _PS_VERSION_];
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

        if (!include __DIR__.'/sql/install.php') {
            return false;
        }

        return parent::install()
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PRICEE_CLIENT_ID');
        Configuration::deleteByName('PRICEE_API_KEY');
        Configuration::deleteByName('PRICEE_WEBHOOK_ENABLED');
        Configuration::deleteByName('PRICEE_WEBHOOK_SECRET');
        // Stored the webhook nonces before 1.4.0
        Configuration::deleteByName('PRICEE_WEBHOOK_NONCES');

        include __DIR__.'/sql/uninstall.php';

        return parent::uninstall();
    }

    /**
     * Load the configuration form.
     */
    public function getContent()
    {
        $notice = '';
        if (Tools::isSubmit('submitPriceeModule')) {
            $notice = $this->postProcess();
        }

        $router = $this->get('router');
        $ajaxSyncLink = $router instanceof UrlGeneratorInterface
            ? $router->generate('priceeio_sync_admin')
            : '';
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'shops' => $this->getShops(),
            'current_shop_id' => $this->getCurrentShopId(),
            'ajax_sync_link' => $ajaxSyncLink,
            'sync_token' => SyncController::token((int) $this->context->employee->id),
        ]);

        $output = $notice.$this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

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
        $settings = new SettingsService();
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

        $values = $settings->formValues();
        $values['PRICEE_SHARED_CREDENTIALS'] = (int) $settings->usesSharedCredentials();
        $values['PRICEE_WEBHOOK_URL_DISPLAY'] = $this->context->link->getModuleLink($this->name, 'webhook', [], true, null, (int) $this->context->shop->id);

        $helper->tpl_vars = [
            'fields_value' => $values,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $output = $helper->generateForm([$this->getConfigForm($settings)]);

        if (SettingsService::SCOPE_SHOP === $settings->scope()) {
            $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/settings.tpl');
        }

        return $output;
    }

    /**
     * Structure of form: what is editable depends on the level the
     * back-office context edits (see SettingsService).
     */
    protected function getConfigForm(SettingsService $settings)
    {
        $scope = $settings->scope();
        $inputs = [];

        if (SettingsService::SCOPE_SHOP === $scope) {
            $inputs[] = $this->switchInput(
                'PRICEE_SHARED_CREDENTIALS',
                $this->l('Utiliser les identifiants communs'),
                $this->l('Oui : cette boutique utilise les identifiants définis pour toutes les boutiques ou pour son groupe. Non : elle utilise ses propres identifiants ci-dessous.')
            );
        }

        $inputs[] = [
            'col' => 3,
            'type' => 'text',
            'prefix' => '<i class="icon icon-envelope"></i>',
            'name' => 'PRICEE_CLIENT_ID',
            'label' => $this->l('ID Client'),
            'desc' => $this->credentialsHelp($settings),
        ];
        $inputs[] = [
            'col' => 3,
            'type' => 'password',
            'name' => 'PRICEE_API_KEY',
            // The core template forces a 160px width on password
            // inputs: let it fill its column like the text inputs.
            'form_group_class' => 'pricee-fluid-input',
            'label' => $this->l('Clé API'),
        ];

        if ($settings->webhookEditable()) {
            $inputs[] = $this->switchInput(
                'PRICEE_WEBHOOK_ENABLED',
                $this->l('Activer la synchronisation webhook'),
                $this->l('Active la mise à jour automatique des prix de vos produits depuis Pricee.')
            );
            $inputs[] = [
                'col' => 5,
                'type' => 'text',
                'label' => $this->l('URL du Webhook'),
                'name' => 'PRICEE_WEBHOOK_URL_DISPLAY',
                'readonly' => true,
                'prefix' => '<i class="icon icon-link"></i>',
                'desc' => $this->l('Copiez cette URL pour configurer le webhook dans Pricee.io.'),
            ];
            $inputs[] = [
                'col' => 3,
                'type' => 'text',
                'name' => 'PRICEE_WEBHOOK_SECRET',
                'label' => $this->l('Clé secrète webhook'),
                'desc' => $this->l('Clé secrète pour valider la provenance des webhooks.'),
            ];
        } else {
            $inputs[] = [
                'type' => 'html',
                'name' => 'PRICEE_WEBHOOK_SHOP_ONLY',
                'label' => $this->l('Webhook'),
                'html_content' => '<div class="alert alert-info">'
                    .htmlspecialchars($this->l('Le webhook se configure boutique par boutique, car chaque boutique a son propre site et son propre webhook sur Pricee.io : sélectionnez une boutique dans le sélecteur de boutiques en haut de page pour le configurer.'), ENT_QUOTES, 'UTF-8')
                    .'</div>',
            ];
        }

        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration').' — '.$this->scopeLabel($scope),
                    'icon' => 'icon-cogs',
                ],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
            ],
        ];
    }

    /**
     * Save form data at the level of the back-office context.
     *
     * @return string confirmation or error message to show
     */
    protected function postProcess(): string
    {
        $settings = new SettingsService();
        $values = [];
        foreach (array_merge(SettingsService::CREDENTIAL_KEYS, SettingsService::WEBHOOK_KEYS) as $key) {
            $values[$key] = (string) Tools::getValue($key);
        }

        $errors = $settings->save($values, (bool) Tools::getValue('PRICEE_SHARED_CREDENTIALS'));
        if (in_array(SettingsService::ERROR_API_KEY_REQUIRED, $errors, true)) {
            return $this->displayError($this->l('Saisissez la clé API de cette boutique pour qu\'elle utilise ses propres identifiants. Les réglages n\'ont pas été enregistrés.'));
        }

        return $this->displayConfirmation($this->l('Réglages enregistrés.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function switchInput(string $name, string $label, string $desc): array
    {
        return [
            'col' => 3,
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'values' => [
                ['id' => $name.'_on', 'value' => 1, 'label' => $this->l('Oui')],
                ['id' => $name.'_off', 'value' => 0, 'label' => $this->l('Non')],
            ],
            'desc' => $desc,
        ];
    }

    /**
     * Name of the level the form edits, shown in its title.
     */
    private function scopeLabel(string $scope): string
    {
        switch ($scope) {
            case SettingsService::SCOPE_SHOP:
                return sprintf($this->l('Boutique : %s'), $this->context->shop->name);

            case SettingsService::SCOPE_GROUP:
                $group = new ShopGroup((int) Shop::getContextShopGroupID());

                return sprintf($this->l('Groupe : %s'), $group->name);

            case SettingsService::SCOPE_ALL:
                return $this->l('Toutes les boutiques');

            default:
                return (string) $this->context->shop->name;
        }
    }

    /**
     * Which shops the credentials being edited apply to, in multistore.
     */
    private function credentialsHelp(SettingsService $settings): string
    {
        $scope = $settings->scope();
        if (!in_array($scope, [SettingsService::SCOPE_ALL, SettingsService::SCOPE_GROUP], true)) {
            return '';
        }

        $applies = SettingsService::SCOPE_ALL === $scope
            ? $this->l('Ces identifiants s\'appliquent à toutes les boutiques')
            : $this->l('Ces identifiants s\'appliquent à toutes les boutiques du groupe');

        $overrides = $settings->credentialOverrides();
        if ([] === $overrides) {
            return $applies.'.';
        }

        return htmlspecialchars($applies.', '.$this->l('sauf celles qui ont leurs propres identifiants :').' '.implode(', ', $overrides).'.', ENT_QUOTES, 'UTF-8');
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

        $idCategories = SyncService::shopCategoryIds($idShop);
        if ([] === $idCategories) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name, c.active
            FROM '._DB_PREFIX_.'category c
            INNER JOIN '._DB_PREFIX_.'category_lang cl ON cl.id_category = c.id_category
                AND cl.id_lang = '.$idLang.' AND cl.id_shop = '.$idShop.'
            WHERE c.id_category IN ('.implode(',', $idCategories).')
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
