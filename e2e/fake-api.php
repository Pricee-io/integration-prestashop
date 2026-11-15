<?php

/*
 * Fake Pricee API for the end-to-end tests, run with PHP's built-in server
 * inside the shop container (`php -S 127.0.0.1:8090 fake-api.php`): the module
 * reaches it through PRICEE_API_BASE_URL. It answers like the real API for the
 * calls the sync makes, keeps its data in a JSON file, and exposes it on
 * GET /_state so the tests can check what the shop sent.
 *
 * Compatible with PHP 7.4 (PrestaShop 1.7.8 images).
 */

const STATE_FILE = '/tmp/pricee-fake-api.json';
const CLIENT_ID = 'e2e-client';
const API_KEY = 'e2e-key';
const TOKEN = 'e2e-token';
const PAGE_SIZE = 30;
// The website the API key is scoped to, returned at login
const WEBSITE_IRI = '/api/v1/websites/11111111-0000-4000-8000-000000000001';

function loadState(): array
{
    $state = is_file(STATE_FILE) ? json_decode((string) file_get_contents(STATE_FILE), true) : null;

    return is_array($state) ? $state : ['websites' => [], 'products' => [], 'requests' => []];
}

function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/ld+json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function uuid(string $prefix, int $number): string
{
    return sprintf('%s-0000-4000-8000-%012d', $prefix, $number);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode((string) file_get_contents('php://input'), true);
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);

$state = loadState();

if ('/_state' === $path) {
    respond(200, $state);

    return;
}

$route = (string) preg_replace('#^/api/v1/#', '', $path);
$state['requests'][] = ['method' => $method, 'route' => $route];

if ('POST' === $method && 'login' === $route) {
    $valid = CLIENT_ID === ($headers['x-client-id'] ?? '') && API_KEY === ($headers['x-api-key'] ?? '');
    saveState($state);
    $valid ? respond(200, ['token' => TOKEN, 'website' => WEBSITE_IRI]) : respond(401, ['detail' => 'Invalid credentials']);

    return;
}

if ('Bearer '.TOKEN !== ($headers['authorization'] ?? '')) {
    saveState($state);
    respond(401, ['detail' => 'Missing or invalid token']);

    return;
}

if ('GET' === $method && 'websites' === $route) {
    $page = max(1, (int) ($query['page'] ?? 1));
    saveState($state);
    respond(200, ['member' => array_slice($state['websites'], ($page - 1) * PAGE_SIZE, PAGE_SIZE)]);

    return;
}

if ('POST' === $method && 'websites' === $route) {
    // API keys are scoped to a website: they cannot create websites
    saveState($state);
    respond(403, ['detail' => 'This API key is scoped to a website and cannot create websites.']);

    return;
}

if ('GET' === $method && 'website_products' === $route) {
    // Same filters as the real API: website, exact extId, URL without trailing slash
    $members = array_values(array_filter($state['products'], function (array $product) use ($query): bool {
        if (isset($query['website']) && false === strpos($product['website'], (string) $query['website'])) {
            return false;
        }
        if (isset($query['extId']) && $product['extId'] !== $query['extId']) {
            return false;
        }

        return !isset($query['url']) || $product['url'] === rtrim((string) $query['url'], '/');
    }));
    saveState($state);
    respond(200, ['member' => $members]);

    return;
}

if ('POST' === $method && 'website_products' === $route) {
    $id = uuid('22222222', count($state['products']) + 1);
    $product = [
        '@id' => '/api/v1/website_products/'.$id,
        'id' => $id,
        'website' => (string) ($body['website'] ?? ''),
        'url' => rtrim((string) ($body['url'] ?? ''), '/'),
        'extId' => isset($body['extId']) ? (string) $body['extId'] : null,
    ];
    $state['products'][] = $product;
    saveState($state);
    respond(201, $product);

    return;
}

if ('PATCH' === $method && 0 === strpos($route, 'website_products/')) {
    foreach ($state['products'] as &$product) {
        if ('website_products/'.$product['id'] === $route) {
            if (isset($body['url'])) {
                $product['url'] = rtrim((string) $body['url'], '/');
            }
            if (is_array($body) && array_key_exists('extId', $body)) {
                $product['extId'] = $body['extId'];
            }
            $updated = $product;
            unset($product);
            saveState($state);
            respond(200, $updated);

            return;
        }
    }
    unset($product);
}

saveState($state);
respond(404, ['detail' => 'Unexpected request '.$method.' '.$route]);
