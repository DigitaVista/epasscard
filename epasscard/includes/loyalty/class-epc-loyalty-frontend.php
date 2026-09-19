<?php
/**
 * Customer-facing loyalty wallet (My Account).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce My Account loyalty wallet endpoint and claim UI.
 */
class EPC_Loyalty_Frontend {

	public const WC_ENDPOINT = 'loyalty-wallet';

	/**
	 * Rewrite flush marker. Bump when the endpoint slug or mask changes.
	 */
	private const REWRITE_VERSION = '2';

	/**
	 * Register hooks when the loyalty module is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush_rewrites' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::WC_ENDPOINT . '_endpoint', array( __CLASS__, 'render_wallet' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'epc_enqueue_frontend_assets', array( __CLASS__, 'maybe_enqueue_shared_styles' ) );
	}

	/**
	 * Whether the loyalty module is loaded and available.
	 *
	 * @return bool
	 */
	private static function is_active() {
		if ( ! function_exists( 'epc_plugin' ) ) {
			return false;
		}
		$module = epc_plugin()->get_module( 'woocommerce-loyalty' );
		return $module && $module->is_available();
	}

	/**
	 * Register the My Account endpoint.
	 *
	 * Always register when WooCommerce is loaded so activation and permalink
	 * flushes include the rule. Gating on the loyalty module caused 404s:
	 * activation ran before modules booted, flushed rules without this endpoint,
	 * then stored a flush flag so it never retried.
	 *
	 * @return void
	 */
	public static function register_endpoint() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_rewrite_endpoint( self::WC_ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Tell WooCommerce this is a My Account query var so /my-account/loyalty-wallet/ resolves.
	 *
	 * @param array<string, string> $query_vars Endpoint slug map.
	 * @return array<string, string>
	 */
	public static function add_query_var( $query_vars ) {
		$query_vars[ self::WC_ENDPOINT ] = self::WC_ENDPOINT;
		return $query_vars;
	}

	/**
	 * Flush rewrite rules once after the endpoint is registered.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'epc_loyalty_wallet_rewrite_flushed' ) === self::REWRITE_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'epc_loyalty_wallet_rewrite_flushed', self::REWRITE_VERSION, false );
	}

	/**
	 * Insert Loyalty wallet into the My Account menu.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_item( $items ) {
		if ( ! self::is_active() || ! is_user_logged_in() ) {
			return $items;
		}

		$new      = array();
		$inserted = false;
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( ! $inserted && ( EPC_Frontend::WC_ENDPOINT === $key || 'orders' === $key ) ) {
				// Prefer placing after wallet-passes when present; otherwise after orders.
				if ( EPC_Frontend::WC_ENDPOINT === $key || ! isset( $items[ EPC_Frontend::WC_ENDPOINT ] ) ) {
					$new[ self::WC_ENDPOINT ] = __( 'Loyalty wallet', 'epasscard' );
					$inserted                 = true;
				}
			}
		}

		if ( ! $inserted ) {
			$new[ self::WC_ENDPOINT ] = __( 'Loyalty wallet', 'epasscard' );
		}

		return $new;
	}

	/**
	 * Load wallet assets on the loyalty endpoint.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() || ! is_user_logged_in() ) {
			return;
		}

		$on_endpoint = false;
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			global $wp;
			$on_endpoint = isset( $wp->query_vars[ self::WC_ENDPOINT ] );
		}

		if ( ! $on_endpoint ) {
			return;
		}

		wp_enqueue_style(
			'epc-loyalty-wallet',
			EPC_PLUGIN_URL . 'assets/frontend/loyalty-wallet.css',
			array(),
			EPC_VERSION
		);
		wp_enqueue_script(
			'epc-loyalty-wallet',
			EPC_PLUGIN_URL . 'assets/frontend/loyalty-wallet.js',
			array( 'jquery' ),
			EPC_VERSION,
			true
		);
		wp_localize_script(
			'epc-loyalty-wallet',
			'epcLoyaltyWallet',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epc_loyalty_claim' ),
				'i18n'    => array(
					'claiming' => __( 'Claiming…', 'epasscard' ),
					'claimed'  => __( 'Reward claimed.', 'epasscard' ),
					'error'    => __( 'Unable to claim this reward.', 'epasscard' ),
					'empty'    => __( 'You have no unclaimed rewards right now.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Also allow shared frontend styles on the loyalty endpoint.
	 *
	 * @param bool $should_load Current flag.
	 * @return bool
	 */
	public static function maybe_enqueue_shared_styles( $should_load ) {
		if ( $should_load || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return $should_load;
		}
		global $wp;
		return isset( $wp->query_vars[ self::WC_ENDPOINT ] );
	}

	/**
	 * Render the loyalty wallet dashboard.
	 *
	 * @param string $endpoint_value Endpoint value (history page number).
	 * @return void
	 */
	public static function render_wallet( $endpoint_value = '' ) {
		if ( ! self::is_active() ) {
			echo '<p>' . esc_html__( 'Loyalty is not available.', 'epasscard' ) . '</p>';
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			echo '<p>' . esc_html__( 'Please sign in to view your loyalty wallet.', 'epasscard' ) . '</p>';
			return;
		}

		$account = EPC_Loyalty_Account_Service::get_or_create( $user_id );
		if ( is_wp_error( $account ) ) {
			echo '<p>' . esc_html( $account->get_error_message() ) . '</p>';
			return;
		}

		$per_page = (int) apply_filters( 'epc_loyalty_my_account_history_per_page', 20, $user_id );
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = absint( $endpoint_value );
		if ( $page < 1 ) {
			$page = 1;
		}

		$summary = EPC_Loyalty_Customer_Service::get_customer_summary( $account );
		$ledger  = EPC_Loyalty_Ledger_Service::query_for_user(
			$user_id,
			array(
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$total_pages = max( 1, (int) ceil( (int) ( $ledger['total'] ?? 0 ) / $per_page ) );
		if ( $page > $total_pages ) {
			$page   = $total_pages;
			$ledger = EPC_Loyalty_Ledger_Service::query_for_user(
				$user_id,
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);
		}
		$ledger['page']        = $page;
		$ledger['per_page']    = $per_page;
		$ledger['total_pages'] = $total_pages;
		$redemption            = class_exists( 'EPC_Loyalty_Redemption_Service' )
			? EPC_Loyalty_Redemption_Service::get_settings()
			: array();

		/**
		 * Filter loyalty My Account payload.
		 *
		 * @param array<string, mixed> $data Wallet data.
		 * @param int                  $user_id User ID.
		 */
		$data = (array) apply_filters(
			'epc_loyalty_my_account_data',
			array(
				'summary'    => $summary,
				'ledger'     => $ledger,
				'redemption' => $redemption,
			),
			$user_id
		);

		$summary = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : $summary;
		$ledger  = isset( $data['ledger'] ) && is_array( $data['ledger'] ) ? $data['ledger'] : $ledger;
		$design  = class_exists( 'EPC_Loyalty_Pass_Design_Service' )
			? EPC_Loyalty_Pass_Design_Service::get_design()
			: array();

		include EPC_PLUGIN_DIR . 'assets/frontend/loyalty-wallet.php';
	}
}
