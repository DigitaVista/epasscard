<?php
/**
 * WooCommerce product → wallet card: what the ticket, voucher and product-card integrations share.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues one card per unit bought of a WooCommerce product marked "Wallet card" with this
 * integration's kind (ticket, voucher or card). No other plugin needed.
 *
 * - Record: "i{order item id}_{unit}"; item: the product (parent of a variation).
 * - Owner: the order's customer; a guest order's card goes to the billing email.
 * - Order status → card by the status rules: Processing and Completed create the cards,
 *   Cancelled, Refunded and Failed void them (defaults; changeable per status).
 */
abstract class EPC_Module_WC_Product_Cards extends EPC_Module {

	/**
	 * Card kind: ticket | voucher | card.
	 *
	 * @return string
	 */
	abstract public function get_card_type();

	/**
	 * Fields only this kind has (source slug => label).
	 *
	 * @return array<string, string>
	 */
	abstract protected function get_type_source_fields();

	/**
	 * Values of those fields for one card.
	 *
	 * @param WC_Order_Item_Product $item       Line.
	 * @param WC_Order              $order      Order.
	 * @param int                   $product_id Product.
	 * @param int                   $unit       Unit.
	 * @return array<string, string>
	 */
	abstract protected function get_type_values( $item, $order, $product_id, $unit );

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'WooCommerce', 'epasscard' );
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
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_woocommerce_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'WooCommerce is not installed or activated. Activate WooCommerce, then mark products with "Wallet card" on the product edit screen.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array_merge(
			array(
				'user_display_name' => __( 'Buyer name', 'epasscard' ),
				'user_email'        => __( 'Buyer email', 'epasscard' ),
				'user_first_name'   => __( 'First name', 'epasscard' ),
				'user_last_name'    => __( 'Last name', 'epasscard' ),
				'user_full_name'    => __( 'Full name', 'epasscard' ),
				'product_id'        => __( 'Product ID', 'epasscard' ),
				'product_name'      => __( 'Product name', 'epasscard' ),
				'order_id'          => __( 'Order ID', 'epasscard' ),
				'order_number'      => __( 'Order number', 'epasscard' ),
				'order_date'        => __( 'Order date', 'epasscard' ),
				'unit_label'        => __( 'Number in the order (1 of 2)', 'epasscard' ),
				'price'             => __( 'Price paid', 'epasscard' ),
				'card_code'         => __( 'Card code', 'epasscard' ),
			),
			$this->get_type_source_fields()
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = wc_get_products(
			array(
				'limit'      => 200,
				'status'     => array( 'publish', 'private', 'draft', 'pending' ),
				'orderby'    => 'title',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Products marked for this kind of card.
				'meta_key'   => EPC_WC_Product_Cards::META_TYPE,
				'meta_value' => $this->get_card_type(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( is_array( $products ) ? $products : array() as $product ) {
			if ( $product instanceof WC_Product ) {
				$out[] = array(
					'id'    => (int) $product->get_id(),
					'label' => (string) $product->get_name(),
				);
			}
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$entity_id = absint( $entity_id );
		$title     = $entity_id ? get_the_title( $entity_id ) : '';
		return '' !== $title ? $title : ( '#' . $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Product', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'edit.php?post_type=product' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Open products', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __( 'Open a product in WooCommerce, tick "Wallet card" under General, choose the kind of card and update the product.', 'epasscard' );
	}

	/* -------------------------------------------------------------------------
	 * Status rules: order status → card
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_' . str_replace( '-', '_', $this->get_slug() ) . '_status_rules';
	}

	/**
	 * register_event_hooks() reads the status rules.
	 *
	 * @inheritDoc
	 */
	public function get_boot_option_keys() {
		return array( $this->get_status_rules_option_key() );
	}

	/**
	 * @inheritDoc
	 */
	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * Order statuses (slug without "wc-" => label).
	 *
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		$out = array();
		foreach ( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() as $status => $label ) {
			$out[ 0 === strpos( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status ] = (string) $label;
		}
		return $out;
	}

	/**
	 * Paid → card; cancelled, refunded or failed → void.
	 *
	 * @inheritDoc
	 */
	public function get_default_status_rules() {
		return array(
			'pending'    => 'none',
			'processing' => 'sync',
			'on-hold'    => 'none',
			'completed'  => 'sync',
			'cancelled'  => 'revoke',
			'refunded'   => 'revoke',
			'failed'     => 'revoke',
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
				'heading'      => __( 'Cards by order status', 'epasscard' ),
				'description'  => __( 'One card per unit bought. Choose what happens to the cards of an order when its status changes.', 'epasscard' ),
				'status_label' => __( 'Order status', 'epasscard' ),
			)
		);
		$this->render_push_notification_settings();
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		EPC_WC_Product_Cards::init();
		/*
		 * "woocommerce_order_status_{status}" runs before the order emails of the same change
		 * ("..._{from}_to_{to}"), so the cards exist when the email lists them.
		 */
		foreach ( array_keys( $this->get_status_rules() ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_order_status' ), 5, 2 );
		}
		add_filter( 'epc_pass_email_order_passes', array( $this, 'append_order_passes' ), 10, 2 );
	}

	/**
	 * An order reached a status: create, update or void the cards of its lines of this kind,
	 * as the status rule says.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 * @return void
	 */
	public function on_order_status( $order_id, $order = null ) {
		$status = substr( (string) current_filter(), strlen( 'woocommerce_order_status_' ) );
		$this->handle_order_status( $order instanceof WC_Order ? $order : wc_get_order( absint( $order_id ) ), $status );
	}

	/**
	 * Apply the rule of a status to an order's cards of this kind.
	 *
	 * @param WC_Order|false|null $order  Order.
	 * @param string              $status Status without "wc-".
	 * @return void
	 */
	public function handle_order_status( $order, $status ) {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$action = $this->get_status_rule( (string) $status );
		if ( 'none' === $action ) {
			return;
		}
		$no_design = array();
		foreach ( $this->order_source_ids( $order ) as $source_id ) {
			$result = $this->apply_action( $source_id, $action );
			if ( 'sync' === $action && is_wp_error( $result ) && 'epc_no_mapping' === $result->get_error_code() ) {
				$product_name = $this->report_missing_design( $source_id );
				if ( '' !== $product_name ) {
					$no_design[ $product_name ] = true;
				}
			}
		}
		if ( ! empty( $no_design ) ) {
			$this->add_missing_design_note( $order, array_keys( $no_design ) );
		}
	}

	/**
	 * Run a status-rule action on one card.
	 *
	 * @param string $source_id Record id.
	 * @param string $action    sync|update|revoke.
	 * @return array<string, mixed>|\WP_Error|null Result of the sync, null for other actions.
	 */
	protected function apply_action( $source_id, $action ) {
		switch ( $action ) {
			case 'sync':
			case 'update':
				// A failure is recorded by the pass service and shown on Cards.
				return $this->sync_by_source_id( $source_id, $action );
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), $source_id );
				break;
		}
		return null;
	}

	/**
	 * A paid line has no card because its kind has no design yet: list it under Cards → Need
	 * attention, where Retry creates the card once the design is saved.
	 *
	 * The pass service does not record "no design" (most integrations show it on their own
	 * screen), but here the merchant only ticked a box on the product, so nothing else says it.
	 *
	 * @param string $source_id Record id.
	 * @return string Product name, or '' when the line is gone.
	 */
	protected function report_missing_design( $source_id ) {
		$record = $this->resolve( $source_id );
		if ( is_wp_error( $record ) ) {
			return '';
		}
		$product_name = $this->get_entity_label( $record['product_id'] );
		EPC_Pass_Issues::record(
			$this->get_slug(),
			$source_id,
			'sync',
			new WP_Error(
				'epc_product_no_design',
				sprintf(
					/* translators: 1: product name, 2: integration name, e.g. "WooCommerce vouchers" */
					__( 'No wallet card for "%1$s": there is no %2$s design yet. Save one under EpassCard → Design, then retry.', 'epasscard' ),
					$product_name,
					$this->get_label()
				)
			)
		);
		return $product_name;
	}

	/**
	 * Say on the order, once per order and kind of card, that no card was made for lack of a design.
	 *
	 * @param WC_Order           $order         Order.
	 * @param array<int, string> $product_names Products without a card.
	 * @return void
	 */
	protected function add_missing_design_note( $order, array $product_names ) {
		$meta_key = '_epc_no_design_noted_' . $this->get_card_type();
		if ( '' !== (string) $order->get_meta( $meta_key ) ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: product names, 2: integration name, e.g. "WooCommerce vouchers" */
				__( 'EpassCard: no wallet card was made for %1$s because there is no %2$s design yet. Save one under EpassCard → Design, then use "Give cards to existing customers" or Retry under Cards → Need attention.', 'epasscard' ),
				implode( ', ', $product_names ),
				$this->get_label()
			)
		);
		$order->update_meta_data( $meta_key, (string) time() );
		$order->save_meta_data();
	}

	/**
	 * Record ids of this kind in an order: every unit of every line whose product has it.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, string>
	 */
	public function order_source_ids( $order ) {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			if ( EPC_WC_Product_Cards::get_type( $product_id ) !== $this->get_card_type() ) {
				continue;
			}
			$units = EPC_WC_Product_Cards::units( $item );
			for ( $unit = 1; $unit <= $units; $unit++ ) {
				$out[] = EPC_WC_Product_Cards::source_id( (int) $item_id, $unit );
			}
		}
		return $out;
	}

	/**
	 * The line, order and product of a record, or a WP_Error.
	 *
	 * @param string $source_id Record id.
	 * @return array{item: WC_Order_Item_Product, order: WC_Order, product_id: int, unit: int, units: int}|\WP_Error
	 */
	protected function resolve( $source_id ) {
		$parsed = EPC_WC_Product_Cards::parse_source_id( (string) $source_id );
		$item   = null === $parsed ? null : EPC_WC_Product_Cards::get_item( $parsed[0] );
		$order  = $item ? $item->get_order() : null;
		if ( null === $parsed || ! $item || ! $order instanceof WC_Order ) {
			return new WP_Error( 'epc_invalid_source', __( 'Order line not found.', 'epasscard' ) );
		}
		$product_id = EPC_WC_Product_Cards::parent_id( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
		$units      = EPC_WC_Product_Cards::units( $item );
		if ( EPC_WC_Product_Cards::get_type( $product_id ) !== $this->get_card_type() || $parsed[1] > $units ) {
			return new WP_Error( 'epc_invalid_source', __( 'This order line has no card of this kind.', 'epasscard' ) );
		}
		return array(
			'item'       => $item,
			'order'      => $order,
			'product_id' => $product_id,
			'unit'       => $parsed[1],
			'units'      => $units,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$record = $this->resolve( $source_id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$mapping = $this->get_mapping( $record['product_id'] );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for this product yet.', 'epasscard' ) );
		}

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			EPC_WC_Product_Cards::source_id( $record['item']->get_id(), $record['unit'] ),
			$record['product_id'],
			(int) $record['order']->get_customer_id(),
			$mapping,
			$this->build_values( $record ),
			$mode
		);
	}

	/**
	 * Field values of one card.
	 *
	 * @param array{item: WC_Order_Item_Product, order: WC_Order, product_id: int, unit: int, units: int} $record Record.
	 * @return array<string, string>
	 */
	protected function build_values( array $record ) {
		$item    = $record['item'];
		$order   = $record['order'];
		$user    = $order->get_customer_id() ? get_userdata( (int) $order->get_customer_id() ) : false;
		$first   = (string) $order->get_billing_first_name();
		$last    = (string) $order->get_billing_last_name();
		$display = trim( $first . ' ' . $last );
		if ( '' === $display && $user ) {
			$display = (string) $user->display_name;
		}
		$email   = (string) $order->get_billing_email();
		if ( '' === $email && $user ) {
			$email = (string) $user->user_email;
		}
		$created = $order->get_date_created();
		$qty     = max( 1, (int) $item->get_quantity() );
		$paid    = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty;

		return array_merge(
			array(
				'user_display_name' => $display,
				'user_email'        => $email,
				'user_first_name'   => $first,
				'user_last_name'    => $last,
				'user_full_name'    => epc_format_user_full_name( $first, $last, $display ),
				'product_id'        => (string) $record['product_id'],
				'product_name'      => (string) $item->get_name(),
				'order_id'          => (string) $order->get_id(),
				'order_number'      => (string) $order->get_order_number(),
				'order_date'        => $created ? wp_date( (string) get_option( 'date_format' ), $created->getTimestamp() ) : '',
				/* translators: 1: this card's number in the order line, 2: cards in the line */
				'unit_label'        => sprintf( __( '%1$d of %2$d', 'epasscard' ), $record['unit'], $record['units'] ),
				'price'             => EPC_WC_Product_Cards::money( $paid, (string) $order->get_currency() ),
				'card_code'         => EPC_WC_Product_Cards::code( $item, $record['unit'] ),
			),
			$this->get_type_values( $item, $order, $record['product_id'], $record['unit'] )
		);
	}

	/* -------------------------------------------------------------------------
	 * Guests, emails, the order screen
	 * ---------------------------------------------------------------------- */

	/**
	 * A guest order's card goes to its billing email.
	 *
	 * @inheritDoc
	 */
	public function get_guest_recipient( $source_id ) {
		$record = $this->resolve( (string) $source_id );
		if ( is_wp_error( $record ) || $record['order']->get_customer_id() ) {
			return null;
		}
		$email = (string) $record['order']->get_billing_email();
		return is_email( $email ) ? array(
			'email' => $email,
			'name'  => (string) $record['order']->get_billing_first_name(),
		) : null;
	}

	/**
	 * Name and email of the buyer, for lists that show a card without an account.
	 *
	 * @inheritDoc
	 */
	public function get_record_customer( $source_id ) {
		$record = $this->resolve( (string) $source_id );
		if ( is_wp_error( $record ) ) {
			return null;
		}
		$order = $record['order'];
		return array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => (string) $order->get_billing_email(),
		);
	}

	/**
	 * This order's cards in its WooCommerce emails (Settings → Emails → order emails).
	 *
	 * @param array<int, object> $passes Pass rows.
	 * @param WC_Order           $order  Order.
	 * @return array<int, object>
	 */
	public function append_order_passes( $passes, $order ) {
		$passes = is_array( $passes ) ? $passes : array();
		if ( ! $order instanceof WC_Order ) {
			return $passes;
		}
		foreach ( $this->order_source_ids( $order ) as $source_id ) {
			$pass = EPC_DB::get_pass( $this->get_slug(), $source_id );
			if ( $pass && ! empty( $pass->pass_link ) && 'active' === (string) $pass->status ) {
				$passes[] = $pass;
			}
		}
		return $passes;
	}

	/**
	 * This order's cards of this kind, one list item each with status and actions (for the
	 * "Wallet cards" box on the order screen).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function render_order_cards( $order ) {
		foreach ( $this->order_source_ids( $order ) as $source_id ) {
			$record = $this->resolve( $source_id );
			if ( is_wp_error( $record ) ) {
				continue;
			}
			$pass = EPC_DB::get_pass( $this->get_slug(), $source_id );
			echo '<li data-epc-source-id="' . esc_attr( $source_id ) . '" data-epc-module="' . esc_attr( $this->get_slug() ) . '">';
			echo '<strong>' . esc_html( $record['item']->get_name() ) . '</strong> ';
			/* translators: 1: card number in the order line, 2: cards in the line */
			echo esc_html( sprintf( __( '%1$d of %2$d', 'epasscard' ), $record['unit'], $record['units'] ) ) . '<br />';
			if ( $pass && ! empty( $pass->pass_uid ) ) {
				echo esc_html( 'active' === (string) $pass->status ? __( 'Card active', 'epasscard' ) : __( 'Card revoked', 'epasscard' ) );
			} else {
				esc_html_e( 'No card yet', 'epasscard' );
			}
			echo '<br />' . wp_kses( (string) $this->render_pass_action_links( $source_id ), self::get_pass_action_allowed_html() );
			echo '</li>';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		if ( parent::should_enqueue_pass_action_assets( $hook ) ) {
			return true;
		}
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( (string) $screen->id, array( 'shop_order', $order_screen ), true );
	}

	/* -------------------------------------------------------------------------
	 * Existing customers
	 * ---------------------------------------------------------------------- */

	/**
	 * Units bought of a product in orders whose status gives a card, oldest first. Paging is
	 * by unit: offset and limit count cards, not order lines.
	 *
	 * @inheritDoc
	 */
	public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
		$product_id = absint( $entity_id );
		if ( $product_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		if ( EPC_WC_Product_Cards::get_type( $product_id ) !== $this->get_card_type() ) {
			return array();
		}

		global $wpdb;
		$variations = array_map( 'absint', (array) get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation', 'fields' => 'ids', 'post_status' => 'any' ) ) );
		$ids        = array_merge( array( $product_id ), $variations );
		$in         = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Order lines of these products ($in is only %d placeholders).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_item_id, i.order_id FROM %i i
				INNER JOIN %i p ON p.order_item_id = i.order_item_id AND p.meta_key IN ('_product_id', '_variation_id') AND p.meta_value IN ($in)
				WHERE i.order_item_type = 'line_item'
				GROUP BY i.order_item_id, i.order_id
				ORDER BY i.order_item_id ASC",
				array_merge( array( $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta' ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$units  = array();
		$status = array();
		foreach ( (array) $rows as $row ) {
			$order_id = absint( $row->order_id );
			if ( ! isset( $status[ $order_id ] ) ) {
				$order               = wc_get_order( $order_id );
				$status[ $order_id ] = $order instanceof WC_Order && 'shop_order' === $order->get_type() ? $this->get_status_rule( $order->get_status() ) : 'none';
			}
			if ( 'sync' !== $status[ $order_id ] ) {
				continue;
			}
			$item = EPC_WC_Product_Cards::get_item( absint( $row->order_item_id ) );
			if ( ! $item ) {
				continue;
			}
			$count = EPC_WC_Product_Cards::units( $item );
			for ( $unit = 1; $unit <= $count; $unit++ ) {
				$units[] = EPC_WC_Product_Cards::source_id( (int) $row->order_item_id, $unit );
			}
		}
		return array_slice( $units, absint( $offset ), absint( $limit ) );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_scope_label() {
		return __( 'Buyers of these products in paid orders', 'epasscard' );
	}

	/**
	 * The order's customer; 0 for a guest (the card email goes to the billing email).
	 *
	 * @inheritDoc
	 */
	public function get_backfill_user_id( $source_id ) {
		$record = $this->resolve( (string) $source_id );
		return is_wp_error( $record ) ? 0 : (int) $record['order']->get_customer_id();
	}

	/**
	 * Later order lines first; within a line, by unit.
	 *
	 * @inheritDoc
	 */
	public function get_backfill_sort_key( $source_id ) {
		$parsed = EPC_WC_Product_Cards::parse_source_id( (string) $source_id );
		return null === $parsed ? 0 : $parsed[0] * 1000 + min( 999, $parsed[1] );
	}
}
