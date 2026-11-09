<?php
/**
 * WooCommerce products that come with a wallet card: the product settings and the order data
 * the three product-card integrations share (tickets, vouchers, product cards).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product edit screen → General: "Give a wallet card" with the kind of card (ticket, voucher or
 * card) and its details; plus the per-unit card codes kept on order lines.
 *
 * A purchase of quantity N gives N cards, one per unit. A card's record id is
 * "i{order item id}_{unit}" (unit 1..N).
 */
class EPC_WC_Product_Cards {

	/**
	 * Product meta: '' | ticket | voucher | card.
	 */
	const META_TYPE = '_epc_wallet_card';

	/**
	 * Product meta: event start, site time "Y-m-d H:i" (tickets).
	 */
	const META_EVENT_START = '_epc_event_start';

	/**
	 * Product meta: venue (tickets).
	 */
	const META_VENUE = '_epc_event_venue';

	/**
	 * Product meta: last valid day "Y-m-d" (vouchers).
	 */
	const META_VALID_UNTIL = '_epc_voucher_valid_until';

	/**
	 * Order item meta: unit => card code.
	 */
	const ITEM_CODES = '_epc_card_codes';

	/**
	 * Hooks added once.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Card kinds and their integrations.
	 *
	 * @return array<string, array{module: string, label: string}>
	 */
	public static function types() {
		return array(
			'ticket'  => array(
				'module' => 'woocommerce-tickets',
				'label'  => __( 'Ticket', 'epasscard' ),
			),
			'voucher' => array(
				'module' => 'woocommerce-vouchers',
				'label'  => __( 'Voucher', 'epasscard' ),
			),
			'card'    => array(
				'module' => 'woocommerce-product-cards',
				'label'  => __( 'Card', 'epasscard' ),
			),
		);
	}

	/**
	 * Card kinds whose integration is turned on.
	 *
	 * @return array<string, string> type => label
	 */
	public static function enabled_types() {
		$out = array();
		foreach ( self::types() as $type => $info ) {
			if ( EPC_Module_Settings::is_enabled( $info['module'] ) ) {
				$out[ $type ] = $info['label'];
			}
		}
		return $out;
	}

	/**
	 * Add the product settings (called by each product-card integration that is turned on).
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 30, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_product_script' ) );
	}

	/**
	 * Integrations that are turned on, by card kind.
	 *
	 * @return array<string, EPC_Module_WC_Product_Cards>
	 */
	private static function enabled_modules() {
		$out = array();
		foreach ( self::types() as $type => $info ) {
			$module = EPC_Module_Loader::get_module_instance( $info['module'] );
			if ( $module instanceof EPC_Module_WC_Product_Cards && EPC_Module_Settings::is_enabled( $info['module'] ) ) {
				$out[ $type ] = $module;
			}
		}
		return $out;
	}

	/**
	 * Order being edited, from the meta box arguments.
	 *
	 * @param mixed $object Post or order.
	 * @return WC_Order|null
	 */
	private static function order_from( $object ) {
		if ( $object instanceof WC_Order ) {
			return $object;
		}
		$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : null;
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * "Wallet cards" box on the order edit screen (classic and HPOS) when the order has cards.
	 *
	 * @param string $screen_id Screen id.
	 * @param mixed  $object    Post or order.
	 * @return void
	 */
	public static function add_order_meta_box( $screen_id, $object = null ) {
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$order        = self::order_from( $object );
		if ( ! in_array( $screen_id, array( 'shop_order', $order_screen ), true ) || null === $order || ! epc_current_user_can_manage_passes() ) {
			return;
		}
		foreach ( self::enabled_modules() as $module ) {
			if ( ! empty( $module->order_source_ids( $order ) ) ) {
				add_meta_box( 'epc-order-cards', __( 'Wallet cards', 'epasscard' ), array( __CLASS__, 'render_order_meta_box' ), $screen_id, 'side', 'default' );
				return;
			}
		}
	}

	/**
	 * The box: one line per card with its status and actions.
	 *
	 * @param mixed $object Post or order.
	 * @return void
	 */
	public static function render_order_meta_box( $object ) {
		$order = self::order_from( $object );
		if ( null === $order ) {
			return;
		}
		echo '<ul class="epc-order-cards">';
		foreach ( self::enabled_modules() as $module ) {
			$module->render_order_cards( $order );
		}
		echo '</ul>';
	}

	/**
	 * Product edit screen: show only the settings of the chosen kind of card.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_product_script( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		$path = EPC_PLUGIN_DIR . 'admin/js/product-card.js';
		wp_enqueue_script( 'epc-product-card', EPC_PLUGIN_URL . 'admin/js/product-card.js', array(), file_exists( $path ) ? (string) filemtime( $path ) : EPC_VERSION, true );
		EPC_Card_Preview::register_style();
		$css = EPC_PLUGIN_DIR . 'admin/css/product-card.css';
		wp_enqueue_style( 'epc-product-card', EPC_PLUGIN_URL . 'admin/css/product-card.css', array( 'epc-card-preview' ), file_exists( $css ) ? (string) filemtime( $css ) : EPC_VERSION );
	}

	/**
	 * Tutorial video of each kind of card (EpassCard YouTube channel).
	 *
	 * @return array<string, array{id: string, title: string, length: string}>
	 */
	public static function videos() {
		return array(
			'ticket'  => array(
				'id'     => 'kyMYjVzNhEw',
				'title'  => __( 'Wallet tickets for the events you sell', 'epasscard' ),
				'length' => '1:39',
			),
			'voucher' => array(
				'id'     => '4oo-8ZPi2yM',
				'title'  => __( 'Wallet vouchers for the products you sell', 'epasscard' ),
				'length' => '1:39',
			),
			'card'    => array(
				'id'     => 'jbOXPYZMr1s',
				'title'  => __( 'Wallet passes for any product you sell', 'epasscard' ),
				'length' => '1:37',
			),
		);
	}

	/**
	 * Card kind of a product ('' = none). Variations use their parent product's setting.
	 *
	 * @param int $product_id Product or variation id.
	 * @return string
	 */
	public static function get_type( $product_id ) {
		$product_id = self::parent_id( $product_id );
		if ( $product_id <= 0 ) {
			return '';
		}
		$type = (string) get_post_meta( $product_id, self::META_TYPE, true );
		return isset( self::types()[ $type ] ) ? $type : '';
	}

	/**
	 * Parent product of a variation, else the id itself.
	 *
	 * @param int $product_id Product or variation id.
	 * @return int
	 */
	public static function parent_id( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return 0;
		}
		$parent = 'product_variation' === get_post_type( $product_id ) ? absint( wp_get_post_parent_id( $product_id ) ) : 0;
		return $parent > 0 ? $parent : $product_id;
	}

	/**
	 * Product edit → General: the wallet card settings.
	 *
	 * @return void
	 */
	public static function render_product_fields() {
		global $post;
		$types = self::enabled_types();
		if ( empty( $types ) || ! $post ) {
			return;
		}
		$product_id = (int) $post->ID;
		$type       = (string) get_post_meta( $product_id, self::META_TYPE, true );
		if ( ! isset( self::types()[ $type ] ) ) {
			$type = '';
		}
		if ( '' !== $type && ! isset( $types[ $type ] ) ) {
			// Its integration was turned off: keep the choice visible so it is not lost on save.
			$types[ $type ] = self::types()[ $type ]['label'];
		}

		echo '<div class="options_group epc-product-card"><div class="epc-product-card__fields">';
		wp_nonce_field( 'epc_product_card', 'epc_product_card_nonce' );
		woocommerce_wp_checkbox(
			array(
				'id'          => 'epc_wallet_card_on',
				'label'       => __( 'Wallet card', 'epasscard' ),
				'description' => __( 'Give the buyer an Apple Wallet and Google Wallet card for each one bought', 'epasscard' ),
				'value'       => '' !== $type ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => 'epc_wallet_card_type',
				'label'       => __( 'Kind of card', 'epasscard' ),
				'options'     => $types,
				'value'       => '' !== $type ? $type : (string) array_keys( $types )[0],
				'desc_tip'    => true,
				'description' => __( 'Each kind has its own design under EpassCard → Design.', 'epasscard' ),
			)
		);
		self::render_missing_design_warning( $product_id, $type );
		if ( isset( $types['ticket'] ) ) {
			woocommerce_wp_text_input(
				array(
					'id'                => 'epc_event_start',
					'label'             => __( 'Event date and time', 'epasscard' ),
					'type'              => 'datetime-local',
					'value'             => self::datetime_local_value( (string) get_post_meta( $product_id, self::META_EVENT_START, true ) ),
					'description'       => __( 'Tickets only. Shown on the ticket; reminders count down to it.', 'epasscard' ),
					'desc_tip'          => true,
					'custom_attributes' => array( 'step' => '60' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'          => 'epc_event_venue',
					'label'       => __( 'Venue', 'epasscard' ),
					'value'       => (string) get_post_meta( $product_id, self::META_VENUE, true ),
					'description' => __( 'Tickets only.', 'epasscard' ),
					'desc_tip'    => true,
				)
			);
		}
		if ( isset( $types['voucher'] ) ) {
			woocommerce_wp_text_input(
				array(
					'id'          => 'epc_voucher_valid_until',
					'label'       => __( 'Voucher valid until', 'epasscard' ),
					'type'        => 'date',
					'value'       => (string) get_post_meta( $product_id, self::META_VALID_UNTIL, true ),
					'description' => __( 'Vouchers only. Leave empty when it does not expire. The card expires in the wallet after this day.', 'epasscard' ),
					'desc_tip'    => true,
				)
			);
		}
		self::render_video_button( $types );
		echo '</div>';
		self::render_preview( $types );
		echo '</div>';
		self::render_video_dialog();
	}

	/**
	 * Play button after "Give the buyer ... card": the tutorial of the chosen kind of card. The
	 * script moves it next to the checkbox text; YouTube loads only when it is clicked.
	 *
	 * @param array<string, string> $types Kinds offered on this product.
	 * @return void
	 */
	private static function render_video_button( array $types ) {
		$videos = array_intersect_key( self::videos(), $types );
		if ( empty( $videos ) ) {
			return;
		}
		echo '<p class="form-field epc-product-card__video-row">';
		foreach ( $videos as $type => $video ) {
			$label = sprintf(
				/* translators: 1: video title, 2: length such as 1:39 */
				__( 'Watch the video: %1$s (%2$s)', 'epasscard' ),
				$video['title'],
				$video['length']
			);
			printf(
				'<button type="button" class="epc-product-card__play" data-epc-video="%1$s" data-epc-video-type="%2$s" data-epc-video-title="%3$s" title="%4$s" aria-label="%4$s"><svg viewBox="0 0 28 20" width="28" height="20" aria-hidden="true" focusable="false"><rect width="28" height="20" rx="5" fill="#ff0000"/><path d="M11 5.5v9l8-4.5z" fill="#fff"/></svg></button>',
				esc_attr( $video['id'] ),
				esc_attr( $type ),
				esc_attr( $video['title'] ),
				esc_attr( $label )
			);
		}
		echo '</p>';
	}

	/**
	 * The popup that plays a tutorial (printed once; the script fills in the player).
	 *
	 * @return void
	 */
	private static function render_video_dialog() {
		?>
		<dialog class="epc-video-dialog" id="epc-product-card-video" aria-labelledby="epc-product-card-video-title">
			<div class="epc-video-dialog__head">
				<h2 class="epc-video-dialog__title" id="epc-product-card-video-title"></h2>
				<button type="button" class="epc-video-dialog__close" data-epc-video-close><span aria-hidden="true">&times;</span><span class="screen-reader-text"><?php esc_html_e( 'Close video', 'epasscard' ); ?></span></button>
			</div>
			<div class="epc-video-dialog__frame"></div>
			<p class="epc-video-dialog__note"><?php esc_html_e( 'Plays from YouTube (privacy-enhanced mode).', 'epasscard' ); ?></p>
		</dialog>
		<?php
	}

	/**
	 * Right of the settings: the card of each kind, as its design looks, with sample details
	 * (the product name is filled in from the product).
	 *
	 * @param array<string, string> $types Kinds offered on this product.
	 * @return void
	 */
	private static function render_preview( array $types ) {
		$cards = array();
		foreach ( array_keys( $types ) as $type ) {
			$slug = self::types()[ $type ]['module'];
			if ( EPC_Module_Loader::get_module_instance( $slug ) instanceof EPC_Module ) {
				$cards[ $type ] = $slug;
			}
		}
		if ( empty( $cards ) ) {
			return;
		}
		echo '<div class="epc-product-card__preview">';
		echo '<p class="epc-product-card__preview-title">' . esc_html__( 'Card preview', 'epasscard' ) . '</p>';
		foreach ( $cards as $type => $slug ) {
			echo '<div class="epc-product-card__preview-card" data-epc-preview="' . esc_attr( $type ) . '">';
			EPC_Card_Preview::render( $slug );
			echo '</div>';
		}
		echo '<p class="epc-product-card__preview-note">' . esc_html__( 'Your design with sample details. Each buyer\'s card shows their own.', 'epasscard' );
		if ( current_user_can( 'manage_options' ) ) {
			foreach ( $cards as $type => $slug ) {
				echo ' <a href="' . esc_url( EPC_Admin_Design::url_for_module( $slug ) ) . '" data-epc-preview-link="' . esc_attr( $type ) . '">' . esc_html__( 'Edit the design', 'epasscard' ) . '</a>';
			}
		}
		echo '</p></div>';
	}

	/**
	 * Under the kind of card: a warning when that kind has no design yet, since buyers get no
	 * card until one is saved.
	 *
	 * @param int    $product_id Product id.
	 * @param string $type       Saved kind of card, '' for none.
	 * @return void
	 */
	private static function render_missing_design_warning( $product_id, $type ) {
		if ( '' === $type || ! EPC_Module_Settings::is_enabled( self::types()[ $type ]['module'] ) ) {
			return;
		}
		$module = EPC_Module_Loader::get_module_instance( self::types()[ $type ]['module'] );
		if ( ! $module instanceof EPC_Module ) {
			return;
		}
		$mapping = $module->get_mapping( $product_id );
		if ( ! empty( $mapping['template_uid'] ) ) {
			return;
		}
		echo '<p class="form-field epc-product-card__warning" style="color:#b32d2e;">';
		echo esc_html(
			sprintf(
				/* translators: %s: integration name, e.g. "WooCommerce vouchers" */
				__( 'There is no %s design yet, so buyers of this product get no wallet card.', 'epasscard' ),
				$module->get_label()
			)
		);
		if ( current_user_can( 'manage_options' ) ) {
			echo ' <a href="' . esc_url( EPC_Admin_Design::url_for_module( $module->get_slug() ) ) . '">' . esc_html__( 'Create the design', 'epasscard' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Save the product settings.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save_product_fields( $product ) {
		if ( ! $product instanceof WC_Product || ! isset( $_POST['epc_product_card_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['epc_product_card_nonce'] ) ), 'epc_product_card' ) ) {
			return;
		}

		$on   = isset( $_POST['epc_wallet_card_on'] ) && 'yes' === sanitize_key( wp_unslash( (string) $_POST['epc_wallet_card_on'] ) );
		$type = isset( $_POST['epc_wallet_card_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['epc_wallet_card_type'] ) ) : '';
		$product->update_meta_data( self::META_TYPE, $on && isset( self::types()[ $type ] ) ? $type : '' );

		if ( isset( $_POST['epc_event_start'] ) ) {
			$product->update_meta_data( self::META_EVENT_START, self::sanitize_datetime( sanitize_text_field( wp_unslash( (string) $_POST['epc_event_start'] ) ) ) );
		}
		if ( isset( $_POST['epc_event_venue'] ) ) {
			$product->update_meta_data( self::META_VENUE, sanitize_text_field( wp_unslash( (string) $_POST['epc_event_venue'] ) ) );
		}
		if ( isset( $_POST['epc_voucher_valid_until'] ) ) {
			$product->update_meta_data( self::META_VALID_UNTIL, self::sanitize_date( sanitize_text_field( wp_unslash( (string) $_POST['epc_voucher_valid_until'] ) ) ) );
		}
	}

	/**
	 * "Y-m-d H:i" from a datetime-local value, or ''.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function sanitize_datetime( $value ) {
		$value = str_replace( 'T', ' ', trim( (string) $value ) );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(:\d{2})?$/', $value, $m ) ) {
			$date = DateTime::createFromFormat( '!Y-m-d H:i', $m[1] . ' ' . $m[2] );
			return $date && $date->format( 'Y-m-d H:i' ) === $m[1] . ' ' . $m[2] ? $m[1] . ' ' . $m[2] : '';
		}
		return '';
	}

	/**
	 * "Y-m-d", or ''.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTime::createFromFormat( '!Y-m-d', $value ) : false;
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	/**
	 * Stored "Y-m-d H:i" as a datetime-local input value.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function datetime_local_value( $value ) {
		return '' !== $value ? str_replace( ' ', 'T', $value ) : '';
	}

	/**
	 * Timestamp of a stored site-time value ("Y-m-d H:i" or "Y-m-d" + time), or 0.
	 *
	 * @param string $value Stored value.
	 * @param string $time  Time to add to a date-only value.
	 * @return int
	 */
	public static function site_timestamp( $value, $time = '' ) {
		$value = trim( (string) $value . ( '' !== $time ? ' ' . $time : '' ) );
		if ( '' === $value ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * Record id of one card.
	 *
	 * @param int $item_id Order item id.
	 * @param int $unit    1..quantity.
	 * @return string
	 */
	public static function source_id( $item_id, $unit ) {
		return 'i' . absint( $item_id ) . '_' . absint( $unit );
	}

	/**
	 * Order item id and unit of a record id, or null.
	 *
	 * @param string $source_id Record id.
	 * @return array{0: int, 1: int}|null
	 */
	public static function parse_source_id( $source_id ) {
		if ( ! preg_match( '/^i(\d+)_(\d+)$/', (string) $source_id, $m ) ) {
			return null;
		}
		$item = absint( $m[1] );
		$unit = absint( $m[2] );
		return $item > 0 && $unit > 0 ? array( $item, $unit ) : null;
	}

	/**
	 * Product line of an order, or null.
	 *
	 * @param int $item_id Order item id.
	 * @return WC_Order_Item_Product|null
	 */
	public static function get_item( $item_id ) {
		if ( ! class_exists( 'WC_Order_Factory' ) ) {
			return null;
		}
		$item = WC_Order_Factory::get_order_item( absint( $item_id ) );
		return $item instanceof WC_Order_Item_Product ? $item : null;
	}

	/**
	 * Number of cards a line gives: one per unit bought and not refunded. A refund of some
	 * units voids the cards of the last units (WooCommerce refunds a quantity, not a unit).
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @return int
	 */
	public static function units( $item ) {
		$units = max( 0, (int) $item->get_quantity() );
		$order = $units > 0 && $item->get_id() > 0 ? $item->get_order() : null;
		if ( $order instanceof WC_Order ) {
			// Negative: the quantity refunded for this line.
			$units += (int) $order->get_qty_refunded_for_item( $item->get_id() );
		}
		return max( 0, $units );
	}

	/**
	 * Card code of one unit, made once and kept on the order line.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @param int                   $unit Unit.
	 * @return string
	 */
	public static function code( $item, $unit ) {
		$codes = $item->get_meta( self::ITEM_CODES, true );
		$codes = is_array( $codes ) ? $codes : array();
		$unit  = absint( $unit );
		if ( isset( $codes[ $unit ] ) && '' !== (string) $codes[ $unit ] ) {
			return (string) $codes[ $unit ];
		}
		$codes[ $unit ] = self::new_code();
		$item->update_meta_data( self::ITEM_CODES, $codes );
		$item->save_meta_data();
		return $codes[ $unit ];
	}

	/**
	 * A 10-character code without look-alike characters (no 0/O, 1/I/L).
	 *
	 * @return string
	 */
	public static function new_code() {
		$chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$code  = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $code;
	}

	/**
	 * Money as plain text.
	 *
	 * @param float $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}
		$args = '' !== $currency ? array( 'currency' => $currency ) : array();
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}
}
