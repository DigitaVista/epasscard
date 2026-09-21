<?php
/**
 * Checkout points redemption for classic and block flows.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies points as an order-bound WooCommerce discount coupon with atomic lifecycle.
 *
 * Cart selection only mirrors a server-side quote. Points are reserved when the
 * order is created, committed on successful payment/status, released on failure
 * or cancellation, and restored on refunds according to the saved policy.
 */
class EPC_Loyalty_Redemption_Service {

	public const OPTION_KEY = 'epc_loyalty_redemption';
	public const SESSION_KEY = 'epc_loyalty_redeem_points';
	public const STORE_NAMESPACE = 'epasscard-loyalty';
	public const COUPON_PREFIX = 'epc-loyalty-';

	/**
	 * Prevent recursive cart coupon syncs.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * When the shopper removes the loyalty coupon, do not re-apply it in this request.
	 *
	 * @var bool
	 */
	private static $prevent_reapply = false;

	/**
	 * Register cart, checkout, Store API, and order lifecycle hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'maybe_sync_cart_coupon' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_sync_cart_coupon' ), 20 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'maybe_sync_cart_coupon' ), 20 );
		add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'on_removed_coupon' ), 0 );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'filter_virtual_coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_virtual_coupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_message', array( __CLASS__, 'filter_coupon_message' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'filter_coupon_label' ), 10, 2 );

		add_action( 'woocommerce_cart_coupon', array( __CLASS__, 'render_cart_widget' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_widget' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_guest_notice' ), 5 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_guest_notice' ), 5 );

		add_action( 'wp_ajax_epc_loyalty_set_redemption', array( __CLASS__, 'ajax_set_redemption' ) );
		add_action( 'wc_ajax_epc_loyalty_set_redemption', array( __CLASS__, 'ajax_set_redemption' ) );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_order_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'attach_order_meta' ), 20, 1 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_redemption' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reserve_for_order_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_for_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'commit_for_order_id' ), 25, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 25, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 25, 2 );

		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		}
	}

	/**
	 * Read sanitized redemption settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$refund_policy = sanitize_key( (string) ( $saved['refund_restore_policy'] ?? 'proportional' ) );
		if ( ! in_array( $refund_policy, array( 'proportional', 'full', 'none' ), true ) ) {
			$refund_policy = 'proportional';
		}

		$settings = array(
			'version'               => 1,
			'enabled'               => ! isset( $saved['enabled'] ) || self::to_bool( $saved['enabled'] ),
			'points_per_currency'   => max( 0.000001, (float) ( $saved['points_per_currency'] ?? 100 ) ),
			'minimum_balance'       => max( 0, absint( $saved['minimum_balance'] ?? 0 ) ),
			'increment'             => max( 1, absint( $saved['increment'] ?? 1 ) ),
			'max_order_percent'     => min( 100, max( 0, (float) ( $saved['max_order_percent'] ?? 100 ) ) ),
			'excluded_products'     => self::sanitize_ids( $saved['excluded_products'] ?? array() ),
			'excluded_categories'   => self::sanitize_ids( $saved['excluded_categories'] ?? array() ),
			'include_sale_items'    => ! isset( $saved['include_sale_items'] ) || self::to_bool( $saved['include_sale_items'] ),
			'refund_restore_policy' => $refund_policy,
		);

		/**
		 * Filter normalized loyalty redemption settings.
		 *
		 * @param array<string, mixed> $settings Redemption settings.
		 */
		return (array) apply_filters( 'epc_loyalty_redemption_settings', $settings );
	}

	/**
	 * Persist sanitized redemption settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public static function save_settings( array $settings ) {
		update_option( self::OPTION_KEY, $settings );
		$clean = self::get_settings();
		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	/**
	 * Register Store API cart extensions for Checkout Block.
	 *
	 * @return void
	 */
	public static function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::STORE_NAMESPACE,
				'data_callback'   => array( __CLASS__, 'get_store_api_data' ),
				'schema_callback' => array( __CLASS__, 'get_store_api_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_NAMESPACE,
				'callback'  => array( __CLASS__, 'handle_store_api_update' ),
			)
		);
	}

	/**
	 * Cart/checkout extension payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_store_api_data() {
		return self::get_quote_payload();
	}

	/**
	 * Store API schema for the loyalty extension.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_store_api_schema() {
		return array(
			'enabled'             => array( 'type' => 'boolean' ),
			'can_redeem'          => array( 'type' => 'boolean' ),
			'is_guest'            => array( 'type' => 'boolean' ),
			'balance'             => array( 'type' => 'integer' ),
			'requested_points'    => array( 'type' => 'integer' ),
			'applied_points'      => array( 'type' => 'integer' ),
			'max_points'          => array( 'type' => 'integer' ),
			'discount'            => array( 'type' => 'string' ),
			'currency'            => array( 'type' => 'string' ),
			'minimum_balance'     => array( 'type' => 'integer' ),
			'increment'           => array( 'type' => 'integer' ),
			'points_per_currency' => array( 'type' => 'number' ),
			'message'             => array( 'type' => 'string' ),
			'login_url'           => array( 'type' => 'string' ),
		);
	}

	/**
	 * Apply a Store API cart extension update.
	 *
	 * @param array<string, mixed> $data Extension data.
	 * @return void
	 */
	public static function handle_store_api_update( $data ) {
		$data   = is_array( $data ) ? $data : array();
		$points = isset( $data['points'] ) ? absint( $data['points'] ) : 0;
		$result = self::set_requested_points( $points );
		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Enqueue classic and block checkout assets.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::get_settings()['enabled'] || ! function_exists( 'is_cart' ) ) {
			return;
		}

		$on_cart     = is_cart();
		$on_checkout = is_checkout() && ! is_order_received_page();
		$has_blocks  = has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' );
		if ( ! $on_cart && ! $on_checkout && ! $has_blocks ) {
			return;
		}

		$handle = 'epc-loyalty-checkout';
		wp_register_style( $handle, EPC_PLUGIN_URL . 'assets/frontend/loyalty-checkout.css', array(), EPC_VERSION );
		wp_enqueue_style( $handle );

		wp_register_script( $handle, EPC_PLUGIN_URL . 'assets/frontend/loyalty-checkout.js', array( 'jquery' ), EPC_VERSION, true );
		wp_localize_script(
			$handle,
			'epcLoyaltyCheckout',
			array(
				'ajaxUrl'   => class_exists( 'WC_AJAX' )
					? WC_AJAX::get_endpoint( 'epc_loyalty_set_redemption' )
					: admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'epc_loyalty_redemption' ),
				'namespace' => self::STORE_NAMESPACE,
				'i18n'      => array(
					'apply'   => __( 'Apply points', 'epasscard' ),
					'remove'  => __( 'Remove', 'epasscard' ),
					'balance' => __( 'Available points', 'epasscard' ),
					'guest'   => __( 'Sign in to redeem loyalty points.', 'epasscard' ),
					'error'   => __( 'Unable to update loyalty points.', 'epasscard' ),
				),
				'quote'     => self::get_quote_payload(),
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * AJAX handler for classic cart/checkout redemption updates.
	 *
	 * @return void
	 */
	public static function ajax_set_redemption() {
		check_ajax_referer( 'epc_loyalty_redemption', 'nonce' );

		$points = isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0;
		$result = self::set_requested_points( $points );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		wp_send_json_success(
			array(
				'message' => __( 'Loyalty points updated.', 'epasscard' ),
				'quote'   => self::get_quote_payload(),
			)
		);
	}

	/**
	 * Persist the requested redemption amount and sync the cart coupon.
	 *
	 * @param int $points Requested points.
	 * @return true|\WP_Error
	 */
	public static function set_requested_points( $points ) {
		self::$prevent_reapply = false;

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'epc_loyalty_redemption_disabled', __( 'Points redemption is disabled.', 'epasscard' ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			self::clear_session_points();
			return new WP_Error( 'epc_loyalty_guest', __( 'Sign in to redeem loyalty points.', 'epasscard' ) );
		}

		$points = absint( $points );
		if ( $points > 0 ) {
			$quote = self::build_quote( $user_id, $points );
			if ( is_wp_error( $quote ) ) {
				return $quote;
			}
			$points = (int) $quote['applied_points'];
		}

		self::set_session_points( $points );
		self::maybe_sync_cart_coupon();
		return true;
	}

	/**
	 * Quote payload shared by classic UI and Store API.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_quote_payload() {
		$settings = self::get_settings();
		$user_id  = get_current_user_id();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

		$payload = array(
			'enabled'             => ! empty( $settings['enabled'] ),
			'can_redeem'          => false,
			'is_guest'            => $user_id <= 0,
			'balance'             => 0,
			'requested_points'    => self::get_session_points(),
			'applied_points'      => 0,
			'max_points'          => 0,
			'discount'            => '0',
			'currency'            => $currency,
			'minimum_balance'     => (int) $settings['minimum_balance'],
			'increment'           => (int) $settings['increment'],
			'points_per_currency' => (float) $settings['points_per_currency'],
			'message'             => '',
			'login_url'           => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
		);

		if ( empty( $settings['enabled'] ) ) {
			$payload['message'] = __( 'Points redemption is currently disabled.', 'epasscard' );
			return $payload;
		}

		if ( $user_id <= 0 ) {
			$payload['message'] = __( 'Sign in or create an account to redeem loyalty points.', 'epasscard' );
			return $payload;
		}

		$quote = self::build_quote( $user_id, self::get_session_points() );
		if ( is_wp_error( $quote ) ) {
			$payload['message'] = $quote->get_error_message();
			$account            = EPC_Loyalty_Account_Service::get_or_create( $user_id );
			if ( ! is_wp_error( $account ) ) {
				$payload['balance'] = (int) $account->points_balance;
			}
			return $payload;
		}

		return array_merge( $payload, $quote );
	}

	/**
	 * Build a server-side redemption quote.
	 *
	 * @param int $user_id User ID.
	 * @param int $requested Requested points.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build_quote( $user_id, $requested = 0 ) {
		$settings = self::get_settings();
		$account  = EPC_Loyalty_Account_Service::get_or_create( $user_id );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$balance = (int) $account->points_balance;
		if ( $balance < 0 ) {
			return new WP_Error(
				'epc_loyalty_negative_balance',
				__( 'Loyalty points cannot be redeemed while the account balance is negative.', 'epasscard' )
			);
		}

		$eligible = self::get_eligible_cart_subtotal();
		$max_by_percent = $eligible * ( (float) $settings['max_order_percent'] / 100 );
		$max_by_balance = self::points_to_currency( $balance, $settings );
		$max_discount   = min( $max_by_percent, $max_by_balance );
		$max_points     = self::currency_to_points( $max_discount, $settings );
		$max_points     = self::snap_points( $max_points, (int) $settings['increment'], $balance );

		$can_redeem = $balance >= (int) $settings['minimum_balance'] && $max_points > 0 && $eligible > 0;
		$requested  = absint( $requested );
		$applied    = 0;
		$discount   = 0.0;

		if ( $can_redeem && $requested > 0 ) {
			$applied  = self::snap_points( min( $requested, $max_points ), (int) $settings['increment'], $max_points );
			$discount = min( $max_discount, self::points_to_currency( $applied, $settings ) );
			$applied  = min( self::currency_to_points( $discount, $settings ), $max_points, $balance );
			$applied  = self::snap_points( $applied, (int) $settings['increment'], $max_points );
			$discount = min( $max_discount, self::points_to_currency( $applied, $settings ) );
		}

		return array(
			'enabled'             => true,
			'can_redeem'          => $can_redeem,
			'is_guest'            => false,
			'balance'             => $balance,
			'requested_points'    => $requested,
			'applied_points'      => $applied,
			'max_points'          => $max_points,
			'discount'            => self::format_decimal( $discount ),
			'currency'            => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'minimum_balance'     => (int) $settings['minimum_balance'],
			'increment'           => (int) $settings['increment'],
			'points_per_currency' => (float) $settings['points_per_currency'],
			'message'             => $can_redeem
				? ''
				: (
					$balance < (int) $settings['minimum_balance']
						? sprintf(
							/* translators: %d: minimum points. */
							__( 'You need at least %d points to redeem.', 'epasscard' ),
							(int) $settings['minimum_balance']
						)
						: __( 'No redeemable amount is available for this cart.', 'epasscard' )
				),
			'login_url'           => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
		);
	}

	/**
	 * Keep the virtual loyalty coupon aligned with the session quote.
	 *
	 * @param mixed $cart Optional cart.
	 * @return void
	 */
	public static function maybe_sync_cart_coupon( $cart = null ) {
		unset( $cart );

		if ( self::$syncing || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		self::$syncing = true;
		try {
			$settings = self::get_settings();
			$user_id  = get_current_user_id();
			$code     = self::coupon_code_for_user( $user_id );

			if ( self::$prevent_reapply ) {
				self::remove_loyalty_coupons_from_cart();
				return;
			}

			if ( empty( $settings['enabled'] ) || $user_id <= 0 ) {
				self::remove_loyalty_coupons_from_cart();
				self::clear_session_points();
				return;
			}

			$requested = self::get_session_points();
			$quote     = self::build_quote( $user_id, $requested );
			if ( is_wp_error( $quote ) || (int) $quote['applied_points'] <= 0 ) {
				self::set_session_points( 0 );
				self::remove_loyalty_coupons_from_cart();
				return;
			}

			if ( (int) $quote['applied_points'] !== $requested ) {
				self::set_session_points( (int) $quote['applied_points'] );
			}

			$applied = WC()->cart->get_applied_coupons();
			$applied = array_map( 'wc_format_coupon_code', is_array( $applied ) ? $applied : array() );
			foreach ( $applied as $existing ) {
				if ( self::is_loyalty_coupon_code( $existing ) && wc_format_coupon_code( $existing ) !== wc_format_coupon_code( $code ) ) {
					WC()->cart->remove_coupon( $existing );
				}
			}

			if ( ! in_array( wc_format_coupon_code( $code ), $applied, true ) ) {
				// Apply quietly during totals calculation to avoid recursive notices.
				WC()->cart->applied_coupons[] = $code;
			}
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Honor shopper removal of the virtual loyalty coupon.
	 *
	 * Checkout Blocks and classic totals both remove the coupon from the cart first.
	 * Without clearing the session quote, maybe_sync_cart_coupon() immediately puts it back.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	public static function on_removed_coupon( $code ) {
		if ( self::$syncing || ! self::is_loyalty_coupon_code( $code ) ) {
			return;
		}

		self::clear_session_points();
		self::$prevent_reapply = true;
	}

	/**
	 * Provide virtual coupon properties for the active redemption.
	 *
	 * @param array|false $data Coupon data.
	 * @param string      $code Coupon code.
	 * @param WC_Coupon   $coupon Coupon object.
	 * @return array|false
	 */
	public static function filter_virtual_coupon_data( $data, $code, $coupon = null ) {
		unset( $coupon );
		if ( ! self::is_loyalty_coupon_code( $code ) ) {
			return $data;
		}

		$user_id = self::user_id_from_coupon_code( $code );
		if ( $user_id <= 0 || $user_id !== get_current_user_id() ) {
			return false;
		}

		$quote = self::build_quote( $user_id, self::get_session_points() );
		if ( is_wp_error( $quote ) || (int) $quote['applied_points'] <= 0 ) {
			return false;
		}

		$settings = self::get_settings();
		return array(
			'id'                          => 0,
			'discount_type'               => 'fixed_cart',
			'amount'                      => (float) $quote['discount'],
			'individual_use'              => false,
			'product_ids'                 => array(),
			'excluded_product_ids'        => $settings['excluded_products'],
			'exclude_sale_items'          => empty( $settings['include_sale_items'] ),
			'product_categories'          => array(),
			'excluded_product_categories' => $settings['excluded_categories'],
			'minimum_amount'              => '',
			'maximum_amount'              => '',
			'usage_limit'                 => 0,
			'usage_limit_per_user'        => 0,
			'limit_usage_to_x_items'      => null,
			'usage_count'                 => 0,
			'free_shipping'               => false,
			'date_expires'                => null,
			'virtual'                     => true,
		);
	}

	/**
	 * Restrict loyalty coupons to their owning customer.
	 *
	 * @param bool      $valid Validity.
	 * @param WC_Coupon $coupon Coupon.
	 * @param WC_Discounts|null $discounts Discounts helper.
	 * @return bool
	 */
	public static function validate_virtual_coupon( $valid, $coupon, $discounts = null ) {
		unset( $discounts );
		if ( ! $valid || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) {
			return $valid;
		}

		$code = (string) $coupon->get_code();
		if ( ! self::is_loyalty_coupon_code( $code ) ) {
			return $valid;
		}

		$user_id = self::user_id_from_coupon_code( $code );
		if ( $user_id <= 0 || $user_id !== get_current_user_id() ) {
			throw new Exception( esc_html__( 'This loyalty coupon belongs to another customer.', 'epasscard' ) );
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			throw new Exception( esc_html__( 'Points redemption is disabled.', 'epasscard' ) );
		}

		$quote = self::build_quote( $user_id, self::get_session_points() );
		if ( is_wp_error( $quote ) || (int) $quote['applied_points'] <= 0 ) {
			throw new Exception(
				$quote instanceof WP_Error
					? esc_html( $quote->get_error_message() )
					: esc_html__( 'No loyalty points are available to redeem.', 'epasscard' )
			);
		}

		return true;
	}

	/**
	 * Friendly coupon flash message.
	 *
	 * @param string    $message Message.
	 * @param string    $message_code Code.
	 * @param WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function filter_coupon_message( $message, $message_code, $coupon ) {
		if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) || ! self::is_loyalty_coupon_code( $coupon->get_code() ) ) {
			return $message;
		}
		if ( 'coupon_success' === $message_code || 200 === (int) $message_code ) {
			return __( 'Loyalty points discount applied.', 'epasscard' );
		}
		return $message;
	}

	/**
	 * Label loyalty coupons in totals.
	 *
	 * @param string    $label Label.
	 * @param WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function filter_coupon_label( $label, $coupon ) {
		if ( is_object( $coupon ) && method_exists( $coupon, 'get_code' ) && self::is_loyalty_coupon_code( $coupon->get_code() ) ) {
			$points = self::get_session_points();
			return sprintf(
				/* translators: %d: redeemed points. */
				esc_html__( 'Loyalty points (%d)', 'epasscard' ),
				$points
			);
		}
		return $label;
	}

	/**
	 * Classic cart redemption widget.
	 *
	 * @return void
	 */
	public static function render_cart_widget() {
		self::render_widget( 'cart' );
	}

	/**
	 * Classic checkout redemption widget.
	 *
	 * @return void
	 */
	public static function render_checkout_widget() {
		self::render_widget( 'checkout' );
	}

	/**
	 * Guest prompt above cart/checkout.
	 *
	 * @return void
	 */
	public static function render_guest_notice() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || is_user_logged_in() ) {
			return;
		}
		$login = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		echo '<div class="woocommerce-info epc-loyalty-guest-notice">';
		echo esc_html__( 'Sign in or create an account to earn and redeem loyalty points.', 'epasscard' );
		echo ' <a href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'epasscard' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Fail classic checkout early when the quoted redemption is no longer valid.
	 *
	 * @return void
	 */
	public static function validate_checkout_redemption() {
		$requested = self::get_session_points();
		if ( $requested <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wc_add_notice( __( 'Sign in to redeem loyalty points.', 'epasscard' ), 'error' );
			return;
		}

		$quote = self::build_quote( $user_id, $requested );
		if ( is_wp_error( $quote ) ) {
			wc_add_notice( $quote->get_error_message(), 'error' );
			return;
		}

		if ( (int) $quote['applied_points'] !== $requested ) {
			self::set_session_points( (int) $quote['applied_points'] );
			self::maybe_sync_cart_coupon();
			if ( (int) $quote['applied_points'] <= 0 ) {
				wc_add_notice( __( 'Your loyalty points redemption is no longer available for this order.', 'epasscard' ), 'error' );
			}
		}
	}

	/**
	 * Copy the quoted redemption onto the order before save.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data Checkout data.
	 * @return void
	 */
	public static function attach_order_meta( $order, $data = array() ) {
		unset( $data );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return;
		}

		$quote = self::build_quote( $user_id, self::get_session_points() );
		if ( is_wp_error( $quote ) || (int) $quote['applied_points'] <= 0 ) {
			$order->delete_meta_data( '_epc_loyalty_points_redeemed' );
			$order->delete_meta_data( '_epc_loyalty_redemption_amount' );
			$order->delete_meta_data( '_epc_loyalty_redemption_coupon' );
			$order->delete_meta_data( '_epc_loyalty_redemption_status' );
			$order->delete_meta_data( '_epc_loyalty_redemption_settings' );
			return;
		}

		$settings = self::get_settings();
		$order->update_meta_data( '_epc_loyalty_points_redeemed', (int) $quote['applied_points'] );
		$order->update_meta_data( '_epc_loyalty_redemption_amount', self::format_decimal( $quote['discount'] ) );
		$order->update_meta_data( '_epc_loyalty_redemption_coupon', self::coupon_code_for_user( $user_id ) );
		$order->update_meta_data( '_epc_loyalty_redemption_status', 'pending' );
		$order->update_meta_data(
			'_epc_loyalty_redemption_settings',
			array(
				'points_per_currency'   => (float) $settings['points_per_currency'],
				'refund_restore_policy' => (string) $settings['refund_restore_policy'],
			)
		);
	}

	/**
	 * Reserve points after classic checkout creates the order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function reserve_for_order_id( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$result = self::reserve_points( $order );
		if ( is_wp_error( $result ) ) {
			$order->update_status( 'failed', $result->get_error_message() );
			throw new Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Reserve points after Store API checkout creates the order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function reserve_for_store_api_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $order->meta_exists( '_epc_loyalty_points_redeemed' ) ) {
			self::attach_order_meta( $order );
			$order->save();
		}

		$result = self::reserve_points( $order );
		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Atomically reserve redeemed points for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array|\WP_Error|null
	 */
	public static function reserve_points( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$user_id = (int) $order->get_user_id();
		$points  = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		$amount  = (float) $order->get_meta( '_epc_loyalty_redemption_amount', true );
		if ( $user_id <= 0 || $points <= 0 ) {
			return null;
		}

		$status = sanitize_key( (string) $order->get_meta( '_epc_loyalty_redemption_status', true ) );
		if ( in_array( $status, array( 'reserved', 'committed' ), true ) ) {
			return EPC_Loyalty_Ledger_Service::get_by_event_key( self::reservation_event_key( $order->get_id() ) );
		}
		if ( 'released' === $status ) {
			return self::reinstate_released_points( $order );
		}

		$result = EPC_Loyalty_Ledger_Service::record(
			$user_id,
			self::reservation_event_key( $order->get_id() ),
			'redemption_reservation',
			-$points,
			0,
			array(
				'order_id'                   => $order->get_id(),
				'amount'                     => -$amount,
				'currency'                   => $order->get_currency(),
				'require_available_balance'  => true,
				'description'                => sprintf(
					/* translators: %d: order ID. */
					__( 'Points reserved for order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'                       => array(
					'points' => $points,
					'coupon' => (string) $order->get_meta( '_epc_loyalty_redemption_coupon', true ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( '_epc_loyalty_redemption_status', 'reserved' );
		$order->save();
		self::clear_session_points();
		return $result;
	}

	/**
	 * Reserve points again when a failed/cancelled order is subsequently paid.
	 *
	 * @param WC_Order $order Order.
	 * @return array|\WP_Error|null
	 */
	private static function reinstate_released_points( $order ) {
		$user_id    = (int) $order->get_user_id();
		$points     = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		$amount     = (float) $order->get_meta( '_epc_loyalty_redemption_amount', true );
		$cycle      = absint( $order->get_meta( '_epc_loyalty_redemption_cycle', true ) ) + 1;
		$restored   = self::get_restored_points_for_order( $order->get_id() );
		$to_reserve = min( $points, $restored );

		if ( $user_id <= 0 || $to_reserve <= 0 ) {
			return null;
		}

		$result = EPC_Loyalty_Ledger_Service::record(
			$user_id,
			'redemption_reinstatement:' . $order->get_id() . ':' . $cycle,
			'redemption_reinstatement',
			-$to_reserve,
			0,
			array(
				'order_id'                  => $order->get_id(),
				'amount'                    => -abs( $amount ) * ( $to_reserve / max( 1, $points ) ),
				'currency'                  => $order->get_currency(),
				'require_available_balance' => true,
				'description'               => sprintf(
					/* translators: %d: order ID. */
					__( 'Points reserved again for order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'                      => array(
					'cycle'  => $cycle,
					'points' => $to_reserve,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( '_epc_loyalty_redemption_cycle', $cycle );
		$order->update_meta_data( '_epc_loyalty_redemption_status', 'reserved' );
		$order->save();
		return $result;
	}

	/**
	 * Commit a reservation after successful payment.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function commit_for_order_id( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( $order instanceof WC_Order ) {
			self::commit_points( $order );
		}
	}

	/**
	 * Handle terminal and paid status transitions.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		unset( $old_status );

		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order_id ) );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$status = sanitize_key( str_replace( 'wc-', '', (string) $new_status ) );
		if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			self::commit_points( $order );
			return;
		}

		if ( in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			self::release_points( $order, $status );
		}
	}

	/**
	 * Mark reserved points as committed without changing the balance again.
	 *
	 * @param WC_Order $order Order.
	 * @return array|\WP_Error|null
	 */
	public static function commit_points( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$points = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 || $points <= 0 ) {
			return null;
		}

		$reservation = EPC_Loyalty_Ledger_Service::get_by_event_key( self::reservation_event_key( $order->get_id() ) );
		if ( ! $reservation ) {
			$reserved = self::reserve_points( $order );
			if ( is_wp_error( $reserved ) || null === $reserved ) {
				return $reserved;
			}
		}

		$status = sanitize_key( (string) $order->get_meta( '_epc_loyalty_redemption_status', true ) );
		if ( 'released' === $status ) {
			$reinstated = self::reinstate_released_points( $order );
			if ( is_wp_error( $reinstated ) || null === $reinstated ) {
				return $reinstated;
			}
		}

		$result = EPC_Loyalty_Ledger_Service::record(
			$user_id,
			self::commit_event_key( $order->get_id() ),
			'redemption_commit',
			0,
			0,
			array(
				'order_id'    => $order->get_id(),
				'amount'      => 0,
				'currency'    => $order->get_currency(),
				'description' => sprintf(
					/* translators: %d: order ID. */
					__( 'Points redemption committed for order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'        => array(
					'points' => $points,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( '_epc_loyalty_redemption_status', 'committed' );
		$order->save();
		return $result;
	}

	/**
	 * Restore reserved points when checkout fails or the order is cancelled.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $reason Status reason.
	 * @return array|\WP_Error|null
	 */
	public static function release_points( $order, $reason = 'cancelled' ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$user_id = (int) $order->get_user_id();
		$points  = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		$amount  = (float) $order->get_meta( '_epc_loyalty_redemption_amount', true );
		if ( $user_id <= 0 || $points <= 0 ) {
			return null;
		}

		$reservation = EPC_Loyalty_Ledger_Service::get_by_event_key( self::reservation_event_key( $order->get_id() ) );
		if ( ! $reservation ) {
			return null;
		}

		$already_restored = self::get_restored_points_for_order( $order->get_id() );
		$remaining        = max( 0, abs( (int) $reservation->points_delta ) - $already_restored );
		if ( $remaining <= 0 ) {
			$order->update_meta_data( '_epc_loyalty_redemption_status', 'released' );
			$order->save();
			return null;
		}

		$result = EPC_Loyalty_Ledger_Service::record(
			$user_id,
			self::release_event_key(
				$order->get_id(),
				absint( $order->get_meta( '_epc_loyalty_redemption_cycle', true ) )
			),
			'redemption_release',
			$remaining,
			0,
			array(
				'order_id'    => $order->get_id(),
				'amount'      => abs( $amount ) * ( $remaining / max( 1, abs( (int) $reservation->points_delta ) ) ),
				'currency'    => $order->get_currency(),
				'description' => sprintf(
					/* translators: %d: order ID. */
					__( 'Points released for order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'        => array(
					'reason' => sanitize_key( (string) $reason ),
					'points' => $remaining,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( '_epc_loyalty_redemption_status', 'released' );
		$order->save();
		return $result;
	}

	/**
	 * Restore redeemed points for a refund according to policy.
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public static function on_order_refunded( $order_id, $refund_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order  = wc_get_order( absint( $order_id ) );
		$refund = wc_get_order( absint( $refund_id ) );
		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		self::restore_points_for_refund( $order, $refund );
	}

	/**
	 * Restore a proportional/full share of redeemed points after refund.
	 *
	 * @param WC_Order        $order Order.
	 * @param WC_Order_Refund $refund Refund.
	 * @return array|\WP_Error|null
	 */
	public static function restore_points_for_refund( $order, $refund ) {
		$user_id = (int) $order->get_user_id();
		$points  = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		$amount  = (float) $order->get_meta( '_epc_loyalty_redemption_amount', true );
		if ( $user_id <= 0 || $points <= 0 ) {
			return null;
		}

		$status = sanitize_key( (string) $order->get_meta( '_epc_loyalty_redemption_status', true ) );
		if ( 'released' === $status ) {
			return null;
		}

		$reservation = EPC_Loyalty_Ledger_Service::get_by_event_key( self::reservation_event_key( $order->get_id() ) );
		if ( ! $reservation ) {
			return null;
		}

		$snapshot = $order->get_meta( '_epc_loyalty_redemption_settings', true );
		$policy   = is_array( $snapshot ) ? sanitize_key( (string) ( $snapshot['refund_restore_policy'] ?? 'proportional' ) ) : 'proportional';
		if ( 'none' === $policy ) {
			return null;
		}

		$already = self::get_restored_points_for_order( $order->get_id() );
		$remaining = max( 0, abs( (int) $reservation->points_delta ) - $already );
		if ( $remaining <= 0 ) {
			return null;
		}

		if ( 'full' === $policy ) {
			$restore = $remaining;
		} else {
			$order_total    = max( 0, (float) $order->get_total() );
			$total_refunded = abs( (float) $order->get_total_refunded() );
			$ratio          = $order_total > 0 ? min( 1, $total_refunded / $order_total ) : 0;
			$target         = (int) floor( $points * $ratio );
			$restore        = max( 0, min( $remaining, $target - $already ) );
			if ( $restore <= 0 && $ratio >= 0.999 ) {
				$restore = $remaining;
			}
		}

		if ( $restore <= 0 ) {
			return null;
		}

		$result = EPC_Loyalty_Ledger_Service::record(
			$user_id,
			'redemption_restore:' . absint( $refund->get_id() ),
			'redemption_restore',
			$restore,
			0,
			array(
				'order_id'    => $order->get_id(),
				'refund_id'   => $refund->get_id(),
				'amount'      => $amount > 0 ? ( $amount * ( $restore / $points ) ) : 0,
				'currency'    => $order->get_currency(),
				'description' => sprintf(
					/* translators: %d: order ID. */
					__( 'Redeemed points restored after refund on order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'        => array(
					'policy' => $policy,
					'points' => $restore,
				),
			)
		);

		if ( ! is_wp_error( $result ) && ( $already + $restore ) >= abs( (int) $reservation->points_delta ) ) {
			$order->update_meta_data( '_epc_loyalty_redemption_status', 'released' );
			$order->save();
		}

		return $result;
	}

	/**
	 * Points already released/restored for an order redemption.
	 *
	 * @param int $order_id Order ID.
	 * @return int
	 */
	public static function get_restored_points_for_order( $order_id ) {
		global $wpdb;

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate custom ledger lookup.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta), 0) FROM {$table}
				WHERE order_id = %d
				AND entry_type IN ('redemption_release', 'redemption_restore', 'redemption_reinstatement')",
				absint( $order_id )
			)
		);

		return max( 0, (int) $total );
	}

	/**
	 * Render the classic redemption form.
	 *
	 * @param string $context cart|checkout.
	 * @return void
	 */
	private static function render_widget( $context ) {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$quote = self::get_quote_payload();
		?>
		<div class="epc-loyalty-redemption" data-epc-loyalty-context="<?php echo esc_attr( $context ); ?>">
			<h3 class="epc-loyalty-redemption__title"><?php esc_html_e( 'Redeem loyalty points', 'epasscard' ); ?></h3>
			<?php if ( ! empty( $quote['is_guest'] ) ) : ?>
				<p class="epc-loyalty-redemption__message">
					<?php echo esc_html( (string) $quote['message'] ); ?>
					<a href="<?php echo esc_url( (string) $quote['login_url'] ); ?>"><?php esc_html_e( 'Sign in', 'epasscard' ); ?></a>
				</p>
			<?php else : ?>
				<p class="epc-loyalty-redemption__balance">
					<?php
					printf(
						/* translators: %d: available points. */
						esc_html__( 'Available points: %d', 'epasscard' ),
						(int) $quote['balance']
					);
					?>
				</p>
				<?php if ( ! empty( $quote['message'] ) && empty( $quote['can_redeem'] ) && (int) $quote['applied_points'] <= 0 ) : ?>
					<p class="epc-loyalty-redemption__message"><?php echo esc_html( (string) $quote['message'] ); ?></p>
				<?php else : ?>
					<p class="epc-loyalty-redemption__controls">
						<label for="epc-loyalty-points-<?php echo esc_attr( $context ); ?>" class="screen-reader-text"><?php esc_html_e( 'Points to redeem', 'epasscard' ); ?></label>
						<input
							id="epc-loyalty-points-<?php echo esc_attr( $context ); ?>"
							class="epc-loyalty-redemption__input"
							type="number"
							min="0"
							step="<?php echo esc_attr( (string) $quote['increment'] ); ?>"
							max="<?php echo esc_attr( (string) max( (int) $quote['max_points'], (int) $quote['applied_points'] ) ); ?>"
							value="<?php echo esc_attr( (string) ( (int) $quote['applied_points'] > 0 ? $quote['applied_points'] : $quote['requested_points'] ) ); ?>"
						/>
						<button type="button" class="button epc-loyalty-redemption__apply"><?php esc_html_e( 'Apply points', 'epasscard' ); ?></button>
						<button type="button" class="button epc-loyalty-redemption__remove" <?php disabled( (int) $quote['applied_points'] <= 0 ); ?>><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
					</p>
					<p class="epc-loyalty-redemption__hint">
						<?php
						printf(
							/* translators: 1: points per currency unit, 2: currency code, 3: max points. */
							esc_html__( '%1$s points = 1 %2$s. Maximum for this order: %3$d points.', 'epasscard' ),
							esc_html( (string) $quote['points_per_currency'] ),
							esc_html( (string) $quote['currency'] ),
							(int) $quote['max_points']
						);
						?>
					</p>
					<p class="epc-loyalty-redemption__status" aria-live="polite"></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Eligible merchandise subtotal for the current cart.
	 *
	 * @return float
	 */
	private static function get_eligible_cart_subtotal() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$settings = self::get_settings();
		$total    = 0.0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product      = isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
			$product_id   = absint( $cart_item['product_id'] ?? 0 );
			$variation_id = absint( $cart_item['variation_id'] ?? 0 );
			$match_id     = $variation_id > 0 ? $variation_id : $product_id;
			$categories   = $product_id > 0 ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) : array();
			$categories   = is_wp_error( $categories ) ? array() : array_map( 'absint', $categories );

			if ( in_array( $match_id, $settings['excluded_products'], true ) || in_array( $product_id, $settings['excluded_products'], true ) ) {
				continue;
			}
			if ( ! empty( array_intersect( $settings['excluded_categories'], $categories ) ) ) {
				continue;
			}
			if ( empty( $settings['include_sale_items'] ) && $product && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
				continue;
			}

			$total += max( 0, (float) ( $cart_item['line_subtotal'] ?? 0 ) );
		}

		return (float) self::format_decimal( $total );
	}

	/**
	 * Remove loyalty coupons from the cart.
	 *
	 * @return void
	 */
	private static function remove_loyalty_coupons_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( self::is_loyalty_coupon_code( $code ) ) {
				WC()->cart->remove_coupon( $code );
			}
		}
	}

	/**
	 * Convert points to currency using redemption settings.
	 *
	 * @param int                  $points Points.
	 * @param array<string, mixed> $settings Settings.
	 * @return float
	 */
	private static function points_to_currency( $points, array $settings ) {
		$rate = max( 0.000001, (float) $settings['points_per_currency'] );
		return (float) self::format_decimal( max( 0, (int) $points ) / $rate );
	}

	/**
	 * Convert currency to whole points using redemption settings.
	 *
	 * @param float                $amount Amount.
	 * @param array<string, mixed> $settings Settings.
	 * @return int
	 */
	private static function currency_to_points( $amount, array $settings ) {
		$rate = max( 0.000001, (float) $settings['points_per_currency'] );
		return max( 0, (int) floor( max( 0, (float) $amount ) * $rate ) );
	}

	/**
	 * Snap points down to the configured increment.
	 *
	 * @param int $points Points.
	 * @param int $increment Increment.
	 * @param int $cap Maximum allowed points.
	 * @return int
	 */
	private static function snap_points( $points, $increment, $cap ) {
		$points    = max( 0, (int) $points );
		$increment = max( 1, (int) $increment );
		$cap       = max( 0, (int) $cap );
		$points    = min( $points, $cap );
		if ( $points <= 0 ) {
			return 0;
		}
		$snapped = (int) ( floor( $points / $increment ) * $increment );
		if ( $snapped <= 0 && $points === $cap && $cap > 0 && $cap < $increment ) {
			return $cap;
		}
		return max( 0, $snapped );
	}

	/**
	 * Session helpers.
	 *
	 * @return int
	 */
	private static function get_session_points() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0;
		}
		return absint( WC()->session->get( self::SESSION_KEY, 0 ) );
	}

	/**
	 * @param int $points Points.
	 * @return void
	 */
	private static function set_session_points( $points ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( self::SESSION_KEY, absint( $points ) );
		if ( method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}
	}

	/**
	 * @return void
	 */
	private static function clear_session_points() {
		self::set_session_points( 0 );
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function coupon_code_for_user( $user_id ) {
		return self::COUPON_PREFIX . absint( $user_id );
	}

	/**
	 * @param string $code Coupon code.
	 * @return bool
	 */
	private static function is_loyalty_coupon_code( $code ) {
		$code = wc_format_coupon_code( (string) $code );
		return 0 === strpos( $code, self::COUPON_PREFIX );
	}

	/**
	 * @param string $code Coupon code.
	 * @return int
	 */
	private static function user_id_from_coupon_code( $code ) {
		$code = wc_format_coupon_code( (string) $code );
		if ( ! self::is_loyalty_coupon_code( $code ) ) {
			return 0;
		}
		return absint( substr( $code, strlen( self::COUPON_PREFIX ) ) );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function reservation_event_key( $order_id ) {
		return 'redemption_reservation:' . absint( $order_id );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function commit_event_key( $order_id ) {
		return 'redemption_commit:' . absint( $order_id );
	}

	/**
	 * @param int $order_id Order ID.
	 * @param int $cycle Reservation cycle.
	 * @return string
	 */
	private static function release_event_key( $order_id, $cycle = 0 ) {
		$key = 'redemption_release:' . absint( $order_id );
		return $cycle > 0 ? $key . ':' . absint( $cycle ) : $key;
	}

	/**
	 * @param mixed $value IDs.
	 * @return array<int, int>
	 */
	private static function sanitize_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
	}

	/**
	 * @param mixed $value Raw bool.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * @param mixed $value Decimal.
	 * @return string
	 */
	private static function format_decimal( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value, wc_get_price_decimals() );
		}
		return number_format( (float) $value, 2, '.', '' );
	}
}
