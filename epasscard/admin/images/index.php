<?php
/**
 * Plugin Name: OpenAI Conversions API for oskaskrin.is
 * Description: Sends order_created events to OpenAI Conversions API after successful WooCommerce payments.
 * Version: 1.0.0
 * Author: oskaskrin.is
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================================
// Configuration
// ============================================================================

define( 'OSKASKRIN_OPENAI_PIXEL_ID', 'HjeZyGtkmTPXn4RAkGJp5F' );
define( 'OSKASKRIN_OPENAI_ENDPOINT', 'https://bzr.openai.com/v1/events' );
define( 'OSKASKRIN_OPENAI_API_KEY', $_ENV['OPENAIKEY'] );
/**
 * IMPORTANT:
 *
 * Add the API key to wp-config.php:
 *
 * define( 'OSKASKRIN_OPENAI_API_KEY', 'YOUR-REAL-API-KEY' );
 */

// ============================================================================
// HPOS Compatibility
// ============================================================================

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// ============================================================================
// Send OpenAI Conversion Event
// ============================================================================

/**
 * Send order_created event when WooCommerce confirms payment.
 *
 * @param int $order_id WooCommerce order ID.
 */
function oskaskrin_send_openai_order_created_event( $order_id ) {

	// Make sure API key exists.
	if (
		! defined( 'OSKASKRIN_OPENAI_API_KEY' ) ||
		empty( OSKASKRIN_OPENAI_API_KEY )
	) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	// Prevent sending the same conversion multiple times.
	if ( $order->get_meta( '_openai_conversion_sent', true ) ) {
		return;
	}

	/**
	 * Use a stable event ID.
	 *
	 * OpenAI recommends reusing the same event ID when retrying
	 * or sending the same conversion through another integration.
	 */
	$event_id = 'wc_order_' . $order->get_id();

	/**
	 * Event timestamp in milliseconds.
	 *
	 * Prefer WooCommerce's payment timestamp.
	 */
	$date_paid = $order->get_date_paid();

	if ( $date_paid ) {
		$timestamp_ms = $date_paid->getTimestamp() * 1000;
	} else {
		$timestamp_ms = (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Conversion source URL.
	 *
	 * We intentionally don't use get_checkout_order_received_url()
	 * because that URL contains the private WooCommerce order key.
	 */
	$source_url = wc_get_endpoint_url(
		'order-received',
		$order->get_id(),
		wc_get_checkout_url()
	);

	// Build payload according to customer's reference request.
	$payload = array(
		'validate_only' => false,
		'events'        => array(
			array(
				'id'            => $event_id,
				'type'          => 'order_created',
				'timestamp_ms'  => $timestamp_ms,
				'source_url'    => $source_url,
				'action_source' => 'web',
				'data'          => array(
					'type' => 'contents',
				),
			),
		),
	);

	$response = oskaskrin_send_openai_api_request( $payload );

	// ------------------------------------------------------------------------
	// Handle result
	// ------------------------------------------------------------------------

	if ( is_wp_error( $response ) ) {

		$order->add_order_note(
			'OpenAI Conversions API error: ' .
			$response->get_error_message()
		);

		return;
	}

	$status_code  = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );

	if ( 200 === $status_code ) {

		// Mark conversion as successfully sent.
		$order->update_meta_data( '_openai_conversion_sent', 'yes' );
		$order->update_meta_data( '_openai_conversion_event_id', $event_id );
		$order->save();

		$order->add_order_note(
			sprintf(
				'OpenAI Conversions API: order_created event sent successfully. Event ID: %s',
				$event_id
			)
		);

	} else {

		$order->add_order_note(
			sprintf(
				'OpenAI Conversions API failed. HTTP %d. Response: %s',
				$status_code,
				$response_body
			)
		);
	}
}

/**
 * Fires after WooCommerce confirms successful payment.
 */
add_action(
	'woocommerce_payment_complete',
	'oskaskrin_send_openai_order_created_event',
	10,
	1
);

// ============================================================================
// OpenAI API Request
// ============================================================================

/**
 * Send request to OpenAI Conversions API.
 *
 * @param array $payload Event payload.
 *
 * @return array|WP_Error
 */
function oskaskrin_send_openai_api_request( $payload ) {

	$url = add_query_arg(
		array(
			'pid' => OSKASKRIN_OPENAI_PIXEL_ID,
		),
		OSKASKRIN_OPENAI_ENDPOINT
	);

	$response = wp_remote_post(
		$url,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . OSKASKRIN_OPENAI_API_KEY,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		)
	);

	return $response;
}