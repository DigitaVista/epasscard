<?php
/**
 * Third-party plugin dependency helpers.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether a plugin bootstrap file is active.
 *
 * @param string $plugin_file Plugin path relative to wp-content/plugins.
 * @return bool
 */
function epc_is_plugin_active( $plugin_file ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( $plugin_file );
}

/**
 * Whether MemberPress is installed and active.
 *
 * @return bool
 */
function epc_is_memberpress_active() {
	if ( defined( 'MEPR_VERSION' ) || defined( 'MEPR_PLUGIN_NAME' ) ) {
		return true;
	}

	if ( class_exists( 'MeprProduct' ) || class_exists( 'MeprAppCtrl' ) ) {
		return true;
	}

	$plugin_files = array(
		'memberpress/memberpress.php',
		'memberpress-developer/memberpress.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether WooCommerce Subscriptions is installed and active.
 *
 * @return bool
 */
function epc_is_woocommerce_subscriptions_active() {
	if ( class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscriptions' ) ) {
		return true;
	}

	$plugin_files = array(
		'woocommerce-subscriptions/woocommerce-subscriptions.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether WooCommerce core is installed and active.
 *
 * @return bool
 */
function epc_is_woocommerce_active() {
	if ( defined( 'WC_VERSION' ) || class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'woocommerce/woocommerce.php' );
}

/**
 * Whether Paid Memberships Pro is installed and active.
 *
 * @return bool
 */
function epc_is_paid_memberships_pro_active() {
	if ( defined( 'PMPRO_VERSION' ) || defined( 'PMPRO_DIR' ) ) {
		return true;
	}

	if ( function_exists( 'pmpro_getAllLevels' ) || function_exists( 'pmpro_changeMembershipLevel' ) ) {
		return true;
	}

	$plugin_files = array(
		'paid-memberships-pro/paid-memberships-pro.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Ultimate Membership Pro (Indeed) is installed and active.
 *
 * @return bool
 */
function epc_is_ultimate_membership_pro_active() {
	if ( defined( 'IHC_PATH' ) || defined( 'IHC_URL' ) || defined( 'IHC_PLUGIN_VERSION' ) ) {
		return true;
	}

	if ( function_exists( 'ihc_get_level_by_id' ) || function_exists( 'ihc_handle_levels_assign' ) ) {
		return true;
	}

	$plugin_files = array(
		'indeed-membership-pro/indeed-membership-pro.php',
		'ultimate-membership-pro/indeed-membership-pro.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Simple Membership is installed and active.
 *
 * @return bool
 */
function epc_is_simple_membership_active() {
	if ( defined( 'SIMPLE_WP_MEMBERSHIP_VER' ) || defined( 'SIMPLE_WP_MEMBERSHIP_PATH' ) ) {
		return true;
	}

	if ( class_exists( 'SwpmMemberUtils' ) || class_exists( 'SimpleWpMembership' ) ) {
		return true;
	}

	$plugin_files = array(
		'simple-membership/simple-wp-membership.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether The Events Calendar is installed and active.
 *
 * @return bool
 */
function epc_is_the_events_calendar_active() {
	if ( defined( 'TRIBE_EVENTS_FILE' ) || defined( 'TRIBE_EVENTS_MAJOR_VERSION' ) ) {
		return true;
	}

	if ( class_exists( 'Tribe__Events__Main' ) || function_exists( 'tribe_events' ) ) {
		return true;
	}

	$plugin_files = array(
		'the-events-calendar/the-events-calendar.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Event Tickets is installed and active.
 *
 * @return bool
 */
function epc_is_event_tickets_active() {
	if ( defined( 'EVENT_TICKETS_DIR' ) || defined( 'EVENT_TICKETS_MAIN_PLUGIN_FILE' ) ) {
		return true;
	}

	if ( class_exists( 'Tribe__Tickets__Main' ) || function_exists( 'tribe_tickets' ) ) {
		return true;
	}

	$plugin_files = array(
		'event-tickets/event-tickets.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Events Manager is installed and active.
 *
 * @return bool
 */
function epc_is_events_manager_active() {
	if ( defined( 'EM_VERSION' ) || defined( 'EM_DIR' ) ) {
		return true;
	}

	if ( class_exists( 'EM_Booking' ) || class_exists( 'EM_Event' ) ) {
		return true;
	}

	$plugin_files = array(
		'events-manager/events-manager.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether PW WooCommerce Gift Cards is installed and active.
 *
 * @return bool
 */
function epc_is_pw_gift_cards_active() {
	if ( defined( 'PWGC_VERSION' ) || class_exists( 'PW_Gift_Card' ) || class_exists( 'PW_Gift_Cards' ) ) {
		return true;
	}

	$plugin_files = array(
		'pw-woocommerce-gift-cards/pw-gift-cards.php',
		'pw-gift-cards/pw-gift-cards.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether YITH WooCommerce Gift Cards is installed and active.
 *
 * @return bool
 */
function epc_is_yith_gift_cards_active() {
	if ( defined( 'YITH_YWGC_VERSION' ) || defined( 'YITH_YWGC_FREE' ) || function_exists( 'YITH_YWGC' ) ) {
		return true;
	}

	if ( class_exists( 'YITH_YWGC_Gift_Card' ) || class_exists( 'YITH_WooCommerce_Gift_Cards' ) ) {
		return true;
	}

	$plugin_files = array(
		'yith-woocommerce-gift-cards/init.php',
		'yith-woocommerce-gift-cards/yith-woocommerce-gift-cards.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( epc_is_plugin_active( $plugin_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a full name from first + last, with optional fallback.
 *
 * @param string $first_name First name.
 * @param string $last_name  Last name.
 * @param string $fallback   Used when both names are empty (e.g. display name).
 * @return string
 */
function epc_format_user_full_name( $first_name, $last_name, $fallback = '' ) {
	$full = trim( trim( (string) $first_name ) . ' ' . trim( (string) $last_name ) );

	if ( '' !== $full ) {
		return $full;
	}

	return trim( (string) $fallback );
}

/**
 * Years ahead used for lifetime / open-ended expiry on wallet passes.
 *
 * Global EpassCard rule: when an expiry/end date field is mapped but the source
 * has no real end date (lifetime, until cancelled, empty), send +99 years.
 *
 * @return int
 */
function epc_lifetime_expiry_years() {
	return 99;
}

/**
 * Unix timestamp for the lifetime expiry sentinel (+99 years from now or base).
 *
 * @param int|null $from_timestamp Base timestamp (default: now).
 * @return int
 */
function epc_lifetime_expiry_timestamp( $from_timestamp = null ) {
	$base = null !== $from_timestamp ? (int) $from_timestamp : time();
	if ( $base <= 0 ) {
		$base = time();
	}

	$ts = strtotime( '+' . epc_lifetime_expiry_years() . ' years', $base );

	return $ts ? (int) $ts : $base;
}

/**
 * Format an expiry timestamp for pass field mapping.
 *
 * Missing, zero, or open-ended (PHP_INT_MAX) timestamps become +99 years.
 *
 * @param int $timestamp Expiry unix timestamp.
 * @return string Formatted date for the pass API.
 */
function epc_format_pass_expiry_timestamp( $timestamp ) {
	$ts = is_numeric( $timestamp ) ? (int) $timestamp : 0;

	if ( $ts <= 0 || $ts >= PHP_INT_MAX ) {
		$ts = epc_lifetime_expiry_timestamp();
	}

	return wp_date( get_option( 'date_format' ), $ts );
}

/**
 * Format a raw datetime for pass expiry mapping (lifetime → +99 years).
 *
 * @param string $datetime MySQL/datetime string or empty.
 * @return string
 */
function epc_format_pass_expiry_datetime( $datetime ) {
	$datetime = trim( (string) $datetime );

	if ( '' === $datetime || '0000-00-00' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
		return epc_format_pass_expiry_timestamp( 0 );
	}

	if ( class_exists( 'MeprUtils' ) && method_exists( 'MeprUtils', 'db_lifetime' ) && $datetime === MeprUtils::db_lifetime() ) {
		return epc_format_pass_expiry_timestamp( 0 );
	}

	$ts = strtotime( $datetime );

	return epc_format_pass_expiry_timestamp( $ts ? (int) $ts : 0 );
}

/**
 * WordPress option name for EpassCard connection settings.
 *
 * @return string
 */
function epc_get_connection_option_name() {
	return EPC_Connection::OPTION;
}

/**
 * Get the decrypted EpassCard X-Api-Key for custom integrations.
 *
 * @return string Plain API key, or empty string when not connected.
 */
function epc_get_api_key() {
	if ( ! class_exists( 'EPC_Connection' ) ) {
		return '';
	}

	return EPC_Connection::get_api_key();
}

/**
 * Register a custom EpassCard integration module.
 *
 * Call from plugins_loaded (priority < 20) so the module is available before EpassCard boots.
 *
 * @param string $slug       Unique module slug (lowercase, hyphens).
 * @param string $file       Module PHP file name (relative to modules/) or absolute path.
 * @param string $class_name PHP class extending EPC_Module.
 * @return void
 */
function epc_register_module( $slug, $file, $class_name ) {
	$slug       = sanitize_key( (string) $slug );
	$file       = (string) $file;
	$class_name = (string) $class_name;

	if ( '' === $slug || '' === $file || '' === $class_name ) {
		return;
	}

	add_filter(
		'epc_module_files',
		static function ( $files ) use ( $slug, $file ) {
			if ( ! is_array( $files ) ) {
				$files = array();
			}
			$files[ $slug ] = $file;
			return $files;
		}
	);

	add_filter(
		'epc_module_classes',
		static function ( $map ) use ( $slug, $class_name ) {
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			$map[ $slug ] = $class_name;
			return $map;
		}
	);

	if ( str_starts_with( $file, '/' ) || ( 1 < strlen( $file ) && ':' === $file[1] ) ) {
		add_filter(
			'epc_module_path',
			static function ( $path, $filter_slug, $filter_file ) use ( $slug, $file ) {
				if ( $filter_slug === $slug && $filter_file === $file ) {
					return $file;
				}
				return $path;
			},
			10,
			3
		);
	}
}
