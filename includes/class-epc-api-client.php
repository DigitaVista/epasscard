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
 * HTTP wrapper for EpassCard public v1 API.
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
	 * @param string               $url  Full URL.
	 * @param array<string, mixed> $body Request body.
	 * @param bool                 $use_key Send X-Api-Key header.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function post_json( $url, array $body, $use_key = false ) {
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
				'timeout' => 30,
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
	 * @param string               $path Path after v1/.
	 * @param array<string, mixed> $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function put_json( $path, array $body ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard API key is not configured.', 'epasscard' ) );
		}

		$url       = self::api_base() . '/' . ltrim( (string) $path, '/' );
		$body_json = wp_json_encode( $body );

		return self::remote_request(
			'PUT',
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
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
	 * @return array{api_key: string, data: array<string,mixed>}|\WP_Error
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

		return array(
			'api_key' => $key,
			'data'    => $data,
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

		$result = self::post_json(
			self::sign_up_url(),
			array(
				'name'  => $name,
				'email' => $email,
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = (int) ( $result['status'] ?? 0 );
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

		$package = array();
		if ( isset( $data['package_details'] ) && is_array( $data['package_details'] ) ) {
			$package = $data['package_details'];
		}

		$account_email = $email;
		if ( isset( $data['email'] ) ) {
			$from_api = sanitize_email( (string) $data['email'] );
			if ( '' !== $from_api && is_email( $from_api ) ) {
				$account_email = $from_api;
			}
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

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['headers'] = array_merge( self::api_request_headers(), $headers );

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$args['method'] = $method;
			$response       = wp_remote_request( $url, $args );
		}

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
			return $response;
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
}
