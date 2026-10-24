<?php
/**
 * WooCommerce product cards: a wallet card for each product bought (passes, memberships,
 * anything that is not a ticket or a voucher).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A general wallet card for products marked "Wallet card: Card".
 */
class EPC_Module_WooCommerce_Product_Cards extends EPC_Module_WC_Product_Cards {

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'woocommerce-product-cards';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WooCommerce product cards', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_card_type() {
		return 'card';
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet card for each one bought: products marked "Wallet card: Card" in WooCommerce.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_source_fields() {
		return array(
			'product_short_description' => __( 'Product short description', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_type_values( $item, $order, $product_id, $unit ) {
		unset( $item, $order, $unit );
		$product = wc_get_product( $product_id );
		$text    = $product ? wp_strip_all_tags( (string) $product->get_short_description() ) : '';
		return array(
			'product_short_description' => trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) ),
		);
	}
}
