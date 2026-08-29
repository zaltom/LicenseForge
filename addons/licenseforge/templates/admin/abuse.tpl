{*
    Abuse - signals derived from licensing traffic.

    Open and resolved are separate views, both plain links so each is
    bookmarkable and the back button behaves.

    Variables
      $events        object[]  Signals for the current view.
      $showResolved  bool      Which view is active.
      $moduleLink    string    Base addon URL.
      $csrfToken     string    Submitted as lfg_token with the resolve form.
      $L             array     Translated strings, keyed abus_*.

    Posts
      do=abuse.resolve  Marks one signal resolved.

    Signals are derived only from licensing calls, never from anything a
    customer types. Only over-deployment acts unattended, because it counts
    activations, which had to pass authentication to exist; every other signal
    flags and notifies but waits for a person, since anyone who learns a licence
    key could otherwise trigger them to get a customer suspended.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-section">
  <a class="btn btn-sm {if !$showResolved}btn-primary{else}btn-default{/if}" href="{$moduleLink|escape}&amp;page=abuse">{$L.abus_open_alerts|escape}</a>
  <a class="btn btn-sm {if $showResolved}btn-primary{else}btn-default{/if}" href="{$moduleLink|escape}&amp;page=abuse&amp;resolved=1">{$L.abus_resolved|escape}</a>
</div>

<div class="lfg-card">
  <div class="panel-heading"><strong>{if $showResolved}Resolved{else}Open{/if} abuse signals</strong></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.abus_when|escape}</th><th>{$L.abus_severity|escape}</th><th>{$L.abus_signal|escape}</th><th>{$L.abus_summary|escape}</th><th>{$L.abus_license|escape}</th><th>{$L.abus_ip|escape}</th><th></th></tr></thead>
    <tbody>
    {foreach from=$events item=event}

      <tr class="{if $event->severity eq 'high'}danger{elseif $event->severity eq 'medium'}warning{/if}">
        <td class="lfg-muted">{$event->created_at|escape}</td>
        <td><span class="lfg-pill lfg-pill--{if $event->severity eq 'high'}bad{elseif $event->severity eq 'medium'}warn{/if}">{$event->severity|escape}</span></td>
        <td>{$event->signal|escape}</td>
        <td>{$event->summary|escape}</td>
        <td>{if $event->license_id}<a href="{$moduleLink|escape}&amp;page=license&amp;id={$event->license_id|escape}">#{$event->license_id|escape}</a>{else}-{/if}</td>
        <td class="lfg-muted">{$event->ip_address|escape|default:'-'}</td>
        <td>

          {if !$event->resolved}
          <form method="post" action="{$moduleLink|escape}&amp;page=abuse">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="abuse.resolve">
            <input type="hidden" name="event_id" value="{$event->id|escape}">
            <button class="btn btn-xs btn-default">{$L.abus_mark_resolved|escape}</button>
          </form>
          {else}
            <span class="lfg-muted">{$event->resolved_at|escape}</span>
          {/if}
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="lfg-muted">{$L.abus_nothing_to_show|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>

<div class="alert alert-info">{$L.abus_signals_are_derived_only_from_licensing|escape}</div>

</div>
