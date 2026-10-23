<?php
/**
 * The Home checklist: Connect → Job & rules → Design → Try it → Go live.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checklist state and step status.
 *
 * Everything that can be read from the site (connection, design, cards) is read, not stored.
 * Only the merchant's choices are stored, in one option: the job, the module, whether the
 * rules were confirmed, and whether the optional test was done or skipped.
 */
class EPC_Onboarding {

	/**
	 * Option holding the merchant's choices (not autoloaded).
	 */
	const OPTION = 'epc_setup';

	/**
	 * Stored choices with defaults.
	 *
	 * Sites that set up EpassCard before this version are adopted: the first enabled
	 * integration with a design becomes the job, and its rules count as confirmed.
	 *
	 * @return array{job: string, module: string, rules_confirmed: bool, try: string}
	 */
	public static function get_state() {
		$saved = get_option( self::OPTION, null );
		if ( ! is_array( $saved ) ) {
			$saved = self::adopt_existing_setup();
			update_option( self::OPTION, $saved, false );
		}

		return array(
			'job'             => sanitize_key( (string) ( $saved['job'] ?? '' ) ),
			'module'          => sanitize_key( (string) ( $saved['module'] ?? '' ) ),
			'rules_confirmed' => ! empty( $saved['rules_confirmed'] ),
			'try'             => in_array( (string) ( $saved['try'] ?? '' ), array( 'done', 'skipped' ), true ) ? (string) $saved['try'] : '',
		);
	}

	/**
	 * Save choices (merged into what is stored).
	 *
	 * @param array<string, mixed> $changes Changes.
	 * @return void
	 */
	public static function update( array $changes ) {
		$state = array_merge( self::get_state(), $changes );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * First state for a site: what the older setup already did.
	 *
	 * @return array<string, mixed>
	 */
	private static function adopt_existing_setup() {
		$state = array(
			'job'             => '',
			'module'          => '',
			'rules_confirmed' => false,
			'try'             => '',
		);

		// The 1.1 setup wizard remembers the job and module.
		$wizard = get_option( EPC_Setup_Wizard::OPTION_KEY, array() );
		if ( is_array( $wizard ) && ! empty( $wizard['module'] ) ) {
			$state['module'] = sanitize_key( (string) $wizard['module'] );
			$state['job']    = EPC_Jobs::get_job_for_module( $state['module'] );
		}

		if ( '' === $state['module'] ) {
			foreach ( EPC_Module_Settings::get_enabled_slugs() as $slug ) {
				$module = EPC_Module_Loader::get_module_instance( $slug );
				if ( $module instanceof EPC_Module && EPC_Jobs::has_design( $module ) ) {
					$state['module'] = $slug;
					$state['job']    = EPC_Jobs::get_job_for_module( $slug );
					break;
				}
			}
		}

		// A site that already has a design set its rules with the older screens.
		if ( '' !== $state['module'] ) {
			$module = EPC_Module_Loader::get_module_instance( $state['module'] );
			if ( $module instanceof EPC_Module && EPC_Jobs::has_design( $module ) ) {
				$state['rules_confirmed'] = true;
			}
		}

		return $state;
	}

	/**
	 * Steps with their status.
	 *
	 * @return array<string, array{label: string, status: string}> status: done|current|todo|optional|skipped
	 */
	public static function get_steps() {
		$state     = self::get_state();
		$connected = EPC_Connection::is_connected();
		$job       = $state['job'];
		$status    = '' !== $job ? EPC_Jobs::get_status( $job, $state['module'] ) : null;

		$done = array(
			'connect' => $connected,
			'job'     => $connected && '' !== $job && $status && '' !== $status['module'] && EPC_Module_Settings::is_enabled( $status['module'] ) && $state['rules_confirmed'],
			'design'  => false,
			'try'     => '' !== $state['try'],
			'live'    => false,
		);
		if ( $done['job'] && $status ) {
			$module         = EPC_Module_Loader::get_module_instance( $status['module'] );
			$done['design'] = $module instanceof EPC_Module && EPC_Jobs::has_design( $module );
			$done['live']   = $done['design'] && EPC_Jobs::LIVE === $status['state'];
		}

		$labels = array(
			'connect' => __( 'Connect', 'epasscard' ),
			'job'     => __( 'Job & rules', 'epasscard' ),
			'design'  => __( 'Design', 'epasscard' ),
			'try'     => __( 'Try it', 'epasscard' ),
			'live'    => __( 'Go live', 'epasscard' ),
		);

		$steps   = array();
		$current = '';
		foreach ( $labels as $id => $label ) {
			if ( $done[ $id ] ) {
				$step_status = ( 'try' === $id && 'skipped' === $state['try'] ) ? 'skipped' : 'done';
			} elseif ( 'try' === $id ) {
				// Optional: never blocks the steps after it.
				$step_status = 'optional';
			} elseif ( '' === $current ) {
				$step_status = 'current';
				$current     = $id;
			} else {
				$step_status = 'todo';
			}
			$steps[ $id ] = array(
				'label'  => $label,
				'status' => $step_status,
			);
		}

		// Try it becomes the current step once the design is done and the merchant has not chosen yet.
		if ( 'live' === $current && 'optional' === $steps['try']['status'] ) {
			$steps['try']['status']  = 'current';
			$steps['live']['status'] = 'todo';
		}

		return $steps;
	}

	/**
	 * Id of the step to work on, or '' when setup is complete.
	 *
	 * @return string
	 */
	public static function current_step() {
		foreach ( self::get_steps() as $id => $step ) {
			if ( 'current' === $step['status'] ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Whether the checklist is finished: connected, job chosen, design saved and a real card issued.
	 *
	 * Sites that already issue real cards count as finished even if the checklist was never used.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		$steps = self::get_steps();
		if ( 'done' === $steps['live']['status'] ) {
			return true;
		}
		return EPC_Connection::is_connected() && EPC_DB::count_all_real_passes( 'active' ) > 0 && '' === self::get_state()['job'];
	}
}
