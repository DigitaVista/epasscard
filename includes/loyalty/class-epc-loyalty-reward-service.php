<?php
/**
 * Loyalty tiers, milestones, claims, and reward issuance.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates lifetime progress and maintains reward claims.
 */
class EPC_Loyalty_Reward_Service {

	public const TIERS_OPTION = 'epc_loyalty_tiers';
	public const MILESTONES_OPTION = 'epc_loyalty_milestones';
	public const CRON_HOOK = 'epc_loyalty_expiry_event';

	/**
	 * Claim insert mode: empty (normal) or waive (record without issuing).
	 *
	 * @var string
	 */
	private static $claim_mode = '';

	/**
	 * Override how new milestone claims are stored.
	 *
	 * @param string $mode Empty or waive.
	 * @return void
	 */
	public static function set_claim_mode( $mode ) {
		self::$claim_mode = sanitize_key( (string) $mode );
	}

	/**
	 * Register lifecycle hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'epc_loyalty_balance_changed', array( __CLASS__, 'on_balance_changed' ), 20, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_expiry' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_reward_coupon_owner' ), 20, 3 );
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'on_coupon_applied' ) );
		add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'on_coupon_removed' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_free_reward_products' ) );
	}

	/**
	 * Schedule daily expiry processing.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear expiry schedule.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Read normalized tiers sorted by threshold.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tiers() {
		EPC_Loyalty_Starter::maybe_install();

		$saved = get_option( self::TIERS_OPTION, array() );
		$items = is_array( $saved ) && isset( $saved['tiers'] ) && is_array( $saved['tiers'] ) ? $saved['tiers'] : $saved;
		$tiers = array();

		foreach ( is_array( $items ) ? $items : array() as $index => $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $tier['id'] ?? 'tier-' . ( $index + 1 ) ) );
			if ( '' === $id ) {
				continue;
			}
			$tiers[] = array(
				'id'        => substr( $id, 0, 64 ),
				'name'      => sanitize_text_field( (string) ( $tier['name'] ?? $id ) ),
				'threshold' => max( 0, absint( $tier['threshold'] ?? 0 ) ),
				'active'    => ! isset( $tier['active'] ) || filter_var( $tier['active'], FILTER_VALIDATE_BOOLEAN ),
			);
		}

		usort(
			$tiers,
			static function ( $left, $right ) {
				return (int) $left['threshold'] <=> (int) $right['threshold'];
			}
		);
		return $tiers;
	}

	/**
	 * Save sanitized tier definitions.
	 *
	 * @param array<int, array<string, mixed>> $tiers Tiers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_tiers( array $tiers ) {
		update_option( self::TIERS_OPTION, array( 'version' => 1, 'tiers' => $tiers ) );
		$clean = self::get_tiers();
		update_option( self::TIERS_OPTION, array( 'version' => 1, 'tiers' => $clean ) );
		return $clean;
	}

	/**
	 * Read normalized milestones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_milestones() {
		EPC_Loyalty_Starter::maybe_install();

		$saved = get_option( self::MILESTONES_OPTION, array() );
		$items = is_array( $saved ) && isset( $saved['milestones'] ) && is_array( $saved['milestones'] )
			? $saved['milestones']
			: ( is_array( $saved ) ? $saved : array() );
		$milestones = array();

		foreach ( $items as $index => $milestone ) {
			if ( ! is_array( $milestone ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $milestone['id'] ?? 'milestone-' . ( $index + 1 ) ) );
			if ( '' === $id ) {
				continue;
			}
			$reward = isset( $milestone['reward'] ) && is_array( $milestone['reward'] ) ? $milestone['reward'] : array();
			$type   = sanitize_key( (string) ( $reward['type'] ?? 'bonus_points' ) );
			if ( ! in_array( $type, array( 'fixed_coupon', 'percentage_coupon', 'free_shipping', 'free_product', 'bonus_points' ), true ) ) {
				$type = 'bonus_points';
			}
			$claim_mode = sanitize_key( (string) ( $milestone['claim_mode'] ?? 'automatic' ) );

			$milestones[] = array(
				'id'          => substr( $id, 0, 64 ),
				'name'        => sanitize_text_field( (string) ( $milestone['name'] ?? $id ) ),
				'active'      => ! isset( $milestone['active'] ) || filter_var( $milestone['active'], FILTER_VALIDATE_BOOLEAN ),
				'threshold'   => max( 1, absint( $milestone['threshold'] ?? 1 ) ),
				'repeatable'  => ! empty( $milestone['repeatable'] ) && filter_var( $milestone['repeatable'], FILTER_VALIDATE_BOOLEAN ),
				'claim_mode'  => 'manual' === $claim_mode ? 'manual' : 'automatic',
				'expiry_days' => min( 3650, absint( $milestone['expiry_days'] ?? 0 ) ),
				'reward'      => array(
					'type'       => $type,
					'amount'     => max( 0, (float) ( $reward['amount'] ?? 0 ) ),
					'product_id' => absint( $reward['product_id'] ?? 0 ),
				),
			);
		}

		usort(
			$milestones,
			static function ( $left, $right ) {
				return (int) $left['threshold'] <=> (int) $right['threshold'];
			}
		);
		return $milestones;
	}

	/**
	 * Save sanitized milestone definitions.
	 *
	 * @param array<int, array<string, mixed>> $milestones Milestones.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_milestones( array $milestones ) {
		update_option( self::MILESTONES_OPTION, array( 'version' => 1, 'milestones' => $milestones ) );
		$clean = self::get_milestones();
		update_option( self::MILESTONES_OPTION, array( 'version' => 1, 'milestones' => $clean ) );
		return $clean;
	}

	/**
	 * Current tier and next progress for a lifetime balance.
	 *
	 * @param int $lifetime_points Lifetime points.
	 * @return array{current: array<string, mixed>|null, next: array<string, mixed>|null}
	 */
	public static function get_tier_progress( $lifetime_points ) {
		$current = null;
		$next    = null;
		foreach ( self::get_tiers() as $tier ) {
			if ( empty( $tier['active'] ) ) {
				continue;
			}
			if ( (int) $tier['threshold'] <= (int) $lifetime_points ) {
				$current = $tier;
			} elseif ( null === $next ) {
				$next = $tier;
			}
		}
		return array( 'current' => $current, 'next' => $next );
	}

	/**
	 * Next milestone and progress.
	 *
	 * @param int $lifetime_points Lifetime points.
	 * @return array<string, mixed>|null
	 */
	public static function get_next_milestone( $lifetime_points ) {
		foreach ( self::get_milestones() as $milestone ) {
			if ( empty( $milestone['active'] ) ) {
				continue;
			}
			if ( ! empty( $milestone['repeatable'] ) ) {
				$cycle = (int) floor( max( 0, (int) $lifetime_points ) / (int) $milestone['threshold'] ) + 1;
				$milestone['next_threshold'] = $cycle * (int) $milestone['threshold'];
				return $milestone;
			}
			if ( (int) $milestone['threshold'] > (int) $lifetime_points ) {
				$milestone['next_threshold'] = (int) $milestone['threshold'];
				return $milestone;
			}
		}
		return null;
	}

	/**
	 * Evaluate progress after a committed ledger mutation.
	 *
	 * @param object $entry Ledger entry.
	 * @param object $account Account.
	 * @return void
	 */
	public static function on_balance_changed( $entry, $account ) {
		$user_id = absint( $account->user_id ?? 0 );
		if ( $user_id <= 0 ) {
			return;
		}

		$previous_lifetime = max( 0, (int) $account->lifetime_points - (int) ( $entry->lifetime_delta ?? 0 ) );
		$previous_tier     = self::get_tier_progress( $previous_lifetime )['current'];
		$current_tier      = self::get_tier_progress( (int) $account->lifetime_points )['current'];

		if ( $current_tier && ( ! $previous_tier || $previous_tier['id'] !== $current_tier['id'] ) ) {
			$highest = absint( get_user_meta( $user_id, 'epc_loyalty_highest_tier_threshold', true ) );
			if ( (int) $current_tier['threshold'] > $highest ) {
				update_user_meta( $user_id, 'epc_loyalty_highest_tier_threshold', (int) $current_tier['threshold'] );
				do_action( 'epc_loyalty_tier_reached', $user_id, $current_tier, $account );
			}
		}

		if ( (int) ( $entry->lifetime_delta ?? 0 ) > 0 ) {
			self::create_earned_claims( $user_id, (int) $account->lifetime_points );
		}
	}

	/**
	 * Create all milestone claims earned by a lifetime total.
	 *
	 * @param int $user_id User ID.
	 * @param int $lifetime_points Lifetime points.
	 * @return array<int, object>
	 */
	public static function create_earned_claims( $user_id, $lifetime_points ) {
		$created = array();
		foreach ( self::get_milestones() as $milestone ) {
			if ( empty( $milestone['active'] ) || $lifetime_points < (int) $milestone['threshold'] ) {
				continue;
			}

			$cycles = ! empty( $milestone['repeatable'] )
				? (int) floor( $lifetime_points / (int) $milestone['threshold'] )
				: 1;

			for ( $cycle = 1; $cycle <= $cycles; $cycle++ ) {
				$claim = self::create_claim( $user_id, $milestone, $cycle );
				if ( $claim && ! is_wp_error( $claim ) ) {
					$created[] = $claim;
				}
			}
		}
		return $created;
	}

	/**
	 * Create one idempotent milestone claim.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $milestone Milestone.
	 * @param int                  $cycle Repeat cycle.
	 * @return object|\WP_Error|null
	 */
	private static function create_claim( $user_id, array $milestone, $cycle ) {
		global $wpdb;

		$reward_key = 'milestone:' . $milestone['id'] . ( ! empty( $milestone['repeatable'] ) ? ':' . absint( $cycle ) : '' );
		$expires_at = (int) $milestone['expiry_days'] > 0
			? gmdate( 'Y-m-d H:i:s', time() + ( (int) $milestone['expiry_days'] * DAY_IN_SECONDS ) )
			: null;
		$table  = EPC_DB::loyalty_claims_table_name();
		$status = 'waive' === self::$claim_mode ? 'waived' : 'available';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Idempotent custom claim insert.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(user_id, reward_key, reward_type, status, meta, expires_at)
				VALUES (%d, %s, %s, %s, %s, NULLIF(%s, ''))",
				absint( $user_id ),
				$reward_key,
				$milestone['reward']['type'],
				$status,
				wp_json_encode( array( 'milestone' => $milestone, 'cycle' => absint( $cycle ) ) ),
				$expires_at
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'epc_loyalty_claim_failed', __( 'The loyalty reward claim could not be created.', 'epasscard' ) );
		}
		if ( 0 === $inserted ) {
			$existing = self::get_claim_by_reward_key( $user_id, $reward_key );
			if ( $existing && 'available' === $existing->status && 'automatic' === $milestone['claim_mode'] && 'waive' !== self::$claim_mode ) {
				return self::issue_claim( (int) $existing->id, absint( $user_id ) );
			}
			return null;
		}

		$claim = self::get_claim( (int) $wpdb->insert_id );
		if ( ! $claim ) {
			return new WP_Error( 'epc_loyalty_claim_read_failed', __( 'The loyalty reward was created but could not be read.', 'epasscard' ) );
		}

		if ( 'waived' === (string) $claim->status ) {
			return $claim;
		}

		do_action( 'epc_loyalty_reward_available', $claim, $milestone );

		if ( 'automatic' === $milestone['claim_mode'] ) {
			return self::issue_claim( (int) $claim->id, absint( $user_id ) );
		}
		return $claim;
	}

	/**
	 * Issue a claim, enforcing ownership and idempotency.
	 *
	 * @param int $claim_id Claim ID.
	 * @param int $user_id Expected owner.
	 * @return object|\WP_Error
	 */
	public static function issue_claim( $claim_id, $user_id ) {
		global $wpdb;

		$claim_id = absint( $claim_id );
		$user_id  = absint( $user_id );
		$table    = EPC_DB::loyalty_claims_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Claim row lock.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked claim lookup.
		$claim = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $claim_id ) );

		if ( ! $claim || (int) $claim->user_id !== $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End failed claim transaction.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'epc_loyalty_claim_missing', __( 'The loyalty reward was not found.', 'epasscard' ) );
		}
		if ( 'issued' === $claim->status || 'claimed' === $claim->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End idempotent claim transaction.
			$wpdb->query( 'COMMIT' );
			return $claim;
		}
		if ( 'available' !== $claim->status || ( $claim->expires_at && strtotime( $claim->expires_at . ' UTC' ) <= time() ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End unavailable claim transaction.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'epc_loyalty_claim_unavailable', __( 'This loyalty reward is no longer available.', 'epasscard' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reserve claim against concurrent issuance.
		$updated = $wpdb->update( $table, array( 'status' => 'processing' ), array( 'id' => $claim_id ), array( '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End failed reservation transaction.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'epc_loyalty_claim_lock_failed', __( 'The loyalty reward could not be reserved.', 'epasscard' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit claim reservation.
		$wpdb->query( 'COMMIT' );

		$meta      = self::decode_meta( $claim );
		$milestone = isset( $meta['milestone'] ) && is_array( $meta['milestone'] ) ? $meta['milestone'] : array();
		$result    = self::issue_reward( $claim, $milestone );

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Release failed reservation.
			$wpdb->update( $table, array( 'status' => 'available' ), array( 'id' => $claim_id, 'status' => 'processing' ), array( '%s' ), array( '%d', '%s' ) );
			return $result;
		}

		$meta['issued'] = $result;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Finalize reward claim.
		$wpdb->update(
			$table,
			array(
				'status'      => 'issued',
				'usage_count' => 1,
				'meta'        => wp_json_encode( $meta ),
				'claimed_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $claim_id, 'status' => 'processing' ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		$claim = self::get_claim( $claim_id );
		do_action( 'epc_loyalty_reward_issued', $claim, $milestone );
		return $claim;
	}

	/**
	 * Issue the concrete reward.
	 *
	 * @param object               $claim Claim.
	 * @param array<string, mixed> $milestone Milestone.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function issue_reward( $claim, array $milestone ) {
		$reward = isset( $milestone['reward'] ) && is_array( $milestone['reward'] ) ? $milestone['reward'] : array();
		$type   = sanitize_key( (string) ( $reward['type'] ?? $claim->reward_type ) );

		if ( 'bonus_points' === $type ) {
			$points = absint( $reward['amount'] ?? 0 );
			if ( $points <= 0 ) {
				return new WP_Error( 'epc_loyalty_reward_invalid', __( 'The bonus-points reward has no point value.', 'epasscard' ) );
			}
			$record = EPC_Loyalty_Ledger_Service::record(
				(int) $claim->user_id,
				'claim_reward:' . (int) $claim->id,
				'milestone_bonus',
				$points,
				0,
				array(
					'description' => sanitize_text_field( (string) ( $milestone['name'] ?? __( 'Milestone bonus', 'epasscard' ) ) ),
					'meta'        => array( 'claim_id' => (int) $claim->id ),
				)
			);
			return is_wp_error( $record ) ? $record : array( 'points' => $points );
		}

		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error( 'epc_loyalty_woocommerce_missing', __( 'WooCommerce is required to issue this reward.', 'epasscard' ) );
		}

		$user = get_userdata( (int) $claim->user_id );
		if ( ! $user ) {
			return new WP_Error( 'epc_loyalty_reward_user_missing', __( 'The reward customer no longer exists.', 'epasscard' ) );
		}

		$code   = 'epc-' . (int) $claim->user_id . '-' . substr( hash( 'sha256', wp_salt( 'nonce' ) . ':' . (int) $claim->id ), 0, 12 );
		$coupon = new WC_Coupon( $code );

		if ( 'percentage_coupon' === $type ) {
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( min( 100, max( 0, (float) ( $reward['amount'] ?? 0 ) ) ) );
		} elseif ( 'free_product' === $type ) {
			$product_id = absint( $reward['product_id'] ?? 0 );
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( ! $product ) {
				return new WP_Error( 'epc_loyalty_reward_product_missing', __( 'The free-product reward product was not found.', 'epasscard' ) );
			}
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 0 );
		} else {
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 'free_shipping' === $type ? 0 : max( 0, (float) ( $reward['amount'] ?? 0 ) ) );
			if ( 'free_shipping' === $type ) {
				$coupon->set_free_shipping( true );
			}
		}

		$coupon->set_description( sprintf( 'EpassCard loyalty claim #%d', (int) $claim->id ) );
		$coupon->set_email_restrictions( array( (string) $user->user_email ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		$coupon->update_meta_data( '_epc_loyalty_claim_id', (int) $claim->id );
		$coupon->update_meta_data( '_epc_loyalty_user_id', (int) $claim->user_id );
		if ( 'free_product' === $type ) {
			$coupon->update_meta_data( '_epc_loyalty_free_product_id', absint( $reward['product_id'] ?? 0 ) );
		}
		if ( ! empty( $claim->expires_at ) ) {
			$coupon->set_date_expires( strtotime( $claim->expires_at . ' UTC' ) );
		}

		try {
			$coupon_id = $coupon->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'epc_loyalty_coupon_failed', $exception->getMessage() );
		}

		if ( ! $coupon_id ) {
			return new WP_Error( 'epc_loyalty_coupon_failed', __( 'The loyalty coupon could not be created.', 'epasscard' ) );
		}

		return array( 'coupon_id' => (int) $coupon_id, 'coupon_code' => $code );
	}

	/**
	 * Restrict issued milestone coupons to the WordPress account that earned them.
	 *
	 * WooCommerce email restrictions alone can be bypassed by entering another
	 * customer's email at checkout, so loyalty ownership must be account-bound.
	 *
	 * @param bool              $valid Validity from earlier checks.
	 * @param \WC_Coupon        $coupon Coupon being validated.
	 * @param \WC_Discounts|null $discounts Discounts helper.
	 * @return bool
	 * @throws \Exception When the current customer does not own the reward.
	 */
	public static function validate_reward_coupon_owner( $valid, $coupon, $discounts = null ) {
		unset( $discounts );

		if ( ! $valid || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_meta' ) ) {
			return $valid;
		}

		$claim_id = absint( $coupon->get_meta( '_epc_loyalty_claim_id', true ) );
		if ( $claim_id <= 0 ) {
			return $valid;
		}

		$owner_id = absint( $coupon->get_meta( '_epc_loyalty_user_id', true ) );
		if ( $owner_id <= 0 || $owner_id !== get_current_user_id() ) {
			throw new Exception( esc_html_e( 'This loyalty reward belongs to another customer.', 'epasscard' ) );
		}

		$claim = self::get_claim( $claim_id );
		if ( ! $claim || (int) $claim->user_id !== $owner_id || 'issued' !== (string) $claim->status ) {
			throw new Exception( esc_html_e( 'This loyalty reward is no longer available.', 'epasscard' ) );
		}

		return true;
	}

	/**
	 * Add the configured free product when its private reward coupon is applied.
	 *
	 * @param string $coupon_code Coupon code.
	 * @return void
	 */
	public static function on_coupon_applied( $coupon_code ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! class_exists( 'WC_Coupon' ) ) {
			return;
		}

		$coupon     = new WC_Coupon( sanitize_text_field( (string) $coupon_code ) );
		$product_id = absint( $coupon->get_meta( '_epc_loyalty_free_product_id', true ) );
		$claim_id   = absint( $coupon->get_meta( '_epc_loyalty_claim_id', true ) );
		if ( $product_id <= 0 || $claim_id <= 0 ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( $claim_id === absint( $cart_item['_epc_loyalty_claim_id'] ?? 0 ) ) {
				return;
			}
		}

		$product      = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$variation_id = $product && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? $product_id : 0;
		$parent_id    = $variation_id > 0 && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : $product_id;
		$variation    = $variation_id > 0 && method_exists( $product, 'get_variation_attributes' )
			? $product->get_variation_attributes()
			: array();
		if ( $product && $product->is_purchasable() ) {
			WC()->cart->add_to_cart(
				$parent_id,
				1,
				$variation_id,
				is_array( $variation ) ? $variation : array(),
				array(
					'_epc_loyalty_claim_id'    => $claim_id,
					'_epc_loyalty_coupon_code' => $coupon->get_code(),
				)
			);
		}
	}

	/**
	 * Remove an automatically added reward product with its coupon.
	 *
	 * @param string $coupon_code Coupon code.
	 * @return void
	 */
	public static function on_coupon_removed( $coupon_code ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$coupon_code = wc_format_coupon_code( (string) $coupon_code );
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$item_code = wc_format_coupon_code( (string) ( $cart_item['_epc_loyalty_coupon_code'] ?? '' ) );
			if ( '' !== $item_code && $coupon_code === $item_code ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}
	}

	/**
	 * Keep tagged reward products free only while their claim coupon is applied.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public static function price_free_reward_products( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return;
		}

		$applied = array_map( 'wc_format_coupon_code', $cart->get_applied_coupons() );
		foreach ( $cart->get_cart() as $cart_item ) {
			$coupon_code = wc_format_coupon_code( (string) ( $cart_item['_epc_loyalty_coupon_code'] ?? '' ) );
			if ( '' === $coupon_code || ! in_array( $coupon_code, $applied, true ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			if ( is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'set_price' ) ) {
				$cart_item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Process expired claims and point awards.
	 *
	 * @return void
	 */
	public static function process_expiry() {
		global $wpdb;

		EPC_Loyalty_Notification_Service::process_expiring_points();

		$claims = EPC_DB::loyalty_claims_table_name();
		$ledger = EPC_DB::loyalty_ledger_table_name();
		$now    = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled claim expiry candidates.
		$expired_claims = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$claims} WHERE status = 'available'
				AND expires_at IS NOT NULL AND expires_at <= %s LIMIT 500",
				$now
			)
		);
		foreach ( is_array( $expired_claims ) ? $expired_claims : array() as $claim ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Scheduled claim expiry.
			$updated = $wpdb->update(
				$claims,
				array( 'status' => 'expired' ),
				array( 'id' => (int) $claim->id, 'status' => 'available' ),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( $updated ) {
				$claim->status = 'expired';
				do_action( 'epc_loyalty_reward_expired', $claim );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled point expiry candidates.
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$ledger} l
				LEFT JOIN {$ledger} x ON x.event_key = CONCAT('point_expiry:', l.id)
				WHERE l.points_delta > 0 AND l.expires_at IS NOT NULL AND l.expires_at <= %s AND x.id IS NULL
				ORDER BY l.id ASC LIMIT 500",
				$now
			)
		);

		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$account = EPC_Loyalty_Account_Service::get( (int) $entry->user_id );
			$outstanding = (int) $entry->points_delta;
			if ( (int) $entry->order_id > 0 ) {
				$outstanding = max(
					0,
					$outstanding - EPC_Loyalty_Ledger_Service::get_reversed_points_for_order( (int) $entry->order_id )
				);
			}
			$points = $account ? min( max( 0, (int) $account->points_balance ), $outstanding ) : 0;
			EPC_Loyalty_Ledger_Service::record(
				(int) $entry->user_id,
				'point_expiry:' . (int) $entry->id,
				'point_expiry',
				-$points,
				0,
				array(
					'description' => __( 'Loyalty points expired', 'epasscard' ),
					'meta'        => array( 'source_entry_id' => (int) $entry->id ),
				)
			);
		}
	}

	/**
	 * Get one claim.
	 *
	 * @param int $claim_id Claim ID.
	 * @return object|null
	 */
	public static function get_claim( $claim_id ) {
		global $wpdb;
		$table = EPC_DB::loyalty_claims_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom claim lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $claim_id ) ) );
		return $row ?: null;
	}

	/**
	 * Get a customer's claim by its idempotency key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $reward_key Reward key.
	 * @return object|null
	 */
	private static function get_claim_by_reward_key( $user_id, $reward_key ) {
		global $wpdb;
		$table = EPC_DB::loyalty_claims_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND reward_key = %s LIMIT 1",
				absint( $user_id ),
				sanitize_text_field( (string) $reward_key )
			)
		);
		return $row ?: null;
	}

	/**
	 * Available claims for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, object>
	 */
	public static function get_available_claims( $user_id ) {
		global $wpdb;
		$table = EPC_DB::loyalty_claims_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Customer claim list.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = 'available'
				AND (expires_at IS NULL OR expires_at > %s) ORDER BY created_at DESC",
				absint( $user_id ),
				current_time( 'mysql', true )
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decode claim metadata.
	 *
	 * @param object $claim Claim.
	 * @return array<string, mixed>
	 */
	private static function decode_meta( $claim ) {
		$meta = ! empty( $claim->meta ) ? json_decode( (string) $claim->meta, true ) : array();
		return is_array( $meta ) ? $meta : array();
	}
}
