<?php
/**
 * Inactive starter earning rules, tiers, and rewards for a fresh install.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds practical sample loyalty data once, left inactive until reviewed.
 */
class EPC_Loyalty_Starter {

	public const OPTION_KEY = 'epc_loyalty_starter_pack';
	public const VERSION    = 3;

	/**
	 * Whether a seed is already in progress.
	 *
	 * @var bool
	 */
	private static $installing = false;

	/**
	 * Persist starter definitions when a list has never been configured.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::$installing ) {
			return;
		}
		if ( (int) get_option( self::OPTION_KEY, 0 ) >= self::VERSION ) {
			return;
		}
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return;
		}

		self::$installing = true;

		if ( self::should_seed_list( EPC_Loyalty_Rule_Service::OPTION_KEY, 'rules' ) ) {
			EPC_Loyalty_Rule_Service::save_rules( self::rules() );
		}
		if ( self::stored_list_empty( EPC_Loyalty_Reward_Service::TIERS_OPTION, 'tiers' ) ) {
			EPC_Loyalty_Reward_Service::save_tiers( self::tiers() );
		}
		if ( self::stored_list_empty( EPC_Loyalty_Reward_Service::MILESTONES_OPTION, 'milestones' ) ) {
			EPC_Loyalty_Reward_Service::save_milestones( self::milestones() );
		}

		update_option( self::OPTION_KEY, self::VERSION, false );
		self::$installing = false;
	}

	/**
	 * Sample earning rules covering the common store setups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules() {
		$currency = self::currency_symbol();

		return array(
			array(
				'id'                 => 'points-per-spend',
				'name'               => sprintf(
					/* translators: %s: currency symbol */
					__( 'Earn 1 point for every %s1 spent', 'epasscard' ),
					$currency
				),
				'active'             => false,
				'priority'           => 10,
				'stack'              => true,
				'award_type'         => 'per_currency',
				'points'             => 1,
				'minimum_spend'      => 0,
				'first_order'        => false,
				'include_sale_items' => true,
				'point_expiry_days'  => 0,
			),
			array(
				'id'                 => 'welcome-first-order',
				'name'               => __( 'Welcome bonus on the first order', 'epasscard' ),
				'active'             => false,
				'priority'           => 5,
				'stack'              => true,
				'award_type'         => 'fixed',
				'points'             => 100,
				'minimum_spend'      => 0,
				'first_order'        => true,
				'include_sale_items' => true,
				'point_expiry_days'  => 0,
			),
			array(
				'id'                 => 'high-spend-bonus',
				'name'               => sprintf(
					/* translators: %s: currency symbol */
					__( 'Extra 50 points on orders of %s100+', 'epasscard' ),
					$currency
				),
				'active'             => false,
				'priority'           => 20,
				'stack'              => true,
				'award_type'         => 'fixed',
				'points'             => 50,
				'minimum_spend'      => 100,
				'first_order'        => false,
				'include_sale_items' => true,
				'point_expiry_days'  => 0,
			),
		);
	}

	/**
	 * Sample lifetime tiers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tiers() {
		return array(
			array(
				'id'        => 'bronze',
				'name'      => __( 'Bronze', 'epasscard' ),
				'threshold' => 0,
				'active'    => false,
			),
			array(
				'id'        => 'silver',
				'name'      => __( 'Silver', 'epasscard' ),
				'threshold' => 500,
				'active'    => false,
			),
			array(
				'id'        => 'gold',
				'name'      => __( 'Gold', 'epasscard' ),
				'threshold' => 2000,
				'active'    => false,
			),
			array(
				'id'        => 'platinum',
				'name'      => __( 'Platinum', 'epasscard' ),
				'threshold' => 5000,
				'active'    => false,
			),
		);
	}

	/**
	 * One sample reward for each reward type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function milestones() {
		$currency = self::currency_symbol();

		return array(
			array(
				'id'          => 'starter-bonus-points',
				'name'        => __( '50 bonus points', 'epasscard' ),
				'active'      => false,
				'threshold'   => 100,
				'repeatable'  => false,
				'claim_mode'  => 'automatic',
				'expiry_days' => 0,
				'reward'      => array(
					'type'       => 'bonus_points',
					'amount'     => 50,
					'product_id' => 0,
				),
			),
			array(
				'id'          => 'five-off-coupon',
				'name'        => sprintf(
					/* translators: %s: currency symbol */
					__( '%s5 off your next order', 'epasscard' ),
					$currency
				),
				'active'      => false,
				'threshold'   => 250,
				'repeatable'  => false,
				'claim_mode'  => 'manual',
				'expiry_days' => 60,
				'reward'      => array(
					'type'       => 'fixed_coupon',
					'amount'     => 5,
					'product_id' => 0,
				),
			),
			array(
				'id'          => 'ten-percent-coupon',
				'name'        => __( '10% off coupon', 'epasscard' ),
				'active'      => false,
				'threshold'   => 500,
				'repeatable'  => false,
				'claim_mode'  => 'manual',
				'expiry_days' => 30,
				'reward'      => array(
					'type'       => 'percentage_coupon',
					'amount'     => 10,
					'product_id' => 0,
				),
			),
			array(
				'id'          => 'free-shipping-reward',
				'name'        => __( 'Free shipping on your next order', 'epasscard' ),
				'active'      => false,
				'threshold'   => 1000,
				'repeatable'  => false,
				'claim_mode'  => 'manual',
				'expiry_days' => 45,
				'reward'      => array(
					'type'       => 'free_shipping',
					'amount'     => 0,
					'product_id' => 0,
				),
			),
			array(
				'id'          => 'free-gift-product',
				'name'        => __( 'Free gift', 'epasscard' ),
				'active'      => false,
				'threshold'   => 2000,
				'repeatable'  => false,
				'claim_mode'  => 'manual',
				'expiry_days' => 90,
				'reward'      => array(
					'type'       => 'free_product',
					'amount'     => 0,
					'product_id' => self::sample_product_id(),
				),
			),
		);
	}

	/**
	 * Whether earning rules are missing or still the old implicit default.
	 *
	 * @param string $option_key Option name.
	 * @param string $child_key  List key inside a versioned payload.
	 * @return bool
	 */
	private static function should_seed_list( $option_key, $child_key ) {
		if ( self::stored_list_empty( $option_key, $child_key ) ) {
			return true;
		}

		$saved = get_option( $option_key, false );
		$items = is_array( $saved ) && isset( $saved[ $child_key ] ) && is_array( $saved[ $child_key ] )
			? $saved[ $child_key ]
			: ( is_array( $saved ) ? $saved : array() );

		$items = array_values( $items );
		if ( 1 !== count( $items ) || ! is_array( $items[0] ) ) {
			return false;
		}

		return 'default' === sanitize_key( (string) ( $items[0]['id'] ?? '' ) );
	}

	/**
	 * Whether a versioned list option is missing or has no items.
	 *
	 * @param string $option_key Option name.
	 * @param string $child_key  List key inside a versioned payload.
	 * @return bool
	 */
	private static function stored_list_empty( $option_key, $child_key ) {
		$saved = get_option( $option_key, false );
		if ( false === $saved || ! is_array( $saved ) ) {
			return true;
		}
		if ( isset( $saved[ $child_key ] ) && is_array( $saved[ $child_key ] ) ) {
			return empty( $saved[ $child_key ] );
		}
		return empty( $saved );
	}

	/**
	 * Store currency symbol for sample labels.
	 *
	 * @return string
	 */
	private static function currency_symbol() {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = (string) get_woocommerce_currency_symbol();
			if ( '' !== $symbol ) {
				return $symbol;
			}
		}
		return '$';
	}

	/**
	 * Newest published product, if the catalog has one.
	 *
	 * @return int
	 */
	private static function sample_product_id() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 1,
				'return'  => 'ids',
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		return isset( $ids[0] ) ? absint( $ids[0] ) : 0;
	}
}
