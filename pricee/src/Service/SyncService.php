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
        $idShop = (int) $context->shop->id;
        $this->iterateProducts(
            array_map('intval', $categories),
            $idShop,
            $idLang,
            self::MAX_PRODUCTS,
            function (\Product $product) use (
                &$context,
                &$bearer,
                &$websiteId,
                &$idLang,
                &$idShop,
                &$syncedCount
            ) {
                // Get the internal PrestaShop product ID
                $productExtId = (string) $product->id;

                $forceRoutes = (bool) \Configuration::get('PS_REWRITING_SETTINGS', null, null, $idShop);

                $productUrl = $context->link->getProductLink(
                    $product,
                    null,
                    null,
                    null,
                    (int) $idLang,
                    $idShop,
                    null,
                    $forceRoutes
                );

                // In back-office requests the dispatcher can fall back to the
                // legacy "product_rule" URL, which is not a valid front-office
                // route: rewrite it to the standard product URL
                $normalizedUrl = preg_replace(
                    '#^(.*index\.php)\?controller=product_rule&id=(\d+).*$#',
                    '$1?controller=product&id_product=$2',
                    $productUrl
                );
                if (is_string($normalizedUrl)) {
                    $productUrl = $normalizedUrl;
                }

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
        int $idShop,
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
            // Scope to the current shop: category_product is not shop-specific
            // and product.active is the global flag, so without the product_shop
            // join a multistore sync would also pick up products that belong to
            // other shops or are disabled in the current one
            $sql = '
                SELECT DISTINCT cp.id_product
                FROM '._DB_PREFIX_.'category_product cp
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product = cp.id_product
                INNER JOIN '._DB_PREFIX_.'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = '.(int) $idShop.'
                WHERE cp.id_category IN ('.implode(',', $idCategories).')
                AND p.active = 1
                AND ps.active = 1
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
