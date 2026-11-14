<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base of the tests running against the real PrestaShop install: every test
 * runs in a database transaction rolled back afterwards, and PrestaShop's
 * static caches are reset around it so no state leaks between tests.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Db::getInstance()->execute('START TRANSACTION');
        $this->resetCaches();
    }

    protected function tearDown(): void
    {
        // A statement that commits implicitly (TRUNCATE, ALTER, ...), e.g.
        // from a module hook, would leak this test's data into the next ones
        $stillInTransaction = (bool) \Db::getInstance()->getValue('SELECT @@in_transaction');

        \Db::getInstance()->execute('ROLLBACK');
        $this->resetCaches();

        parent::tearDown();

        if (!$stillInTransaction) {
            $this->fail('The test transaction was committed by a statement, so its data was not rolled back');
        }
    }

    protected function resetCaches(): void
    {
        \Cache::clean('*');
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        \Product::resetStaticCache();
        \SpecificPrice::resetStaticCache();
        \SpecificPrice::flushCache();
        \TaxManagerFactory::resetStaticCache();
        \GroupReduction::resetStaticCache();
        \Shop::resetStaticCache();
        \Shop::cacheShops(true);
        \Product::$_taxCalculationMethod = null;

        foreach (['group_price_display_method' => [], 'groups' => [], 'ps_unidentified_group' => null, 'ps_customer_group' => null, 'cache_reduction' => []] as $property => $value) {
            $reflection = new \ReflectionProperty(\Group::class, $property);
            $reflection->setValue(null, $value);
        }
    }

    protected function shopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    protected function homeCategoryId(): int
    {
        return (int) \Context::getContext()->shop->id_category;
    }

    /**
     * Set a configuration value globally and for the current shop, so it
     * applies whether multistore is on (shop value) or off (global value).
     *
     * @param mixed $value
     */
    protected function setConfiguration(string $key, $value): void
    {
        \Configuration::updateGlobalValue($key, $value);
        \Configuration::updateValue($key, $value, false, (int) \Context::getContext()->shop->id_shop_group, $this->shopId());
        $this->resetCaches();
    }

    /**
     * Turn the multistore feature on (with a second shop, created if the
     * install has only one) or off, whatever the install it runs on.
     */
    protected function setMultistore(bool $active): void
    {
        if ($active) {
            $this->otherShopId();
        }

        \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', $active ? 1 : 0);
        $this->resetCaches();
    }

    /**
     * A shop other than the current one, created if needed.
     */
    protected function otherShopId(): int
    {
        $idShop = (int) \Db::getInstance()->getValue('SELECT id_shop FROM '._DB_PREFIX_.'shop WHERE id_shop != '.$this->shopId());
        if ($idShop > 0) {
            return $idShop;
        }

        $current = \Context::getContext()->shop;
        $shop = new \Shop();
        $shop->name = 'Pricee test shop';
        $shop->id_shop_group = (int) $current->id_shop_group;
        $shop->id_category = (int) $current->id_category;
        $shop->theme_name = (string) $current->theme_name;
        $shop->active = true;
        $this->assertTrue($shop->add(), 'test shop could not be created');
        $this->resetCaches();

        return (int) $shop->id;
    }

    /**
     * An active language other than the shop default one, created if needed.
     */
    protected function createLanguage(): \Language
    {
        $idDefault = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $this->shopId());
        foreach (\Language::getLanguages(true, $this->shopId()) as $language) {
            if ((int) $language['id_lang'] !== $idDefault) {
                return new \Language((int) $language['id_lang']);
            }
        }

        $language = new \Language();
        $language->name = 'Pricee test language';
        $language->iso_code = 'zz';
        $language->locale = 'en-US';
        $language->language_code = 'en-us';
        $language->date_format_lite = 'Y-m-d';
        $language->date_format_full = 'Y-m-d H:i:s';
        $language->active = true;
        $this->assertTrue($language->add(), 'test language could not be created');
        $this->resetCaches();
        \Language::loadLanguages();

        return $language;
    }

    /**
     * @return array<int, string> the same value for every language
     */
    protected function multilang(string $value): array
    {
        $values = [];
        foreach (\Language::getIDs(false) as $idLang) {
            $values[(int) $idLang] = $value;
        }

        return $values;
    }

    /**
     * @param array<int> $categoryIds categories the product is in, besides its default one
     */
    protected function createProduct(string $name = 'Pricee test product', float $price = 10.0, array $categoryIds = [], int $idTaxRulesGroup = 0): \Product
    {
        $product = new \Product();
        $product->name = $this->multilang($name);
        $product->link_rewrite = $this->multilang(\Tools::str2url($name));
        $product->price = $price;
        $product->id_tax_rules_group = $idTaxRulesGroup;
        $product->id_category_default = $categoryIds[0] ?? $this->homeCategoryId();
        $product->active = true;
        $this->assertTrue($product->add(), 'test product could not be created');
        $product->addToCategories(array_unique(array_merge([$this->homeCategoryId()], $categoryIds)));

        return $product;
    }

    protected function createCategory(string $name, ?int $idParent = null): \Category
    {
        $category = new \Category();
        $category->name = $this->multilang($name);
        $category->link_rewrite = $this->multilang(\Tools::str2url($name));
        $category->id_parent = $idParent ?? $this->homeCategoryId();
        $category->active = true;
        $this->assertTrue($category->add(), 'test category could not be created');

        return $category;
    }

    /**
     * Tax rules group applying a single rate in the shop default country.
     */
    protected function createTaxRulesGroup(float $rate): int
    {
        $tax = new \Tax();
        $tax->name = $this->multilang('Pricee test tax');
        $tax->rate = $rate;
        $tax->active = true;
        $this->assertTrue($tax->add());

        $group = new \TaxRulesGroup();
        $group->name = 'Pricee test tax rules';
        $group->active = true;
        $this->assertTrue($group->add());

        $rule = new \TaxRule();
        $rule->id_tax_rules_group = (int) $group->id;
        $rule->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $rule->id_tax = (int) $tax->id;
        $rule->behavior = 0;
        $this->assertTrue($rule->add());

        return (int) $group->id;
    }

    /**
     * Whether visitors (not logged in) see prices tax included.
     */
    protected function setVisitorPricesTaxIncluded(bool $taxIncluded): void
    {
        $idGroup = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP');
        \Db::getInstance()->update('group', ['price_display_method' => $taxIncluded ? PS_TAX_INC : PS_TAX_EXC], 'id_group = '.$idGroup);
        $this->setConfiguration('PS_TAX', 1);
    }

    /**
     * @param array<string, mixed> $fields specific price fields overriding the defaults (all products, shops, currencies, countries, groups)
     */
    protected function createSpecificPrice(array $fields): \SpecificPrice
    {
        $specificPrice = new \SpecificPrice();
        $defaults = [
            'id_product' => 0,
            'id_product_attribute' => 0,
            'id_shop' => 0,
            'id_shop_group' => 0,
            'id_cart' => 0,
            'id_specific_price_rule' => 0,
            'id_currency' => 0,
            'id_country' => 0,
            'id_group' => 0,
            'id_customer' => 0,
            'from_quantity' => 1,
            'price' => -1,
            'reduction' => 0.1,
            'reduction_tax' => 1,
            'reduction_type' => 'percentage',
            'from' => '0000-00-00 00:00:00',
            'to' => '0000-00-00 00:00:00',
        ];
        foreach (array_merge($defaults, $fields) as $field => $value) {
            $specificPrice->{$field} = $value;
        }
        $this->assertTrue($specificPrice->add(), 'test specific price could not be created');
        $this->resetCaches();

        return $specificPrice;
    }
}
