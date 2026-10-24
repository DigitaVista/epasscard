<?php
/**
 * Tickera integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A wallet ticket for each Tickera ticket (one per attendee), with a design per event.
 *
 * - Record: the ticket (Tickera's tc_tickets_instances post id). Its barcode can carry
 *   Tickera's ticket code, which Tickera's check-in apps scan.
 * - The order status decides (paid: card; received, i.e. waiting for an offline payment:
 *   wait; cancelled, refunded and fraud: void; changeable per status). A trashed or deleted
 *   order or ticket voids the card.
 * - The card goes to the attendee's email when Tickera asks for it, else to the buyer.
 * - Check-ins update the "Checked in" field (only when the design shows it).
 * - Changing the event's date, place or name, or a ticket type's name, refreshes the event's
 *   cards in the background.
 * - The day after the event ends, the card is expired. The same daily check voids cards
 *   whose ticket was deleted and sends the "before it starts" reminder.
 * - Tickera keeps event times as the site's local time ("Y-m-d H:i").
 * - Tickera's own checkout only (orders of Bridge for WooCommerce are not covered).
 */
class EPC_Module_Tickera extends EPC_Module {

	/**
	 * Cron hook that refreshes the cards of an event after it changed.
	 */
	const REFRESH_HOOK = 'epc_tickera_refresh_event';

	/**
	 * Cards refreshed per cron run.
	 */
	const REFRESH_BATCH = 50;

	/**
	 * Tickets changed in this request: ticket id => true for a full check, false when only a
	 * check-in changed.
	 *
	 * @var array<int, bool>
	 */
	private $queued = array();

	/**
	 * Orders changed in this request (their tickets are listed at the end of the request,
	 * once Tickera has saved them).
	 *
	 * @var array<int, true>
	 */
	private $queued_orders = array();

	/**
	 * Whether an orphan sweep is due at the end of the request.
	 *
	 * @var bool
	 */
	private $sweep_queued = false;

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'tickera';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Tickera', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Tickera', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => 'tickera-event-ticketing-system',
				'label'       => __( 'Tickera', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'tickera-event-ticketing-system',
				'plugin_file' => 'tickera-event-ticketing-system/tickera.php',
				'url'         => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_tickera_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'Tickera is not installed or activated. Activate Tickera, then return here to set up event tickets.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet ticket for each Tickera ticket, with a design per event. Tickera\'s check-in apps can scan it. The order status decides when the ticket is made or voided; the day after the event it expires.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_full_name' => __( 'Attendee name', 'epasscard' ),
			'user_email'     => __( 'Attendee email (the buyer\'s when Tickera does not ask for it)', 'epasscard' ),
			'buyer_name'     => __( 'Buyer name', 'epasscard' ),
			'buyer_email'    => __( 'Buyer email', 'epasscard' ),
			'event_name'     => __( 'Event', 'epasscard' ),
			'event_start'    => __( 'Starts (date and time)', 'epasscard' ),
			'event_date'     => __( 'Date', 'epasscard' ),
			'event_time'     => __( 'Time', 'epasscard' ),
			'event_end'      => __( 'Ends (date and time)', 'epasscard' ),
			'event_location' => __( 'Location', 'epasscard' ),
			'event_url'      => __( 'Event page', 'epasscard' ),
			'ticket_type'    => __( 'Ticket type', 'epasscard' ),
			'ticket_price'   => __( 'Ticket price', 'epasscard' ),
			'ticket_code'    => __( 'Ticket code (scanned by Tickera\'s check-in apps)', 'epasscard' ),
			'order_number'   => __( 'Order number', 'epasscard' ),
			'order_status'   => __( 'Order status', 'epasscard' ),
			'checked_in'     => __( 'Checked in (date and time of the last check-in)', 'epasscard' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Status rules
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_tickera_status_rules';
	}

	/**
	 * @inheritDoc
	 */
	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * Tickera order statuses.
	 *
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		return array(
			'order_paid'      => __( 'Paid', 'epasscard' ),
			'order_received'  => __( 'Received (waiting for payment)', 'epasscard' ),
			'order_cancelled' => __( 'Cancelled', 'epasscard' ),
			'order_refunded'  => __( 'Refunded', 'epasscard' ),
			'order_fraud'     => __( 'Fraud', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_status_rules() {
		return array(
			'order_paid'      => 'sync',
			'order_received'  => 'none',
			'order_cancelled' => 'revoke',
			'order_refunded'  => 'revoke',
			'order_fraud'     => 'revoke',
		);
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
				'heading'      => __( 'Tickets by order status', 'epasscard' ),
				'description'  => __( 'Choose what happens to the wallet ticket when a Tickera order is paid, cancelled or refunded. Tickera\'s check-in apps only accept tickets of paid orders. The day after the event ends, its tickets are expired.', 'epasscard' ),
				'status_label' => __( 'Order status', 'epasscard' ),
			)
		);
		$this->render_push_notification_settings();
	}

	/* -------------------------------------------------------------------------
	 * Items: events
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		$ids = get_posts(
			array(
				'post_type'      => 'tc_events',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'label' => self::plain_title( (int) $id ),
			);
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$entity_id = absint( $entity_id );
		return 'tc_events' === get_post_type( $entity_id ) ? self::plain_title( $entity_id ) : '#' . $entity_id;
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
		return __( 'No Tickera events yet.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'post-new.php?post_type=tc_events' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Tickera events', 'epasscard' );
	}

	/* -------------------------------------------------------------------------
	 * Tickets
	 * ---------------------------------------------------------------------- */

	/**
	 * Ticket data; null when the ticket, or its Tickera order, does not exist or is in the trash.
	 *
	 * @param int $ticket_id Ticket (tc_tickets_instances post) id.
	 * @return array<string, mixed>|null Keys: ticket, order (WP_Post), event_id, ticket_type_id,
	 *                                   status (order status), start, end (Unix time, 0 when unset).
	 */
	public function resolve_ticket( $ticket_id ) {
		$ticket = get_post( absint( $ticket_id ) );
		if ( ! $ticket instanceof WP_Post || 'tc_tickets_instances' !== $ticket->post_type || 'publish' !== $ticket->post_status ) {
			return null;
		}
		$order = $ticket->post_parent > 0 ? get_post( (int) $ticket->post_parent ) : null;
		if ( ! $order instanceof WP_Post || 'tc_orders' !== $order->post_type || 'trash' === $order->post_status ) {
			return null;
		}
		$type_id  = absint( get_post_meta( $ticket->ID, 'ticket_type_id', true ) );
		$event_id = absint( get_post_meta( $ticket->ID, 'event_id', true ) );
		if ( $event_id <= 0 && $type_id > 0 ) {
			$event_id = absint( get_post_meta( $type_id, 'event_name', true ) );
		}
		return array(
			'ticket'         => $ticket,
			'order'          => $order,
			'event_id'       => $event_id,
			'ticket_type_id' => $type_id,
			'status'         => (string) $order->post_status,
			'start'          => self::local_timestamp( (string) get_post_meta( $event_id, 'event_date_time', true ) ),
			'end'            => self::local_timestamp( (string) get_post_meta( $event_id, 'event_end_date_time', true ) ),
		);
	}

	/**
	 * Unix time of a Tickera event time (the site's local time, "Y-m-d H:i").
	 *
	 * @param string $local Local date and time.
	 * @return int 0 when empty or not a date.
	 */
	public static function local_timestamp( $local ) {
		$local = trim( (string) $local );
		if ( '' === $local || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $local ) ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $local, wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}
		return (int) $date->getTimestamp();
	}

	/**
	 * Whether the event ended before today (site time zone). Without an end time, its start counts.
	 *
	 * @param array<string, mixed> $record Resolved ticket.
	 * @return bool
	 */
	public function has_ended( array $record ) {
		$end = (int) $record['end'] > 0 ? (int) $record['end'] : (int) $record['start'];
		return $end > 0 && wp_date( 'Y-m-d', $end ) < wp_date( 'Y-m-d' );
	}

	/**
	 * Ticket ids of an order.
	 *
	 * @param int $order_id Order post id.
	 * @return array<int, int>
	 */
	public static function order_ticket_ids( $order_id ) {
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'tc_tickets_instances',
					'post_status'    => 'any',
					'post_parent'    => absint( $order_id ),
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'transition_post_status', array( $this, 'on_post_status' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ), 20, 1 );
		add_action( 'added_post_meta', array( $this, 'on_post_meta' ), 20, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_post_meta' ), 20, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_post_meta' ), 20, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 20, 3 );
		add_action( self::REFRESH_HOOK, array( $this, 'refresh_event' ), 10, 2 );
		add_filter( 'tickera_front_ticket_table_columns', array( $this, 'add_wallet_column' ), 20 );
	}

	/**
	 * An order or ticket changed status (created, paid, cancelled, trashed, restored).
	 *
	 * @param string       $new_status New status.
	 * @param string       $old_status Old status.
	 * @param WP_Post|null $post       Post.
	 * @return void
	 */
	public function on_post_status( $new_status, $old_status, $post ) {
		unset( $new_status, $old_status );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'tc_orders' === $post->post_type ) {
			$this->queued_orders[ (int) $post->ID ] = true;
			$this->schedule_flush();
		} elseif ( 'tc_tickets_instances' === $post->post_type ) {
			$this->queue( (int) $post->ID );
		}
	}

	/**
	 * An order or ticket is being deleted for good.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_post_delete( $post_id ) {
		$type = get_post_type( (int) $post_id );
		if ( 'tc_tickets_instances' === $type ) {
			$this->queue( (int) $post_id );
		} elseif ( 'tc_orders' === $type ) {
			foreach ( self::order_ticket_ids( (int) $post_id ) as $ticket_id ) {
				$this->queue( $ticket_id );
			}
			$this->sweep_queued = true;
			$this->schedule_flush();
		}
	}

	/**
	 * A ticket's attendee or check-ins changed, or an event's date or place.
	 *
	 * @param int|array<int, int> $meta_id   Meta id(s).
	 * @param int                 $object_id Post id.
	 * @param string              $meta_key  Meta key.
	 * @return void
	 */
	public function on_post_meta( $meta_id, $object_id, $meta_key ) {
		unset( $meta_id );
		$meta_key = (string) $meta_key;
		if ( in_array( $meta_key, array( 'first_name', 'last_name', 'owner_email', 'tc_checkins' ), true ) ) {
			if ( 'tc_tickets_instances' === get_post_type( (int) $object_id ) ) {
				$this->queue( (int) $object_id, 'tc_checkins' !== $meta_key );
			}
		} elseif ( in_array( $meta_key, array( 'event_date_time', 'event_end_date_time', 'event_location' ), true ) ) {
			if ( 'tc_events' === get_post_type( (int) $object_id ) ) {
				self::schedule_refresh( (int) $object_id );
			}
		}
	}

	/**
	 * An event or ticket type was renamed: refresh the event's cards.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 * @return void
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || $after->post_title === $before->post_title ) {
			return;
		}
		if ( 'tc_events' === $after->post_type ) {
			self::schedule_refresh( (int) $post_id );
		} elseif ( 'tc_tickets' === $after->post_type ) {
			self::schedule_refresh( absint( get_post_meta( (int) $post_id, 'event_name', true ) ) );
		}
	}

	/**
	 * Queue a ticket for the end of the request.
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param bool $full      False when only its check-ins changed.
	 * @return void
	 */
	public function queue( $ticket_id, $full = true ) {
		$ticket_id = absint( $ticket_id );
		if ( $ticket_id <= 0 ) {
			return;
		}
		$this->queued[ $ticket_id ] = ! empty( $this->queued[ $ticket_id ] ) || (bool) $full;
		$this->schedule_flush();
	}

	/**
	 * Run the queue once, at the end of the request (after Tickera has saved everything).
	 *
	 * @return void
	 */
	private function schedule_flush() {
		if ( ! has_action( 'shutdown', array( $this, 'flush' ) ) ) {
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
	}

	/**
	 * Handle the queued orders and tickets.
	 *
	 * @return void
	 */
	public function flush() {
		$orders              = array_keys( $this->queued_orders );
		$tickets             = $this->queued;
		$sweep               = $this->sweep_queued;
		$this->queued_orders = array();
		$this->queued        = array();
		$this->sweep_queued  = false;
		foreach ( $orders as $order_id ) {
			foreach ( self::order_ticket_ids( (int) $order_id ) as $ticket_id ) {
				$tickets[ $ticket_id ] = true;
			}
		}
		foreach ( $tickets as $ticket_id => $full ) {
			$this->handle_ticket( (int) $ticket_id, (bool) $full );
		}
		if ( $sweep ) {
			$this->void_orphans();
		}
	}

	/**
	 * Apply the order's status rule to the ticket's card.
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param bool $full      False when only its check-ins changed: then only an existing card
	 *                        whose design shows the check-in is updated.
	 * @return void
	 */
	public function handle_ticket( $ticket_id, $full = true ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$source_id = (string) absint( $ticket_id );
		$record    = $this->resolve_ticket( $ticket_id );
		if ( null === $record ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
			return;
		}
		$action = $this->get_status_rule( (string) $record['status'] );
		if ( 'revoke' === $action ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
			return;
		}
		if ( ! in_array( $action, array( 'sync', 'update' ), true ) || $this->has_ended( $record ) ) {
			return; // A finished event's card stays expired; none is made for it.
		}
		$existing = EPC_DB::get_pass( $this->get_slug(), $source_id );
		$has_card = $existing && ! empty( $existing->pass_uid );
		if ( 'update' === $action && ! $has_card ) {
			return;
		}
		if ( ! $full && ( ! $has_card || ! $this->design_shows_check_in( $record ) ) ) {
			return;
		}
		// A failure is recorded by the pass service and shown on Cards.
		$this->sync_by_source_id( $source_id, $action );
	}

	/**
	 * Whether the event's design shows the "Checked in" field.
	 *
	 * @param array<string, mixed> $record Resolved ticket.
	 * @return bool
	 */
	public function design_shows_check_in( array $record ) {
		$mapping = $this->get_mapping( (int) $record['event_id'] );
		if ( empty( $mapping['template_uid'] ) ) {
			return false;
		}
		$values = $this->build_values( $record );
		$a      = EPC_Pass_Service::build_update_fields( $mapping, array_merge( $values, array( 'checked_in' => 'a' ) ), $this->get_slug() );
		$b      = EPC_Pass_Service::build_update_fields( $mapping, array_merge( $values, array( 'checked_in' => 'b' ) ), $this->get_slug() );
		return $a !== $b;
	}

	/**
	 * Void live cards whose ticket no longer exists.
	 *
	 * @return void
	 */
	public function void_orphans() {
		foreach ( EPC_DB::get_active_passes_for_module( $this->get_slug() ) as $row ) {
			if ( null === $this->resolve_ticket( absint( $row->source_id ) ) ) {
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $row->source_id );
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Event changes: refresh its cards in the background
	 * ---------------------------------------------------------------------- */

	/**
	 * Refresh the event's cards shortly (once, however many fields changed).
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function schedule_refresh( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id > 0 && ! wp_next_scheduled( self::REFRESH_HOOK, array( $event_id, 0 ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REFRESH_HOOK, array( $event_id, 0 ) );
		}
	}

	/**
	 * Update the live cards of an event, a batch at a time.
	 *
	 * @param int $event_id Event id.
	 * @param int $after_id Pass row id to continue after.
	 * @return void
	 */
	public function refresh_event( $event_id, $after_id = 0 ) {
		$event_id = absint( $event_id );
		$after_id = absint( $after_id );
		if ( $event_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$done = 0;
		while ( $done < self::REFRESH_BATCH ) {
			$rows = EPC_DB::get_active_passes_for_module( $this->get_slug(), $after_id, 200 );
			if ( empty( $rows ) ) {
				return;
			}
			foreach ( $rows as $row ) {
				$after_id = (int) $row->id;
				if ( (int) $row->entity_id !== $event_id ) {
					continue;
				}
				$this->handle_ticket( absint( $row->source_id ) );
				++$done;
				if ( $done >= self::REFRESH_BATCH ) {
					break;
				}
			}
		}
		wp_schedule_single_event( time() + 30, self::REFRESH_HOOK, array( $event_id, $after_id ) );
	}

	/* -------------------------------------------------------------------------
	 * Cards
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$record = ctype_digit( (string) $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		if ( null === $record ) {
			return new WP_Error( 'epc_invalid_source', __( 'Tickera ticket not found.', 'epasscard' ) );
		}
		$mapping = $this->get_mapping( (int) $record['event_id'] );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for this item yet.', 'epasscard' ) );
		}
		$recipient = $this->recipient( $record );
		return EPC_Pass_Service::sync_pass( $this->get_slug(), (string) $source_id, (int) $record['event_id'], $recipient['user_id'], $mapping, $this->build_values( $record ), $mode );
	}

	/**
	 * Who gets the card: the attendee's email when Tickera asked for it, else the buyer's. The
	 * card is linked to the WordPress account with that email, if there is one.
	 *
	 * @param array<string, mixed> $record Resolved ticket.
	 * @return array{user_id: int, email: string, name: string} user_id 0 = no WordPress account.
	 */
	public function recipient( array $record ) {
		$ticket = $record['ticket'];
		$owner  = sanitize_email( (string) get_post_meta( $ticket->ID, 'owner_email', true ) );
		if ( '' !== $owner && is_email( $owner ) ) {
			$user = get_user_by( 'email', $owner );
			return array(
				'user_id' => $user ? (int) $user->ID : 0,
				'email'   => $owner,
				'name'    => (string) get_post_meta( $ticket->ID, 'first_name', true ),
			);
		}
		// The buyer's account is found by email: Tickera's order author changes to whoever
		// saves the order in wp-admin.
		$buyer = self::buyer( $record['order'] );
		$user  = '' !== $buyer['email'] ? get_user_by( 'email', $buyer['email'] ) : false;
		return array(
			'user_id' => $user ? (int) $user->ID : 0,
			'email'   => $buyer['email'],
			'name'    => $buyer['first_name'],
		);
	}

	/**
	 * Buyer of an order.
	 *
	 * @param WP_Post $order Order post.
	 * @return array{first_name: string, last_name: string, email: string}
	 */
	public static function buyer( $order ) {
		$info  = get_post_meta( $order->ID, 'tc_cart_info', true );
		$buyer = is_array( $info ) && isset( $info['buyer_data'] ) && is_array( $info['buyer_data'] ) ? $info['buyer_data'] : array();
		$email = sanitize_email( (string) ( $buyer['email_post_meta'] ?? '' ) );
		return array(
			'first_name' => (string) ( $buyer['first_name_post_meta'] ?? '' ),
			'last_name'  => (string) ( $buyer['last_name_post_meta'] ?? '' ),
			'email'      => is_email( $email ) ? $email : '',
		);
	}

	/**
	 * Field values of a card.
	 *
	 * @param array<string, mixed> $record Resolved ticket.
	 * @return array<string, string>
	 */
	public function build_values( array $record ) {
		global $tc;
		$ticket   = $record['ticket'];
		$order    = $record['order'];
		$event_id = (int) $record['event_id'];
		$buyer    = self::buyer( $order );
		$owner    = sanitize_email( (string) get_post_meta( $ticket->ID, 'owner_email', true ) );
		$date     = (string) get_option( 'date_format' );
		$time     = (string) get_option( 'time_format' );
		$start    = (int) $record['start'];
		$end      = (int) $record['end'];
		$statuses = $this->get_pass_behavior_statuses();
		$status   = (string) $record['status'];
		$price    = (string) get_post_meta( $ticket->ID, 'ticket_total', true );
		$location = (string) get_post_meta( $event_id, 'event_location', true );

		return array(
			'user_full_name' => trim( get_post_meta( $ticket->ID, 'first_name', true ) . ' ' . get_post_meta( $ticket->ID, 'last_name', true ) ),
			'user_email'     => '' !== $owner && is_email( $owner ) ? $owner : $buyer['email'],
			'buyer_name'     => trim( $buyer['first_name'] . ' ' . $buyer['last_name'] ),
			'buyer_email'    => $buyer['email'],
			'event_name'     => $event_id > 0 ? self::plain_title( $event_id ) : '',
			'event_start'    => $start ? wp_date( $date . ' ' . $time, $start ) : '',
			'event_date'     => $start ? wp_date( $date, $start ) : '',
			'event_time'     => $start ? wp_date( $time, $start ) : '',
			'event_end'      => $end ? wp_date( $date . ' ' . $time, $end ) : '',
			'event_location' => trim( html_entity_decode( wp_strip_all_tags( $location ), ENT_QUOTES, 'UTF-8' ) ),
			'event_url'      => $event_id > 0 && get_permalink( $event_id ) ? (string) get_permalink( $event_id ) : '',
			'ticket_type'    => (int) $record['ticket_type_id'] > 0 ? self::plain_title( (int) $record['ticket_type_id'] ) : '',
			'ticket_price'   => '' !== $price && is_object( $tc ) && method_exists( $tc, 'get_cart_currency_and_format' ) ? wp_strip_all_tags( html_entity_decode( (string) $tc->get_cart_currency_and_format( $price ), ENT_QUOTES, 'UTF-8' ) ) : $price,
			'ticket_code'    => (string) get_post_meta( $ticket->ID, 'ticket_code', true ),
			'order_number'   => (string) $order->post_title,
			'order_status'   => isset( $statuses[ $status ] ) ? (string) $statuses[ $status ] : $status,
			'checked_in'     => self::last_check_in( $ticket->ID ),
		);
	}

	/**
	 * Date and time of the ticket's last successful check-in, as Tickera shows it.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return string "Not yet" when it has none.
	 */
	public static function last_check_in( $ticket_id ) {
		// Read the meta directly: Tickera's own reader may rewrite it (legacy check-outs).
		$records = get_post_meta( absint( $ticket_id ), 'tc_checkins', true );
		$last    = 0;
		foreach ( is_array( $records ) ? $records : array() as $check_in ) {
			if ( ! is_array( $check_in ) || 'out' === ( $check_in['direction'] ?? 'in' ) || 'Pass' !== (string) ( $check_in['status'] ?? '' ) ) {
				continue;
			}
			$last = max( $last, (int) ( $check_in['date_checked'] ?? 0 ) );
		}
		// Tickera stores the local time as a Unix number (see tickera_timestamp_to_local()).
		return $last > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) : __( 'Not yet', 'epasscard' );
	}

	/**
	 * Post title without markup or HTML entities.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function plain_title( $post_id ) {
		return trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A card without a WordPress account: it goes to the attendee's (or buyer's) email.
	 *
	 * @inheritDoc
	 */
	public function get_guest_recipient( $source_id ) {
		$record = ctype_digit( (string) $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		if ( null === $record ) {
			return null;
		}
		$to = $this->recipient( $record );
		if ( $to['user_id'] > 0 || ! is_email( $to['email'] ) ) {
			return null;
		}
		return array(
			'email' => $to['email'],
			'name'  => $to['name'],
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_record_customer( $source_id ) {
		$record = ctype_digit( (string) $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		if ( null === $record ) {
			return null;
		}
		$values = $this->build_values( $record );
		return array(
			'name'  => '' !== $values['user_full_name'] ? $values['user_full_name'] : $values['buyer_name'],
			'email' => $this->recipient( $record )['email'],
		);
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cards of the user's tickets.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM %i WHERE module = %s AND user_id = %d ORDER BY id DESC LIMIT 50', EPC_DB::table_name(), $this->get_slug(), $user_id ) );
		return array_map( 'strval', (array) $ids );
	}

	/* -------------------------------------------------------------------------
	 * Tickera's order page: "Add to wallet" per ticket
	 * ---------------------------------------------------------------------- */

	/**
	 * Add a Wallet column to the tickets table on Tickera's order page.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function add_wallet_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		foreach ( $columns as $column ) {
			if ( is_array( $column ) && 'epasscard_wallet' === ( $column['id'] ?? '' ) ) {
				return $columns;
			}
		}
		$columns[] = array(
			'id'                => 'epasscard_wallet',
			'field_name'        => 'ticket_code',
			'field_title'       => __( 'Wallet', 'epasscard' ),
			'field_type'        => 'function',
			'function'          => array( $this, 'render_wallet_cell' ),
			'field_description' => '',
			'post_field_type'   => 'post_meta',
		);
		return $columns;
	}

	/**
	 * Wallet cell of one ticket: the link when it has a live card.
	 *
	 * @param string $field_name Field name.
	 * @param string $field_id   Field id.
	 * @param int    $ticket_id  Ticket id.
	 * @return void
	 */
	public function render_wallet_cell( $field_name = '', $field_id = '', $ticket_id = 0 ) {
		unset( $field_name, $field_id );
		$pass = EPC_DB::get_pass( $this->get_slug(), (string) absint( $ticket_id ) );
		if ( ! $pass || empty( $pass->pass_link ) || 'active' !== (string) $pass->status ) {
			return;
		}
		echo '<a class="epc-tickera-wallet" href="' . esc_url( (string) $pass->pass_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Add to wallet', 'epasscard' ) . '</a>';
	}

	/* -------------------------------------------------------------------------
	 * Daily: expiry, deleted tickets, reminder
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
	public function get_default_notification_rules() {
		return array(
			'before_event_start' => array(
				'enabled' => false,
				'days'    => 1,
				'title'   => __( 'See you soon', 'epasscard' ),
				'message' => __( '{event_name} starts {event_start}. Your ticket is in your wallet.', 'epasscard' ),
			),
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
	 * The daily check also expires tickets of finished events and voids deleted ones, so it
	 * runs even with the reminder off.
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
		$source_id = (string) $pass_row->source_id;
		$record    = ctype_digit( $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		if ( null === $record ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
			return;
		}
		if ( $this->has_ended( $record ) ) {
			// The day after the event: the ticket shows as expired in the wallet.
			EPC_Pass_Service::expire_issued_pass( $this->get_slug(), $source_id );
			return;
		}
		EPC_Pass_Notifications::maybe_send_for_event(
			$pass_row,
			'before_event_start',
			$rules['before_event_start'] ?? array(),
			(int) $record['start'],
			function () use ( $record ) {
				return $this->build_values( $record );
			}
		);
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		$source_id = is_object( $pass_row ) ? (string) $pass_row->source_id : '';
		$record    = ctype_digit( $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		return null !== $record ? $this->build_values( $record ) : array();
	}

	/* -------------------------------------------------------------------------
	 * Existing tickets
	 * ---------------------------------------------------------------------- */

	/**
	 * Tickets of an event that would get a card (order status gives a card, event not over),
	 * oldest first.
	 *
	 * @param int $entity_id Event id.
	 * @param int $limit     Batch size.
	 * @param int $offset    Offset.
	 * @return array<int, string>
	 */
	public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
		$ids = get_posts(
			array(
				'post_type'      => 'tc_tickets_instances',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Tickera keeps the event on the ticket's meta.
				'meta_query'     => array(
					array(
						'key'   => 'event_id',
						'value' => absint( $entity_id ),
					),
				),
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$record = $this->resolve_ticket( (int) $id );
			if ( null === $record || 'sync' !== $this->get_status_rule( (string) $record['status'] ) || $this->has_ended( $record ) ) {
				continue;
			}
			$out[] = (string) $id;
		}
		return array_slice( $out, absint( $offset ), absint( $limit ) );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_scope_label() {
		return __( 'Tickets of upcoming events whose order status gives a card', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_user_id( $source_id ) {
		$record = ctype_digit( (string) $source_id ) ? $this->resolve_ticket( (int) $source_id ) : null;
		return null !== $record ? $this->recipient( $record )['user_id'] : 0;
	}
}
