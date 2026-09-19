<?php
/**
 * Free setup help helpers (days window, WhatsApp CTA, avatar markup).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared free-setup offer utilities.
 */
class EPC_Setup_Help {

	/**
	 * Free setup offer window in days.
	 */
	public const TOTAL_FREE_DAYS = 30;

	/**
	 * Option key for first activation timestamp.
	 */
	public const OPTION_FIRST_ACTIVATED = 'epc_first_activated_time';

	/**
	 * Ensure first-activated timestamp exists and return it.
	 *
	 * @return int Unix timestamp.
	 */
	public static function get_first_activated_time() {
		$first_activated = get_option( self::OPTION_FIRST_ACTIVATED );

		if ( ! $first_activated ) {
			$first_activated = time();
			add_option( self::OPTION_FIRST_ACTIVATED, $first_activated );
		}

		return (int) $first_activated;
	}

	/**
	 * Record activation time once (call from activator).
	 *
	 * @return void
	 */
	public static function maybe_set_first_activated_time() {
		if ( get_option( self::OPTION_FIRST_ACTIVATED ) ) {
			return;
		}

		add_option( self::OPTION_FIRST_ACTIVATED, time() );
	}

	/**
	 * Days remaining in the free setup window.
	 *
	 * @return int
	 */
	public static function get_days_left() {
		$days_passed = (int) floor( ( time() - self::get_first_activated_time() ) / DAY_IN_SECONDS );

		return max( 0, self::TOTAL_FREE_DAYS - $days_passed );
	}

	/**
	 * Whether the free setup offer is still active.
	 *
	 * @return bool
	 */
	public static function is_offer_active() {
		return self::get_days_left() > 0;
	}

	/**
	 * Localized “N days left” label.
	 *
	 * @return string
	 */
	public static function get_days_left_label() {
		$days_left = self::get_days_left();

		if ( $days_left > 0 ) {
			/* translators: %d: number of days left for free setup help. */
			return sprintf( __( '%d days left', 'epasscard' ), $days_left );
		}

		return __( '0 days left', 'epasscard' );
	}

	/**
	 * WhatsApp CTA URL for free setup.
	 *
	 * @return string
	 */
	public static function get_whatsapp_url() {
		return 'https://wa.me/8801926167151?text=' . rawurlencode( 'Hi, I need free setup help for EpassCard' );
	}

	/**
	 * Support avatar SVG markup.
	 *
	 * @param string $class CSS class for the svg element.
	 * @param int    $size  Width/height in px.
	 * @return string
	 */
	public static function get_avatar_svg( $class = 'epc-setup-avatar', $size = 64 ) {
		$size = absint( $size );

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" class="%2$s" aria-hidden="true">
				<circle cx="40" cy="40" r="40" fill="#E2E8F0"/>
				<path d="M16 68C16 56 26 48 40 48C54 48 64 56 64 68" fill="#475569"/>
				<path d="M32 48L40 58L48 48" fill="#FFFFFF"/>
				<path d="M34 40V46C34 49.3 36.7 52 40 52C43.3 52 46 49.3 46 46V40" fill="#FDBA74"/>
				<circle cx="40" cy="32" r="14" fill="#FDBA74"/>
				<path d="M26 28C26 20 32 16 40 16C48 16 54 20 54 28C54 28 50 24 40 24C30 24 26 28 26 28Z" fill="#334155"/>
				<rect x="29" y="29" width="9" height="7" rx="2" fill="none" stroke="#1E293B" stroke-width="2"/>
				<rect x="42" y="29" width="9" height="7" rx="2" fill="none" stroke="#1E293B" stroke-width="2"/>
				<line x1="38" y1="32" x2="42" y2="32" stroke="#1E293B" stroke-width="2"/>
				<path d="M36 38C36 40 38 41 40 41C42 41 44 40 44 38" stroke="#C2410C" stroke-width="1.5" stroke-linecap="round"/>
				<path d="M24 32C24 23 31 16 40 16C49 16 56 23 56 32" stroke="#0F172A" stroke-width="3" stroke-linecap="round" fill="none"/>
				<rect x="22" y="28" width="4" height="8" rx="2" fill="#0F172A"/>
				<rect x="54" y="28" width="4" height="8" rx="2" fill="#0F172A"/>
				<path d="M24 34C24 40 30 42 34 42" stroke="#0F172A" stroke-width="2" stroke-linecap="round" fill="none"/>
				<circle cx="35" cy="42" r="2.5" fill="#22C55E"/>
			</svg>',
			$size,
			esc_attr( $class )
		);
	}

	/**
	 * Render the sidebar sticky free-setup card (admin shell).
	 *
	 * @return void
	 */
	public static function render_sidebar_card() {
		return;
		if ( ! self::is_offer_active() ) {
			return;
		}

		$days_text = self::get_days_left_label();
		?>
		<a href="<?php echo esc_url( self::get_whatsapp_url() ); ?>" target="_blank" rel="noopener noreferrer" class="epc-sidebar-setup-card">
			<div class="epc-sidebar-setup-top">
				<div class="epc-sidebar-avatar-wrap">
					<?php echo self::get_avatar_svg( 'epc-sidebar-avatar-img', 38 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted SVG from helper. ?>
					<span class="epc-sidebar-status-dot" aria-hidden="true"></span>
				</div>
				<div class="epc-sidebar-badge">
					<span><?php echo esc_html( $days_text ); ?></span>
				</div>
			</div>
			<div class="epc-sidebar-setup-content">
				<h4 class="epc-sidebar-setup-title"><?php esc_html_e( 'Get free setup help', 'epasscard' ); ?></h4>
				<p class="epc-sidebar-setup-desc"><?php esc_html_e( 'Get help from our experts at no cost', 'epasscard' ); ?></p>
			</div>
		</a>
		<?php
	}
}
