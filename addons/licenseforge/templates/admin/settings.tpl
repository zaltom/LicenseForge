{*
    Settings - module-wide configuration.

    Everything here is a default: a product may override it on its Module
    Settings tab, and an individual licence may override the product. Changing a
    value affects only the products and licences that have not been given
    their own.

    The page is one settings form followed by four independent panels, listed in
    the sidebar either side of a divider. Keep that split: the panels perform
    actions rather than saving values, and nesting them inside the settings form
    would submit the entire configuration every time one was used.

    Variables
      $settings            array    Current values, keyed without the s_ prefix.
      $apiUrl              string   Detected endpoint, used as a placeholder.
      $statusMap           array[]  Service-event rows {service, key, options, explain}.
      $releaseDirProblem   bool     The configured release directory was refused.
      $sodium              bool     Whether libsodium is available, deciding if
                                    Ed25519 can be offered.
      $unsigned_permitted  bool     Whether the config constant allowing unsigned
                                    requests is actually defined.
      $installProof        array    .required, .active, .unproven.
      $emailTemplates      array[]  Each licensing email, with .exists.
      $emailMissing        int      How many are not installed.
      $mergeFields         array    field => meaning, shared by every template.
      $signingKeys         array[]  Offline signing keys, one active.
      $migrations          object[] Applied database migrations.
      $moduleLink          string   Base addon URL.
      $csrfToken           string   Submitted as lfg_token with every form here.
      $L                   array    Translated strings, keyed sett_*.

    Posts
      do=settings.save   Every s_* input on the main form.
      do=emails.install  Installs missing templates; with reset=1 it also
                         overwrites edited ones back to the shipped wording.
      do=keys.generate   Mints a new offline signing key.
      do=keys.activate   Promotes an existing key to active.

    INPUT NAMING: every saved field is named s_<key> while its value is read
    back from $settings.<key> without the prefix. The prefix is what lets the
    handler pick settings out of the POST body and ignore everything else
    without maintaining a whitelist. Adding a setting means adding an
    s_-prefixed input; forgetting the prefix silently drops it on save.

    Unchecked checkboxes post nothing, so the handler must read a missing s_*
    checkbox as false rather than as "leave unchanged", or no toggle on this
    page could ever be turned off.

    Each section carries id="set-*", matching an anchor in the sidebar.
    assets/admin.js shows one at a time and hides the rest with CSS - never
    disabling them, so all ten still post through the single save button.
*}
<div class="lfg-console">

{include file="nav.tpl"}

<div class="lfg-settings" data-lf-sections>
  <nav class="lfg-sidenav" aria-label="{$L.nav_settings|escape}">
    <a href="#set-general">{$L.sett_general|escape}</a>
    <a href="#set-keys">{$L.sett_license_key_format|escape}</a>
    <a href="#set-defaults">{$L.sett_global_defaults|escape}</a>
    <a href="#set-binding">{$L.sett_binding_resets|escape}</a>
    <a href="#set-service">{$L.sett_service_status_mapping|escape}</a>
    <a href="#set-api">{$L.sett_api_security_rate_limiting|escape}</a>
    <a href="#set-abuse">{$L.sett_abuse_detection|escape}</a>
    <a href="#set-client">{$L.sett_client_area|escape}</a>
    <a href="#set-logging">{$L.sett_logging_retention|escape}</a>
    <a href="#set-emails">{$L.sett_customer_emails|escape}</a>
    <span class="lfg-sidenav-split" aria-hidden="true"></span>
    <a href="#set-templates">{$L.sett_email_templates|escape}</a>
    <a href="#set-master">{$L.sett_master_key|escape}</a>
    <a href="#set-signing">{$L.sett_offline_signing_keys|escape}</a>
    <a href="#set-migrations">{$L.sett_applied_migrations|escape}</a>
  </nav>

  <div class="lfg-settings-main">

<form method="post" action="{$moduleLink|escape}&amp;page=settings" class="form-horizontal">
  <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
  <input type="hidden" name="do" value="settings.save">

  <div class="lfg-card" id="set-general">
    <div class="panel-heading"><strong>{$L.sett_general|escape}</strong><span class="lfg-muted">{$L.sett_where_your_software_talks_to_this|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_licensing_api_url|escape}</label>
        <div class="col-sm-7"><input type="text" name="s_license_server_url" class="form-control" value="{$settings.license_server_url|escape}" placeholder="{$apiUrl|escape}">
          <span class="help-block lfg-muted">{$L.sett_advertised_to_client_sdks_leave_blank|escape}</span>
        </div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-7">
          <label class="checkbox-inline"><input type="checkbox" name="s_module_enabled" value="1"{if $settings.module_enabled} checked{/if}>{$L.sett_licensing_enabled_uncheck_for_maintenance|escape}</label>
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-keys">
    <div class="panel-heading"><strong>{$L.sett_license_key_format|escape}</strong><span class="lfg-muted">{$L.sett_the_shape_of_newly_issued_keys|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_prefix|escape}</label>
        <div class="col-sm-2"><input type="text" name="s_key_prefix" class="form-control" value="{$settings.key_prefix|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_segments|escape}</label>
        <div class="col-sm-1"><input type="number" name="s_key_segments" class="form-control" value="{$settings.key_segments|escape}" min="1" max="12"></div>
        <label class="col-sm-2 control-label">{$L.sett_segment_length|escape}</label>
        <div class="col-sm-1"><input type="number" name="s_key_segment_length" class="form-control" value="{$settings.key_segment_length|escape}" min="2" max="12"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_separator|escape}</label>
        <div class="col-sm-2"><input type="text" name="s_key_separator" class="form-control" value="{$settings.key_separator|escape}" maxlength="3"></div>
        <label class="col-sm-2 control-label">{$L.sett_alphabet|escape}</label>
        <div class="col-sm-3">
          <select name="s_key_alphabet" class="form-control">
            <option value="crockford"{if $settings.key_alphabet eq 'crockford'} selected{/if}>Crockford base32 (no I/L/O/U)</option>
            <option value="hex"{if $settings.key_alphabet eq 'hex'} selected{/if}>{$L.sett_hexadecimal|escape}</option>
            <option value="alnum"{if $settings.key_alphabet eq 'alnum'} selected{/if}>{$L.sett_alphanumeric|escape}</option>
          </select>
        </div>
        <div class="col-sm-2"><label class="checkbox-inline"><input type="checkbox" name="s_key_uppercase" value="1"{if $settings.key_uppercase} checked{/if}>{$L.sett_uppercase|escape}</label></div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-defaults">
    <div class="panel-heading"><strong>{$L.sett_global_defaults|escape}</strong><span class="lfg-muted">{$L.sett_used_whenever_a_product_leaves_a|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_duration_days|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_default_duration_days" class="form-control" value="{$settings.default_duration_days|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_trial_days|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_default_trial_days" class="form-control" value="{$settings.default_trial_days|escape}"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_activation_limit|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_default_max_activations" class="form-control" value="{$settings.default_max_activations|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_reissue_limit|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_default_max_reissues" class="form-control" value="{$settings.default_max_reissues|escape}"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_grace_period_days|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_default_grace_days" class="form-control" value="{$settings.default_grace_days|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_check_interval_h|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_validation_interval_hours" class="form-control" value="{$settings.validation_interval_hours|escape}"></div>
        <label class="col-sm-1 control-label">{$L.sett_offline_d|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_offline_validity_days" class="form-control" value="{$settings.offline_validity_days|escape}"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_offline_d_help|escape}</span></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_release_dir|escape}</label>
        <div class="col-sm-7"><input type="text" name="s_release_dir" class="form-control" value="{$settings.release_dir|escape}" placeholder="/var/lib/licenseforge/releases">
          {if $releaseDirProblem}
            <span class="lfg-pill lfg-pill--bad">{$L.sett_release_dir_refused|escape}</span>
          {elseif $settings.release_dir}
            <span class="lfg-pill lfg-pill--ok">{$L.sett_release_dir_ok|escape}</span>
          {/if}
          <span class="help-block lfg-muted">{$L.sett_release_dir_help|escape}</span>

          {if $settings.release_dir}
            <div class="alert alert-warning lfg-mt-sm">{$L.sett_release_dir_alias_warn|escape}</div>
          {/if}
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-binding">
    <div class="panel-heading"><strong>{$L.sett_binding_resets|escape}</strong><span class="lfg-muted">{$L.sett_what_ties_a_license_to_an|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <label class="checkbox-inline"><input type="checkbox" name="s_lock_domain" value="1"{if $settings.lock_domain} checked{/if}>{$L.sett_domain_lock|escape}</label>
          <label class="checkbox-inline"><input type="checkbox" name="s_lock_ip" value="1"{if $settings.lock_ip} checked{/if}>{$L.sett_ip_lock|escape}</label>
          <label class="checkbox-inline"><input type="checkbox" name="s_lock_directory" value="1"{if $settings.lock_directory} checked{/if}>{$L.sett_directory_lock|escape}</label>
          <label class="checkbox-inline"><input type="checkbox" name="s_lock_machine" value="1"{if $settings.lock_machine} checked{/if}>{$L.sett_machine_lock|escape}</label>
        </div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <label class="checkbox-inline"><input type="checkbox" name="s_allow_subdomains" value="1"{if $settings.allow_subdomains} checked{/if}>{$L.sett_allow_subdomains|escape}</label>
          <label class="checkbox-inline"><input type="checkbox" name="s_allow_www_normalisation" value="1"{if $settings.allow_www_normalisation} checked{/if}>{$L.sett_treat_www_as_the_same_host|escape}</label>
          <label class="checkbox-inline"><input type="checkbox" name="s_allow_local_domains" value="1"{if $settings.allow_local_domains} checked{/if}>{$L.sett_allow_dev_staging_domains|escape}</label>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_reissue_cooldown_h|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_reissue_cooldown_hours" class="form-control" value="{$settings.reissue_cooldown_hours|escape}"></div>
        <div class="col-sm-6">
          <label class="lfg-mt-xs checkbox-inline"><input type="checkbox" name="s_reissue_self_service" value="1"{if $settings.reissue_self_service} checked{/if}>{$L.sett_customer_self_service|escape}</label>
          <label class="lfg-mt-xs checkbox-inline"><input type="checkbox" name="s_reissue_requires_approval" value="1"{if $settings.reissue_requires_approval} checked{/if}>{$L.sett_require_approval|escape}</label>
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-service">
    <div class="panel-heading">
      <strong>{$L.sett_service_status_mapping|escape}</strong>
      <span class="lfg-muted">{$L.sett_what_happens_to_a_license_when|escape}</span>
    </div>
    <div class="lfg-card-body">

      {foreach from=$statusMap item=row}
      <div class="form-group">
        <label class="col-sm-3 control-label">Service {$row.service|escape}</label>
        <div class="col-sm-3">
          <select name="s_{$row.key|escape}" class="form-control">
            {foreach from=$row.options item=option}
              <option value="{$option.value|escape}"{if $settings[$row.key] eq $option.value} selected{/if}>License becomes {$option.label|escape}</option>
            {/foreach}
          </select>
        </div>

        {* $row.explain is a shipped string containing markup, never user input. *}
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$row.explain}</span></div>
      </div>
      {/foreach}
    </div>
  </div>

  <div class="lfg-card" id="set-api">
    <div class="panel-heading"><strong>{$L.sett_api_security_rate_limiting|escape}</strong><span class="lfg-muted">{$L.sett_protects_the_public_licensing_endpoint|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">

          <label class="checkbox-inline"><input type="checkbox" name="s_require_api_auth" value="1"{if $settings.require_api_auth} checked{/if}> <strong>{$L.sett_require_signed_api_requests|escape}</strong>{$L.sett_recommended|escape}</label>
          {if $settings.require_api_auth}
            <span class="help-block lfg-muted">{$L.sett_unsigned_warning|escape}</span>
            <div class="lfg-inline">
              <input type="text" name="unsigned_confirm" class="lfg-w260 form-control input-sm"
                     placeholder="{$L.sett_unsigned_placeholder|escape}" autocomplete="off">
            </div>
          {elseif $unsigned_permitted}
            <div class="alert alert-danger lfg-mt8">
              <strong>{$L.sett_unsigned_active|escape}</strong> {$L.sett_unsigned_active_why|escape}
            </div>
          {else}
            <div class="alert alert-warning lfg-mt8">
              <strong>{$L.sett_unsigned_blocked|escape}</strong> {$L.sett_unsigned_blocked_why|escape}
              <pre class="lfg-mt8">define('LICENSEFORGE_ALLOW_UNSIGNED', true);</pre>
            </div>
          {/if}
        </div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">

          <label class="checkbox-inline"><input type="checkbox" name="s_require_install_proof" value="1"{if $settings.require_install_proof} checked{/if}><strong>{$L.sett_require_install_proof|escape}</strong></label>
          {if $installProof.required}
            <span class="lfg-pill lfg-pill--ok">{$L.sett_proof_closed|escape}</span>
          {elseif $installProof.active === 0}
            <span class="lfg-pill">{$L.sett_proof_no_installs|escape}</span>
          {elseif $installProof.unproven > 0}
            <span class="lfg-pill lfg-pill--warn">{$L.sett_proof_pending|escape}: {$installProof.unproven|escape}</span>
            <span class="help-block lfg-muted">{$L.sett_proof_pending_help|escape}</span>
          {else}
            <span class="lfg-pill lfg-pill--warn">{$L.sett_proof_ready|escape}</span>
            <span class="help-block lfg-muted">{$L.sett_proof_ready_help|escape}</span>
          {/if}
          <span class="help-block lfg-muted">{$L.sett_require_install_proof_help|escape}</span>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_signature_algorithm|escape}</label>
        <div class="col-sm-3">
          <select name="s_signature_algorithm" class="form-control">
            <option value="auto"{if $settings.signature_algorithm eq 'auto'} selected{/if}>{$L.sett_automatic|escape}</option>
            <option value="ed25519"{if $settings.signature_algorithm eq 'ed25519'} selected{/if}>Ed25519{if !$sodium} (libsodium unavailable){/if}</option>
            <option value="rsa"{if $settings.signature_algorithm eq 'rsa'} selected{/if}>RSA-2048 / SHA-256</option>
          </select>
        </div>
        <label class="col-sm-2 control-label">{$L.sett_max_clock_skew_s|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_request_max_skew_seconds" class="form-control" value="{$settings.request_max_skew_seconds|escape}"></div>
      </div>
      <div class="form-group">

        <label class="col-sm-3 control-label">{$L.sett_trusted_proxies|escape}</label>
        <div class="col-sm-5"><input type="text" name="s_trusted_proxies" class="form-control" value="{$settings.trusted_proxies|escape}" placeholder="{$L.sett_10_0_0_0_8_2001|escape}">
          <span class="help-block lfg-muted">{$L.sett_proxy_headers_are_honoured_only_when|escape}</span>
        </div>
        <label class="col-sm-2 control-label">{$L.sett_proxy_header|escape}</label>
        <div class="col-sm-2"><input type="text" name="s_trusted_proxy_header" class="form-control" value="{$settings.trusted_proxy_header|escape}"></div>
      </div>
      <div class="form-group">

        <label class="col-sm-3 control-label">{$L.sett_rate_window_s|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_window_seconds" class="form-control" value="{$settings.rate_window_seconds|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_validate_ip|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_limit_validate_ip" class="form-control" value="{$settings.rate_limit_validate_ip|escape}"></div>
        <label class="col-sm-1 control-label">{$L.sett_activate_ip|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_limit_activate_ip" class="form-control" value="{$settings.rate_limit_activate_ip|escape}"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_activate_key_per_hour|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_limit_activate_key" class="form-control" value="{$settings.rate_limit_activate_key|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_failures_ip|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_limit_failed_ip" class="form-control" value="{$settings.rate_limit_failed_ip|escape}"></div>
        <label class="col-sm-1 control-label">{$L.sett_reissue_client|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_rate_limit_reissue_client" class="form-control" value="{$settings.rate_limit_reissue_client|escape}"></div>
      </div>
      <div class="form-group">

        <label class="col-sm-3 control-label">{$L.sett_fail_closed|escape}</label>
        <div class="col-sm-9">
          <label class="checkbox-inline">
            <input type="checkbox" name="s_rate_limit_fail_closed" value="1"{if $settings.rate_limit_fail_closed} checked{/if}>
            {$L.sett_fail_closed_label|escape}
          </label>
          <span class="help-block lfg-muted">{$L.sett_fail_closed_help|escape}</span>
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-abuse">
    <div class="panel-heading"><strong>{$L.sett_abuse_detection|escape}</strong><span class="lfg-muted">{$L.sett_spots_key_sharing_and_enumeration_findings|escape}</span></div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_window_hours|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_abuse_window_hours" class="form-control" value="{$settings.abuse_window_hours|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_failure_threshold|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_abuse_failed_threshold" class="form-control" value="{$settings.abuse_failed_threshold|escape}"></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_distinct_domains|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_abuse_domain_changes" class="form-control" value="{$settings.abuse_domain_changes|escape}"></div>
        <label class="col-sm-2 control-label">{$L.sett_distinct_ips|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_abuse_ip_changes" class="form-control" value="{$settings.abuse_ip_changes|escape}"></div>
        <div class="col-sm-3"><label class="lfg-mt-xs checkbox-inline"><input type="checkbox" name="s_abuse_auto_suspend" value="1"{if $settings.abuse_auto_suspend} checked{/if}>{$L.sett_auto_suspend_on_high_severity|escape}</label></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_auto_suspend_help|escape}</span></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_install_ip_flips|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_abuse_install_ip_flips" class="form-control" value="{$settings.abuse_install_ip_flips|escape}"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_install_ip_flips_help|escape}</span></div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-client">
    <div class="panel-heading">
      <strong>{$L.sett_client_area|escape}</strong>
      <span class="lfg-muted">{$L.sett_what_customers_see_on_their_product|escape}</span>
    </div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_service_list|escape}</label>
        <div class="col-sm-9">
          <label class="lfg-mt-xs checkbox-inline"><input type="checkbox" name="s_show_key_in_service_list" value="1"{if $settings.show_key_in_service_list} checked{/if}>{$L.sett_show_the_license_key_under_the|escape}</label>
          <span class="help-block lfg-muted">{$L.sett_saves_customers_with_several_licensed_products|escape}</span>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_downloads|escape}</label>
        <div class="col-sm-9">
          <label class="lfg-mt-xs checkbox-inline"><input type="checkbox" name="s_download_protection" value="1"{if $settings.download_protection} checked{/if}>{$L.sett_hide_product_downloads_while_the_license|escape}</label>
          <span class="help-block lfg-muted">{$L.sett_release_files_are_ordinary_whmcs_downloads|escape}<em>Support &rsaquo; Downloads</em>{$L.sett_and_associate_them_with_the_product|escape}</span>
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-logging">
    <div class="panel-heading">
      <strong>{$L.sett_logging_retention|escape}</strong>
      <span class="lfg-muted">{$L.sett_how_much_history_to_keep_before|escape}</span>
    </div>
    <div class="lfg-card-body">
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_check_log_retention|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_validation_log_retention" class="form-control" value="{$settings.validation_log_retention|escape}"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_days_of_individual_activation_check_records|escape}</span></div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_audit_log_retention|escape}</label>
        <div class="col-sm-2"><input type="number" name="s_audit_log_retention" class="form-control" value="{$settings.audit_log_retention|escape}"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_days_of_who_did_what_history|escape}</span></div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <label class="checkbox-inline"><input type="checkbox" name="s_log_validations" value="1"{if $settings.log_validations} checked{/if}>{$L.sett_log_successful_checks_as_well_as|escape}</label>
          <span class="help-block lfg-muted">{$L.sett_off_records_only_refusals_which_keeps|escape}</span>
        </div>
      </div>
    </div>
  </div>

  <div class="lfg-card" id="set-emails">
    <div class="panel-heading">
      <strong>{$L.sett_customer_emails|escape}</strong>
      <span class="lfg-muted">{$L.sett_sent_through_whmcs_using_templates_you|escape}</span>
    </div>
    <div class="lfg-card-body">
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <label class="checkbox-inline"><input type="checkbox" name="s_notify_enabled" value="1"{if $settings.notify_enabled} checked{/if}> <strong>{$L.sett_send_licensing_emails|escape}</strong></label>
          <span class="help-block lfg-muted">{$L.sett_master_switch_off_silences_every_email|escape}</span>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_expiry_reminders|escape}</label>
        <div class="col-sm-3"><input type="text" name="s_notify_expiry_days" class="form-control" value="{$settings.notify_expiry_days|escape}" placeholder="30,14,7,1"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_days_before_expiry_to_remind_comma|escape}</span></div>
      </div>

      <div class="form-group">
        <label class="col-sm-3 control-label">{$L.sett_notify_max_per_run|escape}</label>
        <div class="col-sm-3"><input type="number" name="s_notify_max_per_run" class="form-control" value="{$settings.notify_max_per_run|escape}" min="0"></div>
        <div class="col-sm-offset-3 col-sm-9"><span class="help-block lfg-muted">{$L.sett_notify_max_per_run_help|escape}</span></div>
      </div>
    </div>
  </div>

  <div class="lfg-save-bar">
    <button type="submit" class="btn btn-primary">{$L.sett_save_settings|escape}</button>
  </div>
</form>

<div class="lfg-card" id="set-templates">
  <div class="panel-heading">
    <strong>{$L.sett_email_templates|escape}</strong>
    <span class="lfg-muted">{$L.sett_installed_into_whmcs_edited_like_any|escape}</span>
  </div>
  <div class="lfg-pb0 panel-body">

    {if $emailMissing}
      <div class="alert alert-warning">
        <strong>{$emailMissing|escape} template{if $emailMissing != 1}s{/if} {if $emailMissing == 1}is{else}are{/if} missing.</strong>{$L.sett_the_emails_that_use_them_cannot|escape}</div>
    {/if}
  </div>

  <table class="lfg-table">
    <thead>
      <tr><th>{$L.sett_email_2|escape}</th><th>{$L.sett_sent_when|escape}</th><th>{$L.sett_whmcs_template|escape}</th><th>{$L.sett_merge_fields|escape}</th><th></th></tr>
    </thead>
    <tbody>
    {foreach from=$emailTemplates item=email}
      <tr>
        <td><strong>{$email.label|escape}</strong></td>
        <td class="lfg-muted">{$email.when|escape}</td>
        <td>
          <span class="lfg-key">{$email.name|escape}</span><br>
          {if $email.exists}
            <span class="lfg-pill lfg-pill--ok">{$L.sett_installed|escape}</span>
          {else}
            <span class="lfg-pill lfg-pill--bad">{$L.sett_missing|escape}</span>
          {/if}
        </td>

        <td class="lfg-muted">
          {foreach from=$email.fields item=field name=f}<code>{ldelim}${$field|escape}{rdelim}</code>{if !$smarty.foreach.f.last} {/if}{/foreach}
        </td>
        <td>
          {if $email.exists}
            <a class="btn btn-xs lfg-btn--route" href="{$email.editUrl|escape}" target="_blank">{$L.sett_edit_wording|escape}</a>
          {/if}
        </td>
      </tr>
    {/foreach}
    </tbody>
  </table>

  <div class="panel-footer">
    <form method="post" action="{$moduleLink|escape}&amp;page=settings" class="lfg-inline form-inline">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="emails.install">
      <button class="btn btn-primary btn-sm">{$L.sett_install_missing_templates|escape}</button>
    </form>
    <form method="post" action="{$moduleLink|escape}&amp;page=settings" class="lfg-inline lfg-ml-sm form-inline"
          data-lf-confirm="{$L.sett_restore_every_licensing_template_to_the|escape}">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="emails.install">
      <input type="hidden" name="reset" value="1">
      <button class="btn btn-sm lfg-btn--caution">{$L.sett_restore_shipped_wording|escape}</button>
    </form>
    <span class="lfg-ml-md lfg-muted">
      Every licensing template also has the shared fields:
      {foreach from=$mergeFields key=field item=meaning name=m}<code>{ldelim}${$field|escape}{rdelim}</code>{if !$smarty.foreach.m.last}, {/if}{/foreach}
    </span>
  </div>
</div>

<div class="lfg-card" id="set-master">
  <div class="panel-heading"><strong>{$L.sett_master_key|escape}</strong></div>
  <div class="lfg-card-body">
    {if $keyIntegrity.status == 'ok'}
      <span class="lfg-pill lfg-pill--ok">{$L.sett_master_key_ok|escape}</span>
    {elseif $keyIntegrity.status == 'recorded'}
      <span class="lfg-pill lfg-pill--ok">{$L.sett_master_key_recorded|escape}</span>
      <span class="help-block lfg-muted">{$L.sett_master_key_recorded_help|escape}</span>
    {elseif $keyIntegrity.status == 'unrecorded'}
      <span class="lfg-pill lfg-pill--warn">{$L.sett_master_key_unknown|escape}</span>
      <span class="help-block lfg-muted">{$keyIntegrity.message|escape}</span>
    {elseif $keyIntegrity.status == 'changed'}
      <div class="alert alert-danger lfg-mb-sm">
        <strong>{$L.sett_master_key_changed|escape}</strong>
        {$L.sett_master_key_changed_help|escape}
      </div>
    {else}
      <span class="lfg-pill lfg-pill--warn">{$L.sett_master_key_unknown|escape}</span>
      <span class="help-block lfg-muted">{$keyIntegrity.message|escape}</span>
    {/if}
    <span class="help-block lfg-muted">{$L.sett_master_key_help|escape}</span>
  </div>
</div>

<div class="lfg-card" id="set-signing">
  <div class="panel-heading"><strong>{$L.sett_offline_signing_keys|escape}</strong><span class="lfg-muted">{$L.sett_let_your_software_verify_a_license|escape}</span></div>
  <table class="lfg-table">
    <thead><tr><th>{$L.sett_id|escape}</th><th>{$L.sett_algorithm|escape}</th><th>{$L.sett_fingerprint|escape}</th><th>{$L.sett_created|escape}</th><th>{$L.sett_status|escape}</th><th>{$L.sett_public_key|escape}</th><th></th></tr></thead>
    <tbody>
    {foreach from=$signingKeys item=key}
      <tr>
        <td>{$key.id|escape}</td>
        <td>{$key.algorithm|escape}</td>
        <td class="lfg-key">{$key.fingerprint|escape}</td>
        <td class="lfg-muted">{$key.created_at|escape}</td>
        <td>{if $key.active}<span class="lfg-pill lfg-pill--ok">active</span>{else}<span class="lfg-pill">{$L.sett_retired|escape}</span>{/if}</td>
        <td><textarea class="form-control lfg-key" rows="2" readonly data-lf-select-on-click>{$key.public_key|escape}</textarea></td>
        <td>
          {if !$key.active}
          <form method="post" action="{$moduleLink|escape}&amp;page=settings" data-lf-confirm="{$L.sett_make_this_the_active_signing_key|escape}">
            <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
            <input type="hidden" name="do" value="keys.activate">
            <input type="hidden" name="key_id" value="{$key.id|escape}">
            <button class="btn btn-xs btn-default">{$L.sett_activate|escape}</button>
          </form>
          {/if}
        </td>
      </tr>
    {foreachelse}
      <tr><td colspan="7" class="lfg-muted">{$L.sett_no_signing_keys_yet|escape}</td></tr>
    {/foreach}
    </tbody>
  </table>
  <div class="panel-footer">
    <form method="post" action="{$moduleLink|escape}&amp;page=settings" class="form-inline" data-lf-confirm="{$L.sett_generate_a_new_signing_key_and|escape}">
      <input type="hidden" name="lfg_token" value="{$csrfToken|escape}">
      <input type="hidden" name="do" value="keys.generate">
      <select name="algorithm" class="form-control input-sm">
        <option value="">{$L.sett_automatic_2|escape}</option>
        <option value="ed25519">{$L.sett_ed25519|escape}</option>
        <option value="rsa-sha256">{$L.sett_rsa_2048|escape}</option>
      </select>
      <button class="btn btn-primary btn-sm">{$L.sett_generate_signing_key|escape}</button>
      <span class="lfg-ml-md lfg-muted">{$L.sett_private_keys_are_encrypted_at_rest|escape}</span>
    </form>
  </div>
</div>

<div class="lfg-card" id="set-migrations">
  <div class="panel-heading"><strong>{$L.sett_applied_migrations|escape}</strong><span class="lfg-muted">{$L.sett_database_changes_this_install_has_run|escape}</span></div>
  <table class="lfg-table">
    {foreach from=$migrations item=migration}
      <tr><td class="lfg-key">{$migration->migration|escape}</td><td class="lfg-muted">{$migration->applied_at|escape}</td></tr>
    {/foreach}
  </table>
</div>

  </div>
</div>

</div>
