<?php

declare(strict_types=1);

namespace PriceeIO\Service;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiService
{
    private const BASE_URL = 'https://app.pricee.io/api/v1/';

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient
    ) {
        $this->httpClient = $httpClient;
    }

    public function extracUuid(string $id): string
    {
        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $id, $matches);
        $uuid = $matches[0] ?? null;
        if (!$uuid) {
            throw new \InvalidArgumentException('Invalid ID');
        }

        return $uuid;
    }

    public function getBearer(string $clientId, string $apiKey): string
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.'login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-CLIENT-ID' => $clientId,
                    'X-API-KEY' => $apiKey,
                ],
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to login with API key. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            if (!isset($data['token'])) {
                throw new \RuntimeException('JWT token not returned from API key login.');
            }

            return $data['token'];
        } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch bearer token: '.$e->getMessage(), 0, $e);
        }
    }

    public function getWebsites(string $bearer): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'websites', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$bearer,
                ],
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to fetch websites. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            return $data['member'];
        } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch websites: '.$e->getMessage(), 0, $e);
        }
    }

    public function createWebsite(string $bearer, string $url): array
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL.'websites', [
                'headers' => [
                    'Content-Type' => 'application/ld+json',
                    'Authorization' => 'Bearer '.$bearer,
                ],
                'json' => [
                    'url' => $url,
                ],
            ]);

            $status = $response->getStatusCode();

            if (201 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to create website. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            return $response->toArray(false);
        } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to create website: '.$e->getMessage(), 0, $e);
        }
    }

    public function createOrUpdateProduct(
        string $bearer,
        string $websiteId,
        string $productUrl,
        string $productExtId
    ): void {
        // Check if product exists
        $queryParams = http_build_query([
            'website' => $this->extracUuid($websiteId),
            'extId' => $productExtId,
        ]);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'website_products?'.$queryParams, [
                'headers' => [
                    'Authorization' => 'Bearer '.$bearer,
                ],
            ]);

            $products = $response->toArray(false)['member'];
            if (!empty($products)) {
                // Product exists: update it
                $existingProductId = $products[0]['id'];

                $updateResponse = $this->httpClient->request('PATCH', self::BASE_URL.'website_products/'.$existingProductId, [
                    'headers' => [
                        'Content-Type' => 'application/merge-patch+json',
                        'Authorization' => 'Bearer '.$bearer,
                    ],
                    'json' => [
                        'url' => $productUrl,
                    ],
                ]);

                if (200 !== $updateResponse->getStatusCode()) {
                    throw new \RuntimeException(sprintf(
                        'Failed to update product. Status: %d. Response: %s',
                        $updateResponse->getStatusCode(),
                        $updateResponse->getContent(false)
                    ));
                }

                return;
            }

            // Product does not exist: create it
            $createResponse = $this->httpClient->request('POST', self::BASE_URL.'website_products', [
                'headers' => [
                    'Content-Type' => 'application/ld+json',
                    'Authorization' => 'Bearer '.$bearer,
                ],
                'json' => [
                    'website' => $websiteId,
                    'url' => $productUrl,
                    'extId' => $productExtId,
                ],
            ]);
            if (201 !== $createResponse->getStatusCode()) {
                throw new \RuntimeException(sprintf(
                    'Failed to create product. Status: %d. Response: %s',
                    $createResponse->getStatusCode(),
                    $createResponse->getContent(false)
                ));
            }
        } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to create/update product: '.$e->getMessage(), 0, $e);
        }
    }
}
