<?php
/**
 * Jobs: what the merchant gives customers (loyalty card, membership card, ticket, gift card).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only map of jobs to the modules that do them. Adds no storage.
 */
class EPC_Jobs {

	/**
	 * Jobs keyed by slug, each with a label and its module slugs.
	 *
	 * The setup wizard's goals are the single source, so the wizard and the job
	 * reports always agree.
	 *
	 * @return array<string, array{label: string, modules: array<int, string>}>
	 */
	public static function get_jobs() {
		$jobs = array();
		foreach ( EPC_Setup_Wizard::get_goals() as $slug => $goal ) {
			$jobs[ (string) $slug ] = array(
				'label'   => (string) $goal['label'],
				'modules' => array_values( array_map( 'strval', (array) $goal['modules'] ) ),
			);
		}

		/**
		 * Filter the jobs and the modules that belong to each one.
		 *
		 * Integrations added by other plugins can add their module to a job here.
		 *
		 * @param array<string, array{label: string, modules: array<int, string>}> $jobs Jobs.
		 */
		$filtered = apply_filters( 'epc_jobs', $jobs );

		return is_array( $filtered ) ? $filtered : $jobs;
	}

	/**
	 * Job a module belongs to.
	 *
	 * @param string $module_slug Module slug.
	 * @return string Job slug, or '' when the module belongs to no job.
	 */
	public static function get_job_for_module( $module_slug ) {
		$module_slug = sanitize_key( (string) $module_slug );
		foreach ( self::get_jobs() as $job => $data ) {
			if ( isset( $data['modules'] ) && in_array( $module_slug, (array) $data['modules'], true ) ) {
				return (string) $job;
			}
		}
		return '';
	}
}
