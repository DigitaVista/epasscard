<?php
/**
 * Scheduled wallet push notifications for issued passes.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wallet push helpers and optional scheduled notifications for modules without native reminders.
 */
class EPC_Pass_Notifications {

	public const CRON_HOOK = 'epc_pass_notifications_event';

	/**
	 * Single event that resumes a daily run that ran out of time.
	 */
	public const CONTINUE_HOOK = 'epc_pass_notifications_continue';

	/**
	 * Option: where to resume (module slug + last pass id processed).
	 */
	public const CURSOR_OPTION = 'epc_pass_notifications_cursor';

	/**
	 * Option: unix time a run started; prevents two runs at once.
	 */
	public const LOCK_OPTION = 'epc_pass_notifications_lock';

	/**
	 * Register cron handler for modules that still use scheduled notifications.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'run_continuation' ) );
	}

	/**
	 * Send a push notification to an active pass row.
	 *
	 * @param object $pass_row       Pass row.
	 * @param string $title          Notification title.
	 * @param string $message        Notification body.
	 * @param bool   $require_active Only send when pass status is active.
	 * @return bool
	 */
	public static function send_for_pass( $pass_row, $title, $message, $require_active = true ) {
		if ( ! EPC_Api_Client::is_configured() || ! is_object( $pass_row ) ) {
			return false;
		}

		if ( empty( $pass_row->pass_uid ) ) {
			return false;
		}

		if ( $require_active && 'active' !== (string) $pass_row->status ) {
			return false;
		}

		$title   = sanitize_text_field( (string) $title );
		$message = sanitize_text_field( (string) $message );

		if ( '' === trim( $title ) || '' === trim( $message ) ) {
			return false;
		}

		/**
		 * Filter push title before sending.
		 *
		 * @param string $title    Notification title.
		 * @param object $pass_row Pass row.
		 */
		$title = (string) apply_filters( 'epc_pass_push_title', $title, $pass_row );

		/**
		 * Filter push message before sending.
		 *
		 * @param string $message  Notification body.
		 * @param object $pass_row Pass row.
		 */
		$message = (string) apply_filters( 'epc_pass_push_message', $message, $pass_row );

		$result = EPC_Api_Client::send_pass_push( (string) $pass_row->pass_uid, $title, $message );
		return ! is_wp_error( $result );
	}

	/**
	 * Send a push for a module source record when an active pass exists.
	 *
	 * @param string $module_slug Module slug.
	 * @param int    $source_id   Source record id.
	 * @param string $title       Notification title.
	 * @param string $message     Notification body.
	 * @return bool
	 */
	public static function send_for_module_source( $module_slug, $source_id, $title, $message ) {
		$pass = EPC_DB::get_pass( sanitize_key( (string) $module_slug ), EPC_DB::sanitize_source_id( $source_id ) );
		if ( ! $pass ) {
			return false;
		}

		return self::send_for_pass( $pass, $title, $message );
	}

	/**
	 * Schedule daily notification processing.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled notification processing.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Process notifications for all enabled modules.
	 *
	 * Passes are read in batches by id, so memory stays flat however many passes a site has.
	 * When the time budget runs out, the position is saved and the job resumes a minute later.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! EPC_Api_Client::is_configured() || ! self::acquire_lock() ) {
			return;
		}

		try {
			self::run_locked();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Body of run(), called while holding the lock.
	 *
	 * @return void
	 */
	private static function run_locked() {
		/**
		 * Seconds one run may spend checking passes before it saves its place and resumes later.
		 * Checked between passes, so a run can exceed it by one EpassCard API call.
		 *
		 * @param int $seconds Default 20.
		 */
		$budget   = max( 5, (int) apply_filters( 'epc_pass_notifications_time_budget', 20 ) );
		$deadline = microtime( true ) + $budget;

		$cursor       = get_option( self::CURSOR_OPTION, array() );
		$start_module = is_array( $cursor ) && isset( $cursor['module'] ) ? sanitize_key( (string) $cursor['module'] ) : '';
		$start_after  = is_array( $cursor ) && isset( $cursor['after_id'] ) ? absint( $cursor['after_id'] ) : 0;
		$modules      = EPC_Module_Loader::get_enabled_modules();

		// The saved module may have been disabled since: start over.
		if ( '' !== $start_module && ! isset( $modules[ $start_module ] ) ) {
			$start_module = '';
			$start_after  = 0;
		}

		$started = '' === $start_module;
		foreach ( $modules as $slug => $module ) {
			$after_id = 0;
			if ( ! $started ) {
				if ( $slug !== $start_module ) {
					continue;
				}
				$started  = true;
				$after_id = $start_after;
			}

			if ( $module->uses_batched_notifications() ) {
				$stopped_at = self::run_module( $module, $after_id, $deadline );
				if ( $stopped_at > 0 ) {
					update_option(
						self::CURSOR_OPTION,
						array(
							'module'   => $slug,
							'after_id' => $stopped_at,
						),
						false
					);
					if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
						wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
					}
					return;
				}
			} else {
				// Custom integrations that implement their own loop.
				$module->process_scheduled_notifications();
			}
		}

		delete_option( self::CURSOR_OPTION );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Resume a run that ran out of time. Does nothing when there is nothing to resume.
	 *
	 * @return void
	 */
	public static function run_continuation() {
		if ( false === get_option( self::CURSOR_OPTION, false ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Check one module's active passes in batches until done or out of time.
	 *
	 * @param EPC_Module $module   Module.
	 * @param int        $after_id Resume after this pass id.
	 * @param float      $deadline microtime( true ) deadline.
	 * @return int 0 when finished, otherwise the last pass id processed.
	 */
	private static function run_module( $module, $after_id, $deadline ) {
		$rules = $module->get_notification_rules();
		if ( ! $module->has_scheduled_notification_work( $rules ) ) {
			return 0;
		}

		/**
		 * Passes read per database query in the daily notification job.
		 *
		 * @param int $size Default 200.
		 */
		$batch_size = max( 1, (int) apply_filters( 'epc_pass_notifications_batch_size', 200 ) );

		do {
			$passes = EPC_DB::get_active_passes_for_module( $module->get_slug(), $after_id, $batch_size );
			foreach ( $passes as $pass_row ) {
				$module->process_scheduled_notification_for_pass( $pass_row, $rules );
				$after_id = (int) $pass_row->id;
				if ( microtime( true ) >= $deadline ) {
					return $after_id;
				}
			}
		} while ( count( $passes ) === $batch_size );

		return 0;
	}

	/**
	 * Take the run lock (stale after 10 minutes, e.g. when a run was killed).
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$started = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $started > 0 && ( time() - $started ) < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	/**
	 * Release the run lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Replace template tags in notification copy.
	 *
	 * @param string               $text         Message/title text.
	 * @param array<string, string> $replacements Tag => value.
	 * @return string
	 */
	public static function replace_tags( $text, array $replacements ) {
		$text = (string) $text;

		foreach ( $replacements as $tag => $value ) {
			$text = str_replace( '{' . $tag . '}', (string) $value, $text );
		}

		return $text;
	}

	/**
	 * Whether a notification was already sent for this pass and lead window.
	 *
	 * Since 1.0.9 the flag is stored per target date, so a renewed membership or a
	 * new expiry date gets its own reminder. Flags written by older versions (no date)
	 * still count when they were set inside the current reminder window.
	 *
	 * @param object $pass_row Pass row.
	 * @param string $type     Notification type slug.
	 * @param int    $days     Lead days.
	 * @param int    $event_ts Optional target event timestamp.
	 * @return bool
	 */
	public static function was_sent( $pass_row, $type, $days, $event_ts = 0 ) {
		$meta = EPC_DB::get_pass_meta( $pass_row );
		$sent = isset( $meta['notifications_sent'] ) && is_array( $meta['notifications_sent'] ) ? $meta['notifications_sent'] : array();

		$event_ts = (int) $event_ts;
		if ( $event_ts <= 0 ) {
			return ! empty( $sent[ self::sent_meta_key( $type, $days ) ] );
		}

		if ( ! empty( $sent[ self::sent_meta_key( $type, $days, $event_ts ) ] ) ) {
			return true;
		}

		// Legacy (pre-1.0.9) flag without a date: only honour it for the current window.
		$legacy = $sent[ self::sent_meta_key( $type, $days ) ] ?? '';
		if ( is_string( $legacy ) && '' !== $legacy ) {
			$legacy_ts    = strtotime( $legacy . ' UTC' );
			$window_start = $event_ts - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS );
			if ( false !== $legacy_ts && $legacy_ts >= $window_start ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send push notification when due and not yet sent.
	 *
	 * @param object               $pass_row     Pass row.
	 * @param string               $type         Notification type slug.
	 * @param array<string, mixed> $rule         Saved rule config.
	 * @param int                  $event_ts     Target event timestamp.
	 * @param array<string, string>|Closure $replacements Template tags, or a closure that returns
	 *                                                   them (called only when the push is due).
	 * @return bool
	 */
	public static function maybe_send_for_event( $pass_row, $type, array $rule, $event_ts, $replacements ) {
		if ( empty( $rule['enabled'] ) || empty( $pass_row->pass_uid ) || 'active' !== (string) $pass_row->status ) {
			return false;
		}

		$days = max( 1, absint( $rule['days'] ?? 7 ) );
		if ( $event_ts <= 0 ) {
			return false;
		}

		$now       = time();
		$window_end = $event_ts;
		$window_start = $event_ts - ( $days * DAY_IN_SECONDS );

		if ( $now < $window_start || $now > $window_end ) {
			return false;
		}

		if ( self::was_sent( $pass_row, $type, $days, $event_ts ) ) {
			return false;
		}

		if ( $replacements instanceof Closure ) {
			$replacements = $replacements();
		}
		$replacements = is_array( $replacements ) ? $replacements : array();

		$title   = self::replace_tags( (string) ( $rule['title'] ?? '' ), $replacements );
		$message = self::replace_tags( (string) ( $rule['message'] ?? '' ), $replacements );

		if ( '' === trim( $title ) || '' === trim( $message ) ) {
			return false;
		}

		$result = EPC_Api_Client::send_pass_push( (string) $pass_row->pass_uid, $title, $message );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		self::mark_sent( $pass_row, $type, $days, $event_ts );
		return true;
	}

	/**
	 * Mark notification as sent in pass meta.
	 *
	 * @param object $pass_row Pass row.
	 * @param string $type     Notification type.
	 * @param int    $days     Lead days.
	 * @param int    $event_ts Optional target event timestamp.
	 * @return void
	 */
	public static function mark_sent( $pass_row, $type, $days, $event_ts = 0 ) {
		$meta = EPC_DB::get_pass_meta( $pass_row );
		if ( ! isset( $meta['notifications_sent'] ) || ! is_array( $meta['notifications_sent'] ) ) {
			$meta['notifications_sent'] = array();
		}

		$meta['notifications_sent'][ self::sent_meta_key( $type, $days, (int) $event_ts ) ] = gmdate( 'Y-m-d H:i:s' );
		EPC_DB::update_pass_meta( $pass_row, $meta );
	}

	/**
	 * Build meta key for a sent notification.
	 *
	 * @param string $type Notification type.
	 * @param int    $days Lead days.
	 * @param int    $event_ts Optional target event timestamp (adds the UTC date).
	 * @return string
	 */
	private static function sent_meta_key( $type, $days, $event_ts = 0 ) {
		$key = sanitize_key( (string) $type ) . '_' . absint( $days );
		if ( (int) $event_ts > 0 ) {
			$key .= '_' . gmdate( 'Ymd', (int) $event_ts );
		}
		return $key;
	}
}
