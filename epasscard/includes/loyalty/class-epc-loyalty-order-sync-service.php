<?php
/**
 * Batched historical WooCommerce order credit for loyalty.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional, admin-started backfill of existing orders. Live orders are never
 * credited automatically just because the module was enabled.
 */
class EPC_Loyalty_Order_Sync_Service {

	public const OPTION_KEY = 'epc_loyalty_order_sync';
	public const CRON_HOOK  = 'epc_loyalty_order_sync_batch';
	public const BATCH_SIZE = 25;

	/**
	 * Whether the current request is inside a historical credit batch.
	 *
	 * @var bool
	 */
	private static $in_batch = false;

	/**
	 * Current batch options.
	 *
	 * @var array<string, mixed>
	 */
	private static $batch_job = array();

	/**
	 * Register processors and admin AJAX.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( 'wp_ajax_epc_loyalty_order_sync_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_epc_loyalty_order_sync_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_epc_loyalty_order_sync_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_epc_loyalty_order_sync_cancel', array( __CLASS__, 'ajax_cancel' ) );
	}

	/**
	 * Whether pass API sync should be skipped for this historical batch.
	 *
	 * @return bool
	 */
	public static function should_skip_pass_sync() {
		return self::$in_batch && empty( self::$batch_job['sync_passes'] );
	}

	/**
	 * Saved job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge( self::empty_job(), $saved );
	}

	/**
	 * Preview how many registered-customer orders would be scanned.
	 *
	 * @return void
	 */
	public static function ajax_preview() {
		self::assert_can_manage();
		$args  = self::sanitize_request_args();
		$count = self::count_orders( $args['from'], $args['to'] );
		wp_send_json_success(
			array(
				'count'   => $count,
				'message' => sprintf(
					/* translators: %s: number of orders */
					_n( '%s registered-customer order matches this range.', '%s registered-customer orders match this range.', $count, 'epasscard' ),
					number_format_i18n( $count )
				),
			)
		);
	}

	/**
	 * Start a historical credit job.
	 *
	 * @return void
	 */
	public static function ajax_start() {
		self::assert_can_manage();

		$current = self::get_job();
		if ( 'running' === $current['status'] ) {
			wp_send_json_error( array( 'message' => __( 'A past-order sync is already running.', 'epasscard' ) ), 409 );
		}

		$args = self::sanitize_request_args();
		$job  = array_merge(
			self::empty_job(),
			array(
				'status'        => 'running',
				'from'          => $args['from'],
				'to'            => $args['to'],
				'grant_rewards' => $args['grant_rewards'],
				'sync_passes'   => $args['sync_passes'],
				'found'         => self::count_orders( $args['from'], $args['to'] ),
				'started_at'    => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		self::save_job( $job );
		self::queue_batch( time() );

		wp_send_json_success(
			array(
				'job'     => self::public_job( $job ),
				'message' => __( 'Past-order sync queued. It runs in the background in small batches.', 'epasscard' ),
			)
		);
	}

	/**
	 * Return current job progress.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		self::assert_can_manage();
		$job = self::get_job();
		wp_send_json_success( array( 'job' => self::public_job( $job ) ) );
	}

	/**
	 * Stop a running job after the current batch.
	 *
	 * @return void
	 */
	public static function ajax_cancel() {
		self::assert_can_manage();
		$job = self::get_job();
		if ( 'running' === $job['status'] ) {
			$job['status']      = 'cancelled';
			$job['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			$job['updated_at']  = gmdate( 'Y-m-d H:i:s' );
			self::save_job( $job );
		}
		self::unschedule();
		wp_send_json_success(
			array(
				'job'     => self::public_job( self::get_job() ),
				'message' => __( 'Past-order sync cancelled. Already credited orders stay credited.', 'epasscard' ),
			)
		);
	}

	/**
	 * Process one cursor batch of historical orders.
	 *
	 * @return void
	 */
	public static function process_batch() {
		global $wpdb;

		$job = self::get_job();
		if ( 'running' !== $job['status'] ) {
			return;
		}

		$lock_name = 'epc_loyalty_order_sync';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-flight historical batch.
		$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== (string) $lock ) {
			self::queue_batch( time() + 30 );
			return;
		}

		$job = self::get_job();
		if ( 'running' !== $job['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release unused lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return;
		}

		self::$in_batch  = true;
		self::$batch_job = $job;
		EPC_Loyalty_Notification_Service::suppress();
		EPC_Loyalty_Rule_Service::use_earliest_order_for_first_order_rules( true );
		if ( empty( $job['grant_rewards'] ) ) {
			EPC_Loyalty_Reward_Service::set_claim_mode( 'waive' );
		}

		try {
			$ids = self::query_order_ids( $job['from'], $job['to'], (int) $job['cursor_id'], self::BATCH_SIZE );
			if ( empty( $ids ) ) {
				$job['status']      = 'completed';
				$job['finished_at'] = gmdate( 'Y-m-d H:i:s' );
				$job['updated_at']  = gmdate( 'Y-m-d H:i:s' );
				self::save_job( $job );
				return;
			}

			foreach ( $ids as $order_id ) {
				$job['cursor_id']  = $order_id;
				$job['processed']  = (int) $job['processed'] + 1;
				$job['updated_at'] = gmdate( 'Y-m-d H:i:s' );

				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				if ( ! $order instanceof WC_Order ) {
					$job['skipped'] = (int) $job['skipped'] + 1;
					continue;
				}

				$result = EPC_Loyalty_Order_Service::credit_existing_order( $order );
				if ( is_wp_error( $result ) ) {
					$job['errors']     = (int) $job['errors'] + 1;
					$job['last_error'] = $result->get_error_message();
					continue;
				}

				$job['awarded']  = (int) $job['awarded'] + (int) ( $result['awarded'] ?? 0 );
				$job['skipped']  = (int) $job['skipped'] + (int) ( $result['skipped'] ?? 0 );
				$job['refunded'] = (int) $job['refunded'] + (int) ( $result['refunded'] ?? 0 );
			}

			$latest = self::get_job();
			if ( 'cancelled' === ( $latest['status'] ?? '' ) ) {
				$job['status']      = 'cancelled';
				$job['finished_at'] = (string) ( $latest['finished_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
			}
			self::save_job( $job );
			if ( 'running' === ( $job['status'] ?? '' ) ) {
				self::queue_batch( time() + 2 );
			}
		} finally {
			EPC_Loyalty_Reward_Service::set_claim_mode( '' );
			EPC_Loyalty_Rule_Service::use_earliest_order_for_first_order_rules( false );
			EPC_Loyalty_Notification_Service::restore();
			self::$in_batch  = false;
			self::$batch_job = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the matching advisory lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Default empty job.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_job() {
		return array(
			'status'        => 'idle',
			'from'          => '',
			'to'            => '',
			'grant_rewards' => false,
			'sync_passes'   => true,
			'cursor_id'     => 0,
			'found'         => 0,
			'processed'     => 0,
			'awarded'       => 0,
			'skipped'       => 0,
			'refunded'      => 0,
			'errors'        => 0,
			'last_error'    => '',
			'started_at'    => '',
			'finished_at'   => '',
			'updated_at'    => '',
		);
	}

	/**
	 * Persist job state without autoload.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private static function save_job( array $job ) {
		update_option( self::OPTION_KEY, $job, false );
	}

	/**
	 * Job payload safe for admin JSON.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function public_job( array $job ) {
		$found      = max( 0, (int) $job['found'] );
		$processed  = max( 0, (int) $job['processed'] );
		$percent    = $found > 0 ? min( 100, (int) floor( ( $processed / $found ) * 100 ) ) : ( 'completed' === $job['status'] ? 100 : 0 );
		$job['percent'] = $percent;
		return $job;
	}

	/**
	 * Clear queued historical batches.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		self::unschedule();
	}

	/**
	 * Queue the next batch via Action Scheduler when available.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return void
	 */
	private static function queue_batch( $timestamp ) {
		self::unschedule();
		$timestamp = max( time(), absint( $timestamp ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::CRON_HOOK, array(), 'epasscard-loyalty', true );
			return;
		}
		wp_schedule_single_event( $timestamp, self::CRON_HOOK );
	}

	/**
	 * Clear queued batches.
	 *
	 * @return void
	 */
	private static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), 'epasscard-loyalty' );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Sanitize start/preview fields.
	 *
	 * @return array{from: string, to: string, grant_rewards: bool, sync_passes: bool}
	 */
	private static function sanitize_request_args() {
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '';
		$from = self::sanitize_date( $from );
		$to   = self::sanitize_date( $to );

		return array(
			'from'          => $from,
			'to'            => $to,
			'grant_rewards' => ! empty( $_POST['grant_rewards'] ),
			'sync_passes'   => ! isset( $_POST['sync_passes'] ) || ! empty( $_POST['sync_passes'] ),
		);
	}

	/**
	 * Y-m-d or empty.
	 *
	 * @param string $value Date.
	 * @return string
	 */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $value );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * Count matching orders.
	 *
	 * @param string $from Y-m-d or empty.
	 * @param string $to Y-m-d or empty.
	 * @return int
	 */
	private static function count_orders( $from, $to ) {
		global $wpdb;

		$sql = self::order_sql( $from, $to, 0, 0, true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Built from trusted table/status lists.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Next order IDs after a cursor.
	 *
	 * @param string $from Y-m-d or empty.
	 * @param string $to Y-m-d or empty.
	 * @param int    $cursor Last processed ID.
	 * @param int    $limit Batch size.
	 * @return array<int, int>
	 */
	private static function query_order_ids( $from, $to, $cursor, $limit ) {
		global $wpdb;

		$sql = self::order_sql( $from, $to, $cursor, $limit, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Built from trusted table/status lists.
		$ids = $wpdb->get_col( $sql );
		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * HPOS or CPT SQL for registered-customer shop orders.
	 *
	 * @param string $from Y-m-d or empty.
	 * @param string $to Y-m-d or empty.
	 * @param int    $cursor Last ID.
	 * @param int    $limit Limit (0 for count).
	 * @param bool   $count Count only.
	 * @return string
	 */
	private static function order_sql( $from, $to, $cursor, $limit, $count ) {
		global $wpdb;

		$statuses = self::sql_status_in_list();
		$from_gmt = $from ? get_gmt_from_date( $from . ' 00:00:00' ) : '';
		$to_gmt   = $to ? get_gmt_from_date( $to . ' 23:59:59' ) : '';
		$cursor   = absint( $cursor );
		$limit    = absint( $limit );
		$order    = $count ? '' : ' ORDER BY o.id ASC';
		$limit_sql = ( ! $count && $limit > 0 ) ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		if ( self::uses_hpos() ) {
			$select = $count ? 'COUNT(*)' : 'o.id';
			$table  = $wpdb->prefix . 'wc_orders';
			$sql    = "SELECT {$select} FROM {$table} o WHERE o.type = 'shop_order' AND o.customer_id > 0 AND o.status IN ({$statuses}) AND o.id > {$cursor}";
			if ( $from_gmt ) {
				$sql .= $wpdb->prepare( ' AND o.date_created_gmt >= %s', $from_gmt );
			}
			if ( $to_gmt ) {
				$sql .= $wpdb->prepare( ' AND o.date_created_gmt <= %s', $to_gmt );
			}
			return $sql . $order . $limit_sql;
		}

		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;
		$select = $count ? 'COUNT(DISTINCT o.ID)' : 'o.ID';
		$order  = $count ? '' : ' ORDER BY o.ID ASC';
		$sql    = "SELECT {$select} FROM {$posts} o
			INNER JOIN {$meta} cm ON cm.post_id = o.ID AND cm.meta_key = '_customer_user' AND CAST(cm.meta_value AS UNSIGNED) > 0
			WHERE o.post_type = 'shop_order' AND o.post_status IN ({$statuses}) AND o.ID > {$cursor}";
		if ( $from_gmt ) {
			$sql .= $wpdb->prepare( ' AND o.post_date_gmt >= %s', $from_gmt );
		}
		if ( $to_gmt ) {
			$sql .= $wpdb->prepare( ' AND o.post_date_gmt <= %s', $to_gmt );
		}
		return $sql . $order . $limit_sql;
	}

	/**
	 * Whether WooCommerce custom order tables are in use.
	 *
	 * @return bool
	 */
	private static function uses_hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * SQL IN list of qualifying + refunded statuses.
	 *
	 * @return string
	 */
	private static function sql_status_in_list() {
		global $wpdb;

		$slugs = EPC_Loyalty_Order_Service::get_settings()['qualifying_statuses'];
		$slugs[] = 'refunded';
		$quoted  = array();
		foreach ( array_unique( array_filter( $slugs ) ) as $slug ) {
			$quoted[] = $wpdb->prepare( '%s', 'wc-' . sanitize_key( str_replace( 'wc-', '', (string) $slug ) ) );
		}
		if ( empty( $quoted ) ) {
			$quoted[] = $wpdb->prepare( '%s', 'wc-completed' );
		}
		return implode( ',', $quoted );
	}

	/**
	 * Capability + nonce gate.
	 *
	 * @return void
	 */
	private static function assert_can_manage() {
		check_ajax_referer( 'epc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}
	}
}
