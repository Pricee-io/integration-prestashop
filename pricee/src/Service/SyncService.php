<?php

declare(strict_types=1);

namespace Pricee\Service;

class SyncService
{
    private const MAX_PRODUCTS = 500;
    private ApiService $apiService;

    public function __construct(
        ApiService $apiService,
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
        $bearer = $this->apiService->getBearer($clientId, $apiKey);
        $websites = $this->apiService->getWebsites($bearer);

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
        foreach ($categories as $idCategory) {
            if ($syncedCount >= self::MAX_PRODUCTS) {
                break;
            }
            $this->iterateProductsByCategory(
                (int) $idCategory,
                $idLang,
                function (\Product $product) use (
                    &$context,
                    &$bearer,
                    &$websiteId,
                    &$idLang,
                    &$syncedCount
                ) {
                    $productUrl = $context->link->getProductLink(
                        $product,
                        null,
                        null,
                        (string) $idLang,
                        $context->shop->id
                    );

                    try {
                        $this->apiService->createProduct($bearer, $websiteId, $productUrl);
                    } catch (\Exception) {
                        // ignore error for now and go to next product
                    }

                    ++$syncedCount;
                }
            );
        }

        return $syncedCount;
    }

    private function iterateProductsByCategory(
        int $idCategory,
        int $idLang,
        callable $callback,
        int $batchSize = 100
    ): void {
        $offset = 0;

        do {
            $sql = '
                SELECT cp.id_product
                FROM '._DB_PREFIX_.'category_product cp
                INNER JOIN '._DB_PREFIX_.'product p ON p.id_product = cp.id_product
                WHERE cp.id_category = '.(int) $idCategory.'
                AND p.active = 1
                ORDER BY cp.id_product
                LIMIT '.(int) $batchSize.' OFFSET '.(int) $offset;

            $rows = \Db::getInstance()->executeS($sql);

            foreach ($rows as $row) {
                $product = new \Product((int) $row['id_product'], false, $idLang);

                $callback($product);

                // free memory explicitly
                unset($product);
            }

            $offset += $batchSize;
        } while (!empty($rows));
    }
}
