<?php
/**
 * WPLoyalty integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A loyalty card for each WPLoyalty customer, showing their points.
 *
 * - Record: the customer's row in WPLoyalty's customer table (`wlr_users`), so the card stays the
 *   same when the customer changes their email. One item: the loyalty program.
 * - The card is made when the customer has points (earned now, or "Give cards to existing
 *   customers"), and updated whenever WPLoyalty changes the balance: an order, a redeemed reward,
 *   an expiry, a return, or an admin change.
 * - Banning the customer in WPLoyalty voids the card; lifting the ban brings it back. Deleting the
 *   customer in WPLoyalty voids it.
 * - Customers without a WordPress account (guest orders) get the card at their WPLoyalty email.
 */
class EPC_Module_WPLoyalty extends EPC_Module {

	/**
	 * The one item: the loyalty program.
	 */
	const PROGRAM_ENTITY_ID = 1;

	/**
	 * Queued sync action (Action Scheduler).
	 */
	const SYNC_HOOK = 'epc_wployalty_sync_card';

	/**
	 * Action Scheduler group.
	 */
	const SYNC_GROUP = 'epasscard-wployalty';

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'wployalty';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WPLoyalty', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'WPLoyalty', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => 'woocommerce',
				'label'       => __( 'WooCommerce', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'woocommerce',
				'plugin_file' => 'woocommerce/woocommerce.php',
				'url'         => '',
			),
			array(
				'key'         => 'wployalty',
				'label'       => __( 'WPLoyalty', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'wployalty',
				'plugin_file' => 'wployalty/wp-loyalty-rules-lite.php',
				'url'         => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_wployalty_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'WPLoyalty is not installed or activated. Activate WPLoyalty (it needs WooCommerce), then return here to set up loyalty cards.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A loyalty card for each WPLoyalty customer. It shows their points and updates whenever WPLoyalty changes the balance.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_full_name'  => __( 'Customer name', 'epasscard' ),
			'user_email'      => __( 'Customer email', 'epasscard' ),
			'member_id'       => __( 'Customer ID (WPLoyalty)', 'epasscard' ),
			'points_balance'  => __( 'Points balance', 'epasscard' ),
			'points_label'    => __( 'Points label (e.g. points)', 'epasscard' ),
			'lifetime_points' => __( 'Lifetime earned points', 'epasscard' ),
			'used_points'     => __( 'Points used', 'epasscard' ),
			'level_name'      => __( 'Level (WPLoyalty Pro)', 'epasscard' ),
			'referral_code'   => __( 'Referral code (referral campaigns)', 'epasscard' ),
			'referral_url'    => __( 'Referral link (referral campaigns)', 'epasscard' ),
			'member_since'    => __( 'Member since', 'epasscard' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Items: the program
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		return array(
			array(
				'id'    => self::PROGRAM_ENTITY_ID,
				'label' => __( 'Loyalty program', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		unset( $entity_id );
		return __( 'Loyalty program', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Program', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return self::admin_url();
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'WPLoyalty', 'epasscard' );
	}

	/**
	 * WPLoyalty's admin page (its slug differs between the free plugin and Pro).
	 *
	 * @return string
	 */
	public static function admin_url() {
		return admin_url( 'admin.php?page=' . ( defined( 'WLR_PLUGIN_SLUG' ) ? (string) WLR_PLUGIN_SLUG : 'wp-loyalty-rules-lite' ) );
	}

	/* -------------------------------------------------------------------------
	 * WPLoyalty customers
	 * ---------------------------------------------------------------------- */

	/**
	 * WPLoyalty's customer table.
	 *
	 * @return string
	 */
	public static function customers_table() {
		global $wpdb;
		return $wpdb->prefix . 'wlr_users';
	}

	/**
	 * A WPLoyalty customer row by id.
	 *
	 * @param int|string $row_id Row id.
	 * @return object|null
	 */
	public function get_customer( $row_id ) {
		global $wpdb;
		$row_id = absint( $row_id );
		if ( $row_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPLoyalty's own table; read fresh for the card.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::customers_table(), $row_id ) );
		return $row ? $row : null;
	}

	/**
	 * Row id of the WPLoyalty customer with this email, or 0.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	public function customer_id_by_email( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPLoyalty's own table.
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_email = %s', self::customers_table(), $email ) ) );
	}

	/**
	 * Whether a customer has points activity (a balance, or points earned before).
	 *
	 * @param object $row Customer row.
	 * @return bool
	 */
	private function has_points( $row ) {
		return (int) $row->points > 0 || (int) $row->earn_total_point > 0;
	}

	/**
	 * Whether WPLoyalty has banned the customer.
	 *
	 * @param object $row Customer row.
	 * @return bool
	 */
	private function is_banned( $row ) {
		return (int) $row->is_banned_user > 0;
	}

	/**
	 * The WordPress account of a customer (by email), or 0 for a guest.
	 *
	 * @param object $row Customer row.
	 * @return int
	 */
	private function account_id( $row ) {
		$user = get_user_by( 'email', (string) $row->user_email );
		return $user ? (int) $user->ID : 0;
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		// WPLoyalty's one hook for every balance change (earn, redeem, expiry, return, admin, import).
		add_action( 'wlr_customer_points_balance_changed', array( $this, 'on_balance_changed' ), 20, 1 );
		add_action( 'wlr_after_user_level_changed', array( $this, 'on_level_changed' ), 20, 2 );
		add_action( 'wlr_my_account_email_change', array( $this, 'on_email_changed' ), 20, 2 );
		// Ban and unban: WPLoyalty fires no hook of its own, so watch its save in that request.
		add_action( 'wp_ajax_wlr_admin_toggle_banned_user', array( $this, 'watch_ban_toggle' ), 1 );
		add_filter( 'wlr_delete_customer', array( $this, 'on_customers_deleted' ), 20, 1 );
		add_action( self::SYNC_HOOK, array( $this, 'run_queued_sync' ) );
		add_action( 'wlr_after_customer_reward_page_content', array( $this, 'render_reward_page_card' ) );
	}

	/**
	 * Points balance changed: queue the card update (or the first card).
	 *
	 * @param string $user_email Customer email.
	 * @return void
	 */
	public function on_balance_changed( $user_email ) {
		$this->queue_sync( $this->customer_id_by_email( (string) $user_email ) );
	}

	/**
	 * Level changed (WPLoyalty Pro): refresh the card.
	 *
	 * @param int                  $old_level_id Previous level.
	 * @param array<string, mixed> $user_fields  Saved customer fields.
	 * @return void
	 */
	public function on_level_changed( $old_level_id, $user_fields ) {
		unset( $old_level_id );
		$email = is_array( $user_fields ) && isset( $user_fields['user_email'] ) ? (string) $user_fields['user_email'] : '';
		$this->queue_sync( $this->customer_id_by_email( $email ) );
	}

	/**
	 * The customer changed their email on My Account: the same card, with the new email.
	 *
	 * @param string $new_email New email.
	 * @param string $old_email Old email.
	 * @return void
	 */
	public function on_email_changed( $new_email, $old_email ) {
		unset( $old_email );
		$this->queue_sync( $this->customer_id_by_email( (string) $new_email ) );
	}

	/**
	 * In WPLoyalty's ban/unban request, act once the customer row is saved.
	 *
	 * @return void
	 */
	public function watch_ban_toggle() {
		add_filter( 'wlr_after_user_updated', array( $this, 'on_ban_saved' ), 20, 2 );
	}

	/**
	 * Ban saved: void the card. Ban lifted: bring a voided card back.
	 *
	 * @param bool                 $status      Whether WPLoyalty saved the row.
	 * @param array<string, mixed> $user_fields Saved customer fields.
	 * @return bool Unchanged.
	 */
	public function on_ban_saved( $status, $user_fields ) {
		if ( ! $status || ! is_array( $user_fields ) || ! EPC_Api_Client::is_configured() ) {
			return $status;
		}
		$row_id = $this->customer_id_by_email( (string) ( $user_fields['user_email'] ?? '' ) );
		$pass   = $row_id > 0 ? EPC_DB::get_pass( $this->get_slug(), (string) $row_id ) : null;
		if ( ! $pass || empty( $pass->pass_uid ) ) {
			return $status;
		}
		if ( ! empty( $user_fields['is_banned_user'] ) ) {
			if ( 'active' === (string) $pass->status ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $row_id );
			}
		} elseif ( 'revoked' === (string) $pass->status ) {
			$this->sync_by_source_id( (string) $row_id, 'sync' );
		}
		return $status;
	}

	/**
	 * Customers deleted in WPLoyalty: void the cards whose customer row is gone.
	 *
	 * @param mixed $status WPLoyalty's delete result (passed through).
	 * @return mixed
	 */
	public function on_customers_deleted( $status ) {
		global $wpdb;
		if ( ! EPC_Api_Client::is_configured() ) {
			return $status;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cards whose WPLoyalty customer no longer exists.
		$orphans = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.source_id FROM %i p LEFT JOIN %i u ON u.id = p.source_id WHERE p.module = %s AND p.status = 'active' AND p.pass_uid <> '' AND u.id IS NULL",
				EPC_DB::table_name(),
				self::customers_table(),
				$this->get_slug()
			)
		);
		foreach ( (array) $orphans as $source_id ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $source_id );
		}
		return $status;
	}

	/**
	 * Queue one card sync for a customer. One order can change the balance several times (one
	 * per campaign); the queued sync runs after the request and reads the final balance once.
	 *
	 * @param int $row_id Customer row id.
	 * @return void
	 */
	public function queue_sync( $row_id ) {
		$row_id = absint( $row_id );
		if ( $row_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$args = array( $row_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::SYNC_HOOK, $args, self::SYNC_GROUP ) ) {
				as_enqueue_async_action( self::SYNC_HOOK, $args, self::SYNC_GROUP );
			}
			return;
		}
		$this->run_queued_sync( $row_id );
	}

	/**
	 * Apply the customer's current state to their card.
	 *
	 * @param int $row_id Customer row id.
	 * @return void
	 */
	public function run_queued_sync( $row_id ) {
		$row_id = absint( $row_id );
		$row    = $this->get_customer( $row_id );
		if ( ! $row || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$pass     = EPC_DB::get_pass( $this->get_slug(), (string) $row_id );
		$has_card = $pass && ! empty( $pass->pass_uid );
		if ( $this->is_banned( $row ) ) {
			if ( $has_card && 'active' === (string) $pass->status ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $row_id );
			}
			return;
		}
		if ( $has_card && 'active' !== (string) $pass->status ) {
			return; // A voided card stays voided (voided on the Cards page, or the customer was banned).
		}
		if ( ! $has_card && ! $this->has_points( $row ) ) {
			return;
		}
		// A failure is recorded by the pass service and shown on Cards.
		$this->sync_by_source_id( (string) $row_id, 'sync' );
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$row = $this->get_customer( $source_id );
		if ( ! $row ) {
			return new WP_Error( 'epc_invalid_source', __( 'WPLoyalty customer not found.', 'epasscard' ) );
		}
		if ( $this->is_banned( $row ) ) {
			return new WP_Error( 'epc_invalid_source', __( 'This customer is banned in WPLoyalty, so they get no loyalty card.', 'epasscard' ) );
		}
		$mapping = $this->get_mapping( self::PROGRAM_ENTITY_ID );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for the loyalty program yet.', 'epasscard' ) );
		}

		return EPC_Pass_Service::sync_pass( $this->get_slug(), (string) $row->id, self::PROGRAM_ENTITY_ID, $this->account_id( $row ), $mapping, $this->build_values( $row ), $mode );
	}

	/**
	 * Field values of a loyalty card.
	 *
	 * @param object $row Customer row.
	 * @return array<string, string>
	 */
	public function build_values( $row ) {
		global $wpdb;
		$user_id = $this->account_id( $row );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$first   = $user ? (string) get_user_meta( $user_id, 'first_name', true ) : '';
		$last    = $user ? (string) get_user_meta( $user_id, 'last_name', true ) : '';
		$name    = $user ? epc_format_user_full_name( $first, $last, (string) $user->display_name ) : '';
		$level   = '';
		if ( (int) $row->level_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPLoyalty's own levels table.
			$level = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . 'wlr_levels', (int) $row->level_id ) );
		}
		$code     = (string) $row->refer_code;
		$referral = '' !== $code ? site_url() . '?wlr_ref=' . $code : '';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPLoyalty's own filter, applied as WPLoyalty does.
		$referral = '' !== $code ? (string) apply_filters( 'wlr_get_referral_url', $referral, $code ) : '';
		$since    = (int) $row->created_date;

		return array(
			'user_full_name'  => $name,
			'user_email'      => (string) $row->user_email,
			'member_id'       => (string) $row->id,
			'points_balance'  => (string) (int) $row->points,
			'points_label'    => $this->points_label( (int) $row->points ),
			'lifetime_points' => (string) (int) $row->earn_total_point,
			'used_points'     => (string) (int) $row->used_total_points,
			'level_name'      => $level,
			'referral_code'   => $code,
			'referral_url'    => $referral,
			'member_since'    => $since > 0 ? wp_date( (string) get_option( 'date_format' ), $since ) : '',
		);
	}

	/**
	 * WPLoyalty's name for points (Settings → point label), singular or plural for the amount.
	 *
	 * @param int $points Points.
	 * @return string
	 */
	private function points_label( $points ) {
		$singular = $this->wlr_setting( 'wlr_point_singular_label', __( 'point', 'epasscard' ) );
		$plural   = $this->wlr_setting( 'wlr_point_label', __( 'points', 'epasscard' ) );
		return 1 === $points ? $singular : $plural;
	}

	/**
	 * A WPLoyalty setting (its `wlr_settings` option), or the default when it is empty.
	 *
	 * @param string $key     Setting key.
	 * @param string $default Default.
	 * @return string
	 */
	private function wlr_setting( $key, $default ) {
		$settings = get_option( 'wlr_settings', array() );
		return is_array( $settings ) && ! empty( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : (string) $default;
	}

	/* -------------------------------------------------------------------------
	 * Customers find it
	 * ---------------------------------------------------------------------- */

	/**
	 * "Wallet card" block at the end of WPLoyalty's rewards page (My Account and the reward page).
	 *
	 * @return void
	 */
	public function render_reward_page_card() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return;
		}
		$row_id = $this->customer_id_by_email( (string) $user->user_email );
		$card   = $row_id > 0 ? EPC_DB::find_active_card( $this->get_slug(), 0, null, (string) $row_id ) : null;
		if ( ! $card || empty( $card->pass_link ) ) {
			return;
		}
		echo '<div class="epc-wlr-card"><div class="wlr-heading-container"><h3 class="wlr-heading">' . esc_html__( 'Wallet card', 'epasscard' ) . '</h3></div>';
		echo '<p class="wlr-text-color">' . esc_html__( 'Your loyalty card for Apple Wallet and Google Wallet. It shows your points and updates by itself.', 'epasscard' ) . '</p>';
		// WPLoyalty's own button look, in the colour chosen in WPLoyalty (Settings → theme colour).
		$color = sanitize_hex_color( $this->wlr_setting( 'theme_color', '#4F47EB' ) );
		$color = $color ? $color : '#4F47EB';
		echo '<p style="margin-top:12px;"><a class="wlr-button wlr-button-text-color epc-wlr-card__button" style="display:inline-block;text-decoration:none;background:' . esc_attr( $color ) . ';" href="' . esc_url( (string) $card->pass_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Add to wallet', 'epasscard' ) . '</a></p></div>';
	}

	/**
	 * A guest's card goes to their WPLoyalty email.
	 *
	 * @inheritDoc
	 */
	public function get_guest_recipient( $source_id ) {
		$row = $this->get_customer( $source_id );
		if ( ! $row || $this->account_id( $row ) > 0 || ! is_email( (string) $row->user_email ) ) {
			return null;
		}
		return array(
			'email' => (string) $row->user_email,
			'name'  => '',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_record_customer( $source_id ) {
		$row = $this->get_customer( $source_id );
		return $row ? array(
			'name'  => '',
			'email' => (string) $row->user_email,
		) : null;
	}

	/**
	 * @inheritDoc
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		$user   = get_userdata( absint( $user_id ) );
		$row_id = $user ? $this->customer_id_by_email( (string) $user->user_email ) : 0;
		return $row_id > 0 ? array( $row_id ) : array();
	}

	/* -------------------------------------------------------------------------
	 * Existing customers
	 * ---------------------------------------------------------------------- */

	/**
	 * Customers with points (a balance or points earned), not banned, oldest first.
	 *
	 * @inheritDoc
	 */
	public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
		global $wpdb;
		if ( self::PROGRAM_ENTITY_ID !== absint( $entity_id ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WPLoyalty's own table, for giving cards to existing customers.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE is_banned_user = 0 AND ( points > 0 OR earn_total_point > 0 ) ORDER BY id ASC LIMIT %d OFFSET %d',
				self::customers_table(),
				absint( $limit ),
				absint( $offset )
			)
		);
		return array_map( 'strval', (array) $ids );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_scope_label() {
		return __( 'WPLoyalty customers with points', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_user_id( $source_id ) {
		$row = $this->get_customer( $source_id );
		return $row ? $this->account_id( $row ) : 0;
	}
}
