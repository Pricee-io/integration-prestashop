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
     * Sync the given categories of a single shop.
     *
     * @param array<int> $categories
     */
    public function sync(int $idShop, array $categories): int
    {
        $context = \Context::getContext();

        // Product URLs are sent in the shop default language: the back-office
        // user's language may not be active in that shop
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        // Credentials can be configured per shop: read them for the shop
        // being synced, with the usual fallback to group/global values
        $clientId = (string) \Configuration::get('PRICEE_CLIENT_ID', null, null, $idShop);
        $apiKey = (string) \Configuration::get('PRICEE_API_KEY', null, null, $idShop);

        try {
            $bearer = $this->apiService->getBearer($clientId, $apiKey);
            $websites = $this->apiService->getWebsites($bearer);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Pricee sync (shop '.$idShop.'): '.$e->getMessage(), 3);

            throw $e;
        }

        // In multistore each shop can have its own domain, so the website
        // registered on Pricee must be the shop front URL, not the host of
        // the back-office request
        $normalizedWebsiteUrl = rtrim($this->getShopWebsiteUrl($idShop), '/');
        $websiteId = null;

        // Look for existing website, whatever the scheme it was registered with
        $websiteKey = $this->websiteKey($normalizedWebsiteUrl);
        foreach ($websites as $w) {
            if ($this->websiteKey((string) $w['url']) === $websiteKey) {
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
            $idShop,
            $idLang,
            self::MAX_PRODUCTS,
            function (\Product $product) use (
                $context,
                &$bearer,
                &$websiteId,
                $idLang,
                $idShop,
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

    /**
     * IDs of the categories a shop can sync, in tree order: the categories
     * associated with the shop that sit under its own home category. The
     * global root and the home category itself are left out, as every
     * product belongs to its home category, and so are categories of
     * another shop's tree that happen to be associated with this shop.
     *
     * @return array<int>
     */
    public static function shopCategoryIds(int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT c.id_category
            FROM '._DB_PREFIX_.'category c
            INNER JOIN '._DB_PREFIX_.'category_shop cs ON cs.id_category = c.id_category AND cs.id_shop = '.(int) $idShop.'
            INNER JOIN '._DB_PREFIX_.'shop s ON s.id_shop = '.(int) $idShop.'
            INNER JOIN '._DB_PREFIX_.'category home ON home.id_category = s.id_category
            WHERE c.nleft > home.nleft AND c.nright < home.nright
            ORDER BY c.nleft'
        );

        return array_map('intval', array_column($rows ?: [], 'id_category'));
    }

    /**
     * FROM/WHERE clause of the products a shop sells, joined to their
     * categories: category_product is not shop-specific, and product_shop
     * holds the shop's own active flag (product.active is the default
     * shop's), so a multistore sync never takes another shop's products.
     */
    public static function shopProductsSql(int $idShop): string
    {
        return _DB_PREFIX_.'category_product cp
            INNER JOIN '._DB_PREFIX_.'product_shop ps ON ps.id_product = cp.id_product AND ps.id_shop = '.(int) $idShop.'
            WHERE ps.active = 1';
    }

    /**
     * Front URL of a shop (its own domain and base URI), in https when SSL is
     * enabled whatever the protocol of the back-office request.
     */
    private function getShopWebsiteUrl(int $idShop): string
    {
        $ssl = (bool) \Configuration::get('PS_SSL_ENABLED', null, null, $idShop);

        return \Context::getContext()->link->getBaseLink($idShop, $ssl);
    }

    private function websiteKey(string $url): string
    {
        return strtolower((string) preg_replace('#^https?://#i', '', rtrim($url, '/')));
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
            $sql = '
                SELECT DISTINCT cp.id_product
                FROM '.self::shopProductsSql($idShop).'
                AND cp.id_category IN ('.implode(',', $idCategories).')
                ORDER BY cp.id_product
                LIMIT '.(int) $batchSize.' OFFSET '.(int) $offset;

            $rows = \Db::getInstance()->executeS($sql);

            foreach ($rows as $row) {
                if ($count >= $limit) {
                    return;
                }

                $product = new \Product((int) $row['id_product'], false, $idLang, $idShop);

                $callback($product);
                ++$count;

                // free memory explicitly
                unset($product);
            }

            $offset += $batchSize;
        } while (!empty($rows));
    }
}
