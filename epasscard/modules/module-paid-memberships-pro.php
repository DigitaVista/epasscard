<?php
/**
 * Paid Memberships Pro integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for Paid Memberships Pro levels.
 */
class EPC_Module_Paid_Memberships_Pro extends EPC_Module {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'paid-memberships-pro';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Paid Memberships Pro', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Paid Memberships Pro', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_paid_memberships_pro_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'Paid Memberships Pro is not installed or activated. Install Paid Memberships Pro, activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name'  => __( 'Display name', 'epasscard' ),
			'user_email'         => __( 'Email', 'epasscard' ),
			'user_first_name'    => __( 'First name', 'epasscard' ),
			'user_last_name'     => __( 'Last name', 'epasscard' ),
			'user_full_name'     => __( 'Full name', 'epasscard' ),
			'membership_row_id'  => __( 'Membership record ID', 'epasscard' ),
			'level_id'           => __( 'Level ID', 'epasscard' ),
			'level_name'         => __( 'Level name', 'epasscard' ),
			'membership_status'  => __( 'Status', 'epasscard' ),
			'start_date'         => __( 'Start date', 'epasscard' ),
			'expire_date'        => __( 'Expiration date', 'epasscard' ),
			'order_id'           => __( 'Latest order ID', 'epasscard' ),
			'order_code'         => __( 'Latest order code', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_pmpro_status_rules';
	}

	/**
	 * @inheritDoc
	 */
	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		return $this->get_configurable_membership_statuses();
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_action_options() {
		return array(
			'none'   => __( 'Do nothing', 'epasscard' ),
			'sync'   => __( 'Create or update pass', 'epasscard' ),
			'update' => __( 'Update pass only', 'epasscard' ),
			'revoke' => __( 'Revoke pass', 'epasscard' ),
		);
	}

	/**
	 * Default pass behavior per membership status.
	 *
	 * @return array<string, string>
	 */
	public function get_default_status_rules() {
		return array(
			'active'    => 'sync',
			'cancelled' => 'revoke',
			'expired'   => 'revoke',
			'inactive'  => 'revoke',
		);
	}

	/**
	 * Membership statuses available for configuration.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_membership_statuses() {
		return array(
			'active'    => __( 'Active', 'epasscard' ),
			'cancelled' => __( 'Cancelled', 'epasscard' ),
			'expired'   => __( 'Expired', 'epasscard' ),
			'inactive'  => __( 'Inactive / changed', 'epasscard' ),
		);
	}

	/**
	 * Saved status rules merged with defaults.
	 *
	 * @return array<string, string>
	 */
	public function get_status_rules() {
		$saved    = get_option( $this->get_status_rules_option_key(), array() );
		$defaults = $this->get_default_status_rules();
		$merged   = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		$allowed  = array_keys( $this->get_status_action_options() );

		foreach ( $merged as $status => $action ) {
			if ( ! in_array( $action, $allowed, true ) ) {
				$merged[ $status ] = $defaults[ $status ] ?? 'none';
			}
		}

		return $merged;
	}

	/**
	 * Get configured action for a membership status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public function get_status_rule( $status ) {
		$status = sanitize_key( (string) $status );
		$rules  = $this->get_status_rules();

		return $rules[ $status ] ?? 'none';
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}

		$statuses = $this->get_configurable_membership_statuses();
		$actions  = $this->get_status_action_options();
		$rules    = $this->get_status_rules();
		?>
		<div id="epc-section-pass-behavior" class="epc-section epc-section--status-rules">
			<h2><?php esc_html_e( 'Pass behavior by membership status', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose what happens to the wallet pass when a member level reaches each status.', 'epasscard' ); ?>
			</p>
			<form class="epc-ajax-form" data-epc-action="<?php echo esc_attr( 'epc_save_status_rules_' . $this->get_slug() ); ?>" method="post" action="">
				<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
				<table class="widefat striped epc-status-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Membership status', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pass action', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $statuses as $status => $label ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $label ); ?></strong></td>
								<td>
									<select name="epc_status_rule_<?php echo esc_attr( $status ); ?>">
										<?php foreach ( $actions as $action_key => $action_label ) : ?>
											<option value="<?php echo esc_attr( $action_key ); ?>" <?php selected( $rules[ $status ] ?? 'none', $action_key ); ?>>
												<?php echo esc_html( $action_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<?php submit_button( __( 'Save pass behavior', 'epasscard' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
		$this->render_push_notification_settings();
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'before_level_expire' => __( 'Before membership level expires', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'before_level_expire' => array(
				'enabled' => false,
				'days'    => 7,
				'title'   => __( 'Membership ending soon', 'epasscard' ),
				'message' => __( 'Your {level_name} membership expires on {expire_date}. Renew to keep your pass active.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array(
			'level_name',
			'expire_date',
			'user_display_name',
			'user_email',
		);
	}

	/**
	 * Process scheduled push notifications for Paid Memberships Pro.
	 *
	 * @return void
	 */
	public function process_scheduled_notifications() {
		$rules  = $this->get_notification_rules();
		$passes = EPC_DB::get_active_passes_for_module( $this->get_slug() );

		foreach ( $passes as $pass_row ) {
			$row = $this->get_membership_row_by_id( (int) $pass_row->source_id );
			if ( ! $row || 'active' !== (string) $row->status ) {
				continue;
			}

			$expiry_ts = $this->get_enddate_timestamp( isset( $row->enddate ) ? (string) $row->enddate : '' );
			if ( $expiry_ts <= 0 ) {
				continue;
			}

			$replacements = $this->build_push_replacements_for_pass( $pass_row );

			EPC_Pass_Notifications::maybe_send_for_event(
				$pass_row,
				'before_level_expire',
				$rules['before_level_expire'] ?? array(),
				$expiry_ts,
				$replacements
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		if ( ! is_object( $pass_row ) ) {
			return array();
		}

		$row = $this->get_membership_row_by_id( (int) $pass_row->source_id );
		if ( ! $row ) {
			return array();
		}

		$expiry_ts = $this->get_enddate_timestamp( isset( $row->enddate ) ? (string) $row->enddate : '' );
		$user      = get_userdata( (int) $row->user_id );

		return array(
			'level_name'        => $this->get_entity_label( (int) $row->membership_id ),
			'expire_date'       => epc_format_pass_expiry_timestamp( $expiry_ts ),
			'user_display_name' => $user ? (string) $user->display_name : '',
			'user_email'        => $user ? (string) $user->user_email : '',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}

		$levels = pmpro_getAllLevels( true, true );
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return array();
		}

		$out = array();
		foreach ( $levels as $level ) {
			if ( ! is_object( $level ) || empty( $level->id ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $level->id,
				'label' => (string) ( $level->name ?? ( '#' . (int) $level->id ) ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Level', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create a membership level in Paid Memberships Pro (Memberships → Settings → Levels), then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=pmpro-membershiplevels' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Create level', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$entity_id = absint( $entity_id );
		if ( $entity_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'pmpro_getLevel' ) ) {
			$level = pmpro_getLevel( $entity_id );
			if ( is_object( $level ) && ! empty( $level->name ) ) {
				return (string) $level->name;
			}
		}

		return '#' . $entity_id;
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'pmpro_after_change_membership_level', array( $this, 'on_change_membership_level' ), 20, 3 );
		add_action( 'pmpro_after_checkout', array( $this, 'on_after_checkout' ), 20, 2 );
	}

	/**
	 * Handle level assign / cancel.
	 *
	 * @param int      $level_id     New level id (0 when cancelling).
	 * @param int      $user_id      User id.
	 * @param int|null $cancel_level Cancelled level id when cancelling.
	 * @return void
	 */
	public function on_change_membership_level( $level_id, $user_id, $cancel_level = null ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$user_id  = absint( $user_id );
		$level_id = absint( $level_id );

		if ( $user_id <= 0 ) {
			return;
		}

		if ( $level_id > 0 ) {
			$this->apply_status_rule_for_user_level( $user_id, $level_id, 'active' );
			return;
		}

		$cancelled_level = absint( $cancel_level );
		if ( $cancelled_level > 0 ) {
			$this->apply_status_rule_for_user_level( $user_id, $cancelled_level, 'cancelled' );
			return;
		}

		// Cancel-all path: revoke for each recent membership row still mapped.
		$rows = $this->get_recent_membership_rows_for_user( $user_id );
		foreach ( $rows as $row ) {
			$status = $this->normalize_status( isset( $row->status ) ? (string) $row->status : 'cancelled' );
			$this->apply_action_for_row( $row, $this->get_status_rule( $status ) );
		}
	}

	/**
	 * Sync after successful checkout.
	 *
	 * @param int         $user_id User id.
	 * @param object|null $morder  Member order.
	 * @return void
	 */
	public function on_after_checkout( $user_id, $morder = null ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$level_id = 0;
		if ( is_object( $morder ) && ! empty( $morder->membership_id ) ) {
			$level_id = absint( $morder->membership_id );
		}

		if ( $level_id <= 0 && function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id );
			if ( is_object( $level ) && ! empty( $level->id ) ) {
				$level_id = absint( $level->id );
			}
		}

		if ( $level_id > 0 ) {
			$this->apply_status_rule_for_user_level( $user_id, $level_id, 'active' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$row = $this->get_membership_row_by_id( absint( $source_id ) );
		if ( ! $row ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		return $this->sync_membership_row( $row, $mode );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$source_ids = array();
		global $wpdb;

		$table = EPC_DB::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id FROM %i WHERE module = %s AND user_id = %d AND pass_uid <> '' ORDER BY updated_at DESC",
				$table,
				$this->get_slug(),
				$user_id
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$source_ids[] = (int) $row->source_id;
			}
		}

		foreach ( $this->get_active_membership_rows_for_user( $user_id ) as $row ) {
			$level_id = (int) $row->membership_id;
			if ( empty( $this->get_mapping( $level_id )['template_uid'] ) ) {
				continue;
			}
			$source_ids[] = (int) $row->id;
		}

		return array_values( array_unique( array_filter( $source_ids ) ) );
	}

	/**
	 * Apply configured pass action for a user + level.
	 *
	 * @param int    $user_id  User id.
	 * @param int    $level_id Level id.
	 * @param string $hint     Preferred status hint (active|cancelled|…).
	 * @return void
	 */
	private function apply_status_rule_for_user_level( $user_id, $level_id, $hint = 'active' ) {
		$row = $this->get_membership_row_for_user_level( $user_id, $level_id, $hint );
		if ( ! $row ) {
			return;
		}

		$status = $this->normalize_status( isset( $row->status ) ? (string) $row->status : $hint );
		$this->apply_action_for_row( $row, $this->get_status_rule( $status ) );
	}

	/**
	 * Run a pass action for a memberships_users row.
	 *
	 * @param object $row    Memberships users row.
	 * @param string $action none|sync|update|revoke.
	 * @return void
	 */
	private function apply_action_for_row( $row, $action ) {
		$source_id = isset( $row->id ) ? absint( $row->id ) : 0;
		if ( $source_id <= 0 ) {
			return;
		}

		switch ( $action ) {
			case 'sync':
				$this->sync_membership_row( $row, 'sync' );
				break;
			case 'update':
				$this->sync_membership_row( $row, 'update' );
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
				break;
			case 'none':
			default:
				break;
		}
	}

	/**
	 * Sync pass from a pmpro_memberships_users row.
	 *
	 * @param object $row  Row.
	 * @param string $mode sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_membership_row( $row, $mode = 'sync' ) {
		$user_id  = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
		$level_id = isset( $row->membership_id ) ? absint( $row->membership_id ) : 0;
		$row_id   = isset( $row->id ) ? absint( $row->id ) : 0;

		if ( $user_id <= 0 || $level_id <= 0 || $row_id <= 0 ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $level_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this membership level.', 'epasscard' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'epc_no_user', __( 'Member user not found.', 'epasscard' ) );
		}

		$order = $this->get_latest_order( $user_id, $level_id );
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );

		$values = array(
			'user_display_name' => $user->display_name,
			'user_email'        => $user->user_email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $user->display_name ),
			'membership_row_id' => (string) $row_id,
			'level_id'          => (string) $level_id,
			'level_name'        => $this->get_entity_label( $level_id ),
			'membership_status' => $this->normalize_status( isset( $row->status ) ? (string) $row->status : '' ),
			'start_date'        => $this->format_db_date( isset( $row->startdate ) ? (string) $row->startdate : '' ),
			'expire_date'       => epc_format_pass_expiry_datetime( isset( $row->enddate ) ? (string) $row->enddate : '' ),
			'order_id'          => $order ? (string) $order['id'] : '',
			'order_code'        => $order ? (string) $order['code'] : '',
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			$row_id,
			$level_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}

	/**
	 * Normalize PMPro row status for behavior rules.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = sanitize_key( (string) $status );

		switch ( $status ) {
			case 'active':
				return 'active';
			case 'expired':
				return 'expired';
			case 'cancelled':
			case 'admin_cancelled':
				return 'cancelled';
			case 'inactive':
			case 'changed':
			case 'admin_changed':
			default:
				return 'inactive';
		}
	}

	/**
	 * Memberships users table name.
	 *
	 * @return string
	 */
	private function get_memberships_users_table() {
		global $wpdb;

		if ( ! empty( $wpdb->pmpro_memberships_users ) ) {
			return (string) $wpdb->pmpro_memberships_users;
		}

		return $wpdb->prefix . 'pmpro_memberships_users';
	}

	/**
	 * Orders table name.
	 *
	 * @return string
	 */
	private function get_orders_table() {
		global $wpdb;

		if ( ! empty( $wpdb->pmpro_membership_orders ) ) {
			return (string) $wpdb->pmpro_membership_orders;
		}

		return $wpdb->prefix . 'pmpro_membership_orders';
	}

	/**
	 * Get memberships_users row by primary id.
	 *
	 * @param int $row_id Row id.
	 * @return object|null
	 */
	private function get_membership_row_by_id( $row_id ) {
		global $wpdb;

		$row_id = absint( $row_id );
		if ( $row_id <= 0 ) {
			return null;
		}

		$table = $this->get_memberships_users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted helper.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$row_id
			)
		);

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Find the best memberships_users row for a user + level.
	 *
	 * @param int    $user_id  User id.
	 * @param int    $level_id Level id.
	 * @param string $hint     Prefer active when hint is active.
	 * @return object|null
	 */
	private function get_membership_row_for_user_level( $user_id, $level_id, $hint = 'active' ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$level_id = absint( $level_id );

		if ( $user_id <= 0 || $level_id <= 0 ) {
			return null;
		}

		$table = $this->get_memberships_users_table();

		if ( 'active' === $hint ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND membership_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
					$user_id,
					$level_id
				)
			);
			if ( $row instanceof stdClass ) {
				return $row;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND membership_id = %d ORDER BY id DESC LIMIT 1",
				$user_id,
				$level_id
			)
		);

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Active membership rows for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, object>
	 */
	private function get_active_membership_rows_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = $this->get_memberships_users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = 'active' ORDER BY id DESC",
				$user_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recent membership rows for cancel-all handling.
	 *
	 * @param int $user_id User id.
	 * @return array<int, object>
	 */
	private function get_recent_membership_rows_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = $this->get_memberships_users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 20",
				$user_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest successful order for user + level.
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 * @return array{id:int,code:string}|null
	 */
	private function get_latest_order( $user_id, $level_id ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$level_id = absint( $level_id );

		if ( $user_id <= 0 ) {
			return null;
		}

		$table = $this->get_orders_table();

		if ( $level_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, code FROM {$table} WHERE user_id = %d AND membership_id = %d AND status NOT IN ('error','pending','token','review') ORDER BY id DESC LIMIT 1",
					$user_id,
					$level_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, code FROM {$table} WHERE user_id = %d AND status NOT IN ('error','pending','token','review') ORDER BY id DESC LIMIT 1",
					$user_id
				)
			);
		}

		if ( ! $order instanceof stdClass ) {
			return null;
		}

		return array(
			'id'   => (int) $order->id,
			'code' => (string) $order->code,
		);
	}

	/**
	 * Format a MySQL datetime for pass fields.
	 *
	 * @param string $datetime Datetime string.
	 * @return string
	 */
	private function format_db_date( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $ts );
	}

	/**
	 * Convert enddate to timestamp.
	 *
	 * @param string $enddate End date.
	 * @return int
	 */
	private function get_enddate_timestamp( $enddate ) {
		$enddate = trim( (string) $enddate );
		if ( '' === $enddate || '0000-00-00 00:00:00' === $enddate ) {
			return 0;
		}

		$ts = strtotime( $enddate );
		return $ts ? (int) $ts : 0;
	}
}
