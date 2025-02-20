{**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<div class="card">
    <div class="card-header">
        <h3>{l s='Invoice #%s' sprintf=[ $number ] d='Modules.Fattureincloud.Admin'}</h3>
    </div>
    <div class="card-body">
        <p>{l s='For this order FattureInCloud has generate invoice  #%s' sprintf=[$number] d='Modules.Fattureincloud.Admin'}</p>

        <div class="text-right">
            <a class="btn btn-primary" href="{$edit_url}" target="_blank">{l s='Edit Invoice' d='Modules.Fattureincloud.Admin'}</a>
            <a class="btn btn-outline-secondary" href="{$download_url}" target="_blank">{l s='Download Invoice' d='Modules.Fattureincloud.Admin'}</a>
        </div>
    </div>
</div>