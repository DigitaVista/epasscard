<?php
/**
 * Encrypt / decrypt stored API credentials.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses WordPress AUTH_KEY and AUTH_SALT (same pattern as sibling plugins).
 */
class EPC_Encryption {

	/**
	 * Encrypt a plain string.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$iv = substr( (string) AUTH_SALT, 0, 16 );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', AUTH_KEY, 0, $iv );

		return is_string( $enc ) ? base64_encode( $enc ) : '';
	}

	/**
	 * Decrypt stored value.
	 *
	 * @param string $encrypted Encrypted string.
	 * @return string
	 */
	public static function decrypt( $encrypted ) {
		$encrypted = (string) $encrypted;
		if ( '' === $encrypted ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$iv     = substr( (string) AUTH_SALT, 0, 16 );
		$plain  = openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', AUTH_KEY, 0, $iv );

		return is_string( $plain ) ? $plain : '';
	}
}
