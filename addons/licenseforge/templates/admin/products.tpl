{*
    Products - which WHMCS products are licensed, and their release files.

    The licensed-products table is read-only: every value lives on the product's
    own Module Settings tab in WHMCS, and the action column links out to it.
    This page reports what those settings currently resolve to.

    Variables
      $products     array[]  Licensed products, display-ready.
      $retired      array[]  Products that no longer use the module.
      $stale        array[]  Products whose stored settings need re-saving.
      $releaseDir   string   Configured release directory; blank disables downloads.
      $releases     array    product id => release rows.
      $newProduct   string   URL of WHMCS's create-product screen.
      $moduleLink   string   Base addon URL.
      $csrfToken    string   Submitted as lfg_token with the release forms.
      $L            array    Translated strings, keyed prod_* and rel_*.

    Posts
      do=release.add     Registers a release file by path.
      do=release.delete  Removes a release record.
      do=release.verify  Re-checks that a release file is still present.

    Release files are referenced by path inside the configured directory, not
    uploaded: archives are large, and a path reference lets whatever build or
    deploy process already produces them place them. Without a release directory
    the whole section collapses to a link into Settings rather than showing
    forms that would fail.
*}
<div class="lfg-console">

{include file="nav.tpl"}

{if $needResave}
<div class="alert alert-danger">
  <strong>{$needResave|@count} product{if $needResave|@count != 1}s{/if} {if $needResave|@count == 1}needs{else}need{/if} their Module Settings re-saved.</strong>
  <p class="lfg-my-sm">{$L.prod_the_licensing_options_changed_order_in|escape}</p>
  <p class="lfg-my-sm">{$L.prod_open_each_one_check_the_values|escape}</p>
  <table class="lfg-bare table lfg-table">
    <thead><tr><th>{$L.prod_product|escape}</th><th>{$L.prod_license_term_currently_holds|escape}</th><th></th></tr></thead>
    <tbody>
    {foreach from=$needResave item=stale}
      <tr>
        <td>{$stale.name|escape} <span class="lfg-muted">#{$stale.id|escape}</span></td>
        <td><span class="lfg-key">{$stale.found|escape}</span> <span class="lfg-muted">{$L.prod_the_old_duration_in_days|escape}</span></td>
        <td><a class="btn btn-xs btn-primary" href="{$stale.editUrl|escape}">{$L.prod_fix_now|escape}</a></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
</div>
{/if}

<div class="alert alert-info">
  {$L.prod_how_it_works_html}</div>

<div class="lfg-card">
  <div class="panel-heading">
    <strong>{$L.prod_licensed_products|escape}</strong>
    <a href="{$newProduct|escape}" class="btn btn-primary btn-xs pull-right">{$L.prod_create_a_product|escape}</a>
  </div>
  <table class="lfg-table">
    <thead>
      <tr>
        <th>{$L.prod_product_2|escape}</th><th>{$L.prod_slug|escape}</th><th>{$L.prod_term|escape}</th><th>{$L.prod_activations|escape}</th><th>{$L.prod_reissues|escape}</th>
        <th>{$L.prod_grace|escape}</th><th>{$L.prod_locks|escape}</th><th>{$L.prod_latest|escape}</th><th>{$L.prod_features|escape}</th><th>{$L.prod_licenses|escape}</th><th></th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$products item=product}
      <tr>
        <td>{$product.name|escape} <span class="lfg-muted">#{$product.whmcsId|escape}</span></td>
        <td class="lfg-key">{$product.slug|escape}</td>
        <td>{$product.duration|escape}</td>
        <td>{$product.activations|escape}</td>
        <td>{$product.reissues|escape}</td>
        <td>{$product.grace|escape}</td>
        <td class="lfg-muted">{$product.locks|escape}</td>
        <td>{if $product.latest}{$product.latest|escape}{else}<span class="lfg-muted">-</span>{/if}</td>
        <td class="lfg-muted">{if $product.features}{$product.features|escape}{else}-{/if}</td>
        <td><a href="{$moduleLink|escape}&amp;page=licenses&amp;product={$product.id|escape}">{$product.licenses|escape}</a></td>
        <td><a class="btn btn-xs lfg-btn--route" href="{$product.editUrl|escape}">Module Settings</a></td>
      </tr>
    {foreachelse}
      <tr><td colspan="11" class="lfg-muted">{$L.prod_no_product_uses_the_license_forge|escape}<em>License Forge</em>{$L.prod_and_configure_the_licensing_rules_on|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.rel_heading|escape}</strong><span class="lfg-muted">{$L.rel_subheading|escape}</span></div>
  <div class="lfg-card-body">
    {if !$releaseDir}
      <div class="alert alert-warning lfg-my-sm">{$L.rel_no_dir|escape}
        <a href="{$moduleLink|escape}&amp;page=settings">{$L.rel_set_it|escape}</a>
      </div>
    {else}
      <p class="lfg-muted">{$L.rel_dir_is|escape} <code>{$releaseDir|escape}</code></p>

      {foreach from=$products item=product}
        <div class="lfg-mt-sm">
          <strong>{$product.name|escape}</strong>
          {if $product.releases}
            <table class="lfg-bare table lfg-table">
              <thead><tr><th>{$L.rel_label|escape}</th><th>{$L.rel_version|escape}</th><th>{$L.rel_file|escape}</th><th>{$L.rel_size|escape}</th><th>{$L.rel_downloads|escape}</th><th></th></tr></thead>
              <tbody>
              {foreach from=$product.releases item=release}
                <tr>
                  <td>{$release.label|escape}</td>
                  <td>{$release.version|escape|default:'-'}</td>

                  <td class="lfg-key">{$release.path|escape}
                    {if !$release.readable}<span class="lfg-pill lfg-pill--bad">{$L.rel_missing|escape}</span>{/if}
                  </td>
                  <td>{$release.size|escape}</td>
                  <td>{$release.downloads|escape}</td>
                  <td>
                    <form method="post" class="lfg-inline" data-lf-confirm="{$L.rel_remove_confirm|escape}">
                      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
                      <input type="hidden" name="do" value="release.delete">
                      <input type="hidden" name="release_id" value="{$release.id|escape}">
                      <button class="btn btn-xs lfg-btn--caution">{$L.rel_remove|escape}</button>
                    </form>
                  </td>
                </tr>
              {/foreach}
              </tbody>
            </table>

            <form method="post" class="lfg-inline">
              <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
              <input type="hidden" name="do" value="release.verify">
              <button class="btn btn-xs btn-default">{$L.rel_verify|escape}</button>
              <span class="lfg-muted">{$L.rel_verify_help|escape}</span>
            </form>
          {else}
            <span class="lfg-muted">{$L.rel_none|escape}</span>
          {/if}

          <form method="post" class="form-inline lfg-my-sm">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="release.create">
            <input type="hidden" name="product_id" value="{$product.id|escape}">
            <input type="text" name="label" class="form-control input-sm" placeholder="{$L.rel_label|escape}" required>
            <input type="text" name="version" class="lfg-w110 form-control input-sm" placeholder="{$L.rel_version|escape}">
            <input type="text" name="file_path" class="lfg-w260 form-control input-sm" placeholder="{$L.rel_file_placeholder|escape}" required>
            <button class="btn btn-sm btn-primary">{$L.rel_add|escape}</button>
          </form>
        </div>
      {/foreach}
    {/if}
  </div>
</div>

{if $retired}
<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.prod_no_longer_licensed|escape}</strong></div>
  <table class="lfg-table">
    <thead>
      <tr><th>{$L.prod_product_3|escape}</th><th>{$L.prod_slug_2|escape}</th><th>{$L.prod_whmcs_product|escape}</th><th>{$L.prod_licenses_2|escape}</th></tr>
    </thead>
    <tbody>
    {foreach from=$retired item=product}
      <tr>
        <td>{$product.name|escape}</td>
        <td class="lfg-key">{$product.slug|escape}</td>
        <td>#{$product.whmcsId|escape}</td>
        <td><a href="{$moduleLink|escape}&amp;page=licenses&amp;product={$product.id|escape}">{$product.licenses|escape}</a></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <div class="panel-footer lfg-muted">{$L.prod_these_products_no_longer_use_the|escape}</div>
</div>
{/if}

</div>
