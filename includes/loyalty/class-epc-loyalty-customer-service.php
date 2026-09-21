<?php
/**
 * Loyalty customer listing, lookup, adjustments, and export.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin and staff-facing loyalty customer operations.
 */
class EPC_Loyalty_Customer_Service {

	/**
	 * Register privacy hooks even when the loyalty module is disabled.
	 *
	 * @return void
	 */
	public static function init_privacy() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_eraser' ) );
		add_action( 'deleted_user', array( __CLASS__, 'delete_user_data' ) );
	}

	/**
	 * Register admin export and staff lookup hooks.
	 *
	 * @return void
	 */
	public static function init() {
		self::init_privacy();
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_customers' ) );
		add_action( 'wp_ajax_epc_loyalty_lookup_member', array( __CLASS__, 'ajax_lookup_member' ) );
		add_action( 'wp_ajax_epc_loyalty_customer_history', array( __CLASS__, 'ajax_customer_history' ) );
		add_action( 'wp_ajax_epc_loyalty_adjust_points', array( __CLASS__, 'ajax_adjust_points' ) );
		add_action( 'wp_ajax_epc_loyalty_send_customer_email', array( __CLASS__, 'ajax_send_customer_email' ) );
		add_filter( 'epc_pass_email_order_passes', array( __CLASS__, 'filter_order_email_passes' ), 10, 3 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'ensure_pass_before_order_email' ), 5, 4 );
	}

	/**
	 * Register the loyalty personal-data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_privacy_exporter( $exporters ) {
		$exporters['epasscard-loyalty'] = array(
			'exporter_friendly_name' => __( 'EpassCard loyalty data', 'epasscard' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the loyalty personal-data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_privacy_eraser( $erasers ) {
		$erasers['epasscard-loyalty'] = array(
			'eraser_friendly_name' => __( 'EpassCard loyalty data', 'epasscard' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of loyalty account, ledger, claim, and pass data.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page Page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$user_id  = (int) $user->ID;
		$page     = max( 1, absint( $page ) );
		$per_page = 100;
		$data     = array();
		$account  = EPC_Loyalty_Account_Service::get( $user_id );

		if ( 1 === $page && $account ) {
			$data[] = array(
				'group_id'    => 'epasscard-loyalty',
				'group_label' => __( 'EpassCard loyalty', 'epasscard' ),
				'item_id'     => 'loyalty-account-' . (int) $account->id,
				'data'        => array(
					array( 'name' => __( 'Membership ID', 'epasscard' ), 'value' => (string) $account->member_id ),
					array( 'name' => __( 'Spendable points', 'epasscard' ), 'value' => (int) $account->points_balance ),
					array( 'name' => __( 'Lifetime points', 'epasscard' ), 'value' => (int) $account->lifetime_points ),
					array( 'name' => __( 'Created', 'epasscard' ), 'value' => (string) $account->created_at ),
					array( 'name' => __( 'Updated', 'epasscard' ), 'value' => (string) $account->updated_at ),
				),
			);

			$pass = EPC_DB::get_pass( 'woocommerce-loyalty', $user_id );
			if ( $pass ) {
				$data[] = array(
					'group_id'    => 'epasscard-loyalty',
					'group_label' => __( 'EpassCard loyalty', 'epasscard' ),
					'item_id'     => 'loyalty-pass-' . (int) $pass->id,
					'data'        => array(
						array( 'name' => __( 'Pass status', 'epasscard' ), 'value' => (string) $pass->status ),
						array( 'name' => __( 'Pass link', 'epasscard' ), 'value' => (string) $pass->pass_link ),
						array( 'name' => __( 'Pass identifier', 'epasscard' ), 'value' => (string) $pass->pass_uid ),
						array( 'name' => __( 'Pass metadata', 'epasscard' ), 'value' => (string) $pass->meta ),
					),
				);
			}
		}

		$ledger = EPC_Loyalty_Ledger_Service::query_for_user(
			$user_id,
			array( 'page' => $page, 'per_page' => $per_page )
		);
		foreach ( $ledger['items'] as $entry ) {
			$data[] = array(
				'group_id'    => 'epasscard-loyalty',
				'group_label' => __( 'EpassCard loyalty', 'epasscard' ),
				'item_id'     => 'loyalty-ledger-' . (int) $entry->id,
				'data'        => array(
					array( 'name' => __( 'Transaction type', 'epasscard' ), 'value' => (string) $entry->entry_type ),
					array( 'name' => __( 'Points change', 'epasscard' ), 'value' => (int) $entry->points_delta ),
					array( 'name' => __( 'Lifetime points change', 'epasscard' ), 'value' => (int) $entry->lifetime_delta ),
					array( 'name' => __( 'Order ID', 'epasscard' ), 'value' => (int) $entry->order_id ),
					array( 'name' => __( 'Refund ID', 'epasscard' ), 'value' => (int) $entry->refund_id ),
					array( 'name' => __( 'Description', 'epasscard' ), 'value' => (string) $entry->description ),
					array( 'name' => __( 'Transaction metadata', 'epasscard' ), 'value' => (string) $entry->meta ),
					array( 'name' => __( 'Created', 'epasscard' ), 'value' => (string) $entry->created_at ),
				),
			);
		}

		$claims_table = EPC_DB::loyalty_claims_table_name();
		$offset       = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export for custom claims.
		$claim_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$claims_table} WHERE user_id = %d", $user_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export for custom claims.
		$claims = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$claims_table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		foreach ( is_array( $claims ) ? $claims : array() as $claim ) {
			$data[] = array(
				'group_id'    => 'epasscard-loyalty',
				'group_label' => __( 'EpassCard loyalty', 'epasscard' ),
				'item_id'     => 'loyalty-claim-' . (int) $claim->id,
				'data'        => array(
					array( 'name' => __( 'Reward', 'epasscard' ), 'value' => (string) $claim->reward_key ),
					array( 'name' => __( 'Reward type', 'epasscard' ), 'value' => (string) $claim->reward_type ),
					array( 'name' => __( 'Status', 'epasscard' ), 'value' => (string) $claim->status ),
					array( 'name' => __( 'Expires', 'epasscard' ), 'value' => (string) $claim->expires_at ),
					array( 'name' => __( 'Claimed', 'epasscard' ), 'value' => (string) $claim->claimed_at ),
					array( 'name' => __( 'Reward metadata', 'epasscard' ), 'value' => (string) $claim->meta ),
					array( 'name' => __( 'Created', 'epasscard' ), 'value' => (string) $claim->created_at ),
				),
			);
		}

		$ledger_done = ( $page * $per_page ) >= (int) $ledger['total'];
		$claims_done = ( $page * $per_page ) >= $claim_total;
		return array( 'data' => $data, 'done' => $ledger_done && $claims_done );
	}

	/**
	 * Erase loyalty data for a verified WordPress privacy request.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page Page number.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$pass            = EPC_DB::get_pass( 'woocommerce-loyalty', (int) $user->ID );
		$remote_retained = $pass && ! empty( $pass->pass_uid );
		$order_result    = self::erase_order_loyalty_meta( (int) $user->ID, max( 1, absint( $page ) ) );
		$removed         = self::delete_user_data( (int) $user->ID ) || $order_result['removed'];
		return array(
			'items_removed'  => $removed,
			'items_retained' => $remote_retained,
			'messages'       => $remote_retained
				? array( __( 'The local loyalty data was erased. The EpassCard service does not currently provide a pass-deletion API, so the remote wallet pass must be removed through EpassCard support.', 'epasscard' ) )
				: array(),
			'done'           => $order_result['done'],
		);
	}

	/**
	 * Remove loyalty-only metadata from retained WooCommerce orders via CRUD.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $page Page number.
	 * @return array{removed: bool, done: bool}
	 */
	private static function erase_order_loyalty_meta( $user_id, $page ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'removed' => false, 'done' => true );
		}

		$result = wc_get_orders(
			array(
				'customer_id' => absint( $user_id ),
				'limit'       => 100,
				'page'        => max( 1, absint( $page ) ),
				'paginate'    => true,
			)
		);
		if ( ! is_object( $result ) || ! isset( $result->orders ) ) {
			return array( 'removed' => false, 'done' => true );
		}

		$removed = false;
		$keys    = array(
			'_epc_loyalty_points_awarded',
			'_epc_loyalty_points_redeemed',
			'_epc_loyalty_redemption_amount',
			'_epc_loyalty_redemption_coupon',
			'_epc_loyalty_redemption_status',
			'_epc_loyalty_redemption_settings',
			'_epc_loyalty_redemption_cycle',
		);
		foreach ( $result->orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$changed = false;
			foreach ( $keys as $key ) {
				if ( $order->meta_exists( $key ) ) {
					$order->delete_meta_data( $key );
					$changed = true;
				}
			}
			if ( $changed ) {
				$order->save();
				$removed = true;
			}
		}

		return array(
			'removed' => $removed,
			'done'    => max( 1, absint( $page ) ) >= max( 1, absint( $result->max_num_pages ?? 1 ) ),
		);
	}

	/**
	 * Delete local loyalty records and generated reward coupons for one user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool Whether any local data was removed.
	 */
	public static function delete_user_data( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$pass = EPC_DB::get_pass( 'woocommerce-loyalty', $user_id );
		if ( $pass && ! empty( $pass->pass_uid ) ) {
			/**
			 * Fires before a remote loyalty pass reference is removed locally.
			 *
			 * @param string $pass_uid Remote pass identifier.
			 * @param int    $user_id  WordPress user ID.
			 * @param object $pass     Local pass row.
			 */
			do_action( 'epc_loyalty_personal_data_remote_deletion_required', (string) $pass->pass_uid, $user_id, $pass );
		}

		$removed = false;
		foreach (
			array(
				EPC_DB::loyalty_ledger_table_name(),
				EPC_DB::loyalty_claims_table_name(),
				EPC_DB::loyalty_accounts_table_name(),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Privacy erasure for custom loyalty tables.
			$deleted = $wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
			$removed = $removed || ( is_int( $deleted ) && $deleted > 0 );
		}

		$passes = EPC_DB::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Privacy erasure for local loyalty passes.
		$deleted = $wpdb->delete(
			$passes,
			array( 'module' => 'woocommerce-loyalty', 'user_id' => $user_id ),
			array( '%s', '%d' )
		);
		$removed = $removed || ( is_int( $deleted ) && $deleted > 0 );

		$coupon_ids = get_posts(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => '_epc_loyalty_user_id',
				'meta_value'             => $user_id,
			)
		);
		foreach ( $coupon_ids as $coupon_id ) {
			$removed = (bool) wp_delete_post( (int) $coupon_id, true ) || $removed;
		}

		$removed = delete_user_meta( $user_id, 'epc_loyalty_highest_tier_threshold' ) || $removed;
		return $removed;
	}

	/**
	 * Whether the current user may manage loyalty customers.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Paginated loyalty account query with optional search/tier filters.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function query_accounts( array $args = array() ) {
		global $wpdb;

		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'updated_at' ) );
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$allowed_orderby = array(
			'updated_at'      => 'a.updated_at',
			'points_balance'  => 'a.points_balance',
			'lifetime_points' => 'a.lifetime_points',
			'member_id'       => 'a.member_id',
			'user_email'      => 'u.user_email',
			'display_name'    => 'u.display_name',
		);
		$order_sql = ( $allowed_orderby[ $orderby ] ?? 'a.updated_at' ) . ' ' . $order;

		$accounts = EPC_DB::loyalty_accounts_table_name();
		$users    = $wpdb->users;

		// Search and limit fragments are prepared; table names and ORDER BY use allowlisted values only.
		$search_sql = '';
		if ( '' !== $search ) {
			$like       = '%' . $wpdb->esc_like( $search ) . '%';
			$search_sql = $wpdb->prepare(
				' AND (a.member_id LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s OR CAST(a.user_id AS CHAR) LIKE %s)',
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}

		$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		$count_sql = "SELECT COUNT(*) FROM {$accounts} a INNER JOIN {$users} u ON u.ID = a.user_id WHERE 1=1{$search_sql}";
		$list_sql  = "SELECT a.*, u.user_email, u.display_name, u.user_login
			FROM {$accounts} a
			INNER JOIN {$users} u ON u.ID = a.user_id
			WHERE 1=1{$search_sql}
			ORDER BY {$order_sql}
			{$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/ORDER BY allowlisted; search+limit prepared above.
		$total = (int) $wpdb->get_var( $count_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/ORDER BY allowlisted; search+limit prepared above.
		$items = $wpdb->get_results( $list_sql );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Build a rich customer payload for admin list, lookup, and My Account.
	 *
	 * @param int|object $account_or_user Account row or user ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_customer_summary( $account_or_user ) {
		$account = null;
		if ( is_object( $account_or_user ) && isset( $account_or_user->user_id ) ) {
			$account = $account_or_user;
		} else {
			$account = EPC_Loyalty_Account_Service::get( absint( $account_or_user ) );
		}

		if ( ! $account ) {
			return null;
		}

		$user_id = absint( $account->user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$tier       = EPC_Loyalty_Reward_Service::get_tier_progress( (int) $account->lifetime_points );
		$milestone  = EPC_Loyalty_Reward_Service::get_next_milestone( (int) $account->lifetime_points );
		$claims     = EPC_Loyalty_Reward_Service::get_available_claims( $user_id );
		$pass       = EPC_DB::get_pass( 'woocommerce-loyalty', $user_id );
		$pass_link  = ( $pass && ! empty( $pass->pass_link ) ) ? (string) $pass->pass_link : '';
		$pass_status = ( $pass && ! empty( $pass->status ) ) ? (string) $pass->status : 'none';

		$next_reward = '';
		$progress    = '';
		if ( $milestone ) {
			$next_reward = (string) ( $milestone['name'] ?? '' );
			$threshold   = absint( $milestone['next_threshold'] ?? $milestone['threshold'] ?? 0 );
			$progress    = sprintf( '%1$d / %2$d', (int) $account->lifetime_points, $threshold );
		}

		return array(
			'user_id'           => $user_id,
			'member_id'         => (string) $account->member_id,
			'display_name'      => (string) $user->display_name,
			'email'             => (string) $user->user_email,
			'points_balance'    => (int) $account->points_balance,
			'lifetime_points'   => (int) $account->lifetime_points,
			'tier'              => $tier['current'] ? (string) $tier['current']['name'] : '',
			'tier_id'           => $tier['current'] ? (string) $tier['current']['id'] : '',
			'next_tier'         => $tier['next'] ? (string) $tier['next']['name'] : '',
			'next_tier_threshold' => $tier['next'] ? absint( $tier['next']['threshold'] ) : 0,
			'next_reward'       => $next_reward,
			'milestone_progress'=> $progress,
			'unclaimed_rewards' => count( $claims ),
			'claims'            => $claims,
			'pass_status'       => $pass_status,
			'pass_link'         => $pass_link,
			'pass_uid'          => $pass && ! empty( $pass->pass_uid ) ? (string) $pass->pass_uid : '',
			'last_activity'     => (string) ( $account->updated_at ?? '' ),
			'created_at'        => (string) ( $account->created_at ?? '' ),
		);
	}

	/**
	 * Resolve a staff lookup query (member ID, email, or user ID).
	 *
	 * @param string $query Raw lookup input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function lookup( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return new WP_Error( 'epc_loyalty_lookup_empty', __( 'Enter a membership ID, email, or customer ID.', 'epasscard' ) );
		}

		$account = null;
		$member  = EPC_Loyalty_Account_Service::normalize_member_id( $query );
		if ( '' !== $member ) {
			$account = EPC_Loyalty_Account_Service::get_by_member_id( $member );
		}

		if ( ! $account && is_email( $query ) ) {
			$user = get_user_by( 'email', sanitize_email( $query ) );
			if ( $user ) {
				$account = EPC_Loyalty_Account_Service::get( (int) $user->ID );
			}
		}

		if ( ! $account && ctype_digit( $query ) ) {
			$account = EPC_Loyalty_Account_Service::get( absint( $query ) );
		}

		if ( ! $account ) {
			return new WP_Error( 'epc_loyalty_lookup_miss', __( 'No loyalty member matched that lookup.', 'epasscard' ) );
		}

		$summary = self::get_customer_summary( $account );
		if ( ! $summary ) {
			return new WP_Error( 'epc_loyalty_lookup_miss', __( 'No loyalty member matched that lookup.', 'epasscard' ) );
		}

		/**
		 * Filter staff loyalty lookup payload.
		 *
		 * @param array<string, mixed> $summary Customer summary.
		 * @param string               $query   Original query.
		 */
		return (array) apply_filters( 'epc_loyalty_staff_lookup_result', $summary, $query );
	}

	/**
	 * Manual staff points adjustment with required reason and compensating ledger entry.
	 *
	 * @param int    $user_id Customer user ID.
	 * @param int    $points Signed spendable points delta.
	 * @param string $reason Required reason.
	 * @param bool   $affect_lifetime Whether lifetime points move with positive awards only.
	 * @return array{created: bool, entry: object, account: object}|\WP_Error
	 */
	public static function adjust_points( $user_id, $points, $reason, $affect_lifetime = false ) {
		$user_id = absint( $user_id );
		$points  = (int) $points;
		$reason  = sanitize_text_field( (string) $reason );

		if ( $user_id <= 0 || 0 === $points ) {
			return new WP_Error( 'epc_loyalty_adjust_invalid', __( 'Enter a non-zero points adjustment for a valid customer.', 'epasscard' ) );
		}
		if ( '' === $reason ) {
			return new WP_Error( 'epc_loyalty_adjust_reason', __( 'A reason is required for manual loyalty adjustments.', 'epasscard' ) );
		}

		$actor_id = get_current_user_id();
		$event    = sprintf(
			'manual_adjustment:%1$d:%2$d:%3$s',
			$user_id,
			$actor_id,
			gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false )
		);

		$lifetime = 0;
		if ( $affect_lifetime && $points > 0 ) {
			$lifetime = $points;
		}

		return EPC_Loyalty_Ledger_Service::record(
			$user_id,
			$event,
			'manual_adjustment',
			$points,
			$lifetime,
			array(
				'description' => $reason,
				'meta'        => array(
					'actor_id'        => $actor_id,
					'affect_lifetime' => (bool) $affect_lifetime,
					'reason'          => $reason,
				),
			)
		);
	}

	/**
	 * CSV column definitions for customer export.
	 *
	 * @return array<string, string>
	 */
	public static function get_export_columns() {
		$columns = array(
			'user_id'            => __( 'User ID', 'epasscard' ),
			'member_id'          => __( 'Member ID', 'epasscard' ),
			'display_name'       => __( 'Name', 'epasscard' ),
			'email'              => __( 'Email', 'epasscard' ),
			'points_balance'     => __( 'Points', 'epasscard' ),
			'lifetime_points'    => __( 'Lifetime points', 'epasscard' ),
			'tier'               => __( 'Tier', 'epasscard' ),
			'next_reward'        => __( 'Next reward', 'epasscard' ),
			'milestone_progress' => __( 'Milestone progress', 'epasscard' ),
			'unclaimed_rewards'  => __( 'Unclaimed rewards', 'epasscard' ),
			'pass_status'        => __( 'Pass status', 'epasscard' ),
			'pass_link'          => __( 'Pass link', 'epasscard' ),
			'last_activity'      => __( 'Last activity', 'epasscard' ),
		);

		/**
		 * Filter loyalty customer CSV columns (key => header label).
		 *
		 * @param array<string, string> $columns Columns.
		 */
		return (array) apply_filters( 'epc_loyalty_customer_export_columns', $columns );
	}

	/**
	 * Stream a CSV export of loyalty customers.
	 *
	 * @param string $search Optional search filter.
	 * @return void
	 */
	public static function export_csv( $search = '' ) {
		$columns = self::get_export_columns();
		$filename = 'epasscard-loyalty-customers-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV download.
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to start the loyalty customer export.', 'epasscard' ) );
		}

		fputcsv( $output, array_values( $columns ) );

		$page     = 1;
		$per_page = 200;
		$exported = 0;
		$max_rows = 10000;

		do {
			$result = self::query_accounts(
				array(
					'search'   => $search,
					'page'     => $page,
					'per_page' => $per_page,
					'orderby'  => 'updated_at',
					'order'    => 'DESC',
				)
			);

			foreach ( $result['items'] as $row ) {
				$summary = self::get_customer_summary( $row );
				if ( ! $summary ) {
					continue;
				}
				$line = array();
				foreach ( array_keys( $columns ) as $key ) {
					$line[] = isset( $summary[ $key ] ) ? (string) $summary[ $key ] : '';
				}
				fputcsv( $output, $line );
				++$exported;
				if ( $exported >= $max_rows ) {
					break 2;
				}
			}

			++$page;
			$more = ( $page - 1 ) * $per_page < (int) $result['total'];
		} while ( $more );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV download.
		exit;
	}

	/**
	 * Handle CSV export request from the loyalty admin screen.
	 *
	 * @return void
	 */
	public static function maybe_export_customers() {
		if ( empty( $_GET['epc_loyalty_export'] ) || 'customers' !== sanitize_key( wp_unslash( (string) $_GET['epc_loyalty_export'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}

		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ), 403 );
		}

		check_admin_referer( 'epc_loyalty_export_customers' );

		$search = isset( $_GET['epc_customer_s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['epc_customer_s'] ) ) : '';
		self::export_csv( $search );
	}

	/**
	 * AJAX: staff barcode / membership lookup.
	 *
	 * @return void
	 */
	public static function ajax_lookup_member() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$query  = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['query'] ) ) : '';
		$result = self::lookup( $query );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 404 );
		}

		wp_send_json_success(
			array(
				'customer' => $result,
				'message'  => __( 'Loyalty member found.', 'epasscard' ),
			)
		);
	}

	/**
	 * AJAX: paginated points history for a customer.
	 *
	 * @return void
	 */
	public static function ajax_customer_history() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$user_id  = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$page     = isset( $_POST['paged'] ) ? max( 1, absint( wp_unslash( $_POST['paged'] ) ) ) : 1;
		$per_page = 20;
		$user     = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Customer not found.', 'epasscard' ) ), 404 );
		}

		$result      = EPC_Loyalty_Ledger_Service::query_for_user(
			$user_id,
			array(
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$total_pages = max( 1, (int) ceil( (int) $result['total'] / $per_page ) );
		if ( $page > $total_pages ) {
			$page   = $total_pages;
			$result = EPC_Loyalty_Ledger_Service::query_for_user(
				$user_id,
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);
		}

		$entries = array();
		foreach ( $result['items'] as $entry ) {
			$entries[] = EPC_Loyalty_Ledger_Service::format_entry_for_admin( $entry );
		}

		wp_send_json_success(
			array(
				'customer'    => array(
					'user_id'      => $user_id,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
				),
				'entries'     => $entries,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $result['total'],
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * AJAX: manual points adjustment.
	 *
	 * @return void
	 */
	public static function ajax_adjust_points() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$user_id         = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$points          = isset( $_POST['points'] ) ? (int) wp_unslash( $_POST['points'] ) : 0;
		$reason          = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$affect_lifetime = ! empty( $_POST['affect_lifetime'] );

		$result = self::adjust_points( $user_id, $points, $reason, $affect_lifetime );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$summary = self::get_customer_summary( $result['account'] );
		wp_send_json_success(
			array(
				'message'  => __( 'Loyalty points adjusted.', 'epasscard' ),
				'customer' => $summary,
			)
		);
	}

	/**
	 * AJAX: email the loyalty pass link to a customer.
	 *
	 * @return void
	 */
	public static function ajax_send_customer_email() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid customer.', 'epasscard' ) ), 400 );
		}

		$result = EPC_Pass_Email::send_for_source(
			'woocommerce-loyalty',
			$user_id,
			array(
				'module_label' => __( 'WooCommerce Loyalty', 'epasscard' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Loyalty pass email sent.', 'epasscard' ) ) );
	}

	/**
	 * Append the customer loyalty pass to order emails when toggles allow it.
	 *
	 * @param array<int, object> $passes Pass rows.
	 * @param WC_Order           $order Order.
	 * @param WC_Email|null      $email Email instance.
	 * @return array<int, object>
	 */
	public static function filter_order_email_passes( $passes, $order, $email = null ) {
		unset( $email );

		if ( ! $order instanceof WC_Order ) {
			return $passes;
		}

		$settings = self::get_email_settings();
		if ( empty( $settings['include_pass_on_order_emails'] ) ) {
			return $passes;
		}

		$status = sanitize_key( str_replace( 'wc-', '', (string) $order->get_status() ) );
		if ( ! in_array( $status, $settings['order_email_statuses'], true ) ) {
			return $passes;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return $passes;
		}

		$relevant = self::order_is_loyalty_relevant( $order );
		if ( ! $relevant ) {
			return $passes;
		}

		$pass = EPC_DB::get_pass( 'woocommerce-loyalty', $user_id );
		if ( ! $pass || empty( $pass->pass_link ) || 'active' !== (string) $pass->status ) {
			return $passes;
		}

		$passes   = is_array( $passes ) ? $passes : array();
		$indexed  = array();
		foreach ( $passes as $row ) {
			if ( is_object( $row ) && ! empty( $row->id ) ) {
				$indexed[ (int) $row->id ] = $row;
			}
		}
		$indexed[ (int) $pass->id ] = $pass;

		return array_values( $indexed );
	}

	/**
	 * Ensure a loyalty pass exists before selected customer order emails render.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $sent_to_admin Admin email flag.
	 * @param bool     $plain_text Plain text flag.
	 * @param WC_Email $email Email object.
	 * @return void
	 */
	public static function ensure_pass_before_order_email( $order, $sent_to_admin, $plain_text, $email ) {
		unset( $plain_text, $email );

		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$settings = self::get_email_settings();
		if ( empty( $settings['ensure_pass_before_order_emails'] ) || empty( $settings['include_pass_on_order_emails'] ) ) {
			return;
		}

		$status = sanitize_key( str_replace( 'wc-', '', (string) $order->get_status() ) );
		if ( ! in_array( $status, $settings['order_email_statuses'], true ) ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 || ! self::order_is_loyalty_relevant( $order ) ) {
			return;
		}

		$pass = EPC_DB::get_pass( 'woocommerce-loyalty', $user_id );
		if ( $pass && ! empty( $pass->pass_link ) && 'active' === (string) $pass->status ) {
			return;
		}

		if ( ! function_exists( 'epc_plugin' ) ) {
			return;
		}

		$module = epc_plugin()->get_module( 'woocommerce-loyalty' );
		if ( $module && method_exists( $module, 'sync_by_source_id' ) ) {
			$module->sync_by_source_id( $user_id, 'sync' );
		}
	}

	/**
	 * Whether an order earned or redeemed loyalty points.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_is_loyalty_relevant( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$order_id = (int) $order->get_id();
		if ( EPC_Loyalty_Ledger_Service::get_by_event_key( 'order_award:' . $order_id ) ) {
			return true;
		}
		if ( EPC_Loyalty_Ledger_Service::get_by_event_key( 'redemption_reservation:' . $order_id ) ) {
			return true;
		}
		if ( EPC_Loyalty_Ledger_Service::get_by_event_key( 'redemption_commit:' . $order_id ) ) {
			return true;
		}

		$meta_points = absint( $order->get_meta( '_epc_loyalty_points_awarded', true ) );
		$meta_redeem = absint( $order->get_meta( '_epc_loyalty_points_redeemed', true ) );
		return ( $meta_points > 0 || $meta_redeem > 0 );
	}

	/**
	 * Loyalty-specific order email toggles.
	 *
	 * @return array{include_pass_on_order_emails: bool, ensure_pass_before_order_emails: bool, order_email_statuses: array<int, string>}
	 */
	public static function get_email_settings() {
		$saved = get_option( 'epc_loyalty_program', array() );
		$saved = is_array( $saved ) ? $saved : array();

		$statuses = isset( $saved['order_email_statuses'] ) && is_array( $saved['order_email_statuses'] )
			? $saved['order_email_statuses']
			: array( 'processing', 'completed' );
		$statuses = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $status ) {
							return sanitize_key( str_replace( 'wc-', '', (string) $status ) );
						},
						$statuses
					)
				)
			)
		);

		return array(
			'include_pass_on_order_emails'    => ! isset( $saved['include_pass_on_order_emails'] ) || self::is_truthy( $saved['include_pass_on_order_emails'] ),
			'ensure_pass_before_order_emails' => ! isset( $saved['ensure_pass_before_order_emails'] ) || self::is_truthy( $saved['ensure_pass_before_order_emails'] ),
			'order_email_statuses'            => empty( $statuses ) ? array( 'processing', 'completed' ) : $statuses,
		);
	}

	/**
	 * Truthy option helper.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( $value, array( 1, '1', 'true', 'yes', 'on' ), true );
	}
}
