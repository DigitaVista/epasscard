<?php
/**
 * Amelia events integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A wallet ticket for each Amelia event booking, with a design per event. All dates of a
 * recurring event share the design of its first date.
 */
class EPC_Module_Amelia_Events extends EPC_Module_Amelia_Bookings {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'amelia-events';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Amelia events', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function booking_kind() {
		return 'event';
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'Amelia is not installed or activated. Activate Amelia, then return here to set up event tickets.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet ticket for each Amelia event booking, with a design per event. The booking status decides when the ticket is made or voided; the day after the event it expires.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function kind_source_fields() {
		return array(
			'event_name' => __( 'Event', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function kind_values( array $record ) {
		return array(
			'event_name' => (string) $record['item_name'],
		);
	}

	/* -------------------------------------------------------------------------
	 * Items: events (a recurring event is one item: its first date)
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i WHERE parentId IS NULL ORDER BY id DESC LIMIT 200', self::table( 'events' ) ) );
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
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', self::table( 'events' ), absint( $entity_id ) ) );
		return null !== $name ? (string) $name : '#' . absint( $entity_id );
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
		return __( 'No Amelia events yet.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=wpamelia-events' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Amelia events', 'epasscard' );
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own tables, read fresh for the card.
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT id, customerId, status, persons FROM %i WHERE id = %d AND appointmentId IS NULL', self::table( 'customer_bookings' ), $booking_id ) );
		$period  = $booking ? $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ep.eventId, MIN(ep.periodStart) AS start_utc, MAX(ep.periodEnd) AS end_utc
				FROM %i bp INNER JOIN %i ep ON ep.id = bp.eventPeriodId
				WHERE bp.customerBookingId = %d GROUP BY ep.eventId ORDER BY ep.eventId ASC LIMIT 1',
				self::table( 'customer_bookings_to_events_periods' ),
				self::table( 'events_periods' ),
				$booking_id
			)
		) : null;
		$event   = $period ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, parentId, name, status, locationId, customLocation FROM %i WHERE id = %d', self::table( 'events' ), absint( $period->eventId ) ) ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $booking || ! $event ) {
			return null;
		}
		$location = $this->get_location( absint( $event->locationId ) );
		if ( ! $location && '' !== trim( (string) $event->customLocation ) ) {
			$location = (object) array(
				'name'    => (string) $event->customLocation,
				'address' => '',
			);
		}
		return array(
			'booking'   => $booking,
			'customer'  => $this->get_customer( absint( $booking->customerId ) ),
			'status'    => in_array( (string) $event->status, array( 'canceled', 'rejected' ), true ) ? (string) $event->status : (string) $booking->status,
			'start'     => (string) $period->start_utc,
			'end'       => (string) $period->end_utc,
			'item_id'   => absint( $event->parentId ) > 0 ? absint( $event->parentId ) : absint( $event->id ),
			'item_name' => (string) $event->name,
			'location'  => $location,
			'extra'     => array(),
		);
	}

	/**
	 * Ids of an event and, for a recurring event, its other dates.
	 *
	 * @param int  $id        Event id.
	 * @param bool $recurring Include the other dates.
	 * @return array<int, int>
	 */
	private function event_ids( $id, $recurring ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $recurring ) {
			return array( $id );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table.
		$parent = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT parentId FROM %i WHERE id = %d', self::table( 'events' ), $id ) ) );
		$root   = $parent > 0 ? $parent : $id;
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d OR parentId = %d', self::table( 'events' ), $root, $root ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Booking ids of events.
	 *
	 * @param array<int, int> $event_ids Event ids.
	 * @return array<int, int>
	 */
	private function booking_ids_for_events( array $event_ids ) {
		global $wpdb;
		$event_ids = array_values( array_filter( array_map( 'absint', $event_ids ) ) );
		if ( empty( $event_ids ) ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Amelia's own tables ($in is only %d placeholders).
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT bp.customerBookingId FROM %i bp INNER JOIN %i ep ON ep.id = bp.eventPeriodId WHERE ep.eventId IN ($in) ORDER BY bp.customerBookingId ASC",
				array_merge( array( self::table( 'customer_bookings_to_events_periods' ), self::table( 'events_periods' ) ), $event_ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @inheritDoc
	 */
	protected function booking_ids_of( $id, $recurring = false ) {
		return $this->booking_ids_for_events( $this->event_ids( $id, $recurring ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function is_own_parent( array $data ) {
		return array_key_exists( 'periods', $data ) && array_key_exists( 'bookingOpens', $data );
	}

	/**
	 * @inheritDoc
	 */
	protected function item_booking_ids( $entity_id ) {
		return $this->booking_ids_for_events( $this->event_ids( $entity_id, true ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function change_hooks() {
		return array(
			'amelia_after_event_booking_saved',
			'amelia_after_event_booking_updated',
			'amelia_after_event_status_updated',
			'amelia_after_event_updated',
			'amelia_after_booking_canceled',
			'amelia_after_booking_approved',
			'amelia_after_booking_pending',
			'amelia_after_booking_rejected',
			'amelia_after_booking_no-show',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function delete_hooks() {
		return array(
			'amelia_after_event_booking_deleted',
			'amelia_after_event_deleted',
			'amelia_after_events_deleted',
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
				'message' => __( '{event_name} starts {booking_start}. Your ticket is in your wallet.', 'epasscard' ),
			),
		);
	}
}
