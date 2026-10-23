<?php
/**
 * Plan meter numbers.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the top-bar meter shows.
 *
 * The plugin only knows this site: active, non-test cards in wp_epc_passes against the
 * plan's card limit stored at connect. Cards made on other sites or in the EpassCard app
 * are not visible here, so the meter is labelled as this site's count.
 */
class EPC_Plan {

	/**
	 * Share of the limit at which the meter turns orange.
	 */
	const WARN_AT = 0.8;

	/**
	 * Meter data, computed once per request.
	 *
	 * @return array{plan: string, used: int, limit: int, warn: bool, connected: bool}
	 */
	public static function get_meter() {
		static $meter = null;
		if ( null !== $meter ) {
			return $meter;
		}

		$connected = EPC_Connection::is_connected();
		$package   = $connected ? EPC_Connection::get_package_details() : array();
		$limit     = (int) ( $package['num_of_pass'] ?? 0 );
		$used      = $connected ? EPC_DB::count_all_real_passes( 'active' ) : 0;

		$meter = array(
			'plan'      => (string) ( $package['package_name'] ?? '' ),
			'used'      => $used,
			'limit'     => $limit,
			'warn'      => $limit > 0 && $used >= (int) ceil( $limit * self::WARN_AT ),
			'connected' => $connected,
		);

		return $meter;
	}

	/**
	 * Cards left on the plan, or null when the limit is unknown.
	 *
	 * @return int|null
	 */
	public static function cards_left() {
		$meter = self::get_meter();
		return $meter['limit'] > 0 ? max( 0, $meter['limit'] - $meter['used'] ) : null;
	}
}
