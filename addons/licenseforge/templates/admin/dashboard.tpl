{*
    Overview - the landing page.

    Order is deliberate: the attention ribbon, then the to-do list, then licence
    counts, then 24-hour traffic, then recent activity, then the maintenance
    trigger. Metrics above actions would mean reading a set of numbers to
    discover there is a problem; the ribbon states it outright.

    Variables
      $attention          array[]  Outstanding items {what, why, url, action, tone}.
                                   Empty means all clear.
      $stats              array    total, active, pending, suspended, expired,
                                   revoked, expiring_30d, activations,
                                   validations_24h, failed_24h, reissues_24h.
      $recentLicenses     array[]  Newest licences, display-ready.
      $abuseEvents        object[] Newest abuse signals.
      $recentValidations  object[] Newest licensing calls, any outcome.
      $recentFailures     object[] Newest refusals only.
      $moduleLink         string   Base addon URL.
      $csrfToken          string   Submitted as lfg_token with the maintenance form.
      $version            string   Module version, shown in the footer.
      $L                  array    Translated strings, keyed ov_*.

    Posts
      do=maintenance.run  Runs the scheduled housekeeping immediately: expiry
                          sweeps, retention pruning, reminder emails.

    Every reading carries lfg-reading--zero when its value is 0, which greys it
    out - the point of the strip. Zero and bad are different states: no
    revocations is healthy and greys, a non-zero count turns red.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-page">

{if $attention}
  <div class="lfg-ribbon lfg-ribbon--attention">
    <span class="lfg-ribbon-dot"></span>
    <span class="lfg-ribbon-text">
      <strong>{if $attention|@count == 1}{$L.ov_attention_one|escape}{else}{$L.ov_attention_many|replace:':count':$attention|@count|escape}{/if}</strong>
      {$L.ov_attention_rest_ok|escape}
    </span>
  </div>

  <div class="lfg-card">
    <ul class="lfg-todo">

      {foreach from=$attention item=item}
        <li class="is-{$item.tone|escape}">
          <span>
            <span class="lfg-todo-what">{$item.what|escape}</span>
            <span class="lfg-todo-why">{$item.why|escape}</span>
          </span>
          <a class="btn btn-xs lfg-btn--route" href="{$item.url|escape}">{$item.action|escape}</a>
        </li>
      {/foreach}
    </ul>
  </div>
{else}
  <div class="lfg-ribbon lfg-ribbon--clear">
    <span class="lfg-ribbon-dot"></span>
    <span class="lfg-ribbon-text">
      <strong>{$L.ov_all_clear|escape}</strong>
      {$L.ov_all_clear_detail|escape}
    </span>
  </div>
{/if}

<div class="lfg-card">
  <div class="lfg-card-head">
    <h3 class="lfg-card-title">{$L.ov_licenses|escape}</h3>
    <span class="lfg-card-note">{$L.ov_in_total|replace:':count':$stats.total|escape}</span>
  </div>
  <div class="lfg-readings">
    <div class="lfg-reading {if $stats.active}lfg-reading--ok{else}lfg-reading--zero{/if}">
      <div class="v"><a href="{$moduleLink|escape}&amp;page=licenses&amp;status=active">{$stats.active|escape}</a></div>
      <div class="l">{$L.ov_active|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.pending}{else}lfg-reading--zero{/if}">
      <div class="v"><a href="{$moduleLink|escape}&amp;page=licenses&amp;status=pending">{$stats.pending|escape}</a></div>
      <div class="l">{$L.ov_pending|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.suspended}lfg-reading--warn{else}lfg-reading--zero{/if}">
      <div class="v"><a href="{$moduleLink|escape}&amp;page=licenses&amp;status=suspended">{$stats.suspended|escape}</a></div>
      <div class="l">{$L.ov_suspended|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.expired}{else}lfg-reading--zero{/if}">
      <div class="v"><a href="{$moduleLink|escape}&amp;page=licenses&amp;status=expired">{$stats.expired|escape}</a></div>
      <div class="l">{$L.ov_expired|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.revoked}lfg-reading--bad{else}lfg-reading--zero{/if}">
      <div class="v"><a href="{$moduleLink|escape}&amp;page=licenses&amp;status=revoked">{$stats.revoked|escape}</a></div>
      <div class="l">{$L.ov_revoked|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.expiring_30d}lfg-reading--warn{else}lfg-reading--zero{/if}">
      <div class="v">{$stats.expiring_30d|escape}</div>
      <div class="l">{$L.ov_expiring_30d|escape}</div>
    </div>
  </div>
</div>

<div class="lfg-card">
  <div class="lfg-card-head">
    <h3 class="lfg-card-title">{$L.ov_traffic|escape}</h3>
    <span class="lfg-card-note">{$L.ov_last_24_hours|escape}</span>
  </div>
  <div class="lfg-readings">
    <div class="lfg-reading {if $stats.activations}{else}lfg-reading--zero{/if}">
      <div class="v">{$stats.activations|escape}</div>
      <div class="l">{$L.ov_installations|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.validations_24h}{else}lfg-reading--zero{/if}">
      <div class="v">{$stats.validations_24h|escape}</div>
      <div class="l">{$L.ov_checks|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.failed_24h}lfg-reading--bad{else}lfg-reading--zero{/if}">
      <div class="v">{$stats.failed_24h|escape}</div>
      <div class="l">{$L.ov_refused|escape}</div>
    </div>
    <div class="lfg-reading {if $stats.reissues_24h}{else}lfg-reading--zero{/if}">
      <div class="v">{$stats.reissues_24h|escape}</div>
      <div class="l">{$L.ov_resets|escape}</div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-md-6">
    <div class="lfg-card">
      <div class="lfg-card-head">
        <h3 class="lfg-card-title">{$L.ov_newest_licenses|escape}</h3>
        <a class="lfg-card-note" href="{$moduleLink|escape}&amp;page=licenses">{$L.ov_all_licenses|escape}</a>
      </div>
      <table class="lfg-table">
        <thead><tr><th>Key</th><th>Status</th><th>Expires</th><th>Product</th></tr></thead>
        <tbody>
        {foreach from=$recentLicenses item=item}
          <tr>
            <td><a class="lfg-key" href="{$moduleLink|escape}&amp;page=license&amp;id={$item.id|escape}">{$item.key|escape}</a></td>
            <td><span class="lfg-pill lfg-pill--{$item.tone|escape}">{$item.statusLabel|escape}</span></td>
            <td class="lfg-data lfg-muted">{if $item.isLifetime}Never{elseif $item.expires}{$item.expires|escape}{else}-{/if}</td>
            <td class="lfg-muted">{$item.product|escape}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="4"><div class="lfg-empty">
            <strong>{$L.ov_empty_licenses|escape}</strong>
            {$L.ov_empty_licenses_hint|escape}
          </div></td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-md-6">
    <div class="lfg-card">
      <div class="lfg-card-head">
        <h3 class="lfg-card-title">{$L.ov_suspicious|escape}</h3>
        <a class="lfg-card-note" href="{$moduleLink|escape}&amp;page=abuse">{$L.ov_all_events|escape}</a>
      </div>
      <table class="lfg-table">
        <thead><tr><th>Signal</th><th>Severity</th><th>What happened</th><th>When</th></tr></thead>
        <tbody>
        {foreach from=$abuseEvents item=event}
          <tr>
            <td class="lfg-data">{$event->signal|escape}</td>
            <td><span class="lfg-pill lfg-pill--{if $event->severity eq 'high'}bad{elseif $event->severity eq 'medium'}warn{/if}">{$event->severity|escape}</span></td>
            <td>{$event->summary|escape}</td>
            <td class="lfg-data lfg-muted">{$event->created_at|escape}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="4"><div class="lfg-empty">
            <strong>{$L.ov_empty_abuse|escape}</strong>
            {$L.ov_empty_abuse_hint|escape}
          </div></td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-md-6">
    <div class="lfg-card">
      <div class="lfg-card-head"><h3 class="lfg-card-title">{$L.ov_recent_checks|escape}</h3></div>
      <table class="lfg-table">
        <thead><tr><th>Call</th><th>Result</th><th>Domain</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        {foreach from=$recentValidations item=check}
          <tr>
            <td class="lfg-data">{$check->endpoint|escape}</td>
            <td>{if $check->success}<span class="lfg-pill lfg-pill--ok">ok</span>{else}<span class="lfg-pill lfg-pill--bad">{$check->error_code|escape}</span>{/if}</td>
            <td class="lfg-data">{$check->domain|escape|default:'-'}</td>
            <td class="lfg-data lfg-muted">{$check->ip_address|escape|default:'-'}</td>
            <td class="lfg-data lfg-muted">{$check->created_at|escape}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="5"><div class="lfg-empty">
            <strong>{$L.ov_empty_traffic|escape}</strong>
            {$L.ov_empty_traffic_hint|escape}
          </div></td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-md-6">
    <div class="lfg-card">

      <div class="lfg-card-head">
        <h3 class="lfg-card-title">{$L.ov_refused_checks|escape}</h3>
        <span class="lfg-card-note">{$L.ov_what_customers_hit|escape}</span>
      </div>
      <table class="lfg-table">
        <thead><tr><th>Reason</th><th>Domain</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        {foreach from=$recentFailures item=check}
          <tr>
            <td><span class="lfg-pill lfg-pill--bad">{$check->error_code|escape}</span></td>
            <td class="lfg-data">{$check->domain|escape|default:'-'}</td>
            <td class="lfg-data lfg-muted">{$check->ip_address|escape|default:'-'}</td>
            <td class="lfg-data lfg-muted">{$check->created_at|escape}</td>
          </tr>
        {foreachelse}
          <tr><td colspan="4"><div class="lfg-empty">
            <strong>{$L.ov_empty_refused|escape}</strong>
            {$L.ov_empty_refused_hint|escape}
          </div></td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="lfg-card">
  <div class="lfg-card-body">
    <form method="post" action="{$moduleLink|escape}&amp;page=dashboard" class="lfg-m0 lfg-toolbar"
          data-lf-confirm="{$L.ov_run_confirm|escape}">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="maintenance.run">
      <span class="lfg-text-sm lfg-muted">
        {$L.ov_maintenance_hint|escape}
      </span>
      <button type="submit" class="btn btn-sm lfg-btn--caution">{$L.ov_run_maintenance|escape}</button>
    </form>
  </div>
</div>

<p class="lfg-footnote">LicenseForge {$version|escape}</p>

</div>

</div>
