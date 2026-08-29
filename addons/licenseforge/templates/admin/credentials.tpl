{*
    API credentials - the keys client SDKs sign their requests with.

    Each credential occupies two rows: a read-only summary, then an inline edit
    form. Editing in place keeps the comparison between credentials visible
    while one is being changed.

    Variables
      $credentials  object[]  Existing credentials, with .is_admin_scoped.
      $apiUrl       string    The licensing endpoint SDKs point at.
      $scopes       array     Available scope names.
      $products     object[]  For the per-credential product restriction.
      $newSecret    string    Set once, immediately after creation.
      $moduleLink   string    Base addon URL.
      $csrfToken    string    Submitted as lfg_token with every form here.
      $L            array     Translated strings, keyed cred_*.

    Posts
      do=credential.create  Creates a credential and reveals its secret once.
      do=credential.update  Saves the inline edit row.
      do=credential.rotate  Issues a new secret; the old one stops working.
      do=credential.delete  Removes the credential.

    A secret is shown once, on creation, and never again - only its hash is
    stored. The admin scope satisfies every scope check, so it is flagged in red
    wherever it appears.

    An unchecked checkbox posts nothing, so the handler must read a missing
    allow_all_products or is_active as "off" rather than as "unchanged", or
    neither could ever be turned back off from this form.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="alert alert-info">
  <strong>{$L.cred_licensing_endpoint|escape}</strong> <code>{$apiUrl|escape}</code><br>
  {$L.cred_signing_help|escape}<br>
  {$L.cred_signing_manual|escape}
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.cred_api_credentials|escape}</strong></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.cred_name|escape}</th><th>{$L.cred_key|escape}</th><th>{$L.cred_scopes|escape}</th><th>{$L.cred_ip_allow_list|escape}</th><th>{$L.cred_products|escape}</th><th>{$L.cred_requests|escape}</th><th>{$L.cred_last_used|escape}</th><th>{$L.cred_status|escape}</th><th></th></tr></thead>
    <tbody>

    {foreach from=$credentials item=credential}
      <tr>
        <td>{$credential->name|escape}</td>
        <td class="lfg-key">{$credential->api_key|escape}</td>

        <td>
          {$credential->scopes|escape}
          {if $credential->is_admin_scoped}
            <br><span class="lfg-pill lfg-pill--bad">{$L.cred_admin_warning|escape}</span>
          {/if}
        </td>
        <td class="lfg-muted">{$credential->allowed_ips|escape|default:'any'}</td>
        <td class="lfg-muted">{if $credential->allow_all_products}<span class="lfg-pill lfg-pill--warn">{$L.cred_all_products|escape}</span>{else}{$credential->allowed_products|escape|default:'none'}{/if}</td>
        <td>{$credential->request_count|escape}</td>
        <td class="lfg-muted">{$credential->last_used_at|escape|default:'never'}{if $credential->last_used_ip}<br>{$credential->last_used_ip|escape}{/if}</td>
        <td>
          {if $credential->is_active}<span class="lfg-pill lfg-pill--ok">active</span>{else}<span class="lfg-pill">disabled</span>{/if}
          {if $credential->expires_at}<br><span class="lfg-muted">expires {$credential->expires_at|escape}</span>{/if}
        </td>
        <td>
          <form method="post" action="{$moduleLink|escape}&amp;page=credentials" data-lf-confirm="{$L.cred_rotate_the_secret_clients_using_the|escape}" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="credential.rotate">
            <input type="hidden" name="credential_id" value="{$credential->id|escape}">
            <button class="btn btn-xs lfg-btn--caution">{$L.cred_rotate|escape}</button>
          </form>
          <form method="post" action="{$moduleLink|escape}&amp;page=credentials" data-lf-confirm="{$L.cred_delete_this_credential_permanently|escape}" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="credential.delete">
            <input type="hidden" name="credential_id" value="{$credential->id|escape}">
            <button class="btn btn-xs btn-danger">{$L.cred_delete|escape}</button>
          </form>
        </td>
      </tr>

      <tr>
        <td colspan="9" class="lfg-shade">
          <form method="post" action="{$moduleLink|escape}&amp;page=credentials" class="form-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="credential.update">
            <input type="hidden" name="credential_id" value="{$credential->id|escape}">
            <input type="text" name="name" class="form-control input-sm" value="{$credential->name|escape}" placeholder="{$L.cred_name_3|escape}">
            <input type="text" name="scopes" class="lfg-w260 form-control input-sm" value="{$credential->scopes|escape}" placeholder="activate,check">
            <input type="text" name="allowed_ips" class="lfg-w220 form-control input-sm" value="{$credential->allowed_ips|escape}" placeholder="{$L.cred_ips_cidrs_blank_any|escape}">
            <input type="text" name="allowed_products" class="lfg-w220 form-control input-sm" value="{$credential->allowed_products|escape}" placeholder="{$L.cred_products_blank_any|escape}">
            <label class="checkbox-inline"><input type="checkbox" name="allow_all_products" value="1"{if $credential->allow_all_products} checked{/if}>{$L.cred_all_products|escape}</label>
            <input type="number" name="rate_limit" class="lfg-w110 form-control input-sm" value="{$credential->rate_limit|escape}" placeholder="{$L.cred_rate_limit_2|escape}">
            <input type="text" name="expires_at" class="lfg-w150 form-control input-sm" value="{$credential->expires_at|escape}" placeholder="expires YYYY-MM-DD">
            <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1"{if $credential->is_active} checked{/if}>{$L.cred_active|escape}</label>
            <button class="btn btn-primary btn-sm">{$L.cred_update|escape}</button>
          </form>
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="9" class="lfg-muted">{$L.cred_no_credentials_yet|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.cred_create_a_credential|escape}</strong></div>
  <div class="lfg-card-body">
    <form method="post" action="{$moduleLink|escape}&amp;page=credentials" class="form-horizontal">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="credential.create">

      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.cred_name_2|escape}</label>
        <div class="col-sm-5"><input type="text" name="name" class="form-control" placeholder="My product SDK" required></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.cred_scopes_2|escape}</label>
        <div class="col-sm-5">
          <input type="text" name="scopes" class="form-control" value="activate,check">
          <span class="help-block lfg-muted">
            Available: {foreach from=$scopes item=scope}{$scope|escape}{if !$scope@last}, {/if}{/foreach}.
            <code>activate,check</code> is what a shipped SDK needs. <code>admin</code> satisfies
            every scope check, so give it only to a credential that stays on a server you
            control - never one that travels inside software a customer can unpack.
            Give each release line its own credential, so a leaked build can be revoked on
            its own.
          </span>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.cred_ip_allow_list_2|escape}</label>
        <div class="col-sm-5"><input type="text" name="allowed_ips" class="form-control" placeholder="{$L.cred_leave_blank_to_allow_any_source|escape}"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.cred_product_allow_list|escape}</label>
        <div class="col-sm-5"><input type="text" name="allowed_products" class="form-control" placeholder="{$L.cred_products_blank_any|escape}"></div>
        <div class="col-sm-4"><label class="checkbox-inline"><input type="checkbox" name="allow_all_products" value="1">{$L.cred_all_products|escape}</label></div>
        <div class="col-sm-12"><span class="help-block lfg-muted">{$L.cred_product_allow_list_help|escape}</span></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.cred_expires|escape}</label>
        <div class="col-sm-3"><input type="date" name="expires_at" class="form-control"></div>
        <label class="col-sm-2 control-label">{$L.cred_rate_limit|escape}</label>
        <div class="col-sm-2"><input type="number" name="rate_limit" class="form-control" value="0" min="0"></div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <span class="help-block lfg-muted">{$L.cred_rate_limit_help|escape}</span>
        </div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary">{$L.cred_create_credential|escape}</button></div>
      </div>
    </form>
  </div>
</div>

</div>
