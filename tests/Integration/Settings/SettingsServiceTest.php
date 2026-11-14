<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Settings;

use PriceeIO\Settings\SettingsService;
use PriceeIO\Tests\Integration\IntegrationTestCase;

/**
 * Level the settings are saved at: credentials shared or per shop, webhook
 * settings per shop only.
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
        $this->assertTrue($this->settings->webhookEditable());

        $this->settings->save($this->values());

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertSame('key', $this->storedValue('PRICEE_API_KEY', null, null));
        $this->assertSame('1', $this->storedValue('PRICEE_WEBHOOK_ENABLED', null, null));
        $this->assertSame('secret', $this->storedValue('PRICEE_WEBHOOK_SECRET', null, null));
        $this->assertSame([], $this->shopLevelKeys());
    }

    public function testAllShopsSavesSharedCredentialsButNoWebhookSettings(): void
    {
        $this->setMultistore(true);
        $this->setContext(\Shop::CONTEXT_ALL);

        $this->assertSame(SettingsService::SCOPE_ALL, $this->settings->scope());
        $this->assertFalse($this->settings->webhookEditable());

        $this->settings->save($this->values());

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertSame('key', $this->storedValue('PRICEE_API_KEY', null, null));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_ENABLED', null, null), 'a webhook setting for all shops would only match one Pricee website');
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', null, null));
    }

    public function testAllShopsListsTheShopsUsingTheirOwnCredentials(): void
    {
        $this->setMultistore(true);
        $otherShop = new \Shop($this->otherShopId());
        \Configuration::updateValue('PRICEE_CLIENT_ID', 'own-client', false, (int) $otherShop->id_shop_group, (int) $otherShop->id);
        $this->setContext(\Shop::CONTEXT_ALL);

        $this->assertSame([$otherShop->name], $this->settings->credentialOverrides());
    }

    public function testAllShopsListsTheGroupsUsingTheirOwnCredentials(): void
    {
        $this->setMultistore(true);
        $idGroup = (int) \Context::getContext()->shop->id_shop_group;
        // Saved from the group context, as the module page would
        $this->setContext(\Shop::CONTEXT_GROUP, $idGroup);
        \Configuration::updateValue('PRICEE_API_KEY', 'group-key');
        $this->setContext(\Shop::CONTEXT_ALL);

        $this->assertSame([(new \ShopGroup($idGroup))->name.' (groupe)'], $this->settings->credentialOverrides());
    }

    public function testGroupSavesGroupCredentialsButNoWebhookSettings(): void
    {
        $this->setMultistore(true);
        $idGroup = (int) \Context::getContext()->shop->id_shop_group;
        $this->setContext(\Shop::CONTEXT_GROUP, $idGroup);

        $this->assertSame(SettingsService::SCOPE_GROUP, $this->settings->scope());
        $this->assertFalse($this->settings->webhookEditable());

        $this->settings->save($this->values());

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', $idGroup, null));
        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, null));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', $idGroup, null));
    }

    public function testShopWithOwnCredentialsSavesThemForThatShopOnly(): void
    {
        $this->setMultistore(true);
        \Configuration::updateGlobalValue('PRICEE_CLIENT_ID', 'shared-client');
        $this->setContext(\Shop::CONTEXT_SHOP, $this->shopId());

        $this->settings->save($this->values(), false);

        $this->assertSame('client', $this->storedValue('PRICEE_CLIENT_ID', null, $this->shopId()));
        $this->assertSame('shared-client', $this->storedValue('PRICEE_CLIENT_ID', null, null), 'the shared value is untouched');
        $this->resetCaches();
        $this->assertFalse($this->settings->usesSharedCredentials());
    }

    public function testShopSwitchingToSharedCredentialsDropsItsOwn(): void
    {
        $this->setMultistore(true);
        \Configuration::updateGlobalValue('PRICEE_CLIENT_ID', 'shared-client');
        \Configuration::updateValue('PRICEE_CLIENT_ID', 'own-client', false, null, $this->shopId());
        \Configuration::updateValue('PRICEE_API_KEY', 'own-key', false, null, $this->shopId());
        $this->setContext(\Shop::CONTEXT_SHOP, $this->shopId());
        $this->assertFalse($this->settings->usesSharedCredentials());

        // The credential fields are disabled in the form, so not submitted
        $this->settings->save(['PRICEE_WEBHOOK_ENABLED' => '1', 'PRICEE_WEBHOOK_SECRET' => 'secret'], true);

        $this->assertNull($this->storedValue('PRICEE_CLIENT_ID', null, $this->shopId()));
        $this->assertNull($this->storedValue('PRICEE_API_KEY', null, $this->shopId()));
        $this->resetCaches();
        $this->assertTrue($this->settings->usesSharedCredentials());
        $this->assertSame('shared-client', \Configuration::get('PRICEE_CLIENT_ID', null, null, $this->shopId()));
    }

    public function testShopSavesItsOwnWebhookSettings(): void
    {
        $this->setMultistore(true);
        $this->setContext(\Shop::CONTEXT_SHOP, $this->shopId());

        $this->settings->save($this->values(), true);

        $this->assertTrue($this->settings->webhookEditable());
        $this->assertSame('secret', $this->storedValue('PRICEE_WEBHOOK_SECRET', null, $this->shopId()));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', null, null));
        $this->assertNull($this->storedValue('PRICEE_WEBHOOK_SECRET', null, $this->otherShopId()), 'other shops are untouched');
    }

    public function testEmptyApiKeyKeepsTheStoredOne(): void
    {
        $this->setMultistore(false);
        \Configuration::updateValue('PRICEE_API_KEY', 'stored-key');

        $this->settings->save(['PRICEE_CLIENT_ID' => 'client', 'PRICEE_API_KEY' => '']);

        $this->assertSame('stored-key', $this->storedValue('PRICEE_API_KEY', null, null));
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
