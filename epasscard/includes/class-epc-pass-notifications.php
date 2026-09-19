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
	 * Register cron handler for modules that still use scheduled notifications.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
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
	}

	/**
	 * Process notifications for all enabled modules.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		foreach ( EPC_Module_Loader::get_registry() as $slug => $module ) {
			if ( ! EPC_Module_Settings::is_enabled( $slug ) || ! $module->is_available() ) {
				continue;
			}

			if ( method_exists( $module, 'process_scheduled_notifications' ) ) {
				$module->process_scheduled_notifications();
			}
		}
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
	 * @param object $pass_row Pass row.
	 * @param string $type     Notification type slug.
	 * @param int    $days     Lead days.
	 * @return bool
	 */
	public static function was_sent( $pass_row, $type, $days ) {
		$meta = EPC_DB::get_pass_meta( $pass_row );
		$key  = self::sent_meta_key( $type, $days );

		return ! empty( $meta['notifications_sent'][ $key ] );
	}

	/**
	 * Send push notification when due and not yet sent.
	 *
	 * @param object               $pass_row     Pass row.
	 * @param string               $type         Notification type slug.
	 * @param array<string, mixed> $rule         Saved rule config.
	 * @param int                  $event_ts     Target event timestamp.
	 * @param array<string, string> $replacements Template tags.
	 * @return bool
	 */
	public static function maybe_send_for_event( $pass_row, $type, array $rule, $event_ts, array $replacements ) {
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

		if ( self::was_sent( $pass_row, $type, $days ) ) {
			return false;
		}

		$title   = self::replace_tags( (string) ( $rule['title'] ?? '' ), $replacements );
		$message = self::replace_tags( (string) ( $rule['message'] ?? '' ), $replacements );

		if ( '' === trim( $title ) || '' === trim( $message ) ) {
			return false;
		}

		$result = EPC_Api_Client::send_pass_push( (string) $pass_row->pass_uid, $title, $message );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		self::mark_sent( $pass_row, $type, $days );
		return true;
	}

	/**
	 * Mark notification as sent in pass meta.
	 *
	 * @param object $pass_row Pass row.
	 * @param string $type     Notification type.
	 * @param int    $days     Lead days.
	 * @return void
	 */
	public static function mark_sent( $pass_row, $type, $days ) {
		$meta = EPC_DB::get_pass_meta( $pass_row );
		if ( ! isset( $meta['notifications_sent'] ) || ! is_array( $meta['notifications_sent'] ) ) {
			$meta['notifications_sent'] = array();
		}

		$meta['notifications_sent'][ self::sent_meta_key( $type, $days ) ] = gmdate( 'Y-m-d H:i:s' );
		EPC_DB::update_pass_meta( $pass_row, $meta );
	}

	/**
	 * Build meta key for a sent notification.
	 *
	 * @param string $type Notification type.
	 * @param int    $days Lead days.
	 * @return string
	 */
	private static function sent_meta_key( $type, $days ) {
		return sanitize_key( (string) $type ) . '_' . absint( $days );
	}
}
