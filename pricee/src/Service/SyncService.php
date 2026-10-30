<?php

declare(strict_types=1);

namespace PriceeIO\Service;

class SyncService
{
    private const MAX_PRODUCTS = 500;
    private ApiService $apiService;

    public function __construct(
        ApiService $apiService
    ) {
        $this->apiService = $apiService;
    }

    /**
     * @param array<int> $categories
     */
    public function sync(string $websiteUrl, int $idLang, array $categories): int
    {
        $context = \Context::getContext();

        $clientId = (string) \Configuration::get('PRICEE_CLIENT_ID');
        $apiKey = (string) \Configuration::get('PRICEE_API_KEY');

        try {
            $bearer = $this->apiService->getBearer($clientId, $apiKey);
            $websites = $this->apiService->getWebsites($bearer);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Pricee sync: '.$e->getMessage(), 3);

            throw $e;
        }

        $normalizedWebsiteUrl = rtrim($websiteUrl, '/');
        $websiteId = null;

        // Look for existing website
        foreach ($websites as $w) {
            if (rtrim($w['url'], '/') === $normalizedWebsiteUrl) {
                $websiteId = $w['@id'];

                break;
            }
        }

        // If not found, create it
        if (!$websiteId) {
            $website = $this->apiService->createWebsite($bearer, $normalizedWebsiteUrl);
            $websiteId = $website['@id'];
        }

        $syncedCount = 0;
        $this->iterateProducts(
            array_map('intval', $categories),
            $idLang,
            self::MAX_PRODUCTS,
            function (\Product $product) use (
                &$context,
                &$bearer,
                &$websiteId,
                &$idLang,
                &$syncedCount
            ) {
                // Get the internal PrestaShop product ID
                $productExtId = (string) $product->id;

                $productUrl = $context->link->getProductLink(
                    $product,
                    null,
                    null,
                    (string) $idLang,
                    $context->shop->id
                );

                try {
                    $this->apiService->createOrUpdateProduct(
                        $bearer,
                        $websiteId,
                        $productUrl,
                        $productExtId,
                    );

                    ++$syncedCount;
                } catch (\Exception $e) {
                    // log the error and go to the next product
                    \PrestaShopLogger::addLog(
                        'Pricee sync: failed to sync product ID '.$product->id.': '.$e->getMessage(),
                        3
                    );
                }
            }
        );

        return $syncedCount;
    }

    private function iterateProducts(
        array $idCategories,
        int $idLang,
        int $limit,
        callable $callback,
        int $batchSize = 100
    ): void {
        if ([] === $idCategories || $limit <= 0) {
            return;
        }

        $idCategories = array_map('intval', $idCategories);
        $offset = 0;
        $count = 0;

        do {
            $sql = '
                SELECT DISTINCT cp.id_product
                FROM '._DB_PREFIX_.'category_product cp
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product = cp.id_product
                WHERE cp.id_category IN ('.implode(',', $idCategories).')
                AND p.active = 1
                ORDER BY cp.id_product
                LIMIT '.(int) $batchSize.' OFFSET '.(int) $offset;

            $rows = \Db::getInstance()->executeS($sql);

            foreach ($rows as $row) {
                if ($count >= $limit) {
                    return;
                }

                $product = new \Product((int) $row['id_product'], false, $idLang);

                $callback($product);
                ++$count;

                // free memory explicitly
                unset($product);
            }

            $offset += $batchSize;
        } while (!empty($rows));
    }
}
