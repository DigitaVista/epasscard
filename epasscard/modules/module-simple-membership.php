<?php
/**
 * Simple Membership integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for Simple Membership members.
 */
class EPC_Module_Simple_Membership extends EPC_Module {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'simple-membership';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Simple Membership', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Simple Membership', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_simple_membership_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'Simple Membership is not installed or activated. Install Simple Membership, activate it, then return here to map pass templates.',
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
			'member_id'          => __( 'Member ID', 'epasscard' ),
			'user_name'          => __( 'Username', 'epasscard' ),
			'level_id'           => __( 'Level ID', 'epasscard' ),
			'level_name'         => __( 'Level name', 'epasscard' ),
			'membership_status'  => __( 'Account state', 'epasscard' ),
			'start_date'         => __( 'Subscription start date', 'epasscard' ),
			'expire_date'        => __( 'Expiration date', 'epasscard' ),
			'subscr_id'          => __( 'Subscriber / subscription ID', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_swpm_status_rules';
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
	 * Default pass behavior per account state.
	 *
	 * @return array<string, string>
	 */
	public function get_default_status_rules() {
		return array(
			'active'              => 'sync',
			'inactive'            => 'revoke',
			'pending'             => 'none',
			'activation_required' => 'none',
			'expired'             => 'revoke',
		);
	}

	/**
	 * Account states available for configuration.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_membership_statuses() {
		if ( class_exists( 'SwpmUtils' ) && method_exists( 'SwpmUtils', 'get_account_state_options' ) ) {
			$options = SwpmUtils::get_account_state_options();
			if ( is_array( $options ) && ! empty( $options ) ) {
				return $options;
			}
		}

		return array(
			'active'              => __( 'Active', 'epasscard' ),
			'inactive'            => __( 'Inactive', 'epasscard' ),
			'pending'             => __( 'Pending', 'epasscard' ),
			'activation_required' => __( 'Activation Required', 'epasscard' ),
			'expired'             => __( 'Expired', 'epasscard' ),
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
	 * Get configured action for an account state.
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
			<h2><?php esc_html_e( 'Pass behavior by account state', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose what happens to the wallet pass when a member account reaches each state.', 'epasscard' ); ?>
			</p>
			<form class="epc-ajax-form" data-epc-action="<?php echo esc_attr( 'epc_save_status_rules_' . $this->get_slug() ); ?>" method="post" action="">
				<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
				<table class="widefat striped epc-status-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Account state', 'epasscard' ); ?></th>
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
			'before_level_expire' => __( 'Before membership expires', 'epasscard' ),
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
			'user_full_name',
			'user_email',
		);
	}

	/**
	 * Process scheduled push notifications for Simple Membership.
	 *
	 * @return void
	 */
	public function process_scheduled_notifications() {
		$rules  = $this->get_notification_rules();
		$passes = EPC_DB::get_active_passes_for_module( $this->get_slug() );

		foreach ( $passes as $pass_row ) {
			$member = $this->get_member_by_id( (int) $pass_row->source_id );
			if ( ! $member || 'active' !== $this->normalize_status( isset( $member->account_state ) ? (string) $member->account_state : '' ) ) {
				continue;
			}

			$expiry_ts = $this->get_member_expiry_timestamp( $member );
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

		$member = $this->get_member_by_id( (int) $pass_row->source_id );
		if ( ! $member ) {
			return array();
		}

		$first = isset( $member->first_name ) ? (string) $member->first_name : '';
		$last  = isset( $member->last_name ) ? (string) $member->last_name : '';
		$email = isset( $member->email ) ? (string) $member->email : '';

		return array(
			'level_name'        => $this->get_entity_label( isset( $member->membership_level ) ? (int) $member->membership_level : 0 ),
			'expire_date'       => $this->format_member_expire_date( $member ),
			'user_display_name' => epc_format_user_full_name( $first, $last, $email ),
			'user_full_name'    => epc_format_user_full_name( $first, $last, $email ),
			'user_email'        => $email,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! class_exists( 'SwpmMembershipLevelUtils' ) || ! method_exists( 'SwpmMembershipLevelUtils', 'get_all_membership_levels_in_array' ) ) {
			return array();
		}

		$levels = SwpmMembershipLevelUtils::get_all_membership_levels_in_array();
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return array();
		}

		$out = array();
		foreach ( $levels as $id => $label ) {
			$out[] = array(
				'id'    => (int) $id,
				'label' => (string) $label,
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
			'Create a membership level in Simple Membership (Membership Levels → Add New), then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=simple_wp_membership_levels&level_action=add' );
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

		if ( class_exists( 'SwpmMembershipLevelUtils' ) && method_exists( 'SwpmMembershipLevelUtils', 'get_membership_level_name_by_level_id' ) ) {
			$name = SwpmMembershipLevelUtils::get_membership_level_name_by_level_id( $entity_id );
			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}

		if ( class_exists( 'SwpmUtils' ) && method_exists( 'SwpmUtils', 'get_membership_level_row_by_id' ) ) {
			$row = SwpmUtils::get_membership_level_row_by_id( $entity_id );
			if ( is_object( $row ) && ! empty( $row->alias ) ) {
				return (string) $row->alias;
			}
		}

		return '#' . $entity_id;
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'swpm_front_end_registration_complete_user_data', array( $this, 'on_member_data' ), 20, 1 );
		add_action( 'swpm_admin_end_registration_complete_user_data', array( $this, 'on_member_data' ), 20, 1 );
		add_action( 'swpm_admin_end_edit_complete_user_data', array( $this, 'on_member_data' ), 20, 1 );
		add_action( 'swpm_front_end_profile_edited', array( $this, 'on_member_data' ), 20, 1 );

		add_action( 'swpm_membership_level_changed', array( $this, 'on_level_changed' ), 20, 1 );
		add_action( 'swpm_account_status_updated', array( $this, 'on_status_updated' ), 20, 1 );
		add_action( 'swpm_account_status_refreshed', array( $this, 'on_status_refreshed' ), 20, 1 );

		add_action( 'swpm_ipn_account_upgrade_event', array( $this, 'on_member_id_event' ), 20, 1 );
		add_action( 'swpm_ipn_account_renewal_event', array( $this, 'on_member_id_event' ), 20, 1 );
		add_action( 'swpm_subscription_payment_cancelled', array( $this, 'on_subscription_cancelled' ), 20, 1 );
		add_action( 'swpm_cronjob_account_status_updated_to_expired', array( $this, 'on_members_expired' ), 20, 1 );
		add_action( 'swpm_admin_end_user_delete_action', array( $this, 'on_member_deleted' ), 20, 1 );
		add_action( 'swpm_before_user_delete_action', array( $this, 'on_member_deleted' ), 20, 1 );
	}

	/**
	 * Handle registration / edit payloads (array or object).
	 *
	 * @param mixed $member_data Member info.
	 * @return void
	 */
	public function on_member_data( $member_data ) {
		$member_id = $this->extract_member_id( $member_data );
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
		}
	}

	/**
	 * Handle membership level changed hook.
	 *
	 * @param array<string, mixed> $args Hook args.
	 * @return void
	 */
	public function on_level_changed( $args ) {
		$member_id = 0;
		if ( is_array( $args ) && isset( $args['member_id'] ) ) {
			$member_id = absint( $args['member_id'] );
		}
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
		}
	}

	/**
	 * Handle account status updated hook.
	 *
	 * @param array<string, mixed> $args Hook args.
	 * @return void
	 */
	public function on_status_updated( $args ) {
		$member_id = 0;
		if ( is_array( $args ) && isset( $args['member_id'] ) ) {
			$member_id = absint( $args['member_id'] );
		}
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
		}
	}

	/**
	 * Handle account status refreshed hook (IPN upgrade path).
	 *
	 * @param array<string, mixed> $args Hook args.
	 * @return void
	 */
	public function on_status_refreshed( $args ) {
		$member_id = 0;
		if ( is_array( $args ) && isset( $args['member_id'] ) ) {
			$member_id = absint( $args['member_id'] );
		}
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
		}
	}

	/**
	 * Handle upgrade / renewal events that pass member id first.
	 *
	 * @param mixed $member_id Member id.
	 * @return void
	 */
	public function on_member_id_event( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
		}
	}

	/**
	 * Handle subscription cancellation IPN.
	 *
	 * @param mixed $ipn_data IPN payload.
	 * @return void
	 */
	public function on_subscription_cancelled( $ipn_data ) {
		$member_id = $this->extract_member_id_from_ipn( $ipn_data );
		if ( $member_id > 0 ) {
			$this->sync_member_by_status_rule( $member_id );
			return;
		}

		// Fallback: look up by subscriber id.
		$subscr_id = '';
		if ( is_array( $ipn_data ) ) {
			$subscr_id = (string) ( $ipn_data['subscr_id'] ?? $ipn_data['subscriber_id'] ?? '' );
		}
		if ( '' !== $subscr_id && class_exists( 'SwpmMemberUtils' ) && method_exists( 'SwpmMemberUtils', 'get_user_by_subsriber_id' ) ) {
			$member = SwpmMemberUtils::get_user_by_subsriber_id( $subscr_id );
			if ( is_object( $member ) && ! empty( $member->member_id ) ) {
				$this->sync_member_by_status_rule( (int) $member->member_id );
			}
		}
	}

	/**
	 * Handle cron expiry batch.
	 *
	 * @param mixed $member_ids Member ids.
	 * @return void
	 */
	public function on_members_expired( $member_ids ) {
		if ( ! is_array( $member_ids ) ) {
			return;
		}

		foreach ( $member_ids as $member_id ) {
			$member_id = absint( $member_id );
			if ( $member_id > 0 ) {
				$this->apply_action_for_member( $member_id, $this->get_status_rule( 'expired' ) );
			}
		}
	}

	/**
	 * Revoke pass when member is deleted.
	 *
	 * @param mixed $member_id Member id.
	 * @return void
	 */
	public function on_member_deleted( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id > 0 ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $member_id );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$member = $this->get_member_by_id( absint( $source_id ) );
		if ( ! $member ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		return $this->sync_member_row( $member, $mode );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$user = get_userdata( $user_id );
		if ( $user && class_exists( 'SwpmMemberUtils' ) ) {
			$member = null;
			if ( method_exists( 'SwpmMemberUtils', 'get_user_by_user_name' ) ) {
				$member = SwpmMemberUtils::get_user_by_user_name( $user->user_login );
			}
			if ( ! $member && method_exists( 'SwpmMemberUtils', 'get_user_by_email' ) ) {
				$member = SwpmMemberUtils::get_user_by_email( $user->user_email );
			}
			if ( is_object( $member ) && ! empty( $member->member_id ) ) {
				$level_id = isset( $member->membership_level ) ? (int) $member->membership_level : 0;
				if ( $level_id > 0 && ! empty( $this->get_mapping( $level_id )['template_uid'] ) ) {
					$source_ids[] = (int) $member->member_id;
				}
			}
		}

		return array_values( array_unique( array_filter( $source_ids ) ) );
	}

	/**
	 * Apply configured status rule for a member.
	 *
	 * @param int $member_id Member id.
	 * @return void
	 */
	private function sync_member_by_status_rule( $member_id ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$member = $this->get_member_by_id( $member_id );
		if ( ! $member ) {
			return;
		}

		$status = $this->normalize_status( isset( $member->account_state ) ? (string) $member->account_state : '' );
		$this->apply_action_for_member( $member_id, $this->get_status_rule( $status ), $member );
	}

	/**
	 * Run a pass action for a member.
	 *
	 * @param int         $member_id Member id.
	 * @param string      $action    none|sync|update|revoke.
	 * @param object|null $member    Optional preloaded member row.
	 * @return void
	 */
	private function apply_action_for_member( $member_id, $action, $member = null ) {
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return;
		}

		if ( ! $member ) {
			$member = $this->get_member_by_id( $member_id );
		}

		switch ( $action ) {
			case 'sync':
				if ( $member ) {
					$this->sync_member_row( $member, 'sync' );
				}
				break;
			case 'update':
				if ( $member ) {
					$this->sync_member_row( $member, 'update' );
				}
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $member_id );
				break;
			case 'none':
			default:
				break;
		}
	}

	/**
	 * Sync pass from a swpm_members_tbl row.
	 *
	 * @param object $member Member row.
	 * @param string $mode   sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_member_row( $member, $mode = 'sync' ) {
		$member_id = isset( $member->member_id ) ? absint( $member->member_id ) : 0;
		$level_id  = isset( $member->membership_level ) ? absint( $member->membership_level ) : 0;

		if ( $member_id <= 0 || $level_id <= 0 ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $level_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this membership level.', 'epasscard' ) );
		}

		$user_id = $this->resolve_wp_user_id( $member );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		$first = isset( $member->first_name ) ? (string) $member->first_name : '';
		$last  = isset( $member->last_name ) ? (string) $member->last_name : '';
		$email = isset( $member->email ) ? (string) $member->email : ( $user ? (string) $user->user_email : '' );

		if ( '' === $first && $user ) {
			$first = (string) get_user_meta( $user_id, 'first_name', true );
		}
		if ( '' === $last && $user ) {
			$last = (string) get_user_meta( $user_id, 'last_name', true );
		}

		$display = $user ? (string) $user->display_name : epc_format_user_full_name( $first, $last, $email );

		$values = array(
			'user_display_name' => $display,
			'user_email'        => $email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $display ),
			'member_id'         => (string) $member_id,
			'user_name'         => isset( $member->user_name ) ? (string) $member->user_name : '',
			'level_id'          => (string) $level_id,
			'level_name'        => $this->get_entity_label( $level_id ),
			'membership_status' => $this->normalize_status( isset( $member->account_state ) ? (string) $member->account_state : '' ),
			'start_date'        => $this->format_db_date( isset( $member->subscription_starts ) ? (string) $member->subscription_starts : '' ),
			'expire_date'       => $this->format_member_expire_date( $member ),
			'subscr_id'         => isset( $member->subscr_id ) ? (string) $member->subscr_id : '',
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			$member_id,
			$level_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}

	/**
	 * Resolve WordPress user id for an SWPM member.
	 *
	 * @param object $member Member row.
	 * @return int
	 */
	private function resolve_wp_user_id( $member ) {
		if ( class_exists( 'SwpmMemberUtils' ) && method_exists( 'SwpmMemberUtils', 'get_wp_user_from_swpm_user_id' ) && ! empty( $member->member_id ) ) {
			$wp_user = SwpmMemberUtils::get_wp_user_from_swpm_user_id( (int) $member->member_id );
			if ( $wp_user instanceof WP_User ) {
				return (int) $wp_user->ID;
			}
		}

		if ( ! empty( $member->user_name ) ) {
			$wp_user = get_user_by( 'login', (string) $member->user_name );
			if ( $wp_user instanceof WP_User ) {
				return (int) $wp_user->ID;
			}
		}

		if ( ! empty( $member->email ) ) {
			$wp_user = get_user_by( 'email', (string) $member->email );
			if ( $wp_user instanceof WP_User ) {
				return (int) $wp_user->ID;
			}
		}

		return 0;
	}

	/**
	 * Get member row by id.
	 *
	 * @param int $member_id Member id.
	 * @return object|null
	 */
	private function get_member_by_id( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return null;
		}

		if ( class_exists( 'SwpmMemberUtils' ) && method_exists( 'SwpmMemberUtils', 'get_user_by_id' ) ) {
			$member = SwpmMemberUtils::get_user_by_id( $member_id );
			return is_object( $member ) ? $member : null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'swpm_members_tbl';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE member_id = %d LIMIT 1",
				$member_id
			)
		);

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Extract member id from assorted payloads.
	 *
	 * @param mixed $data Member data.
	 * @return int
	 */
	private function extract_member_id( $data ) {
		if ( is_numeric( $data ) ) {
			return absint( $data );
		}

		if ( is_object( $data ) && isset( $data->member_id ) ) {
			return absint( $data->member_id );
		}

		if ( is_array( $data ) && isset( $data['member_id'] ) ) {
			return absint( $data['member_id'] );
		}

		return 0;
	}

	/**
	 * Extract member id from IPN payload.
	 *
	 * @param mixed $ipn_data IPN data.
	 * @return int
	 */
	private function extract_member_id_from_ipn( $ipn_data ) {
		if ( ! is_array( $ipn_data ) ) {
			return 0;
		}

		foreach ( array( 'member_id', 'swpm_id', 'custom' ) as $key ) {
			if ( empty( $ipn_data[ $key ] ) ) {
				continue;
			}
			if ( 'custom' === $key && is_string( $ipn_data[ $key ] ) ) {
				parse_str( $ipn_data[ $key ], $custom );
				if ( ! empty( $custom['swpm_id'] ) ) {
					return absint( $custom['swpm_id'] );
				}
				if ( ! empty( $custom['member_id'] ) ) {
					return absint( $custom['member_id'] );
				}
				continue;
			}
			if ( is_numeric( $ipn_data[ $key ] ) ) {
				return absint( $ipn_data[ $key ] );
			}
		}

		return 0;
	}

	/**
	 * Normalize account state.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = sanitize_key( (string) $status );
		$known  = array_keys( $this->get_configurable_membership_statuses() );

		if ( in_array( $status, $known, true ) ) {
			return $status;
		}

		return 'inactive';
	}

	/**
	 * Formatted membership expiry for pass / push fields.
	 *
	 * @param object $member Member row.
	 * @return string
	 */
	private function format_member_expire_date( $member ) {
		if ( ! is_object( $member ) ) {
			return epc_format_pass_expiry_timestamp( 0 );
		}

		$ts = $this->get_member_expiry_timestamp( $member );

		if ( $this->member_has_no_expiry( $member, $ts ) ) {
			return epc_format_pass_expiry_timestamp( 0 );
		}

		if ( $ts > 0 ) {
			return epc_format_pass_expiry_timestamp( $ts );
		}

		if ( class_exists( 'SwpmMemberUtils' ) && method_exists( 'SwpmMemberUtils', 'get_formatted_expiry_date_by_user_id' ) && ! empty( $member->member_id ) ) {
			$formatted = SwpmMemberUtils::get_formatted_expiry_date_by_user_id( (int) $member->member_id );
			if ( is_string( $formatted ) ) {
				$formatted = trim( $formatted );
				// SWPM labels for lifetime — convert to +99 years.
				if ( '' !== $formatted && ( false !== stripos( $formatted, 'no expir' ) || 0 === strcasecmp( $formatted, 'never' ) ) ) {
					return epc_format_pass_expiry_timestamp( 0 );
				}
				if ( '' !== $formatted && ! preg_match( '/\b1970\b/', $formatted ) ) {
					$parsed = strtotime( $formatted );
					if ( $parsed ) {
						return epc_format_pass_expiry_timestamp( (int) $parsed );
					}
					return $formatted;
				}
			}
		}

		return epc_format_pass_expiry_timestamp( 0 );
	}

	/**
	 * Whether the member level is lifetime / no expiry.
	 *
	 * @param object $member Member row.
	 * @param int    $ts     Computed expiry timestamp (0 unknown).
	 * @return bool
	 */
	private function member_has_no_expiry( $member, $ts = 0 ) {
		if ( $ts >= PHP_INT_MAX ) {
			return true;
		}

		if ( ! class_exists( 'SwpmPermission' ) || ! class_exists( 'SwpmMembershipLevel' ) || empty( $member->membership_level ) ) {
			return false;
		}

		if ( ! class_exists( 'SwpmUtils' ) || ! method_exists( 'SwpmUtils', 'calculate_subscription_period_days' ) ) {
			return false;
		}

		$permission = SwpmPermission::get_instance( $member->membership_level );
		if ( ! is_object( $permission ) ) {
			return false;
		}

		$days = SwpmUtils::calculate_subscription_period_days(
			$permission->get( 'subscription_period' ),
			$permission->get( 'subscription_duration_type' )
		);

		return 'noexpire' === $days;
	}

	/**
	 * Member expiry timestamp (0 = unknown / lifetime handled separately).
	 *
	 * @param object $member Member row.
	 * @return int
	 */
	private function get_member_expiry_timestamp( $member ) {
		if ( ! is_object( $member ) ) {
			return 0;
		}

		$raw = null;

		if ( class_exists( 'SwpmUtils' ) && method_exists( 'SwpmUtils', 'get_expiration_timestamp' ) ) {
			$raw = SwpmUtils::get_expiration_timestamp( $member );
		}

		if ( ! is_numeric( $raw ) || (int) $raw <= 0 ) {
			$raw = $this->calculate_expiry_from_level( $member );
		}

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		$ts = (int) $raw;
		if ( $ts <= 0 || $ts >= PHP_INT_MAX ) {
			return 0;
		}

		return $ts;
	}

	/**
	 * Fallback expiry calculation from level duration + subscription_starts.
	 *
	 * @param object $member Member row.
	 * @return int
	 */
	private function calculate_expiry_from_level( $member ) {
		if ( empty( $member->membership_level ) || empty( $member->subscription_starts ) ) {
			return 0;
		}

		if ( ! class_exists( 'SwpmPermission' ) || ! class_exists( 'SwpmMembershipLevel' ) || ! class_exists( 'SwpmUtils' ) ) {
			return 0;
		}

		if ( ! method_exists( 'SwpmUtils', 'calculate_subscription_period_days' ) ) {
			return 0;
		}

		$level_id = $member->membership_level;
		if ( class_exists( 'SwpmMembershipLevelUtils' ) && method_exists( 'SwpmMembershipLevelUtils', 'check_if_membership_level_exists' ) ) {
			if ( ! SwpmMembershipLevelUtils::check_if_membership_level_exists( $level_id ) ) {
				return 0;
			}
		}

		$permission = SwpmPermission::get_instance( $level_id );
		if ( ! is_object( $permission ) ) {
			return 0;
		}

		$duration_type = $permission->get( 'subscription_duration_type' );
		$period        = $permission->get( 'subscription_period' );

		if ( class_exists( 'SwpmMembershipLevel' ) && SwpmMembershipLevel::FIXED_DATE == $duration_type ) {
			$ts = strtotime( (string) $period );
			return $ts ? (int) $ts : 0;
		}

		$days = SwpmUtils::calculate_subscription_period_days( $period, $duration_type );
		if ( 'noexpire' === $days || '' === $days || null === $days ) {
			return PHP_INT_MAX;
		}

		$starts = trim( (string) $member->subscription_starts );
		$ts     = strtotime( $starts . ' +' . (int) $days . ' days' );
		if ( ! $ts ) {
			// Match SWPM's concatenation style as a second attempt.
			$ts = strtotime( $starts . ' ' . (int) $days . ' days' );
		}

		return $ts ? (int) $ts : 0;
	}

	/**
	 * Format a date string for pass fields.
	 *
	 * @param string $datetime Date value.
	 * @return string
	 */
	private function format_db_date( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime || '0000-00-00' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $ts );
	}
}
