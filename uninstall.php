<?php
/**
 * Runs when EpassCard is deleted from the Plugins screen.
 *
 * Data is removed only on sites where the owner turned on
 * "Delete all EpassCard data when the plugin is deleted" (option epc_delete_data_on_uninstall = '1').
 * Left alone on purpose: WooCommerce reward coupons already given to customers, WooCommerce
 * order meta, and passes on EpassCard's servers.
 *
 * @package EpassCard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Scheduled hooks EpassCard registers (WP-Cron).
 *
 * @return string[]
 */
function epasscard_uninstall_cron_hooks() {
	return array(
		'epc_extend_api_key_event',
		'epc_purge_api_logs_event',
		'epc_pass_notifications_event',
		'epc_pass_notifications_continue',
		'epc_loyalty_expiry_event',
		'epc_loyalty_order_sync_batch',
		'epc_sync_user_passes',
		'epc_loyalty_sync_pass',
		'epc_resync_revoked_passes',
		'epc_retry_pass_revoke',
		'epc_retry_failed_batch',
		'epc_existing_customers_batch',
		'epc_tickera_refresh_event',
	);
}

/**
 * Remove scheduled jobs on the current site. Safe to run whether or not data is deleted.
 *
 * @return void
 */
function epasscard_uninstall_clear_jobs() {
	foreach ( epasscard_uninstall_cron_hooks() as $hook ) {
		wp_unschedule_hook( $hook );
	}

	// Action Scheduler jobs (loaded when WooCommerce is active).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'epasscard-loyalty' );
		as_unschedule_all_actions( '', array(), 'epasscard-wployalty' );
		as_unschedule_all_actions( '', array(), 'epasscard' );
	}
}

/**
 * Delete EpassCard data on the current site when its owner opted in.
 *
 * @return bool Whether this site opted in.
 */
function epasscard_uninstall_site() {
	epasscard_uninstall_clear_jobs();

	if ( '1' !== (string) get_option( 'epc_delete_data_on_uninstall', '0' ) ) {
		return false;
	}

	global $wpdb;

	foreach ( array( 'epc_passes', 'epc_api_logs', 'epc_loyalty_accounts', 'epc_loyalty_ledger', 'epc_loyalty_claims', 'epc_form_submissions' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
	}

	$options = array(
		// Connection, setup and general settings.
		'epc_connection_settings',
		'epc_enabled_modules',
		'epc_pass_email_settings',
		'epc_pass_issues',
		'epc_pass_notifications_cursor',
		'epc_pass_notifications_lock',
		'epc_setup_wizard',
		'epc_first_activated_time',
		'epasscard_setup_help_notice',
		'epasscard_halloween_deal_notice',
		'epc_delete_data_on_uninstall',
		// Database and one-time maintenance state.
		'epc_db_version',
		'epc_pass_unique_attempts',
		'epc_revoked_resync_1010',
		'epc_api_log_retention_days',
		'epc_api_log_redaction_version',
		'epc_api_log_redaction_cursor',
		'epc_first_real_pass_at',
		'epc_retry_failed_job',
		'epc_existing_job',
		'epc_existing_queue',
		'epc_existing_results',
		'epc_setup',
		'epc_test_cards',
		'epc_user_field_keys',
		// Integration pass-behavior rules.
		'epc_events_manager_status_rules',
		'epc_memberpress_status_rules',
		'epc_pmpro_status_rules',
		'epc_pw_gift_cards_status_rules',
		'epc_swpm_status_rules',
		'epc_the_events_calendar_status_rules',
		'epc_ump_status_rules',
		'epc_wcs_status_rules',
		'epc_yith_gift_cards_status_rules',
		'epc_woocommerce_tickets_status_rules',
		'epc_ultimate_member_status_rules',
		'epc_amelia_appointments_status_rules',
		'epc_amelia_appointments_cards_per',
		'epc_amelia_events_status_rules',
		'epc_amelia_events_cards_per',
		'epc_tickera_status_rules',
		'epc_um_role_ids',
		'epc_woocommerce_vouchers_status_rules',
		'epc_woocommerce_product_cards_status_rules',
		// Loyalty.
		'epc_loyalty_program',
		'epc_loyalty_earning_rules',
		'epc_loyalty_tiers',
		'epc_loyalty_milestones',
		'epc_loyalty_notifications',
		'epc_loyalty_redemption',
		'epc_loyalty_pass_design',
		'epc_loyalty_order_sync',
		'epc_loyalty_starter_pack',
		'epc_loyalty_wallet_rewrite_flushed',
	);

	// Appsero usage-tracking choices, keyed by the plugin folder name.
	$slug = basename( __DIR__ );
	foreach ( array( '_allow_tracking', '_tracking_notice', '_tracking_last_send', '_tracking_skipped' ) as $suffix ) {
		$options[] = $slug . $suffix;
	}

	// Per-integration options named after the integration slug (built-in and custom integrations).
	$patterns = array(
		'epc\_mappings\_%',
		'epc\_%\_notification\_rules',
		'epc\_starter\_template\_%',
		'epc\_default\_mapping\_%',
	);
	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		$options = array_merge( $options, is_array( $found ) ? $found : array() );
	}

	foreach ( array_unique( $options ) as $option ) {
		delete_option( $option );
	}

	// "Wallet card" settings on WooCommerce products (the card codes on order lines stay, with the order).
	foreach ( array( '_epc_wallet_card', '_epc_event_start', '_epc_event_venue', '_epc_voucher_valid_until' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	// Short-lived caches.
	delete_transient( 'epc_setup_wizard_redirect' );
	foreach ( array( 'epc\_catalog\_%', 'epc\_starter\_lock\_%', 'epc\_retry\_failed\_notice\_%', 'epc\_try\_error\_%', 'epc\_existing\_check\_%', 'epc\_existing\_notice\_%' ) as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '\_transient\_' . $pattern ) );
		foreach ( is_array( $found ) ? $found : array() as $option ) {
			delete_transient( substr( (string) $option, strlen( '_transient_' ) ) );
		}
	}

	return true;
}

/**
 * Delete EpassCard user meta. User meta is shared by every site of a network.
 *
 * @return void
 */
function epasscard_uninstall_user_meta() {
	global $wpdb;

	delete_metadata( 'user', 0, 'epc_loyalty_highest_tier_threshold', '', true );
	delete_metadata( 'user', 0, 'epc_review_prompt', '', true );
	delete_metadata( 'user', 0, 'epc_help', '', true );
	delete_metadata( 'user', 0, 'epc_support_chat_consent', '', true );
	delete_metadata( 'user', 0, 'epc_support_chat_hidden', '', true );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall.
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", 'epc\_loyalty\_expiry\_notice\_%' ) );
	foreach ( is_array( $keys ) ? $keys : array() as $key ) {
		delete_metadata( 'user', 0, (string) $key, '', true );
	}
}

if ( is_multisite() ) {
	$epasscard_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	$epasscard_all_opted_in = true;
	foreach ( $epasscard_site_ids as $epasscard_site_id ) {
		switch_to_blog( (int) $epasscard_site_id );
		$epasscard_all_opted_in = epasscard_uninstall_site() && $epasscard_all_opted_in;
		restore_current_blog();
	}
	// Shared user meta goes only when every site asked for its data to be deleted.
	if ( $epasscard_all_opted_in ) {
		epasscard_uninstall_user_meta();
	}
} elseif ( epasscard_uninstall_site() ) {
	epasscard_uninstall_user_meta();
}
