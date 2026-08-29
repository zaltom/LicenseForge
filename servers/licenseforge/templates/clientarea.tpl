{*
    ==========================================================================
    License Forge - customer licensing panel
    ==========================================================================

    Rendered inside the customer's product details page in the client area.
    This is the only part of the module a customer ever sees, so it answers
    their three questions in order: what is my key, where is it installed, and
    how do I move it.

    Structure:

      1. Flash messages from the previous action.
      2. The license card - key, expiry, installs, version, features, downloads.
      3. The installations table, with a per-row deactivate control.
      4. The reset card, when self-service resets are enabled.

    --------------------------------------------------------------------------
    EXPECTED VARIABLES
    --------------------------------------------------------------------------
      $lfHasLicense   bool     False before provisioning completes.
      $lfPending      string   Explains why there is no license yet, if known.
      $lfLicense      array    Display-ready: .key, .keyBlocks, .statusLabel,
                               .badge, .expires, .days, .isLifetime, .isTrial,
                               .domain, .activationsUsed, .activationsLimit,
                               .slots, .version, .latest, .inGrace, .graceEnds,
                               .reissuesLeft, .reissuesLimit.
      $lfActivations  array[]  Installations, each .canRelease.
      $lfFeatures     string[] Entitlement names, already made human-readable.
      $lfReleases     array[]  Downloads available to this license.
      $lfCanReissue   bool     Whether self-service resets are permitted.
      $lfServiceId    int      Used to build the post-back URL.
      $lfCsrfToken    string   Submitted as lfg_token with both forms.
      $lfMessages     array[]  Each {type, text}.
      $L              array    Translated strings, keyed client_*.

    --------------------------------------------------------------------------
    POSTS
    --------------------------------------------------------------------------
    Both forms post back to the product details page itself, because WHMCS
    routes client-area module output through that URL and there is no separate
    endpoint to target.

      lf_action=deactivate   activation_id   Releases one installation.
      lf_action=reissue                      Releases all of them at once.

    --------------------------------------------------------------------------
    WHY THIS BORROWS NOTHING FROM THE THEME
    --------------------------------------------------------------------------
    Every class here is a .lf- class defined in assets/client.css. The panel
    uses none of the theme's own - not .panel, .btn, .label, .table, and not
    the grid.

    That is not stylistic preference. Those classes differ between Six
    (Bootstrap 3), Twenty-One (Bootstrap 5) and every third-party theme a
    customer might install, and a component that inherits half its appearance
    looks broken in every theme except the one it was written against. Being
    self-contained means it looks the same everywhere and cannot be broken by a
    theme update.

    For the same reason, inherited type, colour and alignment are reset
    explicitly in the stylesheet rather than assumed. Only the body font is
    allowed through, so the panel still reads as part of the surrounding site.

    If you restyle this, change assets/client.css. Reaching for the theme's
    classes here will look correct on your theme and wrong on your customers'.

    --------------------------------------------------------------------------
    ESCAPING
    --------------------------------------------------------------------------
    Everything is escaped, without exception. This template renders customer-
    supplied values - domains and versions reported by their own installations
    - so there is no such thing as a trusted value on this page.
*}
{* Styles and behaviour: the addon's assets/client.css and
   assets/client-panel.js, loaded by the ClientAreaHeadOutput hook. *}
<div class="lf-panel">

{foreach $lfMessages as $lfMessage}
    <div class="lf-note lf-note--{if $lfMessage.type == 'danger'}danger{elseif $lfMessage.type == 'success'}success{else}info{/if}">{$lfMessage.text|escape}</div>
{/foreach}

{* No license yet - usually a service still provisioning, or one that failed to
   provision. The customer is told why rather than shown an empty panel, which
   would read as a fault in the site. *}
{if !$lfHasLicense}

    <div class="lfg-mb0 lf-note lf-note--info">
        {$lfPending|default:$L.client_no_license|escape}
    </div>

{else}

{* Both forms on this page post back here. See the header note on why. *}
{assign var="lfPostUrl" value="clientarea.php?action=productdetails&id=`$lfServiceId`"}

<div class="lf-card">
    <div class="lf-card-head">
        <h3 class="lf-card-title">{$L.client_your_license|escape}</h3>
        <span class="lf-status lf-status--{$lfLicense.badge|escape}">{$lfLicense.statusLabel|escape}</span>
    </div>
    <div class="lf-card-body">

        {* At most one warning, and grace wins. A license already inside its
           grace period is also "expiring", so showing both would tell the
           customer two different things about the same date. *}
        {if $lfLicense.inGrace}
            <div class="lf-note lf-note--warning">
                {$L.client_grace_warning|replace:':date':$lfLicense.graceEnds|escape}
            </div>
        {elseif $lfLicense.days !== null && $lfLicense.days >= 0 && $lfLicense.days <= 30}
            <div class="lf-note lf-note--warning">
                {if $lfLicense.days == 1}{$L.client_expiring_tomorrow|escape}{else}{$L.client_expiring_warning|replace:':days':$lfLicense.days|escape}{/if}
            </div>
        {/if}

        {* The key itself. Split into blocks so it can be read off a screen and
           typed accurately, with a copy button for the common case.

           The plate carries the unsplit key in data-lf-key, which is what the
           copy button in assets/client-panel.js actually copies - reassembling
           it from the DOM would risk picking up rendering artefacts. *}
        <p class="lf-eyebrow" id="lfKeyLabel">{$L.client_license_key|escape}</p>
        <div class="lf-plate">
            {* {strip} and the absence of whitespace here are load-bearing.
               Selecting the plate by hand must yield exactly the key, dashes
               included and nothing else, because customers do that instead of
               using the button. Any whitespace between the blocks, or a
               prettier separator glyph in place of the literal "-", ends up in
               what they paste into their config - and the resulting key does
               not validate. *}
            <p class="lf-plate-key" aria-labelledby="lfKeyLabel" data-lf-key="{$lfLicense.key|escape}">{strip}
                {foreach $lfLicense.keyBlocks as $lfBlock}
                    {if !$lfBlock@first}<span class="lf-sep">-</span>{/if}<span class="lf-block">{$lfBlock|escape}</span>
                {/foreach}
            {/strip}</p>
            <button type="button" class="lf-btn lf-btn--onplate" data-lf-copy aria-live="polite">{$L.client_copy_key|escape}</button>
        </div>

        {* Four facts: when it ends, where it runs, how many installs are used,
           which version. Each shows a headline value with a clarifying line
           beneath only when there is something to add - an em dash where a
           value is genuinely unknown, never a blank cell. *}
        <div class="lf-readout">
            <div class="lf-cell">
                <p class="lf-eyebrow">{$L.client_expires|escape}</p>
                <div class="lf-value">{$lfLicense.expires|escape}</div>
                {if $lfLicense.isLifetime}
                    <div class="lf-sub">{$L.client_never_expires|escape}</div>
                {elseif $lfLicense.days !== null && $lfLicense.days >= 0}
                    <div class="lf-sub">{if $lfLicense.days == 1}{$L.client_expires_in_day|escape}{else}{$L.client_expires_in_days|replace:':days':$lfLicense.days|escape}{/if}</div>
                {/if}
                {if $lfLicense.isTrial}<div class="lf-sub">{$L.client_trial|escape}</div>{/if}
            </div>

            <div class="lf-cell">
                <p class="lf-eyebrow">{$L.client_installed_on|escape}</p>
                <div class="lf-value">{if $lfLicense.domain}{$lfLicense.domain|escape}{else}-{/if}</div>
                {if !$lfLicense.domain}<div class="lf-sub">{$L.client_set_on_activation|escape}</div>{/if}
            </div>

            <div class="lf-cell">
                <p class="lf-eyebrow">{$L.client_installations|escape}</p>
                <div class="lf-value">{$L.client_x_of_y|replace:':used':$lfLicense.activationsUsed|replace:':limit':$lfLicense.activationsLimit|escape}</div>
                {* Dots repeating the used/limit count visually. aria-hidden
                   because the same information is already in the text above,
                   and a screen reader announcing a row of empty spans would
                   only add noise. *}
                {if $lfLicense.slots}
                    <div class="lf-slots" aria-hidden="true">
                        {foreach $lfLicense.slots as $lfSlot}<span class="lf-slot{if $lfSlot} is-used{/if}"></span>{/foreach}
                    </div>
                {/if}
            </div>

            <div class="lf-cell">
                <p class="lf-eyebrow">{$L.client_version|escape}</p>
                <div class="lf-value">{if $lfLicense.version}{$lfLicense.version|escape}{else}-{/if}</div>
                {if $lfLicense.latest && $lfLicense.latest != $lfLicense.version}
                    <div class="lf-sub lf-sub-up">{$L.client_version_available|replace:':version':$lfLicense.latest|escape}</div>
                {/if}
            </div>
        </div>

        {if $lfFeatures}
            <div class="lf-tags">
                <p class="lf-eyebrow">{$L.client_included|escape}</p>
                {foreach $lfFeatures as $lfFeature}<span class="lf-tag">{$lfFeature|escape}</span>{/foreach}
            </div>
        {/if}

        {if $lfReleases}
            <div class="lf-tags">
                <p class="lf-eyebrow">{$L.client_downloads|escape}</p>
                {foreach $lfReleases as $lfRelease}
                    <a class="lf-tag" href="{$lfRelease.url|escape}">{$lfRelease.label|escape}{if $lfRelease.version} {$lfRelease.version|escape}{/if}{if $lfRelease.size} · {$lfRelease.size|escape}{/if}</a>
                {/foreach}
            </div>
        {/if}

    </div>
</div>

{* ---------------------------------------------------------------------------
   Where the software is running.

   Past installations stay in the list, dimmed via .lf-past, so a customer can
   see that a slot was used and released rather than wondering where it went.
   Only an active one offers the deactivate button, and $lfActivation.canRelease
   is decided server-side - the button's absence is presentation, not the
   control.
   --------------------------------------------------------------------------- *}
<div class="lf-card">
    <div class="lf-card-head"><h3 class="lf-card-title">{$L.client_installations|escape}</h3></div>

    {if $lfActivations}
        <div class="lf-scroll">
            <table class="lf-table">
                <thead>
                    <tr>
                        <th scope="col">{$L.client_col_location|escape}</th>
                        <th scope="col">{$L.client_col_version|escape}</th>
                        <th scope="col">{$L.client_col_activated|escape}</th>
                        <th scope="col">{$L.client_col_last_checkin|escape}</th>
                        {* Header for the actions column, visible to screen
                           readers only: a table header that reads as blank is
                           worse than no column name at all. *}
                        <th scope="col"><span class="lf-sr">{$L.client_actions|escape}</span></th>
                    </tr>
                </thead>
                <tbody>
                {foreach $lfActivations as $lfActivation}
                    <tr{if !$lfActivation.isActive} class="lf-past"{/if}>
                        <td>
                            <span class="lf-host">{$lfActivation.label|escape}</span>
                            {if $lfActivation.ip}<div class="lf-meta">{$lfActivation.ip|escape}</div>{/if}
                            {if !$lfActivation.isActive}<div class="lf-meta">{$lfActivation.status|escape}</div>{/if}
                        </td>
                        <td class="lf-meta">{if $lfActivation.version}{$lfActivation.version|escape}{else}-{/if}</td>
                        <td class="lf-meta">{$lfActivation.activatedAt|escape}</td>
                        <td class="lf-meta">{$lfActivation.lastSeen|escape}</td>
                        <td class="lf-right">
                            {if $lfActivation.canRelease}
                                <form method="post" action="{$lfPostUrl|escape}" class="lf-inline-form"
                                      data-lf-confirm="{$L.client_deactivate_confirm|escape}">
                                    <input type="hidden" name="lfg_token" value="{$lfCsrfToken|escape}">
                                    <input type="hidden" name="lf_action" value="deactivate">
                                    <input type="hidden" name="activation_id" value="{$lfActivation.id|escape}">
                                    <button type="submit" class="lf-btn lf-btn--sm">{$L.client_deactivate|escape}</button>
                                </form>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="lf-card-body">
            <p>{$L.client_nothing_using|escape}</p>
            <p class="lf-meta">{$L.client_enter_key_hint|escape}</p>
        </div>
    {/if}
</div>

{* ---------------------------------------------------------------------------
   Self-service reset.

   The whole card is hidden unless resets are enabled, rather than shown
   disabled: a customer offered a control they cannot use opens a ticket asking
   why. Its two states are handled separately below.
   --------------------------------------------------------------------------- *}
{if $lfCanReissue}
<div class="lf-card">
    <div class="lf-card-head"><h3 class="lf-card-title">{$L.client_reset_title|escape}</h3></div>
    <div class="lf-card-body">
        {if $lfLicense.reissuesLeft > 0}
            <p>
                Deactivates every installation at once so you can activate the software somewhere else. Use this
                after moving to a new server or if you have lost access to an old installation.
            </p>
            <p class="lfg-mb-md lf-meta">
                You can reset {$lfLicense.reissuesLeft|escape} more time{if $lfLicense.reissuesLeft != 1}s{/if}.
            </p>
            <form method="post" action="{$lfPostUrl|escape}" class="lf-inline-form"
                  data-lf-confirm="{$L.client_reset_confirm|escape}">
                <input type="hidden" name="lfg_token" value="{$lfCsrfToken|escape}">
                <input type="hidden" name="lf_action" value="reissue">
                <button type="submit" class="lf-btn">{$L.client_reset_button|escape}</button>
            </form>
        {else}
            {* No resets remaining. States the limit that was reached and points
               at support, rather than rendering a disabled button - a greyed
               control tells the customer they cannot proceed but not why, and
               not what to do next. *}
            <p>
                You have used all {$lfLicense.reissuesLimit|escape} of your resets.
                <a href="submitticket.php">Open a ticket</a> if you need to reset it again.
            </p>
        {/if}
    </div>
</div>
{/if}

{/if}

</div>
