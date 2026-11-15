<?php

/*
 * End-to-end test helper, run inside the throwaway shop container by
 * e2e/run.sh (never against a dev shop: it writes to the shop database).
 *
 *   php shop.php is-installed         exits with 1 unless the module is installed and active
 *   php shop.php configure            settings as a merchant would have them
 *   php shop.php check-upgrade <from> module still active and settings kept after the upgrade
 *   php shop.php webhook              real signed HTTP calls to the webhook, prices checked in DB
 *   php shop.php sync-fixtures        category with enough products for several sync batches
 *   php shop.php admin-url            module page URL, with the admin token of employee 1
 *   php shop.php check-sync           what the fake Pricee API received from the sync
 *
 * Compatible with PHP 7.4 (PrestaShop 1.7.8 images).
 */

const CLIENT_ID = 'e2e-client';
const API_KEY = 'e2e-key';
const SECRET = 'e2e-webhook-secret';
const FIXTURES_FILE = '/tmp/pricee-e2e-fixtures.json';
const SYNC_CATEGORY = 'Pricee E2E';
const SYNC_PRODUCTS = 55; // more than one batch of 50
const FAKE_API_STATE = 'http://127.0.0.1:8090/_state';

require '/var/www/html/config/config.inc.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");

    exit(1);
}

function pass(string $message): void
{
    echo "ok - {$message}\n";
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
    pass($message);
}

function idShop(): int
{
    return (int) Context::getContext()->shop->id;
}

function multilang(string $value): array
{
    $values = [];
    foreach (Language::getIDs(false) as $idLang) {
        $values[(int) $idLang] = $value;
    }

    return $values;
}

function createProduct(string $name, float $price, int $idCategory): Product
{
    $product = new Product();
    $product->name = multilang($name);
    $product->link_rewrite = multilang(Tools::str2url($name));
    $product->price = $price;
    $product->id_tax_rules_group = 0;
    $product->id_category_default = $idCategory;
    $product->active = true;
    if (!$product->add()) {
        fail('could not create product '.$name);
    }
    $product->addToCategories(array_unique([(int) Context::getContext()->shop->id_category, $idCategory]));

    return $product;
}

function storedPrice(Product $product): float
{
    return (float) Db::getInstance()->getValue(
        'SELECT price FROM '._DB_PREFIX_.'product_shop WHERE id_product = '.(int) $product->id.' AND id_shop = '.idShop()
    );
}

/**
 * POST to the shop through its web server, as Pricee does: the request goes
 * to Apache in this container, with the shop's own host name.
 *
 * @return array{0: int, 1: string} status and body
 */
function httpRequest(string $url, string $method = 'GET', string $body = '', array $headers = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    $local = 'http://127.0.0.1'.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

    $curl = curl_init($local);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => 'POST' === $method ? $body : null,
        CURLOPT_HTTPHEADER => array_merge(['Host: '.$host], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return [$status, $response];
}

/**
 * @param array<int, array<string, mixed>> $entries
 *
 * @return array{0: int, 1: string, 2: string} status, response body and the request body sent
 */
function sendWebhook(array $entries, ?string $secret = null, ?string $body = null): array
{
    $body = $body ?? (string) json_encode($entries);
    $url = Context::getContext()->link->getModuleLink('pricee', 'webhook', [], true, null, idShop());
    [$status, $response] = httpRequest($url, 'POST', $body, [
        'Content-Type: application/json',
        'X-Signature: '.hash_hmac('sha256', $body, $secret ?? SECRET),
        'User-Agent: Pricee',
    ]);

    return [$status, $response, $body];
}

/**
 * @return array<string, mixed>
 */
function entry(Product $product, float $price): array
{
    return [
        'url' => Context::getContext()->link->getProductLink($product),
        'extId' => (string) $product->id,
        'priceAmount' => $price,
        'priceCurrency' => (new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code,
        // The e2e products have no tax: visitors see the stored price
        'currentPriceAmount' => storedPrice($product),
        'timestamp' => time(),
        'nonce' => bin2hex(random_bytes(16)),
    ];
}

function moduleVersionInDb(): string
{
    return (string) Db::getInstance()->getValue('SELECT version FROM '._DB_PREFIX_."module WHERE name = 'pricee'");
}

function tableExists(string $table): bool
{
    return (bool) Db::getInstance()->executeS("SHOW TABLES LIKE '"._DB_PREFIX_.pSQL($table)."'");
}

function configure(): void
{
    Configuration::updateValue('PRICEE_CLIENT_ID', CLIENT_ID);
    Configuration::updateValue('PRICEE_API_KEY', API_KEY);
    Configuration::updateValue('PRICEE_WEBHOOK_ENABLED', 1);
    Configuration::updateValue('PRICEE_WEBHOOK_SECRET', SECRET);
    pass('module configured (version '.moduleVersionInDb().')');
}

function checkUpgrade(string $fromVersion): void
{
    $module = Module::getInstanceByName('pricee');
    if (!$module instanceof Module) {
        fail('module not found');
    }

    assertSame($module->version, moduleVersionInDb(), 'module version in database is the code version');
    assertSame(1, (int) Db::getInstance()->getValue('SELECT active FROM '._DB_PREFIX_."module WHERE name = 'pricee'"), 'module still active');
    assertSame(CLIENT_ID, (string) Configuration::get('PRICEE_CLIENT_ID'), 'client ID kept');
    assertSame(API_KEY, (string) Configuration::get('PRICEE_API_KEY'), 'API key kept');
    assertSame('1', (string) Configuration::get('PRICEE_WEBHOOK_ENABLED'), 'webhook still enabled');
    assertSame(SECRET, (string) Configuration::get('PRICEE_WEBHOOK_SECRET'), 'webhook secret kept');
    assertSame(true, tableExists('pricee_webhook_nonce'), 'webhook nonce table exists');

    if (version_compare($module->version, $fromVersion, '>') && version_compare($module->version, '1.4.0', '>=')) {
        assertSame(false, tableExists('pricee'), 'unused pricee table dropped');
        assertSame(false, (bool) Configuration::hasKey('PRICEE_WEBHOOK_NONCES'), 'old nonce setting removed');
    }
}

function webhook(): void
{
    $category = new Category();
    $category->name = multilang('Pricee E2E webhook');
    $category->link_rewrite = multilang('pricee-e2e-webhook');
    $category->id_parent = (int) Context::getContext()->shop->id_category;
    $category->active = true;
    if (!$category->add()) {
        fail('could not create the webhook category');
    }

    $simple = createProduct('Pricee E2E simple', 10.0, (int) $category->id);
    $promoted = createProduct('Pricee E2E promoted', 10.0, (int) $category->id);
    $specificPrice = new SpecificPrice();
    foreach ([
        'id_product' => (int) $promoted->id, 'id_product_attribute' => 0, 'id_shop' => 0, 'id_shop_group' => 0,
        'id_cart' => 0, 'id_currency' => 0, 'id_country' => 0, 'id_group' => 0, 'id_customer' => 0,
        'from_quantity' => 1, 'price' => -1, 'reduction' => 0.1, 'reduction_tax' => 1, 'reduction_type' => 'percentage',
        'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00',
    ] as $field => $value) {
        $specificPrice->{$field} = $value;
    }
    if (!$specificPrice->add()) {
        fail('could not create the promotion');
    }

    [$status, $response, $body] = sendWebhook([entry($simple, 8.0), entry($promoted, 7.0)]);
    assertSame(200, $status, 'signed call accepted ('.$response.')');
    assertSame(['updated_price' => 1, 'skipped_promotion' => 1], json_decode($response, true)['outcomes'] ?? null, 'answer counts each outcome');
    assertSame(8.0, storedPrice($simple), 'simple product price written');
    assertSame(10.0, storedPrice($promoted), 'product with a promotion left untouched');

    [$status] = sendWebhook([], null, $body);
    assertSame(401, $status, 'replayed call refused');

    [$status] = sendWebhook([entry($simple, 5.0)], 'wrong-secret');
    assertSame(401, $status, 'call with a wrong signature refused');
    assertSame(8.0, storedPrice($simple), 'refused calls write nothing');

    // Maintenance mode, without the local IP allowed: visitors get the
    // maintenance page, Pricee still reaches the webhook
    $maintenanceIps = (string) Configuration::get('PS_MAINTENANCE_IP');
    Configuration::updateValue('PS_SHOP_ENABLE', 0);
    Configuration::updateValue('PS_MAINTENANCE_IP', '');
    [$frontStatus] = httpRequest(Context::getContext()->link->getPageLink('index', true));
    [$status, $response] = sendWebhook([entry($simple, 9.0)]);
    Configuration::updateValue('PS_SHOP_ENABLE', 1);
    Configuration::updateValue('PS_MAINTENANCE_IP', $maintenanceIps);
    assertSame(503, $frontStatus, 'front office shows the maintenance page');
    assertSame(200, $status, 'webhook still works in maintenance mode ('.$response.')');
    assertSame(9.0, storedPrice($simple), 'price written in maintenance mode');
}

function syncFixtures(): void
{
    $category = new Category();
    $category->name = multilang(SYNC_CATEGORY);
    $category->link_rewrite = multilang(Tools::str2url(SYNC_CATEGORY));
    $category->id_parent = (int) Context::getContext()->shop->id_category;
    $category->active = true;
    if (!$category->add()) {
        fail('could not create the sync category');
    }

    $ids = [];
    for ($i = 1; $i <= SYNC_PRODUCTS; ++$i) {
        $ids[] = (int) createProduct('Pricee E2E sync '.$i, 20.0, (int) $category->id)->id;
    }

    file_put_contents(FIXTURES_FILE, json_encode(['category' => (int) $category->id, 'products' => $ids]));
    pass(SYNC_PRODUCTS.' products created in category "'.SYNC_CATEGORY.'"');
}

function adminUrl(): void
{
    $token = Tools::getAdminToken('AdminModules'.(int) Tab::getIdFromClassName('AdminModules').'1');

    echo 'index.php?controller=AdminModules&configure=pricee&token='.$token."\n";
}

function checkSync(): void
{
    $fixtures = json_decode((string) file_get_contents(FIXTURES_FILE), true);
    $state = json_decode((string) file_get_contents(FAKE_API_STATE), true);
    if (!is_array($fixtures) || !is_array($state)) {
        fail('missing fixtures or fake API state');
    }

    $baseUrl = rtrim(Context::getContext()->link->getBaseLink(idShop()), '/');
    assertSame([$baseUrl], array_column($state['websites'], 'url'), 'one website registered, with the shop front URL');

    $extIds = array_map('intval', array_column($state['products'], 'extId'));
    sort($extIds);
    assertSame($fixtures['products'], $extIds, 'every product of the category sent once, with its ID');

    foreach ($state['products'] as $product) {
        $expected = rtrim(Context::getContext()->link->getProductLink((int) $product['extId'], null, null, null, (int) Configuration::get('PS_LANG_DEFAULT'), idShop()), '/');
        if ($product['url'] !== $expected) {
            fail('product '.$product['extId'].' sent with URL '.$product['url'].' instead of '.$expected);
        }
    }
    pass('product URLs are the front-office product pages');

    $logins = count(array_filter($state['requests'], function (array $request): bool {
        return 'login' === $request['route'];
    }));
    assertSame((int) ceil(SYNC_PRODUCTS / 50), $logins, 'sync sent in batches (one login per batch)');
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'is-installed':
        if ('' === moduleVersionInDb() || !Module::isEnabled('pricee')) {
            fail('module pricee is not installed and active');
        }
        pass('module installed (version '.moduleVersionInDb().')');
        break;
    case 'configure':
        configure();
        break;
    case 'check-upgrade':
        checkUpgrade((string) ($argv[2] ?? '0'));
        break;
    case 'webhook':
        webhook();
        break;
    case 'sync-fixtures':
        syncFixtures();
        break;
    case 'admin-url':
        adminUrl();
        break;
    case 'check-sync':
        checkSync();
        break;
    default:
        fail('unknown command '.$command);
}
