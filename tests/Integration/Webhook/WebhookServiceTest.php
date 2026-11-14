<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PriceeIO\Tests\Integration\IntegrationTestCase;
use PriceeIO\Webhook\WebhookRejectedException;
use PriceeIO\Webhook\WebhookService;

/**
 * Webhook contract (AGENTS.md): signed payload, replay protection, product
 * matching and where the received price is written.
 *
 * @internal
 *
 * @coversNothing
 */
class WebhookServiceTest extends IntegrationTestCase
{
    private const SECRET = 'test-secret';

    private WebhookService $service;

    private string $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfiguration('PRICEE_WEBHOOK_ENABLED', 1);
        $this->setConfiguration('PRICEE_WEBHOOK_SECRET', self::SECRET);
        $this->setConfiguration(WebhookService::NONCE_CONFIGURATION_KEY, '');
        // Prices as stored, unless a test switches to tax-included display
        $this->setVisitorPricesTaxIncluded(false);

        $this->currency = (string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code;
        $this->service = new WebhookService();
    }

    // ---------------------------------------------------------------
    // Whole call refused
    // ---------------------------------------------------------------

    public function testRejectsMissingSignature(): void
    {
        $this->assertRejected(400, 'Missing information for webhook', function () {
            $this->service->handle($this->body([$this->entry()]), '', $this->shopId());
        });
    }

    public function testRejectsWhenWebhookDisabled(): void
    {
        $this->setConfiguration('PRICEE_WEBHOOK_ENABLED', 0);

        $this->assertRejected(401, 'Webhook not configured', fn () => $this->send([$this->entry()]));
    }

    public function testRejectsWhenNoSecret(): void
    {
        $this->setConfiguration('PRICEE_WEBHOOK_SECRET', '');

        $this->assertRejected(401, 'Webhook not configured', fn () => $this->send([$this->entry()]));
    }

    public function testRejectsInvalidSignature(): void
    {
        $product = $this->createProduct();
        $body = $this->body([$this->entry(['extId' => (string) $product->id])]);

        $this->assertRejected(401, 'Invalid signature', function () use ($body) {
            $this->service->handle($body, hash_hmac('sha256', $body, 'other-secret'), $this->shopId());
        });
        $this->assertProductPrice($product, 10.0);
    }

    public function testSignatureCoversTheRawBody(): void
    {
        $body = $this->body([$this->entry()]);
        $signature = hash_hmac('sha256', $body, self::SECRET);

        // Same JSON content, different bytes: the signature no longer matches
        $this->assertRejected(401, 'Invalid signature', function () use ($body, $signature) {
            $this->service->handle($body.' ', $signature, $this->shopId());
        });
    }

    public function testRejectsInvalidJson(): void
    {
        $this->assertRejected(400, 'Invalid JSON payload', function () {
            $body = 'not json';
            $this->service->handle($body, hash_hmac('sha256', $body, self::SECRET), $this->shopId());
        });
    }

    public function testRejectsEntryWithoutNonce(): void
    {
        $entry = $this->entry();
        unset($entry['nonce']);

        $this->assertRejected(401, 'Missing timestamp or nonce', fn () => $this->send([$entry]));
    }

    public function testRejectsEntryWithoutTimestamp(): void
    {
        $entry = $this->entry();
        unset($entry['timestamp']);

        $this->assertRejected(401, 'Missing timestamp or nonce', fn () => $this->send([$entry]));
    }

    public function testRejectsExpiredPayload(): void
    {
        $product = $this->createProduct();
        $entry = $this->entry(['extId' => (string) $product->id, 'timestamp' => time() - WebhookService::WEBHOOK_MAX_AGE - 1]);

        $this->assertRejected(401, 'Expired webhook payload', fn () => $this->send([$entry]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testOneStaleEntryRejectsTheWholeCall(): void
    {
        $product = $this->createProduct();
        $entries = [
            $this->entry(['extId' => (string) $product->id]),
            $this->entry(['timestamp' => time() - WebhookService::WEBHOOK_MAX_AGE - 1]),
        ];

        $this->assertRejected(401, 'Expired webhook payload', fn () => $this->send($entries));
        $this->assertProductPrice($product, 10.0);
    }

    public function testRejectsReplayedPayload(): void
    {
        $product = $this->createProduct();
        $entries = [$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 8.5])];

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send($entries));

        // Same call again, e.g. captured and resent: refused
        $this->resetProductPrice($product, 10.0);
        $this->assertRejected(401, 'Replayed webhook payload', fn () => $this->send($entries));
        $this->assertProductPrice($product, 10.0);
    }

    public function testNonceIsAcceptedAgainOnceExpired(): void
    {
        $entries = [$this->entry()];
        $this->send($entries);

        // Past the nonce TTL the stored nonce is dropped; the timestamp must
        // still be fresh, so the call is signed again for the later time
        $later = time() + WebhookService::NONCE_TTL + 1;
        $entries[0]['timestamp'] = $later;
        $body = $this->body($entries);

        $this->assertSame(
            [WebhookService::SKIPPED_UNKNOWN_PRODUCT],
            $this->service->handle($body, hash_hmac('sha256', $body, self::SECRET), $this->shopId(), $later)
        );
    }

    public function testBatchSharingOneNonceIsAccepted(): void
    {
        $first = $this->createProduct('Pricee first');
        $second = $this->createProduct('Pricee second');
        $nonce = bin2hex(random_bytes(16));

        $outcomes = $this->send([
            $this->entry(['extId' => (string) $first->id, 'bestPriceAmount' => 7, 'nonce' => $nonce]),
            $this->entry(['extId' => (string) $second->id, 'bestPriceAmount' => 6, 'nonce' => $nonce]),
        ]);

        $this->assertSame([WebhookService::UPDATED_PRICE, WebhookService::UPDATED_PRICE], $outcomes);
        $this->assertProductPrice($first, 7.0);
        $this->assertProductPrice($second, 6.0);
    }

    // ---------------------------------------------------------------
    // Product matching
    // ---------------------------------------------------------------

    public function testMatchesProductByExtId(): void
    {
        $product = $this->createProduct();

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 9.9])]));
        $this->assertProductPrice($product, 9.9);
    }

    public function testUnknownExtIdIsSkippedWithoutFallingBackToTheUrl(): void
    {
        $product = $this->createProduct();

        $outcomes = $this->send([$this->entry([
            'extId' => '999999999',
            'url' => 'https://shop.example/index.php?controller=product&id_product='.$product->id,
        ])]);

        $this->assertSame([WebhookService::SKIPPED_UNKNOWN_PRODUCT], $outcomes);
        $this->assertProductPrice($product, 10.0);
    }

    public function testMatchesProductByIdProductUrlWithoutExtId(): void
    {
        $product = $this->createProduct();

        $outcomes = $this->send([$this->entry([
            'extId' => null,
            'url' => 'https://shop.example/index.php?controller=product&id_product='.$product->id,
        ])]);

        $this->assertSame([WebhookService::UPDATED_PRICE], $outcomes);
        $this->assertProductPrice($product, 9.0);
    }

    public function testMatchesProductByRewrittenUrlWithoutExtId(): void
    {
        $product = $this->createProduct('Pricee Rewrite Product');

        $outcomes = $this->send([
            $this->entry(['extId' => null, 'url' => 'https://shop.example/fr/accueil/'.$product->id.'-pricee-rewrite-product.html']),
        ]);

        $this->assertSame([WebhookService::UPDATED_PRICE], $outcomes);
        $this->assertProductPrice($product, 9.0);
    }

    public function testRewrittenUrlWithAnotherProductNameIsSkipped(): void
    {
        $product = $this->createProduct('Pricee Rewrite Product');

        $outcomes = $this->send([
            $this->entry(['extId' => null, 'url' => 'https://shop.example/'.$product->id.'-some-other-product.html']),
        ]);

        $this->assertSame([WebhookService::SKIPPED_UNKNOWN_PRODUCT], $outcomes);
        $this->assertProductPrice($product, 10.0);
    }

    public function testProductNotAssociatedWithTheShopIsSkipped(): void
    {
        $product = $this->createProduct();
        \Db::getInstance()->delete('product_shop', 'id_product = '.(int) $product->id.' AND id_shop = '.$this->shopId());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_OTHER_SHOP], $this->send([$this->entry(['extId' => (string) $product->id])]));
    }

    public function testProductWithCombinationsIsSkipped(): void
    {
        $product = $this->createProduct();
        $combination = new \Combination();
        $combination->id_product = (int) $product->id;
        $this->assertTrue($combination->add());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_UNSUPPORTED_TYPE], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    // ---------------------------------------------------------------
    // Price validation
    // ---------------------------------------------------------------

    public function testEntryWithoutPriceIsSkipped(): void
    {
        $product = $this->createProduct();
        $entry = $this->entry(['extId' => (string) $product->id]);
        unset($entry['bestPriceAmount']);

        $this->assertSame([WebhookService::SKIPPED_NO_PRICE], $this->send([$entry]));
    }

    public function testInvalidPricesAreSkipped(): void
    {
        $products = [$this->createProduct('Pricee zero'), $this->createProduct('Pricee negative'), $this->createProduct('Pricee text')];

        $outcomes = $this->send([
            $this->entry(['extId' => (string) $products[0]->id, 'bestPriceAmount' => 0]),
            $this->entry(['extId' => (string) $products[1]->id, 'bestPriceAmount' => -5]),
            $this->entry(['extId' => (string) $products[2]->id, 'bestPriceAmount' => 'abc']),
        ]);

        $this->assertSame(array_fill(0, 3, WebhookService::SKIPPED_INVALID_PRICE), $outcomes);
        foreach ($products as $product) {
            $this->assertProductPrice($product, 10.0);
        }
    }

    public function testPriceInAnotherCurrencyIsSkipped(): void
    {
        $otherCurrencyProduct = $this->createProduct('Pricee other currency');
        $noCurrencyProduct = $this->createProduct('Pricee no currency');
        $otherCurrency = 'EUR' === strtoupper($this->currency) ? 'USD' : 'EUR';

        $outcomes = $this->send([
            $this->entry(['extId' => (string) $otherCurrencyProduct->id, 'bestPriceCurrency' => $otherCurrency]),
            $this->entry(['extId' => (string) $noCurrencyProduct->id, 'bestPriceCurrency' => null]),
        ]);

        $this->assertSame([WebhookService::SKIPPED_CURRENCY, WebhookService::SKIPPED_CURRENCY], $outcomes);
        $this->assertProductPrice($otherCurrencyProduct, 10.0);
        $this->assertProductPrice($noCurrencyProduct, 10.0);
    }

    public function testCurrencyIsCaseInsensitive(): void
    {
        $product = $this->createProduct();

        $this->assertSame(
            [WebhookService::UPDATED_PRICE],
            $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceCurrency' => strtolower($this->currency)])])
        );
    }

    public function testOtherEntriesAreStillAppliedWhenOneIsSkipped(): void
    {
        $product = $this->createProduct();

        $outcomes = $this->send([
            $this->entry(['extId' => '999999999']),
            $this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 8]),
        ]);

        $this->assertSame([WebhookService::SKIPPED_UNKNOWN_PRODUCT, WebhookService::UPDATED_PRICE], $outcomes);
        $this->assertProductPrice($product, 8.0);
    }

    // ---------------------------------------------------------------
    // Taxes
    // ---------------------------------------------------------------

    public function testTaxIncludedPriceIsStoredTaxExcluded(): void
    {
        $product = $this->createProduct('Pricee taxed product', 100.0, [], $this->createTaxRulesGroup(20.0));
        $this->setVisitorPricesTaxIncluded(true);

        $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 90])]);

        $this->assertProductPrice($product, 75.0);
    }

    public function testTaxExcludedDisplayStoresThePriceAsReceived(): void
    {
        $product = $this->createProduct('Pricee taxed product', 100.0, [], $this->createTaxRulesGroup(20.0));
        $this->setVisitorPricesTaxIncluded(false);

        $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 90])]);

        $this->assertProductPrice($product, 90.0);
    }

    /**
     * @dataProvider promotionProvider
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('promotionProvider')]
    public function testProductWithAPromotionIsSkipped(array $fields): void
    {
        $product = $this->createProduct();
        $fields = array_map(fn ($value) => 'product' === $value ? (int) $product->id : ('shop' === $value ? $this->shopId() : $value), $fields);
        $promotion = $this->createSpecificPrice($fields);
        $before = $this->specificPriceRow((int) $promotion->id);

        $this->assertSame([WebhookService::SKIPPED_PROMOTION], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 7])]));

        $this->assertProductPrice($product, 10.0);
        $this->assertSame($before, $this->specificPriceRow((int) $promotion->id), 'the promotion must not change');
        $this->assertSame(
            [(int) $promotion->id],
            array_map('intval', array_column(\Db::getInstance()->executeS('SELECT id_specific_price FROM '._DB_PREFIX_.'specific_price WHERE id_product IN (0, '.(int) $product->id.')') ?: [], 'id_specific_price')),
            'no promotion is added'
        );
    }

    // ---------------------------------------------------------------
    // Promotions: never written, the product is skipped
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function promotionProvider(): iterable
    {
        yield 'own promotion' => [['id_product' => 'product']];

        yield 'own fixed price promotion' => [['id_product' => 'product', 'price' => 8, 'reduction' => 0, 'reduction_type' => 'amount']];

        yield 'own promotion for this shop' => [['id_product' => 'product', 'id_shop' => 'shop']];

        yield 'catalog-wide promotion' => [['id_product' => 0]];

        yield 'catalog price rule' => [['id_product' => 0, 'id_specific_price_rule' => 999999]];

        yield 'quantity discount' => [['id_product' => 'product', 'from_quantity' => 3]];

        yield 'promotion for one customer group' => [['id_product' => 'product', 'id_group' => 3]];

        yield 'promotion for another currency' => [['id_product' => 'product', 'id_currency' => 999]];

        yield 'scheduled promotion' => [['id_product' => 'product', 'from' => '2099-01-01 00:00:00', 'to' => '2099-12-31 00:00:00']];
    }

    public function testExpiredPromotionDoesNotBlockTheUpdate(): void
    {
        $product = $this->createProduct();
        $this->createSpecificPrice(['id_product' => (int) $product->id, 'from' => '2020-01-01 00:00:00', 'to' => '2020-12-31 00:00:00']);

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 7])]));
        $this->assertProductPrice($product, 7.0);
    }

    public function testPromotionOfAnotherShopDoesNotBlockTheUpdate(): void
    {
        $this->setMultistore(true);
        $product = $this->createProduct();
        $this->createSpecificPrice(['id_product' => (int) $product->id, 'id_shop' => $this->otherShopId()]);

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 7])]));
        $this->assertProductPrice($product, 7.0);
    }

    // ---------------------------------------------------------------
    // Other cases where the displayed price is not the base price
    // ---------------------------------------------------------------

    public function testProductWithEcotaxIsSkipped(): void
    {
        $this->setConfiguration('PS_USE_ECOTAX', 1);
        $product = $this->createProduct();
        $product->ecotax = 0.5;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_ECOTAX], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testEcotaxIsIgnoredWhenDisabled(): void
    {
        $this->setConfiguration('PS_USE_ECOTAX', 0);
        $product = $this->createProduct();
        $product->ecotax = 0.5;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send([$this->entry(['extId' => (string) $product->id])]));
    }

    public function testVisitorGroupDiscountSkipsTheUpdate(): void
    {
        $product = $this->createProduct();
        \Db::getInstance()->update('group', ['reduction' => 5], 'id_group = '.(int) \Configuration::get('PS_UNIDENTIFIED_GROUP'));
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_GROUP_DISCOUNT], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testVisitorCategoryDiscountSkipsTheUpdate(): void
    {
        $product = $this->createProduct();
        \Db::getInstance()->insert('product_group_reduction_cache', [
            'id_product' => (int) $product->id,
            'id_group' => (int) \Configuration::get('PS_UNIDENTIFIED_GROUP'),
            'reduction' => 0.1,
        ]);
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_GROUP_DISCOUNT], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testDisabledProductIsSkipped(): void
    {
        $product = $this->createProduct();
        $product->active = false;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_PRICE_NOT_SHOWN], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testProductWithHiddenPriceIsSkipped(): void
    {
        $product = $this->createProduct();
        $product->available_for_order = false;
        $product->show_price = false;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_PRICE_NOT_SHOWN], $this->send([$this->entry(['extId' => (string) $product->id])]));
        $this->assertProductPrice($product, 10.0);
    }

    // ---------------------------------------------------------------
    // Safety rules on the new price
    // ---------------------------------------------------------------

    public function testPriceBelowCostIsSkipped(): void
    {
        $product = $this->createProduct();
        $product->wholesale_price = 8;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::SKIPPED_BELOW_COST], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 7.99])]));
        $this->assertProductPrice($product, 10.0);
    }

    public function testPriceEqualToCostIsAccepted(): void
    {
        $product = $this->createProduct();
        $product->wholesale_price = 8;
        $this->assertTrue($product->update());
        $this->resetCaches();

        $this->assertSame([WebhookService::UPDATED_PRICE], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 8])]));
    }

    public function testCostIsComparedTaxExcluded(): void
    {
        $product = $this->createProduct('Pricee taxed product', 100.0, [], $this->createTaxRulesGroup(20.0));
        $product->wholesale_price = 80;
        $this->assertTrue($product->update());
        $this->setVisitorPricesTaxIncluded(true);

        // 90 tax included is 75 tax excluded, below the cost of 80
        $this->assertSame([WebhookService::SKIPPED_BELOW_COST], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 90])]));
        $this->assertProductPrice($product, 100.0);
    }

    public function testSamePriceIsNotRewritten(): void
    {
        $product = $this->createProduct();

        $this->assertSame([WebhookService::UNCHANGED], $this->send([$this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 10])]));
    }

    public function testProductSentTwiceInTheSameCallIsSkipped(): void
    {
        $product = $this->createProduct('Pricee Twice Product');
        $other = $this->createProduct('Pricee other product');

        $outcomes = $this->send([
            $this->entry(['extId' => (string) $product->id, 'bestPriceAmount' => 7]),
            $this->entry(['extId' => (string) $other->id, 'bestPriceAmount' => 7]),
            // Same product again, matched by URL this time
            $this->entry(['extId' => null, 'url' => 'https://shop.example/'.$product->id.'-pricee-twice-product.html', 'bestPriceAmount' => 6]),
        ]);

        $this->assertSame([WebhookService::SKIPPED_DUPLICATE, WebhookService::UPDATED_PRICE, WebhookService::SKIPPED_DUPLICATE], $outcomes);
        $this->assertProductPrice($product, 10.0);
        $this->assertProductPrice($other, 7.0);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://shop.example/unknown',
            'extId' => '999999999',
            'bestPriceAmount' => 9.0,
            'bestPriceCurrency' => $this->currency,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ], $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function body(array $entries): string
    {
        return (string) json_encode($entries);
    }

    /**
     * Send a correctly signed call to the current shop.
     *
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<int, string>
     */
    private function send(array $entries): array
    {
        $body = $this->body($entries);
        $outcomes = $this->service->handle($body, hash_hmac('sha256', $body, self::SECRET), $this->shopId());
        $this->resetCaches();

        return $outcomes;
    }

    private function assertRejected(int $status, string $message, callable $call): void
    {
        try {
            $call();
        } catch (WebhookRejectedException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail('The webhook call was not rejected');
    }

    private function assertProductPrice(\Product $product, float $expected): void
    {
        $price = (float) \Db::getInstance()->getValue(
            'SELECT price FROM '._DB_PREFIX_.'product_shop WHERE id_product = '.(int) $product->id.' AND id_shop = '.$this->shopId()
        );

        $this->assertEqualsWithDelta($expected, $price, 0.000001);
    }

    private function resetProductPrice(\Product $product, float $price): void
    {
        \Db::getInstance()->update('product_shop', ['price' => $price], 'id_product = '.(int) $product->id);
        \Db::getInstance()->update('product', ['price' => $price], 'id_product = '.(int) $product->id);
        $this->resetCaches();
    }

    /**
     * @return array<string, mixed>
     */
    private function specificPriceRow(int $idSpecificPrice): array
    {
        return \Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'specific_price WHERE id_specific_price = '.$idSpecificPrice) ?: [];
    }
}
