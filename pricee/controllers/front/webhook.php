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

        // Process products
        foreach ($data as $productData) {
            if (empty($productData['extId']) || !isset($productData['bestPriceAmount'])) {
                continue;
            }

            $product = new Product((int) $productData['extId']);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            // Only update simple products for now (no variants)
            $productType = $product->getDynamicProductType();
            if (ProductType::TYPE_PACK === $productType
                || ProductType::TYPE_COMBINATIONS === $productType) {
                continue;
            }

            try {
                // Validate the received price before writing it
                $rawPrice = $productData['bestPriceAmount'];
                if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                    PrestaShopLogger::addLog(
                        'Pricee webhook: invalid price received for product ID '.$product->id.' | Value: '.print_r($rawPrice, true),
                        2
                    );

                    continue;
                }
                $newPrice = (float) $rawPrice;

                // When an active specific price (promotion) exists, it drives
                // the effective price: update it instead of the base price
                $specificPrice = $this->getActiveSpecificPrice($product);
                if (null !== $specificPrice) {
                    $currentPrice = $specificPrice->price;
                    $specificPrice->price = $newPrice;
                    $specificPrice->reduction = 0;
                    $specificPrice->update();

                    PrestaShopLogger::addLog(
                        "Pricee webhook: promotion price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}",
                        1
                    );
                } else {
                    $currentPrice = $product->price;
                    $product->price = $newPrice;
                    $product->update();

                    PrestaShopLogger::addLog(
                        "Pricee webhook: price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}",
                        1
                    );
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    "Pricee webhook: price update failed for product ID {$product->id}: ".$e->getMessage(),
                    3
                );
            }
        }

        $this->sendResponse(200, true, 'Webhook received and validated');
    }

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
     * Return the specific price (promotion) currently applied to a product
     * for a quantity of 1, or null when the base price applies.
     */
    private function getActiveSpecificPrice(Product $product): ?SpecificPrice
    {
        $context = $this->context;

        $specificPriceData = SpecificPrice::getSpecificPrice(
            (int) $product->id,
            (int) $context->shop->id,
            (int) $context->currency->id,
            (int) $context->country->id,
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

    private function sendResponse(int $statusCode, bool $success, string $message): void
    {
        http_response_code($statusCode);
        echo json_encode(['success' => $success, 'message' => $message]);

        exit;
    }
}
