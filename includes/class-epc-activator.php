<?php
/**
 * Activation and deactivation hooks.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation / deactivation.
 */
class EPC_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-db.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-api-log.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-connection.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-pass-notifications.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-rule-service.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-reward-service.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-starter.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-frontend.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-frontend.php';
		EPC_DB::install();
		EPC_Api_Log::schedule_cron();
		EPC_Connection::schedule_cron();
		EPC_Pass_Notifications::schedule_cron();
		EPC_Loyalty_Reward_Service::schedule_cron();
		EPC_Loyalty_Starter::maybe_install();
		EPC_Frontend::register_wc_endpoint();
		EPC_Loyalty_Frontend::register_endpoint();
		flush_rewrite_rules();

		require_once EPC_PLUGIN_DIR . 'includes/class-epc-setup-help.php';
		EPC_Setup_Help::maybe_set_first_activated_time();
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-setup-wizard.php';
		EPC_Setup_Wizard::flag_activation_redirect();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-connection.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-api-log.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-pass-notifications.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-reward-service.php';
		require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-order-sync-service.php';
		require_once EPC_PLUGIN_DIR . 'includes/class-epc-retry-failed.php';
		EPC_Connection::clear_cron();
		EPC_Api_Log::clear_cron();
		EPC_Pass_Notifications::clear_cron();
		EPC_Loyalty_Reward_Service::clear_cron();
		EPC_Loyalty_Order_Sync_Service::clear_cron();
		EPC_Retry_Failed::clear_cron();

		/*
		 * Queued per-user pass syncs. They carry the user id as an argument, which
		 * wp_clear_scheduled_hook() would only match for one exact id, so remove every event
		 * of these hooks. While the plugin is inactive they would run with no handler anyway;
		 * after reactivation the next profile or points change queues a fresh sync.
		 * Revoke retries (epc_retry_pass_revoke) and the one-time revoked-pass repair are kept:
		 * clearing them could leave a pass active that should be expired.
		 */
		wp_unschedule_hook( 'epc_sync_user_passes' );
		wp_unschedule_hook( 'epc_loyalty_sync_pass' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'epc_loyalty_sync_pass' );
		}
	}
}
