<?php
/**
 * Shared base of the Amelia integrations (appointments and events).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A wallet card for each Amelia booking (or one per person, if the site chooses so).
 *
 * - Record: the booking (Amelia's customer_bookings row). One card per booking: the record id
 *   is the booking id. One card per person: "p<booking id>_<n>". A booking keeps the way its
 *   cards were first made when the setting changes later.
 * - The booking status decides (approved: card; pending: wait; canceled and rejected: void;
 *   changeable per status). A canceled or rejected appointment or event counts for all its
 *   bookings.
 * - The day after the appointment or event ends, the card is expired. The same daily check
 *   voids cards whose booking was deleted and sends the "before it starts" reminder.
 * - Amelia keeps its times in UTC; cards show them in the site's time zone.
 */
abstract class EPC_Module_Amelia_Bookings extends EPC_Module {

	/**
	 * Booking ids changed in this request, handled once at the end of the request.
	 *
	 * @var array<int, true>
	 */
	private $queued = array();

	/**
	 * Whether an orphan sweep is due at the end of the request.
	 *
	 * @var bool
	 */
	private $sweep_queued = false;

	/**
	 * 'appointment' or 'event'.
	 *
	 * @return string
	 */
	abstract protected function booking_kind();

	/**
	 * Booking data (null when the booking does not exist or is of the other kind).
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string, mixed>|null Keys: booking (object), customer (object|null),
	 *                                   status, start, end (UTC 'Y-m-d H:i:s'), item_id,
	 *                                   item_name, location (object|null), extra (array).
	 */
	abstract public function resolve_booking( $booking_id );

	/**
	 * Booking ids of an appointment or event.
	 *
	 * @param int  $id        Appointment or event id.
	 * @param bool $recurring Also the other dates of a recurring event.
	 * @return array<int, int>
	 */
	abstract protected function booking_ids_of( $id, $recurring = false );

	/**
	 * Card fields of this kind.
	 *
	 * @return array<string, string>
	 */
	abstract protected function kind_source_fields();

	/**
	 * Card values of this kind.
	 *
	 * @param array<string, mixed> $record Resolved booking.
	 * @return array<string, string>
	 */
	abstract protected function kind_values( array $record );

	/**
	 * Amelia table name.
	 *
	 * @param string $name Table name without the prefix.
	 * @return string
	 */
	protected static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'amelia_' . $name;
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Amelia', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => 'ameliabooking',
				'label'       => __( 'Amelia', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'ameliabooking',
				'plugin_file' => 'ameliabooking/ameliabooking.php',
				'url'         => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_amelia_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array_merge(
			array(
				'user_full_name'   => __( 'Customer name', 'epasscard' ),
				'user_email'       => __( 'Customer email', 'epasscard' ),
				'customer_phone'   => __( 'Customer phone', 'epasscard' ),
				'booking_start'    => __( 'Starts (date and time)', 'epasscard' ),
				'booking_date'     => __( 'Date', 'epasscard' ),
				'booking_time'     => __( 'Time', 'epasscard' ),
				'booking_end'      => __( 'Ends (date and time)', 'epasscard' ),
				'location_name'    => __( 'Location', 'epasscard' ),
				'location_address' => __( 'Location address', 'epasscard' ),
				'persons'          => __( 'Number of people', 'epasscard' ),
				'unit_label'       => __( 'Person (e.g. "2 of 3", one card per person)', 'epasscard' ),
				'booking_status'   => __( 'Booking status', 'epasscard' ),
				'booking_id'       => __( 'Booking number', 'epasscard' ),
				'ticket_code'      => __( 'Card code (booking number, with the person when one card per person)', 'epasscard' ),
			),
			$this->kind_source_fields()
		);
	}

	/* -------------------------------------------------------------------------
	 * Status rules and cards per booking
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_' . str_replace( '-', '_', $this->get_slug() ) . '_status_rules';
	}

	/**
	 * @inheritDoc
	 */
	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * Amelia booking statuses.
	 *
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		return array(
			'approved' => __( 'Approved', 'epasscard' ),
			'pending'  => __( 'Pending', 'epasscard' ),
			'canceled' => __( 'Canceled', 'epasscard' ),
			'rejected' => __( 'Rejected', 'epasscard' ),
			'no-show'  => __( 'No-show', 'epasscard' ),
			'waiting'  => __( 'On the waiting list', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_status_rules() {
		return array(
			'approved' => 'sync',
			'pending'  => 'none',
			'canceled' => 'revoke',
			'rejected' => 'revoke',
			'no-show'  => 'none',
			'waiting'  => 'none',
		);
	}

	/**
	 * Option: 'booking' (one card per booking) or 'person'.
	 *
	 * @return string
	 */
	public function get_cards_per_option_key() {
		return 'epc_' . str_replace( '-', '_', $this->get_slug() ) . '_cards_per';
	}

	/**
	 * How new bookings get cards: 'booking' (default) or 'person'.
	 *
	 * @return string
	 */
	public function cards_per() {
		return 'person' === get_option( $this->get_cards_per_option_key(), 'booking' ) ? 'person' : 'booking';
	}

	/**
	 * @inheritDoc
	 */
	protected function render_status_rules_extra_fields() {
		$current = $this->cards_per();
		?>
		<fieldset class="epc-cards-per" style="margin-top:16px;">
			<legend><strong><?php esc_html_e( 'A booking for several people gets', 'epasscard' ); ?></strong></legend>
			<p>
				<label><input type="radio" name="epc_cards_per" value="booking" <?php checked( 'booking', $current ); ?> /> <?php esc_html_e( 'One card for the booking, showing the number of people (one code for the group)', 'epasscard' ); ?></label><br />
				<label><input type="radio" name="epc_cards_per" value="person" <?php checked( 'person', $current ); ?> /> <?php esc_html_e( 'One card per person, each with its own code', 'epasscard' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'Applies to new bookings. Bookings that already have cards keep them as they are.', 'epasscard' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * @inheritDoc
	 */
	protected function save_status_rules_extra() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_status_rules().
		$value = isset( $_POST['epc_cards_per'] ) ? sanitize_key( wp_unslash( (string) $_POST['epc_cards_per'] ) ) : '';
		if ( in_array( $value, array( 'booking', 'person' ), true ) ) {
			update_option( $this->get_cards_per_option_key(), $value, false );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}
		$this->render_status_rules_form(
			array(
				'heading'      => __( 'Cards by booking status', 'epasscard' ),
				'description'  => __( 'Choose what happens to the card when an Amelia booking is approved, canceled or rejected. The day after the booking ends, its card is expired.', 'epasscard' ),
				'status_label' => __( 'Booking status', 'epasscard' ),
			)
		);
		$this->render_push_notification_settings();
	}

	/* -------------------------------------------------------------------------
	 * Record ids
	 * ---------------------------------------------------------------------- */

	/**
	 * Booking id and person number of a record id ("12" or "p12_3"); null when malformed.
	 *
	 * @param string $source_id Record id.
	 * @return array{0: int, 1: int}|null Person number 0 = one card for the booking.
	 */
	public static function parse_source_id( $source_id ) {
		if ( preg_match( '/^\d+$/', (string) $source_id ) ) {
			return array( (int) $source_id, 0 );
		}
		if ( preg_match( '/^p(\d+)_(\d+)$/', (string) $source_id, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		return null;
	}

	/**
	 * Record ids of a booking's existing cards.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int, string>
	 */
	public function existing_source_ids( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cards of one booking.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT source_id FROM %i WHERE module = %s AND ( source_id = %s OR source_id LIKE %s ) AND pass_uid <> ''", EPC_DB::table_name(), $this->get_slug(), (string) $booking_id, $wpdb->esc_like( 'p' . $booking_id . '_' ) . '%' ) );
		return array_values( array_filter( array_map( 'strval', (array) $ids ), array( __CLASS__, 'parse_source_id' ) ) );
	}

	/**
	 * Record ids a booking should have cards for.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $persons    Number of people.
	 * @return array<int, string>
	 */
	public function wanted_source_ids( $booking_id, $persons ) {
		$existing   = $this->existing_source_ids( $booking_id );
		$per_person = empty( $existing ) ? 'person' === $this->cards_per() : ! in_array( (string) $booking_id, $existing, true );
		if ( ! $per_person ) {
			return array( (string) absint( $booking_id ) );
		}
		$out = array();
		for ( $n = 1; $n <= max( 1, (int) $persons ); $n++ ) {
			$out[] = 'p' . absint( $booking_id ) . '_' . $n;
		}
		return $out;
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * Amelia actions that change bookings of this kind.
	 *
	 * @return array<int, string>
	 */
	abstract protected function change_hooks();

	/**
	 * Amelia actions after which bookings may have been deleted.
	 *
	 * @return array<int, string>
	 */
	abstract protected function delete_hooks();

	/**
	 * Of the change hooks, those about one booking: their first argument is the booking (or has it
	 * under "booking"), and the other bookings of its appointment or event did not change.
	 *
	 * @return array<int, string>
	 */
	protected function booking_hooks() {
		return array();
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		$booking_hooks = $this->booking_hooks();
		foreach ( $this->change_hooks() as $hook ) {
			$handler = in_array( $hook, $booking_hooks, true ) ? 'on_amelia_booking_change' : 'on_amelia_change';
			add_action( $hook, array( $this, $handler ), 20, 3 );
		}
		foreach ( $this->delete_hooks() as $hook ) {
			add_action( $hook, array( $this, 'on_amelia_delete' ), 20, 1 );
		}
		// A customer's name, email or phone changed in Amelia: refresh their live cards.
		add_action( 'amelia_after_customer_updated', array( $this, 'on_customer_updated' ), 20, 1 );
	}

	/**
	 * One booking changed (a new booking, its status): handle that booking only. Before, the
	 * other bookings in the hook's event were handled too, one card update each.
	 *
	 * @param mixed $first  The booking, or an array with it under "booking".
	 * @param mixed $second Second argument.
	 * @param mixed $third  Third argument.
	 * @return void
	 */
	public function on_amelia_booking_change( $first = null, $second = null, $third = null ) {
		$booking = is_array( $first ) && isset( $first['booking'] ) && is_array( $first['booking'] ) ? $first['booking'] : $first;
		if ( is_array( $booking ) && isset( $booking['id'] ) && is_numeric( $booking['id'] ) && array_key_exists( 'customerId', $booking ) && array_key_exists( 'persons', $booking ) ) {
			$this->queue( (int) $booking['id'] );
			return;
		}
		$this->on_amelia_change( $first, $second, $third );
	}

	/**
	 * A customer was edited in Amelia: update the live cards of their bookings of this kind.
	 *
	 * Only cards that exist and are live are updated; no card is made for an old booking.
	 *
	 * @param mixed $customer Customer (array).
	 * @return void
	 */
	public function on_customer_updated( $customer = null ) {
		global $wpdb;
		$customer_id = is_array( $customer ) && isset( $customer['id'] ) ? absint( $customer['id'] ) : 0;
		if ( $customer_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$kind = 'appointment' === $this->booking_kind() ? 'appointmentId IS NOT NULL' : 'appointmentId IS NULL';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Amelia's own table; $kind is one of two fixed strings.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE customerId = %d AND $kind ORDER BY id ASC", self::table( 'customer_bookings' ), $customer_id ) );
		foreach ( array_map( 'absint', (array) $ids ) as $booking_id ) {
			foreach ( $this->existing_source_ids( $booking_id ) as $source_id ) {
				$row = EPC_DB::get_pass( $this->get_slug(), $source_id );
				if ( $row && 'active' === (string) $row->status ) {
					$this->sync_by_source_id( $source_id, 'update' );
				}
			}
		}
	}

	/**
	 * Something changed in Amelia: remember the bookings it touched.
	 *
	 * @param mixed $first  First hook argument (a booking, appointment or event as an array).
	 * @param mixed $second Second argument.
	 * @param mixed $third  Third argument (applyGlobally for event updates).
	 * @return void
	 */
	public function on_amelia_change( $first = null, $second = null, $third = null ) {
		$recurring = true === $third || 'true' === $third || 1 === $third || '1' === $third;
		foreach ( array( $first, $second ) as $arg ) {
			foreach ( $this->booking_ids_in( $arg, $recurring ) as $id ) {
				$this->queue( $id );
			}
		}
	}

	/**
	 * Bookings may have been deleted: check the cards at the end of the request.
	 *
	 * @param mixed $data Hook argument.
	 * @return void
	 */
	public function on_amelia_delete( $data = null ) {
		foreach ( $this->booking_ids_in( $data, false ) as $id ) {
			$this->queue( $id );
		}
		$this->sweep_queued = true;
		$this->schedule_flush();
	}

	/**
	 * Booking ids in a hook argument: bookings themselves, and the bookings of the
	 * appointments and events in it.
	 *
	 * @param mixed $data      Hook argument.
	 * @param bool  $recurring Include the other dates of recurring events.
	 * @return array<int, int>
	 */
	public function booking_ids_in( $data, $recurring = false ) {
		$out = array();
		if ( ! is_array( $data ) ) {
			return $out;
		}
		if ( isset( $data['id'] ) && is_numeric( $data['id'] ) ) {
			if ( array_key_exists( 'customerId', $data ) && array_key_exists( 'persons', $data ) ) {
				$out[] = (int) $data['id']; // A booking.
			} elseif ( $this->is_own_parent( $data ) ) {
				$out = array_merge( $out, $this->booking_ids_of( (int) $data['id'], $recurring ) );
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$out = array_merge( $out, $this->booking_ids_in( $value, $recurring ) );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $out ) ) ) );
	}

	/**
	 * Whether an array is an appointment (or event) of this kind.
	 *
	 * @param array<string, mixed> $data Array.
	 * @return bool
	 */
	abstract protected function is_own_parent( array $data );

	/**
	 * Queue a booking for the end of the request.
	 *
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function queue( $booking_id ) {
		$booking_id = absint( $booking_id );
		if ( $booking_id <= 0 ) {
			return;
		}
		$this->queued[ $booking_id ] = true;
		$this->schedule_flush();
	}

	/**
	 * Run the queue once, at the end of the request (after Amelia has saved everything).
	 *
	 * @return void
	 */
	private function schedule_flush() {
		if ( ! has_action( 'shutdown', array( $this, 'flush' ) ) ) {
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
	}

	/**
	 * Handle the queued bookings.
	 *
	 * @return void
	 */
	public function flush() {
		$queued       = array_keys( $this->queued );
		$sweep        = $this->sweep_queued;
		$this->queued = array();
		$this->sweep_queued = false;
		foreach ( $queued as $booking_id ) {
			$this->handle_booking( (int) $booking_id );
		}
		if ( $sweep ) {
			$this->void_orphans();
		}
	}

	/**
	 * Apply the booking's status rule to its cards.
	 *
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function handle_booking( $booking_id ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$record = $this->resolve_booking( $booking_id );
		if ( null === $record ) {
			foreach ( $this->existing_source_ids( $booking_id ) as $source_id ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
			}
			return;
		}
		$action   = $this->get_status_rule( (string) $record['status'] );
		$existing = $this->existing_source_ids( $booking_id );
		if ( 'revoke' === $action ) {
			foreach ( $existing as $source_id ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
			}
			return;
		}
		if ( ! in_array( $action, array( 'sync', 'update' ), true ) || $this->has_ended( $record ) ) {
			return; // A finished booking's card stays expired; none is made for it.
		}
		$wanted = $this->wanted_source_ids( $booking_id, (int) $record['booking']->persons );
		foreach ( $wanted as $source_id ) {
			if ( 'update' === $action && ! in_array( $source_id, $existing, true ) ) {
				continue;
			}
			// A failure is recorded by the pass service and shown on Cards.
			$this->sync_by_source_id( $source_id, $action );
		}
		// Fewer people than before: void the extra cards.
		foreach ( array_diff( $existing, $wanted ) as $source_id ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
		}
	}

	/**
	 * Void live cards whose booking no longer exists.
	 *
	 * @return void
	 */
	public function void_orphans() {
		foreach ( EPC_DB::get_active_passes_for_module( $this->get_slug() ) as $row ) {
			$parsed = self::parse_source_id( (string) $row->source_id );
			if ( null !== $parsed && null === $this->resolve_booking( $parsed[0] ) ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $row->source_id );
			}
		}
	}

	/**
	 * Whether the booking ended before today (site time zone).
	 *
	 * @param array<string, mixed> $record Resolved booking.
	 * @return bool
	 */
	public function has_ended( array $record ) {
		$end = self::utc_timestamp( (string) $record['end'] );
		return $end > 0 && wp_date( 'Y-m-d', $end ) < wp_date( 'Y-m-d' );
	}

	/**
	 * Unix time of an Amelia UTC datetime.
	 *
	 * @param string $utc 'Y-m-d H:i:s' in UTC.
	 * @return int
	 */
	public static function utc_timestamp( $utc ) {
		$utc = trim( (string) $utc );
		if ( '' === $utc || 0 === strpos( $utc, '0000' ) ) {
			return 0;
		}
		$ts = strtotime( $utc . ' UTC' );
		return $ts ? (int) $ts : 0;
	}

	/* -------------------------------------------------------------------------
	 * Cards
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$parsed = self::parse_source_id( (string) $source_id );
		$record = null !== $parsed ? $this->resolve_booking( $parsed[0] ) : null;
		if ( null === $record ) {
			return new WP_Error( 'epc_invalid_source', __( 'Amelia booking not found.', 'epasscard' ) );
		}
		$mapping = $this->get_mapping( (int) $record['item_id'] );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for this item yet.', 'epasscard' ) );
		}
		$user_id = $record['customer'] ? absint( $record['customer']->externalId ) : 0;
		if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
			$user_id = 0;
		}
		return EPC_Pass_Service::sync_pass( $this->get_slug(), (string) $source_id, (int) $record['item_id'], $user_id, $mapping, $this->build_values( $record, $parsed[1] ), $mode );
	}

	/**
	 * Field values of a card.
	 *
	 * @param array<string, mixed> $record Resolved booking.
	 * @param int                  $unit   Person number, 0 for one card for the booking.
	 * @return array<string, string>
	 */
	public function build_values( array $record, $unit = 0 ) {
		$booking  = $record['booking'];
		$customer = $record['customer'];
		$location = $record['location'];
		$start    = self::utc_timestamp( (string) $record['start'] );
		$end      = self::utc_timestamp( (string) $record['end'] );
		$date     = (string) get_option( 'date_format' );
		$time     = (string) get_option( 'time_format' );
		$persons  = max( 1, (int) $booking->persons );
		$first    = $customer ? (string) $customer->firstName : '';
		$last     = $customer ? (string) $customer->lastName : '';
		$statuses = $this->get_pass_behavior_statuses();
		$status   = (string) $record['status'];

		return array_merge(
			array(
				'user_full_name'   => trim( $first . ' ' . $last ),
				'user_email'       => $customer ? (string) $customer->email : '',
				'customer_phone'   => $customer ? (string) $customer->phone : '',
				'booking_start'    => $start ? wp_date( $date . ' ' . $time, $start ) : '',
				'booking_date'     => $start ? wp_date( $date, $start ) : '',
				'booking_time'     => $start ? wp_date( $time, $start ) : '',
				'booking_end'      => $end ? wp_date( $date . ' ' . $time, $end ) : '',
				'location_name'    => $location ? (string) $location->name : '',
				'location_address' => $location ? (string) $location->address : '',
				'persons'          => (string) $persons,
				/* translators: 1: person number, 2: number of people */
				'unit_label'       => $unit > 0 ? sprintf( __( '%1$d of %2$d', 'epasscard' ), $unit, $persons ) : '',
				'booking_status'   => isset( $statuses[ $status ] ) ? (string) $statuses[ $status ] : $status,
				'booking_id'       => (string) $booking->id,
				'ticket_code'      => $unit > 0 ? $booking->id . '-' . $unit : (string) $booking->id,
			),
			$this->kind_values( $record )
		);
	}

	/**
	 * A booking without a WordPress account: the card goes to the customer's email.
	 *
	 * @inheritDoc
	 */
	public function get_guest_recipient( $source_id ) {
		$parsed = self::parse_source_id( (string) $source_id );
		$record = null !== $parsed ? $this->resolve_booking( $parsed[0] ) : null;
		$c      = $record ? $record['customer'] : null;
		if ( ! $c || ( absint( $c->externalId ) > 0 && get_userdata( absint( $c->externalId ) ) ) || ! is_email( (string) $c->email ) ) {
			return null;
		}
		return array(
			'email' => (string) $c->email,
			'name'  => (string) $c->firstName,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_record_customer( $source_id ) {
		$parsed = self::parse_source_id( (string) $source_id );
		$record = null !== $parsed ? $this->resolve_booking( $parsed[0] ) : null;
		$c      = $record ? $record['customer'] : null;
		return $c ? array(
			'name'  => trim( $c->firstName . ' ' . $c->lastName ),
			'email' => (string) $c->email,
		) : null;
	}

	/**
	 * Amelia customer row.
	 *
	 * @param int $customer_id Amelia user id.
	 * @return object|null
	 */
	protected function get_customer( $customer_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, firstName, lastName, email, phone, externalId FROM %i WHERE id = %d', self::table( 'users' ), absint( $customer_id ) ) );
		return $row ? $row : null;
	}

	/**
	 * Amelia location row.
	 *
	 * @param int $location_id Location id.
	 * @return object|null
	 */
	protected function get_location( $location_id ) {
		global $wpdb;
		if ( absint( $location_id ) <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, address FROM %i WHERE id = %d', self::table( 'locations' ), absint( $location_id ) ) );
		return $row ? $row : null;
	}

	/**
	 * @inheritDoc
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cards of the user's bookings.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE module = %s AND user_id = %d ORDER BY id DESC LIMIT 50', EPC_DB::table_name(), $this->get_slug(), $user_id ) );
		return array_map( 'strval', (array) $ids );
	}

	/* -------------------------------------------------------------------------
	 * Daily: expiry, deleted bookings, reminder
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'before_event_start' => __( 'Before it starts', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array_keys( $this->get_source_fields() );
	}

	/**
	 * @inheritDoc
	 */
	public function uses_batched_notifications() {
		return true;
	}

	/**
	 * The daily check also expires finished bookings and voids deleted ones, so it runs even
	 * with the reminder off.
	 *
	 * @param array<string, array<string, mixed>> $rules Notification rules.
	 * @return bool
	 */
	public function has_scheduled_notification_work( array $rules ) {
		unset( $rules );
		return true;
	}

	/**
	 * Daily check for one live card.
	 *
	 * @param object                              $pass_row Active pass row.
	 * @param array<string, array<string, mixed>> $rules    Notification rules.
	 * @return void
	 */
	public function process_scheduled_notification_for_pass( $pass_row, array $rules ) {
		$parsed = self::parse_source_id( (string) $pass_row->source_id );
		if ( null === $parsed ) {
			return;
		}
		$record = $this->resolve_booking( $parsed[0] );
		if ( null === $record ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $pass_row->source_id );
			return;
		}
		if ( $this->has_ended( $record ) ) {
			// The day after it ends: the card shows as expired in the wallet.
			EPC_Pass_Service::expire_issued_pass( $this->get_slug(), (string) $pass_row->source_id );
			return;
		}
		EPC_Pass_Notifications::maybe_send_for_event(
			$pass_row,
			'before_event_start',
			$rules['before_event_start'] ?? array(),
			self::utc_timestamp( (string) $record['start'] ),
			function () use ( $record, $parsed ) {
				return $this->build_values( $record, $parsed[1] );
			}
		);
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		$parsed = is_object( $pass_row ) ? self::parse_source_id( (string) $pass_row->source_id ) : null;
		$record = null !== $parsed ? $this->resolve_booking( $parsed[0] ) : null;
		return null !== $record ? $this->build_values( $record, $parsed[1] ) : array();
	}

	/* -------------------------------------------------------------------------
	 * Existing bookings
	 * ---------------------------------------------------------------------- */

	/**
	 * Cards upcoming bookings of an item would get (status gives a card, not ended yet), one
	 * record id per card, oldest booking first.
	 *
	 * @param int $entity_id Item id.
	 * @param int $limit     Batch size.
	 * @param int $offset    Offset.
	 * @return array<int, string>
	 */
	public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
		$units = array();
		foreach ( $this->item_booking_ids( absint( $entity_id ) ) as $booking_id ) {
			$record = $this->resolve_booking( $booking_id );
			if ( null === $record || 'sync' !== $this->get_status_rule( (string) $record['status'] ) || $this->has_ended( $record ) ) {
				continue;
			}
			$units = array_merge( $units, $this->wanted_source_ids( $booking_id, (int) $record['booking']->persons ) );
		}
		return array_slice( $units, absint( $offset ), absint( $limit ) );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_scope_label() {
		return __( 'Upcoming bookings whose status gives a card', 'epasscard' );
	}

	/**
	 * Booking ids of an item, oldest first.
	 *
	 * @param int $entity_id Item id.
	 * @return array<int, int>
	 */
	abstract protected function item_booking_ids( $entity_id );

	/**
	 * @inheritDoc
	 */
	public function get_backfill_user_id( $source_id ) {
		$parsed = self::parse_source_id( (string) $source_id );
		$record = null !== $parsed ? $this->resolve_booking( $parsed[0] ) : null;
		$user   = $record && $record['customer'] ? absint( $record['customer']->externalId ) : 0;
		return $user > 0 && get_userdata( $user ) ? $user : 0;
	}
}
