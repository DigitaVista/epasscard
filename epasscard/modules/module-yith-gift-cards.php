<?php
/**
 * YITH WooCommerce Gift Cards integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for YITH gift cards.
 */
class EPC_Module_YITH_Gift_Cards extends EPC_Module {

	/**
	 * Guard recursive meta hooks.
	 *
	 * @var bool
	 */
	private static bool $handling = false;

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'yith-gift-cards';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'YITH Gift Cards', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'YITH WooCommerce Gift Cards', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_yith_gift_cards_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'YITH WooCommerce Gift Cards is not installed or activated. Install YITH Gift Cards (and WooCommerce), activate it, then return here to map pass templates.',
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
			'amount'            => __( 'Original amount', 'epasscard' ),
			'card_status'       => __( 'Card status', 'epasscard' ),
			'expire_date'       => __( 'Expiration date', 'epasscard' ),
			'product_id'        => __( 'Product ID', 'epasscard' ),
			'product_name'      => __( 'Product name', 'epasscard' ),
			'order_id'          => __( 'Order ID', 'epasscard' ),
			'recipient_email'   => __( 'Recipient email', 'epasscard' ),
			'recipient_name'    => __( 'Recipient name', 'epasscard' ),
			'sender_name'       => __( 'Sender name', 'epasscard' ),
			'message'           => __( 'Gift message', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_yith_gift_cards_status_rules';
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
	 * Default pass behavior by post status.
	 *
	 * @return array<string, string>
	 */
	public function get_default_status_rules() {
		return array(
			'publish'         => 'sync',
			'ywgc-disabled'   => 'revoke',
			'ywgc-dismissed'  => 'revoke',
			'expired'         => 'revoke',
			'trash'           => 'revoke',
		);
	}

	/**
	 * Configurable statuses for UI.
	 *
	 * @return array<string, string>
	 */
	public function get_configurable_card_statuses() {
		return array(
			'publish'        => __( 'Enabled', 'epasscard' ),
			'ywgc-disabled'  => __( 'Disabled', 'epasscard' ),
			'ywgc-dismissed' => __( 'Dismissed', 'epasscard' ),
			'expired'        => __( 'Expired', 'epasscard' ),
			'trash'          => __( 'Trashed', 'epasscard' ),
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
				<?php esc_html_e( 'Choose what happens to the wallet pass when a gift card is issued, updated, disabled, or expires.', 'epasscard' ); ?>
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

			$expire_ts = ! empty( $card->expiration ) ? (int) $card->expiration : 0;
			if ( $expire_ts <= 0 ) {
				continue;
			}

			EPC_Pass_Notifications::maybe_send_for_event(
				$pass_row,
				'before_card_expire',
				$rules['before_card_expire'] ?? array(),
				$expire_ts,
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

		$recipient_name = (string) $card->recipient_name;
		$email          = (string) $card->recipient;

		return array(
			'product_name'      => ! empty( $card->product_id ) ? get_the_title( (int) $card->product_id ) : '',
			'card_number'       => method_exists( $card, 'get_code' ) ? (string) $card->get_code() : '',
			'expire_date'       => epc_format_pass_expiry_timestamp( ! empty( $card->expiration ) ? (int) $card->expiration : 0 ),
			'balance_formatted' => $this->format_money( (float) $card->get_balance() ),
			'user_full_name'    => $recipient_name ? $recipient_name : epc_format_user_full_name( '', '', $email ),
			'user_email'        => $email,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$type = defined( 'YWGC_GIFT_CARD_PRODUCT_TYPE' ) ? YWGC_GIFT_CARD_PRODUCT_TYPE : 'gift-card';
		$products = wc_get_products(
			array(
				'type'    => $type,
				'limit'   => 200,
				'status'  => array( 'publish', 'private' ),
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
			'Create a YITH Gift Card product in WooCommerce, then return here to map a pass template.',
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
		add_action( 'yith_ywgc_after_gift_card_generation_save', array( $this, 'on_gift_card' ), 20, 1 );
		add_action( 'save_post_gift_card', array( $this, 'on_save_gift_card' ), 20, 2 );
		add_action( 'updated_post_meta', array( $this, 'on_updated_post_meta' ), 20, 4 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 20, 1 );
	}

	/**
	 * After generation / object save.
	 *
	 * @param mixed $gift_card Gift card object.
	 * @return void
	 */
	public function on_gift_card( $gift_card ) {
		if ( ! $gift_card instanceof YITH_YWGC_Gift_Card ) {
			return;
		}
		$this->handle_card( $gift_card );
	}

	/**
	 * Manual create/edit of gift_card CPT.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save_gift_card( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		unset( $post );
		$card = $this->get_card( absint( $post_id ) );
		if ( $card ) {
			$this->handle_card( $card );
		}
	}

	/**
	 * Balance / amount meta updates.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_updated_post_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( self::$handling ) {
			return;
		}

		$watched = array( '_ywgc_balance_total', '_ywgc_amount_total', '_ywgc_expiration', '_ywgc_recipient', '_ywgc_recipient_name' );
		if ( ! in_array( (string) $meta_key, $watched, true ) ) {
			return;
		}

		if ( 'gift_card' !== get_post_type( $object_id ) ) {
			return;
		}

		$card = $this->get_card( absint( $object_id ) );
		if ( $card ) {
			$this->handle_card( $card );
		}
	}

	/**
	 * Post status transitions for gift_card CPT.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'gift_card' !== $post->post_type ) {
			return;
		}

		$card = $this->get_card( (int) $post->ID );
		if ( $card ) {
			$this->handle_card( $card );
		}
	}

	/**
	 * Revoke when gift card post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_before_delete_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || 'gift_card' !== get_post_type( $post_id ) ) {
			return;
		}

		EPC_Pass_Service::revoke_pass( $this->get_slug(), $post_id );
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
	 * Apply status rule.
	 *
	 * @param YITH_YWGC_Gift_Card $card Card.
	 * @return void
	 */
	private function handle_card( $card ) {
		if ( ! EPC_Api_Client::is_configured() || ! $card instanceof YITH_YWGC_Gift_Card ) {
			return;
		}

		if ( self::$handling ) {
			return;
		}

		self::$handling = true;

		try {
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
					EPC_Pass_Service::revoke_pass( $this->get_slug(), absint( $card->ID ) );
					break;
				default:
					break;
			}
		} finally {
			self::$handling = false;
		}
	}

	/**
	 * Sync pass from YITH gift card.
	 *
	 * @param YITH_YWGC_Gift_Card $card Card.
	 * @param string              $mode Mode.
	 * @return true|\WP_Error
	 */
	private function sync_card( $card, $mode = 'sync' ) {
		$card_id    = absint( $card->ID );
		$product_id = absint( $card->product_id );

		if ( $card_id <= 0 || $product_id <= 0 ) {
			return new WP_Error( 'epc_invalid_source', __( 'Gift card not found or missing product.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $product_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this gift card product.', 'epasscard' ) );
		}

		$recipient_email = (string) $card->recipient;
		$recipient_name  = (string) $card->recipient_name;
		$sender_name     = (string) $card->sender_name;
		$user_id         = 0;
		$first           = '';
		$last            = '';
		$display         = $recipient_name ? $recipient_name : $sender_name;

		if ( $recipient_email && is_email( $recipient_email ) ) {
			$user = get_user_by( 'email', $recipient_email );
			if ( $user ) {
				$user_id = (int) $user->ID;
				$first   = (string) get_user_meta( $user_id, 'first_name', true );
				$last    = (string) get_user_meta( $user_id, 'last_name', true );
				$display = (string) $user->display_name;
			}
		}

		$balance     = (float) $card->get_balance();
		$amount      = isset( $card->total_amount ) ? (float) $card->total_amount : $balance;
		$status      = $this->normalize_card_status( $card );
		$expire_ts   = ! empty( $card->expiration ) ? (int) $card->expiration : 0;
		$message     = isset( $card->message ) ? (string) $card->message : '';

		$values = array(
			'user_display_name' => $display,
			'user_email'        => $recipient_email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $display ? $display : $recipient_name ),
			'card_id'           => (string) $card_id,
			'card_number'       => method_exists( $card, 'get_code' ) ? (string) $card->get_code() : '',
			'balance'           => (string) $balance,
			'balance_formatted' => $this->format_money( $balance ),
			'amount'            => (string) $amount,
			'card_status'       => $status,
			'expire_date'       => epc_format_pass_expiry_timestamp( $expire_ts ),
			'product_id'        => (string) $product_id,
			'product_name'      => $this->get_entity_label( $product_id ),
			'order_id'          => isset( $card->order_id ) ? (string) absint( $card->order_id ) : '',
			'recipient_email'   => $recipient_email,
			'recipient_name'    => $recipient_name,
			'sender_name'       => $sender_name,
			'message'           => $message,
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
	 * Normalize status for rules.
	 *
	 * @param YITH_YWGC_Gift_Card $card Card.
	 * @return string
	 */
	private function normalize_card_status( $card ) {
		if ( method_exists( $card, 'is_expired' ) && $card->is_expired() ) {
			return 'expired';
		}

		$status = isset( $card->status ) ? (string) $card->status : 'publish';
		if ( 'publish' === $status || '' === $status ) {
			return 'publish';
		}

		return $status;
	}

	/**
	 * Load card by post ID.
	 *
	 * @param int $card_id Gift card post ID.
	 * @return YITH_YWGC_Gift_Card|null
	 */
	private function get_card( $card_id ) {
		$card_id = absint( $card_id );
		if ( $card_id <= 0 || ! class_exists( 'YITH_YWGC_Gift_Card' ) ) {
			return null;
		}

		$card = new YITH_YWGC_Gift_Card( array( 'ID' => $card_id ) );
		return $card->exists() ? $card : null;
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
