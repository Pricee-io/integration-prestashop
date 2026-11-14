{*
* 2007-2026 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2026 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="panel">
    <h3><i class="icon icon-refresh"></i> {l s='Synchronisation' mod='pricee'}</h3>

    <form id="pricee-sync-form" method="post">
        <h4>{l s='Synchroniser les produits par catégorie' mod='pricee'}</h4>
        <hr>

        <div id="pricee-sync-loading" class="d-none mt-2 mb-4 text-muted">
            <i class="icon-refresh icon-spin"></i> {l s='Chargement...' mod='pricee'}
        </div>
        <div id="pricee-sync-result" class="mt-2 mb-4"></div>

        <input type="hidden" name="sync_token" value="{$sync_token}">

        {if $shops|@count > 1}
            <div class="form-group">
                <label for="pricee-sync-shop">{l s='Boutique à synchroniser' mod='pricee'}</label>
                <select id="pricee-sync-shop" name="id_shop" class="form-control">
                    {foreach from=$shops item=shop}
                        <option value="{$shop.id_shop}"{if $shop.id_shop == $current_shop_id} selected{/if}>{$shop.name|escape:'html':'UTF-8'}</option>
                    {/foreach}
                </select>
            </div>
        {else}
            <input type="hidden" name="id_shop" value="{$current_shop_id}">
        {/if}

        {foreach from=$shops item=shop}
            <fieldset class="pricee-sync-categories{if $shop.id_shop != $current_shop_id} d-none{/if}" data-shop="{$shop.id_shop}"{if $shop.id_shop != $current_shop_id} disabled{/if}>
                {foreach from=$shop.categories item=category}
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                            name="categories[]"
                            value="{$category.id_category}"
                            id="category-{$shop.id_shop}-{$category.id_category}">
                        <label class="form-check-label{if !$category.active} text-muted{/if}" for="category-{$shop.id_shop}-{$category.id_category}">
                            {$category.name|escape:'html':'UTF-8'} ({$category.product_count}){if !$category.active} — {l s='désactivée' mod='pricee'}{/if}
                        </label>
                    </div>
                {/foreach}
            </fieldset>
        {/foreach}

        <button type="submit" class="btn btn-primary mt-4">{l s='Synchroniser les produits' mod='pricee'}</button>
    </form>
</div>

<script>
// Only the categories of the selected shop are shown and submitted. The
// select is read on load too: browsers can restore its previous value on
// reload, which would otherwise show another shop's categories.
var priceeShopSelect = document.getElementById('pricee-sync-shop');
if (priceeShopSelect) {
    var priceeShowShopCategories = function() {
        document.querySelectorAll('.pricee-sync-categories').forEach(function(fieldset) {
            var selected = fieldset.dataset.shop === priceeShopSelect.value;
            fieldset.classList.toggle('d-none', !selected);
            fieldset.disabled = !selected;
        });
    };
    priceeShopSelect.addEventListener('change', priceeShowShopCategories);
    priceeShowShopCategories();
}

// The sync runs in batches, one request each, so a large selection never
// hits a timeout: each answer gives the cursor for the next batch.
document.getElementById('pricee-sync-form').addEventListener('submit', function(e) {
    e.preventDefault();

    var form = e.target;
    var button = form.querySelector('button[type="submit"]');
    var result = document.getElementById('pricee-sync-result');
    var loading = document.getElementById('pricee-sync-loading');
    var synced = 0;
    var failed = 0;

    var show = function(type, message) {
        result.className = 'alert alert-' + type + ' mt-2';
        result.innerText = message;
    };

    var finish = function() {
        loading.classList.add('d-none');
        button.disabled = false;
    };

    var syncedMessage = function() {
        var message = synced + ' ' + '{l s='produits synchronisés' js=1 mod='pricee'}';
        if (failed > 0) {
            message += ', ' + failed + ' ' + '{l s='en erreur (voir les logs PrestaShop)' js=1 mod='pricee'}';
        }

        return message;
    };

    var fail = function(error) {
        finish();
        var message = '{l s='Erreur lors de la synchronisation' js=1 mod='pricee'}';
        if (error) {
            message += ' : ' + error;
        }
        if (synced > 0 || failed > 0) {
            message += ' (' + syncedMessage() + ' {l s='avant l\'erreur' js=1 mod='pricee'})';
        }
        show('danger', message);
    };

    if (!form.querySelector('.pricee-sync-categories:not([disabled]) input[name="categories[]"]:checked')) {
        show('warning', '{l s='Sélectionnez au moins une catégorie.' js=1 mod='pricee'}');

        return;
    }

    button.disabled = true;
    loading.classList.remove('d-none');
    result.className = 'd-none';

    var syncBatch = function(afterId) {
        var body = new FormData(form);
        body.append('after_id', afterId);

        fetch('{$ajax_sync_link}', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    fail(data.error);

                    return;
                }

                synced += data.synced;
                failed += data.failed;

                if (data.done) {
                    finish();
                    show(failed > 0 ? 'warning' : 'success', syncedMessage());

                    return;
                }

                show('info', '{l s='Synchronisation en cours :' js=1 mod='pricee'} ' + (synced + failed) + ' / ' + data.total);
                syncBatch(data.last_id);
            })
            .catch(function() {
                fail('');
            });
    };

    syncBatch(0);
});
</script>
