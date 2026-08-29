{*
    Resets - reissue requests awaiting approval, and what has already happened.

    The pending table is empty whenever approval is not required in Settings.
    Completed reissues are history and are not editable from the UI.

    Variables
      $pending     object[]  Requests awaiting approval.
      $reissues    object[]  Completed and rejected reissues.
      $moduleLink  string    Base addon URL.
      $csrfToken   string    Submitted as lfg_token with both forms.
      $L           array     Translated strings, keyed reis_*.

    Posts
      do=reissue.approve  Approves and performs the reissue.
      do=reissue.reject   Declines, recording the typed reason.

    Approving confirms and rejecting does not: approval changes what the
    customer's software is using, while a rejection is recoverable - they can
    ask again. The reject reason is typed inline rather than on a second screen,
    so declining stays a single action.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.reis_pending_approval|escape}</strong></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.reis_requested|escape}</th><th>{$L.reis_license|escape}</th><th>{$L.reis_from|escape}</th><th>{$L.reis_to|escape}</th><th>{$L.reis_reason|escape}</th><th>{$L.reis_by|escape}</th><th></th></tr></thead>
    <tbody>
    {foreach from=$pending item=request}
      <tr>
        <td class="lfg-muted">{$request->created_at|escape}</td>
        <td><a href="{$moduleLink|escape}&amp;page=license&amp;id={$request->license_id|escape}">#{$request->license_id|escape}</a></td>
        <td>{$request->old_domain|escape|default:'-'}</td>
        <td>{$request->new_domain|escape|default:'-'}</td>
        <td>{$request->reason|escape}</td>
        <td>{$request->initiated_by|escape}{if $request->initiator_id} #{$request->initiator_id|escape}{/if}</td>
        <td>

          <form method="post" action="{$moduleLink|escape}&amp;page=reissues" data-lf-confirm="{$L.reis_approve_this_reissue_current_installations_will|escape}" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="reissue.approve">
            <input type="hidden" name="request_id" value="{$request->id|escape}">
            <button class="btn btn-xs btn-primary">{$L.reis_approve|escape}</button>
          </form>
          <form method="post" action="{$moduleLink|escape}&amp;page=reissues" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="reissue.reject">
            <input type="hidden" name="request_id" value="{$request->id|escape}">
            <input type="text" name="reason" class="lfg-w150 lfg-ib form-control input-sm" placeholder="{$L.reis_reason_2|escape}">
            <button class="btn btn-xs lfg-btn--caution">{$L.reis_reject|escape}</button>
          </form>
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="lfg-muted">{$L.reis_no_requests_awaiting_approval|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.reis_recent_reissues|escape}</strong></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.reis_when|escape}</th><th>{$L.reis_license_2|escape}</th><th>{$L.reis_status|escape}</th><th>{$L.reis_old_key|escape}</th><th>{$L.reis_new_key|escape}</th><th>{$L.reis_from_2|escape}</th><th>{$L.reis_to_2|escape}</th><th>{$L.reis_by_2|escape}</th><th>{$L.reis_ip|escape}</th></tr></thead>
    <tbody>
    {foreach from=$recent item=reissue}
      <tr>
        <td class="lfg-muted">{$reissue->created_at|escape}</td>
        <td><a href="{$moduleLink|escape}&amp;page=license&amp;id={$reissue->license_id|escape}">#{$reissue->license_id|escape}</a></td>
        <td>{$reissue->status|escape}</td>
        <td class="lfg-key">{$reissue->old_key|escape|default:'-'}</td>

        <td class="lfg-key">{if $reissue->new_key_hash neq $reissue->old_key_hash}{$reissue->new_key|escape}{else}<span class="lfg-muted">unchanged</span>{/if}</td>
        <td>{$reissue->old_domain|escape|default:'-'}</td>
        <td>{$reissue->new_domain|escape|default:'-'}</td>
        <td>{$reissue->initiated_by|escape}</td>
        <td class="lfg-muted">{$reissue->ip_address|escape|default:'-'}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="9" class="lfg-muted">{$L.reis_no_reissues_recorded|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

</div>
