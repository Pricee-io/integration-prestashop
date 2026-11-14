<?php

declare(strict_types=1);

namespace PriceeIO\Settings;

/**
 * Module settings and the level they are saved at in multistore (see
 * docs/behavior.md, "Configuration block"):
 *
 * - Pricee credentials: shared (all shops, or a shop group) by default, a
 *   shop can use its own.
 * - Webhook settings: per shop only, as each shop is its own website with
 *   its own webhook on Pricee. They are not editable for all shops or a
 *   group.
 *
 * Without multistore everything is saved globally, as before.
 */
class SettingsService
{
    public const SCOPE_SINGLE = 'single';
    public const SCOPE_ALL = 'all';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_SHOP = 'shop';

    public const CREDENTIAL_KEYS = ['PRICEE_CLIENT_ID', 'PRICEE_API_KEY'];
    public const WEBHOOK_KEYS = ['PRICEE_WEBHOOK_ENABLED', 'PRICEE_WEBHOOK_SECRET'];

    /** A shop switching to its own credentials submitted no API key */
    public const ERROR_API_KEY_REQUIRED = 'api_key_required';

    /**
     * Level the back-office context edits.
     */
    public function scope(): string
    {
        if (!\Shop::isFeatureActive()) {
            return self::SCOPE_SINGLE;
        }

        switch (\Shop::getContext()) {
            case \Shop::CONTEXT_SHOP:
                return self::SCOPE_SHOP;

            case \Shop::CONTEXT_GROUP:
                return self::SCOPE_GROUP;

            default:
                return self::SCOPE_ALL;
        }
    }

    public function webhookEditable(): bool
    {
        return in_array($this->scope(), [self::SCOPE_SINGLE, self::SCOPE_SHOP], true);
    }

    /**
     * Whether the current shop uses the shared credentials (no value of its
     * own). Only meaningful in shop scope.
     */
    public function usesSharedCredentials(): bool
    {
        return [] === $this->shopsWithOwnCredentials([(int) \Shop::getContextShopID()]);
    }

    /**
     * Names of the shops of the context, and of the shop groups in "all
     * shops" scope, whose own credentials take precedence over the ones
     * being edited.
     *
     * @return array<int, string>
     */
    public function credentialOverrides(): array
    {
        $names = [];
        $scope = $this->scope();

        if (self::SCOPE_ALL === $scope) {
            $groupIds = array_map('intval', array_column(\Db::getInstance()->executeS(
                'SELECT DISTINCT id_shop_group FROM '._DB_PREFIX_.'configuration
                WHERE name IN ('.$this->keysSql(self::CREDENTIAL_KEYS).')
                AND id_shop IS NULL AND id_shop_group IS NOT NULL'
            ) ?: [], 'id_shop_group'));
            foreach ($groupIds as $idGroup) {
                $group = new \ShopGroup($idGroup);
                if (\Validate::isLoadedObject($group)) {
                    $names[] = sprintf('%s (groupe)', $group->name);
                }
            }
        }

        if (in_array($scope, [self::SCOPE_ALL, self::SCOPE_GROUP], true)) {
            foreach ($this->shopsWithOwnCredentials(array_map('intval', \Shop::getContextListShopID())) as $idShop) {
                $shop = new \Shop($idShop);
                if (\Validate::isLoadedObject($shop)) {
                    $names[] = $shop->name;
                }
            }
        }

        return $names;
    }

    /**
     * Values shown in the form: the ones that apply in the context.
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $values = [];
        foreach (array_merge(self::CREDENTIAL_KEYS, self::WEBHOOK_KEYS) as $key) {
            $values[$key] = \Configuration::get($key);
        }

        return $values;
    }

    /**
     * Save the submitted settings at the level of the context. Nothing is
     * saved when an error is returned.
     *
     * @param array<string, string> $values               submitted values, by configuration key
     * @param bool                  $useSharedCredentials shop scope: drop the shop's own credentials
     *
     * @return array<string> errors (ERROR_* constants), empty when saved
     */
    public function save(array $values, bool $useSharedCredentials = false): array
    {
        $scope = $this->scope();

        // An empty API key keeps the current one, which for a shop switching
        // to its own credentials is the shared key: its "own" credentials
        // would silently mix its client ID with the shared key
        if (self::SCOPE_SHOP === $scope && !$useSharedCredentials
            && '' === trim((string) ($values['PRICEE_API_KEY'] ?? ''))
            && !$this->shopHasOwnValue('PRICEE_API_KEY', (int) \Shop::getContextShopID())) {
            return [self::ERROR_API_KEY_REQUIRED];
        }

        if (self::SCOPE_SHOP === $scope && $useSharedCredentials) {
            foreach (self::CREDENTIAL_KEYS as $key) {
                \Configuration::deleteFromContext($key);
            }
        } else {
            foreach (self::CREDENTIAL_KEYS as $key) {
                $value = trim((string) ($values[$key] ?? ''));

                // Password inputs are not repopulated by the form: an empty
                // submitted value means "keep the current one", not "erase it"
                if ('' === $value && 'PRICEE_API_KEY' === $key) {
                    continue;
                }

                \Configuration::updateValue($key, $value);
            }
        }

        // Webhook settings belong to one shop, never to all shops or a group
        if ($this->webhookEditable()) {
            foreach (self::WEBHOOK_KEYS as $key) {
                \Configuration::updateValue($key, trim((string) ($values[$key] ?? '')));
            }
        }

        return [];
    }

    private function shopHasOwnValue(string $key, int $idShop): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM '._DB_PREFIX_.'configuration
            WHERE name = \''.pSQL($key).'\' AND id_shop = '.$idShop
        );
    }

    /**
     * @param array<int> $shopIds
     *
     * @return array<int> the ones with a credential value of their own
     */
    private function shopsWithOwnCredentials(array $shopIds): array
    {
        if ([] === $shopIds) {
            return [];
        }

        return array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT DISTINCT id_shop FROM '._DB_PREFIX_.'configuration
            WHERE name IN ('.$this->keysSql(self::CREDENTIAL_KEYS).')
            AND id_shop IN ('.implode(',', array_map('intval', $shopIds)).')
            ORDER BY id_shop'
        ) ?: [], 'id_shop'));
    }

    /**
     * @param array<string> $keys
     */
    private function keysSql(array $keys): string
    {
        return implode(',', array_map(fn (string $key) => "'".pSQL($key)."'", $keys));
    }
}
