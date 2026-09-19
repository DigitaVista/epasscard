<?php
/**
 * Sync passes when subscriber profile data changes.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates issued passes when member/subscriber profile fields change.
 */
class EPC_User_Pass_Sync {

	public const CRON_HOOK = 'epc_sync_user_passes';

	/**
	 * User meta keys that should trigger a pass refresh.
	 *
	 * @var array<int, string>
	 */
	private static array $watched_meta_keys = array(
		'first_name',
		'last_name',
		'nickname',
		'billing_first_name',
		'billing_last_name',
		'billing_email',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'profile_update', array( __CLASS__, 'schedule_sync' ), 20, 1 );
		add_action( 'personal_options_update', array( __CLASS__, 'schedule_sync' ), 20, 1 );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'schedule_sync' ), 20, 1 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta_updated' ), 20, 4 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'schedule_sync' ), 20, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_user_passes' ), 10, 1 );
	}

	/**
	 * Queue a deferred pass sync for a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function schedule_sync( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $user_id ) ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK, array( $user_id ) );
		}
	}

	/**
	 * Sync passes when relevant user meta changes.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_user_meta_updated( $meta_id, $user_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( ! in_array( (string) $meta_key, self::$watched_meta_keys, true ) ) {
			return;
		}

		self::schedule_sync( $user_id );
	}

	/**
	 * Update all active passes for a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function sync_user_passes( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$passes = EPC_DB::get_active_passes_for_user( $user_id );
		if ( empty( $passes ) ) {
			return;
		}

		$registry = EPC_Module_Loader::get_registry();

		foreach ( $passes as $pass ) {
			if ( empty( $pass->module ) || empty( $pass->source_id ) ) {
				continue;
			}

			if ( ! EPC_Module_Settings::is_enabled( (string) $pass->module ) ) {
				continue;
			}

			$module = $registry[ (string) $pass->module ] ?? null;
			if ( ! $module instanceof EPC_Module || ! $module->is_available() ) {
				continue;
			}

			$module->sync_by_source_id( (string) $pass->source_id, 'update' );
		}
	}
}
