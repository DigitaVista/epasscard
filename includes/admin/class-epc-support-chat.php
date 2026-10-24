<?php
/**
 * "Chat with us": EpassCard support chat (Crisp) in the EpassCard top bar.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "Chat with us" button in the top bar of the EpassCard screens (admins only).
 *
 * - Nothing from Crisp loads until the person starts a chat. The first time, the panel says
 *   what chatting sends (and lists it) and links the privacy policies; starting a chat is the
 *   consent, remembered per user.
 * - The chat opens in Crisp's own window, with the person's name and email and the site's
 *   details (no API key, no customer data) as Crisp session data, so the first reply does not
 *   have to ask. Three quick starts fill in the first message.
 * - Green "Online" during support hours (10:00-19:00 Asia/Dhaka, Saturday to Thursday; filter
 *   epc_support_chat_hours), grey "Leave a message" outside them.
 * - Each user can hide the button (Settings → Advanced); filter epc_support_chat_enabled turns
 *   it off for the whole site.
 */
class EPC_Support_Chat {

	/**
	 * EpassCard's Crisp website id (the one app.epasscard.com uses).
	 */
	const WEBSITE_ID = '7372926d-fd4c-4df0-8acf-5465c1e18344';

	/**
	 * Crisp's chatbox loader.
	 */
	const LOADER = 'https://client.crisp.chat/l.js';

	/**
	 * User meta: time the user first started a chat (their consent).
	 */
	const META_CONSENT = 'epc_support_chat_consent';

	/**
	 * User meta: '1' when the user hid the button.
	 */
	const META_HIDDEN = 'epc_support_chat_hidden';

	/**
	 * Privacy policies linked in the panel.
	 */
	const PRIVACY_URL       = 'https://www.epasscard.com/privacy-policy/';
	const CRISP_PRIVACY_URL = 'https://crisp.chat/en/privacy/';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_action( 'wp_ajax_epc_support_chat_consent', array( __CLASS__, 'ajax_consent' ) );
		add_action( 'wp_ajax_epc_support_chat_visibility', array( __CLASS__, 'ajax_visibility' ) );
	}

	/**
	 * Whether the site allows the chat at all.
	 *
	 * @return bool
	 */
	public static function site_enabled() {
		/**
		 * Filter whether the "Chat with us" button can appear on this site (e.g. false for
		 * agencies that run their own support).
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'epc_support_chat_enabled', true );
	}

	/**
	 * Whether the current user sees the button.
	 *
	 * @return bool
	 */
	public static function is_visible() {
		return self::site_enabled() && current_user_can( 'manage_options' ) && '1' !== (string) get_user_meta( get_current_user_id(), self::META_HIDDEN, true );
	}

	/**
	 * Support hours.
	 *
	 * @return array{timezone: string, days: array<int, int>, open: string, close: string} Days are
	 *         ISO-8601 numbers (1 = Monday ... 7 = Sunday); open and close are "H:i".
	 */
	public static function hours() {
		$default = array(
			'timezone' => 'Asia/Dhaka',
			'days'     => array( 6, 7, 1, 2, 3, 4 ),
			'open'     => '10:00',
			'close'    => '19:00',
		);
		/**
		 * Filter EpassCard support hours (they decide the green "Online" dot).
		 *
		 * @param array $hours timezone, days (ISO-8601, 1 = Monday), open and close ("H:i").
		 */
		$hours = apply_filters( 'epc_support_chat_hours', $default );
		return is_array( $hours ) ? array_merge( $default, $hours ) : $default;
	}

	/**
	 * Whether support is online at a time.
	 *
	 * @param int|null $now Unix time; null = now.
	 * @return bool
	 */
	public static function is_online( $now = null ) {
		$hours = self::hours();
		try {
			$time = new DateTimeImmutable( '@' . ( null === $now ? time() : (int) $now ) );
			$time = $time->setTimezone( new DateTimeZone( (string) $hours['timezone'] ) );
		} catch ( Exception $e ) {
			return false;
		}
		$clock = $time->format( 'H:i' );
		return in_array( (int) $time->format( 'N' ), array_map( 'intval', (array) $hours['days'] ), true ) && $clock >= (string) $hours['open'] && $clock < (string) $hours['close'];
	}

	/**
	 * What the chat sends with the session, as label => value (shown in the panel too).
	 *
	 * @return array<string, array{label: string, value: string}> Keyed by Crisp session data key.
	 */
	public static function context() {
		global $wp_version;
		$settings = EPC_Connection::get_settings();
		$meter    = class_exists( 'EPC_Plan' ) ? EPC_Plan::get_meter() : array();
		$enabled  = EPC_Module_Settings::get_enabled_slugs();
		$designs  = 0;
		foreach ( $enabled as $slug ) {
			$module = EPC_Module_Loader::get_module_instance( (string) $slug );
			if ( $module instanceof EPC_Module ) {
				$designs += count( (array) $module->get_mappings() );
				if ( class_exists( 'EPC_Default_Design' ) && null !== EPC_Default_Design::get( (string) $slug ) ) {
					++$designs;
				}
			}
		}
		$plan = (string) ( $meter['plan'] ?? '' );
		if ( ! empty( $meter['limit'] ) ) {
			/* translators: 1: plan name, 2: cards used, 3: plan limit */
			$plan = trim( sprintf( __( '%1$s (%2$d of %3$d cards)', 'epasscard' ), $plan, (int) $meter['used'], (int) $meter['limit'] ) );
		}
		$values = array(
			'site-url'           => array( __( 'Site', 'epasscard' ), home_url( '/' ) ),
			'wordpress-version'  => array( __( 'WordPress', 'epasscard' ), (string) $wp_version ),
			'php-version'        => array( __( 'PHP', 'epasscard' ), PHP_VERSION ),
			'plugin-version'     => array( __( 'EpassCard plugin', 'epasscard' ), EPC_VERSION ),
			'epasscard-email'    => array( __( 'EpassCard account', 'epasscard' ), EPC_Connection::is_connected() ? (string) ( $settings['connected_email'] ?? '' ) : __( 'Not connected', 'epasscard' ) ),
			'epasscard-plan'     => array( __( 'Plan', 'epasscard' ), $plan ),
			'integrations'       => array( __( 'Integrations on', 'epasscard' ), implode( ', ', array_map( 'strval', $enabled ) ) ),
			'designs'            => array( __( 'Card designs', 'epasscard' ), (string) $designs ),
			'cards'              => array( __( 'Active cards', 'epasscard' ), (string) EPC_DB::count_all_real_passes( 'active' ) ),
			'screen'             => array( __( 'Screen', 'epasscard' ), self::current_screen() ),
			'last-failed-call'   => array( __( 'Last failed call to EpassCard', 'epasscard' ), self::last_failed_call() ),
		);
		$out = array();
		foreach ( $values as $key => $pair ) {
			$out[ $key ] = array(
				'label' => (string) $pair[0],
				'value' => '' !== trim( (string) $pair[1] ) ? (string) $pair[1] : '-',
			);
		}
		return $out;
	}

	/**
	 * The EpassCard screen the user is on ("epc-settings, tab advanced").
	 *
	 * @return string
	 */
	private static function current_screen() {
		$page = EPC_Admin_App::current_page();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen name.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen name.
		$module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : '';
		return trim( $page . ( '' !== $tab ? ', tab ' . $tab : '' ) . ( '' !== $module ? ', ' . $module : '' ) );
	}

	/**
	 * The last failed call to EpassCard from the API log: when, what and the error. Never the
	 * key (the log stores bodies with secrets removed; only the endpoint path and the error are
	 * used here).
	 *
	 * @return string
	 */
	public static function last_failed_call() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Newest failed row of EpassCard's own log.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT method, endpoint_url, response_body, http_status, error_code, context, created_at FROM %i WHERE is_success = 0 ORDER BY id DESC LIMIT 1', EPC_Api_Log::table_name() ) );
		if ( ! $row ) {
			return '';
		}
		$path    = (string) wp_parse_url( (string) $row->endpoint_url, PHP_URL_PATH );
		$body    = json_decode( (string) $row->response_body, true );
		$message = is_array( $body ) ? (string) ( $body['message'] ?? ( $body['error'] ?? '' ) ) : '';
		$parts   = array(
			(string) $row->created_at . ' UTC',
			trim( (string) $row->method . ' ' . $path ),
			(int) $row->http_status > 0 ? 'HTTP ' . (int) $row->http_status : '',
			(string) $row->error_code,
			is_scalar( $message ) ? wp_strip_all_tags( (string) $message ) : '',
			'' !== (string) $row->context ? '(' . (string) $row->context . ')' : '',
		);
		$text = implode( ' · ', array_filter( $parts, 'strlen' ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}

	/**
	 * The email given to Crisp: empty for an address on a reserved test domain (.test,
	 * .example, .invalid, .localhost, .local), which Crisp rejects as mistyped.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function chat_email( $email ) {
		$email = sanitize_email( (string) $email );
		$tld   = strtolower( (string) substr( (string) strrchr( $email, '.' ), 1 ) );
		return is_email( $email ) && ! in_array( $tld, array( 'test', 'example', 'invalid', 'localhost', 'local' ), true ) ? $email : '';
	}

	/**
	 * Load the button's script and styles on EpassCard screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! EPC_Admin_Shell::is_epc_screen( $hook ) || ! self::is_visible() ) {
			return;
		}
		$css = EPC_PLUGIN_DIR . 'admin/css/support-chat.css';
		$js  = EPC_PLUGIN_DIR . 'admin/js/support-chat.js';
		wp_enqueue_style( 'epc-support-chat', EPC_PLUGIN_URL . 'admin/css/support-chat.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : EPC_VERSION );
		wp_enqueue_script( 'epc-support-chat', EPC_PLUGIN_URL . 'admin/js/support-chat.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : EPC_VERSION, true );

		$user = wp_get_current_user();
		$data = array();
		foreach ( self::context() as $key => $item ) {
			$data[] = array( $key, $item['value'] );
		}
		wp_localize_script(
			'epc-support-chat',
			'epcSupportChat',
			array(
				'websiteId' => self::WEBSITE_ID,
				'loader'    => self::LOADER,
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'epc_support_chat' ),
				'consented' => '' !== (string) get_user_meta( $user->ID, self::META_CONSENT, true ),
				'user'      => array(
					'name'  => (string) $user->display_name,
					'email' => self::chat_email( (string) $user->user_email ),
				),
				'data'      => $data,
				'starts'    => array(
					'setup'   => __( 'Hi! Can you help me set up EpassCard on my site?', 'epasscard' ),
					'failed'  => __( 'Hi! A card failed on my site. The last failed call is in the details I sent.', 'epasscard' ),
					'billing' => __( 'Hi! I have a question about my EpassCard plan or billing.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * The button and its panel, in the top bar (after Help).
	 *
	 * @return void
	 */
	public static function render_button() {
		if ( ! self::is_visible() ) {
			return;
		}
		$online    = self::is_online();
		$consented = '' !== (string) get_user_meta( get_current_user_id(), self::META_CONSENT, true );
		$status    = $online ? __( 'Online · replies in about 10 min', 'epasscard' ) : __( 'Leave a message · we reply by email', 'epasscard' );
		?>
		<div class="epc-chat" data-epc-chat>
			<button type="button" class="epc-chat__button" aria-expanded="false" aria-controls="epc-chat-panel" title="<?php echo esc_attr( $status ); ?>">
				<span class="epc-chat__dot<?php echo $online ? ' is-online' : ''; ?>" aria-hidden="true"></span>
				<?php esc_html_e( 'Chat with us', 'epasscard' ); ?>
				<span class="screen-reader-text"><?php echo esc_html( '(' . $status . ')' ); ?></span>
			</button>
			<div class="epc-chat__panel" id="epc-chat-panel" role="dialog" aria-modal="false" aria-labelledby="epc-chat-title" hidden>
				<div class="epc-chat__head">
					<h2 class="epc-chat__title" id="epc-chat-title"><?php esc_html_e( 'Chat with EpassCard support', 'epasscard' ); ?></h2>
					<button type="button" class="epc-chat__close" data-epc-chat-close aria-label="<?php esc_attr_e( 'Close', 'epasscard' ); ?>">&times;</button>
				</div>
				<p class="epc-chat__status<?php echo $online ? ' is-online' : ''; ?>">
					<span class="epc-chat__dot<?php echo $online ? ' is-online' : ''; ?>" aria-hidden="true"></span>
					<?php echo esc_html( $online ? __( 'Online · replies in about 10 min', 'epasscard' ) : __( 'We reply by email, usually within a few hours.', 'epasscard' ) ); ?>
				</p>
				<p class="epc-chat__label"><?php esc_html_e( 'Start with', 'epasscard' ); ?></p>
				<div class="epc-chat__starts">
					<button type="button" class="epc-chat__start" data-epc-chat-start="setup"><?php esc_html_e( 'Help me set up', 'epasscard' ); ?></button>
					<button type="button" class="epc-chat__start" data-epc-chat-start="failed"><?php esc_html_e( 'A card failed', 'epasscard' ); ?></button>
					<button type="button" class="epc-chat__start" data-epc-chat-start="billing"><?php esc_html_e( 'Billing question', 'epasscard' ); ?></button>
					<button type="button" class="epc-chat__start epc-chat__start--plain" data-epc-chat-start=""><?php esc_html_e( 'Something else', 'epasscard' ); ?></button>
				</div>
				<p class="epc-chat__consent"<?php echo $consented ? ' hidden' : ''; ?>>
					<?php
					printf(
						/* translators: 1: EpassCard privacy policy link, 2: Crisp privacy policy link */
						esc_html__( 'Chatting sends your name, email, site URL and plugin details to Crisp (our support tool). %1$s · %2$s', 'epasscard' ),
						'<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'epasscard' ) . '</a>',
						'<a href="' . esc_url( self::CRISP_PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Crisp privacy policy', 'epasscard' ) . '</a>'
					);
					?>
				</p>
				<details class="epc-chat__details">
					<summary><?php esc_html_e( 'What we send', 'epasscard' ); ?></summary>
					<dl>
						<dt><?php esc_html_e( 'You', 'epasscard' ); ?></dt>
						<dd><?php echo esc_html( wp_get_current_user()->display_name . ' · ' . wp_get_current_user()->user_email ); ?></dd>
						<?php foreach ( self::context() as $item ) : ?>
							<dt><?php echo esc_html( $item['label'] ); ?></dt>
							<dd><?php echo esc_html( $item['value'] ); ?></dd>
						<?php endforeach; ?>
					</dl>
					<p class="description"><?php esc_html_e( 'Never your API key or your customers\' data.', 'epasscard' ); ?></p>
				</details>
				<p class="epc-chat__error" role="alert" hidden><?php esc_html_e( 'The chat could not load. Please email hello@epasscard.com.', 'epasscard' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: the user started their first chat (their consent to send the details to Crisp).
	 *
	 * @return void
	 */
	public static function ajax_consent() {
		check_ajax_referer( 'epc_support_chat', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}
		if ( '' === (string) get_user_meta( get_current_user_id(), self::META_CONSENT, true ) ) {
			update_user_meta( get_current_user_id(), self::META_CONSENT, (string) time() );
		}
		wp_send_json_success();
	}

	/**
	 * AJAX: Settings → Advanced, "Show the Chat with us button" (per user).
	 *
	 * @return void
	 */
	public static function ajax_visibility() {
		check_ajax_referer( 'epc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$show = ! empty( $_POST['epc_support_chat_show'] );
		if ( $show ) {
			delete_user_meta( get_current_user_id(), self::META_HIDDEN );
		} else {
			update_user_meta( get_current_user_id(), self::META_HIDDEN, '1' );
		}
		wp_send_json_success(
			array(
				'message' => $show ? __( 'Saved. The Chat with us button is shown to you.', 'epasscard' ) : __( 'Saved. The Chat with us button is hidden for you.', 'epasscard' ),
			)
		);
	}
}
