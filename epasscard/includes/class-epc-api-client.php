<?php
/**
 * EpassCard public API client.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP wrapper for EpassCard public API (v1 + documented v2 template endpoints).
 */
class EPC_Api_Client {

	/**
	 * Filterable API base (no trailing slash).
	 *
	 * @return string
	 */
	public static function api_base() {
		return rtrim(
			(string) apply_filters(
				'epc_api_base',
				'https://api.epasscard.com/api/public/v1'
			),
			'/'
		);
	}

	/**
	 * Filterable public API v2 base (no trailing slash).
	 *
	 * Live contract: https://app.epasscard.com/doc/api/v2 (OpenAPI at /api/docs/openapi.json).
	 *
	 * @return string
	 */
	public static function api_base_v2() {
		return rtrim(
			(string) apply_filters(
				'epc_api_base_v2',
				'https://api.epasscard.com/api/public/v2'
			),
			'/'
		);
	}

	/**
	 * Validate API key endpoint.
	 *
	 * @return string
	 */
	public static function validate_url() {
		return (string) apply_filters(
			'epc_validate_api_url',
			self::api_base() . '/validate-api-key'
		);
	}

	/**
	 * Generate API key from email/password endpoint.
	 *
	 * @return string
	 */
	public static function generate_key_url() {
		return (string) apply_filters(
			'epc_generate_api_key_url',
			self::api_base() . '/generate-api-key'
		);
	}

	/**
	 * Extend API key expiry endpoint.
	 *
	 * @return string
	 */
	public static function extend_key_url() {
		return (string) apply_filters(
			'epc_extend_api_key_url',
			self::api_base() . '/extend-api-key'
		);
	}

	/**
	 * Public sign-up endpoint.
	 *
	 * @return string
	 */
	public static function sign_up_url() {
		return (string) apply_filters(
			'epc_sign_up_url',
			self::api_base() . '/sign-up'
		);
	}

	/**
	 * Site timezone string for API requests (WordPress Settings → General).
	 *
	 * @return string
	 */
	public static function site_timezone() {
		$timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';

		if ( '' === $timezone ) {
			$timezone = 'UTC';
		}

		/**
		 * Filter timezone sent to EpassCard API.
		 *
		 * @param string $timezone PHP timezone identifier.
		 */
		return (string) apply_filters( 'epc_api_timezone', $timezone );
	}

	/**
	 * Current site origin (protocol + host) for allowed_domains.
	 *
	 * @return string e.g. https://example.com
	 */
	public static function site_domain() {
		$parts  = wp_parse_url( home_url() );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( '' === $host ) {
			return '';
		}

		$origin = $scheme . '://' . $host;

		if ( ! empty( $parts['port'] ) ) {
			$port         = (int) $parts['port'];
			$default_port = ( 'https' === $scheme ) ? 443 : 80;
			if ( $port !== $default_port ) {
				$origin .= ':' . $port;
			}
		}

		/**
		 * Filter allowed_domains sent when generating an API key.
		 *
		 * @param string $origin Site origin (scheme + host, optional port).
		 */
		return (string) apply_filters( 'epc_api_allowed_domains', $origin );
	}

	/**
	 * Headers that identify this WordPress site to the EpassCard API.
	 *
	 * Server-side wp_remote_* calls do not send Origin automatically (unlike browsers).
	 * The API uses X-Request-Origin (and Origin) to match allowed_domains on the API key.
	 *
	 * @return array<string, string>
	 */
	public static function api_request_headers() {
		$origin = self::site_domain();
		if ( '' === $origin ) {
			return array();
		}

		$headers = array(
			'X-Request-Origin' => $origin,
			'Origin'           => $origin,
			'Referer'          => trailingslashit( home_url() ),
		);

		/**
		 * Filter headers sent on every EpassCard API HTTP request.
		 *
		 * @param array<string, string> $headers Request headers.
		 * @param string                $origin  Site origin (scheme + host).
		 */
		return (array) apply_filters( 'epc_api_request_headers', $headers, $origin );
	}

	/**
	 * Default API key expiry (1 year from a base time) in site timezone.
	 *
	 * @param int|null $base_timestamp Unix timestamp base. Null = now.
	 * @return string Y-m-d H:i:s
	 */
	public static function default_expire_at( $base_timestamp = null ) {
		$tz = wp_timezone();

		if ( null === $base_timestamp ) {
			$dt = new DateTimeImmutable( 'now', $tz );
		} else {
			$dt = ( new DateTimeImmutable( '@' . absint( $base_timestamp ) ) )->setTimezone( $tz );
		}

		$expire = $dt->modify( '+1 year' );

		/**
		 * Filter default expire_at for generate/extend API key calls.
		 *
		 * @param string             $expire_at Formatted datetime.
		 * @param DateTimeImmutable  $expire    Expiry object.
		 * @param int|null           $base_timestamp Base timestamp used.
		 */
		return (string) apply_filters(
			'epc_api_default_expire_at',
			$expire->format( 'Y-m-d H:i:s' ),
			$expire,
			$base_timestamp
		);
	}

	/**
	 * Extract expire_at from an API response payload.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return string
	 */
	public static function extract_expire_at( array $data ) {
		foreach ( array( 'expire_at', 'expires_at', 'next_refresh', 'expireAt' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return sanitize_text_field( $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Extract package_details from an API payload.
	 *
	 * Supports sign-up / generate (`package_details`) and validate-api-key
	 * (`organization.package` plus optional `organization.subscription`).
	 *
	 * @param array<string, mixed> $data Response data or merged connection payload.
	 * @return array<string, mixed>
	 */
	public static function extract_package_details( array $data ) {
		$sources = array( $data );
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$sources[] = $data['data'];
		}

		foreach ( $sources as $source ) {
			foreach ( array( 'package_details', 'packageDetails' ) as $key ) {
				if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) {
					return self::normalize_package_payload( $source[ $key ], $source );
				}
			}

			$org = ( isset( $source['organization'] ) && is_array( $source['organization'] ) )
				? $source['organization']
				: array();

			if ( isset( $org['package'] ) && is_array( $org['package'] ) ) {
				return self::normalize_package_payload( $org['package'], $org );
			}

			if ( isset( $source['package'] ) && is_array( $source['package'] ) ) {
				return self::normalize_package_payload( $source['package'], $source );
			}
		}

		return array();
	}

	/**
	 * Map API package objects onto the stored package_details keys.
	 *
	 * Validate-api-key uses `id` / `name`; sign-up uses `package_id` / `package_name`.
	 *
	 * @param array<string, mixed> $package Package object from the API.
	 * @param array<string, mixed> $context Parent object (organization or data) for subscription fields.
	 * @return array<string, mixed>
	 */
	private static function normalize_package_payload( array $package, array $context = array() ) {
		$normalized = array(
			'package_id'     => 0,
			'package_name'   => '',
			'num_of_pass'    => 0,
			'price_per_card' => 0.0,
			'total_price'    => 0.0,
			'billing_period' => '',
		);

		if ( isset( $package['package_id'] ) ) {
			$normalized['package_id'] = absint( $package['package_id'] );
		} elseif ( isset( $package['id'] ) ) {
			$normalized['package_id'] = absint( $package['id'] );
		}

		if ( isset( $package['package_name'] ) ) {
			$normalized['package_name'] = sanitize_text_field( (string) $package['package_name'] );
		} elseif ( isset( $package['name'] ) ) {
			$normalized['package_name'] = sanitize_text_field( (string) $package['name'] );
		}

		if ( isset( $package['num_of_pass'] ) ) {
			$normalized['num_of_pass'] = absint( $package['num_of_pass'] );
		}

		if ( isset( $package['price_per_card'] ) && is_numeric( $package['price_per_card'] ) ) {
			$normalized['price_per_card'] = (float) $package['price_per_card'];
		}

		if ( isset( $package['total_price'] ) && is_numeric( $package['total_price'] ) ) {
			$normalized['total_price'] = (float) $package['total_price'];
		}

		if ( isset( $package['billing_period'] ) ) {
			$normalized['billing_period'] = sanitize_text_field( (string) $package['billing_period'] );
		}

		$subscription = array();
		if ( isset( $context['subscription'] ) && is_array( $context['subscription'] ) ) {
			$subscription = $context['subscription'];
		}

		if ( 0.0 === $normalized['total_price'] && isset( $subscription['price'] ) && is_numeric( $subscription['price'] ) ) {
			$normalized['total_price'] = (float) $subscription['price'];
		}

		if ( '' === $normalized['billing_period'] && isset( $subscription['billing_type'] ) ) {
			$normalized['billing_period'] = sanitize_text_field( (string) $subscription['billing_type'] );
		}

		return $normalized;
	}

	/**
	 * Extract account email from an API payload.
	 *
	 * @param array<string, mixed> $data     Response data or merged connection payload.
	 * @param string               $fallback Email to use when the payload has none.
	 * @return string
	 */
	public static function extract_account_email( array $data, $fallback = '' ) {
		$sources = array( $data );
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$sources[] = $data['data'];
		}

		foreach ( $sources as $source ) {
			if ( isset( $source['email'] ) ) {
				$email = sanitize_email( (string) $source['email'] );
				if ( '' !== $email && is_email( $email ) ) {
					return $email;
				}
			}
		}

		$fallback = sanitize_email( (string) $fallback );

		return ( '' !== $fallback && is_email( $fallback ) ) ? $fallback : '';
	}

	/**
	 * Whether a usable API key is stored.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( EPC_Connection::get_api_key() );
	}

	/**
	 * Sanitize template/pass UUID.
	 *
	 * @param string $uid Raw UID.
	 * @return string|false
	 */
	public static function sanitize_uid( $uid ) {
		$uid = strtolower( trim( (string) $uid ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid ) ) {
			return false;
		}
		return $uid;
	}

	/**
	 * GET request with X-Api-Key.
	 *
	 * @param string $path Path after v1/.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get( $path ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		$url = self::api_base() . '/' . ltrim( (string) $path, '/' );

		return self::remote_request(
			'GET',
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'X-Api-Key' => EPC_Connection::get_api_key(),
				),
			),
			''
		);
	}

	/**
	 * POST JSON with optional API key header.
	 *
	 * @param string               $url     Full URL.
	 * @param array<string, mixed> $body    Request body.
	 * @param bool                 $use_key Send X-Api-Key header.
	 * @param int                  $timeout Request timeout in seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function post_json( $url, array $body, $use_key = false, $timeout = 30 ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( $use_key ) {
			if ( ! self::is_configured() ) {
				return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
			}
			$headers['X-Api-Key'] = EPC_Connection::get_api_key();
		}

		$body_json = wp_json_encode( $body );

		return self::remote_request(
			'POST',
			$url,
			array(
				'timeout' => max( 5, (int) $timeout ),
				'headers' => $headers,
				'body'    => $body_json,
			),
			$body_json,
			true
		);
	}

	/**
	 * PUT JSON with X-Api-Key.
	 *
	 * @param string               $path_or_url Path after v1/, or absolute https URL.
	 * @param array<string, mixed> $body        Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function put_json( $path_or_url, array $body ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		$path_or_url = (string) $path_or_url;
		$url         = preg_match( '#^https?://#i', $path_or_url )
			? $path_or_url
			: self::api_base() . '/' . ltrim( $path_or_url, '/' );
		$body_json   = wp_json_encode( $body );

		return self::remote_request(
			'PUT',
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 60,
				'headers' => array(
					'X-Api-Key'    => EPC_Connection::get_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body_json,
			),
			$body_json,
			true
		);
	}

	/**
	 * Validate remote API key.
	 *
	 * @param string $api_key Plain key.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( '' === $api_key ) {
			return new WP_Error( 'epc_empty_key', __( 'Please enter an API key.', 'epasscard' ) );
		}

		$result = self::post_json(
			self::validate_url(),
			array( 'apiKey' => $api_key ),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 200 !== (int) ( $result['status'] ?? 0 ) ) {
			$msg = isset( $result['message'] ) && is_string( $result['message'] )
				? sanitize_text_field( $result['message'] )
				: __( 'This API key could not be validated.', 'epasscard' );
			return new WP_Error( 'epc_invalid_key', $msg );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['valid'] ) ) {
			return new WP_Error( 'epc_invalid_key', __( 'This API key is not valid.', 'epasscard' ) );
		}

		if ( isset( $data['activeStatus'] ) && false === $data['activeStatus'] ) {
			return new WP_Error( 'epc_inactive_key', __( 'This API key is not active.', 'epasscard' ) );
		}

		$package = self::extract_package_details( $data );
		if ( empty( $package ) ) {
			$package = self::extract_package_details( $result );
		}
		if ( ! empty( $package ) ) {
			$data['package_details'] = $package;
		}

		$email = self::extract_account_email( $data );
		if ( '' === $email ) {
			$email = self::extract_account_email( $result );
		}
		if ( '' !== $email ) {
			$data['email'] = $email;
		}

		return $data;
	}

	/**
	 * Default API key name derived from the site title.
	 *
	 * @return string
	 */
	public static function default_key_name() {
		$name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		if ( '' === $name ) {
			$name = sanitize_text_field( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		if ( '' === $name ) {
			$name = 'WordPress';
		}

		/**
		 * Filter the keyName sent when generating an EpassCard API key.
		 *
		 * @param string $name Site-derived default name.
		 */
		return (string) apply_filters( 'epc_generate_api_key_name', $name );
	}

	/**
	 * Generate API key from account credentials.
	 *
	 * @param string $email    Account email.
	 * @param string $password Account password.
	 * @return array{api_key: string, email: string, package_details: array<string,mixed>, data: array<string,mixed>}|\WP_Error
	 */
	public static function generate_api_key( $email, $password ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'epc_invalid_email', __( 'Please enter a valid email address.', 'epasscard' ) );
		}

		$password = (string) $password;
		if ( '' === $password ) {
			return new WP_Error( 'epc_empty_password', __( 'Please enter your password.', 'epasscard' ) );
		}

		$result = self::post_json(
			self::generate_key_url(),
			array(
				'email'           => $email,
				'password'        => $password,
				'keyName'         => self::default_key_name(),
				'expire_at'       => self::default_expire_at(),
				'timezone'        => self::site_timezone(),
				'allowed_domains' => self::site_domain(),
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 200 !== (int) ( $result['status'] ?? 0 ) ) {
			$msg = isset( $result['message'] ) && is_string( $result['message'] )
				? sanitize_text_field( $result['message'] )
				: __( 'Could not generate an API key with those credentials.', 'epasscard' );
			return new WP_Error( 'epc_generate_failed', $msg );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$key  = '';
		if ( isset( $data['apiKey'] ) ) {
			$key = trim( (string) $data['apiKey'] );
		} elseif ( isset( $data['api_key'] ) ) {
			$key = trim( (string) $data['api_key'] );
		}

		if ( '' === $key ) {
			return new WP_Error( 'epc_generate_incomplete', __( 'The EpassCard server did not return an API key.', 'epasscard' ) );
		}

		$package = self::extract_package_details( $data );
		if ( empty( $package ) ) {
			$package = self::extract_package_details( $result );
		}
		$account_email = self::extract_account_email( $data, $email );
		if ( '' === $account_email ) {
			$account_email = self::extract_account_email( $result, $email );
		}

		return array(
			'api_key'         => $key,
			'email'           => $account_email,
			'package_details' => $package,
			'data'            => $data,
		);
	}

	/**
	 * Create an EpassCard account and receive an API key.
	 *
	 * @param string $name  Name or business name.
	 * @param string $email Account email.
	 * @return array{api_key: string, email: string, package_details: array<string,mixed>, message: string, data: array<string,mixed>}|\WP_Error
	 */
	public static function sign_up( $name, $email ) {
		$name  = sanitize_text_field( (string) $name );
		$email = sanitize_email( (string) $email );

		if ( '' === $name ) {
			return new WP_Error( 'epc_empty_name', __( 'Please enter a name or business name.', 'epasscard' ) );
		}

		$name_len = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
		if ( $name_len < 2 ) {
			return new WP_Error( 'epc_short_name', __( 'Name must be at least 2 characters.', 'epasscard' ) );
		}
		if ( $name_len > 255 ) {
			return new WP_Error( 'epc_long_name', __( 'Name must be 255 characters or fewer.', 'epasscard' ) );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'epc_invalid_email', __( 'Please enter a valid email address.', 'epasscard' ) );
		}

		$email_len = function_exists( 'mb_strlen' ) ? mb_strlen( $email, 'UTF-8' ) : strlen( $email );
		if ( $email_len > 254 ) {
			return new WP_Error( 'epc_invalid_email', __( 'Please enter a valid email address.', 'epasscard' ) );
		}

		$payload = array(
			'name'  => $name,
			'email' => $email,
		);

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$result = self::post_json( self::sign_up_url(), $payload, false, 45 );
		if ( self::is_timeout_error( $result ) ) {
			$result = self::post_json( self::sign_up_url(), $payload, false, 45 );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = (int) ( $result['status'] ?? 0 );
		if ( 409 === $status ) {
			return new WP_Error(
				'epc_sign_up_exists',
				__( 'An account with this email already exists. Use Sign in and connect with the password that was emailed to you.', 'epasscard' )
			);
		}
		if ( 201 !== $status && 200 !== $status ) {
			$msg = isset( $result['message'] ) && is_string( $result['message'] )
				? sanitize_text_field( $result['message'] )
				: __( 'Could not create an EpassCard account with those details.', 'epasscard' );
			return new WP_Error( 'epc_sign_up_failed', $msg );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$key  = '';
		if ( isset( $data['api_key'] ) ) {
			$key = trim( (string) $data['api_key'] );
		} elseif ( isset( $data['apiKey'] ) ) {
			$key = trim( (string) $data['apiKey'] );
		}

		if ( '' === $key ) {
			return new WP_Error( 'epc_sign_up_incomplete', __( 'The EpassCard server did not return an API key.', 'epasscard' ) );
		}

		$package       = self::extract_package_details( $data );
		if ( empty( $package ) ) {
			$package = self::extract_package_details( $result );
		}
		$account_email = self::extract_account_email( $data, $email );
		if ( '' === $account_email ) {
			$account_email = self::extract_account_email( $result, $email );
		}

		$message = '';
		if ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
			$message = sanitize_text_field( $result['message'] );
		}

		return array(
			'api_key'         => $key,
			'email'           => $account_email,
			'package_details' => $package,
			'message'         => $message,
			'data'            => $data,
		);
	}

	/**
	 * Extend stored API key expiry by one year.
	 *
	 * @param string|null $api_key Optional plain key; uses stored key when null.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function extend_api_key( $api_key = null ) {
		$api_key = null === $api_key ? EPC_Connection::get_api_key() : trim( (string) $api_key );

		if ( '' === $api_key ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		$base_timestamp = EPC_Connection::get_key_expires_timestamp();
		if ( $base_timestamp <= time() ) {
			$base_timestamp = null;
		}

		$result = self::post_json(
			self::extend_key_url(),
			array(
				'apiKey'    => $api_key,
				'expire_at' => self::default_expire_at( $base_timestamp ),
				'timezone'  => self::site_timezone(),
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 200 !== (int) ( $result['status'] ?? 0 ) ) {
			$msg = isset( $result['message'] ) && is_string( $result['message'] )
				? sanitize_text_field( $result['message'] )
				: __( 'Could not extend the API key.', 'epasscard' );
			return new WP_Error( 'epc_extend_failed', $msg );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		$expire_at = self::extract_expire_at( $data );

		if ( '' === $expire_at ) {
			$expire_at = self::default_expire_at( $base_timestamp );
		}

		return array(
			'expire_at' => $expire_at,
			'data'      => $data,
		);
	}

	/**
	 * Create a pass template via public API v2 (simplified loyalty-compatible contract).
	 *
	 * POST /api/public/v2/create-pass-template
	 *
	 * @param array<string, mixed> $payload Sanitized create payload.
	 * @return array<string,mixed>|\WP_Error Template data (uid, fields, …).
	 */
	public static function create_pass_template_v2( array $payload ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( 'loyalty:create_pass_template_v2' );
		}

		$url       = self::api_base_v2() . '/create-pass-template';
		$body_json = wp_json_encode( $payload );
		$body      = self::remote_request(
			'POST',
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'X-Api-Key'    => EPC_Connection::get_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body_json,
			),
			$body_json,
			true
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$status = (int) ( $body['status'] ?? 0 );
		if ( 201 !== $status && 200 !== $status ) {
			$msg = isset( $body['message'] ) && is_string( $body['message'] )
				? sanitize_text_field( $body['message'] )
				: __( 'The loyalty pass template could not be created.', 'epasscard' );
			return new WP_Error( 'epc_template_create_failed', $msg, array( 'response' => $body ) );
		}

		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
		$uid  = isset( $data['uid'] ) ? self::sanitize_uid( (string) $data['uid'] ) : false;
		if ( false === $uid ) {
			return new WP_Error( 'epc_template_create_incomplete', __( 'The EpassCard response did not include a template UID.', 'epasscard' ) );
		}

		$data['uid'] = $uid;
		return $data;
	}

	/**
	 * Update a pass template via the public API v2 simplified contract.
	 *
	 * PUT /api/public/v2/update-pass-template/{templateUid}
	 *
	 * Request body is the same simplified object as create-pass-template.
	 *
	 * @param string               $template_uid Template UUID from create.
	 * @param array<string, mixed> $payload      Same simplified shape as create_pass_template_v2().
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function update_pass_template_v2( $template_uid, array $payload ) {
		$san = self::sanitize_uid( $template_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid template identifier.', 'epasscard' ) );
		}

		if ( ! self::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( 'loyalty:update_pass_template_v2' );
		}

		$url       = self::api_base_v2() . '/update-pass-template/' . rawurlencode( $san );
		$body_json = wp_json_encode( $payload );
		$body      = self::remote_request(
			'PUT',
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'X-Api-Key'    => EPC_Connection::get_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body_json,
			),
			$body_json,
			true
		);

		if ( is_wp_error( $body ) ) {
			$status = (int) ( $body->get_error_data()['status'] ?? 0 );
			if ( 404 === $status ) {
				return new WP_Error(
					'epc_template_update_unavailable',
					__( 'The EpassCard API could not find that pass template to update. Check the template UID and API Log.', 'epasscard' ),
					array( 'status' => 404 )
				);
			}
			return $body;
		}

		$status = (int) ( $body['status'] ?? 0 );
		if ( 200 !== $status && 201 !== $status && 0 !== $status ) {
			$msg = isset( $body['message'] ) && is_string( $body['message'] )
				? sanitize_text_field( $body['message'] )
				: __( 'The pass template could not be updated.', 'epasscard' );
			return new WP_Error( 'epc_template_update_failed', $msg, array( 'response' => $body ) );
		}

		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
		$uid  = isset( $data['uid'] ) ? self::sanitize_uid( (string) $data['uid'] ) : false;
		if ( false === $uid ) {
			$data['uid'] = $san;
		} else {
			$data['uid'] = $uid;
		}

		return $data;
	}

	/**
	 * Read one template (v1 details endpoint referenced by v2 create responses).
	 *
	 * GET /api/public/v1/template-details/{uid}
	 *
	 * @param string $template_uid Template UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get_template_details( $template_uid ) {
		$san = self::sanitize_uid( $template_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid template identifier.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( 'loyalty:get_template_details' );
		}

		$body = self::get( 'template-details/' . rawurlencode( $san ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
		if ( empty( $data['template_uid'] ) && empty( $data['uid'] ) ) {
			$data['template_uid'] = $san;
		}

		return $data;
	}

	/**
	 * Fetch pass templates (paginated).
	 *
	 * @param int $page Page number.
	 * @return array{templates: array<int,array<string,mixed>>, total_templates: int}|\WP_Error
	 */
	public static function get_templates( $page = 1 ) {
		$page = max( 1, absint( $page ) );
		$body = self::get( 'get-pass-templates?page=' . $page );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$templates = array();
		if ( isset( $body['templates'] ) && is_array( $body['templates'] ) ) {
			$templates = $body['templates'];
		} elseif ( isset( $body['data']['templates'] ) && is_array( $body['data']['templates'] ) ) {
			$templates = $body['data']['templates'];
		}

		$total = 0;
		if ( isset( $body['total']['total_templates'] ) ) {
			$total = absint( $body['total']['total_templates'] );
		} elseif ( isset( $body['data']['total']['total_templates'] ) ) {
			$total = absint( $body['data']['total']['total_templates'] );
		}

		return array(
			'templates'       => array_values( $templates ),
			'total_templates' => $total,
		);
	}

	/**
	 * Fetch pass field definitions for a template.
	 *
	 * @param string $template_uid Template UUID.
	 * @return array{passFields: array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function get_pass_fields( $template_uid ) {
		$san = self::sanitize_uid( $template_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid template identifier.', 'epasscard' ) );
		}

		$body = self::get( 'pass-fields/' . rawurlencode( $san ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$fields = array();
		if ( isset( $body['passFields'] ) && is_array( $body['passFields'] ) ) {
			$fields = $body['passFields'];
		} elseif ( isset( $body['data']['passFields'] ) && is_array( $body['data']['passFields'] ) ) {
			$fields = $body['data']['passFields'];
		}

		return array(
			'passFields' => array_values( $fields ),
		);
	}

	/**
	 * Create a wallet pass.
	 *
	 * @param string              $template_uid Template UUID.
	 * @param array<int, array{uid: string, fieldValue: string}> $fields Field values.
	 * @return array{passUid: string, passLink: string}|\WP_Error
	 */
	public static function create_pass( $template_uid, array $fields ) {
		$san = self::sanitize_uid( $template_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid template identifier.', 'epasscard' ) );
		}

		$body = self::post_json(
			self::api_base() . '/create-single-pass/' . rawurlencode( $san ),
			array(
				'additionalFieldsValue' => array_values( $fields ),
			),
			true
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			$msg = isset( $body['message'] ) && is_string( $body['message'] )
				? sanitize_text_field( $body['message'] )
				: __( 'Pass could not be created.', 'epasscard' );
			return new WP_Error( 'epc_create_failed', $msg );
		}

		$pass_uid  = isset( $body['passUid'] ) ? (string) $body['passUid'] : '';
		$pass_link = isset( $body['passLink'] ) ? (string) $body['passLink'] : '';

		if ( '' === $pass_uid || '' === $pass_link ) {
			return new WP_Error( 'epc_create_incomplete', __( 'The EpassCard response did not include pass details.', 'epasscard' ) );
		}

		return array(
			'passUid'  => $pass_uid,
			'passLink' => $pass_link,
		);
	}

	/**
	 * Update an existing pass.
	 *
	 * @param string              $pass_uid Pass UUID.
	 * @param array<int, array{uid: string, field_value: string}> $fields Fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function update_pass( $pass_uid, array $fields ) {
		$san = self::sanitize_uid( $pass_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid pass identifier.', 'epasscard' ) );
		}

		$body = self::put_json(
			'update-single-pass',
			array(
				'passUid' => $san,
				'fields'  => array_values( $fields ),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( 200 !== (int) ( $body['status'] ?? 0 ) && 0 !== (int) ( $body['status'] ?? 0 ) ) {
			$msg = isset( $body['message'] ) && is_string( $body['message'] )
				? sanitize_text_field( $body['message'] )
				: __( 'Pass could not be updated.', 'epasscard' );
			return new WP_Error( 'epc_update_failed', $msg );
		}

		return $body;
	}

	/**
	 * Push notification URL for a pass.
	 *
	 * @param string $pass_uid Pass UUID.
	 * @return string
	 */
	public static function send_push_url( $pass_uid ) {
		$san = self::sanitize_uid( $pass_uid );
		if ( false === $san ) {
			return '';
		}

		/**
		 * Filter push notification endpoint URL.
		 *
		 * @param string $url      Full POST URL including pass id.
		 * @param string $pass_uid Sanitized pass UUID.
		 */
		return (string) apply_filters(
			'epc_send_push_notification_url',
			self::api_base() . '/send-pass-notification/' . rawurlencode( $san ),
			$san
		);
	}

	/**
	 * Combine push title and message for the API body.
	 *
	 * @param string $title   Push title.
	 * @param string $message Push message.
	 * @return string
	 */
	public static function build_push_notification_message( $title, $message ) {
		$title   = trim( sanitize_text_field( (string) $title ) );
		$message = trim( sanitize_textarea_field( (string) $message ) );

		if ( '' === $title ) {
			return $message;
		}

		if ( '' === $message ) {
			return $title;
		}

		return $title . "\n\n" . $message;
	}

	/**
	 * Send a push notification to a wallet pass.
	 *
	 * POST /send-pass-notification/{passId} with body { "message": "..." }.
	 *
	 * @param string $pass_uid Pass UUID.
	 * @param string $title    Notification title.
	 * @param string $message  Notification body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function send_pass_push( $pass_uid, $title, $message ) {
		$san = self::sanitize_uid( $pass_uid );
		if ( false === $san ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid pass identifier.', 'epasscard' ) );
		}

		$combined = self::build_push_notification_message( $title, $message );
		if ( '' === $combined ) {
			return new WP_Error( 'epc_empty_push', __( 'Notification title and message are required.', 'epasscard' ) );
		}

		$url = self::send_push_url( $san );
		if ( '' === $url ) {
			return new WP_Error( 'epc_bad_uid', __( 'Invalid pass identifier.', 'epasscard' ) );
		}

		$body = array(
			'message' => $combined,
		);

		/**
		 * Filter push notification request body.
		 *
		 * @param array<string, string> $body     Request body (message key).
		 * @param string                $pass_uid Pass UUID.
		 * @param string                $title    Original push title.
		 * @param string                $message  Original push message.
		 */
		$body = (array) apply_filters( 'epc_send_push_notification_body', $body, $san, $title, $message );

		$result = self::post_json( $url, $body, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = (int) ( $result['status'] ?? 0 );
		if ( 200 !== $status && 0 !== $status ) {
			$msg = isset( $result['message'] ) && is_string( $result['message'] )
				? sanitize_text_field( $result['message'] )
				: __( 'Push notification could not be sent.', 'epasscard' );
			return new WP_Error( 'epc_push_failed', $msg );
		}

		return $result;
	}

	/**
	 * Perform an HTTP request and log the exchange.
	 *
	 * @param string               $method          HTTP method.
	 * @param string               $url             Full URL.
	 * @param array<string, mixed> $args            wp_remote_* args.
	 * @param string               $request_body    Body used for logging.
	 * @param bool                 $allow_non_200   Allow 2xx besides 200 in parse_response.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function remote_request( $method, $url, array $args, $request_body = '', $allow_non_200 = false ) {
		$started_at = microtime( true );
		$method     = strtoupper( (string) $method );

		$headers         = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['headers'] = array_merge( self::api_request_headers(), $headers );
		// WordPress defaults to HTTP/1.0, which can hang behind Cloudflare with 0 bytes received.
		if ( empty( $args['httpversion'] ) ) {
			$args['httpversion'] = '1.1';
		}

		$force_ipv4 = static function ( $handle ) {
			if ( defined( 'CURL_IPRESOLVE_V4' ) ) {
				curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			}
		};
		add_action( 'http_api_curl', $force_ipv4 );

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$args['method'] = $method;
			$response       = wp_remote_request( $url, $args );
		}

		remove_action( 'http_api_curl', $force_ipv4 );

		$parsed = self::parse_response( $response, $allow_non_200 );

		if ( class_exists( 'EPC_Api_Log' ) ) {
			$log_body = (string) $request_body;
			if ( '' === $log_body && isset( $args['body'] ) ) {
				$log_body = is_string( $args['body'] ) ? $args['body'] : (string) wp_json_encode( $args['body'] );
			}
			if ( '' === $log_body && 'GET' === $method ) {
				$query = wp_parse_url( $url, PHP_URL_QUERY );
				if ( is_string( $query ) && '' !== $query ) {
					$log_body = $query;
				}
			}

			EPC_Api_Log::log_request( $method, $url, $log_body, $response, $parsed, $started_at );
		}

		return $parsed;
	}

	/**
	 * Parse HTTP response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Remote response.
	 * @param bool                            $allow_non_200 Allow 2xx besides 200.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function parse_response( $response, $allow_non_200 = false ) {
		if ( is_wp_error( $response ) ) {
			return self::normalize_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code && ! ( $allow_non_200 && $code >= 200 && $code < 300 ) ) {
			$msg = __( 'The EpassCard API returned an error. Please try again later.', 'epasscard' );
			if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) && '' !== trim( $data['message'] ) ) {
				$msg = sanitize_text_field( $data['message'] );
			}

			return new WP_Error(
				'epc_http',
				$msg,
				array( 'status' => $code )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'epc_bad_json', __( 'The EpassCard server returned an unexpected response.', 'epasscard' ) );
		}

		return $data;
	}

	/**
	 * Whether a request failed because the remote server did not answer in time.
	 *
	 * @param mixed $result Parsed response or error.
	 * @return bool
	 */
	private static function is_timeout_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$data = $result->get_error_data();
		return is_array( $data ) && ! empty( $data['timeout'] );
	}

	/**
	 * Replace raw cURL transport errors with a message merchants can act on.
	 *
	 * @param WP_Error $error Transport error.
	 * @return WP_Error
	 */
	private static function normalize_transport_error( WP_Error $error ) {
		$message = $error->get_error_message();
		$timeout = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
		if ( $timeout ) {
			return new WP_Error(
				'epc_timeout',
				__( 'EpassCard took too long to respond. If a password email arrived, use Sign in and connect. Otherwise try again in a moment.', 'epasscard' ),
				array( 'timeout' => true )
			);
		}

		if ( 'http_request_failed' === $error->get_error_code() ) {
			return new WP_Error(
				'epc_http_transport',
				__( 'Could not reach EpassCard. Check your connection and try again.', 'epasscard' )
			);
		}

		return $error;
	}
}
