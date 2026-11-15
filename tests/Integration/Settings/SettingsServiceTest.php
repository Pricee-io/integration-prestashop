<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Settings;

use PriceeIO\Settings\SettingsService;
use PriceeIO\Tests\Integration\IntegrationTestCase;

/**
 * Level the settings are saved at: credentials and webhook settings per shop
 * only, as each shop is its own website on Pricee.
 *
 * @internal
 *
 * @coversNothing
 */
class SettingsServiceTest extends IntegrationTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from no stored value at any level
        \Db::getInstance()->delete('configuration', "name LIKE 'PRICEE\\_%'");
        $this->resetCaches();

        $this->settings = new SettingsService();
    }

    protected function tearDown(): void
    {
        \Shop::setContext(\Shop::CONTEXT_SHOP, $this->shopId());

        parent::tearDown();
    }

    public function testWithoutMultistoreEverythingIsSavedGlobally(): void
    {
        $this->setMultistore(false);

        $this->assertSame(SettingsService::SCOPE_SINGLE, $this->settings->scope());
        $this->assertTrue($this->settings->editable());

        $this->settings->save($this->values());

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertSame('key', $this->storedValue('PRICEE_API_KEY', null, null));
        $this->assertSame('1', $this->storedValue('PRICEE_WEBHOOK_ENABLED', null, null));
        $this->assertSame('secret', $this->storedValue('PRICEE_WEBHOOK_SECRET', null, null));
        $this->assertSame([], $this->shopLevelKeys());
    }

    public function testAllShopsSavesNothing(): void
    {
        $this->setMultistore(true);
        $this->setContext(\Shop::CONTEXT_ALL);

        $this->assertSame(SettingsService::SCOPE_ALL, $this->settings->scope());
        $this->assertFalse($this->settings->editable());

        $this->settings->save($this->values());

        // An API key is scoped to one Pricee website: shared, it would only fit one shop
        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertNull($this->storedValue('PRICEE_API_KEY', null, null));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', null, null));
    }

    public function testGroupSavesNothing(): void
    {
        $this->setMultistore(true);
        $idGroup = (int) \Context::getContext()->shop->id_shop_group;
        $this->setContext(\Shop::CONTEXT_GROUP, $idGroup);

        $this->assertSame(SettingsService::SCOPE_GROUP, $this->settings->scope());
        $this->assertFalse($this->settings->editable());

        $this->settings->save($this->values());

        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', $idGroup, null));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', $idGroup, null));
    }

    public function testShopSavesItsOwnSettingsOnly(): void
    {
        $this->setMultistore(true);
        $this->setContext(\Shop::CONTEXT_SHOP, $this->shopId());

        $this->assertTrue($this->settings->editable());

        $this->settings->save($this->values());

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, $this->shopId()));
        $this->assertSame('key', $this->storedValue('PRICEE_API_KEY', null, $this->shopId()));
        $this->assertSame('secret', $this->storedValue('PRICEE_WEBHOOK_SECRET', null, $this->shopId()));
        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, $this->otherShopId()), 'other shops are untouched');
    }

    public function testEmptyApiKeyKeepsTheStoredOne(): void
    {
        $this->setMultistore(false);
        \Configuration::updateValue('PRICEE_API_KEY', 'stored-key');

        $this->settings->save(['PRICEE_CLIENT_ID' => 'client', 'PRICEE_API_KEY' => '']);

        $this->assertSame('stored-key', $this->storedValue('PRICEE_API_KEY', null, null));
    }

    public function testUpgradeMovesSharedCredentialsToTheShops(): void
    {
        $this->setMultistore(true);
        \Configuration::updateGlobalValue('PRICEE_CLIENT_ID', 'shared-client');
        \Configuration::updateGlobalValue('PRICEE_API_KEY', 'shared-key');
        // The other shop already uses its own credentials
        \Configuration::updateValue('PRICEE_CLIENT_ID', 'own-client', false, null, $this->otherShopId());
        \Configuration::updateValue('PRICEE_API_KEY', 'own-key', false, null, $this->otherShopId());
        $this->resetCaches();

        $this->settings->moveSharedCredentialsToShops();

        $this->assertSame('shared-client', $this->storedValue('PRICEE_CLIENT_ID', null, $this->shopId()));
        $this->assertSame('shared-key', $this->storedValue('PRICEE_API_KEY', null, $this->shopId()));
        $this->assertSame('own-client', $this->storedValue('PRICEE_CLIENT_ID', null, $this->otherShopId()));
        $this->assertSame('own-key', $this->storedValue('PRICEE_API_KEY', null, $this->otherShopId()));
        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, null), 'no shared credentials left');
        $this->assertNull($this->storedValue('PRICEE_API_KEY', null, null));
    }

    public function testUpgradeKeepsGlobalCredentialsWithoutMultistore(): void
    {
        $this->setMultistore(false);
        \Configuration::updateGlobalValue('PRICEE_CLIENT_ID', 'client');

        $this->settings->moveSharedCredentialsToShops();

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertSame([], $this->shopLevelKeys());
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        return [
            'PRICEE_CLIENT_ID' => 'client',
            'PRICEE_API_KEY' => 'key',
            'PRICEE_WEBHOOK_ENABLED' => '1',
            'PRICEE_WEBHOOK_SECRET' => 'secret',
        ];
    }

    private function setContext(int $context, ?int $id = null): void
    {
        \Shop::setContext($context, $id);
        $this->resetCaches();
    }

    /**
     * Value stored at exactly this level (null: no row), without fallback.
     */
    private function storedValue(string $key, ?int $idShopGroup, ?int $idShop): ?string
    {
        // A shop-level row also carries the shop's group: only the shop decides
        $where = 'name = \''.pSQL($key).'\''
            .(null === $idShop ? ' AND id_shop_group '.(null === $idShopGroup ? 'IS NULL' : '= '.$idShopGroup) : '')
            .' AND id_shop '.(null === $idShop ? 'IS NULL' : '= '.$idShop);
        $value = \Db::getInstance()->getValue('SELECT value FROM '._DB_PREFIX_.'configuration WHERE '.$where);

        return false === $value ? null : (string) $value;
    }

    /**
     * @return array<int, string>
     */
    private function shopLevelKeys(): array
    {
        return array_column(\Db::getInstance()->executeS(
            'SELECT name FROM '._DB_PREFIX_."configuration WHERE name LIKE 'PRICEE\\_%' AND id_shop IS NOT NULL"
        ) ?: [], 'name');
    }
}
