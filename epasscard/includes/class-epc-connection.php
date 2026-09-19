<?php
/**
 * Connection settings (API key storage).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global EpassCard connection credentials.
 */
class EPC_Connection {

	public const OPTION = 'epc_connection_settings';

	/**
	 * Cron hook for proactive API key renewal.
	 */
	public const CRON_HOOK = 'epc_extend_api_key_event';

	/**
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_key_encrypted' => '',
			'key_expires_utc'   => '',
			'org_id'            => 0,
			'connected_email'   => '',
			'package_details'   => array(),
		);
	}

	/**
	 * Register connection maintenance hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_extend_api_key' ) );
	}

	/**
	 * Schedule daily key renewal check.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled key renewal.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Load merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Decrypted API key for internal use.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$settings = self::get_settings();
		$enc      = isset( $settings['api_key_encrypted'] ) ? (string) $settings['api_key_encrypted'] : '';
		if ( '' === $enc ) {
			return '';
		}
		$plain = EPC_Encryption::decrypt( $enc );
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Whether connection is active.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== trim( self::get_api_key() );
	}

	/**
	 * Stored key expiry as unix timestamp (0 when unknown).
	 *
	 * @return int
	 */
	public static function get_key_expires_timestamp() {
		$settings = self::get_settings();
		$raw      = isset( $settings['key_expires_utc'] ) ? trim( (string) $settings['key_expires_utc'] ) : '';

		if ( '' === $raw ) {
			return 0;
		}

		try {
			$dt = new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( Exception $e ) {
			try {
				$dt = new DateTimeImmutable( $raw, new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e2 ) {
				return 0;
			}
		}

		return $dt->getTimestamp();
	}

	/**
	 * Days before expiry when the key should be extended.
	 *
	 * @return int
	 */
	public static function get_extend_lead_days() {
		/**
		 * Filter how many days before expiry the API key is auto-extended.
		 *
		 * @param int $days Default 14.
		 */
		return max( 1, (int) apply_filters( 'epc_api_key_extend_lead_days', 14 ) );
	}

	/**
	 * Whether the stored key should be extended now.
	 *
	 * @return bool
	 */
	public static function should_extend_api_key() {
		if ( ! self::is_connected() ) {
			return false;
		}

		$expires = self::get_key_expires_timestamp();
		if ( $expires <= 0 ) {
			return false;
		}

		$lead_seconds = self::get_extend_lead_days() * DAY_IN_SECONDS;

		return ( $expires - time() ) <= $lead_seconds;
	}

	/**
	 * Extend API key when nearing expiry.
	 *
	 * @return true|\WP_Error
	 */
	public static function maybe_extend_api_key() {
		if ( ! self::should_extend_api_key() ) {
			return true;
		}

		$result = EPC_Api_Client::extend_api_key();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::update_key_expiry( (string) ( $result['expire_at'] ?? '' ) );

		return true;
	}

	/**
	 * Save validated API key.
	 *
	 * @param string               $api_key Plain key.
	 * @param array<string, mixed> $validated Validation or generate response data.
	 * @param string               $email   Optional connected email.
	 * @return true|\WP_Error
	 */
	public static function save_api_key( $api_key, array $validated, $email = '' ) {
		$encrypted = EPC_Encryption::encrypt( (string) $api_key );
		if ( '' === $encrypted ) {
			return new WP_Error(
				'epc_encrypt_failed',
				__( 'Could not store the API key securely. Please try again.', 'epasscard' )
			);
		}

		$settings = self::get_settings();
		$settings['api_key_encrypted'] = $encrypted;
		$settings['key_expires_utc']   = self::resolve_expire_at( $validated );
		$settings['org_id']            = isset( $validated['org_id'] ) ? absint( $validated['org_id'] ) : 0;

		if ( '' === $email ) {
			$email = EPC_Api_Client::extract_account_email( $validated );
		}
		if ( '' !== $email ) {
			$settings['connected_email'] = sanitize_email( $email );
		}

		$settings['package_details'] = self::sanitize_package_details(
			EPC_Api_Client::extract_package_details( $validated )
		);

		update_option( self::OPTION, $settings );
		self::schedule_cron();

		return true;
	}

	/**
	 * Sanitize stored package details from connection API responses.
	 *
	 * @param mixed $raw Raw package payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize_package_details( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$package_name = '';
		if ( isset( $raw['package_name'] ) ) {
			$package_name = sanitize_text_field( (string) $raw['package_name'] );
		} elseif ( isset( $raw['packageName'] ) ) {
			$package_name = sanitize_text_field( (string) $raw['packageName'] );
		} elseif ( isset( $raw['name'] ) ) {
			$package_name = sanitize_text_field( (string) $raw['name'] );
		}

		$num_of_pass = 0;
		if ( isset( $raw['num_of_pass'] ) ) {
			$num_of_pass = absint( $raw['num_of_pass'] );
		} elseif ( isset( $raw['numOfPass'] ) ) {
			$num_of_pass = absint( $raw['numOfPass'] );
		}

		$package_id = 0;
		if ( isset( $raw['package_id'] ) ) {
			$package_id = absint( $raw['package_id'] );
		} elseif ( isset( $raw['packageId'] ) ) {
			$package_id = absint( $raw['packageId'] );
		} elseif ( isset( $raw['id'] ) ) {
			$package_id = absint( $raw['id'] );
		}

		$price_per_card = 0.0;
		if ( isset( $raw['price_per_card'] ) && is_numeric( $raw['price_per_card'] ) ) {
			$price_per_card = (float) $raw['price_per_card'];
		} elseif ( isset( $raw['pricePerCard'] ) && is_numeric( $raw['pricePerCard'] ) ) {
			$price_per_card = (float) $raw['pricePerCard'];
		}

		$total_price = 0.0;
		if ( isset( $raw['total_price'] ) && is_numeric( $raw['total_price'] ) ) {
			$total_price = (float) $raw['total_price'];
		} elseif ( isset( $raw['totalPrice'] ) && is_numeric( $raw['totalPrice'] ) ) {
			$total_price = (float) $raw['totalPrice'];
		}

		$billing_period = '';
		if ( isset( $raw['billing_period'] ) ) {
			$billing_period = sanitize_text_field( (string) $raw['billing_period'] );
		} elseif ( isset( $raw['billingPeriod'] ) ) {
			$billing_period = sanitize_text_field( (string) $raw['billingPeriod'] );
		} elseif ( isset( $raw['billing_type'] ) ) {
			$billing_period = sanitize_text_field( (string) $raw['billing_type'] );
		}

		return array(
			'package_id'     => $package_id,
			'package_name'   => $package_name,
			'num_of_pass'    => $num_of_pass,
			'price_per_card' => $price_per_card,
			'total_price'    => $total_price,
			'billing_period' => $billing_period,
		);
	}

	/**
	 * Stored package details for the connected account.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_package_details() {
		$settings = self::get_settings();
		$raw      = isset( $settings['package_details'] ) ? $settings['package_details'] : array();

		return self::sanitize_package_details( $raw );
	}

	/**
	 * Update stored key expiry after extension.
	 *
	 * @param string $expire_at Expiry datetime from API.
	 * @return void
	 */
	public static function update_key_expiry( $expire_at ) {
		$expire_at = sanitize_text_field( (string) $expire_at );
		if ( '' === $expire_at ) {
			return;
		}

		$settings                      = self::get_settings();
		$settings['key_expires_utc']   = $expire_at;
		update_option( self::OPTION, $settings );
	}

	/**
	 * Resolve expire_at from API payload with sensible fallback.
	 *
	 * @param array<string, mixed> $data API data.
	 * @return string
	 */
	private static function resolve_expire_at( array $data ) {
		$expire_at = EPC_Api_Client::extract_expire_at( $data );
		if ( '' !== $expire_at ) {
			return $expire_at;
		}

		return EPC_Api_Client::default_expire_at();
	}

	/**
	 * Disconnect and clear stored key.
	 *
	 * @return void
	 */
	public static function disconnect() {
		self::clear_cron();
		update_option( self::OPTION, self::defaults() );
	}

	/**
	 * Format expiry for display.
	 *
	 * @return string
	 */
	public static function get_expiry_display() {
		$settings = self::get_settings();
		$raw      = isset( $settings['key_expires_utc'] ) ? trim( (string) $settings['key_expires_utc'] ) : '';
		if ( '' === $raw ) {
			return '';
		}

		try {
			$dt = new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$dt->getTimestamp()
		);
	}

	/**
	 * Example PHP for retrieving X-Api-Key in custom code.
	 *
	 * @return string
	 */
	public static function get_developer_snippet() {
		$option = self::OPTION;

		return "// WordPress option name: {$option}\n"
			. "\$settings = get_option( '{$option}', array() );\n\n"
			. "// Decrypted X-Api-Key for custom EpassCard API calls\n"
			. "\$api_key = epc_get_api_key();\n\n"
			. "// Example request\n"
			. "\$response = wp_remote_get(\n"
			. "\t'https://api.epasscard.com/api/public/v1/get-pass-templates?page=1',\n"
			. "\tarray(\n"
			. "\t\t'headers' => array(\n"
			. "\t\t\t'X-Api-Key' => \$api_key,\n"
			. "\t\t),\n"
			. "\t)\n"
			. ");";
	}
}
