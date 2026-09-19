<?php
/**
 * Configurable loyalty earning rules.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes and evaluates prioritized WooCommerce earning rules.
 */
class EPC_Loyalty_Rule_Service {

	/**
	 * Rules option key.
	 */
	public const OPTION_KEY = 'epc_loyalty_earning_rules';

	/**
	 * When true, first-order rules use the customer's earliest shop order, not current order count.
	 *
	 * @var bool
	 */
	private static $first_order_uses_earliest = false;

	/**
	 * Toggle historical first-order matching.
	 *
	 * @param bool $enabled Whether to match on earliest order.
	 * @return void
	 */
	public static function use_earliest_order_for_first_order_rules( $enabled ) {
		self::$first_order_uses_earliest = (bool) $enabled;
	}

	/**
	 * Read normalized earning rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules() {
		EPC_Loyalty_Starter::maybe_install();

		$saved = get_option( self::OPTION_KEY, array() );
		$rules = is_array( $saved ) && isset( $saved['rules'] ) && is_array( $saved['rules'] )
			? $saved['rules']
			: ( is_array( $saved ) ? $saved : array() );

		$normalized = array();
		foreach ( $rules as $index => $rule ) {
			if ( is_array( $rule ) ) {
				$normalized[] = self::sanitize_rule( $rule, $index );
			}
		}

		usort(
			$normalized,
			static function ( $left, $right ) {
				return (int) $left['priority'] <=> (int) $right['priority'];
			}
		);

		return array_values( $normalized );
	}

	/**
	 * Save a versioned, sanitized rule definition.
	 *
	 * @param array<int, array<string, mixed>> $rules Raw rules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_rules( array $rules ) {
		$clean = array();
		foreach ( $rules as $index => $rule ) {
			if ( is_array( $rule ) ) {
				$clean[] = self::sanitize_rule( $rule, $index );
			}
		}

		update_option(
			self::OPTION_KEY,
			array(
				'version' => 1,
				'rules'   => array_values( $clean ),
			)
		);

		return $clean;
	}

	/**
	 * Evaluate all matching rules for an order.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @param string    $rounding Program rounding mode.
	 * @return array{points: int, amount: float, expires_at: string|null, rules: array<int, array<string, mixed>>}
	 */
	public static function evaluate_order( $order, $rounding = 'floor' ) {
		$result = array(
			'points'     => 0,
			'amount'     => 0.0,
			'expires_at' => null,
			'rules'      => array(),
		);

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) ) {
			return $result;
		}

		$result['amount'] = self::order_merchandise_amount( $order );

		foreach ( self::get_rules() as $rule ) {
			if ( empty( $rule['active'] ) || ! self::matches_order( $rule, $order ) ) {
				continue;
			}

			$amount = self::eligible_amount( $rule, $order );
			if ( $amount < (float) $rule['minimum_spend'] ) {
				continue;
			}

			$raw_points = 'fixed' === $rule['award_type']
				? (float) $rule['points']
				: $amount * (float) $rule['points'];
			$points     = self::round_points( $raw_points, $rounding );

			if ( $points <= 0 ) {
				continue;
			}

			$expiry = null;
			if ( (int) $rule['point_expiry_days'] > 0 ) {
				$expiry = gmdate( 'Y-m-d H:i:s', time() + ( (int) $rule['point_expiry_days'] * DAY_IN_SECONDS ) );
				if ( null === $result['expires_at'] || $expiry < $result['expires_at'] ) {
					$result['expires_at'] = $expiry;
				}
			}

			$result['points'] += $points;
			$result['rules'][] = array(
				'id'        => $rule['id'],
				'name'      => $rule['name'],
				'points'    => $points,
				'amount'    => $amount,
				'expires_at'=> $expiry,
			);

			if ( empty( $rule['stack'] ) ) {
				break;
			}
		}

		$result['amount'] = (float) self::format_decimal( $result['amount'] );
		return $result;
	}

	/**
	 * Normalize one rule.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @param int                  $index Fallback index.
	 * @return array<string, mixed>
	 */
	private static function sanitize_rule( array $rule, $index ) {
		$id = sanitize_key( (string) ( $rule['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = 'rule-' . ( absint( $index ) + 1 );
		}

		$award_type = sanitize_key( (string) ( $rule['award_type'] ?? 'per_currency' ) );
		if ( ! in_array( $award_type, array( 'per_currency', 'fixed' ), true ) ) {
			$award_type = 'per_currency';
		}

		return array(
			'id'                => substr( $id, 0, 64 ),
			'name'              => sanitize_text_field( (string) ( $rule['name'] ?? $id ) ),
			'active'            => ! isset( $rule['active'] ) || self::to_bool( $rule['active'] ),
			'priority'          => (int) ( $rule['priority'] ?? 10 ),
			'stack'             => ! isset( $rule['stack'] ) || self::to_bool( $rule['stack'] ),
			'award_type'        => $award_type,
			'points'            => max( 0, (float) ( $rule['points'] ?? 1 ) ),
			'minimum_spend'     => max( 0, (float) ( $rule['minimum_spend'] ?? 0 ) ),
			'product_ids'       => self::sanitize_ids( $rule['product_ids'] ?? array() ),
			'category_ids'      => self::sanitize_ids( $rule['category_ids'] ?? array() ),
			'excluded_products' => self::sanitize_ids( $rule['excluded_products'] ?? array() ),
			'excluded_categories'=> self::sanitize_ids( $rule['excluded_categories'] ?? array() ),
			'customer_roles'    => self::sanitize_keys( $rule['customer_roles'] ?? array() ),
			'first_order'       => ! empty( $rule['first_order'] ) && self::to_bool( $rule['first_order'] ),
			'include_sale_items'=> ! isset( $rule['include_sale_items'] ) || self::to_bool( $rule['include_sale_items'] ),
			'start_at'          => self::sanitize_datetime( $rule['start_at'] ?? null ),
			'end_at'            => self::sanitize_datetime( $rule['end_at'] ?? null ),
			'point_expiry_days' => min( 3650, absint( $rule['point_expiry_days'] ?? 0 ) ),
		);
	}

	/**
	 * Check order-level filters.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @param \WC_Order            $order Order.
	 * @return bool
	 */
	private static function matches_order( array $rule, $order ) {
		$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$order_ts = $created && method_exists( $created, 'getTimestamp' ) ? $created->getTimestamp() : time();

		if ( $rule['start_at'] && $order_ts < strtotime( $rule['start_at'] . ' UTC' ) ) {
			return false;
		}
		if ( $rule['end_at'] && $order_ts > strtotime( $rule['end_at'] . ' UTC' ) ) {
			return false;
		}

		$user_id = absint( $order->get_user_id() );
		if ( ! empty( $rule['customer_roles'] ) ) {
			$user = get_userdata( $user_id );
			if ( ! $user || empty( array_intersect( $rule['customer_roles'], (array) $user->roles ) ) ) {
				return false;
			}
		}

		if ( ! empty( $rule['first_order'] ) && ! self::matches_first_order_rule( $order, $user_id ) ) {
			return false;
		}

		return self::eligible_amount( $rule, $order ) > 0;
	}

	/**
	 * Whether a first-order-only rule applies to this order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $user_id Customer ID.
	 * @return bool
	 */
	private static function matches_first_order_rule( $order, $user_id ) {
		if ( self::$first_order_uses_earliest ) {
			return self::is_earliest_customer_order( $order, $user_id );
		}

		return ! function_exists( 'wc_get_customer_order_count' ) || wc_get_customer_order_count( $user_id ) <= 1;
	}

	/**
	 * Whether this is the customer's first shop order by date then ID.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $user_id Customer ID.
	 * @return bool
	 */
	private static function is_earliest_customer_order( $order, $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'return'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'type'        => 'shop_order',
			)
		);

		return is_array( $ids ) && isset( $ids[0] ) && absint( $ids[0] ) === (int) $order->get_id();
	}

	/**
	 * Sum merchandise matching product/category filters.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @param \WC_Order            $order Order.
	 * @return float
	 */
	private static function eligible_amount( array $rule, $order ) {
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product_id   = absint( $item->get_product_id() );
			$variation_id = absint( $item->get_variation_id() );
			$match_id     = $variation_id > 0 ? $variation_id : $product_id;
			$categories   = $product_id > 0 ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) : array();
			$categories   = is_wp_error( $categories ) ? array() : array_map( 'absint', $categories );

			if ( ! empty( $rule['product_ids'] ) && ! in_array( $match_id, $rule['product_ids'], true ) && ! in_array( $product_id, $rule['product_ids'], true ) ) {
				continue;
			}
			if ( ! empty( $rule['category_ids'] ) && empty( array_intersect( $rule['category_ids'], $categories ) ) ) {
				continue;
			}
			if ( in_array( $match_id, $rule['excluded_products'], true ) || in_array( $product_id, $rule['excluded_products'], true ) ) {
				continue;
			}
			if ( ! empty( array_intersect( $rule['excluded_categories'], $categories ) ) ) {
				continue;
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			if ( empty( $rule['include_sale_items'] ) && $product && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
				continue;
			}

			$total += max( 0, (float) $item->get_total() );
		}

		return (float) self::format_decimal( $total );
	}

	/**
	 * Merchandise subtotal after discounts, excluding tax and shipping.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	private static function order_merchandise_amount( $order ) {
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$total += max( 0, (float) $item->get_total() );
		}
		return (float) self::format_decimal( $total );
	}

	/**
	 * Sanitize positive IDs.
	 *
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
	 * Sanitize key lists.
	 *
	 * @param mixed $value Keys.
	 * @return array<int, string>
	 */
	private static function sanitize_keys( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $value ) ? $value : array() ) ) ) );
	}

	/**
	 * Normalize a boolean-like setting.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Normalize an optional datetime.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function sanitize_datetime( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	/**
	 * Round whole points.
	 *
	 * @param float  $points Points.
	 * @param string $rounding Rounding mode.
	 * @return int
	 */
	private static function round_points( $points, $rounding ) {
		if ( 'ceil' === $rounding ) {
			return (int) ceil( $points );
		}
		if ( 'round' === $rounding ) {
			return (int) round( $points );
		}
		return (int) floor( $points );
	}

	/**
	 * Format a decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function format_decimal( $value ) {
		return function_exists( 'wc_format_decimal' )
			? wc_format_decimal( $value, 6 )
			: number_format( (float) $value, 6, '.', '' );
	}
}
