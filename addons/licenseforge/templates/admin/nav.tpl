{*
    Page chrome: the title, the tab bar and the flash-message strip.

    Every admin page opens with {include file="nav.tpl"}, so navigation and the
    look of a status message are changed here alone.

    Variables
      $activePage  string  Slug of the page being rendered. 'license' marks the
                           Licenses tab active, being a child of that section.
      $moduleLink  string  Base addon URL, already carrying ?module=licenseforge.
      $nav         array   Badge counts .reissues and .abuse. Zero is falsy in
                           Smarty, so a badge renders only when work is waiting.
      $messages    array   Flash messages, each {type, text}; `type` is a
                           Bootstrap alert suffix.
      $L           array   Translated strings, keyed nav_*.

    SECURITY: $message.text is deliberately NOT escaped - some flash messages
    carry markup, such as a link to the record just created. Whatever sets a
    flash message must escape it. Never place raw user input in one.
*}
{if $activePage eq 'dashboard'}{assign var=pageTitle value=$L.nav_overview}
{elseif $activePage eq 'licenses' or $activePage eq 'license'}{assign var=pageTitle value=$L.nav_licenses}
{elseif $activePage eq 'products'}{assign var=pageTitle value=$L.nav_products}
{elseif $activePage eq 'reissues'}{assign var=pageTitle value=$L.nav_resets}
{elseif $activePage eq 'abuse'}{assign var=pageTitle value=$L.nav_abuse}
{elseif $activePage eq 'credentials'}{assign var=pageTitle value=$L.nav_api}
{elseif $activePage eq 'logs'}{assign var=pageTitle value=$L.nav_audit_log}
{elseif $activePage eq 'settings'}{assign var=pageTitle value=$L.nav_settings}
{else}{assign var=pageTitle value='LicenseForge'}{/if}

<div class="lfg-wrap">

  <div class="lfg-masthead">
    <h2 class="lfg-masthead-title">{$pageTitle|escape}</h2>
  </div>

  <ul class="lfg-nav">
    <li{if $activePage eq 'dashboard'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=dashboard">{$L.nav_overview|escape}</a></li>
    <li{if $activePage eq 'licenses' or $activePage eq 'license'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=licenses">{$L.nav_licenses|escape}</a></li>
    <li{if $activePage eq 'products'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=products">{$L.nav_products|escape}</a></li>
    <li{if $activePage eq 'reissues'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=reissues">{$L.nav_resets|escape}{if $nav.reissues}<span class="lfg-count">{$nav.reissues|escape}</span>{/if}</a></li>
    <li{if $activePage eq 'abuse'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=abuse">{$L.nav_abuse|escape}{if $nav.abuse}<span class="lfg-count">{$nav.abuse|escape}</span>{/if}</a></li>
    <li{if $activePage eq 'credentials'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=credentials">{$L.nav_api|escape}</a></li>
    <li{if $activePage eq 'logs'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=logs">{$L.nav_audit_log|escape}</a></li>
    <li{if $activePage eq 'settings'} class="is-active"{/if}><a href="{$moduleLink|escape}&amp;page=settings">{$L.nav_settings|escape}</a></li>
  </ul>

  {foreach from=$messages item=message}
    <div class="alert alert-{$message.type|escape}">{$message.text}</div>
  {/foreach}
</div>
