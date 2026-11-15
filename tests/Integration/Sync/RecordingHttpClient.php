<?php

declare(strict_types=1);

namespace PriceeIO\Tests\Integration\Sync;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fake Pricee API: answers like the real one from an in-memory list of
 * websites and website products, and records every request made.
 */
class RecordingHttpClient
{
    public const WEBSITE_UUID = '11111111-1111-4111-8111-111111111111';

    /** @var array<int, array{method: string, path: string, query: array<string, string>, json: mixed}> */
    public array $requests = [];

    /** @var array<int, array<string, mixed>> websites of the account */
    public array $websites = [];

    /**
     * Website products, as the API returns them: url is the tracked URL.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $products = [];

    /** IRI of the website the API key is scoped to, returned at login */
    public ?string $scopedWebsite = '/api/v1/websites/'.self::WEBSITE_UUID;

    /** extId whose creation is refused, like when the Pricee product limit is reached */
    public ?string $failProductCreationFor = null;

    private int $nextId = 1;

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $path = (string) preg_replace('#^.*/api/v1/#', '', $parts['path'] ?? '');
            $json = isset($options['body']) && '' !== $options['body'] ? json_decode((string) $options['body'], true) : null;

            $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'json' => $json];

            return $this->respond($method, $path, $query, $json);
        }, 'https://app.pricee.io/api/v1/');
    }

    /**
     * @return array<int, array{method: string, path: string, query: array<string, string>, json: mixed}>
     */
    public function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (array $request) => $request['method'] === $method && 0 === strpos($request['path'], $pathPrefix)
        ));
    }

    public function addWebsite(string $url): void
    {
        $this->websites[] = ['@id' => '/api/v1/websites/'.self::WEBSITE_UUID, 'id' => self::WEBSITE_UUID, 'url' => $url];
    }

    public function addProduct(string $url, ?string $extId): string
    {
        $id = sprintf('22222222-2222-4222-8222-%012d', $this->nextId++);
        $this->products[] = ['@id' => '/api/v1/website_products/'.$id, 'id' => $id, 'url' => rtrim($url, '/'), 'extId' => $extId];

        return $id;
    }

    /**
     * @param array<string, string> $query
     * @param mixed                 $json
     */
    private function respond(string $method, string $path, array $query, $json): MockResponse
    {
        if ('POST' === $method && 'login' === $path) {
            return $this->json(null === $this->scopedWebsite ? ['token' => 'test-token'] : ['token' => 'test-token', 'website' => $this->scopedWebsite]);
        }

        if ('GET' === $method && 'websites' === $path) {
            $page = (int) ($query['page'] ?? 1);

            return $this->json(['member' => array_slice($this->websites, ($page - 1) * 30, 30)]);
        }

        if ('POST' === $method && 'websites' === $path) {
            return $this->json(['detail' => 'This API key is scoped to a website and cannot create websites.'], 403);
        }

        if ('GET' === $method && 'website_products' === $path) {
            // Same filters as the real API: website (required), extId, url (normalized)
            $members = array_values(array_filter($this->products, function (array $product) use ($query) {
                if (isset($query['extId']) && $product['extId'] !== $query['extId']) {
                    return false;
                }

                return !isset($query['url']) || $product['url'] === rtrim($query['url'], '/');
            }));

            return $this->json(['member' => $members]);
        }

        if ('POST' === $method && 'website_products' === $path) {
            if (null !== $this->failProductCreationFor && ($json['extId'] ?? null) === $this->failProductCreationFor) {
                return $this->json(['detail' => 'Products limit exceeded'], 403);
            }

            $id = $this->addProduct((string) $json['url'], $json['extId'] ?? null);

            return $this->json(['id' => $id], 201);
        }

        if ('PATCH' === $method && 0 === strpos($path, 'website_products/')) {
            foreach ($this->products as &$product) {
                if ('website_products/'.$product['id'] === $path) {
                    if (isset($json['url'])) {
                        $product['url'] = rtrim((string) $json['url'], '/');
                    }
                    if (array_key_exists('extId', $json)) {
                        $product['extId'] = $json['extId'];
                    }

                    return $this->json($product);
                }
            }

            return $this->json([], 404);
        }

        return $this->json(['error' => 'unexpected request '.$method.' '.$path], 500);
    }

    /**
     * @param mixed $data
     */
    private function json($data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
