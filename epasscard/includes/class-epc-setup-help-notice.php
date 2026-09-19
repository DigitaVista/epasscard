<?php
/**
 * Free setup help admin notice.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dismissible free-setup banner on dashboard / plugins / EpassCard screens.
 */
class EPC_Setup_Help_Notice {

	/**
	 * Option key for dismiss state.
	 */
	public const OPTION_NAME = 'epasscard_setup_help_notice';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'show_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_epasscard_dismiss_setup_help_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Whether the current screen should show the notice.
	 *
	 * @return bool
	 */
	private static function is_allowed_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		$allowed_bases = array(
			'dashboard',
			'plugins',
			'toplevel_page_epasscard',
			'epasscard_page_epc-api-log',
		);

		if ( in_array( $screen->base, $allowed_bases, true ) ) {
			return true;
		}

		// Any EpassCard admin screen.
		if ( false !== strpos( $screen->id, 'epasscard' ) || false !== strpos( $screen->id, 'epc-' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if notice should be displayed.
	 *
	 * @return bool
	 */
	private static function should_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! self::is_allowed_screen() ) {
			return false;
		}

		if ( ! EPC_Setup_Help::is_offer_active() ) {
			return false;
		}

		$notice_status = get_option( self::OPTION_NAME, array() );

		if ( ! empty( $notice_status['dismissed'] ) ) {
			return false;
		}

		if ( isset( $notice_status['dismissed_until'] ) ) {
			$current_datetime = current_time( 'mysql' );
			if ( $current_datetime < $notice_status['dismissed_until'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Display admin notice.
	 *
	 * @return void
	 */
	public static function show_admin_notice() {
		return;
		if ( ! self::should_show_notice() ) {
			return;
		}

		$days_text = EPC_Setup_Help::get_days_left_label();
		?>
		<div class="notice epasscard-setup-help-notice is-dismissible">
			<button type="button" class="notice-dismiss">
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'epasscard' ); ?></span>
			</button>
			<div class="epc-setup-notice-glow"></div>
			<div class="epc-setup-notice-inner">
				<div class="epc-setup-notice-avatar-wrap">
					<?php echo EPC_Setup_Help::get_avatar_svg( 'epc-setup-notice-avatar', 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted SVG from helper. ?>
					<span class="epc-setup-notice-status-dot" aria-hidden="true"></span>
				</div>

				<div class="epc-setup-notice-body">
					<div class="epc-setup-notice-meta">
						<span class="epc-setup-notice-badge">
							<span class="epc-setup-badge-dot"></span>
							<?php esc_html_e( 'Free expert help', 'epasscard' ); ?>
						</span>
						<span class="epc-setup-notice-timer">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
							<?php echo esc_html( $days_text ); ?>
						</span>
					</div>
					<h3 class="epc-setup-notice-title">
						<?php esc_html_e( 'Get free setup help', 'epasscard' ); ?>
					</h3>
					<p class="epc-setup-notice-text">
						<?php esc_html_e( 'Get help from our experts at no cost. We\'ll configure EpassCard so your wallet passes are ready to launch.', 'epasscard' ); ?>
					</p>
				</div>

				<div class="epc-setup-notice-actions">
					<a href="<?php echo esc_url( EPC_Setup_Help::get_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer" class="epc-setup-notice-cta">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
							<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
						</svg>
						<span><?php esc_html_e( 'Claim Free Setup', 'epasscard' ); ?></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! self::should_show_notice() ) {
			return;
		}

		$style_path  = EPC_PLUGIN_DIR . 'admin/css/setup-help-notice.css';
		$script_path = EPC_PLUGIN_DIR . 'admin/js/setup-help-admin-notice.js';

		wp_enqueue_style(
			'epasscard-setup-help-notice',
			EPC_PLUGIN_URL . 'admin/css/setup-help-notice.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : EPC_VERSION
		);

		wp_enqueue_script(
			'epasscard-setup-help-notice',
			EPC_PLUGIN_URL . 'admin/js/setup-help-admin-notice.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : EPC_VERSION,
			true
		);

		wp_localize_script(
			'epasscard-setup-help-notice',
			'epasscardSetupHelpNotice',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'epasscard_setup_help_notice_nonce' ),
			)
		);
	}

	/**
	 * AJAX handler for dismissing notice.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_notice() {
		check_ajax_referer( 'epasscard_setup_help_notice_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$action = isset( $_POST['dismiss_action'] ) ? sanitize_text_field( wp_unslash( $_POST['dismiss_action'] ) ) : '';

		if ( 'later' === $action ) {
			$until = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) );
			update_option(
				self::OPTION_NAME,
				array(
					'dismissed_until' => $until,
				)
			);

			wp_send_json_success(
				array(
					'message'         => 'Notice snoozed',
					'dismissed_until' => $until,
				)
			);
		}

		if ( 'forever' === $action ) {
			update_option(
				self::OPTION_NAME,
				array(
					'dismissed' => true,
				)
			);

			wp_send_json_success( array( 'message' => 'Notice dismissed' ) );
		}

		wp_send_json_error( array( 'message' => 'Invalid action' ) );
	}
}
