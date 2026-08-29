<?php
/**
 * LicenseForge - English language file.
 *
 * Copy this file to translate the module: name it after the WHMCS language
 * (french.php, german.php, spanish.php …) and translate the values only.
 * Any string you leave out falls back to the English below, so a partial
 * translation is safe to ship.
 *
 * Keys are grouped by where the text appears. Placeholders are written as
 * :name and must survive translation.
 *
 * The customer-facing emails are ordinary WHMCS email templates, so they are
 * translated in WHMCS itself rather than here.
 *
 * @package LicenseForge
 * @author  Ahmad Abu Assab (Fast Hive) <https://fasthive.com>
 */

// ---------------------------------------------------------------- client area

$_ADDONLANG['client_your_license']      = 'Your license';
$_ADDONLANG['client_license_key']       = 'License key';
$_ADDONLANG['client_copy_key']          = 'Copy key';
$_ADDONLANG['client_copied']            = 'Copied';
$_ADDONLANG['client_no_license']        = 'No license is associated with this service.';
$_ADDONLANG['client_license_pending']   = 'Your license is being prepared and will appear here shortly.';

$_ADDONLANG['client_expires']           = 'Expires';
$_ADDONLANG['client_never_expires']     = 'Does not expire';
$_ADDONLANG['client_expires_in_days']   = 'in :days days';
$_ADDONLANG['client_expires_in_day']    = 'in 1 day';
$_ADDONLANG['client_trial']             = 'Trial';
$_ADDONLANG['client_installed_on']      = 'Installed on';
$_ADDONLANG['client_set_on_activation'] = 'Set on first activation';
$_ADDONLANG['client_installations']     = 'Installations';
$_ADDONLANG['client_x_of_y']            = ':used of :limit';
$_ADDONLANG['client_version']           = 'Version';
$_ADDONLANG['client_version_available'] = ':version available';
$_ADDONLANG['client_downloads'] = 'Downloads';
$_ADDONLANG['client_included']          = 'Included';

$_ADDONLANG['client_grace_warning']     = 'This license expired but keeps working until :date. Renew the service to restore it.';
$_ADDONLANG['client_expiring_warning']  = 'This license expires in :days days. It renews with the service.';
$_ADDONLANG['client_expiring_tomorrow'] = 'This license expires tomorrow. It renews with the service.';

$_ADDONLANG['client_col_location']      = 'Location';
$_ADDONLANG['client_col_version']       = 'Version';
$_ADDONLANG['client_col_activated']     = 'Activated';
$_ADDONLANG['client_col_last_checkin']  = 'Last check-in';
$_ADDONLANG['client_actions']           = 'Actions';
$_ADDONLANG['client_deactivate']        = 'Deactivate';
$_ADDONLANG['client_deactivate_confirm'] = 'Deactivate this installation? The software will stop working there until you activate it again.';
$_ADDONLANG['client_nothing_using']     = 'Nothing is using this license yet.';
$_ADDONLANG['client_enter_key_hint']    = 'Enter your key when you install the software and this installation will appear here.';

$_ADDONLANG['client_reset_title']       = 'Reset this license';
$_ADDONLANG['client_reset_button']      = 'Reset license';
$_ADDONLANG['client_reset_confirm']     = 'Reset this license? Every current installation stops working immediately.';

// Messages the panel shows after an action.
$_ADDONLANG['client_msg_deactivated']   = 'Installation deactivated. Its activation slot is free again.';
$_ADDONLANG['client_msg_not_found']     = 'That installation could not be found.';
$_ADDONLANG['client_msg_reset_done']    = 'License reset. Every installation has been deactivated - activate the software wherever you need it.';
$_ADDONLANG['client_msg_reset_pending'] = 'Reset requested. We will email you once it has been approved.';
$_ADDONLANG['client_msg_reset_denied']  = 'Resetting is not available for this license. Please open a ticket.';
$_ADDONLANG['client_msg_unknown']       = 'Unknown action.';
$_ADDONLANG['client_msg_unavailable']   = 'License details are temporarily unavailable.';

// Services list.
$_ADDONLANG['client_copy']              = 'Copy';
$_ADDONLANG['client_copy_aria']         = 'Copy license key';

// ---------------------------------------------------------------- admin: nav

$_ADDONLANG['nav_overview']    = 'Overview';
$_ADDONLANG['nav_licenses']    = 'Licenses';
$_ADDONLANG['nav_products']    = 'Products';
$_ADDONLANG['nav_resets']      = 'Resets';
$_ADDONLANG['nav_abuse']       = 'Abuse';
$_ADDONLANG['nav_api']         = 'API';
$_ADDONLANG['nav_audit_log']   = 'Audit log';
$_ADDONLANG['nav_settings']    = 'Settings';

// ---------------------------------------------------------------- admin: overview

$_ADDONLANG['ov_attention_one']      = '1 thing needs attention.';
$_ADDONLANG['ov_attention_many']     = ':count things need attention.';
$_ADDONLANG['ov_attention_rest_ok']  = 'Everything else is running normally.';
$_ADDONLANG['ov_all_clear']          = 'Nothing needs attention.';
$_ADDONLANG['ov_all_clear_detail']   = 'No reset requests, no unresolved abuse events, and every product and email template is configured.';

$_ADDONLANG['ov_licenses']           = 'Licenses';
$_ADDONLANG['ov_in_total']           = ':count in total';
$_ADDONLANG['ov_active']             = 'Active';
$_ADDONLANG['ov_pending']            = 'Pending';
$_ADDONLANG['ov_suspended']          = 'Suspended';
$_ADDONLANG['ov_expired']            = 'Expired';
$_ADDONLANG['ov_revoked']            = 'Revoked';
$_ADDONLANG['ov_expiring_30d']       = 'Expiring 30d';

$_ADDONLANG['ov_traffic']            = 'Traffic';
$_ADDONLANG['ov_last_24_hours']      = 'last 24 hours';
$_ADDONLANG['ov_installations']      = 'Installations';
$_ADDONLANG['ov_checks']             = 'Checks';
$_ADDONLANG['ov_refused']            = 'Refused';
$_ADDONLANG['ov_resets']             = 'Resets';

$_ADDONLANG['ov_newest_licenses']    = 'Newest licenses';
$_ADDONLANG['ov_all_licenses']       = 'All licenses';
$_ADDONLANG['ov_suspicious']         = 'Suspicious activity';
$_ADDONLANG['ov_all_events']         = 'All events';
$_ADDONLANG['ov_recent_checks']      = 'Recent checks';
$_ADDONLANG['ov_refused_checks']     = 'Refused checks';
$_ADDONLANG['ov_what_customers_hit'] = 'what customers are hitting';

$_ADDONLANG['ov_empty_licenses']       = 'No licenses yet';
$_ADDONLANG['ov_empty_licenses_hint']  = 'They appear here as soon as a customer orders a product using the License Forge module.';
$_ADDONLANG['ov_empty_abuse']          = 'Nothing flagged';
$_ADDONLANG['ov_empty_abuse_hint']     = 'Key sharing, domain churn and enumeration attempts would be listed here.';
$_ADDONLANG['ov_empty_traffic']        = 'No licensing traffic yet';
$_ADDONLANG['ov_empty_traffic_hint']   = 'Every activation and check-in from your software will appear here.';
$_ADDONLANG['ov_empty_refused']        = 'Nothing refused';
$_ADDONLANG['ov_empty_refused_hint']   = 'Domain mismatches and activation limits would show up here first.';

$_ADDONLANG['ov_maintenance_hint']   = 'Expiry, grace periods, reminders, the abuse sweep and log retention all run on the daily cron.';
$_ADDONLANG['ov_run_maintenance']    = 'Run maintenance now';
$_ADDONLANG['ov_run_confirm']        = 'Run licensing maintenance now?';

// Attention items.
$_ADDONLANG['att_maintenance_backlog'] = 'Maintenance did not finish in one pass';
$_ADDONLANG['att_maintenance_backlog_why'] = 'More licenses were due than one run processes, so some are still being served in their old state.';
$_ADDONLANG['att_run_now']           = 'Run now';
$_ADDONLANG['att_master_key_changed'] = 'The master key has changed';
$_ADDONLANG['att_master_key_changed_why'] = 'Every stored API secret and signing key is unreadable until it is restored.';
$_ADDONLANG['att_resets_waiting']    = ':count reset requests awaiting approval';
$_ADDONLANG['att_resets_waiting_one'] = '1 reset request awaiting approval';
$_ADDONLANG['att_resets_why']        = 'Customers are waiting to move their software.';
$_ADDONLANG['att_review']            = 'Review';
$_ADDONLANG['att_abuse']             = ':count unresolved abuse events';
$_ADDONLANG['att_abuse_one']         = '1 unresolved abuse event';
$_ADDONLANG['att_abuse_why']         = 'Possible key sharing or enumeration.';
$_ADDONLANG['att_investigate']       = 'Investigate';
$_ADDONLANG['att_products']          = ':count products reading the wrong settings';
$_ADDONLANG['att_products_one']      = '1 product reading the wrong settings';
$_ADDONLANG['att_products_why']      = 'Their options shifted in an upgrade and need re-saving.';
$_ADDONLANG['att_fix']               = 'Fix';
$_ADDONLANG['att_emails']            = ':count email templates missing';
$_ADDONLANG['att_emails_one']        = '1 email template missing';
$_ADDONLANG['att_emails_why']        = 'Those notifications cannot send at all.';
$_ADDONLANG['att_install']           = 'Install';
$_ADDONLANG['att_disabled']          = 'Licensing is switched off';
$_ADDONLANG['att_disabled_why']      = 'The API refuses every request and no licenses are issued.';
$_ADDONLANG['att_turn_on']           = 'Turn on';
$_ADDONLANG['att_unsigned']          = 'The licensing API is accepting unsigned requests';
$_ADDONLANG['att_unsigned_why']      = 'Anyone who learns a license key can call the API as if they were the licensed installation. Turn signing back on before going live.';
$_ADDONLANG['att_require_signing']   = 'Require signing';
$_ADDONLANG['att_unproven']          = ':count installations are not proving their identity';
$_ADDONLANG['att_unproven_one']      = '1 installation is not proving its identity';
$_ADDONLANG['att_unproven_why']      = 'They predate per-installation credentials, so anyone who learns their installation ID can present it. Each is fixed permanently the next time its software calls activate.';
$_ADDONLANG['att_unproven_clear']    = 'Every installation proves its identity, but proof is not required yet';
$_ADDONLANG['att_unproven_clear_why'] = 'Nothing is left on the grandfathered path, so the exemption can be closed with no customer affected. Until it is, an installation that never re-activates could still be claimed without proof.';
$_ADDONLANG['att_require_proof']     = 'Require proof';
$_ADDONLANG['att_no_credential'] = 'No API credential is enabled';
$_ADDONLANG['att_no_credential_why'] = 'Your software cannot authenticate, so every activation and check-in is refused. The credential created at install is disabled until you scope it to the products it ships with and enable it.';
$_ADDONLANG['att_credential_open']     = ':count API credentials may act on every product';
$_ADDONLANG['att_credential_open_one'] = '1 API credential may act on every product';
$_ADDONLANG['att_credential_open_why'] = 'A credential shipped inside one product can activate and check licences belonging to any other product on this server. Scope it to the products it ships with; leave it open only for a server-side integration that genuinely needs every product.';
$_ADDONLANG['att_unsigned_blocked']     = 'Request signing is switched off in settings, but the server has not allowed it';
$_ADDONLANG['att_unsigned_blocked_why'] = 'The API is still requiring signed requests, so unsigned clients are being refused. This is the safe outcome, but it is not what the setting says: either turn signing back on to match, or define LICENSEFORGE_ALLOW_UNSIGNED in configuration.php if unsigned really is intended.';

// ---------------------------------------------------------------- admin: shared

// ---------------------------------------------------------------- admin: pages

$_ADDONLANG['abus_open_alerts'] = 'Open alerts';
$_ADDONLANG['abus_resolved'] = 'Resolved';
$_ADDONLANG['abus_when'] = 'When';
$_ADDONLANG['abus_severity'] = 'Severity';
$_ADDONLANG['abus_signal'] = 'Signal';
$_ADDONLANG['abus_summary'] = 'Summary';
$_ADDONLANG['abus_license'] = 'License';
$_ADDONLANG['abus_ip'] = 'IP';
$_ADDONLANG['abus_mark_resolved'] = 'Mark resolved';
$_ADDONLANG['abus_nothing_to_show'] = 'Nothing to show.';
$_ADDONLANG['abus_signals_are_derived_only_from_licensing'] = 'Signals are derived only from licensing traffic the module already records - activations, validations and reissues. Thresholds and the optional automatic suspension are configured on the Settings tab.';
$_ADDONLANG['cred_licensing_endpoint'] = 'Licensing endpoint:';
$_ADDONLANG['cred_signing_help']     = 'Requests are signed with HMAC-SHA256 over METHOD, endpoint, timestamp, nonce and the SHA-256 of the body, joined by newlines, and sent as the X-LF-Key, X-LF-Timestamp, X-LF-Nonce and X-LF-Signature headers.';
$_ADDONLANG['cred_signing_manual']   = 'The manual covers the scheme with worked examples, and lists every error code your software may receive.';
$_ADDONLANG['cred_api_credentials'] = 'API credentials';
$_ADDONLANG['cred_name'] = 'Name';
$_ADDONLANG['cred_key'] = 'Key';
$_ADDONLANG['cred_scopes'] = 'Scopes';
$_ADDONLANG['cred_ip_allow_list'] = 'IP allow list';
$_ADDONLANG['cred_requests'] = 'Requests';
$_ADDONLANG['cred_last_used'] = 'Last used';
$_ADDONLANG['cred_status'] = 'Status';
$_ADDONLANG['cred_rotate'] = 'Rotate';
$_ADDONLANG['cred_delete'] = 'Delete';
$_ADDONLANG['cred_products'] = 'Products';
$_ADDONLANG['cred_product_allow_list'] = 'Product allow list';
$_ADDONLANG['cred_products_blank_any'] = 'product IDs';
$_ADDONLANG['cred_all_products'] = 'All products';
$_ADDONLANG['cred_product_allow_list_help'] = 'Product IDs this credential may activate and check, comma separated. A blank list authorises nothing unless "All products" is ticked: an absent restriction is not a decision, and it used to fail open. Authentication only ever proved that a caller holds a valid credential, never that it was entitled to the licence in front of it, so a credential shipped inside one product could act on another product\'s licences - the licence key was the only thing between them, and a key is a bearer token that travels in support tickets and screenshots. Tick "All products" only for a server-side integration that genuinely needs every product. Credentials created before this existed were migrated with it ticked, so nothing working stopped working. This does not separate two customers of the same product, who legitimately share the credential baked into that product\'s build.';
$_ADDONLANG['cred_active'] = 'Active';
$_ADDONLANG['cred_update'] = 'Update';
$_ADDONLANG['cred_no_credentials_yet'] = 'No credentials yet.';
$_ADDONLANG['cred_create_a_credential'] = 'Create a credential';
$_ADDONLANG['cred_name_2'] = 'Name';
$_ADDONLANG['cred_scopes_2'] = 'Scopes';
$_ADDONLANG['cred_admin_warning'] = 'never ship this credential';
$_ADDONLANG['cred_ip_allow_list_2'] = 'IP allow list';
$_ADDONLANG['cred_expires'] = 'Expires';
$_ADDONLANG['cred_rate_limit'] = 'Rate limit';
$_ADDONLANG['cred_rate_limit_help'] = 'Requests this credential may make per rate window (the window is set on the Settings tab). 0 means no ceiling of its own - the per-IP limits still apply. Because the count is kept per credential, one integration exhausting its quota cannot spend another\'s.';
$_ADDONLANG['cred_create_credential'] = 'Create credential';
$_ADDONLANG['cred_rotate_the_secret_clients_using_the'] = 'Rotate the secret? Clients using the old secret will stop working immediately.';
$_ADDONLANG['cred_delete_this_credential_permanently'] = 'Delete this credential permanently?';
$_ADDONLANG['cred_name_3'] = 'Name';
$_ADDONLANG['cred_ips_cidrs_blank_any'] = 'IPs / CIDRs (blank = any)';
$_ADDONLANG['cred_rate_limit_2'] = 'rate limit';
$_ADDONLANG['cred_leave_blank_to_allow_any_source'] = 'Leave blank to allow any source IP';
$_ADDONLANG['det_all_licenses'] = '« All licenses';
$_ADDONLANG['det_in_grace_period'] = 'in grace period';
$_ADDONLANG['det_copy_key'] = 'Copy key';
$_ADDONLANG['det_product'] = 'Product';
$_ADDONLANG['det_client'] = 'Client';
$_ADDONLANG['det_service'] = 'Service';
$_ADDONLANG['det_issued'] = 'Issued';
$_ADDONLANG['det_first_activated'] = 'First activated';
$_ADDONLANG['det_expires'] = 'Expires';
$_ADDONLANG['det_activations'] = 'Activations';
$_ADDONLANG['det_reissues'] = 'Reissues';
$_ADDONLANG['det_last_check'] = 'Last check';
$_ADDONLANG['det_last_failure'] = 'Last failure';
$_ADDONLANG['det_current_version'] = 'Current version';
$_ADDONLANG['det_bound_to'] = 'Bound to';
$_ADDONLANG['det_installations'] = 'Installations';
$_ADDONLANG['det_installation'] = 'Installation';
$_ADDONLANG['det_domain_ip'] = 'Domain / IP';
$_ADDONLANG['det_version'] = 'Version';
$_ADDONLANG['det_status'] = 'Status';
$_ADDONLANG['det_last_seen'] = 'Last seen';
$_ADDONLANG['det_deactivate'] = 'Deactivate';
$_ADDONLANG['det_no_installations_recorded'] = 'No installations recorded.';
$_ADDONLANG['det_edit_license'] = 'Edit license';
$_ADDONLANG['det_expiry'] = 'Expiry';
$_ADDONLANG['det_lifetime_license'] = 'Lifetime license';
$_ADDONLANG['det_max_activations'] = 'Max activations';
$_ADDONLANG['det_max_reissues'] = 'Max reissues';
$_ADDONLANG['det_primary_domain'] = 'Primary domain';
$_ADDONLANG['det_primary_ip'] = 'Primary IP';
$_ADDONLANG['det_additional_domains'] = 'Additional domains';
$_ADDONLANG['det_additional_ips'] = 'Additional IPs';
$_ADDONLANG['det_version_rules'] = 'Version rules';
$_ADDONLANG['det_customer_notes'] = 'Customer notes';
$_ADDONLANG['det_internal_notes'] = 'Internal notes';
$_ADDONLANG['det_save_changes'] = 'Save changes';
$_ADDONLANG['det_status_2'] = 'Status';
$_ADDONLANG['det_apply_status'] = 'Apply status';
$_ADDONLANG['det_service_2'] = 'Service';
$_ADDONLANG['det_held'] = 'Held.';
$_ADDONLANG['det_service_events_cannot_change_this_license'] = 'Service events cannot change this license back. A suspended or terminated service will still restrict it further.';
$_ADDONLANG['det_release_hold'] = 'Release hold';
$_ADDONLANG['det_send_an_email'] = 'Send an email';
$_ADDONLANG['det_send_to_customer'] = 'Send to customer';
$_ADDONLANG['det_goes_to_the_license_holder_and'] = 'Goes to the license holder and is recorded in their email history. The same emails are on the client\'s product page under';
$_ADDONLANG['det_email'] = 'Email';
$_ADDONLANG['det_suspend_the_service_too'] = 'Suspend the service too';
$_ADDONLANG['det_suspending_the_service_emails_the_customer'] = 'Suspending the service emails the customer and starts WHMCS\'s termination countdown, so it is never done automatically by a licensing change.';
$_ADDONLANG['det_entitlements'] = 'Entitlements';
$_ADDONLANG['det_save_entitlements'] = 'Save entitlements';
$_ADDONLANG['det_reissue'] = 'Reissue';
$_ADDONLANG['det_also_generate_a_new_license_key'] = 'Also generate a new license key';
$_ADDONLANG['det_reissue_license'] = 'Reissue license';
$_ADDONLANG['det_reset_all_activations'] = 'Reset all activations';
$_ADDONLANG['det_delete_license'] = 'Delete license';
$_ADDONLANG['det_effective_policy'] = 'Effective policy';
$_ADDONLANG['det_domain_lock'] = 'Domain lock';
$_ADDONLANG['det_subdomains_allowed'] = '(subdomains allowed)';
$_ADDONLANG['det_ip_lock'] = 'IP lock';
$_ADDONLANG['det_directory_lock'] = 'Directory lock';
$_ADDONLANG['det_machine_lock'] = 'Machine lock';
$_ADDONLANG['det_grace_period'] = 'Grace period';
$_ADDONLANG['det_check_interval'] = 'Check interval';
$_ADDONLANG['det_offline_validity'] = 'Offline validity';
$_ADDONLANG['det_reissue_cooldown'] = 'Reissue cooldown';
$_ADDONLANG['det_validation_history'] = 'Validation history';
$_ADDONLANG['det_when'] = 'When';
$_ADDONLANG['det_endpoint'] = 'Endpoint';
$_ADDONLANG['det_result'] = 'Result';
$_ADDONLANG['det_domain'] = 'Domain';
$_ADDONLANG['det_ip'] = 'IP';
$_ADDONLANG['det_version_2'] = 'Version';
$_ADDONLANG['det_time'] = 'Time';
$_ADDONLANG['det_no_validation_traffic_yet'] = 'No validation traffic yet.';
$_ADDONLANG['det_reissue_history'] = 'Reissue history';
$_ADDONLANG['det_when_2'] = 'When';
$_ADDONLANG['det_status_3'] = 'Status';
$_ADDONLANG['det_from'] = 'From';
$_ADDONLANG['det_to'] = 'To';
$_ADDONLANG['det_by'] = 'By';
$_ADDONLANG['det_never_reissued'] = 'Never reissued.';
$_ADDONLANG['det_audit_trail'] = 'Audit trail';
$_ADDONLANG['det_when_3'] = 'When';
$_ADDONLANG['det_action'] = 'Action';
$_ADDONLANG['det_result_2'] = 'Result';
$_ADDONLANG['det_actor'] = 'Actor';
$_ADDONLANG['det_no_entries'] = 'No entries.';
$_ADDONLANG['det_service_events_cannot_change_this_license_2'] = 'Service events cannot change this license';
$_ADDONLANG['det_deactivate_this_installation'] = 'Deactivate this installation?';
$_ADDONLANG['det_one_per_line_example_com_is'] = 'One per line. *.example.com is supported.';
$_ADDONLANG['det_one_per_line_cidr_ranges_are'] = 'One per line. CIDR ranges are supported.';
$_ADDONLANG['det_reason_recorded_in_the_audit_log'] = 'Reason (recorded in the audit log)';
$_ADDONLANG['det_change_the_license_status'] = 'Change the license status?';
$_ADDONLANG['det_release_the_hold_service_events_will'] = 'Release the hold? Service events will drive this license again.';
$_ADDONLANG['det_suspend_the_whmcs_service_too_this'] = 'Suspend the WHMCS service too? This emails the customer and starts the termination countdown.';
$_ADDONLANG['det_reissue_this_license_current_installations_will'] = 'Reissue this license? Current installations will be released.';
$_ADDONLANG['det_new_domain_optional'] = 'New domain (optional)';
$_ADDONLANG['det_reason'] = 'Reason';
$_ADDONLANG['det_release_every_installation_for_this_license'] = 'Release every installation for this license?';
$_ADDONLANG['det_delete_this_license_history_is_retained'] = 'Delete this license? History is retained and it can be restored by support.';
$_ADDONLANG['lic_search_filter'] = 'Search & filter';
$_ADDONLANG['lic_all_statuses'] = 'All statuses';
$_ADDONLANG['lic_all_products'] = 'All products';
$_ADDONLANG['lic_search'] = 'Search';
$_ADDONLANG['lic_reset'] = 'Reset';
$_ADDONLANG['lic_key'] = 'Key';
$_ADDONLANG['lic_product'] = 'Product';
$_ADDONLANG['lic_client'] = 'Client';
$_ADDONLANG['lic_status'] = 'Status';
$_ADDONLANG['lic_domain'] = 'Domain';
$_ADDONLANG['lic_activations'] = 'Activations';
$_ADDONLANG['lic_expires'] = 'Expires';
$_ADDONLANG['lic_last_check'] = 'Last check';
$_ADDONLANG['lic_no_licenses_match_your_filters'] = 'No licenses match your filters.';
$_ADDONLANG['lic_bulk_action'] = 'Bulk action…';
$_ADDONLANG['lic_activate'] = 'Activate';
$_ADDONLANG['lic_suspend'] = 'Suspend';
$_ADDONLANG['lic_revoke'] = 'Revoke';
$_ADDONLANG['lic_reset_activations'] = 'Reset activations';
$_ADDONLANG['lic_delete'] = 'Delete';
$_ADDONLANG['lic_apply'] = 'Apply';
$_ADDONLANG['lic_previous'] = '« Previous';
$_ADDONLANG['lic_next'] = 'Next »';
$_ADDONLANG['lic_issue_a_license_manually'] = 'Issue a license manually';
$_ADDONLANG['lic_service'] = 'Service';
$_ADDONLANG['lic_no_services_are_waiting_for_a'] = 'No services are waiting for a license';
$_ADDONLANG['lic_only_services_on_a_product_using'] = 'Only services on a product using the License Forge module, and without a license, can be issued one. The client, product and term come from the service - that is what makes the license visible to the customer on their product page.';
$_ADDONLANG['lic_expires_2'] = 'Expires';
$_ADDONLANG['lic_lifetime'] = 'Lifetime';
$_ADDONLANG['lic_activations_2'] = 'Activations';
$_ADDONLANG['lic_reissues'] = 'Reissues';
$_ADDONLANG['lic_admin_notes'] = 'Admin notes';
$_ADDONLANG['lic_issue_license'] = 'Issue license';
$_ADDONLANG['lic_leave_the_overrides_blank_to_use'] = 'Leave the overrides blank to use the product\'s own settings.';
$_ADDONLANG['lic_key_domain_ip_client_email_service'] = 'Key, domain, IP, client, email, service ID';
$_ADDONLANG['lic_apply_this_action_to_the_selected'] = 'Apply this action to the selected licenses?';
$_ADDONLANG['lic_product_default'] = 'product default';
$_ADDONLANG['lic_product_default_2'] = 'product default';
$_ADDONLANG['lic_internal_only_optional'] = 'internal only, optional';
$_ADDONLANG['logs_all_actions'] = 'All actions';
$_ADDONLANG['logs_any_result'] = 'Any result';
$_ADDONLANG['logs_success'] = 'Success';
$_ADDONLANG['logs_failure'] = 'Failure';
$_ADDONLANG['logs_denied'] = 'Denied';
$_ADDONLANG['logs_any_actor'] = 'Any actor';
$_ADDONLANG['logs_admin'] = 'Admin';
$_ADDONLANG['logs_client'] = 'Client';
$_ADDONLANG['logs_api'] = 'API';
$_ADDONLANG['logs_system'] = 'System';
$_ADDONLANG['logs_filter'] = 'Filter';
$_ADDONLANG['logs_reset'] = 'Reset';
$_ADDONLANG['logs_when_utc'] = 'When (UTC)';
$_ADDONLANG['logs_action'] = 'Action';
$_ADDONLANG['logs_result'] = 'Result';
$_ADDONLANG['logs_actor'] = 'Actor';
$_ADDONLANG['logs_license'] = 'License';
$_ADDONLANG['logs_ip'] = 'IP';
$_ADDONLANG['logs_metadata'] = 'Metadata';
$_ADDONLANG['logs_no_entries_match_your_filters'] = 'No entries match your filters.';
$_ADDONLANG['logs_previous'] = '« Previous';
$_ADDONLANG['logs_next'] = 'Next »';
$_ADDONLANG['logs_search_actor_or_metadata'] = 'Search actor or metadata';
$_ADDONLANG['logs_license_id'] = 'License ID';
$_ADDONLANG['prod_the_licensing_options_changed_order_in'] = 'The licensing options changed order in this version, and WHMCS stores them by position. These products were configured under the old order, so eight of their settings - term, trial, activation and reissue limits, grace period, check interval and offline validity - are currently being read one slot out. Nothing errors; the policy is just wrong.';
$_ADDONLANG['prod_open_each_one_check_the_values'] = 'Open each one, check the values on the Module Settings tab read the way you intend, and save. That rewrites every slot correctly. Version rules, locks, behaviours and features were not affected.';
$_ADDONLANG['prod_product'] = 'Product';
$_ADDONLANG['prod_license_term_currently_holds'] = 'License Term currently holds';
$_ADDONLANG['prod_the_old_duration_in_days'] = '- the old duration in days';
$_ADDONLANG['prod_fix_now'] = 'Fix now';
$_ADDONLANG['prod_licensed_products'] = 'Licensed products';
$_ADDONLANG['prod_create_a_product'] = 'Create a product';
$_ADDONLANG['prod_product_2'] = 'Product';
$_ADDONLANG['prod_slug'] = 'Slug';
$_ADDONLANG['prod_term'] = 'Term';
$_ADDONLANG['prod_activations'] = 'Activations';
$_ADDONLANG['prod_reissues'] = 'Reissues';
$_ADDONLANG['prod_grace'] = 'Grace';
$_ADDONLANG['prod_locks'] = 'Locks';
$_ADDONLANG['prod_latest'] = 'Latest';
$_ADDONLANG['prod_features'] = 'Features';
$_ADDONLANG['prod_licenses'] = 'Licenses';
$_ADDONLANG['prod_no_product_uses_the_license_forge'] = 'No product uses the License Forge module yet. Create or edit a product, set its module to';
$_ADDONLANG['prod_and_configure_the_licensing_rules_on'] = ', and configure the licensing rules on the Module Settings tab.';
$_ADDONLANG['prod_no_longer_licensed'] = 'No longer licensed';
$_ADDONLANG['prod_product_3'] = 'Product';
$_ADDONLANG['prod_slug_2'] = 'Slug';
$_ADDONLANG['prod_whmcs_product'] = 'WHMCS product';
$_ADDONLANG['prod_licenses_2'] = 'Licenses';
$_ADDONLANG['prod_these_products_no_longer_use_the'] = 'These products no longer use the License Forge module. Their existing licenses keep working under the last known policy; re-assign the module to the product to manage them again.';
$_ADDONLANG['reis_pending_approval'] = 'Pending approval';
$_ADDONLANG['reis_requested'] = 'Requested';
$_ADDONLANG['reis_license'] = 'License';
$_ADDONLANG['reis_from'] = 'From';
$_ADDONLANG['reis_to'] = 'To';
$_ADDONLANG['reis_reason'] = 'Reason';
$_ADDONLANG['reis_by'] = 'By';
$_ADDONLANG['reis_approve'] = 'Approve';
$_ADDONLANG['reis_reject'] = 'Reject';
$_ADDONLANG['reis_no_requests_awaiting_approval'] = 'No requests awaiting approval.';
$_ADDONLANG['reis_recent_reissues'] = 'Recent reissues';
$_ADDONLANG['reis_when'] = 'When';
$_ADDONLANG['reis_license_2'] = 'License';
$_ADDONLANG['reis_status'] = 'Status';
$_ADDONLANG['reis_old_key'] = 'Old key';
$_ADDONLANG['reis_new_key'] = 'New key';
$_ADDONLANG['reis_from_2'] = 'From';
$_ADDONLANG['reis_to_2'] = 'To';
$_ADDONLANG['reis_by_2'] = 'By';
$_ADDONLANG['reis_ip'] = 'IP';
$_ADDONLANG['reis_no_reissues_recorded'] = 'No reissues recorded.';
$_ADDONLANG['reis_approve_this_reissue_current_installations_will'] = 'Approve this reissue? Current installations will be released.';
$_ADDONLANG['reis_reason_2'] = 'Reason';
$_ADDONLANG['sett_general'] = 'General';
$_ADDONLANG['sett_where_your_software_talks_to_this'] = '- where your software talks to this server';
$_ADDONLANG['sett_licensing_api_url'] = 'Licensing API URL';
$_ADDONLANG['sett_advertised_to_client_sdks_leave_blank'] = 'Advertised to client SDKs. Leave blank to use the default endpoint shown above.';
$_ADDONLANG['sett_licensing_enabled_uncheck_for_maintenance'] = 'Licensing enabled (uncheck for maintenance)';
$_ADDONLANG['sett_license_key_format'] = 'License key format';
$_ADDONLANG['sett_the_shape_of_newly_issued_keys'] = '- the shape of newly issued keys; existing keys are never changed';
$_ADDONLANG['sett_prefix'] = 'Prefix';
$_ADDONLANG['sett_segments'] = 'Segments';
$_ADDONLANG['sett_segment_length'] = 'Segment length';
$_ADDONLANG['sett_separator'] = 'Separator';
$_ADDONLANG['sett_alphabet'] = 'Alphabet';
$_ADDONLANG['sett_hexadecimal'] = 'Hexadecimal';
$_ADDONLANG['sett_alphanumeric'] = 'Alphanumeric';
$_ADDONLANG['sett_uppercase'] = 'Uppercase';
$_ADDONLANG['sett_global_defaults'] = 'Global defaults';
$_ADDONLANG['sett_used_whenever_a_product_leaves_a'] = '- used whenever a product leaves a field blank on its Module Settings tab';
$_ADDONLANG['sett_duration_days'] = 'Duration (days)';
$_ADDONLANG['sett_trial_days'] = 'Trial (days)';
$_ADDONLANG['sett_activation_limit'] = 'Activation limit';
$_ADDONLANG['sett_reissue_limit'] = 'Reissue limit';
$_ADDONLANG['sett_grace_period_days'] = 'Grace period (days)';
$_ADDONLANG['sett_check_interval_h'] = 'Check interval (h)';
$_ADDONLANG['sett_offline_d'] = 'Offline (d)';
$_ADDONLANG['sett_release_dir'] = 'Release directory';
// Deliberately narrower than "Outside the web root". What was actually
// verified is that the path is not beneath the WHMCS installation or the
// document root. Whether some other route maps onto it - an nginx alias, an
// Apache Alias, a CDN origin, a symlink into a served tree - is a web-server
// question PHP cannot answer, so the badge must not claim it has.
$_ADDONLANG['sett_release_dir_ok'] = 'Not inside the WHMCS tree';
$_ADDONLANG['sett_release_dir_alias_warn'] = 'Checked: this directory is not inside the WHMCS installation or the document root. NOT checked, and not checkable from PHP: whether your web server maps some other URL onto it. An nginx "alias", an Apache "Alias" or "Directory", a static location block, a CDN origin, or a symlink from inside a served tree would all let the web server hand these files out directly, without ever calling LicenseForge - so no licence check would run and a revoked customer would still get the download. Confirm with an anonymous request from outside your network (for example: curl -I https://your-billing-domain/downloads/some-release.zip) that no URL serves a file from this directory. Re-check after any web server, vhost or CDN change.';
$_ADDONLANG['sett_release_dir_refused'] = 'Refused - downloads are disabled';
$_ADDONLANG['sett_release_dir_help'] = 'Absolute path to your release files, which must sit outside the web root. They are served only through LicenseForge, which checks the licence first. Blank disables downloads.';
$_ADDONLANG['rel_heading'] = 'Release files';
$_ADDONLANG['rel_subheading'] = 'served by LicenseForge, gated on licence state';
$_ADDONLANG['rel_no_dir'] = 'No release directory is configured, so the download endpoint is disabled.';
$_ADDONLANG['rel_set_it'] = 'Set one on the Settings tab.';
$_ADDONLANG['rel_dir_is'] = 'Serving from';
$_ADDONLANG['rel_label'] = 'Label';
$_ADDONLANG['rel_version'] = 'Version';
$_ADDONLANG['rel_file'] = 'File';
$_ADDONLANG['rel_file_placeholder'] = 'path relative to the release directory';
$_ADDONLANG['rel_size'] = 'Size';
$_ADDONLANG['rel_downloads'] = 'Downloads';
$_ADDONLANG['rel_add'] = 'Add release';
$_ADDONLANG['rel_verify'] = 'Verify integrity';
$_ADDONLANG['rel_verify_help'] = 'Re-hashes every release file and compares it with the checksum recorded when it was added. Run it after any change to the release directory. Large files take a while.';
$_ADDONLANG['msg_release_integrity_ok'] = 'Checked :count release files - every one matches the checksum recorded when it was added.';
$_ADDONLANG['msg_release_integrity_ok_some'] = 'Checked :count release files - every one matches. :skipped were skipped because no checksum was recorded for them; re-add those releases to record one.';
$_ADDONLANG['msg_release_integrity_failed'] = ':count release files no longer match the checksum recorded when they were added: :list. Treat these as unsafe to distribute until you know why - the file on disk is not the file you published. See the audit log.';
$_ADDONLANG['rel_remove'] = 'Remove';
$_ADDONLANG['rel_remove_confirm'] = 'Remove this release? The file itself is not deleted.';
$_ADDONLANG['rel_none'] = 'No releases yet.';
$_ADDONLANG['rel_missing'] = 'file not readable';
$_ADDONLANG['msg_release_added'] = 'Release ":label" added.';
$_ADDONLANG['msg_release_removed'] = 'Release removed. The file itself was not deleted.';
$_ADDONLANG['msg_release_incomplete'] = 'A release needs a label and a file path.';
$_ADDONLANG['msg_release_unreadable'] = 'No readable file at ":path" inside the release directory.';
$_ADDONLANG['msg_release_dir_inside_whmcs'] = 'Refused ":path": it is inside the WHMCS installation, so the web server would serve those files directly and the licence check would never run. Use a directory outside the web root, such as /var/lib/licenseforge/releases.';
$_ADDONLANG['msg_release_dir_inside_web_root'] = 'Refused ":path": it is inside the document root, so the web server would serve those files directly and the licence check would never run. Use a directory outside the web root, such as /var/lib/licenseforge/releases.';
$_ADDONLANG['msg_release_dir_unreadable'] = 'Refused ":path": PHP cannot read that directory.';
$_ADDONLANG['msg_release_dir_not_set'] = 'Refused: no release directory was given.';
$_ADDONLANG['msg_release_dir_missing'] = 'Refused ":path": no such directory. Create it first, or leave the setting blank.';
$_ADDONLANG['msg_release_dir_unset'] = 'Set a release directory before adding releases.';
$_ADDONLANG['sett_offline_d_help'] = 'How long a signed token keeps a licence working without reaching the server - and so how long a revocation takes to bite. Seven days suits most products.';
$_ADDONLANG['sett_binding_resets'] = 'Binding & resets';
$_ADDONLANG['sett_what_ties_a_license_to_an'] = '- what ties a license to an installation, and how customers move it';
$_ADDONLANG['sett_domain_lock'] = 'Domain lock';
$_ADDONLANG['sett_ip_lock'] = 'IP lock';
$_ADDONLANG['sett_directory_lock'] = 'Directory lock';
$_ADDONLANG['sett_machine_lock'] = 'Machine lock';
$_ADDONLANG['sett_allow_subdomains'] = 'Allow subdomains';
$_ADDONLANG['sett_treat_www_as_the_same_host'] = 'Treat www. as the same host';
$_ADDONLANG['sett_allow_dev_staging_domains'] = 'Allow dev/staging domains';
$_ADDONLANG['sett_reissue_cooldown_h'] = 'Reissue cooldown (h)';
$_ADDONLANG['sett_customer_self_service'] = 'Customer self-service';
$_ADDONLANG['sett_require_approval'] = 'Require approval';
$_ADDONLANG['sett_service_status_mapping'] = 'Service status mapping';
$_ADDONLANG['sett_what_happens_to_a_license_when'] = '- what happens to a license when its WHMCS service changes state';
$_ADDONLANG['sett_why_is_this_editable'] = 'Why is this editable?';
$_ADDONLANG['sett_because_the_same_service_state_means'] = 'Because the same service state means different things to different businesses. Whether an unpaid order should already work, and whether leaving is final or reversible, are commercial decisions rather than technical ones - so they are yours to make. The options below are limited to outcomes that are defensible for each state; anything else was a way to break licensing by accident.';
$_ADDONLANG['sett_this_mapping_only_runs_one_way'] = 'This mapping only runs one way: the service drives the license, never the reverse. A license you suspend by hand is';
$_ADDONLANG['sett_and_will_not_be_changed_back'] = 'and will not be changed back by these rules.';
$_ADDONLANG['sett_api_security_rate_limiting'] = 'API security & rate limiting';
$_ADDONLANG['sett_protects_the_public_licensing_endpoint'] = '- protects the public licensing endpoint';
$_ADDONLANG['sett_require_signed_api_requests'] = 'Require signed API requests';
$_ADDONLANG['sett_recommended'] = '(recommended)';
$_ADDONLANG['sett_signature_algorithm'] = 'Signature algorithm';
$_ADDONLANG['sett_automatic'] = 'Automatic';
$_ADDONLANG['sett_max_clock_skew_s'] = 'Max clock skew (s)';
$_ADDONLANG['sett_trusted_proxies'] = 'Trusted proxies';
$_ADDONLANG['sett_proxy_headers_are_honoured_only_when'] = 'Proxy headers are honoured only when the connecting peer matches this list.';
$_ADDONLANG['sett_proxy_header'] = 'Proxy header';
$_ADDONLANG['sett_rate_window_s'] = 'Rate window (s)';
$_ADDONLANG['sett_validate_ip'] = 'Validate / IP';
$_ADDONLANG['sett_activate_ip'] = 'Activate / IP';
$_ADDONLANG['sett_activate_key_per_hour'] = 'Activate / key (per hour)';
$_ADDONLANG['sett_failures_ip'] = 'Failures / IP';
$_ADDONLANG['sett_reissue_client'] = 'Reissue / client';
$_ADDONLANG['sett_fail_closed'] = 'If the counter fails';
$_ADDONLANG['sett_fail_closed_label'] = 'Extend that refusal to every rate limit, not just the security-critical ones';
$_ADDONLANG['sett_fail_closed_help'] = 'Activation, credential authentication and reissue always refuse when the counter cannot be read. Turn this on to refuse every other endpoint too, rather than let it run unmetered.';
$_ADDONLANG['sett_abuse_detection'] = 'Abuse detection';
$_ADDONLANG['sett_spots_key_sharing_and_enumeration_findings'] = '- spots key sharing and enumeration; findings appear on the Abuse tab';
$_ADDONLANG['sett_window_hours'] = 'Window (hours)';
$_ADDONLANG['sett_failure_threshold'] = 'Failure threshold';
$_ADDONLANG['sett_distinct_domains'] = 'Distinct domains';
$_ADDONLANG['sett_distinct_ips'] = 'Distinct IPs';
$_ADDONLANG['sett_auto_suspend_on_high_severity'] = 'Auto-suspend on over-deployment';
$_ADDONLANG['sett_install_ip_flips'] = 'Address flips per installation';
$_ADDONLANG['sett_install_ip_flips_help'] = 'Flags an installation whose IP keeps flipping inside the window - a sign of two copies running at once rather than one server moving. Flags and notifies only; never suspends. 0 turns it off.';
$_ADDONLANG['sett_auto_suspend_help'] = 'Suspends automatically when more installations check in than the activation limit allows. Every other abuse signal only flags and notifies.';
$_ADDONLANG['sett_client_area'] = 'Client area';
$_ADDONLANG['sett_what_customers_see_on_their_product'] = '- what customers see on their product page and services list';
$_ADDONLANG['sett_service_list'] = 'Service list';
$_ADDONLANG['sett_show_the_license_key_under_the'] = 'Show the license key under the product name on the client\'s services list';
$_ADDONLANG['sett_saves_customers_with_several_licensed_products'] = 'Shows every licence on one page, so a customer need not open each product to find a key.';
$_ADDONLANG['sett_downloads'] = 'Downloads';
$_ADDONLANG['sett_hide_product_downloads_while_the_license'] = 'Hide product downloads while the license is not usable';
$_ADDONLANG['sett_release_files_are_ordinary_whmcs_downloads'] = 'Release files are ordinary WHMCS downloads - add them under';
$_ADDONLANG['sett_and_associate_them_with_the_product'] = 'and associate them with the product. They appear on the customer\'s product page; with this on, an expired, suspended or revoked license hides them again.';
$_ADDONLANG['sett_logging_retention'] = 'Logging & retention';
$_ADDONLANG['sett_how_much_history_to_keep_before'] = '- how much history to keep before the daily cron prunes it';
$_ADDONLANG['sett_check_log_retention'] = 'Check log retention';
$_ADDONLANG['sett_days_of_individual_activation_check_records'] = 'Days of individual activation and check records. The largest table on a busy install.';
$_ADDONLANG['sett_audit_log_retention'] = 'Audit log retention';
$_ADDONLANG['sett_days_of_who_did_what_history'] = 'Days of who-did-what history: status changes, resets, admin actions. Keep it long - it settles disputes.';
$_ADDONLANG['sett_log_successful_checks_as_well_as'] = 'Log successful checks as well as failures';
$_ADDONLANG['sett_off_records_only_refusals_which_keeps'] = 'Off records refusals only, which keeps the table small. On proves an installation was working at a given time.';
$_ADDONLANG['sett_customer_emails'] = 'Customer emails';
$_ADDONLANG['sett_sent_through_whmcs_using_templates_you'] = '- sent through WHMCS, using templates you can edit';
$_ADDONLANG['sett_send_licensing_emails'] = 'Send licensing emails';
$_ADDONLANG['sett_master_switch_off_silences_every_email'] = 'Master switch. Off silences every email below without losing your templates.';
$_ADDONLANG['sett_expiry_reminders'] = 'Expiry reminders';
$_ADDONLANG['sett_notify_max_per_run'] = 'Emails per maintenance run';
$_ADDONLANG['sett_notify_max_per_run_help'] = 'Ceiling on licensing emails per scheduled run. Anything over it goes out on a later run, never dropped. 0 removes the ceiling.';
$_ADDONLANG['sett_days_before_expiry_to_remind_comma'] = 'Days before expiry to remind, comma separated. Renewing resets them.';
$_ADDONLANG['sett_save_settings'] = 'Save settings';
$_ADDONLANG['sett_email_templates'] = 'Email templates';
$_ADDONLANG['sett_installed_into_whmcs_edited_like_any'] = '- installed into WHMCS, edited like any other template';
$_ADDONLANG['sett_why_are_these_editable'] = 'Why are these editable?';
$_ADDONLANG['sett_because_they_are_your_words_to'] = 'Because they are your words to your customers, not the module\'s. LicenseForge installs working templates so notifications send from the moment you activate it - but the wording, branding, languages and BCC rules all belong in WHMCS, where the rest of your email already lives. Edit them under';
$_ADDONLANG['sett_setup_email_templates'] = 'Setup › Email Templates';
$_ADDONLANG['sett_the_module_only_cares_that_a'] = '; the module only cares that a template with the name below exists.';
$_ADDONLANG['sett_editing_one_here_is_safe_reinstalling'] = 'Editing one here is safe: reinstalling never touches a template that already exists unless you explicitly ask to restore the shipped wording.';
$_ADDONLANG['sett_they_are_installed_as'] = 'They are installed as';
$_ADDONLANG['sett_product'] = 'product';
$_ADDONLANG['sett_emails_the_same_type_as_whmcs'] = 'emails, the same type as WHMCS\'s own welcome and suspension messages. That is what puts them in the';
$_ADDONLANG['sett_email'] = 'Email';
$_ADDONLANG['sett_dropdown_on_a_client_s_product'] = 'dropdown on a client\'s product page, so you can send any of them by hand, and what makes them appear in the client\'s email history like every other WHMCS email.';
$_ADDONLANG['sett_the_emails_that_use_them_cannot'] = 'The emails that use them cannot send - WHMCS has no template by that name. Install them below.';
$_ADDONLANG['sett_email_2'] = 'Email';
$_ADDONLANG['sett_sent_when'] = 'Sent when';
$_ADDONLANG['sett_whmcs_template'] = 'WHMCS template';
$_ADDONLANG['sett_merge_fields'] = 'Merge fields';
$_ADDONLANG['sett_installed'] = 'installed';
$_ADDONLANG['sett_missing'] = 'missing';
$_ADDONLANG['sett_edit_wording'] = 'Edit wording';
$_ADDONLANG['sett_install_missing_templates'] = 'Install missing templates';
$_ADDONLANG['sett_restore_shipped_wording'] = 'Restore shipped wording';
$_ADDONLANG['sett_master_key']       = 'Master key';
$_ADDONLANG['sett_master_key_help']  = 'Derived from storage/master-key.php and this install\'s encryption hash. Keep the key file backed up and out of version control - losing it is not recoverable.';
$_ADDONLANG['sett_master_key_ok']    = 'Matches this installation';
$_ADDONLANG['sett_master_key_recorded'] = 'Fingerprint recorded';
$_ADDONLANG['sett_master_key_recorded_help'] = 'Noted for this installation. Any later change will be reported here and on the overview.';
$_ADDONLANG['sett_master_key_changed'] = 'Changed';
$_ADDONLANG['sett_master_key_changed_help'] = 'The key no longer matches the one this installation recorded, so every API secret and signing key encrypted under the previous key is unreadable. Restore storage/master-key.php from backup and check whether the WHMCS encryption hash was regenerated. Do not issue new credentials until this is resolved.';
$_ADDONLANG['sett_master_key_unknown'] = 'Cannot verify';
$_ADDONLANG['sett_offline_signing_keys'] = 'Offline signing keys';
$_ADDONLANG['sett_let_your_software_verify_a_license'] = '- let your software verify a license while it cannot reach this server';
$_ADDONLANG['sett_id'] = 'ID';
$_ADDONLANG['sett_algorithm'] = 'Algorithm';
$_ADDONLANG['sett_fingerprint'] = 'Fingerprint';
$_ADDONLANG['sett_created'] = 'Created';
$_ADDONLANG['sett_status'] = 'Status';
$_ADDONLANG['sett_public_key'] = 'Public key';
$_ADDONLANG['sett_retired'] = 'retired';
$_ADDONLANG['sett_activate'] = 'Activate';
$_ADDONLANG['sett_no_signing_keys_yet'] = 'No signing keys yet.';
$_ADDONLANG['sett_automatic_2'] = 'Automatic';
$_ADDONLANG['sett_ed25519'] = 'Ed25519';
$_ADDONLANG['sett_rsa_2048'] = 'RSA-2048';
$_ADDONLANG['sett_generate_signing_key'] = 'Generate signing key';
$_ADDONLANG['sett_private_keys_are_encrypted_at_rest'] = 'Private keys are encrypted at rest and never leave the server. Embed only the public key in your SDK.';
$_ADDONLANG['sett_applied_migrations'] = 'Applied migrations';
$_ADDONLANG['sett_database_changes_this_install_has_run'] = '- database changes this install has run';
$_ADDONLANG['sett_10_0_0_0_8_2001'] = '10.0.0.0/8, 2001:db8::/32';
$_ADDONLANG['sett_restore_every_licensing_template_to_the'] = 'Restore every licensing template to the wording LicenseForge ships? Your edits to these templates will be lost.';
$_ADDONLANG['sett_make_this_the_active_signing_key'] = 'Make this the active signing key?';
$_ADDONLANG['sett_generate_a_new_signing_key_and'] = 'Generate a new signing key and make it active? Distribute the new public key with your next SDK release.';

// Whole sentences, markup included, printed unescaped so the wording
// and the emphasis can move together when translated.

$_ADDONLANG['prod_how_it_works_html'] = '<strong>How it works:</strong> a product becomes licensed by assigning it the <em>License Forge</em> provisioning module in <em>Setup &rsaquo; Products/Services &rsaquo; Products/Services</em>. Every licensing rule - term, activation limits, binding, versions, entitlements - lives on that product\'s <em>Module Settings</em> tab, and its license appears on the customer\'s product details page. This page shows what those settings currently resolve to; blank fields fall back to the global defaults on the Settings tab.';

// ---------------------------------------------------------------- admin: messages

$_ADDONLANG['msg_unknown_action'] = 'Unknown action.';
$_ADDONLANG['msg_service_missing'] = 'Service #:id does not exist. A license must belong to a real service.';
$_ADDONLANG['msg_service_no_client'] = 'Service #:id has no valid client account.';
$_ADDONLANG['msg_service_has_license'] = 'Service #:id already has a license.';
$_ADDONLANG['msg_issue_failed'] = 'The license could not be issued. See the server error log for details.';
$_ADDONLANG['msg_license_issued'] = 'License :key issued for service #:id.';
$_ADDONLANG['msg_license_updated'] = 'License updated.';
$_ADDONLANG['msg_unknown_status'] = 'Unknown status.';
$_ADDONLANG['msg_status_not_allowed'] = 'That status change is not permitted from the current status.';
$_ADDONLANG['msg_status_changed'] = 'License status changed to :status.';
$_ADDONLANG['msg_activations_released'] = ':count activation(s) released.';
$_ADDONLANG['msg_entitlements_updated'] = 'Entitlements updated.';
$_ADDONLANG['msg_unknown_template'] = 'Unknown email template.';
$_ADDONLANG['msg_email_sent'] = 'Email sent. It appears in the client\'s email history like any other WHMCS message.';
$_ADDONLANG['msg_hold_released'] = 'Hold released. Service events can change this license again.';
$_ADDONLANG['msg_not_held'] = 'This license is not being held.';
$_ADDONLANG['msg_no_service'] = 'This license is not attached to a service.';
$_ADDONLANG['msg_api_unavailable'] = 'The WHMCS API is unavailable, so the service could not be suspended.';
$_ADDONLANG['msg_service_suspended'] = 'Service #:id suspended.';
$_ADDONLANG['msg_license_deleted'] = 'License deleted. Its history has been retained.';
$_ADDONLANG['msg_select_licenses'] = 'Select at least one license and an action.';
$_ADDONLANG['msg_licenses_updated'] = ':count license(s) updated.';
$_ADDONLANG['msg_bulk_capped']      = 'Only the first :processed of :requested selected licenses were processed. Run the action again to continue with the rest.';
$_ADDONLANG['msg_installation_not_active'] = 'That installation was already released - nothing changed.';
$_ADDONLANG['msg_installation_deactivated'] = 'Installation deactivated.';
$_ADDONLANG['msg_credential_updated'] = 'Credential updated.';
$_ADDONLANG['msg_credential_missing'] = 'Credential not found.';
$_ADDONLANG['msg_credential_deleted'] = 'Credential deleted.';
$_ADDONLANG['msg_reissue_approved'] = 'Reset approved.';
$_ADDONLANG['msg_reissue_rejected'] = 'Reset request rejected.';
$_ADDONLANG['msg_event_resolved'] = 'Event marked as resolved.';
$_ADDONLANG['msg_settings_saved'] = 'Settings saved.';
$_ADDONLANG['msg_key_activated'] = 'Signing key activated.';
$_ADDONLANG['msg_key_generated'] = 'New :algorithm signing key generated and activated.';
$_ADDONLANG['msg_license_missing'] = 'License not found.';

// ---------------------------------------------------------------- maintenance

$_ADDONLANG['msg_status_changed_held'] = 'License status changed to :status and held. Service events will not change it back until you release the hold.';
$_ADDONLANG['msg_offline_until_horizon'] = 'An installation already holding a signed offline token can keep running without contacting the server until :date. It takes effect sooner if it checks in before then; shorten the offline window if that delay matters.';
$_ADDONLANG['msg_reissued'] = 'License reset.';
$_ADDONLANG['msg_reissued_new_key'] = 'License reset. New key: :key';
$_ADDONLANG['msg_email_failed'] = 'The email could not be sent. Check that its template is installed on the Settings tab, that licensing emails are enabled, and that this license has a service.';
$_ADDONLANG['msg_suspend_refused'] = 'WHMCS refused to suspend the service: :reason';
$_ADDONLANG['msg_credential_created'] = 'Credential created. Store the secret now - it is shown here for convenience but should be treated as sensitive.';
$_ADDONLANG['msg_api_key'] = 'API key';
$_ADDONLANG['msg_api_secret'] = 'API secret';
$_ADDONLANG['msg_secret_rotated'] = 'Secret rotated. The new secret is:';
$_ADDONLANG['msg_bad_mapping'] = 'Ignored an unknown license status for :setting: :value';
$_ADDONLANG['msg_unsigned_confirm'] = 'Request signing was left ON. Turning it off lets anyone who learns a license key call the API as that installation, so it must be confirmed: type :phrase in the box beside the checkbox and save again.';
$_ADDONLANG['sett_unsigned_warning'] = 'Type ALLOW UNSIGNED REQUESTS to confirm. Also needs define(\'LICENSEFORGE_ALLOW_UNSIGNED\', true); in configuration.php. Unsigned trusts any caller holding a licence key.';
$_ADDONLANG['sett_unsigned_placeholder'] = 'ALLOW UNSIGNED REQUESTS';
$_ADDONLANG['sett_unsigned_active'] = 'The licensing API is accepting unsigned requests.';
$_ADDONLANG['sett_unsigned_active_why'] = 'Anyone who learns a license key can call the API as that installation. Tick the box above and save to require signing again.';
$_ADDONLANG['sett_require_install_proof'] = 'Require installation proof';
$_ADDONLANG['sett_proof_closed'] = 'Compatibility path closed';
$_ADDONLANG['sett_proof_no_installs'] = 'No installations yet';
$_ADDONLANG['sett_proof_pending'] = 'Still on the compatibility path';
$_ADDONLANG['sett_proof_pending_help'] = 'That many active installations have no secret on record and are honoured on their ID alone. Each is fixed the next time it activates.';
$_ADDONLANG['sett_proof_ready'] = 'Ready to close - nothing left on the compatibility path';
$_ADDONLANG['sett_proof_ready_help'] = 'Every active installation can prove its identity, so turning this on now strands nobody.';
$_ADDONLANG['sett_require_install_proof_help'] = 'Leave off while older activations still lack a secret - they are honoured and fixed on their next activation. Turn on once the count above reaches zero.';
$_ADDONLANG['sett_unsigned_blocked'] = 'This setting is off, but the API is still requiring signed requests.';
$_ADDONLANG['sett_unsigned_blocked_why'] = 'Unsigned mode also has to be permitted by the server, which it is not, so signing is being enforced and unsigned clients are refused. Turn the setting back on to match reality - or, if unsigned really is intended on this install, add the following line to your WHMCS configuration.php over SSH or FTP:';
$_ADDONLANG['msg_unsigned_needs_constant'] = 'Setting saved, but the API is still enforcing signed requests: unsigned mode also requires define(\':constant\', true); in your WHMCS configuration.php. Until that is added over SSH or FTP, nothing has changed for callers.';
$_ADDONLANG['msg_weak_key_format'] ='Key format left unchanged: it would give only :bits bits of entropy, below the :minimum-bit minimum. A key is the only thing protecting a license, so it must be infeasible to guess. Use more segments, longer segments, or a larger alphabet.';
$_ADDONLANG['msg_templates_failed'] = 'Some templates could not be written: :names. See the server error log.';
$_ADDONLANG['msg_templates_result'] = 'Email templates: :summary.';
$_ADDONLANG['msg_templates_created'] = ':count created';
$_ADDONLANG['msg_templates_retyped'] = ':count corrected to product emails';
$_ADDONLANG['msg_templates_reset'] = ':count restored to the shipped wording';
$_ADDONLANG['msg_templates_kept'] = ':count left as they are';
$_ADDONLANG['msg_product_not_licensed'] = 'That service\'s product does not use the License Forge module, so it cannot be licensed. Set the product\'s module first.';
$_ADDONLANG['maint_expired_one'] = '1 license expired';
$_ADDONLANG['maint_expired_many'] = ':count licenses expired';
$_ADDONLANG['maint_grace_started_one'] = '1 license entered its grace period';
$_ADDONLANG['maint_grace_started_many'] = ':count licenses entered their grace period';
$_ADDONLANG['maint_grace_ended_one'] = '1 grace period ended';
$_ADDONLANG['maint_grace_ended_many'] = ':count grace periods ended';
$_ADDONLANG['maint_reminders_one'] = '1 expiry reminder sent';
$_ADDONLANG['maint_reminders_many'] = ':count expiry reminders sent';
$_ADDONLANG['maint_abuse_one'] = '1 abuse event flagged';
$_ADDONLANG['maint_abuse_many'] = ':count abuse events flagged';
$_ADDONLANG['maint_reissue_stalled_one'] = '1 stalled reissue approval returned to the queue';
$_ADDONLANG['maint_reissue_stalled_many'] = ':count stalled reissue approvals returned to the queue';
$_ADDONLANG['maint_mail_deferred_one'] = '1 email held back for the next run';
$_ADDONLANG['maint_mail_deferred_many'] = ':count emails held back for the next run';
$_ADDONLANG['maint_cleanup_one'] = '1 expired record cleared';
$_ADDONLANG['maint_cleanup_many'] = ':count expired records cleared';
$_ADDONLANG['maint_and'] = 'and';

// ---------------------------------------------------------------- status map help

$_ADDONLANG['msg_maintenance_done'] = 'Maintenance completed.';
$_ADDONLANG['map_active_help'] = 'The customer has paid and the service is live. Choose <em>Pending</em> only if you vet every license by hand before it works.';
$_ADDONLANG['map_pending_help'] = 'Ordered but not yet paid or set up. <em>Active</em> lets people start using the software before payment clears - right for invoiced customers, risky for card orders.';
$_ADDONLANG['map_suspended_help'] = 'Usually non-payment. <em>Suspended</em> can be lifted automatically when they pay; <em>Expired</em> behaves the same but reads as a lapsed term.';
$_ADDONLANG['map_terminated_help'] = 'The service is finished. <em>Terminated</em> and <em>Revoked</em> are both final and release every activation; revoked reads as "withdrawn for cause".';
$_ADDONLANG['map_cancelled_help'] = 'The customer chose to leave. <em>Expired</em> is the gentlest - it lets them come back later on the same key.';
$_ADDONLANG['map_fraud_help'] = 'Flagged as fraudulent. <em>Revoked</em> is final and kills every installation at once.';
$_ADDONLANG['msg_see_audit_log'] = 'See the audit log';

// ---------------------------------------------------------------- configure screen

$_ADDONLANG['cfg_description'] = 'Sell and enforce software licenses from WHMCS. Assign the "License Forge" provisioning module to a product and every licensing rule lives on that product\'s own Module Settings tab. Customers get their key on their product page; your software activates once and checks in against a signed API.';
$_ADDONLANG['cfg_api_url'] = 'Licensing API URL';
$_ADDONLANG['cfg_api_url_help'] = 'Public HTTPS URL of the licensing endpoint. Leave blank to use the default: [System URL]/modules/addons/licenseforge/api/index.php';
$_ADDONLANG['cfg_require_auth'] = 'Require API authentication';
$_ADDONLANG['cfg_require_auth_help'] = 'Reject unsigned licensing requests. Strongly recommended. Disable only while integrating.';
$_ADDONLANG['cfg_duration'] = 'Default license duration (days)';
$_ADDONLANG['cfg_duration_help'] = 'Used when a product does not override it. 0 issues lifetime licenses.';
$_ADDONLANG['cfg_activations'] = 'Default activation limit';
$_ADDONLANG['cfg_activations_help'] = 'Concurrent installations permitted per license.';
$_ADDONLANG['cfg_downloads'] = 'Protect downloads';
$_ADDONLANG['cfg_downloads_help'] = 'Hide a product\'s WHMCS downloads from the client area while its license is expired, suspended or revoked.';
$_ADDONLANG['cfg_install_failed'] = 'Installation failed: :error';
$_ADDONLANG['cfg_removed'] = 'LicenseForge deactivated and all licensing data permanently removed.';
$_ADDONLANG['cfg_deactivated'] = 'LicenseForge deactivated. Your licensing data has been kept - reactivate at any time to restore access.';
$_ADDONLANG['cfg_installed'] = 'LicenseForge installed. Assign the "License Forge" provisioning module to a product to start licensing it.';

// ---------------------------------------------------------------- statuses
// Seen by customers on their product page and in their emails, as well as
// throughout the console.

$_ADDONLANG['status_pending']    = 'Pending';
$_ADDONLANG['status_active']     = 'Active';
$_ADDONLANG['status_suspended']  = 'Suspended';
$_ADDONLANG['status_expired']    = 'Expired';
$_ADDONLANG['status_revoked']    = 'Revoked';
$_ADDONLANG['status_terminated'] = 'Terminated';
$_ADDONLANG['status_reissued']   = 'Reissued';

// ---------------------------------------------------------------- provisioning module

$_ADDONLANG['mod_not_configured'] = 'Licensing is not configured for this product.';
$_ADDONLANG['mod_no_license']     = 'No license exists for this service.';
$_ADDONLANG['mod_license']        = 'License';
$_ADDONLANG['mod_none_issued']    = 'No license has been issued for this service yet.';
$_ADDONLANG['maint_nothing_due']  = 'Nothing was due - every license is already up to date.';

// ---------------------------------------------------------------------------
// Product Module Settings - the per-product licensing policy fields.
//
// Only the help text is here. The field labels themselves are not
// translatable by design: WHMCS stores server module options positionally
// and looks them up by their English name, so renaming one would detach it
// from every product already configured with it.
// ---------------------------------------------------------------------------

$_ADDONLANG['opt_product_slug_help'] = 'API identifier sent by your software as <code>product_id</code>. Leave blank to derive it from the product ID.';
$_ADDONLANG['opt_key_prefix_help'] = 'Optional prefix for generated license keys, e.g. <code>ACME</code>.';
$_ADDONLANG['opt_license_term_help'] = 'How long a license lasts. <strong>billing_cycle</strong> (recommended) ties it to what the customer pays for - the license expires on the service\'s next due date and is pushed out on every renewal, so monthly, quarterly and annual terms all work with no extra configuration. <strong>lifetime</strong> never expires. <strong>fixed_days</strong> uses the term below, independently of billing.';
$_ADDONLANG['opt_fixed_term_days_help'] = 'Only used when License Term is <code>fixed_days</code>. Blank inherits the global default duration.';
$_ADDONLANG['opt_trial_period_days_help'] = 'Term applied to licenses issued as trials. Blank inherits the global default.';
$_ADDONLANG['opt_max_activations_help'] = 'Concurrent installations allowed per license. Blank inherits the global default.';
$_ADDONLANG['opt_max_reissues_help'] = 'How many times a license may be moved to a new installation.';
$_ADDONLANG['opt_grace_period_days_help'] = 'Days an expired license keeps working before it stops validating.';
$_ADDONLANG['opt_validation_interval_hours_help'] = 'How often an installation should call home.';
$_ADDONLANG['opt_offline_validity_days_help'] = 'Lifetime of the signed offline token handed to the installation.';
$_ADDONLANG['opt_lock_to_domain_help'] = 'Bind each activation to the domain it was activated on.';
$_ADDONLANG['opt_lock_to_ip_help'] = 'Bind each activation to its server IP address.';
$_ADDONLANG['opt_lock_to_directory_help'] = 'Bind each activation to its installation path.';
$_ADDONLANG['opt_lock_to_machine_id_help'] = 'Bind each activation to a hardware/machine identifier.';
$_ADDONLANG['opt_allow_subdomains_help'] = 'Treat subdomains of the licensed domain as the same installation.';
$_ADDONLANG['opt_allow_local_domains_help'] = 'Permit activation on localhost, .test, .local and private IPs.';
$_ADDONLANG['opt_customer_reissue_help'] = 'Let the customer move the license themselves from the client area.';
$_ADDONLANG['opt_reissue_approval_help'] = 'Hold customer reissue requests until an administrator approves them.';
$_ADDONLANG['opt_minimum_version_help'] = 'Oldest software version allowed to validate. Blank for no minimum.';
$_ADDONLANG['opt_maximum_version_help'] = 'Newest software version allowed to validate. Blank for no maximum.';
$_ADDONLANG['opt_latest_version_help'] = 'Current release, reported back to installations so they can prompt to update.';
$_ADDONLANG['opt_upgrade_behaviour_help'] = 'What happens to the license when the customer changes package.';
$_ADDONLANG['opt_renewal_behaviour_help'] = 'How the expiry moves when a renewal invoice is paid - only consulted for a <code>fixed_days</code> term. A <code>billing_cycle</code> term always follows the new due date.';
$_ADDONLANG['opt_default_features_help'] = 'Feature slugs granted to every license for this product, one per line or comma separated.';

// ---------------------------------------------------------------------------
// Service Module tab - what staff see on a client's licensed service.
// ---------------------------------------------------------------------------

$_ADDONLANG['svc_key']         = 'License Key';
$_ADDONLANG['svc_status']      = 'License Status';
$_ADDONLANG['svc_manage']      = 'Manage license';
$_ADDONLANG['svc_activations'] = 'Activations';
$_ADDONLANG['svc_expires']     = 'License Expires';
$_ADDONLANG['svc_never']       = 'Never';
$_ADDONLANG['svc_btn_reissue'] = 'Reissue License';
$_ADDONLANG['svc_btn_reset']   = 'Reset Activations';
