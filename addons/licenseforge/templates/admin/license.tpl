{*
    Licence detail - the facts on the left, the actions on the right.

    Variables
      $license          object   The licence row.
      $product, $client, $service  Related records; $client and $service are
                                   arrays with .id, and .exists on $service.
      $tone, $statusLabel  string Pill styling and wording for the status.
      $held, $inGrace      bool   Extra states shown as pills.
      $daysToExpiry        int|null  Negative is in the past.
      $activations, $reissues, $validations, $auditTrail  object[]  History.
      $policy           array    Effective policy after the three-layer merge.
      $entitlements     array[]  {key, label, granted}.
      $allowedStatuses  array[]  {value, label} the status control may set.
      $emailOptions     array[]  {value, label} for the send-email control.
      $allowedDomains, $allowedIps  string  Newline-separated, for the textareas.
      $moduleLink       string   Base addon URL.
      $csrfToken        string   Submitted as lfg_token with every form here.
      $L                array    Translated strings, keyed det_*.

    Posts
      do=license.update        Per-licence overrides.
      do=license.status        Status change, with a reason for the audit log.
      do=license.entitlements  Feature grants.
      do=license.email         Sends one licensing email to the holder.
      do=license.reissue       Mints a replacement key.
      do=license.reset         Clears every activation.
      do=license.delete        Soft-deletes the licence.
      do=activation.release    Deactivates one installation.
      do=license.hold_release  Lifts the hold.
      do=service.suspend       Suspends the WHMCS service as well.

    A licence and its WHMCS service are separate decisions and are easily
    confused: neither control here touches the other. A held licence ignores
    service events entirely, which is what stops an admin's deliberate change
    being reverted by the next billing event.

    Blank is not zero in the override form - the controller falls back to the
    product default, so clearing a field restores inheritance.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-section">
  <a href="{$moduleLink|escape}&amp;page=licenses" class="btn btn-sm lfg-btn--route">{$L.det_all_licenses|escape}</a>
</div>

<div class="row">
  <div class="col-md-7">

    <div class="lfg-card">
      <div class="panel-heading">
        <strong class="lfg-key">{$license->license_key|escape}</strong>
        <span class="lfg-pill lfg-pill--{$tone|escape}">{$statusLabel|escape}</span>
        {if $license->flagged}<span class="lfg-pill lfg-pill--warn">flagged</span>{/if}
        {if $held}<span class="lfg-pill lfg-pill--bad" title="{$L.det_service_events_cannot_change_this_license_2|escape}">held</span>{/if}
        {if $inGrace}<span class="lfg-pill lfg-pill--wait">{$L.det_in_grace_period|escape}</span>{/if}
        <a class="btn btn-xs btn-default pull-right lfg-copy" href="#" data-lf-copy-text="{$license->license_key|escape}">{$L.det_copy_key|escape}</a>
      </div>
      <table class="lfg-table">
        <tr><th class="lfg-w35p">{$L.det_product|escape}</th><td>{if $product}{$product->name|escape} <span class="lfg-muted">({$product->product_slug|escape})</span>{else}-{/if}</td></tr>
        <tr><th>{$L.det_client|escape}</th><td>{if $client.id}<a href="clientssummary.php?userid={$client.id|escape}">{$client.name|escape}</a> <span class="lfg-muted">{$client.email|escape}</span>{else}-{/if}</td></tr>
        <tr><th>{$L.det_service|escape}</th><td>{if $license->service_id}<a href="clientsservices.php?userid={$client.id|escape}&amp;id={$license->service_id|escape}">#{$license->service_id|escape}</a>{else}-{/if}</td></tr>
        <tr><th>{$L.det_issued|escape}</th><td>{$license->created_at|escape}</td></tr>
        <tr><th>{$L.det_first_activated|escape}</th><td>{$license->activated_at|escape|default:'Never'}</td></tr>
        <tr><th>{$L.det_expires|escape}</th><td>
          {if $license->is_lifetime}Never (lifetime){else}{$license->expires_at|escape|default:'-'}
            {if $daysToExpiry !== null}<span class="lfg-muted">({if $daysToExpiry < 0}{$daysToExpiry*-1} days ago{else}in {$daysToExpiry|escape} days{/if})</span>{/if}
          {/if}
        </td></tr>
        <tr><th>{$L.det_activations|escape}</th><td>{$license->activation_count|escape} of {$license->max_activations|escape}</td></tr>
        <tr><th>{$L.det_reissues|escape}</th><td>{$license->reissue_count|escape} of {$license->max_reissues|escape}{if $license->last_reissued_at} <span class="lfg-muted">(last {$license->last_reissued_at|escape})</span>{/if}</td></tr>

        <tr><th>{$L.det_last_check|escape}</th><td>{$license->last_validated_at|escape|default:'Never'} <span class="lfg-muted">({$license->validation_count|escape} ok / {$license->failed_validation_count|escape} failed)</span></td></tr>
        <tr><th>{$L.det_last_failure|escape}</th><td>{if $license->last_failure_code}<span class="lfg-pill lfg-pill--bad">{$license->last_failure_code|escape}</span> {$license->last_failure_at|escape}{else}-{/if}</td></tr>
        <tr><th>{$L.det_current_version|escape}</th><td>{$license->current_version|escape|default:'-'}</td></tr>
        <tr><th>{$L.det_bound_to|escape}</th><td>
          {$license->primary_domain|escape|default:'any domain'}<br>
          <span class="lfg-muted">{$license->primary_ip|escape|default:'any IP'} · {$license->primary_machine_id|escape|default:'any machine'}</span>
        </td></tr>
      </table>
    </div>

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_installations|escape}</strong></div>
      <table class="lfg-table">
        <thead><tr><th>{$L.det_installation|escape}</th><th>{$L.det_domain_ip|escape}</th><th>{$L.det_version|escape}</th><th>{$L.det_status|escape}</th><th>{$L.det_last_seen|escape}</th><th></th></tr></thead>
        <tbody>
        {foreach from=$activations item=activation}
          <tr>
            <td class="lfg-key">{$activation->installation_id|escape|truncate:24:"…"}</td>
            <td>{$activation->domain|escape|default:'-'}<br><span class="lfg-muted">{$activation->ip_address|escape|default:'-'}</span></td>
            <td>{$activation->version|escape|default:'-'}</td>
            <td>{$activation->status|escape}</td>
            <td class="lfg-muted">{$activation->last_validated_at|escape|default:'-'}</td>
            <td>
              {if $activation->status eq 'active'}
              <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" data-lf-confirm="{$L.det_deactivate_this_installation|escape}">
                <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
                <input type="hidden" name="do" value="activation.release">
                <input type="hidden" name="id" value="{$license->id|escape}">
                <input type="hidden" name="activation_id" value="{$activation->id|escape}">
                <button class="btn btn-xs lfg-btn--caution">{$L.det_deactivate|escape}</button>
              </form>
              {/if}
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="6" class="lfg-muted">{$L.det_no_installations_recorded|escape}</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_edit_license|escape}</strong></div>
      <div class="lfg-card-body">
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" class="form-horizontal">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.update">
          <input type="hidden" name="id" value="{$license->id|escape}">

          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_expiry|escape}</label>
            <div class="col-sm-4"><input type="text" name="expires_at" class="form-control" value="{$license->expires_at|escape}" placeholder="YYYY-MM-DD HH:MM:SS"></div>
            <div class="col-sm-5"><label class="checkbox-inline"><input type="checkbox" name="is_lifetime" value="1"{if $license->is_lifetime} checked{/if}>{$L.det_lifetime_license|escape}</label></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_max_activations|escape}</label>
            <div class="col-sm-3"><input type="number" name="max_activations" class="form-control" value="{$license->max_activations|escape}" min="0"></div>
            <label class="col-sm-2 control-label">{$L.det_max_reissues|escape}</label>
            <div class="col-sm-3"><input type="number" name="max_reissues" class="form-control" value="{$license->max_reissues|escape}" min="0"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_primary_domain|escape}</label>
            <div class="col-sm-4"><input type="text" name="primary_domain" class="form-control" value="{$license->primary_domain|escape}"></div>
            <label class="col-sm-2 control-label">{$L.det_primary_ip|escape}</label>
            <div class="col-sm-3"><input type="text" name="primary_ip" class="form-control" value="{$license->primary_ip|escape}"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_additional_domains|escape}</label>
            <div class="col-sm-9"><textarea name="allowed_domains" class="form-control" rows="2" placeholder="{$L.det_one_per_line_example_com_is|escape}">{$allowedDomains|escape}</textarea></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_additional_ips|escape}</label>
            <div class="col-sm-9"><textarea name="allowed_ips" class="form-control" rows="2" placeholder="{$L.det_one_per_line_cidr_ranges_are|escape}">{$allowedIps|escape}</textarea></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_version_rules|escape}</label>
            <div class="col-sm-3"><input type="text" name="min_version" class="form-control" value="{$license->min_version|escape}" placeholder="min e.g. 1.0"></div>
            <div class="col-sm-3"><input type="text" name="max_version" class="form-control" value="{$license->max_version|escape}" placeholder="max e.g. 2.9"></div>
            <div class="col-sm-3"><input type="text" name="allowed_versions" class="form-control" value="{$license->allowed_versions|escape}" placeholder="1.x, 2.x"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_customer_notes|escape}</label>
            <div class="col-sm-9"><textarea name="notes" class="form-control" rows="2">{$license->notes|escape}</textarea></div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">{$L.det_internal_notes|escape}</label>
            <div class="col-sm-9"><textarea name="admin_notes" class="form-control" rows="2">{$license->admin_notes|escape}</textarea></div>
          </div>
          <div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary">{$L.det_save_changes|escape}</button></div></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_status_2|escape}</strong></div>
      <div class="lfg-card-body">
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.status">
          <input type="hidden" name="id" value="{$license->id|escape}">
          <div class="form-group">
            <select name="status" class="form-control">
              {foreach from=$allowedStatuses item=status}
                <option value="{$status.value|escape}">{$status.label|escape}</option>
              {/foreach}
            </select>
          </div>
          <div class="form-group"><input type="text" name="reason" class="form-control" placeholder="{$L.det_reason_recorded_in_the_audit_log|escape}"></div>
          <button class="btn btn-primary btn-sm" data-lf-confirm="{$L.det_change_the_license_status|escape}">{$L.det_apply_status|escape}</button>
        </form>

        <hr>

        <p>
          <strong>{$L.det_service_2|escape}</strong>
          {if $service.exists}
            <span class="lfg-pill lfg-pill--{if $service.suspended}warn{else}ok{/if}">{$service.status|escape}</span>
            <a href="clientsservices.php?userid={$client.id|escape}&amp;id={$service.id|escape}" class="lfg-muted">#{$service.id|escape}</a>
          {else}
            <span class="lfg-muted">none</span>
          {/if}
        </p>

        {if $held}
          <div class="lfg-mb-sm alert alert-warning">
            <strong>{$L.det_held|escape}</strong>
            {if $license->held_reason}{$license->held_reason|escape}{else}Set deliberately{/if}
            <span class="lfg-muted">({$license->held_by|escape|default:'admin'}, {$license->held_at|escape})</span>
            <br>{$L.det_service_events_cannot_change_this_license|escape}</div>
          <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="license.hold_release">
            <input type="hidden" name="id" value="{$license->id|escape}">
            <button class="btn btn-sm lfg-btn--caution" data-lf-confirm="{$L.det_release_the_hold_service_events_will|escape}">{$L.det_release_hold|escape}</button>
          </form>
        {/if}

        <hr>

        <p><strong>{$L.det_send_an_email|escape}</strong></p>
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" class="form-inline">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.email">
          <input type="hidden" name="id" value="{$license->id|escape}">
          <select name="template" class="lfg-mw220 form-control input-sm">
            {foreach from=$emailOptions item=option}
              <option value="{$option.value|escape}">{$option.label|escape}</option>
            {/foreach}
          </select>
          <button class="btn btn-default btn-sm">{$L.det_send_to_customer|escape}</button>
        </form>
        <p class="lfg-mt-sm lfg-muted">{$L.det_goes_to_the_license_holder_and|escape}<em>{$L.det_email|escape}</em>.
        </p>

        {if $service.exists and !$service.suspended}
          <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" class="lfg-inline">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="service.suspend">
            <input type="hidden" name="id" value="{$license->id|escape}">
            <button class="btn btn-default btn-sm" data-lf-confirm="{$L.det_suspend_the_whmcs_service_too_this|escape}">{$L.det_suspend_the_service_too|escape}</button>
          </form>
          <p class="lfg-mt-sm lfg-muted">{$L.det_suspending_the_service_emails_the_customer|escape}</p>
        {/if}
      </div>
    </div>

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_entitlements|escape}</strong></div>
      <div class="lfg-card-body">
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.features">
          <input type="hidden" name="id" value="{$license->id|escape}">
          {foreach from=$allFeatures item=feature}
            <label class="lfg-w48p checkbox-inline">
              <input type="checkbox" name="features[]" value="{$feature.slug|escape}"{if $feature.enabled} checked{/if}> {$feature.name|escape}
            </label>
          {/foreach}
          <div class="lfg-mt-md"><button class="btn btn-primary btn-sm">{$L.det_save_entitlements|escape}</button></div>
        </form>
      </div>
    </div>

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_reissue|escape}</strong></div>
      <div class="lfg-card-body">
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" data-lf-confirm="{$L.det_reissue_this_license_current_installations_will|escape}">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.reissue">
          <input type="hidden" name="id" value="{$license->id|escape}">
          <div class="form-group"><input type="text" name="new_domain" class="form-control" placeholder="{$L.det_new_domain_optional|escape}"></div>
          <div class="form-group"><input type="text" name="reason" class="form-control" placeholder="{$L.det_reason|escape}"></div>
          <div class="checkbox"><label><input type="checkbox" name="regenerate_key" value="1">{$L.det_also_generate_a_new_license_key|escape}</label></div>
          <button class="btn btn-sm lfg-btn--caution">{$L.det_reissue_license|escape}</button>
        </form>
        <hr>
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" data-lf-confirm="{$L.det_release_every_installation_for_this_license|escape}">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.reset">
          <input type="hidden" name="id" value="{$license->id|escape}">
          <button class="btn btn-sm lfg-btn--caution">{$L.det_reset_all_activations|escape}</button>
        </form>
        <hr>
        <form method="post" action="{$moduleLink|escape}&amp;page=license&amp;id={$license->id|escape}" data-lf-confirm="{$L.det_delete_this_license_history_is_retained|escape}">
          <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
          <input type="hidden" name="do" value="license.delete">
          <input type="hidden" name="id" value="{$license->id|escape}">
          <button class="btn btn-danger btn-sm">{$L.det_delete_license|escape}</button>
        </form>
      </div>
    </div>

    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_effective_policy|escape}</strong></div>
      <table class="lfg-table">
        <tr><th>{$L.det_domain_lock|escape}</th><td>{if $policy.lock_domain}on{else}off{/if} {if $policy.allow_subdomains}<span class="lfg-muted">{$L.det_subdomains_allowed|escape}</span>{/if}</td></tr>
        <tr><th>{$L.det_ip_lock|escape}</th><td>{if $policy.lock_ip}on{else}off{/if}</td></tr>
        <tr><th>{$L.det_directory_lock|escape}</th><td>{if $policy.lock_directory}on{else}off{/if}</td></tr>
        <tr><th>{$L.det_machine_lock|escape}</th><td>{if $policy.lock_machine}on{else}off{/if}</td></tr>
        <tr><th>{$L.det_grace_period|escape}</th><td>{$policy.grace_days|escape} day{if $policy.grace_days != 1}s{/if}</td></tr>
        <tr><th>{$L.det_check_interval|escape}</th><td>{$policy.validation_interval_hours|escape}h</td></tr>
        <tr><th>{$L.det_offline_validity|escape}</th><td>{$policy.offline_validity_days|escape} day{if $policy.offline_validity_days != 1}s{/if}</td></tr>
        <tr><th>{$L.det_reissue_cooldown|escape}</th><td>{$policy.reissue_cooldown_hours|escape}h</td></tr>
      </table>
    </div>

  </div>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{$L.det_validation_history|escape}</strong></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.det_when|escape}</th><th>{$L.det_endpoint|escape}</th><th>{$L.det_result|escape}</th><th>{$L.det_domain|escape}</th><th>{$L.det_ip|escape}</th><th>{$L.det_version_2|escape}</th><th>{$L.det_time|escape}</th></tr></thead>
    <tbody>
    {foreach from=$validations item=check}
      <tr>
        <td class="lfg-muted">{$check->created_at|escape}</td>
        <td>{$check->endpoint|escape}</td>
        <td>{if $check->success}<span class="lfg-pill lfg-pill--ok">ok</span>{else}<span class="lfg-pill lfg-pill--bad">{$check->error_code|escape}</span>{/if}</td>
        <td>{$check->domain|escape|default:'-'}</td>
        <td>{$check->ip_address|escape|default:'-'}</td>
        <td>{$check->version|escape|default:'-'}</td>
        <td class="lfg-muted">{$check->duration_ms|escape}ms</td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="lfg-muted">{$L.det_no_validation_traffic_yet|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

<div class="row">
  <div class="col-md-6">
    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_reissue_history|escape}</strong></div>
      <table class="lfg-table">
        <thead><tr><th>{$L.det_when_2|escape}</th><th>{$L.det_status_3|escape}</th><th>{$L.det_from|escape}</th><th>{$L.det_to|escape}</th><th>{$L.det_by|escape}</th></tr></thead>
        <tbody>
        {foreach from=$reissues item=reissue}
          <tr>
            <td class="lfg-muted">{$reissue->created_at|escape}</td>
            <td>{$reissue->status|escape}</td>
            <td>{$reissue->old_domain|escape|default:'-'}</td>
            <td>{$reissue->new_domain|escape|default:'-'}</td>
            <td>{$reissue->initiated_by|escape}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="5" class="lfg-muted">{$L.det_never_reissued|escape}</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-md-6">
    <div class="lfg-card">
      <div class="panel-heading"><strong>{$L.det_audit_trail|escape}</strong></div>
      <table class="lfg-table">
        <thead><tr><th>{$L.det_when_3|escape}</th><th>{$L.det_action|escape}</th><th>{$L.det_result_2|escape}</th><th>{$L.det_actor|escape}</th></tr></thead>
        <tbody>
        {foreach from=$auditLog item=entry}
          <tr>
            <td class="lfg-muted">{$entry->created_at|escape}</td>
            <td>{$entry->action|escape}</td>
            <td>{$entry->result|escape}</td>
            <td>{$entry->actor_type|escape}{if $entry->actor_name} · {$entry->actor_name|escape}{/if}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="4" class="lfg-muted">{$L.det_no_entries|escape}</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>
