<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType;

class PriceeWebhookModuleFrontController extends ModuleFrontController
{
    /** Maximum age of a webhook payload, in seconds (5 minutes) */
    private const WEBHOOK_MAX_AGE = 300;

    /** How long a used nonce is remembered, in seconds (twice the max age) */
    private const NONCE_TTL = 600;

    /** Configuration key storing the hashes of the nonces already used */
    private const NONCE_CONFIGURATION_KEY = 'PRICEE_WEBHOOK_NONCES';

    public function initContent(): void
    {
        parent::initContent();

        // Check request
        $body = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

        if (empty($body) || empty($signature)) {
            $this->sendResponse(400, false, 'Missing information for webhook');
        }

        // Check webhook configuration
        $enabled = Configuration::get('PRICEE_WEBHOOK_ENABLED', 0);
        $secret = Configuration::get('PRICEE_WEBHOOK_SECRET', null);
        if (!$enabled || empty($secret)) {
            $this->sendResponse(401, false, 'Webhook not configured');
        }

        // Verify signature
        $expected_signature = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected_signature, $signature)) {
            $this->sendResponse(401, false, 'Invalid signature');
        }

        // Get JSON
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->sendResponse(400, false, 'Invalid JSON payload');
        }

        // Reject replayed payloads
        $this->validateReplayProtection($data);

        // Product prices are stored in the shop default currency
        $shopCurrency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        // Process products
        foreach ($data as $productData) {
            if (!isset($productData['bestPriceAmount'])) {
                continue;
            }

            $product = $this->resolveProduct($productData);
            if (null === $product) {
                continue;
            }

            // Only update simple products for now (no variants)
            $productType = $product->getDynamicProductType();
            if (ProductType::TYPE_PACK === $productType
                || ProductType::TYPE_COMBINATIONS === $productType) {
                continue;
            }

            // Validate the received price before writing it
            $rawPrice = $productData['bestPriceAmount'];
            if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                PrestaShopLogger::addLog(
                    'Pricee webhook: invalid price received for product ID '.$product->id.' | Value: '.print_r($rawPrice, true),
                    2
                );

                continue;
            }

            // An amount in another currency cannot be written as is
            $currency = $productData['bestPriceCurrency'] ?? null;
            if (!is_string($currency) || strtoupper($currency) !== strtoupper((string) $shopCurrency->iso_code)) {
                PrestaShopLogger::addLog(
                    'Pricee webhook: price currency '.print_r($currency, true).' does not match the shop currency '.$shopCurrency->iso_code.' for product ID '.$product->id,
                    2
                );

                continue;
            }

            try {
                $this->applyPrice($product, $this->toStoredPrice($product, (float) $rawPrice));
            } catch (Throwable $e) {
                PrestaShopLogger::addLog(
                    "Pricee webhook: price update failed for product ID {$product->id}: ".$e->getMessage(),
                    3
                );
            }
        }

        $this->sendResponse(200, true, 'Webhook received and validated');
    }

    /**
     * The webhook is a server-to-server call: it must keep working while the
     * shop is in maintenance mode.
     */
    protected function displayMaintenancePage() {}

    /**
     * The webhook is a server-to-server call: geolocation restrictions apply
     * to visitors, not to Pricee.
     */
    protected function displayRestrictedCountryPage() {}

    /**
     * Reject replayed payloads: every entry must carry a fresh signed
     * timestamp and a nonce that has not been used within the accepted
     * window. The app signs a whole batch with a single nonce, so each
     * distinct nonce is consumed only once per payload.
     */
    private function validateReplayProtection(array $data): void
    {
        $usedNonces = $this->getUsedNonces();
        $now = time();
        $freshNonces = [];

        foreach ($data as $productData) {
            if (!isset($productData['timestamp'])
                || !is_numeric($productData['timestamp'])
                || !isset($productData['nonce'])
                || !is_string($productData['nonce'])
                || '' === $productData['nonce']) {
                $this->sendResponse(401, false, 'Missing timestamp or nonce');
            }

            if (abs($now - (int) $productData['timestamp']) > self::WEBHOOK_MAX_AGE) {
                $this->sendResponse(401, false, 'Expired webhook payload');
            }

            $nonceHash = hash('sha256', $productData['nonce']);
            if (isset($freshNonces[$nonceHash])) {
                continue;
            }

            if (isset($usedNonces[$nonceHash])) {
                $this->sendResponse(401, false, 'Replayed webhook payload');
            }

            $freshNonces[$nonceHash] = true;
            $usedNonces[$nonceHash] = $now + self::NONCE_TTL;
        }

        if (!empty($freshNonces)) {
            Configuration::updateValue(self::NONCE_CONFIGURATION_KEY, json_encode($usedNonces));
        }
    }

    /**
     * Return the hashes of the nonces already used, keyed by hash with their
     * expiry timestamp. Expired nonces are dropped, which gives the stored
     * value a time to live like a transient.
     *
     * @return array<string, int>
     */
    private function getUsedNonces(): array
    {
        $rawNonces = Configuration::get(self::NONCE_CONFIGURATION_KEY);
        if (!is_string($rawNonces) || '' === $rawNonces) {
            return [];
        }

        $nonces = json_decode($rawNonces, true);
        if (!is_array($nonces)) {
            return [];
        }

        $now = time();
        $validNonces = [];
        foreach ($nonces as $nonceHash => $expiry) {
            if (is_int($expiry) && $expiry > $now) {
                $validNonces[(string) $nonceHash] = $expiry;
            }
        }

        return $validNonces;
    }

    /**
     * Resolve the product targeted by a webhook entry: by its extId when one
     * is sent, otherwise (products synced before extId existed) by its URL.
     *
     * @param array<string, mixed> $productData
     */
    private function resolveProduct(array $productData): ?Product
    {
        $extId = $productData['extId'] ?? null;

        if (null !== $extId && '' !== $extId) {
            // A stale extId must not fall back to the URL: it could match another product
            $product = is_numeric($extId) ? new Product((int) $extId) : null;
            if (null === $product || !Validate::isLoadedObject($product)) {
                PrestaShopLogger::addLog('Pricee webhook: no product found for extId '.print_r($extId, true), 2);

                return null;
            }
        } else {
            $product = $this->resolveProductFromUrl($productData['url'] ?? null);
            if (null === $product) {
                return null;
            }
        }

        // Multistore: the webhook endpoint belongs to one shop, only update
        // products that are actually associated with it
        if (!$product->isAssociatedToShop((int) $this->context->shop->id)) {
            PrestaShopLogger::addLog(
                'Pricee webhook: product ID '.$product->id.' is not associated with shop ID '.$this->context->shop->id,
                2
            );

            return null;
        }

        return $product;
    }

    /**
     * Resolve a product from its URL: the id_product query parameter, or the
     * last path segment (<id>-<rewrite>.html or <rewrite>-<id>.html) whose
     * rewrite must be the product's own, so an unrelated number in a URL never
     * selects another product.
     *
     * @param mixed $url
     */
    private function resolveProductFromUrl($url): ?Product
    {
        if (!is_string($url) || '' === $url) {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if (preg_match('/(?:^|&)id_product=(\d+)/', $query, $matches)) {
            $product = new Product((int) $matches[1]);

            return Validate::isLoadedObject($product) ? $product : null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = explode('/', $path);
        $last = strtolower(rawurldecode((string) end($segments)));
        if (!preg_match('/^(?:(\d+)-(.+)|(.+)-(\d+))\.html$/', $last, $matches)) {
            return null;
        }

        $idPrefix = '' !== $matches[1] ? (int) $matches[1] : null;
        $idSuffix = isset($matches[4]) ? (int) $matches[4] : null;

        foreach (array_filter([$idPrefix, $idSuffix]) as $idProduct) {
            $product = new Product($idProduct);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            foreach ((array) $product->link_rewrite as $rewrite) {
                $rewrite = strtolower((string) $rewrite);
                if ('' === $rewrite) {
                    continue;
                }

                // <id>-[<id_product_attribute>-]<rewrite>.html
                $prefixMatch = $idProduct === $idPrefix
                    && substr($matches[2], -strlen($rewrite)) === $rewrite;
                // <rewrite>-<id>.html
                $suffixMatch = $idProduct === $idSuffix && $matches[3] === $rewrite;
                if ($prefixMatch || $suffixMatch) {
                    return $product;
                }
            }
        }

        PrestaShopLogger::addLog('Pricee webhook: no product matches URL '.$url, 2);

        return null;
    }

    /**
     * Convert a price as displayed to visitors (what Pricee reads on product
     * pages) into the tax-excluded amount PrestaShop stores.
     */
    private function toStoredPrice(Product $product, float $displayedPrice): float
    {
        if (PS_TAX_EXC === Product::getTaxCalculationMethod()) {
            return $displayedPrice;
        }

        // Visitors see the prices of the shop default country
        $address = new Address();
        $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $taxRate = (float) $product->getTaxesRate($address);

        return round($displayedPrice / (1 + $taxRate / 100), 6);
    }

    /**
     * Write the new price where it drives the effective price: the active
     * promotion (specific price) when one applies, the base price otherwise.
     */
    private function applyPrice(Product $product, float $newPrice): void
    {
        $specificPrice = $this->getActiveSpecificPrice($product);

        if (null === $specificPrice) {
            $currentPrice = $product->price;
            $product->price = $newPrice;
            $product->update();

            PrestaShopLogger::addLog(
                "Pricee webhook: price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}",
                1
            );

            return;
        }

        if ($this->isOwnedByProductAndShop($specificPrice, $product)) {
            $currentPrice = $specificPrice->price;
            $specificPrice->price = $newPrice;
            $specificPrice->reduction = 0;
            $specificPrice->update();

            PrestaShopLogger::addLog(
                "Pricee webhook: promotion price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}",
                1
            );

            return;
        }

        $this->addProductPromotion($specificPrice, $product, $newPrice);
    }

    /**
     * Return the specific price (promotion) a visitor of the current shop
     * gets for a quantity of 1, or null when the base price applies.
     */
    private function getActiveSpecificPrice(Product $product): ?SpecificPrice
    {
        $specificPriceData = SpecificPrice::getSpecificPrice(
            (int) $product->id,
            (int) $this->context->shop->id,
            (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            (int) Group::getCurrent()->id,
            1
        );

        // empty() also covers the false return value of older PrestaShop versions
        if (empty($specificPriceData) || !isset($specificPriceData['id_specific_price'])) {
            return null;
        }

        $specificPrice = new SpecificPrice((int) $specificPriceData['id_specific_price']);

        return Validate::isLoadedObject($specificPrice) ? $specificPrice : null;
    }

    /**
     * Whether a specific price can be edited in place: it belongs to this
     * product only (not catalog-wide), is not generated by a catalog price
     * rule (those are regenerated) and, in multistore, to this shop only.
     */
    private function isOwnedByProductAndShop(SpecificPrice $specificPrice, Product $product): bool
    {
        if ((int) $specificPrice->id_product !== (int) $product->id
            || 0 !== (int) $specificPrice->id_specific_price_rule) {
            return false;
        }

        return !Shop::isFeatureActive() || (int) $specificPrice->id_shop === (int) $this->context->shop->id;
    }

    /**
     * Add a promotion for this product and shop only, with the same
     * conditions and dates as the shared one it overrides, so the other
     * products, shops and the catalog price rules are left untouched.
     */
    private function addProductPromotion(SpecificPrice $source, Product $product, float $newPrice): void
    {
        $promotion = new SpecificPrice();
        $promotion->id_product = (int) $product->id;
        $promotion->id_product_attribute = 0;
        $promotion->id_shop = (int) $this->context->shop->id;
        $promotion->id_shop_group = 0;
        $promotion->id_cart = 0;
        $promotion->id_specific_price_rule = 0;
        $promotion->id_currency = (int) $source->id_currency;
        $promotion->id_country = (int) $source->id_country;
        $promotion->id_group = (int) $source->id_group;
        $promotion->id_customer = (int) $source->id_customer;
        $promotion->from_quantity = (int) $source->from_quantity;
        $promotion->from = $source->from;
        $promotion->to = $source->to;
        $promotion->price = $newPrice;
        $promotion->reduction = 0;
        $promotion->reduction_tax = (int) $source->reduction_tax;
        $promotion->reduction_type = $source->reduction_type;

        if (!$promotion->add()) {
            throw new RuntimeException('could not create a promotion for this product and shop');
        }

        PrestaShopLogger::addLog(
            "Pricee webhook: promotion added for product ID {$product->id} in shop ID {$promotion->id_shop} | New: {$newPrice}",
            1
        );
    }

    private function sendResponse(int $statusCode, bool $success, string $message): void
    {
        http_response_code($statusCode);
        echo json_encode(['success' => $success, 'message' => $message]);

        exit;
    }
}
