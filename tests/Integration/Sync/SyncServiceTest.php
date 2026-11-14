<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Sync;

use PriceeIO\Service\ApiService;
use PriceeIO\Service\SyncService;
use PriceeIO\Tests\Integration\IntegrationTestCase;

/**
 * Which categories and products a sync takes, and what it sends to Pricee.
 *
 * @internal
 *
 * @coversNothing
 */
class SyncServiceTest extends IntegrationTestCase
{
    private RecordingHttpClient $api;

    private SyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfiguration('PRICEE_CLIENT_ID', 'client');
        $this->setConfiguration('PRICEE_API_KEY', 'key');

        $this->api = new RecordingHttpClient();
        $this->service = new SyncService(new ApiService($this->api->client()));
    }

    // ---------------------------------------------------------------
    // Categories offered
    // ---------------------------------------------------------------

    public function testOffersTheShopCategoriesBelowItsHomeCategory(): void
    {
        $parent = $this->createCategory('Pricee parent');
        $child = $this->createCategory('Pricee child', (int) $parent->id);

        $ids = SyncService::shopCategoryIds($this->shopId());

        $this->assertContains((int) $parent->id, $ids);
        $this->assertContains((int) $child->id, $ids);
        $this->assertNotContains($this->homeCategoryId(), $ids, 'the home category holds every product');
        $this->assertNotContains((int) \Configuration::get('PS_ROOT_CATEGORY'), $ids);
    }

    public function testDoesNotOfferCategoriesOfAnotherTree(): void
    {
        // Another shop's tree, under the root, also associated with this shop
        $otherHome = $this->createCategory('Pricee other home', (int) \Configuration::get('PS_ROOT_CATEGORY'));
        $otherChild = $this->createCategory('Pricee other child', (int) $otherHome->id);

        $ids = SyncService::shopCategoryIds($this->shopId());

        $this->assertNotContains((int) $otherHome->id, $ids);
        $this->assertNotContains((int) $otherChild->id, $ids);
    }

    public function testDoesNotOfferCategoriesNotAssociatedWithTheShop(): void
    {
        $category = $this->createCategory('Pricee unassociated');
        \Db::getInstance()->delete('category_shop', 'id_category = '.(int) $category->id.' AND id_shop = '.$this->shopId());

        $this->assertNotContains((int) $category->id, SyncService::shopCategoryIds($this->shopId()));
    }

    // ---------------------------------------------------------------
    // Products sent
    // ---------------------------------------------------------------

    public function testSendsTheActiveProductsOfTheCheckedCategoriesOnce(): void
    {
        $checked = $this->createCategory('Pricee checked');
        $alsoChecked = $this->createCategory('Pricee also checked');
        $unchecked = $this->createCategory('Pricee unchecked');

        $inBoth = $this->createProduct('Pricee in both', 10, [(int) $checked->id, (int) $alsoChecked->id]);
        $inChecked = $this->createProduct('Pricee in checked', 10, [(int) $checked->id]);
        $inUnchecked = $this->createProduct('Pricee in unchecked', 10, [(int) $unchecked->id]);
        $inactive = $this->createProduct('Pricee inactive', 10, [(int) $checked->id]);
        $inactive->active = false;
        $inactive->update();

        $result = $this->service->sync($this->shopId(), [(int) $checked->id, (int) $alsoChecked->id]);

        $sent = $this->sentExtIds();
        $this->assertSame(2, $result['synced']);
        $this->assertSame(2, $result['total']);
        $this->assertTrue($result['done']);
        $this->assertEqualsCanonicalizing([(string) $inBoth->id, (string) $inChecked->id], $sent);
        $this->assertNotContains((string) $inUnchecked->id, $sent);
        $this->assertNotContains((string) $inactive->id, $sent);
    }

    public function testSkipsProductsDisabledInTheSyncedShopOnly(): void
    {
        $category = $this->createCategory('Pricee category');
        $product = $this->createProduct('Pricee disabled here', 10, [(int) $category->id]);
        // Active in the default product table, disabled for this shop
        \Db::getInstance()->update('product_shop', ['active' => 0], 'id_product = '.(int) $product->id.' AND id_shop = '.$this->shopId());

        $this->assertSame(0, $this->service->sync($this->shopId(), [(int) $category->id])['total']);
        $this->assertSame([], $this->sentExtIds());
    }

    public function testSkipsProductsNotAssociatedWithTheSyncedShop(): void
    {
        $category = $this->createCategory('Pricee category');
        $product = $this->createProduct('Pricee other shop product', 10, [(int) $category->id]);
        \Db::getInstance()->delete('product_shop', 'id_product = '.(int) $product->id.' AND id_shop = '.$this->shopId());

        $this->assertSame(0, $this->service->sync($this->shopId(), [(int) $category->id])['total']);
        $this->assertSame([], $this->sentExtIds());
    }

    public function testSendsTheProductPageUrlAndId(): void
    {
        $category = $this->createCategory('Pricee category');
        $product = $this->createProduct('Pricee url product', 10, [(int) $category->id]);

        $this->service->sync($this->shopId(), [(int) $category->id]);

        $created = $this->api->requestsTo('POST', 'website_products');
        $this->assertCount(1, $created);
        $this->assertSame((string) $product->id, $created[0]['json']['extId']);

        $expectedUrl = \Context::getContext()->link->getProductLink(
            new \Product((int) $product->id, false, $this->shopDefaultLanguageId(), $this->shopId()),
            null,
            null,
            null,
            $this->shopDefaultLanguageId(),
            $this->shopId()
        );
        $this->assertSame($expectedUrl, $created[0]['json']['url']);
        $this->assertStringNotContainsString('product_rule', $created[0]['json']['url']);
    }

    public function testUsesTheShopDefaultLanguageNotTheBackOfficeUserLanguage(): void
    {
        $category = $this->createCategory('Pricee category');
        $userLanguage = $this->createLanguage();
        $product = $this->createProduct('Pricee language product', 10, [(int) $category->id]);
        $product->link_rewrite[$this->shopDefaultLanguageId()] = 'shop-language-rewrite';
        $product->link_rewrite[(int) $userLanguage->id] = 'user-language-rewrite';
        $this->assertTrue((bool) $product->update());

        // The back-office user browses in another language than the shop's
        $context = \Context::getContext();
        $previousLanguage = $context->language;
        $context->language = $userLanguage;

        try {
            $this->service->sync($this->shopId(), [(int) $category->id]);
        } finally {
            $context->language = $previousLanguage;
        }

        $created = $this->api->requestsTo('POST', 'website_products');
        $this->assertCount(1, $created);
        $this->assertStringContainsString('shop-language-rewrite', $created[0]['json']['url']);
        $this->assertStringNotContainsString('user-language-rewrite', $created[0]['json']['url']);
    }

    // ---------------------------------------------------------------
    // Website
    // ---------------------------------------------------------------

    public function testCreatesTheShopWebsiteWhenMissing(): void
    {
        $category = $this->createCategory('Pricee category');
        $this->createProduct('Pricee product', 10, [(int) $category->id]);

        $this->service->sync($this->shopId(), [(int) $category->id]);

        $created = $this->api->requestsTo('POST', 'websites');
        $this->assertCount(1, $created);
        $this->assertSame(rtrim($this->shopFrontUrl(), '/'), $created[0]['json']['url']);
    }

    public function testReusesTheShopWebsiteWhateverItsScheme(): void
    {
        $category = $this->createCategory('Pricee category');
        $this->createProduct('Pricee product', 10, [(int) $category->id]);
        $url = rtrim($this->shopFrontUrl(), '/');
        $otherScheme = 0 === strpos($url, 'https://') ? 'http://'.substr($url, 8) : 'https://'.substr($url, 7);
        $this->api->addWebsite($otherScheme.'/');

        $this->service->sync($this->shopId(), [(int) $category->id]);

        $this->assertSame([], $this->api->requestsTo('POST', 'websites'));
        $this->assertCount(1, $this->api->requestsTo('POST', 'website_products'));
    }

    public function testLogsInOncePerBatch(): void
    {
        $category = $this->createCategory('Pricee category');
        $this->createProduct('Pricee product', 10, [(int) $category->id]);

        $this->service->sync($this->shopId(), [(int) $category->id]);

        $this->assertCount(1, $this->api->requestsTo('POST', 'login'));
    }

    public function testNothingToSyncDoesNotCallPricee(): void
    {
        $category = $this->createCategory('Pricee empty category');

        $result = $this->service->sync($this->shopId(), [(int) $category->id]);

        $this->assertSame(['synced' => 0, 'failed' => 0, 'lastId' => 0, 'done' => true, 'total' => 0], $result);
        $this->assertSame([], $this->api->requests);
    }

    // ---------------------------------------------------------------
    // Batches
    // ---------------------------------------------------------------

    public function testLargeSelectionIsSyncedInBatchesWithoutLimit(): void
    {
        $category = $this->createCategory('Pricee large category');
        $ids = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = (string) $this->createProduct('Pricee batch product '.$i, 10, [(int) $category->id])->id;
        }

        // Batches of 2, following the cursor like the module page does
        $batches = [];
        $afterId = 0;
        do {
            $result = $this->service->sync($this->shopId(), [(int) $category->id], $afterId, 2);
            $batches[] = $result['synced'];
            $this->assertSame(5, $result['total']);
            $this->assertGreaterThan($afterId, $result['lastId']);
            $afterId = $result['lastId'];
        } while (!$result['done'] && count($batches) < 10);

        $this->assertSame([2, 2, 1], $batches);
        $this->assertSame($ids, $this->sentExtIds(), 'every product sent once, in ID order');
        $this->assertSame(end($ids), (string) $afterId);
    }

    public function testBatchEndingExactlyOnTheLastProductIsDone(): void
    {
        $category = $this->createCategory('Pricee category');
        $this->createProduct('Pricee first', 10, [(int) $category->id]);
        $this->createProduct('Pricee second', 10, [(int) $category->id]);

        $result = $this->service->sync($this->shopId(), [(int) $category->id], 0, 2);

        $this->assertSame(2, $result['synced']);
        $this->assertTrue($result['done'], 'no empty extra batch is needed');
    }

    public function testFailedProductIsCountedAndTheBatchGoesOn(): void
    {
        $category = $this->createCategory('Pricee category');
        $first = $this->createProduct('Pricee first', 10, [(int) $category->id]);
        $this->createProduct('Pricee second', 10, [(int) $category->id]);
        $this->api->failProductCreationFor = (string) $first->id;

        $result = $this->service->sync($this->shopId(), [(int) $category->id]);

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertTrue($result['done']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function sentExtIds(): array
    {
        return array_map(fn (array $request) => (string) $request['json']['extId'], $this->api->requestsTo('POST', 'website_products'));
    }

    private function shopDefaultLanguageId(): int
    {
        return (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $this->shopId());
    }

    private function shopFrontUrl(): string
    {
        $ssl = (bool) \Configuration::get('PS_SSL_ENABLED', null, null, $this->shopId());

        return \Context::getContext()->link->getBaseLink($this->shopId(), $ssl);
    }
}
