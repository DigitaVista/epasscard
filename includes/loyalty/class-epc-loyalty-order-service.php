<?php
/**
 * WooCommerce order earning and reversal lifecycle.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Awards points for qualifying orders and reverses them for refunds/cancellation.
 */
class EPC_Loyalty_Order_Service {

	/**
	 * Register WooCommerce order lifecycle hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 20, 2 );
	}

	/**
	 * Award an order after payment completes when its status qualifies.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function on_payment_complete( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( $order && self::is_qualifying_status( $order->get_status() ) ) {
			self::award_order( $order );
		}
	}

	/**
	 * Handle qualifying and terminal order status transitions.
	 *
	 * @param int       $order_id WooCommerce order ID.
	 * @param string    $old_status Previous status.
	 * @param string    $new_status New status.
	 * @param \WC_Order $order Order object.
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

		if ( self::is_qualifying_status( $new_status ) ) {
			self::award_order( $order );
			return;
		}

		if ( 'cancelled' === sanitize_key( (string) $new_status ) ) {
			self::reverse_order_remainder( $order, 'order_status_' . sanitize_key( (string) $new_status ) );
		}
	}

	/**
	 * Reverse a proportional number of points for a WooCommerce refund.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund ID.
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

		$award = EPC_Loyalty_Ledger_Service::get_by_event_key( self::award_event_key( $order->get_id() ) );
		if ( ! $award || (int) $award->points_delta <= 0 ) {
			return;
		}

		$original_amount = (float) $award->amount;
		$refund_amount   = self::get_refund_qualifying_amount( $refund, $order, $original_amount );
		if ( $original_amount <= 0 || $refund_amount <= 0 ) {
			return;
		}

		if ( EPC_Loyalty_Ledger_Service::get_by_event_key( 'refund_reversal:' . absint( $refund_id ) ) ) {
			return;
		}

		$awarded    = (int) $award->points_delta;
		$reversed   = EPC_Loyalty_Ledger_Service::get_reversed_points_for_order( $order->get_id() );
		$remaining  = max( 0, $awarded - $reversed );
		$award_meta = json_decode( (string) $award->meta, true );
		$rounding    = is_array( $award_meta ) && isset( $award_meta['rounding'] )
			? sanitize_key( (string) $award_meta['rounding'] )
			: self::get_settings()['rounding'];
		$points      = $remaining;

		EPC_Loyalty_Ledger_Service::record(
			(int) $award->user_id,
			'refund_reversal:' . absint( $refund_id ),
			'refund_reversal',
			-$points,
			-$points,
			array(
				'order_id'                => $order->get_id(),
				'refund_id'               => $refund->get_id(),
				'maximum_order_reversal' => $awarded,
				'refund_award_points'     => $awarded,
				'refund_award_amount'     => $original_amount,
				'refund_rounding'         => $rounding,
				'amount'                  => -$refund_amount,
				'currency'                => $order->get_currency(),
				'description'             => sprintf(
					/* translators: %d: WooCommerce order ID. */
					__( 'Points reversed for refund on order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'                    => array(
					'original_award'  => $awarded,
					'original_amount' => $original_amount,
					'refund_amount'   => $refund_amount,
				),
			)
		);
	}

	/**
	 * Award points for one qualifying registered-customer order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return array|\WP_Error|null
	 */
	public static function award_order( $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_qualifying_status( $order->get_status() ) ) {
			return null;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$existing = EPC_Loyalty_Ledger_Service::get_by_event_key( self::award_event_key( $order->get_id() ) );
		if ( $existing ) {
			return self::reinstate_order( $order, $existing );
		}

		$settings = self::get_settings();
		$award    = EPC_Loyalty_Rule_Service::evaluate_order( $order, $settings['rounding'] );
		$amount   = (float) $award['amount'];
		$points   = (int) $award['points'];
		if ( $points <= 0 ) {
			return null;
		}

		return EPC_Loyalty_Ledger_Service::record(
			$user_id,
			self::award_event_key( $order->get_id() ),
			'order_award',
			$points,
			$points,
			array(
				'order_id'    => $order->get_id(),
				'amount'      => $amount,
				'currency'    => $order->get_currency(),
				'expires_at'  => $award['expires_at'],
				'description' => sprintf(
					/* translators: %d: WooCommerce order ID. */
					__( 'Points earned from order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'        => array(
					'points_basis'       => 'merchandise_after_discounts',
					'points_per_currency' => $settings['points_per_currency'],
					'rounding'           => $settings['rounding'],
					'order_status'       => $order->get_status(),
					'earning_rules'      => $award['rules'],
				),
			)
		);
	}

	/**
	 * Reverse any points not already reversed for a terminal order.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $reason Reversal reason.
	 * @return array|\WP_Error|null
	 */
	private static function reverse_order_remainder( $order, $reason ) {
		$award = EPC_Loyalty_Ledger_Service::get_by_event_key( self::award_event_key( $order->get_id() ) );
		if ( ! $award || (int) $award->points_delta <= 0 ) {
			return null;
		}

		$awarded   = (int) $award->points_delta;
		$reversed  = EPC_Loyalty_Ledger_Service::get_reversed_points_for_order( $order->get_id() );
		$remaining = max( 0, $awarded - $reversed );
		if ( $remaining <= 0 ) {
			return null;
		}

		$reversal_number = EPC_Loyalty_Ledger_Service::get_order_reversal_count( $order->get_id() ) + 1;

		return EPC_Loyalty_Ledger_Service::record(
			(int) $award->user_id,
			'order_reversal:' . $order->get_id() . ':' . $reversal_number,
			'order_reversal',
			-$remaining,
			-$remaining,
			array(
				'order_id'                => $order->get_id(),
				'maximum_order_reversal' => $awarded,
				'amount'                  => -(float) $award->amount,
				'currency'                => $order->get_currency(),
				'description'             => sprintf(
					/* translators: %d: WooCommerce order ID. */
					__( 'Remaining points reversed for order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'                    => array(
					'reason'          => sanitize_key( (string) $reason ),
					'original_award'  => $awarded,
					'already_reversed' => $reversed,
				),
			)
		);
	}

	/**
	 * Restore cancellation-reversed points when an order returns to a qualifying status.
	 *
	 * Refund reversals remain in place.
	 *
	 * @param \WC_Order $order Order object.
	 * @param object    $award Original award ledger entry.
	 * @return array|\WP_Error|null
	 */
	private static function reinstate_order( $order, $award ) {
		$points = EPC_Loyalty_Ledger_Service::get_outstanding_order_reversal( $order->get_id() );
		if ( $points <= 0 ) {
			return null;
		}

		$reversal_number = EPC_Loyalty_Ledger_Service::get_order_reversal_count( $order->get_id() );
		$award_points    = max( 1, (int) $award->points_delta );
		$amount          = (float) $award->amount * min( 1, $points / $award_points );

		return EPC_Loyalty_Ledger_Service::record(
			(int) $award->user_id,
			'order_reinstatement:' . $order->get_id() . ':' . $reversal_number,
			'order_reinstatement',
			$points,
			$points,
			array(
				'order_id'                          => $order->get_id(),
				'cap_to_outstanding_order_reversal' => true,
				'amount'                            => $amount,
				'currency'                          => $order->get_currency(),
				'description'                       => sprintf(
					/* translators: %d: WooCommerce order ID. */
					__( 'Points restored for reopened order #%d', 'epasscard' ),
					$order->get_id()
				),
				'meta'                              => array(
					'reversal_number' => $reversal_number,
				),
			)
		);
	}

	/**
	 * Merchandise subtotal after discounts, excluding tax and shipping.
	 *
	 * @param \WC_Order $order Order object.
	 * @return float
	 */
	private static function get_order_qualifying_amount( $order ) {
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$total += max( 0, (float) $item->get_total() );
		}
		return (float) wc_format_decimal( $total, 6 );
	}

	/**
	 * Refunded merchandise amount after discounts.
	 *
	 * @param \WC_Order_Refund $refund Refund object.
	 * @param \WC_Order        $order Parent order.
	 * @param float            $original_amount Original qualifying merchandise amount.
	 * @return float
	 */
	private static function get_refund_qualifying_amount( $refund, $order, $original_amount ) {
		$total = 0.0;
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			$total += abs( (float) $item->get_total() );
		}

		if ( $total <= 0 ) {
			$refund_total = abs( (float) $refund->get_amount() );
			$non_merchandise = abs( (float) $refund->get_shipping_total() ) + abs( (float) $refund->get_total_tax() );

			if ( $non_merchandise > 0 ) {
				$total = max( 0, $refund_total - $non_merchandise );
			} else {
				$order_total = max( 0, (float) $order->get_total() );
				$total       = $order_total > 0
					? ( $refund_total / $order_total ) * max( 0, (float) $original_amount )
					: 0;
			}
		}

		return (float) wc_format_decimal( min( max( 0, (float) $original_amount ), $total ), 6 );
	}

	/**
	 * Sanitized loyalty earning settings.
	 *
	 * @return array{points_per_currency: float, rounding: string, qualifying_statuses: array<int, string>}
	 */
	public static function get_settings() {
		$saved = get_option( 'epc_loyalty_program', array() );
		$saved = is_array( $saved ) ? $saved : array();

		$rounding = sanitize_key( (string) ( $saved['rounding'] ?? 'floor' ) );
		if ( ! in_array( $rounding, array( 'floor', 'ceil', 'round' ), true ) ) {
			$rounding = 'floor';
		}

		$statuses = isset( $saved['qualifying_statuses'] ) && is_array( $saved['qualifying_statuses'] )
			? $saved['qualifying_statuses']
			: array( 'processing', 'completed' );
		$statuses = array_map(
			static function ( $status ) {
				return sanitize_key( str_replace( 'wc-', '', (string) $status ) );
			},
			$statuses
		);

		$settings = array(
			'points_per_currency' => max( 0.0, (float) ( $saved['points_per_currency'] ?? 1 ) ),
			'rounding'            => $rounding,
			'qualifying_statuses' => array_values( array_unique( array_filter( $statuses ) ) ),
		);

		/**
		 * Filter normalized loyalty earning settings.
		 *
		 * @param array<string, mixed> $settings Loyalty earning settings.
		 */
		return (array) apply_filters( 'epc_loyalty_earning_settings', $settings );
	}

	/**
	 * Whether an order status earns points.
	 *
	 * @param string $status Order status.
	 * @return bool
	 */
	private static function is_qualifying_status( $status ) {
		$status = sanitize_key( str_replace( 'wc-', '', (string) $status ) );
		return in_array( $status, self::get_settings()['qualifying_statuses'], true );
	}

	/**
	 * Convert a fractional point result to whole points.
	 *
	 * @param float  $points Raw points.
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
	 * Award event key for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function award_event_key( $order_id ) {
		return 'order_award:' . absint( $order_id );
	}
}
