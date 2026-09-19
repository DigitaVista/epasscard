<?php
/**
 * Immutable loyalty points ledger.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies idempotent ledger entries and atomically updates account totals.
 */
class EPC_Loyalty_Ledger_Service {

	/**
	 * Apply one immutable points mutation.
	 *
	 * The account row lock serializes concurrent mutations for a customer, while
	 * the unique event key prevents duplicate hooks from applying twice.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param string               $event_key Globally unique source event key.
	 * @param string               $entry_type Entry type.
	 * @param int                  $points_delta Spendable points change.
	 * @param int                  $lifetime_delta Lifetime-earned change.
	 * @param array<string, mixed> $context Optional ledger context.
	 * @return array{created: bool, entry: object, account: object}|\WP_Error
	 */
	public static function record( $user_id, $event_key, $entry_type, $points_delta, $lifetime_delta, array $context = array() ) {
		global $wpdb;

		$user_id        = absint( $user_id );
		$event_key      = self::normalize_event_key( $event_key );
		$entry_type     = substr( sanitize_key( (string) $entry_type ), 0, 32 );
		$points_delta   = (int) $points_delta;
		$lifetime_delta = (int) $lifetime_delta;

		if ( $user_id <= 0 || '' === $event_key || '' === $entry_type ) {
			return new WP_Error( 'epc_loyalty_invalid_entry', __( 'The loyalty ledger entry is invalid.', 'epasscard' ) );
		}

		$ledger_table  = EPC_DB::loyalty_ledger_table_name();
		$account_table = EPC_DB::loyalty_accounts_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic custom-table mutation.
		$wpdb->query( 'START TRANSACTION' );

		$account = EPC_Loyalty_Account_Service::get_or_create( $user_id );
		if ( is_wp_error( $account ) ) {
			self::rollback();
			return $account;
		}

		$account = EPC_Loyalty_Account_Service::get( $user_id, true );
		if ( ! $account ) {
			self::rollback();
			return new WP_Error( 'epc_loyalty_account_missing', __( 'The loyalty account could not be locked.', 'epasscard' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency lookup under account row lock.
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE event_key = %s LIMIT 1", $event_key )
		);
		if ( $existing ) {
			if ( (int) $existing->user_id !== $user_id ) {
				self::rollback();
				return new WP_Error( 'epc_loyalty_event_conflict', __( 'The loyalty event key belongs to another customer.', 'epasscard' ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End read-only transaction.
			$wpdb->query( 'COMMIT' );
			return array(
				'created' => false,
				'entry'   => $existing,
				'account' => $account,
			);
		}

		if ( 'order_reinstatement' === $entry_type && ! empty( $context['cap_to_outstanding_order_reversal'] ) ) {
			$requested_points = max( 0, $points_delta );
			$points_delta   = min(
				$requested_points,
				self::get_outstanding_order_reversal( absint( $context['order_id'] ?? 0 ) )
			);
			$lifetime_delta = $points_delta;
			if ( $requested_points > 0 && isset( $context['amount'] ) ) {
				$context['amount'] = (float) $context['amount'] * ( $points_delta / $requested_points );
			}
		}

		$reclassified_points = 0;
		$refund_award_points = absint( $context['refund_award_points'] ?? 0 );
		$refund_award_amount = (float) ( $context['refund_award_amount'] ?? 0 );
		if ( 'refund_reversal' === $entry_type && $refund_award_points > 0 && $refund_award_amount > 0 ) {
			$refund_order_id = absint( $context['order_id'] ?? 0 );
			$refund_id       = absint( $context['refund_id'] ?? 0 );
			$refund_totals   = self::get_refund_totals_for_order( $refund_order_id );
			$target_points = self::round_points(
				$refund_award_points * min(
					1,
					( $refund_totals['amount'] + abs( (float) ( $context['amount'] ?? 0 ) ) ) / $refund_award_amount
				),
				(string) ( $context['refund_rounding'] ?? 'floor' )
			);
			$points_delta   = -max( 0, $target_points - $refund_totals['points'] );
			$lifetime_delta = $points_delta;

			$net_reversed = self::query_reversed_points_for_order( $refund_order_id );
			$available    = max( 0, $refund_award_points - $net_reversed );
			$shortfall    = max( 0, abs( $points_delta ) - $available );
			if ( $shortfall > 0 && $refund_id > 0 ) {
				$reclassified_points = min(
					$shortfall,
					self::get_outstanding_order_reversal( $refund_order_id )
				);

				if ( $reclassified_points > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reclassify a cancellation reversal within the same transaction.
					$reclassified = $wpdb->insert(
						$ledger_table,
						array(
							'user_id'        => $user_id,
							'event_key'      => 'refund_reclassification:' . $refund_id,
							'entry_type'     => 'order_reinstatement',
							'points_delta'   => $reclassified_points,
							'lifetime_delta' => $reclassified_points,
							'order_id'       => $refund_order_id,
							'refund_id'      => $refund_id,
							'amount'         => self::format_decimal( 0 ),
							'currency'       => substr( sanitize_text_field( (string) ( $context['currency'] ?? '' ) ), 0, 8 ),
							'description'    => __( 'Cancellation reversal reclassified as a refund reversal', 'epasscard' ),
							'meta'           => wp_json_encode( array( 'refund_id' => $refund_id ) ),
						),
						array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
					);

					if ( false === $reclassified ) {
						self::rollback();
						return new WP_Error( 'epc_loyalty_reclassification_failed', __( 'The refund reversal could not be reconciled.', 'epasscard' ) );
					}
				}
			}
		}

		$maximum_reversal = absint( $context['maximum_order_reversal'] ?? 0 );
		$order_id         = absint( $context['order_id'] ?? 0 );
		if ( $points_delta < 0 && $maximum_reversal > 0 && $order_id > 0 ) {
			$already_reversed = self::query_reversed_points_for_order( $order_id );
			$allowed          = max( 0, $maximum_reversal - $already_reversed );
			$capped_points    = min( abs( $points_delta ), $allowed );
			$points_delta     = -$capped_points;

			if ( $lifetime_delta < 0 ) {
				$lifetime_delta = -min( abs( $lifetime_delta ), $capped_points );
			}
		}

		if ( ! empty( $context['require_available_balance'] ) && $points_delta < 0 ) {
			$available = (int) $account->points_balance;
			if ( $available < abs( $points_delta ) ) {
				self::rollback();
				return new WP_Error(
					'epc_loyalty_insufficient_points',
					__( 'Not enough loyalty points are available for this redemption.', 'epasscard' )
				);
			}
		}

		$meta = isset( $context['meta'] ) && is_array( $context['meta'] )
			? wp_json_encode( $context['meta'] )
			: null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Immutable custom ledger insert.
		$inserted = $wpdb->insert(
			$ledger_table,
			array(
				'user_id'        => $user_id,
				'event_key'      => $event_key,
				'entry_type'     => $entry_type,
				'points_delta'   => $points_delta,
				'lifetime_delta' => $lifetime_delta,
				'order_id'       => $order_id,
				'refund_id'      => absint( $context['refund_id'] ?? 0 ),
				'amount'         => self::format_decimal( $context['amount'] ?? 0 ),
				'currency'       => substr( sanitize_text_field( (string) ( $context['currency'] ?? '' ) ), 0, 8 ),
				'description'    => sanitize_text_field( (string) ( $context['description'] ?? '' ) ),
				'meta'           => $meta,
				'expires_at'     => self::sanitize_datetime( $context['expires_at'] ?? null ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			self::rollback();
			return new WP_Error( 'epc_loyalty_ledger_write_failed', __( 'The loyalty ledger entry could not be saved.', 'epasscard' ) );
		}

		$entry_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Account row is locked in this transaction.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$account_table}
				SET points_balance = points_balance + %d,
					lifetime_points = GREATEST(0, lifetime_points + %d)
				WHERE user_id = %d",
				$points_delta + $reclassified_points,
				$lifetime_delta + $reclassified_points,
				$user_id
			)
		);

		if ( false === $updated ) {
			self::rollback();
			return new WP_Error( 'epc_loyalty_balance_write_failed', __( 'The loyalty point balance could not be updated.', 'epasscard' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the newly inserted ledger row.
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE id = %d", $entry_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit atomic ledger and balance update.
		$wpdb->query( 'COMMIT' );

		$account = EPC_Loyalty_Account_Service::get( $user_id );
		if ( ! $entry || ! $account ) {
			return new WP_Error( 'epc_loyalty_readback_failed', __( 'The loyalty transaction was saved but could not be read back.', 'epasscard' ) );
		}

		/**
		 * Fires after a new loyalty ledger entry has committed.
		 *
		 * @param object $entry Ledger entry.
		 * @param object $account Updated account.
		 */
		do_action( 'epc_loyalty_balance_changed', $entry, $account );

		return array(
			'created' => true,
			'entry'   => $entry,
			'account' => $account,
		);
	}

	/**
	 * Paginated ledger history for a customer.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function query_for_user( $user_id, array $args = array() ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $user_id <= 0 ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Customer ledger history.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Customer ledger history.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Human-readable labels for ledger entry types.
	 *
	 * @return array<string, string>
	 */
	public static function get_entry_type_labels() {
		$labels = array(
			'manual_adjustment'        => __( 'Manual adjustment', 'epasscard' ),
			'order_award'              => __( 'Purchase', 'epasscard' ),
			'order_reversal'           => __( 'Order reversed', 'epasscard' ),
			'order_reinstatement'      => __( 'Order restored', 'epasscard' ),
			'refund_reversal'          => __( 'Refund', 'epasscard' ),
			'redemption_reservation'   => __( 'Checkout hold', 'epasscard' ),
			'redemption_commit'        => __( 'Redeemed at checkout', 'epasscard' ),
			'redemption_release'       => __( 'Redemption released', 'epasscard' ),
			'redemption_restore'       => __( 'Redemption restored', 'epasscard' ),
			'redemption_reinstatement' => __( 'Redemption restored', 'epasscard' ),
			'milestone_bonus'          => __( 'Reward bonus', 'epasscard' ),
			'point_expiry'             => __( 'Points expired', 'epasscard' ),
		);

		/**
		 * Filter loyalty ledger entry type labels.
		 *
		 * @param array<string, string> $labels Type slug => label.
		 */
		return (array) apply_filters( 'epc_loyalty_ledger_entry_type_labels', $labels );
	}

	/**
	 * Format a ledger row for staff history UI.
	 *
	 * @param object $entry Ledger row.
	 * @return array<string, mixed>
	 */
	public static function format_entry_for_admin( $entry ) {
		$labels     = self::get_entry_type_labels();
		$type       = (string) ( $entry->entry_type ?? '' );
		$points     = (int) ( $entry->points_delta ?? 0 );
		$lifetime   = (int) ( $entry->lifetime_delta ?? 0 );
		$order_id   = absint( $entry->order_id ?? 0 );
		$order_url  = '';
		$order_label = '';

		if ( $order_id > 0 ) {
			$order_label = sprintf(
				/* translators: %d: order ID. */
				__( 'Order #%d', 'epasscard' ),
				$order_id
			);
			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
					$order_url = (string) $order->get_edit_order_url();
				}
			}
			if ( '' === $order_url ) {
				$order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			}
		}

		$created = (string) ( $entry->created_at ?? '' );
		$when    = '' !== $created
			? get_date_from_gmt( $created, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			: '';

		$description = trim( (string) ( $entry->description ?? '' ) );
		if ( '' === $description && '' !== $order_label ) {
			$description = $order_label;
		}

		return array(
			'id'              => absint( $entry->id ?? 0 ),
			'created_at'      => $when,
			'type'            => $type,
			'type_label'      => $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ),
			'points_delta'    => $points,
			'points_display'  => ( $points > 0 ? '+' : '' ) . number_format_i18n( $points ),
			'lifetime_delta'  => $lifetime,
			'lifetime_display' => 0 === $lifetime ? '—' : ( ( $lifetime > 0 ? '+' : '' ) . number_format_i18n( $lifetime ) ),
			'description'     => $description,
			'order_id'        => $order_id,
			'order_url'       => $order_url,
			'order_label'     => $order_label,
		);
	}

	/**
	 * Get a ledger row by event key.
	 *
	 * @param string $event_key Event key.
	 * @return object|null
	 */
	public static function get_by_event_key( $event_key ) {
		global $wpdb;

		$event_key = self::normalize_event_key( $event_key );
		if ( '' === $event_key ) {
			return null;
		}

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom ledger lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_key = %s LIMIT 1", $event_key ) );
		return $row ?: null;
	}

	/**
	 * Total points already reversed for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int
	 */
	public static function get_reversed_points_for_order( $order_id ) {
		return self::query_reversed_points_for_order( absint( $order_id ) );
	}

	/**
	 * Points and qualifying amount already processed through refund entries.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{points: int, amount: float}
	 */
	public static function get_refund_totals_for_order( $order_id ) {
		global $wpdb;

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate custom ledger lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(ABS(points_delta)), 0) AS points,
					COALESCE(SUM(ABS(amount)), 0) AS amount
				FROM {$table}
				WHERE order_id = %d AND entry_type = 'refund_reversal'",
				absint( $order_id )
			)
		);

		return array(
			'points' => $row ? (int) $row->points : 0,
			'amount' => $row ? (float) $row->amount : 0.0,
		);
	}

	/**
	 * Cancellation points not subsequently reinstated.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int
	 */
	public static function get_outstanding_order_reversal( $order_id ) {
		global $wpdb;

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate custom ledger lookup.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(
					CASE
						WHEN entry_type = 'order_reversal' THEN ABS(points_delta)
						WHEN entry_type = 'order_reinstatement' THEN -ABS(points_delta)
						ELSE 0
					END
				), 0)
				FROM {$table}
				WHERE order_id = %d",
				absint( $order_id )
			)
		);

		return max( 0, (int) $total );
	}

	/**
	 * Number of cancellation reversal cycles recorded for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int
	 */
	public static function get_order_reversal_count( $order_id ) {
		global $wpdb;

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate custom ledger lookup.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND entry_type = 'order_reversal'",
				absint( $order_id )
			)
		);
	}

	/**
	 * Query total points already reversed for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int
	 */
	private static function query_reversed_points_for_order( $order_id ) {
		global $wpdb;

		$table = EPC_DB::loyalty_ledger_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate custom ledger lookup.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(
					CASE
						WHEN entry_type IN ('refund_reversal', 'order_reversal') THEN ABS(points_delta)
						WHEN entry_type = 'order_reinstatement' THEN -ABS(points_delta)
						ELSE 0
					END
				), 0) FROM {$table}
				WHERE order_id = %d
				AND entry_type IN ('refund_reversal', 'order_reversal', 'order_reinstatement')",
				absint( $order_id )
			)
		);

		return max( 0, (int) $total );
	}

	/**
	 * Normalize an event key while preserving a collision-resistant suffix.
	 *
	 * @param string $event_key Raw event key.
	 * @return string
	 */
	private static function normalize_event_key( $event_key ) {
		$event_key = trim( sanitize_text_field( (string) $event_key ) );
		if ( strlen( $event_key ) <= 191 ) {
			return $event_key;
		}

		return substr( $event_key, 0, 150 ) . ':' . hash( 'sha256', $event_key );
	}

	/**
	 * Normalize an optional MySQL datetime.
	 *
	 * @param mixed $value Raw datetime.
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
	 * Format a decimal without requiring WooCommerce to be loaded.
	 *
	 * @param mixed $value Raw numeric value.
	 * @return string
	 */
	private static function format_decimal( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value, 6 );
		}

		return number_format( (float) $value, 6, '.', '' );
	}

	/**
	 * Round a fractional points value using the saved award policy.
	 *
	 * @param float  $points Raw points.
	 * @param string $rounding Rounding mode.
	 * @return int
	 */
	private static function round_points( $points, $rounding ) {
		$rounding = sanitize_key( $rounding );
		if ( 'ceil' === $rounding ) {
			return (int) ceil( $points );
		}
		if ( 'round' === $rounding ) {
			return (int) round( $points );
		}
		return (int) floor( $points );
	}

	/**
	 * Roll back the active database transaction.
	 *
	 * @return void
	 */
	private static function rollback() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic custom-table mutation.
		$wpdb->query( 'ROLLBACK' );
	}
}
