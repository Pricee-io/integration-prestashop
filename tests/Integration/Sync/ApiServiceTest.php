<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Sync;

use PHPUnit\Framework\TestCase;
use PriceeIO\Service\ApiService;

/**
 * Creating or updating a product on Pricee: matched by extId, then by URL,
 * and only changed when something actually differs.
 *
 * @internal
 *
 * @coversNothing
 */
class ApiServiceTest extends TestCase
{
    private const WEBSITE_ID = '/api/v1/websites/'.RecordingHttpClient::WEBSITE_UUID;

    private RecordingHttpClient $api;

    private ApiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new RecordingHttpClient();
        $this->service = new ApiService($this->api->client());
    }

    public function testNewProductIsCreated(): void
    {
        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1', '1');

        $created = $this->api->requestsTo('POST', 'website_products');
        $this->assertCount(1, $created);
        $this->assertSame(['website' => self::WEBSITE_ID, 'url' => 'https://shop.example/p/1', 'extId' => '1'], $created[0]['json']);
        $this->assertSame([], $this->api->requestsTo('PATCH', 'website_products'));
    }

    public function testNewProductNeverTakesOverAnotherProduct(): void
    {
        $otherId = $this->api->addProduct('https://shop.example/p/other', '99');

        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1', '1');

        $this->assertSame([], $this->api->requestsTo('PATCH', 'website_products'));
        $this->assertCount(1, $this->api->requestsTo('POST', 'website_products'));
        $this->assertSame('99', $this->api->products[0]['extId']);
        $this->assertSame($otherId, $this->api->products[0]['id']);
    }

    public function testUnchangedProductIsNotUpdated(): void
    {
        $this->api->addProduct('https://shop.example/p/1', '1');

        // A trailing slash difference is not a change
        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1/', '1');

        $this->assertSame([], $this->api->requestsTo('PATCH', 'website_products'));
        $this->assertSame([], $this->api->requestsTo('POST', 'website_products'));
    }

    public function testProductFoundByExtIdGetsItsNewUrl(): void
    {
        $id = $this->api->addProduct('https://shop.example/p/old-name', '1');

        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/new-name', '1');

        $patches = $this->api->requestsTo('PATCH', 'website_products/'.$id);
        $this->assertCount(1, $patches);
        $this->assertSame(['url' => 'https://shop.example/p/new-name'], $patches[0]['json']);
    }

    public function testProductSyncedWithoutExtIdIsAdoptedByUrl(): void
    {
        $id = $this->api->addProduct('https://shop.example/p/1', null);

        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1', '1');

        $patches = $this->api->requestsTo('PATCH', 'website_products/'.$id);
        $this->assertCount(1, $patches);
        $this->assertSame(['extId' => '1'], $patches[0]['json']);
        $this->assertSame([], $this->api->requestsTo('POST', 'website_products'));
    }

    public function testUrlTrackedForAnotherProductIsNotTakenOver(): void
    {
        // E.g. a deleted product whose URL is now used by another one
        $this->api->addProduct('https://shop.example/p/1', '99');

        try {
            $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1', '1');
            $this->fail('The product should be reported in error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already tracked on Pricee for product ID 99', $e->getMessage());
        }

        $this->assertSame([], $this->api->requestsTo('PATCH', 'website_products'));
        $this->assertSame([], $this->api->requestsTo('POST', 'website_products'));
        $this->assertSame('99', $this->api->products[0]['extId']);
    }

    public function testLookupsAreScopedToTheWebsite(): void
    {
        $this->service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/p/1', '1');

        $lookups = $this->api->requestsTo('GET', 'website_products');
        $this->assertCount(2, $lookups);
        $this->assertSame(['website' => RecordingHttpClient::WEBSITE_UUID, 'extId' => '1'], $lookups[0]['query']);
        $this->assertSame(['website' => RecordingHttpClient::WEBSITE_UUID, 'url' => 'https://shop.example/p/1'], $lookups[1]['query']);
    }

    public function testWebsitesArePaginated(): void
    {
        for ($i = 0; $i < 31; ++$i) {
            $this->api->addWebsite('https://site'.$i.'.example');
        }

        $this->assertCount(31, $this->service->getWebsites('token'));
        $this->assertCount(2, $this->api->requestsTo('GET', 'websites'));
    }
}
