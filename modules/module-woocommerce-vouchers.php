<?php
/**
 * WooCommerce vouchers: a wallet voucher for each voucher product bought.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vouchers sold as WooCommerce products: the value is the price paid; the product's "Wallet
 * card" settings can give a last valid day.
 */
class EPC_Module_WooCommerce_Vouchers extends EPC_Module_WC_Product_Cards {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'woocommerce-vouchers';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WooCommerce vouchers', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_card_type() {
		return 'voucher';
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet voucher for each voucher bought: products marked "Wallet card: Voucher" in WooCommerce.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_source_fields() {
		return array(
			'voucher_value' => __( 'Voucher value (price paid)', 'epasscard' ),
			'valid_until'   => __( 'Valid until', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_values( $item, $order, $product_id, $unit ) {
		unset( $unit );
		$until = (string) get_post_meta( $product_id, EPC_WC_Product_Cards::META_VALID_UNTIL, true );
		$end   = '' !== $until ? EPC_WC_Product_Cards::site_timestamp( $until, '23:59:59' ) : 0;
		$qty   = max( 1, (int) $item->get_quantity() );
		$value = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty;
		$out   = array(
			'voucher_value' => EPC_WC_Product_Cards::money( $value, (string) $order->get_currency() ),
			'valid_until'   => $end ? wp_date( (string) get_option( 'date_format' ), $end ) : __( 'No expiry', 'epasscard' ),
		);
		if ( $end ) {
			// The card expires in the wallet after the last valid day.
			$out['pass_expire_mysql'] = epc_pass_expire_mysql_timestamp( $end );
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'before_voucher_expires' => __( 'Before the voucher expires', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_type_description( $type ) {
		return 'before_voucher_expires' === $type ? __( 'Only for products with a "Voucher valid until" day.', 'epasscard' ) : '';
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'before_voucher_expires' => array(
				'enabled' => false,
				'days'    => 7,
				'title'   => __( 'Your voucher expires soon', 'epasscard' ),
				'message' => __( 'Your {product_name} voucher ({voucher_value}) is valid until {valid_until}.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array( 'product_name', 'voucher_value', 'valid_until', 'user_full_name' );
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
		$until = (string) get_post_meta( absint( $pass_row->entity_id ), EPC_WC_Product_Cards::META_VALID_UNTIL, true );
		$end   = '' !== $until ? EPC_WC_Product_Cards::site_timestamp( $until, '23:59:59' ) : 0;
		if ( $end <= 0 ) {
			return;
		}
		EPC_Pass_Notifications::maybe_send_for_event(
			$pass_row,
			'before_voucher_expires',
			$rules['before_voucher_expires'] ?? array(),
			$end,
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
			'voucher_value'  => $values['voucher_value'],
			'valid_until'    => $values['valid_until'],
			'user_full_name' => $values['user_full_name'],
		);
	}
}
