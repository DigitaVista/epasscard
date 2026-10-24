<?php
/**
 * Amelia appointments integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A wallet card for each Amelia appointment booking, with a design per service.
 */
class EPC_Module_Amelia_Appointments extends EPC_Module_Amelia_Bookings {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'amelia-appointments';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Amelia appointments', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function booking_kind() {
		return 'appointment';
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'Amelia is not installed or activated. Activate Amelia, then return here to set up appointment cards.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet card for each Amelia appointment, with a design per service. The booking status decides when the card is made or voided; the day after the appointment it expires.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function kind_source_fields() {
		return array(
			'service_name'  => __( 'Service', 'epasscard' ),
			'provider_name' => __( 'Employee', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function kind_values( array $record ) {
		return array(
			'service_name'  => (string) $record['item_name'],
			'provider_name' => (string) ( $record['extra']['provider_name'] ?? '' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Items: services
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM %i WHERE status <> 'disabled' ORDER BY position ASC, name ASC", self::table( 'services' ) ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row->id,
				'label' => (string) $row->name,
			);
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', self::table( 'services' ), absint( $entity_id ) ) );
		return null !== $name ? (string) $name : '#' . absint( $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Service', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __( 'No Amelia services yet.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=wpamelia-catalog' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Amelia services', 'epasscard' );
	}

	/* -------------------------------------------------------------------------
	 * Bookings
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function resolve_booking( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		if ( $booking_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own tables, read fresh for the card.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT cb.id, cb.customerId, cb.status, cb.persons, a.status AS appointment_status, a.bookingStart, a.bookingEnd, a.serviceId, a.providerId, a.locationId, s.name AS service_name
				FROM %i cb
				INNER JOIN %i a ON a.id = cb.appointmentId
				LEFT JOIN %i s ON s.id = a.serviceId
				WHERE cb.id = %d',
				self::table( 'customer_bookings' ),
				self::table( 'appointments' ),
				self::table( 'services' ),
				$booking_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$status   = in_array( (string) $row->appointment_status, array( 'canceled', 'rejected' ), true ) ? (string) $row->appointment_status : (string) $row->status;
		$provider = absint( $row->providerId ) > 0 ? $this->get_customer( absint( $row->providerId ) ) : null;
		return array(
			'booking'   => $row,
			'customer'  => $this->get_customer( absint( $row->customerId ) ),
			'status'    => $status,
			'start'     => (string) $row->bookingStart,
			'end'       => (string) $row->bookingEnd,
			'item_id'   => absint( $row->serviceId ),
			'item_name' => (string) $row->service_name,
			'location'  => $this->get_location( absint( $row->locationId ) ),
			'extra'     => array(
				'provider_name' => $provider ? trim( $provider->firstName . ' ' . $provider->lastName ) : '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function booking_ids_of( $id, $recurring = false ) {
		global $wpdb;
		unset( $recurring );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE appointmentId = %d', self::table( 'customer_bookings' ), absint( $id ) ) ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function is_own_parent( array $data ) {
		return array_key_exists( 'serviceId', $data ) && array_key_exists( 'bookingStart', $data );
	}

	/**
	 * @inheritDoc
	 */
	protected function item_booking_ids( $entity_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own tables.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT cb.id FROM %i cb INNER JOIN %i a ON a.id = cb.appointmentId WHERE a.serviceId = %d ORDER BY cb.id ASC', self::table( 'customer_bookings' ), self::table( 'appointments' ), absint( $entity_id ) ) ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function change_hooks() {
		return array(
			'amelia_after_appointment_booking_saved',
			'amelia_after_appointment_added',
			'amelia_after_appointment_updated',
			'amelia_after_appointment_status_updated',
			'amelia_after_booking_added',
			'amelia_after_booking_rescheduled',
			'amelia_after_booking_canceled',
			'amelia_after_booking_approved',
			'amelia_after_booking_pending',
			'amelia_after_booking_rejected',
			'amelia_after_booking_no-show',
			'amelia_after_booking_approved_link',
			'amelia_after_booking_rejected_link',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function delete_hooks() {
		return array(
			'amelia_after_appointment_deleted',
			'amelia_after_appointment_updated', // Bookings removed from the appointment.
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
				'title'   => __( 'See you soon', 'epasscard' ),
				'message' => __( 'Your {service_name} is on {booking_start}. Your card is in your wallet.', 'epasscard' ),
			),
		);
	}
}
