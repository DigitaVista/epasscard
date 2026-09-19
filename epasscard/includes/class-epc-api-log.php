<?php
/**
 * API request log storage and retention.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists EpassCard HTTP API calls for debugging.
 */
class EPC_Api_Log {

	public const TABLE = 'epc_api_logs';

	public const CRON_HOOK = 'epc_purge_api_logs_event';

	public const OPTION_RETENTION_DAYS = 'epc_api_log_retention_days';

	/**
	 * Optional context for the next request(s) in this request cycle.
	 *
	 * @var string|null
	 */
	private static ?string $request_context = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge_expired' ) );
		add_action( 'wp_ajax_epc_save_api_log_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_epc_purge_api_logs', array( __CLASS__, 'ajax_purge_logs' ) );
		add_action( 'wp_ajax_epc_clear_api_logs', array( __CLASS__, 'ajax_clear_logs' ) );
	}

	/**
	 * Schedule daily purge.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled purge.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Create or upgrade the log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			method varchar(10) NOT NULL DEFAULT '',
			endpoint_url text NOT NULL,
			request_body longtext NULL,
			response_body longtext NULL,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			is_success tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(64) NOT NULL DEFAULT '',
			context varchar(191) NOT NULL DEFAULT '',
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY is_success (is_success)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( false === get_option( self::OPTION_RETENTION_DAYS, false ) ) {
			add_option( self::OPTION_RETENTION_DAYS, 30, '', false );
		}
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Retention period in days.
	 *
	 * @return int
	 */
	public static function get_retention_days() {
		$days = (int) get_option( self::OPTION_RETENTION_DAYS, 30 );
		$days = max( 1, min( 365, $days ) );

		/**
		 * Filter API log retention in days.
		 *
		 * @param int $days Retention days.
		 */
		return (int) apply_filters( 'epc_api_log_retention_days', $days );
	}

	/**
	 * Set context label for subsequent API calls in this request.
	 *
	 * @param string|null $context Short label (e.g. memberpress:sync).
	 * @return void
	 */
	public static function set_request_context( $context ) {
		self::$request_context = is_string( $context ) && '' !== trim( $context )
			? sanitize_text_field( $context )
			: null;
	}

	/**
	 * Resolve context for a log row.
	 *
	 * @return string
	 */
	public static function get_request_context() {
		if ( null !== self::$request_context && '' !== self::$request_context ) {
			return self::$request_context;
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request metadata for logging; not form processing.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
			if ( str_starts_with( $action, 'epc_' ) ) {
				return 'ajax:' . $action;
			}
			return 'ajax';
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user:' . $user_id;
		}

		return 'system';
	}

	/**
	 * Record an API call.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL.
	 * @param string               $request_body Request body (JSON).
	 * @param array|\WP_Error|null $response     Raw HTTP response.
	 * @param array|\WP_Error      $parsed       Parsed API payload.
	 * @param float                $started_at   microtime(true) at start.
	 * @return void
	 */
	public static function log_request( $method, $url, $request_body, $response, $parsed, $started_at ) {
		if ( ! apply_filters( 'epc_api_log_enabled', true ) ) {
			return;
		}

		$method = strtoupper( sanitize_text_field( (string) $method ) );
		$url    = esc_url_raw( (string) $url );

		$http_status   = 0;
		$response_body = '';
		$error_code    = '';
		$is_success    = false;

		if ( is_wp_error( $response ) ) {
			$error_code    = $response->get_error_code();
			$response_body = wp_json_encode(
				array(
					'error'   => $response->get_error_message(),
					'code'    => $error_code,
					'data'    => $response->get_error_data(),
				)
			);
		} else {
			$http_status   = (int) wp_remote_retrieve_response_code( $response );
			$response_body = (string) wp_remote_retrieve_body( $response );
		}

		if ( is_wp_error( $parsed ) ) {
			$is_success = false;
			if ( '' === $error_code ) {
				$error_code = $parsed->get_error_code();
			}
		} else {
			$is_success = $http_status >= 200 && $http_status < 300;
		}

		$duration_ms = (int) round( ( microtime( true ) - (float) $started_at ) * 1000 );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$wpdb->insert(
			self::table_name(),
			array(
				'method'        => $method,
				'endpoint_url'  => $url,
				'request_body'  => self::redact_body( $request_body ),
				'response_body' => self::truncate_body( $response_body ),
				'http_status'   => max( 0, $http_status ),
				'is_success'    => $is_success ? 1 : 0,
				'error_code'    => sanitize_key( (string) $error_code ),
				'context'       => self::get_request_context(),
				'duration_ms'   => max( 0, $duration_ms ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Query log rows.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table  = self::table_name();
		$page   = max( 1, absint( $args['page'] ?? 1 ) );
		$limit  = max( 1, min( 100, absint( $args['per_page'] ?? 25 ) ) );
		$offset = ( $page - 1 ) * $limit;
		$search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );

		$has_success = isset( $args['is_success'] ) && '' !== (string) $args['is_success'];
		$has_search  = '' !== $search;
		$success_val = $has_success ? ( '1' === (string) $args['is_success'] ? 1 : 0 ) : 0;
		$like        = $has_search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table admin list.
		if ( ! $has_success && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE 1=1',
					$table
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE 1=1 ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$limit,
					$offset
				)
			);
		} elseif ( $has_success && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE is_success = %d',
					$table,
					$success_val
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE is_success = %d ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$success_val,
					$limit,
					$offset
				)
			);
		} elseif ( ! $has_success && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE (endpoint_url LIKE %s OR request_body LIKE %s OR response_body LIKE %s OR context LIKE %s OR error_code LIKE %s)',
					$table,
					$like,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE (endpoint_url LIKE %s OR request_body LIKE %s OR response_body LIKE %s OR context LIKE %s OR error_code LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$like,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE is_success = %d AND (endpoint_url LIKE %s OR request_body LIKE %s OR response_body LIKE %s OR context LIKE %s OR error_code LIKE %s)',
					$table,
					$success_val,
					$like,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE is_success = %d AND (endpoint_url LIKE %s OR request_body LIKE %s OR response_body LIKE %s OR context LIKE %s OR error_code LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$success_val,
					$like,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete logs older than retention setting.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_expired() {
		global $wpdb;

		$days  = self::get_retention_days();
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table retention purge.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Delete all log rows.
	 *
	 * @return int Rows deleted.
	 */
	public static function clear_all() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table truncate-all.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$table
			)
		);
	}

	/**
	 * AJAX: save log retention settings.
	 *
	 * @return void
	 */
	public static function ajax_save_settings() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$days = isset( $_POST['epc_api_log_retention_days'] ) ? absint( wp_unslash( $_POST['epc_api_log_retention_days'] ) ) : 30;
		update_option( self::OPTION_RETENTION_DAYS, max( 1, min( 365, $days ) ) );

		wp_send_json_success(
			array(
				'message' => __( 'Log settings saved.', 'epasscard' ),
			)
		);
	}

	/**
	 * AJAX: purge expired log entries.
	 *
	 * @return void
	 */
	public static function ajax_purge_logs() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$deleted = self::purge_expired();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of deleted rows */
					__( 'Removed %d expired log entries.', 'epasscard' ),
					(int) $deleted
				),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX: clear all log entries.
	 *
	 * @return void
	 */
	public static function ajax_clear_logs() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$deleted = self::clear_all();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of deleted rows */
					__( 'Removed %d log entries.', 'epasscard' ),
					(int) $deleted
				),
				'reload'  => true,
			)
		);
	}

	/**
	 * @deprecated 1.0.0 Replaced by AJAX handlers.
	 * @return void
	 */
	public static function handle_admin_actions() {
		// Legacy POST handler removed — use ajax_* methods.
	}

	/**
	 * Redact secrets from a JSON request body string.
	 *
	 * @param string $body JSON body.
	 * @return string
	 */
	private static function redact_body( $body ) {
		$body = (string) $body;
		if ( '' === $body ) {
			return '';
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return self::truncate_body( $body );
		}

		foreach ( array( 'apiKey', 'api_key', 'password', 'key' ) as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				$decoded[ $key ] = '***';
			}
		}

		$encoded = wp_json_encode( $decoded );

		return self::truncate_body( false === $encoded ? $body : (string) $encoded );
	}

	/**
	 * Limit stored payload size.
	 *
	 * @param string $body Body text.
	 * @return string
	 */
	private static function truncate_body( $body ) {
		$body = (string) $body;
		$max  = (int) apply_filters( 'epc_api_log_max_body_length', 65535 );

		if ( strlen( $body ) <= $max ) {
			return $body;
		}

		return substr( $body, 0, $max ) . '…[truncated]';
	}
}
