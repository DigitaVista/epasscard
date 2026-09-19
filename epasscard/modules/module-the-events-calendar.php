<?php
/**
 * The Events Calendar integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for The Events Calendar events (attendees via Event Tickets).
 */
class EPC_Module_The_Events_Calendar extends EPC_Module {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'the-events-calendar';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'The Events Calendar', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'The Events Calendar (+ Event Tickets)', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_the_events_calendar_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'The Events Calendar is not installed or activated. Install The Events Calendar, activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name' => __( 'Display name', 'epasscard' ),
			'user_email'        => __( 'Email', 'epasscard' ),
			'user_first_name'   => __( 'First name', 'epasscard' ),
			'user_last_name'    => __( 'Last name', 'epasscard' ),
			'user_full_name'    => __( 'Full name (attendee)', 'epasscard' ),
			'attendee_id'       => __( 'Attendee ID', 'epasscard' ),
			'ticket_id'         => __( 'Ticket ID', 'epasscard' ),
			'ticket_name'       => __( 'Ticket name', 'epasscard' ),
			'event_id'          => __( 'Event ID', 'epasscard' ),
			'event_title'       => __( 'Event title', 'epasscard' ),
			'event_start'       => __( 'Event start', 'epasscard' ),
			'event_end'         => __( 'Event end', 'epasscard' ),
			'expire_date'       => __( 'Pass expiry (event end)', 'epasscard' ),
			'venue_name'        => __( 'Venue name', 'epasscard' ),
			'venue_address'     => __( 'Venue address', 'epasscard' ),
			'organizer_name'    => __( 'Organizer name', 'epasscard' ),
			'event_cost'        => __( 'Event cost', 'epasscard' ),
			'event_url'         => __( 'Event URL', 'epasscard' ),
			'event_website'     => __( 'Event website', 'epasscard' ),
			'order_id'          => __( 'Order ID', 'epasscard' ),
			'security_code'     => __( 'Security / QR code', 'epasscard' ),
			'attendee_status'   => __( 'Attendee status', 'epasscard' ),
			'check_in'          => __( 'Checked in', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_the_events_calendar_status_rules';
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
		return $this->get_configurable_attendee_statuses();
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
	 * Default pass behavior.
	 *
	 * @return array<string, string>
	 */
	public function get_default_status_rules() {
		return array(
			'yes'     => 'sync',
			'no'      => 'revoke',
			'active'  => 'sync',
			'deleted' => 'revoke',
		);
	}

	/**
	 * Attendee statuses for behavior UI.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_attendee_statuses() {
		return array(
			'yes'     => __( 'RSVP going / yes', 'epasscard' ),
			'no'      => __( 'RSVP not going / no', 'epasscard' ),
			'active'  => __( 'Paid / active ticket', 'epasscard' ),
			'deleted' => __( 'Deleted / refunded', 'epasscard' ),
		);
	}

	/**
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
	 * @param string $status Status.
	 * @return string
	 */
	public function get_status_rule( $status ) {
		$status = sanitize_key( (string) $status );
		$rules  = $this->get_status_rules();

		return $rules[ $status ] ?? 'sync';
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}

		if ( ! epc_is_event_tickets_active() ) {
			?>
			<div class="notice notice-info inline">
				<p>
					<?php
					esc_html_e(
						'The Events Calendar is active. Install and activate the Event Tickets add-on (RSVP / Tickets Commerce) so EpassCard can issue wallet passes when attendees register. Map events below once tickets are available.',
						'epasscard'
					);
					?>
				</p>
			</div>
			<?php
		}

		$statuses = $this->get_configurable_attendee_statuses();
		$actions  = $this->get_status_action_options();
		$rules    = $this->get_status_rules();
		?>
		<div id="epc-section-pass-behavior" class="epc-section epc-section--status-rules">
			<h2><?php esc_html_e( 'Pass behavior by attendee status', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose what happens when an Event Tickets attendee is created or their RSVP/ticket status changes for a mapped event.', 'epasscard' ); ?>
			</p>
			<form class="epc-ajax-form" data-epc-action="<?php echo esc_attr( 'epc_save_status_rules_' . $this->get_slug() ); ?>" method="post" action="">
				<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
				<table class="widefat striped epc-status-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Attendee status', 'epasscard' ); ?></th>
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
				<p><?php submit_button( __( 'Save pass behavior', 'epasscard' ), 'secondary', 'submit', false ); ?></p>
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
			'before_event_start' => __( 'Before event starts', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'before_event_start' => array(
				'enabled' => false,
				'days'    => 1,
				'title'   => __( 'Event coming up', 'epasscard' ),
				'message' => __( '{event_title} starts on {event_start}. Your ticket is ready on your phone.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array(
			'event_title',
			'event_start',
			'venue_name',
			'ticket_name',
			'user_full_name',
			'user_email',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function process_scheduled_notifications() {
		$rules  = $this->get_notification_rules();
		$passes = EPC_DB::get_active_passes_for_module( $this->get_slug() );

		foreach ( $passes as $pass_row ) {
			$data = $this->get_attendee_data( (int) $pass_row->source_id );
			if ( ! $data ) {
				continue;
			}

			$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;
			$start_ts = $this->get_event_start_timestamp( $event_id );
			if ( $start_ts <= 0 ) {
				continue;
			}

			EPC_Pass_Notifications::maybe_send_for_event(
				$pass_row,
				'before_event_start',
				$rules['before_event_start'] ?? array(),
				$start_ts,
				$this->build_push_replacements_for_pass( $pass_row )
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

		$data = $this->get_attendee_data( (int) $pass_row->source_id );
		if ( ! $data ) {
			return array();
		}

		$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;
		$name     = isset( $data['holder_name'] ) ? (string) $data['holder_name'] : '';

		return array(
			'event_title'    => $event_id ? get_the_title( $event_id ) : '',
			'event_start'    => $this->format_event_datetime( $event_id, 'start' ),
			'venue_name'     => $this->get_venue_name( $event_id ),
			'ticket_name'    => isset( $data['ticket'] ) ? (string) $data['ticket'] : ( isset( $data['ticket_name'] ) ? (string) $data['ticket_name'] : '' ),
			'user_full_name' => $name,
			'user_email'     => isset( $data['holder_email'] ) ? (string) $data['holder_email'] : '',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		$event_ids = get_posts(
			array(
				'post_type'      => $this->get_event_post_type(),
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! is_array( $event_ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $event_ids as $event_id ) {
			$event_id = absint( $event_id );
			if ( $event_id <= 0 ) {
				continue;
			}

			$label = get_the_title( $event_id );
			$start = $this->format_event_datetime( $event_id, 'start' );
			if ( $start ) {
				$label .= ' (' . $start . ')';
			}

			$out[] = array(
				'id'    => $event_id,
				'label' => $label ? $label : ( '#' . $event_id ),
			);
		}

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Event', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create an event in The Events Calendar, then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'edit.php?post_type=' . $this->get_event_post_type() );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Open events', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$entity_id = absint( $entity_id );
		$title     = $entity_id ? get_the_title( $entity_id ) : '';

		return $title ? $title : ( '#' . $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		if ( ! epc_is_event_tickets_active() ) {
			return;
		}

		add_action( 'event_tickets_rsvp_attendee_created', array( $this, 'on_rsvp_attendee_created' ), 20, 4 );
		add_action( 'event_tickets_tpp_attendee_created', array( $this, 'on_tpp_attendee_created' ), 20, 5 );
		add_action( 'tec_tickets_commerce_attendee_after_create', array( $this, 'on_commerce_attendee_created' ), 20, 4 );
		add_action( 'tec_tickets_commerce_flag_action_generated_attendee', array( $this, 'on_commerce_flag_attendee' ), 20, 1 );
		add_action( 'event_ticket_woo_attendee_created', array( $this, 'on_woo_attendee_created' ), 20, 4 );
		add_action( 'event_tickets_attendee_update', array( $this, 'on_attendee_update' ), 20, 3 );
		add_action( 'event_tickets_attendee_ticket_deleted', array( $this, 'on_attendee_deleted' ), 20, 2 );
		add_action( 'tec_tickets_commerce_attendee_after_delete', array( $this, 'on_commerce_attendee_deleted' ), 20, 1 );

		add_filter( 'tribe_tickets_attendee_table_columns', array( $this, 'attendees_table_columns' ), 20, 2 );
		add_filter( 'tribe_events_tickets_attendees_table_column', array( $this, 'attendees_table_column' ), 20, 3 );
		add_filter( 'event_tickets_attendees_table_row_actions', array( $this, 'attendees_table_row_actions' ), 20, 2 );
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		if ( parent::should_enqueue_pass_action_assets( $hook ) ) {
			return true;
		}

		$hook = (string) $hook;
		if (
			false !== strpos( $hook, 'tec-tickets-attendees' )
			|| false !== strpos( $hook, 'tickets-attendees' )
		) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return in_array( $page, array( 'tec-tickets-attendees', 'tickets-attendees' ), true );
	}

	/**
	 * Add EpassCard column to Event Tickets attendees list.
	 *
	 * @param array<string, string> $columns  Columns.
	 * @param int                   $event_id Event ID.
	 * @return array<string, string>
	 */
	public function attendees_table_columns( $columns, $event_id = 0 ) {
		unset( $event_id );

		if ( ! is_array( $columns ) || ! $this->current_user_can_manage_passes() ) {
			return $columns;
		}

		if ( isset( $columns['epasscard_pass'] ) ) {
			return $columns;
		}

		$label = __( 'EpassCard', 'epasscard' );
		$out   = array();
		foreach ( $columns as $key => $header ) {
			if ( 'check_in' === $key ) {
				$out['epasscard_pass'] = $label;
			}
			$out[ $key ] = $header;
		}

		if ( ! isset( $out['epasscard_pass'] ) ) {
			$out['epasscard_pass'] = $label;
		}

		return $out;
	}

	/**
	 * Render EpassCard pass actions in the attendees table column.
	 *
	 * @param string               $value  Cell value.
	 * @param array<string, mixed> $item   Attendee row.
	 * @param string               $column Column key.
	 * @return string
	 */
	public function attendees_table_column( $value, $item, $column ) {
		if ( 'epasscard_pass' !== $column ) {
			return $value;
		}

		$attendee_id = $this->resolve_attendee_id_from_row( $item );
		if ( $attendee_id <= 0 ) {
			return '—';
		}

		$html = $this->render_pass_action_links( $attendee_id );
		if ( '' === $html ) {
			return '—';
		}

		return self::kses_pass_action_html( $html );
	}

	/**
	 * Append pass action controls under ticket row actions.
	 *
	 * @param array<int|string, string> $row_actions Existing row actions HTML.
	 * @param array<string, mixed>      $item        Attendee row.
	 * @return array<int|string, string>
	 */
	public function attendees_table_row_actions( $row_actions, $item ) {
		if ( ! is_array( $row_actions ) || ! $this->current_user_can_manage_passes() ) {
			return $row_actions;
		}

		$attendee_id = $this->resolve_attendee_id_from_row( $item );
		if ( $attendee_id <= 0 ) {
			return $row_actions;
		}

		$html = $this->render_pass_action_links( $attendee_id );
		if ( '' === $html ) {
			return $row_actions;
		}

		$row_actions['epasscard_pass'] = self::kses_pass_action_html( $html );

		return $row_actions;
	}

	/**
	 * Resolve attendee ID from an attendees-table row.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return int
	 */
	private function resolve_attendee_id_from_row( $item ) {
		if ( ! is_array( $item ) ) {
			return 0;
		}

		if ( ! empty( $item['attendee_id'] ) ) {
			return absint( $item['attendee_id'] );
		}

		if ( ! empty( $item['qr_ticket_id'] ) ) {
			return absint( $item['qr_ticket_id'] );
		}

		return 0;
	}

	/**
	 * RSVP attendee created.
	 *
	 * @param int    $attendee_id Attendee ID.
	 * @param int    $post_id     Event ID.
	 * @param string $order_id    Order hash.
	 * @param int    $product_id  Ticket ID.
	 * @return void
	 */
	public function on_rsvp_attendee_created( $attendee_id, $post_id, $order_id, $product_id = 0 ) {
		unset( $post_id, $order_id, $product_id );
		$this->handle_attendee( absint( $attendee_id ) );
	}

	/**
	 * Legacy Tribe Commerce / PayPal attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return void
	 */
	public function on_tpp_attendee_created( $attendee_id ) {
		$this->handle_attendee( absint( $attendee_id ) );
	}

	/**
	 * Tickets Commerce attendee created.
	 *
	 * @param mixed $attendee Attendee post.
	 * @return void
	 */
	public function on_commerce_attendee_created( $attendee ) {
		$attendee_id = is_object( $attendee ) && isset( $attendee->ID ) ? (int) $attendee->ID : absint( $attendee );
		$this->handle_attendee( $attendee_id );
	}

	/**
	 * Tickets Commerce flag-generated attendee.
	 *
	 * @param mixed $attendee Attendee.
	 * @return void
	 */
	public function on_commerce_flag_attendee( $attendee ) {
		$this->on_commerce_attendee_created( $attendee );
	}

	/**
	 * WooCommerce (Event Tickets Plus) attendee.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return void
	 */
	public function on_woo_attendee_created( $attendee_id ) {
		$this->handle_attendee( absint( $attendee_id ) );
	}

	/**
	 * Attendee profile update.
	 *
	 * @param mixed $attendee_data Data.
	 * @param int   $attendee_id   Attendee ID.
	 * @return void
	 */
	public function on_attendee_update( $attendee_data, $attendee_id ) {
		unset( $attendee_data );
		$this->handle_attendee( absint( $attendee_id ) );
	}

	/**
	 * Attendee ticket deleted.
	 *
	 * @param int $post_id   Event ID.
	 * @param int $ticket_id Attendee/ticket ID (provider-specific).
	 * @return void
	 */
	public function on_attendee_deleted( $post_id, $ticket_id ) {
		unset( $post_id );
		$attendee_id = absint( $ticket_id );
		if ( $attendee_id > 0 ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $attendee_id );
		}
	}

	/**
	 * Commerce attendee deleted.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return void
	 */
	public function on_commerce_attendee_deleted( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		if ( $attendee_id > 0 ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $attendee_id );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$data = $this->get_attendee_data( absint( $source_id ) );
		if ( ! $data ) {
			return new WP_Error( 'epc_invalid_source', __( 'Attendee not found.', 'epasscard' ) );
		}

		return $this->sync_attendee_data( $data, $mode );
	}

	/**
	 * Apply status rule then sync.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return void
	 */
	private function handle_attendee( $attendee_id ) {
		if ( ! EPC_Api_Client::is_configured() || $attendee_id <= 0 ) {
			return;
		}

		$data = $this->get_attendee_data( $attendee_id );
		if ( ! $data ) {
			return;
		}

		$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;
		if ( $event_id <= 0 || ! $this->is_tec_event( $event_id ) ) {
			return;
		}

		$status = $this->normalize_attendee_status( $data );
		$action = $this->get_status_rule( $status );

		switch ( $action ) {
			case 'sync':
				$this->sync_attendee_data( $data, 'sync' );
				break;
			case 'update':
				$this->sync_attendee_data( $data, 'update' );
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $attendee_id );
				break;
			default:
				break;
		}
	}

	/**
	 * Sync from attendee data array.
	 *
	 * @param array<string, mixed> $data Attendee data.
	 * @param string               $mode Mode.
	 * @return true|\WP_Error
	 */
	private function sync_attendee_data( array $data, $mode = 'sync' ) {
		$attendee_id = isset( $data['attendee_id'] ) ? absint( $data['attendee_id'] ) : 0;
		$event_id    = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;

		if ( $attendee_id <= 0 || $event_id <= 0 || ! $this->is_tec_event( $event_id ) ) {
			return new WP_Error( 'epc_invalid_source', __( 'Attendee not found.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $event_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this event.', 'epasscard' ) );
		}

		$ticket_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( $ticket_id <= 0 && isset( $data['ticket_id'] ) ) {
			$ticket_id = absint( $data['ticket_id'] );
		}

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
		$email   = isset( $data['holder_email'] ) ? (string) $data['holder_email'] : '';
		$full    = isset( $data['holder_name'] ) ? (string) $data['holder_name'] : '';

		if ( $user_id <= 0 && $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = (int) $user->ID;
			}
		}

		$user  = $user_id > 0 ? get_userdata( $user_id ) : false;
		$first = $user ? (string) get_user_meta( $user_id, 'first_name', true ) : '';
		$last  = $user ? (string) get_user_meta( $user_id, 'last_name', true ) : '';
		if ( '' === $full ) {
			$full = epc_format_user_full_name( $first, $last, $user ? $user->display_name : $email );
		}

		$ticket_name = isset( $data['ticket'] ) ? (string) $data['ticket'] : '';
		if ( '' === $ticket_name && $ticket_id > 0 ) {
			$ticket_name = get_the_title( $ticket_id );
		}

		$event_end = $this->get_event_end_raw( $event_id );

		$values = array(
			'user_display_name' => $user ? $user->display_name : $full,
			'user_email'        => $email ? $email : ( $user ? $user->user_email : '' ),
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => $full,
			'attendee_id'       => (string) $attendee_id,
			'ticket_id'         => (string) $ticket_id,
			'ticket_name'       => $ticket_name,
			'event_id'          => (string) $event_id,
			'event_title'       => get_the_title( $event_id ),
			'event_start'       => $this->format_event_datetime( $event_id, 'start' ),
			'event_end'         => $this->format_event_datetime( $event_id, 'end' ),
			'expire_date'       => epc_format_pass_expiry_datetime( $event_end ),
			'venue_name'        => $this->get_venue_name( $event_id ),
			'venue_address'     => $this->get_venue_address( $event_id ),
			'organizer_name'    => $this->get_organizer_name( $event_id ),
			'event_cost'        => $this->get_event_cost( $event_id ),
			'event_url'         => get_permalink( $event_id ) ? (string) get_permalink( $event_id ) : '',
			'event_website'     => $this->get_event_website( $event_id ),
			'order_id'          => isset( $data['order_id'] ) ? (string) $data['order_id'] : '',
			'security_code'     => isset( $data['security_code'] ) ? (string) $data['security_code'] : ( isset( $data['security'] ) ? (string) $data['security'] : '' ),
			'attendee_status'   => $this->normalize_attendee_status( $data ),
			'check_in'          => ! empty( $data['check_in'] ) ? '1' : '0',
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			$attendee_id,
			$event_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}

	/**
	 * Load attendee data via Event Tickets provider.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return array<string, mixed>|null
	 */
	private function get_attendee_data( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		if ( $attendee_id <= 0 ) {
			return null;
		}

		if ( function_exists( 'tribe_tickets_get_ticket_provider' ) ) {
			$provider = tribe_tickets_get_ticket_provider( $attendee_id );
			if ( $provider && method_exists( $provider, 'get_attendee' ) ) {
				$data = $provider->get_attendee( $attendee_id );
				if ( is_array( $data ) && ! empty( $data ) ) {
					if ( empty( $data['attendee_id'] ) ) {
						$data['attendee_id'] = $attendee_id;
					}
					if ( empty( $data['event_id'] ) ) {
						$data['event_id'] = $this->get_attendee_event_id( $attendee_id );
					}
					return $data;
				}
			}
		}

		if ( function_exists( 'tribe_tickets_get_attendees' ) ) {
			$list = tribe_tickets_get_attendees( $attendee_id );
			if ( is_array( $list ) ) {
				foreach ( $list as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$row_id = isset( $row['attendee_id'] ) ? absint( $row['attendee_id'] ) : 0;
					if ( $row_id === $attendee_id ) {
						if ( empty( $row['event_id'] ) ) {
							$row['event_id'] = $this->get_attendee_event_id( $attendee_id );
						}
						return $row;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Event ID stored on an attendee post.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return int
	 */
	private function get_attendee_event_id( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		$keys        = array(
			'_tribe_rsvp_event',
			'_tec_tickets_commerce_event',
			'_tribe_wooticket_event',
			'_tribe_tpp_event',
		);

		foreach ( $keys as $key ) {
			$event_id = absint( get_post_meta( $attendee_id, $key, true ) );
			if ( $event_id > 0 ) {
				return $event_id;
			}
		}

		return 0;
	}

	/**
	 * Normalize status for rules.
	 *
	 * @param array<string, mixed> $data Attendee data.
	 * @return string
	 */
	private function normalize_attendee_status( array $data ) {
		$raw = '';
		if ( isset( $data['order_status'] ) ) {
			$raw = (string) $data['order_status'];
		}

		$raw = strtolower( trim( $raw ) );
		if ( in_array( $raw, array( 'yes', 'going' ), true ) ) {
			return 'yes';
		}
		if ( in_array( $raw, array( 'no', 'not-going', 'not_going' ), true ) ) {
			return 'no';
		}

		return 'active';
	}

	/**
	 * Whether a post is a The Events Calendar event.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	private function is_tec_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id <= 0 ) {
			return false;
		}

		return $this->get_event_post_type() === get_post_type( $event_id );
	}

	/**
	 * TEC event post type.
	 *
	 * @return string
	 */
	private function get_event_post_type() {
		if ( class_exists( 'Tribe__Events__Main' ) ) {
			return Tribe__Events__Main::POSTTYPE;
		}

		return 'tribe_events';
	}

	/**
	 * Raw event end datetime string.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_event_end_raw( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id <= 0 ) {
			return '';
		}

		$end = get_post_meta( $event_id, '_EventEndDate', true );
		if ( ! $end ) {
			$end = get_post_meta( $event_id, '_EventEndDateUTC', true );
		}

		return is_string( $end ) ? $end : '';
	}

	/**
	 * Format event start/end for mapping.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $which    start|end.
	 * @return string
	 */
	private function format_event_datetime( $event_id, $which = 'start' ) {
		$event_id = absint( $event_id );
		if ( $event_id <= 0 ) {
			return '';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( 'end' === $which && function_exists( 'tribe_get_end_date' ) ) {
			$formatted = tribe_get_end_date( $event_id, true, $format );
			return is_string( $formatted ) ? $formatted : '';
		}

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$formatted = tribe_get_start_date( $event_id, true, $format );
			return is_string( $formatted ) ? $formatted : '';
		}

		$key = 'end' === $which ? '_EventEndDate' : '_EventStartDate';
		$raw = get_post_meta( $event_id, $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$ts = strtotime( $raw );
		return $ts ? wp_date( $format, $ts ) : $raw;
	}

	/**
	 * Event start timestamp for push scheduling.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	private function get_event_start_timestamp( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$unix = tribe_get_start_date( $event_id, true, 'U' );
			if ( is_numeric( $unix ) && (int) $unix > 0 ) {
				return (int) $unix;
			}
		}

		$raw = get_post_meta( $event_id, '_EventStartDate', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return 0;
		}
		$ts = strtotime( $raw );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Venue name for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_venue_name( $event_id ) {
		if ( function_exists( 'tribe_get_venue' ) ) {
			$name = tribe_get_venue( $event_id );
			return is_string( $name ) ? $name : '';
		}

		return '';
	}

	/**
	 * Plain-text venue address.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_venue_address( $event_id ) {
		$parts = array();

		if ( function_exists( 'tribe_get_address' ) ) {
			$parts[] = (string) tribe_get_address( $event_id );
		}
		if ( function_exists( 'tribe_get_city' ) ) {
			$parts[] = (string) tribe_get_city( $event_id );
		}
		if ( function_exists( 'tribe_get_region' ) ) {
			$parts[] = (string) tribe_get_region( $event_id );
		}
		if ( function_exists( 'tribe_get_zip' ) ) {
			$parts[] = (string) tribe_get_zip( $event_id );
		}
		if ( function_exists( 'tribe_get_country' ) ) {
			$parts[] = (string) tribe_get_country( $event_id );
		}

		$parts = array_filter(
			array_map( 'trim', $parts ),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ', ', $parts );
	}

	/**
	 * Organizer name for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_organizer_name( $event_id ) {
		if ( function_exists( 'tribe_get_organizer' ) ) {
			$name = tribe_get_organizer( $event_id );
			return is_string( $name ) ? $name : '';
		}

		return '';
	}

	/**
	 * Formatted event cost.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_event_cost( $event_id ) {
		if ( function_exists( 'tribe_get_cost' ) ) {
			$cost = tribe_get_cost( $event_id, true );
			return is_string( $cost ) ? $cost : '';
		}

		return '';
	}

	/**
	 * TEC event website URL field.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_event_website( $event_id ) {
		if ( function_exists( 'tribe_get_event_website_url' ) ) {
			$url = tribe_get_event_website_url( $event_id );
			return is_string( $url ) ? $url : '';
		}

		return '';
	}
}
