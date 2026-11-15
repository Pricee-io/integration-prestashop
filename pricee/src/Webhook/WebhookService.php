<?php

declare(strict_types=1);

namespace PriceeIO\Webhook;

/**
 * Price-update webhook received from Pricee (see the webhook contract in
 * AGENTS.md): checks the call, then applies each entry to the receiving shop.
 */
class WebhookService
{
    /** Maximum age of a webhook payload, in seconds (5 minutes) */
    public const WEBHOOK_MAX_AGE = 300;

    /** How long a used nonce is remembered, in seconds (twice the max age) */
    public const NONCE_TTL = 600;

    /**
     * Table of the nonces already used, without prefix: one row per shop and
     * nonce hash, the primary key makes consuming a nonce atomic.
     */
    public const NONCE_TABLE = 'pricee_webhook_nonce';

    /**
     * Outcome of each entry. The price is written only when every rule
     * passes (see docs/behavior.md at the repository root);
     * in any doubtful case the entry is skipped and logged.
     */
    public const UPDATED_PRICE = 'updated_price';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED_NO_PRICE = 'skipped_no_price';
    public const SKIPPED_INVALID_PRICE = 'skipped_invalid_price';
    public const SKIPPED_CURRENCY = 'skipped_currency';
    public const SKIPPED_UNKNOWN_PRODUCT = 'skipped_unknown_product';
    public const SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const SKIPPED_OTHER_SHOP = 'skipped_other_shop';
    public const SKIPPED_PRICE_NOT_SHOWN = 'skipped_price_not_shown';
    public const SKIPPED_UNSUPPORTED_TYPE = 'skipped_unsupported_type';
    public const SKIPPED_PROMOTION = 'skipped_promotion';
    public const SKIPPED_ECOTAX = 'skipped_ecotax';
    public const SKIPPED_GROUP_DISCOUNT = 'skipped_group_discount';
    public const SKIPPED_PRICE_CHANGED_IN_SHOP = 'skipped_price_changed_in_shop';
    public const SKIPPED_BELOW_COST = 'skipped_below_cost';
    public const SKIPPED_UNIT_PRICE = 'skipped_unit_price';
    public const FAILED = 'failed';

    /**
     * Check a webhook call and apply it to a shop.
     *
     * @return array<int, string> outcome of each entry, in payload order
     *
     * @throws WebhookRejectedException when the whole call is refused
     */
    public function handle(string $body, string $signature, int $idShop, ?int $now = null): array
    {
        $now ??= time();

        if ('' === $body || '' === $signature) {
            throw new WebhookRejectedException('Missing information for webhook', 400);
        }

        $enabled = \Configuration::get('PRICEE_WEBHOOK_ENABLED', null, null, $idShop);
        $secret = (string) \Configuration::get('PRICEE_WEBHOOK_SECRET', null, null, $idShop);
        if (!$enabled || '' === $secret) {
            throw new WebhookRejectedException('Webhook not configured', 401);
        }

        // HMAC over the raw body, before any decoding
        if (!hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            throw new WebhookRejectedException('Invalid signature', 401);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new WebhookRejectedException('Invalid JSON payload', 400);
        }

        $this->validateReplayProtection($data, $idShop, $now);

        // Resolve every entry first: a product targeted more than once in the
        // same call has conflicting prices, none of them is applied
        $products = [];
        $timesTargeted = [];
        foreach ($data as $index => $productData) {
            $product = is_array($productData) ? $this->resolveProduct($productData, $idShop) : null;
            $products[$index] = $product;
            if (null !== $product) {
                $timesTargeted[(int) $product->id] = ($timesTargeted[(int) $product->id] ?? 0) + 1;
            }
        }

        $outcomes = [];
        foreach ($data as $index => $productData) {
            $product = $products[$index];
            if (null !== $product && $timesTargeted[(int) $product->id] > 1) {
                \PrestaShopLogger::addLog('Pricee webhook: product ID '.$product->id.' is sent several times in the same call', 2);
                $outcomes[] = self::SKIPPED_DUPLICATE;

                continue;
            }

            $outcomes[] = $this->applyEntry(is_array($productData) ? $productData : [], $product, $idShop);
        }

        return $outcomes;
    }

    /**
     * Apply one entry: write its price only when every rule passes.
     *
     * @param array<string, mixed> $productData
     */
    private function applyEntry(array $productData, ?\Product $product, int $idShop): string
    {
        if (!isset($productData['bestPriceAmount'])) {
            return self::SKIPPED_NO_PRICE;
        }

        $rawPrice = $productData['bestPriceAmount'];
        if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
            return $this->skip(self::SKIPPED_INVALID_PRICE, 'invalid price '.print_r($rawPrice, true), $product);
        }

        // Product prices are stored in the shop default currency: an amount in
        // another currency cannot be written as is
        $shopCurrency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $currency = $productData['bestPriceCurrency'] ?? null;
        if (!is_string($currency) || strtoupper($currency) !== strtoupper((string) $shopCurrency->iso_code)) {
            return $this->skip(self::SKIPPED_CURRENCY, 'price currency '.print_r($currency, true).' is not the shop currency '.$shopCurrency->iso_code, $product);
        }

        if (null === $product) {
            return self::SKIPPED_UNKNOWN_PRODUCT;
        }

        // Multistore: the webhook endpoint belongs to one shop, only update
        // products that are actually associated with it
        if (!$product->isAssociatedToShop($idShop)) {
            return $this->skip(self::SKIPPED_OTHER_SHOP, 'not associated with shop ID '.$idShop, $product);
        }

        // Visitors do not see a price Pricee could have read
        if (!$product->active || (!$product->available_for_order && !$product->show_price)) {
            return $this->skip(self::SKIPPED_PRICE_NOT_SHOWN, 'disabled or price not shown', $product);
        }
        if (!$this->pricesShownToVisitors($idShop)) {
            return $this->skip(self::SKIPPED_PRICE_NOT_SHOWN, 'prices are hidden from visitors (catalog mode or visitor group)', $product);
        }

        // Only simple products: the page of a product with variants shows
        // the default variant's price, a pack's price is computed. Any
        // combination counts, with or without attributes (older PrestaShop
        // versions only detect combinations that have attributes)
        if (\Pack::isPack((int) $product->id) || [] !== (\Product::getProductAttributesIds((int) $product->id) ?: [])) {
            return $this->skip(self::SKIPPED_UNSUPPORTED_TYPE, 'variants or pack', $product);
        }

        // The displayed price may come from a promotion, a quantity discount
        // or a catalog price rule: the base price alone cannot be derived
        if ($this->hasSpecificPrice($product, $idShop)) {
            return $this->skip(self::SKIPPED_PROMOTION, 'has a promotion, quantity discount or catalog price rule', $product);
        }

        // The displayed price includes the ecotax, not part of the base price
        if (\Configuration::get('PS_USE_ECOTAX', null, null, $idShop) && (float) $product->ecotax > 0) {
            return $this->skip(self::SKIPPED_ECOTAX, 'has an ecotax', $product);
        }

        // Visitors get a group discount, the displayed price is not the base price
        $idGroup = $this->visitorGroupId($idShop);
        if ((float) \Group::getReductionByIdGroup($idGroup) > 0
            || (float) \GroupReduction::getValueForProduct((int) $product->id, $idGroup) > 0) {
            return $this->skip(self::SKIPPED_GROUP_DISCOUNT, 'visitors get a group discount', $product);
        }

        // Pricee computes the new price from the price it last read on the
        // page: when the merchant changed it since, their change wins
        $displayFactor = $this->displayFactor($product, $idShop);
        $currentPrice = (float) $product->price;
        $precision = max(0, (int) $shopCurrency->precision);
        $displayedPrice = round($currentPrice * $displayFactor, $precision);
        $readPrice = $productData['currentPriceAmount'] ?? null;
        if (!is_numeric($readPrice)) {
            return $this->skip(self::SKIPPED_PRICE_CHANGED_IN_SHOP, 'no current price sent to compare with the shop price', $product);
        }
        if (abs($displayedPrice - round((float) $readPrice, $precision)) >= 0.000001) {
            return $this->skip(self::SKIPPED_PRICE_CHANGED_IN_SHOP, 'the shop price '.$displayedPrice.' is no longer the one Pricee read ('.$readPrice.')', $product);
        }

        $newPrice = round((float) $rawPrice / $displayFactor, 6);

        // Never sell below the cost price
        if ((float) $product->wholesale_price > 0 && $newPrice < (float) $product->wholesale_price) {
            return $this->skip(self::SKIPPED_BELOW_COST, 'new price '.$newPrice.' is below the cost price '.$product->wholesale_price, $product);
        }

        // Compared as visitors see it, rounded to the currency precision: a
        // stored price differing only in its hidden decimals is unchanged
        if (abs($displayedPrice - round((float) $rawPrice, $precision)) < 0.000001) {
            return self::UNCHANGED;
        }

        // The price per unit (e.g. per kg) keeps its ratio to the price. Since
        // PrestaShop 8 the unit price is stored and would otherwise stay at
        // its old value; before, it is computed from the ratio, which is kept
        $currentUnitPrice = (float) $product->unit_price;
        if ($currentUnitPrice > 0) {
            if ($currentPrice <= 0) {
                return $this->skip(self::SKIPPED_UNIT_PRICE, 'has a unit price but no current price to scale it from', $product);
            }
            $product->unit_price = round($currentUnitPrice * $newPrice / $currentPrice, 6);
        }

        try {
            $product->price = $newPrice;
            if (!$product->update()) {
                throw new \RuntimeException('could not save the product');
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog("Pricee webhook: price update failed for product ID {$product->id}: ".$e->getMessage(), 3);

            return self::FAILED;
        }

        \PrestaShopLogger::addLog("Pricee webhook: price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}", 1);

        return self::UPDATED_PRICE;
    }

    private function skip(string $outcome, string $reason, ?\Product $product): string
    {
        \PrestaShopLogger::addLog('Pricee webhook: product '.(null !== $product ? 'ID '.$product->id : 'not resolved').' skipped, '.$reason, 2);

        return $outcome;
    }

    /**
     * Whether a specific price (promotion, quantity discount, catalog price
     * rule) applies or will apply to the product in this shop, for anyone:
     * product-level or catalog-wide, current or scheduled.
     */
    private function hasSpecificPrice(\Product $product, int $idShop): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM '._DB_PREFIX_.'specific_price
            WHERE id_product IN (0, '.(int) $product->id.')
            AND id_shop IN (0, '.$idShop.')
            AND (`to` = \'0000-00-00 00:00:00\' OR `to` >= \''.pSQL(date('Y-m-d H:i:s')).'\')'
        );
    }

    /**
     * Reject replayed payloads: every entry must carry a fresh signed
     * timestamp and a nonce that has not been used within the accepted
     * window. The app signs a whole batch with a single nonce, so each
     * distinct nonce is consumed only once per payload.
     *
     * Each nonce is consumed by an insert on the table's primary key, which
     * the database makes atomic: of two calls carrying the same nonce at the
     * same time, only one gets through.
     *
     * @param array<int, mixed> $data
     */
    private function validateReplayProtection(array $data, int $idShop, int $now): void
    {
        $nonceHashes = [];

        foreach ($data as $productData) {
            if (!is_array($productData)
                || !isset($productData['timestamp'])
                || !is_numeric($productData['timestamp'])
                || !isset($productData['nonce'])
                || !is_string($productData['nonce'])
                || '' === $productData['nonce']) {
                throw new WebhookRejectedException('Missing timestamp or nonce', 401);
            }

            if (abs($now - (int) $productData['timestamp']) > self::WEBHOOK_MAX_AGE) {
                throw new WebhookRejectedException('Expired webhook payload', 401);
            }

            $nonceHashes[hash('sha256', $productData['nonce'])] = true;
        }

        $db = \Db::getInstance();
        $table = _DB_PREFIX_.self::NONCE_TABLE;

        // Expired nonces are forgotten: their payloads are refused as stale
        $db->execute('DELETE FROM `'.$table.'` WHERE expires_at <= '.$now);

        foreach (array_keys($nonceHashes) as $nonceHash) {
            $inserted = $db->execute(
                'INSERT IGNORE INTO `'.$table.'` (id_shop, nonce_hash, expires_at)
                VALUES ('.$idShop.', \''.pSQL((string) $nonceHash).'\', '.($now + self::NONCE_TTL).')',
                false
            );
            if (!$inserted || 1 !== (int) $db->Affected_Rows()) {
                throw new WebhookRejectedException('Replayed webhook payload', 401);
            }
        }
    }

    /**
     * Resolve the product targeted by a webhook entry: by its extId when one
     * is sent, otherwise (products synced before extId existed) by its URL.
     *
     * @param array<string, mixed> $productData
     */
    private function resolveProduct(array $productData, int $idShop): ?\Product
    {
        $extId = $productData['extId'] ?? null;

        if (null !== $extId && '' !== $extId) {
            // A stale extId must not fall back to the URL: it could match another product
            $product = is_numeric($extId) ? new \Product((int) $extId, false, null, $idShop) : null;
            if (null === $product || !\Validate::isLoadedObject($product)) {
                \PrestaShopLogger::addLog('Pricee webhook: no product found for extId '.print_r($extId, true), 2);

                return null;
            }

            return $product;
        }

        return $this->resolveProductFromUrl($productData['url'] ?? null, $idShop);
    }

    /**
     * Resolve a product from its URL: the id_product query parameter, or the
     * last path segment (<id>-<rewrite>.html or <rewrite>-<id>.html) whose
     * rewrite must be the product's own, so an unrelated number in a URL never
     * selects another product.
     *
     * @param mixed $url
     */
    private function resolveProductFromUrl($url, int $idShop): ?\Product
    {
        if (!is_string($url) || '' === $url) {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if (preg_match('/(?:^|&)id_product=(\d+)/', $query, $matches)) {
            $product = new \Product((int) $matches[1], false, null, $idShop);

            return \Validate::isLoadedObject($product) ? $product : null;
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
            $product = new \Product($idProduct, false, null, $idShop);
            if (!\Validate::isLoadedObject($product)) {
                continue;
            }

            foreach ((array) $product->link_rewrite as $rewrite) {
                $rewrite = strtolower((string) $rewrite);
                if ('' === $rewrite) {
                    continue;
                }

                // <id>-[<id_product_attribute>-]<rewrite>.html
                $prefixMatch = $idProduct === $idPrefix
                    && ($matches[2] === $rewrite || 1 === preg_match('/^\d+-'.preg_quote($rewrite, '/').'$/', $matches[2]));
                // <rewrite>-<id>.html
                $suffixMatch = $idProduct === $idSuffix && $matches[3] === $rewrite;
                if ($prefixMatch || $suffixMatch) {
                    return $product;
                }
            }
        }

        \PrestaShopLogger::addLog('Pricee webhook: no product matches URL '.$url, 2);

        return null;
    }

    /**
     * Factor from the tax-excluded price PrestaShop stores to the price
     * displayed to visitors (what Pricee reads on product pages): 1 when
     * visitors see prices tax excluded, 1 + the tax rate otherwise.
     */
    private function displayFactor(\Product $product, int $idShop): float
    {
        if (PS_TAX_EXC === (int) \Group::getPriceDisplayMethod($this->visitorGroupId($idShop))) {
            return 1.0;
        }

        // Visitors see the prices of the shop default country
        $address = new \Address();
        $address->id_country = (int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop);

        return 1 + (float) $product->getTaxesRate($address) / 100;
    }

    /**
     * Whether visitors see prices at all: catalog mode (unless set to show
     * prices) and the visitor group's "show prices" option hide them.
     */
    private function pricesShownToVisitors(int $idShop): bool
    {
        if (\Configuration::get('PS_CATALOG_MODE', null, null, $idShop)
            && !\Configuration::get('PS_CATALOG_MODE_WITH_PRICES', null, null, $idShop)) {
            return false;
        }

        return !\Group::isFeatureActive() || (bool) (new \Group($this->visitorGroupId($idShop)))->show_prices;
    }

    /**
     * Group of the visitors Pricee reads prices as, not logged in: the same
     * as Group::getCurrent() without customer, for the given shop.
     */
    private function visitorGroupId(int $idShop): int
    {
        $idGroup = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $idShop);
        $group = new \Group($idGroup);
        if (!\Validate::isLoadedObject($group) || !$group->isAssociatedToShop($idShop)) {
            $idGroup = (int) \Configuration::get('PS_CUSTOMER_GROUP', null, null, $idShop);
        }

        return $idGroup;
    }
}
