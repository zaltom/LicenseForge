{*
    Licences - search, list, bulk actions, and manual issue.

    Three forms: the filter (GET, so a narrowed view is bookmarkable), the table
    with its bulk actions, and the manual-issue form.

    Variables
      $licenses      array[]  Display-ready rows.
      $statusLabels  array    value => label, for the status filter.
      $products      object[] For the product filter.
      $filters       array    search, status, product - the active narrowing.
      $sort, $dir    string   Active ordering; $dir is always 'asc' or 'desc'.
      $paging        array    total, from, to, page, pages.
      $issuable      object[] Services eligible for a manual licence.
      $moduleLink    string   Base addon URL.
      $csrfToken     string   Submitted as lfg_token with both post forms.
      $L             array    Translated strings, keyed lic_*.

    Posts
      do=license.bulk    Bulk action over license_ids[].
      do=license.issue   Issues a licence against an existing service.

    $licQuery and $licSort capture the active filters and ordering for the links
    in the table. Anything omitted from them is silently discarded the moment an
    admin sorts or pages, which widens the result set without saying so - add
    any new filter to $licQuery as well as to the form.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.lic_search_filter|escape}</strong></div>
  <div class="lfg-card-body">
    <form method="get" action="addonmodules.php" class="form-inline">
      <input type="hidden" name="module" value="licenseforge">
      <input type="hidden" name="page" value="licenses">
      <input type="text" class="lfg-grow form-control" name="search" placeholder="{$L.lic_key_domain_ip_client_email_service|escape}" value="{$filters.search|escape}">
      <select name="status" class="form-control">
        <option value="">{$L.lic_all_statuses|escape}</option>
        {foreach from=$statusLabels key=value item=label}
          <option value="{$value|escape}"{if $filters.status eq $value} selected{/if}>{$label|escape}</option>
        {/foreach}
      </select>
      <select name="product" class="form-control">
        <option value="0">{$L.lic_all_products|escape}</option>
        {foreach from=$products item=product}
          <option value="{$product->id|escape}"{if $filters.product eq $product->id} selected{/if}>{$product->name|escape}</option>
        {/foreach}
      </select>
      <button type="submit" class="btn btn-primary">{$L.lic_search|escape}</button>
      <a href="{$moduleLink|escape}&amp;page=licenses" class="btn btn-default">{$L.lic_reset|escape}</a>
    </form>
  </div>
</div>

{* Captured on one line each: a multi-line {capture} takes the newlines into
   the value and puts them inside the href. *}
{capture assign="licQuery"}&amp;search={$filters.search|escape:'url'}&amp;status={$filters.status|escape:'url'}{if $filters.product}&amp;product={$filters.product|escape:'url'}{/if}{/capture}
{capture assign="licSort"}{if $sort}&amp;sort={$sort|escape:'url'}&amp;dir={$dir|escape:'url'}{/if}{/capture}

<form method="post" action="{$moduleLink|escape}&amp;page=licenses" id="lfgBulkForm">
<input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
<input type="hidden" name="do" value="license.bulk">

<div class="lfg-card">
  <div class="panel-heading">
    <strong>{$paging.total|escape} license{if $paging.total != 1}s{/if}</strong>
    <span class="lfg-muted">showing {$paging.from|escape}–{$paging.to|escape}</span>
  </div>
  <table class="lfg-table">
    <thead>
      <tr>

        <th class="lfg-w24"><input type="checkbox" data-lf-toggle-all=".lfg-check"></th>

        <th><a class="lfg-sort{if $sort eq 'license_key'} is-{$dir|escape}{/if}" href="{$moduleLink|escape}&amp;page=licenses&amp;sort=license_key&amp;dir={if $sort eq 'license_key' and $dir eq 'asc'}desc{else}asc{/if}{$licQuery}">{$L.lic_key|escape}</a></th>
        <th>{$L.lic_product|escape}</th>
        <th>{$L.lic_client|escape}</th>
        <th><a class="lfg-sort{if $sort eq 'status'} is-{$dir|escape}{/if}" href="{$moduleLink|escape}&amp;page=licenses&amp;sort=status&amp;dir={if $sort eq 'status' and $dir eq 'asc'}desc{else}asc{/if}{$licQuery}">{$L.lic_status|escape}</a></th>
        <th>{$L.lic_domain|escape}</th>
        <th>{$L.lic_activations|escape}</th>
        <th><a class="lfg-sort{if $sort eq 'expires_at'} is-{$dir|escape}{/if}" href="{$moduleLink|escape}&amp;page=licenses&amp;sort=expires_at&amp;dir={if $sort eq 'expires_at' and $dir eq 'asc'}desc{else}asc{/if}{$licQuery}">{$L.lic_expires|escape}</a></th>
        <th><a class="lfg-sort{if $sort eq 'last_validated_at'} is-{$dir|escape}{/if}" href="{$moduleLink|escape}&amp;page=licenses&amp;sort=last_validated_at&amp;dir={if $sort eq 'last_validated_at' and $dir eq 'asc'}desc{else}asc{/if}{$licQuery}">{$L.lic_last_check|escape}</a></th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$licenses item=item}
      <tr{if $item.flagged} class="warning"{/if}>
        <td><input type="checkbox" class="lfg-check" name="license_ids[]" value="{$item.id|escape}"></td>
        <td>
          <a class="lfg-key" href="{$moduleLink|escape}&amp;page=license&amp;id={$item.id|escape}">{$item.key|escape}</a>
          {if $item.flagged}<span class="lfg-pill lfg-pill--warn">flagged</span>{/if}
          {if $item.isTrial}<span class="lfg-pill lfg-pill--wait">trial</span>{/if}
        </td>
        <td>{$item.product|escape}</td>
        <td>
          {if $item.client.id}<a href="clientssummary.php?userid={$item.client.id|escape}">{$item.client.name|escape}</a><br><span class="lfg-muted">{$item.client.email|escape}</span>{else}-{/if}
        </td>
        <td><span class="lfg-pill lfg-pill--{$item.tone|escape}">{$item.statusLabel|escape}</span></td>
        <td>{if $item.domain}{$item.domain|escape}{else}-{/if}</td>
        <td>{$item.activations|escape}</td>

        <td>
          {if $item.isLifetime}Never
          {elseif $item.expires}{$item.expires|escape}
            {if $item.days !== null}<br><span class="lfg-muted">{if $item.days < 0}{$item.days*-1} days ago{else}in {$item.days|escape} days{/if}</span>{/if}
          {else}-{/if}
        </td>
        <td class="lfg-muted">{if $item.lastCheck}{$item.lastCheck|escape}{else}Never{/if}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="9" class="lfg-muted">{$L.lic_no_licenses_match_your_filters|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>

  <div class="panel-footer">
    <div class="form-inline">
      <select name="bulk_action" class="form-control input-sm">
        <option value="">{$L.lic_bulk_action|escape}</option>
        <option value="activate">{$L.lic_activate|escape}</option>
        <option value="suspend">{$L.lic_suspend|escape}</option>
        <option value="revoke">{$L.lic_revoke|escape}</option>
        <option value="reset">{$L.lic_reset_activations|escape}</option>
        <option value="delete">{$L.lic_delete|escape}</option>
      </select>
      <button type="submit" class="btn btn-default btn-sm" data-lf-confirm="{$L.lic_apply_this_action_to_the_selected|escape}">{$L.lic_apply|escape}</button>

      {if $paging.pages > 1}
        <span class="pull-right">
          {if $paging.page > 1}<a class="btn btn-default btn-sm" href="{$moduleLink|escape}&amp;page=licenses&amp;p={$paging.page-1}{$licQuery}{$licSort}">{$L.lic_previous|escape}</a>{/if}
          <span class="lfg-mx-sm lfg-muted">Page {$paging.page|escape} of {$paging.pages|escape}</span>
          {if $paging.page < $paging.pages}<a class="btn btn-default btn-sm" href="{$moduleLink|escape}&amp;page=licenses&amp;p={$paging.page+1}{$licQuery}{$licSort}">{$L.lic_next|escape}</a>{/if}
        </span>
      {/if}
    </div>
  </div>
</div>
</form>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.lic_issue_a_license_manually|escape}</strong></div>
  <div class="lfg-card-body">
    <form method="post" action="{$moduleLink|escape}&amp;page=licenses" class="form-horizontal">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="license.create">

      <div class="form-group">
        <label class="col-sm-2 control-label">{$L.lic_service|escape}</label>
        <div class="col-sm-10">
          <select name="service_id" class="form-control" required>
            {foreach from=$issuable item=service}
              <option value="{$service.id|escape}">{$service.label|escape}</option>
            {foreachelse}
              <option value="0" disabled>{$L.lic_no_services_are_waiting_for_a|escape}</option>
            {/foreach}
          </select>
          <span class="help-block lfg-muted">{$L.lic_only_services_on_a_product_using|escape}</span>
        </div>
      </div>

      <div class="form-group">
        <label class="col-sm-2 control-label">{$L.lic_expires_2|escape}</label>
        <div class="col-sm-2"><input type="date" name="expires_at" class="form-control"></div>
        <div class="col-sm-3">
          <label class="checkbox-inline"><input type="checkbox" name="is_lifetime" value="1">{$L.lic_lifetime|escape}</label>
        </div>
        <label class="col-sm-2 control-label">{$L.lic_activations_2|escape}</label>
        <div class="col-sm-3"><input type="number" name="max_activations" class="form-control" placeholder="{$L.lic_product_default|escape}" min="0"></div>
      </div>

      <div class="form-group">
        <label class="col-sm-2 control-label">{$L.lic_reissues|escape}</label>
        <div class="col-sm-2"><input type="number" name="max_reissues" class="form-control" placeholder="{$L.lic_product_default_2|escape}" min="0"></div>
        <label class="col-sm-2 control-label">{$L.lic_admin_notes|escape}</label>
        <div class="col-sm-6"><input type="text" name="admin_notes" class="form-control" placeholder="{$L.lic_internal_only_optional|escape}"></div>
      </div>

      <div class="form-group">
        <div class="col-sm-offset-2 col-sm-10">
          <button type="submit" class="btn btn-primary"{if !$issuable} disabled{/if}>{$L.lic_issue_license|escape}</button>
          <span class="help-block lfg-muted">{$L.lic_leave_the_overrides_blank_to_use|escape}</span>
        </div>
      </div>
    </form>
  </div>
</div>

</div>
