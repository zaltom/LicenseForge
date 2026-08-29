{*
    Audit log - who did what, and what the API refused.

    Variables
      $logs        object[]  Entries for the current page.
      $actions     array     Distinct action names, for the filter.
      $filters     array     search, action, result, actor_type, license_id.
      $paging      array     total, from, to, page, pages.
      $moduleLink  string    Base addon URL.
      $L           array     Translated strings, keyed logs_*.

    The filter form is GET so the resulting view has a shareable URL, and posts
    to addonmodules.php directly with module and page as hidden inputs: a GET
    form cannot carry the query string already present in $moduleLink.

    $logsQuery carries the active filters onto the paging links; a filter left
    out of it is dropped the moment an admin turns the page.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-card">
  <div class="lfg-card-body">
    <form method="get" action="addonmodules.php" class="form-inline">
      <input type="hidden" name="module" value="licenseforge">
      <input type="hidden" name="page" value="logs">
      <input type="text" name="search" class="lfg-grow form-control" placeholder="{$L.logs_search_actor_or_metadata|escape}" value="{$filters.search|escape}">
      <select name="action_filter" class="form-control">
        <option value="">{$L.logs_all_actions|escape}</option>
        {foreach from=$actions item=action}
          <option value="{$action|escape}"{if $filters.action eq $action} selected{/if}>{$action|escape}</option>
        {/foreach}
      </select>
      <select name="result" class="form-control">
        <option value="">{$L.logs_any_result|escape}</option>
        <option value="success"{if $filters.result eq 'success'} selected{/if}>{$L.logs_success|escape}</option>
        <option value="failure"{if $filters.result eq 'failure'} selected{/if}>{$L.logs_failure|escape}</option>
        <option value="denied"{if $filters.result eq 'denied'} selected{/if}>{$L.logs_denied|escape}</option>
      </select>
      <select name="actor_type" class="form-control">
        <option value="">{$L.logs_any_actor|escape}</option>
        <option value="admin"{if $filters.actor_type eq 'admin'} selected{/if}>{$L.logs_admin|escape}</option>
        <option value="client"{if $filters.actor_type eq 'client'} selected{/if}>{$L.logs_client|escape}</option>
        <option value="api"{if $filters.actor_type eq 'api'} selected{/if}>{$L.logs_api|escape}</option>
        <option value="system"{if $filters.actor_type eq 'system'} selected{/if}>{$L.logs_system|escape}</option>
      </select>
      <input type="number" name="license_id" class="lfg-w120 form-control" placeholder="{$L.logs_license_id|escape}" value="{if $filters.license_id}{$filters.license_id|escape}{/if}">
      <button class="btn btn-primary">{$L.logs_filter|escape}</button>
      <a class="btn btn-default" href="{$moduleLink|escape}&amp;page=logs">{$L.logs_reset|escape}</a>
    </form>
  </div>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$paging.total|escape} audit entries</strong> <span class="lfg-muted">showing {$paging.from|escape}–{$paging.to|escape}</span></div>
  <table class="lfg-table">
    <thead><tr><th class="lfg-w150">{$L.logs_when_utc|escape}</th><th>{$L.logs_action|escape}</th><th>{$L.logs_result|escape}</th><th>{$L.logs_actor|escape}</th><th>{$L.logs_license|escape}</th><th>{$L.logs_ip|escape}</th><th>{$L.logs_metadata|escape}</th></tr></thead>
    <tbody>
    {foreach from=$logs item=entry}
      <tr>
        <td class="lfg-muted">{$entry->created_at|escape}</td>
        <td>{$entry->action|escape}</td>

        <td>
          {if $entry->result eq 'success'}<span class="lfg-pill lfg-pill--ok">success</span>
          {elseif $entry->result eq 'denied'}<span class="lfg-pill lfg-pill--warn">denied</span>
          {else}<span class="lfg-pill lfg-pill--bad">{$entry->result|escape}</span>{/if}
        </td>
        <td>{$entry->actor_type|escape}{if $entry->actor_name && $entry->actor_name neq $entry->actor_type}<br><span class="lfg-muted">{$entry->actor_name|escape}</span>{/if}</td>
        <td>{if $entry->license_id}<a href="{$moduleLink|escape}&amp;page=license&amp;id={$entry->license_id|escape}">#{$entry->license_id|escape}</a>{else}-{/if}</td>
        <td class="lfg-muted">{$entry->ip_address|escape|default:'-'}</td>

        <td class="lfg-mw420 lfg-break lfg-key lfg-muted">{$entry->metadata|escape|truncate:220:"…"}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="lfg-muted">{$L.logs_no_entries_match_your_filters|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>

  {* One line: a multi-line {capture} would put newlines inside the href. *}
  {capture assign="logsQuery"}&amp;search={$filters.search|escape:'url'}&amp;action_filter={$filters.action|escape:'url'}&amp;result={$filters.result|escape:'url'}&amp;actor_type={$filters.actor_type|escape:'url'}{if $filters.license_id}&amp;license_id={$filters.license_id|escape:'url'}{/if}{/capture}
  {if $paging.pages > 1}
  <div class="panel-footer">
    {if $paging.page > 1}<a class="btn btn-default btn-sm" href="{$moduleLink|escape}&amp;page=logs&amp;p={$paging.page-1}{$logsQuery}">{$L.logs_previous|escape}</a>{/if}
    <span class="lfg-mx-sm lfg-muted">Page {$paging.page|escape} of {$paging.pages|escape}</span>
    {if $paging.page < $paging.pages}<a class="btn btn-default btn-sm" href="{$moduleLink|escape}&amp;page=logs&amp;p={$paging.page+1}{$logsQuery}">{$L.logs_next|escape}</a>{/if}
  </div>
  {/if}
</div>

</div>
