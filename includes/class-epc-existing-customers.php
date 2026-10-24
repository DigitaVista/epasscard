<?php
/**
 * Give cards to existing customers: check first, then create and email them in the background.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cards for customers who joined before EpassCard (Cards screen).
 *
 * 1. Check: every record the integration would give a card to (get_backfill_source_ids())
 *    is sorted into "will get a card", "already has one", "no card by your settings" (its
 *    plan, product or event has no design) and "can't send" (no email address). The result is
 *    kept for the admin who ran it, with a sample email.
 * 2. Start: after an explicit confirm, exactly the records of that check (all, or the newest
 *    N when the plan has fewer cards left) are queued.
 * 3. Run: small background batches (Action Scheduler, else WP-Cron) create each card with the
 *    integration's own sync and email the card link (the email under Settings → Emails). A
 *    record is checked again just before: a card made meanwhile is skipped, so nobody gets a
 *    second card. Pause, resume and stop take effect after the current record.
 *
 * One run at a time; its state lives in options.
 */
class EPC_Existing_Customers {

	/**
	 * Option: run state (not autoloaded).
	 */
	const OPTION = 'epc_existing_job';

	/**
	 * Option: record ids of the run, in order.
	 */
	const QUEUE_OPTION = 'epc_existing_queue';

	/**
	 * Option: one result per record handled.
	 */
	const RESULTS_OPTION = 'epc_existing_results';

	/**
	 * Transient prefix for a check (per admin).
	 */
	const CHECK_PREFIX = 'epc_existing_check_';

	/**
	 * Hook that runs one batch.
	 */
	const HOOK = 'epc_existing_customers_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'epasscard';

	/**
	 * A check stops after this many records would get a card.
	 */
	const CHECK_MAX = 5000;

	/**
	 * A check stops after this many seconds.
	 */
	const CHECK_SECONDS = 25;

	/**
	 * Stop a run after this many failures in a row with the same error.
	 */
	const STOP_AFTER = 5;

	/**
	 * Record being created by a run: [module, source_id], or null.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private static $creating = null;

	/**
	 * Whether the record being created got a new card in this request.
	 *
	 * @var bool
	 */
	private static $created = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( 'admin_post_epc_existing_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_epc_existing_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_epc_existing_control', array( __CLASS__, 'handle_control' ) );
		add_action( 'admin_post_epc_existing_results', array( __CLASS__, 'handle_results_csv' ) );
		add_action( 'wp_ajax_epc_existing_status', array( __CLASS__, 'ajax_status' ) );
		// While a run creates a card it sends the email itself, once.
		add_filter( 'epc_pass_email_auto_send', array( __CLASS__, 'filter_auto_send' ), 99 );
		add_action( 'epc_pass_created', array( __CLASS__, 'on_pass_created' ), 10, 2 );
	}

	/**
	 * Whether the current user may check and run (each card counts toward the plan).
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( epc_settings_capability() );
	}

	/**
	 * Integrations that can give cards to existing customers.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function modules() {
		$out = array();
		foreach ( EPC_Admin_Design::modules() as $slug => $module ) {
			if ( $module->supports_backfill() ) {
				$out[ $slug ] = $module;
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------------------
	 * Check
	 * ---------------------------------------------------------------------- */

	/**
	 * Sort every record of an integration that would get a card.
	 *
	 * @param EPC_Module $module Integration.
	 * @return array<string, mixed> The check.
	 */
	public static function check( EPC_Module $module ) {
		$slug  = $module->get_slug();
		$start = microtime( true );
		$will  = array();
		$seen  = array();
		$check = array(
			'module'         => $slug,
			'at'             => time(),
			'will'           => array(),
			'have'           => 0,
			'excluded'       => 0,
			'excluded_items' => array(),
			'no_email'       => 0,
			'no_email_ids'   => array(),
			'truncated'      => false,
			'sample'         => null,
		);

		foreach ( $module->get_filtered_mappable_entities() as $entity ) {
			$entity_id  = absint( $entity['id'] ?? 0 );
			$has_design = $entity_id > 0 && ! empty( $module->get_mapping( $entity_id )['template_uid'] );
			$label      = (string) ( $entity['label'] ?? $entity_id );
			$offset     = 0;
			$page       = 200;

			do {
				$ids = $entity_id > 0 ? $module->get_backfill_source_ids( $entity_id, $page, $offset ) : array();
				$ids = is_array( $ids ) ? $ids : array();
				$offset += count( $ids );

				$batch = array();
				foreach ( $ids as $id ) {
					$id = EPC_DB::sanitize_source_id( $id );
					if ( '' === $id || isset( $seen[ $id ] ) ) {
						continue;
					}
					$seen[ $id ] = true;
					$batch[]     = $id;
				}

				$with_card = EPC_DB::source_ids_with_card( $slug, $batch );
				foreach ( $batch as $id ) {
					if ( isset( $with_card[ $id ] ) ) {
						++$check['have'];
						continue;
					}
					if ( ! $has_design ) {
						++$check['excluded'];
						$check['excluded_items'][ $label ] = (int) ( $check['excluded_items'][ $label ] ?? 0 ) + 1;
						continue;
					}
					$user_id = $module->get_backfill_user_id( $id );
					if ( null !== $user_id && '' === self::recipient( $slug, $id, $user_id, $entity_id ) ) {
						++$check['no_email'];
						if ( count( $check['no_email_ids'] ) < 50 ) {
							$check['no_email_ids'][] = array( $id, (int) $user_id );
						}
						continue;
					}
					$will[] = array( $id, $module->get_backfill_sort_key( $id ), $entity_id );
				}

				if ( count( $will ) >= self::CHECK_MAX || ( microtime( true ) - $start ) > self::CHECK_SECONDS ) {
					$check['truncated'] = true;
					break 2;
				}
			} while ( count( $ids ) >= $page );
		}

		// Newest first: a run that covers only part of the list reaches the newest customers.
		usort(
			$will,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		$check['will'] = array_map(
			static function ( $item ) {
				return $item[0];
			},
			$will
		);
		if ( ! empty( $will ) ) {
			$check['sample'] = array(
				'source_id' => $will[0][0],
				'entity_id' => $will[0][2],
			);
		}

		return $check;
	}

	/**
	 * Address the card email would go to, or '' when there is none. Same rules as the send
	 * (EPC_Pass_Email::compose()), on a stand-in for the card that does not exist yet.
	 *
	 * @param string $module    Module slug.
	 * @param string $source_id Source id.
	 * @param int    $user_id   User the card would belong to.
	 * @param int    $entity_id Entity id.
	 * @return string
	 */
	private static function recipient( $module, $source_id, $user_id, $entity_id ) {
		$email = EPC_Pass_Email::compose( self::stand_in( $module, $source_id, $user_id, $entity_id ) );
		return is_wp_error( $email ) ? '' : (string) $email['to'];
	}

	/**
	 * Pass row stand-in for a card that does not exist yet.
	 *
	 * @param string $module    Module slug.
	 * @param string $source_id Source id.
	 * @param int    $user_id   User id.
	 * @param int    $entity_id Entity id.
	 * @return object
	 */
	private static function stand_in( $module, $source_id, $user_id, $entity_id ) {
		return (object) array(
			'id'        => 0,
			'module'    => $module,
			'source_id' => $source_id,
			'entity_id' => absint( $entity_id ),
			'user_id'   => absint( $user_id ),
			'pass_uid'  => '',
			'pass_link' => __( '[link to their card]', 'epasscard' ),
			'status'    => 'active',
		);
	}

	/**
	 * The email the sample customer of a check would get.
	 *
	 * @param array<string, mixed> $check Check.
	 * @return array{to: string, subject: string, body: string}|null
	 */
	public static function sample_email( array $check ) {
		if ( empty( $check['sample'] ) ) {
			return null;
		}
		$module = EPC_Module_Loader::get_module_instance( (string) $check['module'] );
		if ( ! $module instanceof EPC_Module ) {
			return null;
		}
		$source_id = (string) $check['sample']['source_id'];
		$user_id   = $module->get_backfill_user_id( $source_id );
		$email     = EPC_Pass_Email::compose( self::stand_in( $module->get_slug(), $source_id, (int) $user_id, (int) $check['sample']['entity_id'] ) );
		if ( is_wp_error( $email ) ) {
			return null;
		}
		return array(
			'to'      => (string) $email['to'],
			'subject' => (string) $email['subject'],
			'body'    => (string) $email['body'],
		);
	}

	/**
	 * The current admin's check, if it is less than an hour old.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_check() {
		$check = get_transient( self::CHECK_PREFIX . get_current_user_id() );
		return is_array( $check ) && isset( $check['module'], $check['will'] ) ? $check : null;
	}

	/**
	 * Forget the current admin's check.
	 *
	 * @return void
	 */
	private static function clear_check() {
		delete_transient( self::CHECK_PREFIX . get_current_user_id() );
	}

	/* -------------------------------------------------------------------------
	 * Run state
	 * ---------------------------------------------------------------------- */

	/**
	 * Empty run.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_job() {
		return array(
			'status'      => 'idle',
			'module'      => '',
			'total'       => 0,
			'position'    => 0,
			'emailed'     => 0,
			'not_emailed' => 0,
			'had'         => 0,
			'no_email'    => 0,
			'failed'      => 0,
			'reasons'     => array(),
			'streak'      => array(
				'code'  => '',
				'count' => 0,
			),
			'stop_reason' => '',
			'started_by'  => 0,
			'started_at'  => 0,
			'updated_at'  => 0,
			'finished_at' => 0,
		);
	}

	/**
	 * Run state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::empty_job(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Run state read from the database, not this request's cache (pause and stop come from
	 * another request).
	 *
	 * @return array<string, mixed>
	 */
	private static function get_fresh_job() {
		wp_cache_delete( self::OPTION, 'options' );
		return self::get_job();
	}

	/**
	 * Save run state.
	 *
	 * @param array<string, mixed> $job Run.
	 * @return void
	 */
	private static function save_job( array $job ) {
		$job['updated_at'] = time();
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Whether a run is going on (running or paused).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return in_array( self::get_job()['status'], array( 'running', 'paused' ), true );
	}

	/**
	 * Results of the last run.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: int}> [source_id, outcome, detail, user_id]
	 */
	public static function get_results() {
		$saved = get_option( self::RESULTS_OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Start a run from the current admin's check.
	 *
	 * @param string $module Module slug the admin confirmed.
	 * @param int    $limit  Number of records (newest first), 0 for all.
	 * @return array<string, mixed>|\WP_Error The run.
	 */
	public static function start( $module, $limit = 0 ) {
		$check = self::get_check();
		if ( null === $check || $check['module'] !== $module ) {
			return new WP_Error( 'epc_existing_no_check', __( 'The check expired. Check your customers again, then confirm.', 'epasscard' ) );
		}
		if ( self::is_active() ) {
			return new WP_Error( 'epc_existing_running', __( 'Cards are already being given to existing customers. Wait for that run to finish or stop it first.', 'epasscard' ) );
		}
		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
		}

		$queue = array_values( (array) $check['will'] );
		$limit = absint( $limit );
		if ( $limit > 0 ) {
			$queue = array_slice( $queue, 0, $limit );
		}
		if ( empty( $queue ) ) {
			return new WP_Error( 'epc_existing_nothing', __( 'Nobody in this check needs a card.', 'epasscard' ) );
		}

		$job               = self::empty_job();
		$job['status']     = 'running';
		$job['module']     = sanitize_key( (string) $module );
		$job['total']      = count( $queue );
		$job['started_by'] = get_current_user_id();
		$job['started_at'] = time();

		update_option( self::QUEUE_OPTION, $queue, false );
		update_option( self::RESULTS_OPTION, array(), false );
		self::save_job( $job );
		self::clear_check();
		self::queue_batch( time() );

		return $job;
	}

	/**
	 * Pause, resume or stop the run.
	 *
	 * @param string $command pause|resume|stop|dismiss.
	 * @return void
	 */
	public static function control( $command ) {
		$job = self::get_fresh_job();
		switch ( $command ) {
			case 'pause':
				if ( 'running' === $job['status'] ) {
					$job['status'] = 'paused';
					self::save_job( $job );
					self::unschedule();
				}
				break;
			case 'resume':
				if ( 'paused' === $job['status'] ) {
					$job['status'] = 'running';
					self::save_job( $job );
					self::queue_batch( time() );
				}
				break;
			case 'stop':
				if ( in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
					$job['status']      = 'cancelled';
					$job['finished_at'] = time();
					self::save_job( $job );
				}
				self::unschedule();
				break;
			case 'dismiss':
				if ( ! in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
					delete_option( self::OPTION );
					delete_option( self::QUEUE_OPTION );
					delete_option( self::RESULTS_OPTION );
				}
				break;
		}
	}

	/* -------------------------------------------------------------------------
	 * Background batches
	 * ---------------------------------------------------------------------- */

	/**
	 * One batch: up to 25 records or 20 seconds, then queue the next.
	 *
	 * @return void
	 */
	public static function process_batch() {
		global $wpdb;

		$job = self::get_fresh_job();
		if ( 'running' !== $job['status'] ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-flight lock.
		$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
		if ( '1' !== (string) $lock ) {
			self::queue_batch( time() + 30 );
			return;
		}

		// Another runner may have written results since this process cached them.
		wp_cache_delete( self::RESULTS_OPTION, 'options' );
		$results = self::get_results();
		try {
			/**
			 * Filters how many records one batch handles.
			 *
			 * @param int $size Default 25.
			 */
			$size = max( 1, (int) apply_filters( 'epc_existing_customers_batch_size', 25 ) );
			/**
			 * Filters how many seconds one batch may spend before handing over to the next.
			 *
			 * @param int $seconds Default 20.
			 */
			$budget = max( 1, (int) apply_filters( 'epc_existing_customers_time_budget', 20 ) );
			$start  = microtime( true );
			$done   = 0;
			$queue  = get_option( self::QUEUE_OPTION, array() );
			$queue  = is_array( $queue ) ? array_values( $queue ) : array();

			while ( $done < $size && ( microtime( true ) - $start ) < $budget ) {
				// Re-read before each record: pause and stop must take effect.
				$job = self::get_fresh_job();
				if ( 'running' !== $job['status'] ) {
					break;
				}
				$position = (int) $job['position'];
				if ( $position >= count( $queue ) ) {
					break;
				}

				$outcome = self::handle_one( (string) $job['module'], (string) $queue[ $position ] );
				$results[] = array( (string) $queue[ $position ], $outcome['outcome'], $outcome['detail'], (int) $outcome['user_id'] );

				$latest = self::get_fresh_job();
				$job    = self::count_outcome( $job, $outcome );
				++$job['position'];
				// Keep a pause or stop that arrived while this record was handled.
				if ( 'running' !== $latest['status'] ) {
					$job['status']      = $latest['status'];
					$job['finished_at'] = $latest['finished_at'];
				}
				if ( 'running' === $job['status'] && '' !== $outcome['stop'] ) {
					$job['status']      = 'stopped';
					$job['stop_reason'] = $outcome['stop'];
					$job['finished_at'] = time();
				}
				self::save_job( $job );
				++$done;
			}

			update_option( self::RESULTS_OPTION, $results, false );

			$job = self::get_fresh_job();
			if ( 'running' !== $job['status'] ) {
				return;
			}
			if ( (int) $job['position'] >= count( $queue ) ) {
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
	 * Give one record its card and email it.
	 *
	 * @param string $slug      Module slug.
	 * @param string $source_id Source id.
	 * @return array{outcome: string, detail: string, code: string, user_id: int, stop: string}
	 *         outcome: emailed | not_emailed (card made, email failed) | had | no_email | failed.
	 *         stop: why the whole run must stop, or ''.
	 */
	private static function handle_one( $slug, $source_id ) {
		$result = array(
			'outcome' => 'failed',
			'detail'  => '',
			'code'    => '',
			'user_id' => 0,
			'stop'    => '',
		);

		$module = EPC_Module_Loader::get_module_instance( $slug );
		if ( ! EPC_Module_Settings::is_enabled( $slug ) || ! $module instanceof EPC_Module || ! $module->is_available() ) {
			$result['detail'] = __( 'The integration is turned off or its plugin is not active.', 'epasscard' );
			$result['stop']   = $result['detail'];
			return $result;
		}
		if ( ! EPC_Api_Client::is_configured() ) {
			$result['detail'] = __( 'EpassCard is not connected.', 'epasscard' );
			$result['stop']   = $result['detail'];
			return $result;
		}

		$row = EPC_DB::get_pass( $slug, $source_id );
		if ( $row && ! empty( $row->pass_uid ) ) {
			$result['outcome'] = 'had';
			$result['user_id'] = (int) $row->user_id;
			return $result;
		}

		$user_id = $module->get_backfill_user_id( $source_id );
		if ( null !== $user_id ) {
			$result['user_id'] = (int) $user_id;
			if ( '' === self::recipient( $slug, $source_id, (int) $user_id, 0 ) ) {
				$result['outcome'] = 'no_email';
				$result['detail']  = __( 'No email address, so no card was made.', 'epasscard' );
				return $result;
			}
		}

		self::$creating = array( $slug, $source_id );
		self::$created  = false;
		$suppress       = class_exists( 'EPC_Loyalty_Notification_Service' );
		if ( $suppress ) {
			// The run sends the card email itself; the loyalty "card ready" email would be a second one.
			EPC_Loyalty_Notification_Service::suppress();
		}
		try {
			$synced = $module->sync_by_source_id( $source_id, 'sync' );
		} finally {
			if ( $suppress ) {
				EPC_Loyalty_Notification_Service::restore();
			}
			self::$creating = null;
		}

		if ( is_wp_error( $synced ) ) {
			$result['code']   = (string) $synced->get_error_code();
			$result['detail'] = (string) $synced->get_error_message();
			return $result;
		}

		$row = EPC_DB::get_pass( $slug, $source_id );
		if ( ! $row || empty( $row->pass_uid ) ) {
			$result['code']   = 'epc_existing_no_card';
			$result['detail'] = __( 'EpassCard did not return a card.', 'epasscard' );
			return $result;
		}
		$result['user_id'] = (int) $row->user_id;
		if ( ! self::$created ) {
			// Made by the integration's own sync between the check above and this one.
			$result['outcome'] = 'had';
			return $result;
		}

		$sent = EPC_Pass_Email::send_for_pass_row( $row );
		if ( is_wp_error( $sent ) ) {
			$result['outcome'] = 'not_emailed';
			$result['detail']  = (string) $sent->get_error_message();
			return $result;
		}

		$result['outcome'] = 'emailed';
		return $result;
	}

	/**
	 * Add one record's outcome to the run's counts.
	 *
	 * @param array<string, mixed> $job     Run.
	 * @param array<string, mixed> $outcome Outcome from handle_one().
	 * @return array<string, mixed>
	 */
	private static function count_outcome( array $job, array $outcome ) {
		$key         = (string) $outcome['outcome'];
		$job[ $key ] = (int) ( $job[ $key ] ?? 0 ) + 1;

		if ( 'failed' !== $key ) {
			$job['streak'] = array(
				'code'  => '',
				'count' => 0,
			);
			return $job;
		}

		$code = sanitize_key( '' !== (string) $outcome['code'] ? (string) $outcome['code'] : 'unknown' );

		$reasons          = is_array( $job['reasons'] ) ? $job['reasons'] : array();
		$reasons[ $code ] = array(
			'count'   => (int) ( $reasons[ $code ]['count'] ?? 0 ) + 1,
			'message' => sanitize_text_field( (string) $outcome['detail'] ),
		);
		$job['reasons']   = $reasons;

		$streak        = is_array( $job['streak'] ) ? $job['streak'] : array();
		$job['streak'] = array(
			'code'  => $code,
			'count' => ( (string) ( $streak['code'] ?? '' ) === $code ? (int) ( $streak['count'] ?? 0 ) : 0 ) + 1,
		);

		if ( $job['streak']['count'] >= self::STOP_AFTER && '' === (string) $outcome['stop'] ) {
			$job['status']      = 'stopped';
			$job['finished_at'] = time();
			$job['stop_reason'] = sprintf(
				/* translators: 1: number of records, 2: error message */
				__( 'The last %1$d cards failed the same way: %2$s', 'epasscard' ),
				(int) $job['streak']['count'],
				sanitize_text_field( (string) $outcome['detail'] )
			);
		}

		return $job;
	}

	/**
	 * No automatic email for a card a run is creating: the run emails it after.
	 *
	 * @param bool $send Send flag.
	 * @return bool
	 */
	public static function filter_auto_send( $send ) {
		return null !== self::$creating ? false : $send;
	}

	/**
	 * Note that the record a run is creating got a new card.
	 *
	 * @param string     $module    Module slug.
	 * @param string|int $source_id Source id.
	 * @return void
	 */
	public static function on_pass_created( $module, $source_id ) {
		if ( null !== self::$creating && self::$creating[0] === (string) $module && self::$creating[1] === EPC_DB::sanitize_source_id( $source_id ) ) {
			self::$created = true;
		}
	}

	/**
	 * Lock name (per site on multisite).
	 *
	 * @return string
	 */
	private static function lock_name() {
		return 'epc_existing_' . get_current_blog_id();
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
	 * Deactivation: stop a run (it is not resumed on reactivation).
	 *
	 * @return void
	 */
	public static function clear_cron() {
		self::control( 'stop' );
	}

	/* -------------------------------------------------------------------------
	 * Requests
	 * ---------------------------------------------------------------------- */

	/**
	 * Admin-post: check an integration's customers.
	 *
	 * @return void
	 */
	public static function handle_check() {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_existing' );
		$slug    = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$modules = self::modules();
		if ( ! isset( $modules[ $slug ] ) ) {
			wp_die( esc_html__( 'This integration cannot give cards to existing customers.', 'epasscard' ) );
		}

		set_transient( self::CHECK_PREFIX . get_current_user_id(), self::check( $modules[ $slug ] ), HOUR_IN_SECONDS );
		self::redirect( $slug );
	}

	/**
	 * Admin-post: start after the confirm.
	 *
	 * @return void
	 */
	public static function handle_start() {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_existing' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$slug    = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$limit   = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 0;
		$confirm = ! empty( $_POST['confirm'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $confirm ) {
			self::set_notice( __( 'Tick the box to confirm, then start.', 'epasscard' ) );
			self::redirect( $slug );
		}
		$result = self::start( $slug, $limit );
		if ( is_wp_error( $result ) ) {
			self::set_notice( $result->get_error_message() );
		}
		self::redirect( $slug );
	}

	/**
	 * Admin-post: pause, resume, stop, dismiss the last run, or discard the check.
	 *
	 * @return void
	 */
	public static function handle_control() {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_existing' );
		$command = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( (string) $_POST['command'] ) ) : '';
		if ( 'discard' === $command ) {
			self::clear_check();
		} else {
			self::control( $command );
		}
		self::redirect( '' );
	}

	/**
	 * Admin-post: the last run's results as CSV.
	 *
	 * @return void
	 */
	public static function handle_results_csv() {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_existing' );

		$job    = self::get_job();
		$module = EPC_Module_Loader::get_module_instance( (string) $job['module'] );
		$label  = $module instanceof EPC_Module ? $module->get_label() : (string) $job['module'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=epasscard-existing-customers-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		fputcsv( $out, array( __( 'Integration', 'epasscard' ), __( 'Record', 'epasscard' ), __( 'Customer', 'epasscard' ), __( 'Email', 'epasscard' ), __( 'Result', 'epasscard' ), __( 'Detail', 'epasscard' ) ), ',', '"', '\\' );
		foreach ( self::get_results() as $result ) {
			$user = ! empty( $result[3] ) ? get_userdata( (int) $result[3] ) : false;
			fputcsv(
				$out,
				array_map(
					array( 'EPC_Loyalty_Customer_Service', 'csv_safe_cell' ),
					array(
						$label,
						(string) $result[0],
						$user ? (string) $user->display_name : '',
						$user ? (string) $user->user_email : '',
						self::outcome_label( (string) $result[1] ),
						(string) $result[2],
					)
				),
				',',
				'"',
				'\\'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
		exit;
	}

	/**
	 * AJAX: run progress for the Cards screen.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		check_ajax_referer( 'epc_existing', 'nonce' );
		if ( ! self::current_user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}
		$job = self::get_fresh_job();
		wp_send_json_success(
			array(
				'status'  => (string) $job['status'],
				'percent' => self::percent( $job ),
				'text'    => self::progress_text( $job ),
			)
		);
	}

	/**
	 * Back to Cards, at the panel.
	 *
	 * @param string $module Module slug to keep selected.
	 * @return void
	 */
	private static function redirect( $module ) {
		$args = '' !== $module ? array( 'epc_existing_module' => $module ) : array();
		wp_safe_redirect( EPC_Admin_App::url( 'cards', $args ) . '#epc-existing' );
		exit;
	}

	/**
	 * Keep a message for the next screen.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function set_notice( $message ) {
		set_transient( 'epc_existing_notice_' . get_current_user_id(), (string) $message, 60 );
	}

	/**
	 * Take the message kept for this screen.
	 *
	 * @return string
	 */
	public static function take_notice() {
		$key     = 'epc_existing_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			return $message;
		}
		return '';
	}

	/* -------------------------------------------------------------------------
	 * Text
	 * ---------------------------------------------------------------------- */

	/**
	 * Share of the run done.
	 *
	 * @param array<string, mixed> $job Run.
	 * @return int 0-100.
	 */
	public static function percent( array $job ) {
		$total = max( 1, (int) $job['total'] );
		return (int) min( 100, floor( ( (int) $job['position'] / $total ) * 100 ) );
	}

	/**
	 * "120 of 234 done: …" line.
	 *
	 * @param array<string, mixed> $job Run.
	 * @return string
	 */
	public static function progress_text( array $job ) {
		$parts = array();
		$map   = array(
			'emailed'     => /* translators: %s: number of customers */ _n_noop( '%s emailed', '%s emailed', 'epasscard' ),
			'not_emailed' => /* translators: %s: number of cards */ _n_noop( '%s card made but the email failed', '%s cards made but the email failed', 'epasscard' ),
			'had'         => /* translators: %s: number of customers */ _n_noop( '%s already had one', '%s already had one', 'epasscard' ),
			'no_email'    => /* translators: %s: number of customers */ _n_noop( '%s without an email address', '%s without an email address', 'epasscard' ),
			'failed'      => /* translators: %s: number of cards */ _n_noop( '%s failed', '%s failed', 'epasscard' ),
		);
		foreach ( $map as $key => $noop ) {
			$count = (int) $job[ $key ];
			if ( $count > 0 || 'emailed' === $key ) {
				$parts[] = sprintf( translate_nooped_plural( $noop, $count, 'epasscard' ), number_format_i18n( $count ) );
			}
		}

		return sprintf(
			/* translators: 1: records done, 2: records in the run, 3: list of counts */
			__( '%1$s of %2$s done: %3$s.', 'epasscard' ),
			number_format_i18n( (int) $job['position'] ),
			number_format_i18n( (int) $job['total'] ),
			implode( ', ', $parts )
		);
	}

	/**
	 * Words for one outcome.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	public static function outcome_label( $outcome ) {
		$labels = array(
			'emailed'     => __( 'Card made and emailed', 'epasscard' ),
			'not_emailed' => __( 'Card made, email failed', 'epasscard' ),
			'had'         => __( 'Already had a card', 'epasscard' ),
			'no_email'    => __( 'No email address', 'epasscard' ),
			'failed'      => __( 'Failed', 'epasscard' ),
		);
		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : $outcome;
	}

	/* -------------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether the panel has something to show at the top of Cards (a run, its result or a check).
	 *
	 * @return bool
	 */
	public static function has_focus() {
		if ( ! self::current_user_can() ) {
			return false;
		}
		$job = self::get_job();
		return 'idle' !== $job['status'] || null !== self::get_check();
	}

	/**
	 * The panel.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::current_user_can() ) {
			return;
		}
		$modules = self::modules();
		if ( empty( $modules ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preselects the integration only.
		$selected = isset( $_GET['epc_existing_module'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_existing_module'] ) ) : '';
		$selected = isset( $modules[ $selected ] ) ? $selected : (string) key( $modules );
		$job      = self::get_job();
		$check    = self::get_check();
		$notice   = self::take_notice();
		$left     = EPC_Plan::cards_left();

		include EPC_PLUGIN_DIR . 'admin/views/app/existing-customers.php';
	}
}
