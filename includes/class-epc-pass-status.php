<?php
/**
 * Card statuses shown to the merchant, test cards, and the first real card per job.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-word card statuses built from what is already stored.
 *
 * The `status` column keeps its two stored values ('active', 'revoked'), which other
 * code reads. What the merchant sees is derived: a recorded email makes an active card
 * "Emailed", a recorded failure makes it "Update failed", and so on.
 */
class EPC_Pass_Status {

	/**
	 * Option: GMT time of the first real (non-test) card created on this site.
	 */
	const FIRST_REAL_PASS_OPTION = 'epc_first_real_pass_at';

	/**
	 * Depth of nested test-card calls in this request.
	 *
	 * @var int
	 */
	private static $test_depth = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'epc_pass_email_sent', array( __CLASS__, 'record_email_sent' ), 10, 2 );
		add_action( 'epc_pass_created', array( __CLASS__, 'on_pass_created' ), 20, 3 );
	}

	/**
	 * Run a callback whose cards are test cards (for example the admin's own test card).
	 *
	 * Every pass row written while the callback runs is stored with is_test = 1, and it
	 * does not count as a job's first real card.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	public static function run_as_test( callable $callback ) {
		++self::$test_depth;
		try {
			return call_user_func( $callback );
		} finally {
			--self::$test_depth;
		}
	}

	/**
	 * Whether cards written now are test cards.
	 *
	 * @return bool
	 */
	public static function is_test_context() {
		return self::$test_depth > 0;
	}

	/**
	 * Whether a pass row is a test card.
	 *
	 * @param object|null $pass_row Pass row.
	 * @return bool
	 */
	public static function is_test( $pass_row ) {
		return is_object( $pass_row ) && ! empty( $pass_row->is_test );
	}

	/**
	 * Store when the pass link email was last sent, and how many times.
	 *
	 * Runs on `epc_pass_email_sent`, which fires only after wp_mail() accepted the message.
	 *
	 * @param string $to       Recipient (unused).
	 * @param object $pass_row Pass row.
	 * @return void
	 */
	public static function record_email_sent( $to, $pass_row ) {
		unset( $to );
		if ( ! is_object( $pass_row ) || empty( $pass_row->module ) || ! isset( $pass_row->source_id ) ) {
			return;
		}

		// Re-read the row: the object passed in may predate other meta changes.
		$fresh = EPC_DB::get_pass( (string) $pass_row->module, (string) $pass_row->source_id );
		if ( ! $fresh ) {
			return;
		}

		$meta                  = EPC_DB::get_pass_meta( $fresh );
		$meta['emailed_at']    = current_time( 'mysql', true );
		$meta['emailed_count'] = absint( $meta['emailed_count'] ?? 0 ) + 1;
		EPC_DB::update_pass_meta( $fresh, $meta );
	}

	/**
	 * Fire `epc_job_went_live` when a job gets its first real card.
	 *
	 * The card with the lowest row id among the job's real cards is the first one, so two
	 * cards created at the same moment cannot both fire the event. Sites that already had
	 * cards before this version are already live and do not fire it.
	 *
	 * @param string $module_slug Module slug.
	 * @param mixed  $source_id   Source id (unused).
	 * @param object $pass_row    The new pass row.
	 * @return void
	 */
	public static function on_pass_created( $module_slug, $source_id, $pass_row ) {
		unset( $source_id );
		if ( self::is_test_context() || ! is_object( $pass_row ) || empty( $pass_row->id ) || self::is_test( $pass_row ) ) {
			return;
		}

		$job = EPC_Jobs::get_job_for_module( (string) $module_slug );
		if ( '' === $job ) {
			return;
		}

		$jobs    = EPC_Jobs::get_jobs();
		$modules = isset( $jobs[ $job ]['modules'] ) ? (array) $jobs[ $job ]['modules'] : array();
		if ( EPC_DB::count_real_passes_before( $modules, (int) $pass_row->id ) > 0 ) {
			return;
		}

		if ( false === get_option( self::FIRST_REAL_PASS_OPTION, false ) ) {
			update_option( self::FIRST_REAL_PASS_OPTION, current_time( 'mysql', true ), false );
		}

		/**
		 * Fires once per job, when its first real (non-test) card is created.
		 *
		 * @param string $job         Job slug: loyalty, membership, events or gift-cards.
		 * @param string $module_slug Module that created the card.
		 * @param object $pass_row    The new pass row.
		 */
		do_action( 'epc_job_went_live', $job, sanitize_key( (string) $module_slug ), $pass_row );
	}

	/**
	 * Status shown to the merchant for one pass row.
	 *
	 * @param object $pass_row Pass row.
	 * @return array{key: string, label: string, detail: string, is_test: bool}
	 */
	public static function get_display_status( $pass_row ) {
		$out = array(
			'key'     => '',
			'label'   => '',
			'detail'  => '',
			'is_test' => self::is_test( $pass_row ),
		);
		if ( ! is_object( $pass_row ) ) {
			return $out;
		}

		$status = (string) ( $pass_row->status ?? '' );
		$meta   = EPC_DB::get_pass_meta( $pass_row );

		if ( 'revoked' === $status ) {
			$out['key']   = 'revoked';
			$out['label'] = __( 'Revoked', 'epasscard' );
			return $out;
		}

		if ( 'active' !== $status ) {
			$out['key']   = sanitize_key( $status );
			$out['label'] = ucfirst( $status );
			return $out;
		}

		if ( ! empty( $meta['revoke_pending'] ) ) {
			$out['key']    = 'revoke_pending';
			$out['label']  = __( 'Revoke pending', 'epasscard' );
			$out['detail'] = __( 'Still active in wallets. Expiring it failed; it is retried automatically.', 'epasscard' );
			return $out;
		}

		$issue = class_exists( 'EPC_Pass_Issues' ) && isset( $pass_row->module, $pass_row->source_id )
			? EPC_Pass_Issues::get( (string) $pass_row->module, (string) $pass_row->source_id )
			: null;
		if ( is_array( $issue ) && in_array( (string) ( $issue['action'] ?? '' ), array( 'sync', 'create', 'update' ), true ) ) {
			$out['key']    = 'update_failed';
			$out['label']  = __( 'Update failed', 'epasscard' );
			$out['detail'] = (string) ( $issue['message'] ?? '' );
			return $out;
		}

		if ( ! empty( $meta['emailed_at'] ) ) {
			$out['key']    = 'emailed';
			$out['label']  = __( 'Emailed', 'epasscard' );
			$out['detail'] = sprintf(
				/* translators: %s: date and time the pass link email was last sent */
				__( 'Pass link emailed from this site on %s.', 'epasscard' ),
				(string) get_date_from_gmt( (string) $meta['emailed_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) )
			);
			return $out;
		}

		$out['key']   = 'created';
		$out['label'] = __( 'Created', 'epasscard' );
		return $out;
	}

	/**
	 * Status cell HTML for admin lists: label, test badge, and the detail as a tooltip.
	 *
	 * @param object $pass_row Pass row.
	 * @return string
	 */
	public static function render_status_html( $pass_row ) {
		$status = self::get_display_status( $pass_row );
		$html   = '<span class="epc-pass-status epc-pass-status--' . esc_attr( $status['key'] ) . '"'
			. ( '' !== $status['detail'] ? ' title="' . esc_attr( $status['detail'] ) . '"' : '' )
			. '>' . esc_html( $status['label'] ) . '</span>';

		if ( $status['is_test'] ) {
			$html .= ' <span class="epc-pass-test-badge">' . esc_html__( 'Test', 'epasscard' ) . '</span>';
		}

		return $html;
	}
}
