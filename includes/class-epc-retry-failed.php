<?php
/**
 * Retry every failed card in the background.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replays the failed creates and updates recorded in EPC_Pass_Issues, in small batches.
 *
 * Each record is retried the way its Retry button on the integration page does it
 * (sync_by_source_id( $source_id, 'sync' )), so a retry can never create a second card:
 * the unique key and the per-record lock turn a duplicate create into an update.
 * One job runs at a time; its state lives in one option.
 */
class EPC_Retry_Failed {

	/**
	 * Option holding the job state (not autoloaded).
	 */
	const OPTION = 'epc_retry_failed_job';

	/**
	 * Hook that processes one batch.
	 */
	const HOOK = 'epc_retry_failed_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'epasscard';

	/**
	 * Failure actions this job replays. Expire failures keep their own automatic retry.
	 */
	const ACTIONS = array( 'sync', 'create', 'update' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( 'admin_post_epc_retry_failed_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_epc_retry_failed_cancel', array( __CLASS__, 'handle_cancel' ) );
	}

	/**
	 * Empty job state.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_job() {
		return array(
			'status'      => 'idle',
			'module'      => '',
			'queue'       => array(),
			'total'       => 0,
			'processed'   => 0,
			'fixed'       => 0,
			'failed'      => 0,
			'skipped'     => 0,
			'reasons'     => array(),
			'started_at'  => 0,
			'updated_at'  => 0,
			'finished_at' => 0,
		);
	}

	/**
	 * Current job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::empty_job(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Current job state read from the database, not this request's cache.
	 *
	 * A cancel from the admin screen happens in another request; a batch only sees it when
	 * it skips the copy of the option cached earlier in its own request.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_fresh_job() {
		wp_cache_delete( self::OPTION, 'options' );
		return self::get_job();
	}

	/**
	 * Save job state.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private static function save_job( array $job ) {
		$job['updated_at'] = time();
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Failures this job would retry.
	 *
	 * @param string $module Module slug, or '' for every module.
	 * @return array<int, array{module: string, source_id: string}>
	 */
	public static function get_retryable( $module = '' ) {
		$module = sanitize_key( (string) $module );
		$out    = array();
		foreach ( EPC_Pass_Issues::all() as $issue ) {
			$slug = (string) ( $issue['module'] ?? '' );
			if ( '' === $slug || ( '' !== $module && $module !== $slug ) ) {
				continue;
			}
			if ( ! in_array( (string) ( $issue['action'] ?? '' ), self::ACTIONS, true ) ) {
				continue;
			}
			$out[] = array(
				'module'    => $slug,
				'source_id' => (string) ( $issue['source_id'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Start a job.
	 *
	 * @param string $module Module slug, or '' for every module.
	 * @return array<string, mixed>|\WP_Error The new job.
	 */
	public static function start( $module = '' ) {
		$current = self::get_fresh_job();
		if ( 'running' === $current['status'] ) {
			return new WP_Error( 'epc_retry_running', __( 'Failed cards are already being retried.', 'epasscard' ) );
		}
		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
		}

		$queue = self::get_retryable( $module );
		if ( empty( $queue ) ) {
			return new WP_Error( 'epc_retry_nothing', __( 'There are no failed cards to retry.', 'epasscard' ) );
		}

		$job               = self::empty_job();
		$job['status']     = 'running';
		$job['module']     = sanitize_key( (string) $module );
		$job['queue']      = $queue;
		$job['total']      = count( $queue );
		$job['started_at'] = time();
		self::save_job( $job );
		self::queue_batch( time() );

		return $job;
	}

	/**
	 * Stop a running job after the current record.
	 *
	 * @return void
	 */
	public static function cancel() {
		$job = self::get_fresh_job();
		if ( 'running' === $job['status'] ) {
			$job['status']      = 'cancelled';
			$job['queue']       = array();
			$job['finished_at'] = time();
			self::save_job( $job );
		}
		self::unschedule();
	}

	/**
	 * Process one batch: up to 20 records or 20 seconds, then queue the next batch.
	 *
	 * @return void
	 */
	public static function process_batch() {
		global $wpdb;

		$job = self::get_fresh_job();
		if ( 'running' !== $job['status'] ) {
			return;
		}

		// One batch at a time, even if two runners pick the hook up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-flight lock.
		$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
		if ( '1' !== (string) $lock ) {
			self::queue_batch( time() + 30 );
			return;
		}

		try {
			/**
			 * Filters how many failed cards one batch retries.
			 *
			 * @param int $size Default 20.
			 */
			$size = max( 1, (int) apply_filters( 'epc_retry_failed_batch_size', 20 ) );
			/**
			 * Filters how many seconds one batch may spend before handing over to the next.
			 *
			 * @param int $seconds Default 20.
			 */
			$budget = max( 1, (int) apply_filters( 'epc_retry_failed_time_budget', 20 ) );
			$start  = microtime( true );
			$done   = 0;

			while ( $done < $size && ( microtime( true ) - $start ) < $budget ) {
				// Re-read before each record: a cancel from the screen must stop the job.
				$job = self::get_fresh_job();
				if ( 'running' !== $job['status'] || empty( $job['queue'] ) ) {
					break;
				}

				$item = array_shift( $job['queue'] );
				$job  = self::retry_one( $job, is_array( $item ) ? $item : array() );
				++$job['processed'];

				// Keep a cancel that arrived while this record was being retried.
				$latest = self::get_fresh_job();
				if ( 'running' !== $latest['status'] ) {
					$job['status']      = $latest['status'];
					$job['queue']       = array();
					$job['finished_at'] = $latest['finished_at'];
				}
				self::save_job( $job );
				++$done;

				if ( 'running' === $job['status'] && ! EPC_Api_Client::is_configured() ) {
					$job                = self::get_fresh_job();
					$job['status']      = 'stopped';
					$job['reasons']     = self::add_reason( $job['reasons'], 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
					$job['finished_at'] = time();
					self::save_job( $job );
					return;
				}
			}

			$job = self::get_fresh_job();
			if ( 'running' !== $job['status'] ) {
				return;
			}
			if ( empty( $job['queue'] ) ) {
				$job['status']      = 'done';
				$job['finished_at'] = time();
				self::save_job( $job );
				return;
			}
			self::queue_batch( time() + 2 );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the lock taken above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	/**
	 * Retry one record and count the result.
	 *
	 * @param array<string, mixed>  $job  Job.
	 * @param array<string, string> $item Record: module, source_id.
	 * @return array<string, mixed> Updated job.
	 */
	private static function retry_one( array $job, array $item ) {
		$slug      = sanitize_key( (string) ( $item['module'] ?? '' ) );
		$source_id = EPC_DB::sanitize_source_id( (string) ( $item['source_id'] ?? '' ) );

		$skip = self::skip_reason( $slug, $source_id );
		if ( null !== $skip ) {
			++$job['skipped'];
			$job['reasons'] = self::add_reason( $job['reasons'], $skip[0], $skip[1] );
			return $job;
		}

		$module = EPC_Module_Loader::get_module_instance( $slug );
		$result = $module ? $module->sync_by_source_id( $source_id, 'sync' ) : new WP_Error( 'epc_retry_module_missing', __( 'The integration is not available.', 'epasscard' ) );

		if ( is_wp_error( $result ) ) {
			++$job['failed'];
			$job['reasons'] = self::add_reason( $job['reasons'], (string) $result->get_error_code(), (string) $result->get_error_message() );
			return $job;
		}

		++$job['fixed'];
		return $job;
	}

	/**
	 * Why a record is not retried, or null to retry it.
	 *
	 * @param string $slug      Module slug.
	 * @param string $source_id Source id.
	 * @return array{0: string, 1: string}|null [code, message]
	 */
	private static function skip_reason( $slug, $source_id ) {
		if ( '' === $slug || '' === $source_id ) {
			return array( 'epc_retry_invalid', __( 'The failure record was incomplete.', 'epasscard' ) );
		}

		$issue = EPC_Pass_Issues::get( $slug, $source_id );
		if ( null === $issue || ! in_array( (string) ( $issue['action'] ?? '' ), self::ACTIONS, true ) ) {
			return array( 'epc_retry_already_fixed', __( 'Fixed or dismissed since the retry started.', 'epasscard' ) );
		}

		if ( ! EPC_Module_Settings::is_enabled( $slug ) ) {
			return array( 'epc_retry_module_off', __( 'The integration is turned off.', 'epasscard' ) );
		}

		$module = EPC_Module_Loader::get_module_instance( $slug );
		if ( ! $module instanceof EPC_Module || ! $module->is_available() ) {
			return array( 'epc_retry_module_missing', __( 'The plugin this integration needs is not active.', 'epasscard' ) );
		}

		// A retry runs a sync, which would reactivate a revoked card. Those are left to the
		// Activate button, where the merchant decides.
		$row = EPC_DB::get_pass( $slug, $source_id );
		if ( $row && 'revoked' === (string) $row->status ) {
			return array( 'epc_retry_revoked', __( 'The card was revoked after it failed. Use Activate on the integration page if it should be live again.', 'epasscard' ) );
		}
		// A sync would also cancel a revoke that is waiting to be retried.
		if ( $row && ! empty( EPC_DB::get_pass_meta( $row )['revoke_pending'] ) ) {
			return array( 'epc_retry_revoke_pending', __( 'The card is waiting to be revoked.', 'epasscard' ) );
		}

		return null;
	}

	/**
	 * Count a reason: code => {count, message}.
	 *
	 * @param mixed  $reasons Reasons so far.
	 * @param string $code    Error code.
	 * @param string $message Message (the latest one is kept).
	 * @return array<string, array{count: int, message: string}>
	 */
	private static function add_reason( $reasons, $code, $message ) {
		$reasons = is_array( $reasons ) ? $reasons : array();
		$code    = sanitize_key( $code );
		$code    = '' !== $code ? $code : 'unknown';
		$reasons[ $code ] = array(
			'count'   => (int) ( $reasons[ $code ]['count'] ?? 0 ) + 1,
			'message' => sanitize_text_field( $message ),
		);
		return $reasons;
	}

	/**
	 * Lock name (per site on multisite).
	 *
	 * @return string
	 */
	private static function lock_name() {
		return 'epc_retry_failed_' . get_current_blog_id();
	}

	/**
	 * Queue the next batch: Action Scheduler when present, WP-Cron otherwise.
	 *
	 * @param int $timestamp Unix time.
	 * @return void
	 */
	private static function queue_batch( $timestamp ) {
		self::unschedule();
		$timestamp = max( time(), absint( $timestamp ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::HOOK, array(), self::GROUP, true );
			return;
		}
		wp_schedule_single_event( $timestamp, self::HOOK );
	}

	/**
	 * Remove queued batches.
	 *
	 * @return void
	 */
	private static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Deactivation: stop a running job (it is not resumed on reactivation).
	 *
	 * @return void
	 */
	public static function clear_cron() {
		self::cancel();
	}

	/**
	 * Admin-post: start retrying.
	 *
	 * @return void
	 */
	public static function handle_start() {
		if ( ! epc_current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_retry_failed' );
		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$result = self::start( $module );
		set_transient( 'epc_retry_failed_notice_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : '', 60 );
		self::redirect_back();
	}

	/**
	 * Admin-post: stop retrying.
	 *
	 * @return void
	 */
	public static function handle_cancel() {
		if ( ! epc_current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_retry_failed' );
		self::cancel();
		self::redirect_back();
	}

	/**
	 * Back to the screen the form was on.
	 *
	 * @return void
	 */
	private static function redirect_back() {
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=epasscard' ) );
		exit;
	}

	/**
	 * "Retry all failed cards" button, or the job's progress, for an admin screen.
	 *
	 * @param string $module Module slug for one integration, or '' for all.
	 * @return string HTML.
	 */
	public static function render_controls( $module = '' ) {
		$module = sanitize_key( (string) $module );
		$job    = self::get_job();
		$html   = '';

		$notice_key = 'epc_retry_failed_notice_' . get_current_user_id();
		$error      = get_transient( $notice_key );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( $notice_key );
			$html .= '<p class="epc-retry-failed__error"><strong>' . esc_html( $error ) . '</strong></p>';
		}

		if ( 'running' === $job['status'] ) {
			$html .= '<p class="epc-retry-failed__progress">' . esc_html(
				sprintf(
					/* translators: 1: records retried so far, 2: records to retry */
					__( 'Retrying failed cards in the background: %1$d of %2$d done. Reload to see progress.', 'epasscard' ),
					(int) $job['processed'],
					(int) $job['total']
				)
			) . '</p>';
			$html .= self::form( 'epc_retry_failed_cancel', $module, __( 'Stop', 'epasscard' ), 'button button-small' );
			return $html;
		}

		// The last result stays visible for a day.
		if ( in_array( $job['status'], array( 'done', 'cancelled', 'stopped' ), true ) && (int) $job['finished_at'] > time() - DAY_IN_SECONDS ) {
			$html .= '<p class="epc-retry-failed__result">' . esc_html( self::summary( $job ) ) . '</p>';
		}

		$count = count( self::get_retryable( $module ) );
		if ( $count > 1 ) {
			$label = sprintf(
				/* translators: %d: number of failed cards */
				_n( 'Retry %d failed card', 'Retry all %d failed cards', $count, 'epasscard' ),
				$count
			);
			$html .= self::form( 'epc_retry_failed_start', $module, $label, 'button button-secondary' );
		}

		return $html;
	}

	/**
	 * One line about the last finished job.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string
	 */
	public static function summary( array $job ) {
		$parts = array(
			sprintf(
				/* translators: %d: cards created or updated */
				_n( '%d card fixed', '%d cards fixed', (int) $job['fixed'], 'epasscard' ),
				(int) $job['fixed']
			),
			sprintf(
				/* translators: %d: cards that failed again */
				_n( '%d still failing', '%d still failing', (int) $job['failed'], 'epasscard' ),
				(int) $job['failed']
			),
		);
		if ( (int) $job['skipped'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: cards not retried */
				_n( '%d skipped', '%d skipped', (int) $job['skipped'], 'epasscard' ),
				(int) $job['skipped']
			);
		}

		$reasons = array();
		foreach ( (array) $job['reasons'] as $reason ) {
			if ( is_array( $reason ) && '' !== (string) ( $reason['message'] ?? '' ) ) {
				$reasons[] = (int) ( $reason['count'] ?? 0 ) . ' × ' . (string) $reason['message'];
			}
		}

		switch ( (string) $job['status'] ) {
			case 'cancelled':
				$lead = __( 'Retry stopped:', 'epasscard' );
				break;
			case 'stopped':
				$lead = __( 'Retry stopped early:', 'epasscard' );
				break;
			default:
				$lead = __( 'Last retry:', 'epasscard' );
		}

		return $lead . ' ' . implode( ', ', $parts ) . '.' . ( $reasons ? ' ' . implode( '; ', $reasons ) . '.' : '' );
	}

	/**
	 * Tags render_controls() outputs, for wp_kses().
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html() {
		return array(
			'p'      => array( 'class' => true ),
			'strong' => array(),
			'form'   => array(
				'method' => true,
				'action' => true,
				'class'  => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
				'id'    => true,
			),
			'button' => array(
				'type'  => true,
				'class' => true,
			),
		);
	}

	/**
	 * Small admin-post form.
	 *
	 * @param string $action Admin-post action.
	 * @param string $module Module slug or ''.
	 * @param string $label  Button label.
	 * @param string $class  Button classes.
	 * @return string
	 */
	private static function form( $action, $module, $label, $class ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="epc-retry-failed__form">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="module" value="' . esc_attr( $module ) . '" />'
			. wp_nonce_field( 'epc_retry_failed', '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>'
			. '</form>';
	}
}
