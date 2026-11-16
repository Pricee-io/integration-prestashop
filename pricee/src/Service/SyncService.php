<?php

declare(strict_types=1);

namespace PriceeIO\Service;

class SyncService
{
    /**
     * Products synced per request: the page sends one request per batch, so
     * each stays short whatever the size of the selection (no timeout).
     */
    public const BATCH_SIZE = 50;

    /** Configuration key of the login reused between batches (see login()) */
    public const LOGIN_KEY = 'PRICEE_API_LOGIN';

    /** A Pricee token lasts an hour: reused 45 minutes */
    private const LOGIN_TTL = 2700;
    private ApiService $apiService;

    public function __construct(
        ApiService $apiService
    ) {
        $this->apiService = $apiService;
    }

    /**
     * Sync one batch of the products of the given categories of a shop: the
     * next products after $afterId, in product ID order. Called again with
     * the returned lastId until done.
     *
     * @param array<int> $categories
     *
     * @return array{synced: int, failed: int, lastId: int, done: bool, total: int}
     */
    public function sync(int $idShop, array $categories, int $afterId = 0, int $batchSize = self::BATCH_SIZE): array
    {
        $categories = array_map('intval', $categories);
        $total = $this->countProducts($categories, $idShop);
        $productIds = $this->nextProductIds($categories, $idShop, $afterId, $batchSize);

        if ([] === $productIds) {
            return ['synced' => 0, 'failed' => 0, 'lastId' => $afterId, 'done' => true, 'total' => $total];
        }

        // Product URLs are sent in the shop default language: the back-office
        // user's language may not be active in that shop
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        // Credentials can be configured per shop: read them for the shop
        // being synced, with the usual fallback to group/global values
        $clientId = (string) \Configuration::get('PRICEE_CLIENT_ID', null, null, $idShop);
        $apiKey = (string) \Configuration::get('PRICEE_API_KEY', null, null, $idShop);

        // In multistore each shop can have its own domain, so the website
        // registered on Pricee must be the shop front URL, not the host of
        // the back-office request
        $normalizedWebsiteUrl = rtrim($this->getShopWebsiteUrl($idShop), '/');

        // The login of a previous batch is reused; a reused token that is
        // refused is replaced by a new login, once
        $fresh = false;
        while (true) {
            try {
                $login = $this->login($idShop, $clientId, $apiKey, $fresh);
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('Pricee sync (shop '.$idShop.'): '.$e->getMessage(), 3);

                throw $e;
            }
            $bearer = $login['token'];

            // Each API key is scoped to a website: sync to it, never to another one
            $websiteId = $login['website'];
            if (null === $websiteId) {
                $message = 'This API key is not tied to a website. Create an API key from the Integrations page of this shop\'s website on Pricee.';
                \PrestaShopLogger::addLog('Pricee sync (shop '.$idShop.'): '.$message, 3);

                throw new \RuntimeException($message);
            }

            try {
                $website = $this->apiService->getWebsite($bearer, $websiteId);

                break;
            } catch (\Throwable $e) {
                if (!$fresh && 401 === $e->getCode()) {
                    $fresh = true;

                    continue;
                }
                \PrestaShopLogger::addLog('Pricee sync (shop '.$idShop.'): '.$e->getMessage(), 3);

                throw $e;
            }
        }

        $keyWebsiteUrl = is_string($website['url'] ?? null) ? $website['url'] : '';
        if ($this->websiteHost($keyWebsiteUrl) !== $this->websiteHost($normalizedWebsiteUrl)) {
            $message = sprintf(
                'This API key belongs to the Pricee website %s, not to this shop (%s). Use an API key created from the Integrations page of this shop\'s website on Pricee.',
                $keyWebsiteUrl,
                $normalizedWebsiteUrl
            );
            \PrestaShopLogger::addLog('Pricee sync (shop '.$idShop.'): '.$message, 3);

            throw new \RuntimeException($message);
        }

        $synced = 0;
        $failed = 0;
        foreach ($productIds as $idProduct) {
            $product = new \Product($idProduct, false, $idLang, $idShop);

            try {
                $this->apiService->createOrUpdateProduct($bearer, $websiteId, $this->productUrl($product, $idLang, $idShop), (string) $product->id);
                ++$synced;
            } catch (\Exception $e) {
                // log the error and go to the next product
                ++$failed;
                \PrestaShopLogger::addLog('Pricee sync: failed to sync product ID '.$product->id.': '.$e->getMessage(), 3);
            }

            unset($product);
        }

        $lastId = (int) end($productIds);

        return [
            'synced' => $synced,
            'failed' => $failed,
            'lastId' => $lastId,
            'done' => [] === $this->nextProductIds($categories, $idShop, $lastId, 1),
            'total' => $total,
        ];
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
     * Log in to Pricee for a shop, reusing the login of a previous batch:
     * Pricee allows few logins per minute, and a token lasts an hour, so a
     * large sync logs in once instead of once per batch. Kept 45 minutes in
     * the shop's configuration, for the API key it was made with.
     *
     * @return array{token: string, website: ?string}
     */
    private function login(int $idShop, string $clientId, string $apiKey, bool $fresh): array
    {
        $idShopGroup = (int) \Shop::getGroupFromShop($idShop);
        $keyHash = hash('sha256', $clientId.'|'.$apiKey);

        $stored = json_decode((string) \Configuration::get(self::LOGIN_KEY, null, $idShopGroup, $idShop), true);
        if (!$fresh && is_array($stored) && ($stored['key'] ?? null) === $keyHash
            && (int) ($stored['expires'] ?? 0) > time() && is_string($stored['token'] ?? null)) {
            return ['token' => $stored['token'], 'website' => is_string($stored['website'] ?? null) ? $stored['website'] : null];
        }

        $login = $this->apiService->login($clientId, $apiKey);
        \Configuration::updateValue(self::LOGIN_KEY, (string) json_encode([
            'key' => $keyHash,
            'token' => $login['token'],
            'website' => $login['website'],
            'expires' => time() + self::LOGIN_TTL,
        ]), false, $idShopGroup, $idShop);

        return $login;
    }

    /**
     * Front-office URL of a product page, as Pricee reads it.
     */
    private function productUrl(\Product $product, int $idLang, int $idShop): string
    {
        $forceRoutes = (bool) \Configuration::get('PS_REWRITING_SETTINGS', null, null, $idShop);

        $productUrl = \Context::getContext()->link->getProductLink($product, null, null, null, $idLang, $idShop, null, $forceRoutes);

        // In back-office requests the dispatcher can fall back to the
        // legacy "product_rule" URL, which is not a valid front-office
        // route: rewrite it to the standard product URL
        $normalizedUrl = preg_replace(
            '#^(.*index\.php)\?controller=product_rule&id=(\d+).*$#',
            '$1?controller=product&id_product=$2',
            $productUrl
        );

        return is_string($normalizedUrl) ? $normalizedUrl : $productUrl;
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

    /**
     * Host of a URL, compared like Pricee checks that a product belongs to a
     * website: case-insensitive, without "www.".
     */
    private function websiteHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', $host);
    }

    /**
     * Number of products a sync of these categories takes in the shop.
     *
     * @param array<int> $idCategories
     */
    private function countProducts(array $idCategories, int $idShop): int
    {
        if ([] === $idCategories) {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT cp.id_product)
            FROM '.self::shopProductsSql($idShop).'
            AND cp.id_category IN ('.implode(',', $idCategories).')'
        );
    }

    /**
     * IDs of the next products to sync after $afterId, in ID order: a cursor
     * stays correct even if products are added or removed between batches.
     *
     * @param array<int> $idCategories
     *
     * @return array<int>
     */
    private function nextProductIds(array $idCategories, int $idShop, int $afterId, int $limit): array
    {
        if ([] === $idCategories || $limit <= 0) {
            return [];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT DISTINCT cp.id_product
            FROM '.self::shopProductsSql($idShop).'
            AND cp.id_category IN ('.implode(',', $idCategories).')
            AND cp.id_product > '.$afterId.'
            ORDER BY cp.id_product
            LIMIT '.$limit
        );

        return array_map('intval', array_column($rows ?: [], 'id_product'));
    }
}
