<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType;

class PriceeWebhookModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        // Check request
        $body = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

        if (empty($body) || empty($signature)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing information for webhook']);

            exit;
        }

        // Check webhook configuration
        $enabled = Configuration::get('PRICEE_WEBHOOK_ENABLED', 0);
        $secret = Configuration::get('PRICEE_WEBHOOK_SECRET', null);
        if (!$enabled || empty($secret)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Webhook not configured']);

            exit;
        }

        // Verify signature
        $expected_signature = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected_signature, $signature)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid signature']);

            exit;
        }

        // Get JSON
        $data = json_decode($body, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);

            exit;
        }

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
                $currentPrice = $product->price;
                $newPrice = (float) $productData['bestPriceAmount'];
                $product->price = $newPrice;
                $product->update();

                PrestaShopLogger::addLog(
                    "Pricee webhook: price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}",
                    1
                );
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    "Pricee webhook: price update failed for product ID {$product->id}: ".$e->getMessage(),
                    3
                );
            }
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Webhook received and validated']);

        exit;
    }
}
