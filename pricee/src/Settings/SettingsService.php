<?php

declare(strict_types=1);

namespace PriceeIO\Settings;

/**
 * Module settings and the level they are saved at in multistore (see
 * docs/behavior.md, "Configuration block"):
 *
 * - Pricee credentials and webhook settings: per shop only, as each shop is
 *   its own website on Pricee, with its own API key (an API key is scoped to
 *   one website) and its own webhook. They are not editable for all shops or
 *   a group.
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

    /**
     * Whether the settings can be edited in the context: for one shop (or
     * without multistore), never for all shops or a group.
     */
    public function editable(): bool
    {
        return in_array($this->scope(), [self::SCOPE_SINGLE, self::SCOPE_SHOP], true);
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
     * Save the submitted settings for the shop of the context. Nothing is
     * saved for all shops or a group.
     *
     * @param array<string, string> $values submitted values, by configuration key
     */
    public function save(array $values): void
    {
        if (!$this->editable()) {
            return;
        }

        foreach (array_merge(self::CREDENTIAL_KEYS, self::WEBHOOK_KEYS) as $key) {
            $value = trim((string) ($values[$key] ?? ''));

            // Password inputs are not repopulated by the form: an empty
            // submitted value means "keep the current one", not "erase it"
            if ('' === $value && 'PRICEE_API_KEY' === $key) {
                continue;
            }

            \Configuration::updateValue($key, $value);
        }
    }

    /**
     * Upgrade to per-shop credentials: in multistore, the credentials set for
     * all shops or a group are copied to every shop without its own, then
     * removed, so each shop keeps the credentials it used.
     */
    public function moveSharedCredentialsToShops(): void
    {
        if (!\Shop::isFeatureActive()) {
            return;
        }

        foreach (\Shop::getShops(false, null, true) as $idShop) {
            $idShop = (int) $idShop;
            $idShopGroup = (int) \Shop::getGroupFromShop($idShop);
            foreach (self::CREDENTIAL_KEYS as $key) {
                if ($this->shopHasOwnValue($key, $idShop)) {
                    continue;
                }

                $value = \Configuration::get($key, null, $idShopGroup, $idShop);
                if (false !== $value && '' !== $value) {
                    \Configuration::updateValue($key, (string) $value, false, $idShopGroup, $idShop);
                }
            }
        }

        \Db::getInstance()->delete('configuration', 'name IN ('.$this->keysSql(self::CREDENTIAL_KEYS).') AND id_shop IS NULL');
        \Configuration::loadConfiguration();
    }

    private function shopHasOwnValue(string $key, int $idShop): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM '._DB_PREFIX_.'configuration
            WHERE name = \''.pSQL($key).'\' AND id_shop = '.$idShop
        );
    }

    /**
     * @param array<string> $keys
     */
    private function keysSql(array $keys): string
    {
        return implode(',', array_map(fn (string $key) => "'".pSQL($key)."'", $keys));
    }
}
