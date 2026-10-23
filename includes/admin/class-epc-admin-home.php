<?php
/**
 * Home: the setup checklist until the first real card, then the overview.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home screen.
 */
class EPC_Admin_Home {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_epc_try_skip', array( __CLASS__, 'handle_try_skip' ) );
	}

	/**
	 * Render Home.
	 *
	 * @return void
	 */
	public static function render() {
		$complete = EPC_Onboarding::is_complete();

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'home',
				'title'   => __( 'Home', 'epasscard' ),
				'subnav'  => array(),
			)
		);

		if ( $complete ) {
			self::render_overview();
		} else {
			self::render_checklist();
		}

		EPC_Admin_Shell::render_close();
	}

	/**
	 * The checklist.
	 *
	 * @return void
	 */
	private static function render_checklist() {
		$state   = EPC_Onboarding::get_state();
		$steps   = EPC_Onboarding::get_steps();
		$current = EPC_Onboarding::current_step();
		$job     = $state['job'];
		$status  = '' !== $job ? EPC_Jobs::get_status( $job, $state['module'] ) : null;
		$copy    = '' !== $job ? EPC_Jobs::get_copy( $job ) : null;
		$module  = $status && '' !== $status['module'] ? EPC_Module_Loader::get_module_instance( $status['module'] ) : null;

		include EPC_PLUGIN_DIR . 'admin/views/app/home-setup.php';
	}

	/**
	 * After setup: what the site's cards are doing.
	 *
	 * @return void
	 */
	private static function render_overview() {
		$issues = EPC_Pass_Issues::all();
		$active = EPC_DB::count_all_real_passes( 'active' );
		$week   = EPC_DB::count_real_passes_last_days( 7 );
		$jobs   = array();
		foreach ( array_keys( EPC_Jobs::get_jobs() ) as $job ) {
			$status = EPC_Jobs::get_status( $job );
			if ( EPC_Jobs::NOT_INSTALLED !== $status['state'] ) {
				$jobs[ $job ] = $status;
			}
		}

		include EPC_PLUGIN_DIR . 'admin/views/app/home-live.php';
	}

	/**
	 * Where the Design step sends the merchant for a module.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	public static function design_url( $slug ) {
		return EPC_Admin_Design::url_for_module( $slug );
	}

	/**
	 * Admin-post: skip the optional test.
	 *
	 * @return void
	 */
	public static function handle_try_skip() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_try_skip' );
		EPC_Onboarding::update( array( 'try' => 'skipped' ) );
		wp_safe_redirect( EPC_Admin_App::url( 'home' ) );
		exit;
	}
}
