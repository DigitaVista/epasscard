<?php
/**
 * WooCommerce tickets: a wallet ticket for each ticket product bought.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tickets sold as WooCommerce products (no events plugin needed). Event date and venue come from
 * the product's "Wallet card" settings.
 */
class EPC_Module_WooCommerce_Tickets extends EPC_Module_WC_Product_Cards {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'woocommerce-tickets';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WooCommerce tickets', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_card_type() {
		return 'ticket';
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet ticket for each ticket bought: products marked "Wallet card: Ticket" in WooCommerce.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_source_fields() {
		return array(
			'event_start' => __( 'Event date and time', 'epasscard' ),
			'venue'       => __( 'Venue', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_values( $item, $order, $product_id, $unit ) {
		unset( $item, $order, $unit );
		$start = (string) get_post_meta( $product_id, EPC_WC_Product_Cards::META_EVENT_START, true );
		$ts    = EPC_WC_Product_Cards::site_timestamp( $start );
		return array(
			'event_start' => $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '',
			'venue'       => (string) get_post_meta( $product_id, EPC_WC_Product_Cards::META_VENUE, true ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'before_event_start' => __( 'Before the event starts', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_type_description( $type ) {
		return 'before_event_start' === $type ? __( 'Uses the event date and time on the product.', 'epasscard' ) : '';
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
				'message' => __( '{product_name} starts {event_start}. Your ticket is in your wallet.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array( 'product_name', 'event_start', 'venue', 'user_full_name' );
	}

	/**
	 * @inheritDoc
	 */
	public function uses_batched_notifications() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function process_scheduled_notification_for_pass( $pass_row, array $rules ) {
		$start = (string) get_post_meta( absint( $pass_row->entity_id ), EPC_WC_Product_Cards::META_EVENT_START, true );
		$ts    = EPC_WC_Product_Cards::site_timestamp( $start );
		if ( $ts <= 0 ) {
			return;
		}
		EPC_Pass_Notifications::maybe_send_for_event(
			$pass_row,
			'before_event_start',
			$rules['before_event_start'] ?? array(),
			$ts,
			function () use ( $pass_row ) {
				return $this->build_push_replacements_for_pass( $pass_row );
			}
		);
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		if ( ! is_object( $pass_row ) ) {
			return array();
		}
		$record = $this->resolve( (string) $pass_row->source_id );
		if ( is_wp_error( $record ) ) {
			return array();
		}
		$values = $this->build_values( $record );
		return array(
			'product_name'   => $values['product_name'],
			'event_start'    => $values['event_start'],
			'venue'          => $values['venue'],
			'user_full_name' => $values['user_full_name'],
		);
	}
}
