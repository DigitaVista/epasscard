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
	 * Days of recent activity the forecast looks at.
	 */
	const FORECAST_DAYS = 30;

	/**
	 * Plain-language forecast for Settings → Account & plan: "4 left, about 2 days at this
	 * rate". The rate is real cards created on this site over the last 30 days (or since the
	 * first card, when that is more recent). Empty when the plan limit is unknown.
	 *
	 * @return string
	 */
	public static function get_forecast() {
		$left = self::cards_left();
		if ( null === $left ) {
			return '';
		}

		$window  = self::FORECAST_DAYS;
		$created = 0;
		if ( $left > 0 ) {
			$age = EPC_DB::first_real_pass_age_days();
			if ( null !== $age ) {
				$window  = min( self::FORECAST_DAYS, max( 1, $age ) );
				$created = EPC_DB::count_real_passes_last_days( $window );
			}
		}

		return self::forecast_text( $left, $created, $window );
	}

	/**
	 * Forecast sentence from the numbers.
	 *
	 * @param int $left    Cards left on the plan.
	 * @param int $created Real cards created in the window.
	 * @param int $window  Window length in days.
	 * @return string
	 */
	public static function forecast_text( $left, $created, $window ) {
		$left    = max( 0, (int) $left );
		$created = max( 0, (int) $created );
		$window  = max( 1, (int) $window );

		if ( 0 === $left ) {
			return __( 'No cards left. New cards are not created until you change the plan.', 'epasscard' );
		}

		/* translators: %s: number of cards left on the plan */
		$left_text = sprintf( _n( '%s card left', '%s cards left', $left, 'epasscard' ), number_format_i18n( $left ) );
		if ( 0 === $created ) {
			return $left_text;
		}

		$days = $left / ( $created / $window );
		if ( $days < 1 ) {
			$when = __( 'less than a day', 'epasscard' );
		} elseif ( $days < 14 ) {
			$whole = (int) round( $days );
			/* translators: %s: number of days */
			$when = sprintf( _n( 'about %s day', 'about %s days', $whole, 'epasscard' ), number_format_i18n( $whole ) );
		} elseif ( $days <= 60 ) {
			$weeks = (int) round( $days / 7 );
			/* translators: %s: number of weeks */
			$when = sprintf( _n( 'about %s week', 'about %s weeks', $weeks, 'epasscard' ), number_format_i18n( $weeks ) );
		} else {
			$when = __( 'more than two months', 'epasscard' );
		}

		/* translators: 1: "4 cards left", 2: "about 2 days" */
		return sprintf( __( '%1$s, %2$s at this rate.', 'epasscard' ), $left_text, $when );
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
