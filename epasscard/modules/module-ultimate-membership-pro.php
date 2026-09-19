<?php
/**
 * Ultimate Membership Pro integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for Ultimate Membership Pro levels.
 */
class EPC_Module_Ultimate_Membership_Pro extends EPC_Module {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'ultimate-membership-pro';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Ultimate Membership Pro', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Ultimate Membership Pro', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_ultimate_membership_pro_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'Ultimate Membership Pro is not installed or activated. Install the plugin, activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name'   => __( 'Display name', 'epasscard' ),
			'user_email'          => __( 'Email', 'epasscard' ),
			'user_first_name'     => __( 'First name', 'epasscard' ),
			'user_last_name'      => __( 'Last name', 'epasscard' ),
			'user_full_name'      => __( 'Full name', 'epasscard' ),
			'membership_id'       => __( 'User level record ID', 'epasscard' ),
			'level_id'            => __( 'Level ID', 'epasscard' ),
			'level_name'          => __( 'Level name', 'epasscard' ),
			'membership_status'   => __( 'Status', 'epasscard' ),
			'start_date'          => __( 'Start date', 'epasscard' ),
			'expire_date'         => __( 'Expiration date', 'epasscard' ),
			'transaction_id'      => __( 'Latest transaction ID', 'epasscard' ),
		);
	}

	/**
	 * Option key for per-status pass behavior rules.
	 *
	 * @return string
	 */
	public function get_status_rules_option_key() {
		return 'epc_ump_status_rules';
	}

	/**
	 * Pass actions available per membership status.
	 *
	 * @return array<string, string>
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
			'pending'   => 'none',
			'active'    => 'sync',
			'cancelled' => 'revoke',
			'expired'   => 'revoke',
		);
	}

	/**
	 * Membership statuses available for configuration.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_membership_statuses() {
		return array(
			'pending'   => __( 'Pending payment', 'epasscard' ),
			'active'    => __( 'Active', 'epasscard' ),
			'cancelled' => __( 'Cancelled', 'epasscard' ),
			'expired'   => __( 'Expired', 'epasscard' ),
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
	 * Process scheduled push notifications for Ultimate Membership Pro.
	 *
	 * @return void
	 */
	public function process_scheduled_notifications() {
		$rules  = $this->get_notification_rules();
		$passes = EPC_DB::get_active_passes_for_module( $this->get_slug() );

		foreach ( $passes as $pass_row ) {
			$row = $this->get_user_level_row_by_id( (int) $pass_row->source_id );
			if ( ! $row ) {
				continue;
			}

			$status = $this->get_user_level_status( (int) $row->user_id, (int) $row->level_id, $row );
			if ( 'active' !== $status ) {
				continue;
			}

			$expiry_ts = $this->get_ump_expiry_timestamp( isset( $row->expire_time ) ? (string) $row->expire_time : '' );
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

		$row = $this->get_user_level_row_by_id( (int) $pass_row->source_id );
		if ( ! $row ) {
			return array();
		}

		$expiry_ts = $this->get_ump_expiry_timestamp( isset( $row->expire_time ) ? (string) $row->expire_time : '' );
		$user      = get_userdata( (int) $row->user_id );

		return array(
			'level_name'        => $this->get_entity_label( (int) $row->level_id ),
			'expire_date'       => epc_format_pass_expiry_timestamp( $expiry_ts ),
			'user_display_name' => $user ? (string) $user->display_name : '',
			'user_email'        => $user ? (string) $user->user_email : '',
		);
	}

	/**
	 * Convert UMP expiry datetime to timestamp.
	 *
	 * @param string $expire_time Raw expiry value.
	 * @return int
	 */
	private function get_ump_expiry_timestamp( $expire_time ) {
		$expire_time = trim( (string) $expire_time );
		if ( '' === $expire_time || '0000-00-00 00:00:00' === $expire_time ) {
			return 0;
		}

		$ts = strtotime( $expire_time );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		$levels = get_option( 'ihc_levels', array() );
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return array();
		}

		$out = array();
		foreach ( $levels as $level_id => $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $level_id,
				'label' => $this->format_level_label( (int) $level_id, $level ),
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
		return __( 'Membership level', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create a membership level in Ultimate Membership Pro, then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=ihc_manage&tab=levels' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Create membership level', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$entity_id = absint( $entity_id );
		if ( function_exists( 'ihc_get_level_by_id' ) ) {
			$level = ihc_get_level_by_id( $entity_id );
			if ( is_array( $level ) ) {
				return $this->format_level_label( $entity_id, $level );
			}
		}

		return '#' . $entity_id;
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'ihc_payment_completed', array( $this, 'on_membership_hook' ), 10, 4 );
		add_action( 'ihc_action_after_subscription_activated', array( $this, 'on_membership_hook' ), 10, 2 );
		add_action( 'ihc_action_subscription_expired', array( $this, 'on_membership_hook' ), 10, 2 );
		add_action( 'ihc_action_after_user_approve', array( $this, 'on_user_approved' ), 10, 1 );
		add_action( 'ihc_action_after_user_level_assign', array( $this, 'on_membership_hook' ), 10, 2 );
		add_action( 'ihc_action_after_user_level_delete', array( $this, 'on_level_deleted' ), 10, 2 );

		add_action( 'ump_action_admin_list_user_column_name_after_total_spend', array( $this, 'ump_users_list_column' ) );
		add_action( 'ump_action_admin_list_user_row_after_total_spend', array( $this, 'ump_users_list_cell' ) );
	}

	/**
	 * Add EpassCard column header on UMP members list.
	 *
	 * @return void
	 */
	public function ump_users_list_column() {
		if ( ! $this->current_user_can_manage_passes() ) {
			return;
		}

		echo '<th class="manage-column">' . esc_html__( 'EpassCard', 'epasscard' ) . '</th>';
	}

	/**
	 * Render EpassCard column cell on UMP members list.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function ump_users_list_cell( $user_id ) {
		echo '<td>' . wp_kses( (string) $this->render_pass_actions_for_user( (int) $user_id ), self::get_pass_action_allowed_html() ) . '</td>';
	}

	/**
	 * Whether a user level can show create/update pass actions.
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 * @return bool
	 */
	private function is_user_level_eligible_for_pass_action( $user_id, $level_id ) {
		if ( class_exists( '\Indeed\Ihc\UserSubscriptions' ) && method_exists( '\Indeed\Ihc\UserSubscriptions', 'isActive' ) ) {
			return (bool) \Indeed\Ihc\UserSubscriptions::isActive( $user_id, $level_id );
		}

		return 'active' === $this->get_user_level_status( $user_id, $level_id );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup; not cacheable per request.
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

		$levels_table = $wpdb->prefix . 'ihc_user_levels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table; existence check + read.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $levels_table ) ) === $levels_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table read.
			$level_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, level_id FROM %i WHERE user_id = %d ORDER BY id DESC',
					$levels_table,
					$user_id
				)
			);

			if ( is_array( $level_rows ) ) {
				foreach ( $level_rows as $level_row ) {
					$level_id = isset( $level_row->level_id ) ? (int) $level_row->level_id : 0;
					$row_id   = isset( $level_row->id ) ? (int) $level_row->id : 0;

					if ( $level_id <= 0 || $row_id <= 0 ) {
						continue;
					}

					if ( empty( $this->get_mapping( $level_id )['template_uid'] ) ) {
						continue;
					}

					if ( ! $this->is_user_level_eligible_for_pass_action( $user_id, $level_id ) ) {
						continue;
					}

					$source_ids[] = $row_id;
				}
			}
		} else {
			foreach ( $this->get_user_level_ids( $user_id ) as $level_id ) {
				if ( empty( $this->get_mapping( $level_id )['template_uid'] ) ) {
					continue;
				}

				if ( ! $this->is_user_level_eligible_for_pass_action( $user_id, $level_id ) ) {
					continue;
				}

				$row_id = $this->get_user_level_row_id( $user_id, $level_id );
				if ( $row_id > 0 ) {
					$source_ids[] = $row_id;
				}
			}
		}

		return array_values( array_unique( array_filter( $source_ids ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		if ( parent::should_enqueue_pass_action_assets( $hook ) ) {
			return true;
		}

		if ( 'toplevel_page_ihc_manage' !== $hook ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin screen detection only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		if ( 'users' !== $tab ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin screen detection only.
		if ( isset( $_GET['ihc-new-user'] ) || isset( $_GET['ihc-edit-user'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle UMP hooks that pass user and level ids.
	 *
	 * @return void
	 */
	public function on_membership_hook() {
		$parsed = $this->parse_user_level_from_hook_args( func_get_args() );
		if ( null === $parsed ) {
			return;
		}

		$this->handle_membership_event( $parsed['user_id'], $parsed['level_id'] );
	}

	/**
	 * Sync passes when admin approves a pending member.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_user_approved( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		foreach ( $this->get_user_level_ids( $user_id ) as $level_id ) {
			$this->handle_membership_event( $user_id, $level_id );
		}
	}

	/**
	 * Revoke pass when a level is removed from a user.
	 *
	 * @param int $user_id User id.
	 * @param int $level_id Level id.
	 * @return void
	 */
	public function on_level_deleted( $user_id, $level_id ) {
		$user_id  = absint( $user_id );
		$level_id = absint( $level_id );

		if ( $user_id <= 0 || $level_id <= 0 ) {
			return;
		}

		$row_id = $this->get_user_level_row_id( $user_id, $level_id, false );
		if ( $row_id > 0 ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $row_id );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$row = $this->get_user_level_row_by_id( absint( $source_id ) );
		if ( ! $row ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		return $this->sync_user_level_row( $row, $mode );
	}

	/**
	 * Apply configured pass action for a user level.
	 *
	 * @param int $user_id User id.
	 * @param int $level_id Level id.
	 * @return void
	 */
	private function handle_membership_event( $user_id, $level_id ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$user_id  = absint( $user_id );
		$level_id = absint( $level_id );

		if ( $user_id <= 0 || $level_id <= 0 ) {
			return;
		}

		$status = $this->get_user_level_status( $user_id, $level_id );
		$this->apply_status_rule( $user_id, $level_id, $status );
	}

	/**
	 * Run configured pass action for a membership status.
	 *
	 * @param int    $user_id User id.
	 * @param int    $level_id Level id.
	 * @param string $status Status slug.
	 * @return void
	 */
	private function apply_status_rule( $user_id, $level_id, $status ) {
		$action = $this->get_status_rule( $status );

		switch ( $action ) {
			case 'sync':
				$this->sync_user_level( $user_id, $level_id, 'sync' );
				break;
			case 'update':
				$this->sync_user_level( $user_id, $level_id, 'update' );
				break;
			case 'revoke':
				$row_id = $this->get_user_level_row_id( $user_id, $level_id );
				if ( $row_id > 0 ) {
					EPC_Pass_Service::revoke_pass( $this->get_slug(), $row_id );
				}
				break;
			case 'none':
			default:
				break;
		}
	}

	/**
	 * Sync pass for a user level assignment.
	 *
	 * @param int    $user_id User id.
	 * @param int    $level_id Level id.
	 * @param string $mode sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_user_level( $user_id, $level_id, $mode = 'sync' ) {
		$row = $this->get_user_level_row( $user_id, $level_id );
		if ( ! $row ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		return $this->sync_user_level_row( $row, $mode );
	}

	/**
	 * Sync pass from a user level row object.
	 *
	 * @param object $row User level row.
	 * @param string $mode sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_user_level_row( $row, $mode = 'sync' ) {
		$user_id  = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
		$level_id = isset( $row->level_id ) ? absint( $row->level_id ) : 0;
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

		$level_name = $this->get_entity_label( $level_id );
		$status     = $this->get_user_level_status( $user_id, $level_id, $row );
		$first      = (string) get_user_meta( $user_id, 'first_name', true );
		$last       = (string) get_user_meta( $user_id, 'last_name', true );

		$values = array(
			'user_display_name' => $user->display_name,
			'user_email'        => $user->user_email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $user->display_name ),
			'membership_id'     => (string) $row_id,
			'level_id'          => (string) $level_id,
			'level_name'        => $level_name,
			'membership_status' => $status,
			'start_date'        => $this->format_db_date( isset( $row->start_time ) ? (string) $row->start_time : '' ),
			'expire_date'       => epc_format_pass_expiry_datetime( isset( $row->expire_time ) ? (string) $row->expire_time : '' ),
			'transaction_id'    => $this->get_latest_transaction_id( $user_id, $level_id ),
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
	 * Parse user and level ids from assorted UMP hook signatures.
	 *
	 * @param array<int, mixed> $args Hook arguments.
	 * @return array{user_id: int, level_id: int}|null
	 */
	private function parse_user_level_from_hook_args( array $args ) {
		if ( count( $args ) >= 2 && is_numeric( $args[0] ) && is_numeric( $args[1] ) ) {
			return array(
				'user_id'  => absint( $args[0] ),
				'level_id' => absint( $args[1] ),
			);
		}

		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$data = $args[0];
			$uid  = $data['uid'] ?? $data['user_id'] ?? $data['u_id'] ?? 0;
			$lid  = $data['lid'] ?? $data['level_id'] ?? $data['l_id'] ?? 0;

			if ( is_numeric( $uid ) && is_numeric( $lid ) ) {
				return array(
					'user_id'  => absint( $uid ),
					'level_id' => absint( $lid ),
				);
			}
		}

		return null;
	}

	/**
	 * Get assigned level ids for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, int>
	 */
	private function get_user_level_ids( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = $wpdb->prefix . 'ihc_user_levels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table; existence check + read.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table read.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT level_id FROM %i WHERE user_id = %d ORDER BY level_id ASC',
					$table,
					$user_id
				)
			);

			return array_values( array_filter( array_map( 'absint', (array) $rows ) ) );
		}

		$levels = get_user_meta( $user_id, 'ihc_user_levels', true );
		if ( ! is_string( $levels ) || '' === $levels ) {
			return array();
		}

		$out = array();
		foreach ( explode( ',', $levels ) as $level_token ) {
			$level_token = trim( (string) $level_token );
			if ( '' === $level_token ) {
				continue;
			}

			$level_id = absint( strstr( $level_token, '|', true ) ?: $level_token );
			if ( $level_id > 0 ) {
				$out[] = $level_id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Determine membership status slug for a user level.
	 *
	 * @param int         $user_id User id.
	 * @param int         $level_id Level id.
	 * @param object|null $row Optional user level row.
	 * @return string
	 */
	private function get_user_level_status( $user_id, $level_id, $row = null ) {
		$row = $row ? $row : $this->get_user_level_row( $user_id, $level_id );
		if ( ! $row ) {
			return 'expired';
		}

		if ( function_exists( 'ihc_is_user_level_expired' ) && ihc_is_user_level_expired( $user_id, $level_id ) ) {
			return 'expired';
		}

		$expire_time = isset( $row->expire_time ) ? (string) $row->expire_time : '';
		if ( '' === $expire_time || '0000-00-00 00:00:00' === $expire_time ) {
			return 'pending';
		}

		if ( isset( $row->status ) && (string) $row->status === '0' ) {
			return 'cancelled';
		}

		return 'active';
	}

	/**
	 * Get user level row by user and level ids.
	 *
	 * @param int $user_id User id.
	 * @param int $level_id Level id.
	 * @return object|null
	 */
	private function get_user_level_row( $user_id, $level_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ihc_user_levels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table; existence check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND level_id = %d ORDER BY id DESC LIMIT 1',
				$table,
				absint( $user_id ),
				absint( $level_id )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get user level row by primary key.
	 *
	 * @param int $row_id Row id.
	 * @return object|null
	 */
	private function get_user_level_row_by_id( $row_id ) {
		global $wpdb;

		$row_id = absint( $row_id );
		if ( $row_id <= 0 ) {
			return null;
		}

		$table = $wpdb->prefix . 'ihc_user_levels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table; existence check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$table,
				$row_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get user level row id.
	 *
	 * @param int  $user_id User id.
	 * @param int  $level_id Level id.
	 * @param bool $allow_missing Whether to return 0 when missing.
	 * @return int
	 */
	private function get_user_level_row_id( $user_id, $level_id, $allow_missing = true ) {
		$row = $this->get_user_level_row( $user_id, $level_id );
		if ( ! $row || empty( $row->id ) ) {
			return $allow_missing ? 0 : 0;
		}

		return (int) $row->id;
	}

	/**
	 * Get latest payment transaction id for a user level.
	 *
	 * @param int $user_id User id.
	 * @param int $level_id Level id.
	 * @return string
	 */
	private function get_latest_transaction_id( $user_id, $level_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'indeed_members_payments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table; existence check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Third-party plugin table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT txn_id, payment_data FROM %i WHERE u_id = %d ORDER BY paydate DESC LIMIT 20',
				$table,
				absint( $user_id )
			)
		);

		if ( empty( $rows ) ) {
			return '';
		}

		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row->payment_data, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$row_level_id = 0;
			if ( isset( $data['level'] ) ) {
				$row_level_id = absint( $data['level'] );
			} elseif ( isset( $data['custom'] ) ) {
				$custom = json_decode( (string) $data['custom'], true );
				if ( is_array( $custom ) && isset( $custom['level_id'] ) ) {
					$row_level_id = absint( $custom['level_id'] );
				}
			}

			if ( $row_level_id === absint( $level_id ) && ! empty( $row->txn_id ) ) {
				return (string) $row->txn_id;
			}
		}

		return '';
	}

	/**
	 * Format a level label from UMP level data.
	 *
	 * @param int                  $level_id Level id.
	 * @param array<string, mixed> $level Level data.
	 * @return string
	 */
	private function format_level_label( $level_id, array $level ) {
		if ( ! empty( $level['label'] ) ) {
			return (string) $level['label'];
		}

		if ( ! empty( $level['name'] ) ) {
			return (string) $level['name'];
		}

		return sprintf(
			/* translators: %d: level id */
			__( 'Level #%d', 'epasscard' ),
			absint( $level_id )
		);
	}

	/**
	 * Format a database datetime for pass mapping.
	 *
	 * @param string $value Raw datetime.
	 * @return string
	 */
	private function format_db_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}

		return mysql2date( get_option( 'date_format' ), $value );
	}
}
