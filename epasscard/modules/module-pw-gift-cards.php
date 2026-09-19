<?php
/**
 * PW WooCommerce Gift Cards integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for PW gift cards.
 */
class EPC_Module_PW_Gift_Cards extends EPC_Module {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'pw-gift-cards';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'PW Gift Cards', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'PW WooCommerce Gift Cards', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_pw_gift_cards_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'PW WooCommerce Gift Cards is not installed or activated. Install PW Gift Cards (and WooCommerce), activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name' => __( 'Display name', 'epasscard' ),
			'user_email'        => __( 'Email (recipient)', 'epasscard' ),
			'user_first_name'   => __( 'First name', 'epasscard' ),
			'user_last_name'    => __( 'Last name', 'epasscard' ),
			'user_full_name'    => __( 'Full name', 'epasscard' ),
			'card_id'           => __( 'Gift card ID', 'epasscard' ),
			'card_number'       => __( 'Gift card code', 'epasscard' ),
			'balance'           => __( 'Balance', 'epasscard' ),
			'balance_formatted' => __( 'Balance (formatted)', 'epasscard' ),
			'card_status'       => __( 'Card status', 'epasscard' ),
			'create_date'       => __( 'Created date', 'epasscard' ),
			'expire_date'       => __( 'Expiration date', 'epasscard' ),
			'product_id'        => __( 'Product ID', 'epasscard' ),
			'product_name'      => __( 'Product name', 'epasscard' ),
			'order_id'          => __( 'Order ID', 'epasscard' ),
			'recipient_email'   => __( 'Recipient email', 'epasscard' ),
			'from_name'         => __( 'From name', 'epasscard' ),
			'message'           => __( 'Gift message', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_pw_gift_cards_status_rules';
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
		return $this->get_configurable_card_statuses();
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
			'active'   => 'sync',
			'inactive' => 'revoke',
			'expired'  => 'revoke',
		);
	}

	/**
	 * Configurable statuses for UI.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_card_statuses() {
		return array(
			'active'   => __( 'Active', 'epasscard' ),
			'inactive' => __( 'Inactive / deactivated', 'epasscard' ),
			'expired'  => __( 'Expired', 'epasscard' ),
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
	 * @param string $status Status key.
	 * @return string
	 */
	public function get_status_rule( $status ) {
		$rules = $this->get_status_rules();

		return $rules[ (string) $status ] ?? 'none';
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}

		$statuses = $this->get_configurable_card_statuses();
		$actions  = $this->get_status_action_options();
		$rules    = $this->get_status_rules();
		?>
		<div id="epc-section-pass-behavior" class="epc-section epc-section--status-rules">
			<h2><?php esc_html_e( 'Pass behavior by gift card status', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose what happens to the wallet pass when a gift card is created, updated, deactivated, or expires.', 'epasscard' ); ?>
			</p>
			<form class="epc-ajax-form" data-epc-action="<?php echo esc_attr( 'epc_save_status_rules_' . $this->get_slug() ); ?>" method="post" action="">
				<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
				<table class="widefat striped epc-status-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Card status', 'epasscard' ); ?></th>
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
			'before_card_expire' => __( 'Before gift card expires', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'before_card_expire' => array(
				'enabled' => false,
				'days'    => 7,
				'title'   => __( 'Gift card expiring soon', 'epasscard' ),
				'message' => __( 'Your {product_name} gift card ({card_number}) expires on {expire_date}. Balance: {balance_formatted}.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array(
			'product_name',
			'card_number',
			'expire_date',
			'balance_formatted',
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
			$card = $this->get_card( (int) $pass_row->source_id );
			if ( ! $card ) {
				continue;
			}

			$exp = $card->get_expiration_date();
			if ( empty( $exp ) ) {
				continue;
			}

			$expire_ts = strtotime( (string) $exp );
			if ( ! $expire_ts ) {
				continue;
			}

			EPC_Pass_Notifications::maybe_send_for_event(
				$pass_row,
				'before_card_expire',
				$rules['before_card_expire'] ?? array(),
				(int) $expire_ts,
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

		$card = $this->get_card( (int) $pass_row->source_id );
		if ( ! $card ) {
			return array();
		}

		$meta       = $this->get_card_order_meta( $card );
		$product_id = $this->resolve_product_id( $card, $meta );

		return array(
			'product_name'      => $product_id ? get_the_title( $product_id ) : '',
			'card_number'       => (string) $card->get_number(),
			'expire_date'       => epc_format_pass_expiry_datetime( (string) $card->get_expiration_date() ),
			'balance_formatted' => $this->format_money( (float) $card->get_balance() ),
			'user_full_name'    => isset( $meta['from_name'] ) ? (string) $meta['from_name'] : '',
			'user_email'        => isset( $meta['recipient_email'] ) ? (string) $meta['recipient_email'] : '',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$type = defined( 'PWGC_PRODUCT_TYPE_SLUG' ) ? PWGC_PRODUCT_TYPE_SLUG : 'pw-gift-card';
		$products = wc_get_products(
			array(
				'type'   => $type,
				'limit'  => 200,
				'status' => array( 'publish', 'private' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		if ( ! is_array( $products ) ) {
			return array();
		}

		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $product->get_id(),
				'label' => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : ( '#' . (int) $product->get_id() ),
			);
		}

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Gift card product', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create a PW Gift Card product in WooCommerce, then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'post-new.php?post_type=product' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Create gift card product', 'epasscard' );
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
		add_action( 'pwgc_activity_create', array( $this, 'on_card_activity' ), 20, 1 );
		add_action( 'pwgc_activity_transaction', array( $this, 'on_card_activity' ), 20, 1 );
		add_action( 'pwgc_activity_deactivate', array( $this, 'on_card_activity' ), 20, 1 );
		add_action( 'pwgc_activity_reactivate', array( $this, 'on_card_activity' ), 20, 1 );
		add_action( 'pwgc_property_updated_active', array( $this, 'on_active_property_updated' ), 20, 1 );
	}

	/**
	 * Activity hook (create / transaction / deactivate / reactivate).
	 *
	 * @param mixed $gift_card Card object.
	 * @return void
	 */
	public function on_card_activity( $gift_card ) {
		if ( ! $gift_card instanceof PW_Gift_Card ) {
			return;
		}
		$this->handle_card( $gift_card );
	}

	/**
	 * Active flag changed.
	 *
	 * @param mixed $gift_card Card object.
	 * @return void
	 */
	public function on_active_property_updated( $gift_card ) {
		$this->on_card_activity( $gift_card );
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$card = $this->get_card( absint( $source_id ) );
		if ( ! $card ) {
			return new WP_Error( 'epc_invalid_source', __( 'Gift card not found.', 'epasscard' ) );
		}

		return $this->sync_card( $card, $mode );
	}

	/**
	 * Apply status rule then sync/revoke.
	 *
	 * @param PW_Gift_Card $card Card.
	 * @return void
	 */
	private function handle_card( $card ) {
		if ( ! EPC_Api_Client::is_configured() || ! $card instanceof PW_Gift_Card ) {
			return;
		}

		$status = $this->normalize_card_status( $card );
		$action = $this->get_status_rule( $status );

		switch ( $action ) {
			case 'sync':
				$this->sync_card( $card, 'sync' );
				break;
			case 'update':
				$this->sync_card( $card, 'update' );
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), absint( $card->get_id() ) );
				break;
			default:
				break;
		}
	}

	/**
	 * Sync pass from PW gift card.
	 *
	 * @param PW_Gift_Card $card Card.
	 * @param string       $mode Mode.
	 * @return true|\WP_Error
	 */
	private function sync_card( $card, $mode = 'sync' ) {
		$card_id = absint( $card->get_id() );
		$meta    = $this->get_card_order_meta( $card );
		$product_id = $this->resolve_product_id( $card, $meta );

		if ( $card_id <= 0 || $product_id <= 0 ) {
			return new WP_Error( 'epc_invalid_source', __( 'Gift card not found or missing product.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $product_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this gift card product.', 'epasscard' ) );
		}

		$recipient = isset( $meta['recipient_email'] ) ? (string) $meta['recipient_email'] : '';
		$from_name = isset( $meta['from_name'] ) ? (string) $meta['from_name'] : '';
		$user_id   = 0;
		$first     = '';
		$last      = '';
		$display   = $from_name;

		if ( $recipient && is_email( $recipient ) ) {
			$user = get_user_by( 'email', $recipient );
			if ( $user ) {
				$user_id = (int) $user->ID;
				$first   = (string) get_user_meta( $user_id, 'first_name', true );
				$last    = (string) get_user_meta( $user_id, 'last_name', true );
				$display = (string) $user->display_name;
			}
		}

		$balance = (float) $card->get_balance();
		$status  = $this->normalize_card_status( $card );
		$expire  = (string) $card->get_expiration_date();

		$values = array(
			'user_display_name' => $display,
			'user_email'        => $recipient,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $display ? $display : $from_name ),
			'card_id'           => (string) $card_id,
			'card_number'       => (string) $card->get_number(),
			'balance'           => (string) $balance,
			'balance_formatted' => $this->format_money( $balance ),
			'card_status'       => $status,
			'create_date'       => $card->get_create_date() ? wp_date( get_option( 'date_format' ), strtotime( (string) $card->get_create_date() ) ) : '',
			'expire_date'       => epc_format_pass_expiry_datetime( $expire ),
			'product_id'        => (string) $product_id,
			'product_name'      => $this->get_entity_label( $product_id ),
			'order_id'          => isset( $meta['order_id'] ) ? (string) $meta['order_id'] : '',
			'recipient_email'   => $recipient,
			'from_name'         => $from_name,
			'message'           => isset( $meta['message'] ) ? (string) $meta['message'] : '',
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			$card_id,
			$product_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}

	/**
	 * Normalize status key.
	 *
	 * @param PW_Gift_Card $card Card.
	 * @return string
	 */
	private function normalize_card_status( $card ) {
		if ( method_exists( $card, 'has_expired' ) && $card->has_expired() ) {
			return 'expired';
		}

		return $card->get_active() ? 'active' : 'inactive';
	}

	/**
	 * Load card by ID.
	 *
	 * @param int $card_id Card ID.
	 * @return PW_Gift_Card|null
	 */
	private function get_card( $card_id ) {
		$card_id = absint( $card_id );
		if ( $card_id <= 0 || ! class_exists( 'PW_Gift_Card' ) ) {
			return null;
		}

		$card = PW_Gift_Card::get_by_id( $card_id );
		return $card instanceof PW_Gift_Card && $card->get_id() ? $card : null;
	}

	/**
	 * Resolve product ID from order item meta.
	 *
	 * @param PW_Gift_Card         $card Card.
	 * @param array<string, mixed> $meta Order meta.
	 * @return int
	 */
	private function resolve_product_id( $card, array $meta ) {
		if ( ! empty( $meta['product_id'] ) ) {
			return absint( $meta['product_id'] );
		}

		// Fallback: sole mapped / published PW gift card product.
		$entities = $this->get_mappable_entities();
		if ( 1 === count( $entities ) ) {
			return absint( $entities[0]['id'] );
		}

		unset( $card );
		return 0;
	}

	/**
	 * Recipient / product / order meta from originating order item.
	 *
	 * @param PW_Gift_Card $card Card.
	 * @return array<string, mixed>
	 */
	private function get_card_order_meta( $card ) {
		$meta = array(
			'recipient_email' => '',
			'from_name'       => '',
			'message'         => '',
			'product_id'      => 0,
			'order_id'        => 0,
		);

		if ( ! method_exists( $card, 'get_original_order_item_id' ) ) {
			return $meta;
		}

		$item_id = absint( $card->get_original_order_item_id() );
		if ( $item_id <= 0 || ! function_exists( 'wc_get_order_item_meta' ) ) {
			return $meta;
		}

		$to_key   = defined( 'PWGC_TO_META_KEY' ) ? PWGC_TO_META_KEY : 'pw_gift_card_to';
		$from_key = defined( 'PWGC_FROM_META_KEY' ) ? PWGC_FROM_META_KEY : 'pw_gift_card_from';
		$msg_key  = defined( 'PWGC_MESSAGE_META_KEY' ) ? PWGC_MESSAGE_META_KEY : 'pw_gift_card_message';

		$to = (string) wc_get_order_item_meta( $item_id, $to_key, true );
		// PW may store multiple emails separated by comma/space.
		if ( $to && preg_match( '/[^\s,]+/', $to, $m ) ) {
			$to = $m[0];
		}

		$meta['recipient_email'] = is_email( $to ) ? $to : $to;
		$meta['from_name']       = (string) wc_get_order_item_meta( $item_id, $from_key, true );
		$meta['message']         = (string) wc_get_order_item_meta( $item_id, $msg_key, true );

		if ( function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
			$meta['order_id'] = absint( wc_get_order_id_by_order_item_id( $item_id ) );
		}

		if ( class_exists( 'WC_Order_Item_Product' ) ) {
			try {
				$item = new WC_Order_Item_Product( $item_id );
				$meta['product_id'] = absint( $item->get_product_id() );
				if ( ! $meta['product_id'] ) {
					$meta['product_id'] = absint( $item->get_variation_id() );
				}
				// Prefer parent product for variable gift cards.
				$variation_id = absint( $item->get_variation_id() );
				if ( $variation_id > 0 ) {
					$parent = absint( wp_get_post_parent_id( $variation_id ) );
					if ( $parent > 0 ) {
						$meta['product_id'] = $parent;
					}
				}
				if ( ! $meta['order_id'] && method_exists( $item, 'get_order_id' ) ) {
					$meta['order_id'] = absint( $item->get_order_id() );
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				unset( $e );
			}
		}

		return $meta;
	}

	/**
	 * Format money with WC if available.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return (string) $amount;
	}
}
