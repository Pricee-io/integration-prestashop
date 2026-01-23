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

        <input name="id_lang" type="hidden" value="{$id_lang}">

        {foreach from=$categories item=category}
            <div class="form-check">
                <input class="form-check-input" type="checkbox"
                    name="categories[]"
                    value="{$category.id_category}"
                    id="category-{$category.id_category}">
                <label class="form-check-label" for="category-{$category.id_category}">
                    {$category.name} ({$category.product_count})
                </label>
            </div>
        {/foreach}

        <button type="submit" class="btn btn-primary mt-4">{l s='Synchroniser les produits' mod='pricee'}</button>
    </form>
</div>

<script>
document.getElementById('pricee-sync-form').addEventListener('submit', function(e) {
    e.preventDefault();

    var form = e.target;
    var result = document.getElementById('pricee-sync-result');
    var loading = document.getElementById('pricee-sync-loading');

    // show loading
    loading.classList.remove('d-none');
    result.classList.add('d-none');

    fetch('{$ajax_sync_link}', {
        method: 'POST',
        body: new FormData(form),
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        loading.classList.add('d-none');
        result.classList.remove('d-none');

        if (data.success) {
            result.className = 'alert alert-success mt-2';
            result.innerText = data.synced + ' ' + '{l s="produits synchronisés" mod="pricee"}';
        } else {
            result.className = 'alert alert-danger mt-2';
            result.innerText = '{l s="Erreur lors de la synchronisation" mod="pricee"}';
        }
    })
    .catch(() => {
        loading.classList.add('d-none');
        result.classList.remove('d-none');
        result.className = 'alert alert-danger mt-2';
        result.innerText = '{l s="Erreur lors de la synchronisation" mod="pricee"}';
    });
});
</script>
