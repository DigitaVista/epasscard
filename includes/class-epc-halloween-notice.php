<?php
/**
 * EpassCard — Halloween Deal Admin Notice
 *
 * @package EpassCard
 */

defined( 'ABSPATH' ) || exit;

/**
 * EpassCard — Halloween Deal Admin Notice
 */
class EPC_Halloween_Notice {

	/**
	 * Option key for dismiss state.
	 *
	 * @var string
	 */
	private $option_name = 'epasscard_halloween_deal_notice';

	/**
	 * Deal URL.
	 *
	 * @var string
	 */
	private $deal_url = 'https://app.epasscard.com/register';

	/**
	 * Agency Bundle Deal URL.
	 *
	 * @var string
	 */
	private $bundle_url = 'https://www.webcartisan.com/halloween-deals/#agency-bundle';

	/**
	 * Folder that holds the Halloween artwork (same images as the deals page).
	 *
	 * @var string
	 */
	private $img_base = 'https://www.webcartisan.com/wp-content/uploads/2026/10/';

	/**
	 * Last moment the notice is shown (site time, Y-m-d H:i:s).
	 *
	 * Filter with `epc_halloween_notice_ends_at`.
	 *
	 * @var string
	 */
	private $ends_at = '2026-11-01 00:00:00';

	/**
	 * Singleton instance.
	 *
	 * @var EPC_Halloween_Notice|null
	 */
	private static $instance = null;

	/**
	 * Initialize the notice handler.
	 *
	 * @return EPC_Halloween_Notice
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// in_admin_header prints the banner at the very top of the page, above the page title.
		add_action( 'in_admin_header', array( $this, 'show_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_epasscard_dismiss_halloween_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Check if notice should be displayed.
	 *
	 * @return bool
	 */
	private function should_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$ends_at = (string) apply_filters( 'epc_halloween_notice_ends_at', $this->ends_at );
		if ( '' !== $ends_at && current_time( 'mysql' ) >= $ends_at ) {
			return false;
		}

		$notice_status = get_option( $this->option_name, array() );

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
	 * Display Halloween Deal admin notice.
	 *
	 * @return void
	 */
	public function show_admin_notice() {
		if ( ! $this->should_show_notice() ) {
			return;
		}

		$img = $this->img_base;
		?>
		<?php // Not using the core "notice" class on purpose: WordPress JS moves those below the page title. ?>
		<div class="epc-hw-notice gl-hw-notice vm-hw-notice epasscard-halloween-notice" role="region" aria-label="<?php esc_attr_e( 'Halloween Sale', 'epasscard' ); ?>">

			<button type="button" class="epc-hw-close gl-hw-close vm-hw-close" aria-label="<?php esc_attr_e( 'Dismiss this notice.', 'epasscard' ); ?>">&times;</button>

			<img class="epc-hw-ghost gl-hw-ghost vm-hw-ghost" src="<?php echo esc_url( $img . 'Cute-Glowing-Purple-Ghost-Sticker-2.png' ); ?>" alt="" aria-hidden="true">

			<div class="epc-hw-art gl-hw-art vm-hw-art">
				<div class="epc-hw-title gl-hw-title vm-hw-title">
					<img class="epc-hw-sale gl-hw-sale vm-hw-sale" src="<?php echo esc_url( $img . 'halloween-sale-text.png' ); ?>" alt="<?php esc_attr_e( 'Halloween Sale', 'epasscard' ); ?>">
				</div>

				<div class="epc-hw-discount gl-hw-discount vm-hw-discount">
					<span class="epc-hw-flat gl-hw-flat vm-hw-flat"><?php esc_html_e( 'Flat', 'epasscard' ); ?></span>
					<img class="epc-hw-30 gl-hw-30 vm-hw-30" src="<?php echo esc_url( $img . 'Glossy_Dripping_Halloween_30_-removebg-preview.png' ); ?>" alt="30%">
					<img class="epc-hw-off gl-hw-off vm-hw-off" src="<?php echo esc_url( $img . 'off.png' ); ?>" alt="<?php esc_attr_e( 'OFF', 'epasscard' ); ?>">
				</div>
			</div>

			<div class="epc-hw-body gl-hw-body vm-hw-body">
				<p class="epc-hw-text gl-hw-text vm-hw-text">
					<?php esc_html_e( 'Upgrade your store with EpassCard! Issue stunning Apple Wallet and Google Wallet passes for loyalty, memberships, event tickets, and gift cards with exclusive Halloween savings.', 'epasscard' ); ?>
				</p>

				<div class="epc-hw-actions gl-hw-actions vm-hw-actions">
					<span class="epc-hw-cta gl-hw-cta vm-hw-cta">
						<a href="<?php echo esc_url( $this->deal_url ); ?>" target="_blank" rel="noopener noreferrer" class="epc-hw-btn-primary gl-hw-btn-primary vm-hw-btn-primary">
							<?php esc_html_e( 'Get Deal (30% OFF)', 'epasscard' ); ?> <span aria-hidden="true">→</span>
						</a>
					</span>

					<a href="<?php echo esc_url( $this->bundle_url ); ?>" target="_blank" rel="noopener noreferrer" class="epc-hw-bundle gl-hw-bundle vm-hw-bundle">
						<img src="<?php echo esc_url( $img . 'agency-bundle-button.png' ); ?>" alt="<?php esc_attr_e( 'Agency Bundle', 'epasscard' ); ?>">
					</a>

					<button type="button" class="epc-hw-dismiss-forever gl-hw-dismiss-forever vm-hw-dismiss-forever">
						<?php esc_html_e( 'Dismiss', 'epasscard' ); ?>
					</button>
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
	public function enqueue_scripts() {
		if ( ! $this->should_show_notice() ) {
			return;
		}

		$style_path  = EPC_PLUGIN_DIR . 'admin/css/halloween-deal-notice.css';
		$script_path = EPC_PLUGIN_DIR . 'admin/js/halloween-deal-notice.js';

		wp_enqueue_style(
			'epasscard-halloween-deal-notice',
			EPC_PLUGIN_URL . 'admin/css/halloween-deal-notice.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : ( defined( 'EPC_VERSION' ) ? EPC_VERSION : '1.0.0' )
		);

		wp_enqueue_script(
			'epasscard-halloween-deal-notice',
			EPC_PLUGIN_URL . 'admin/js/halloween-deal-notice.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ( defined( 'EPC_VERSION' ) ? EPC_VERSION : '1.0.0' ),
			true
		);

		wp_localize_script(
			'epasscard-halloween-deal-notice',
			'epasscardHalloweenNotice',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'epasscard_halloween_notice_nonce' ),
			)
		);
	}

	/**
	 * AJAX handler for dismissing notice.
	 *
	 * @return void
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'epasscard_halloween_notice_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$action = isset( $_POST['dismiss_action'] ) ? sanitize_text_field( wp_unslash( $_POST['dismiss_action'] ) ) : '';

		if ( 'later' === $action ) {
			$until = wp_date( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );
			update_option(
				$this->option_name,
				array(
					'dismissed_until' => $until,
				)
			);

			wp_send_json_success(
				array(
					'message'         => 'Notice snoozed for 3 days',
					'dismissed_until' => $until,
				)
			);
		}

		if ( 'forever' === $action ) {
			update_option(
				$this->option_name,
				array(
					'dismissed' => true,
				)
			);

			wp_send_json_success( array( 'message' => 'Notice dismissed' ) );
		}

		wp_send_json_error( array( 'message' => 'Invalid action' ) );
	}
}
