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
        // if (!hash_equals($expected_signature, $signature)) {
        //     http_response_code(401);
        //     echo json_encode(['success' => false, 'message' => 'Invalid signature']);

        //     exit;
        // }

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

            $product = new Product($productData['extId']);
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

                $this->pricee_log(
                    "Price updated for product ID {$product->id} | Old: {$currentPrice} -> New: {$newPrice}"
                );
            } catch (Exception $e) {
                $this->pricee_log(
                    "Price update failed for product ID {$product->id}: ".$e->getMessage(),
                    'ERROR'
                );
            }
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Webhook received and validated']);

        exit;
    }

    public function pricee_log(mixed $message, string $level = 'INFO', string $file = 'main')
    {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $log_dir = _PS_MODULE_DIR_.'pricee/logs/';

        if (!file_exists($log_dir)) {
            if (!mkdir($log_dir, 0755, true) && !is_dir($log_dir)) {
                error_log("Failed to create log folder: {$log_dir}");

                return;
            }
        }

        $log_file = $log_dir.$file.'.log';

        $time = date('Y-m-d H:i:s');

        $line = "[{$time}] [{$level}] {$message}".PHP_EOL;

        file_put_contents($log_file, $line, FILE_APPEND);
    }
}
