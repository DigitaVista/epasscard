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
 * Authenticated encryption for the stored API key.
 *
 * Format v2 (since 1.2.0): "epc2:" . base64( iv[16] . hmac[32] . ciphertext ).
 * - AES-256-CBC with a random IV per value.
 * - HMAC-SHA256 over iv + ciphertext (encrypt-then-MAC), checked before decrypting.
 * - Separate encryption and MAC keys, derived from AUTH_KEY and AUTH_SALT.
 *
 * Values written by 1.1.x and earlier (static IV, raw AUTH_KEY, no MAC) are still read;
 * EPC_Connection re-saves them as v2 on first use.
 */
class EPC_Encryption {

	/**
	 * Prefix marking the v2 format. Legacy values are base64 and never contain ":".
	 */
	public const V2_PREFIX = 'epc2:';

	/**
	 * Cipher used by both formats.
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a plain string (v2 format).
	 *
	 * @param string $plain Plain text.
	 * @return string Encrypted value, or '' when encryption is not possible.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::can_encrypt() ) {
			return '';
		}

		$keys = self::derive_keys();
		if ( null === $keys ) {
			return '';
		}

		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $e ) {
			return '';
		}

		$cipher = openssl_encrypt( $plain, self::CIPHER, $keys['enc'], OPENSSL_RAW_DATA, $iv );
		if ( ! is_string( $cipher ) || '' === $cipher ) {
			return '';
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, $keys['mac'], true );

		return self::V2_PREFIX . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of ciphertext.
	}

	/**
	 * Decrypt a stored value (v2 or legacy).
	 *
	 * @param string $encrypted Encrypted string.
	 * @return string Plain text, or '' when the value cannot be decrypted or was tampered with.
	 */
	public static function decrypt( $encrypted ) {
		$encrypted = (string) $encrypted;
		if ( '' === $encrypted || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		if ( 0 === strpos( $encrypted, self::V2_PREFIX ) ) {
			return self::decrypt_v2( substr( $encrypted, strlen( self::V2_PREFIX ) ) );
		}

		return self::decrypt_legacy( $encrypted );
	}

	/**
	 * Whether a stored value uses the pre-1.2.0 format.
	 *
	 * @param string $encrypted Stored value.
	 * @return bool
	 */
	public static function is_legacy( $encrypted ) {
		$encrypted = (string) $encrypted;
		return '' !== $encrypted && 0 !== strpos( $encrypted, self::V2_PREFIX );
	}

	/**
	 * Whether this server can write v2 values.
	 *
	 * @return bool
	 */
	public static function can_encrypt() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) && function_exists( 'hash_hmac' );
	}

	/**
	 * Decrypt the body of a v2 value.
	 *
	 * @param string $body Base64 body after the prefix.
	 * @return string
	 */
	private static function decrypt_v2( $body ) {
		$keys = self::derive_keys();
		if ( null === $keys ) {
			return '';
		}

		$raw = base64_decode( (string) $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of ciphertext.
		// 16-byte IV + 32-byte MAC + at least one 16-byte block.
		if ( ! is_string( $raw ) || strlen( $raw ) < 64 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $keys['mac'], true ), $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, self::CIPHER, $keys['enc'], OPENSSL_RAW_DATA, $iv );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Decrypt a value written by EpassCard 1.1.x or earlier (unchanged algorithm).
	 *
	 * @param string $encrypted Stored value.
	 * @return string
	 */
	private static function decrypt_legacy( $encrypted ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return '';
		}

		$iv    = substr( (string) AUTH_SALT, 0, 16 );
		$plain = openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', AUTH_KEY, 0, $iv ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Legacy storage format.

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Encryption and MAC keys for v2.
	 *
	 * @return array{enc: string, mac: string}|null Null when the WordPress secret keys are not defined.
	 */
	private static function derive_keys() {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) || ! function_exists( 'hash_hmac' ) ) {
			return null;
		}

		$material = (string) AUTH_KEY . '|' . (string) AUTH_SALT;

		return array(
			'enc' => hash_hmac( 'sha256', 'epasscard-encryption-v2', $material, true ),
			'mac' => hash_hmac( 'sha256', 'epasscard-authentication-v2', $material, true ),
		);
	}
}
