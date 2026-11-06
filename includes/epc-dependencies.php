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
 * Whether Ultimate Member is installed and active.
 *
 * @return bool
 */
function epc_is_ultimate_member_active() {
	if ( defined( 'UM_PLUGIN' ) && function_exists( 'UM' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'ultimate-member/ultimate-member.php' );
}

/**
 * Whether Amelia (Lite or a paid plan) is installed and active.
 *
 * @return bool
 */
function epc_is_amelia_active() {
	if ( defined( 'AMELIA_VERSION' ) && defined( 'AMELIA_PATH' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'ameliabooking/ameliabooking.php' );
}

/**
 * Whether Tickera is installed and active.
 *
 * @return bool
 */
function epc_is_tickera_active() {
	if ( class_exists( '\\Tickera\\TC' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'tickera-event-ticketing-system/tickera.php' );
}

/**
 * Whether WPForms (Lite or Pro) is installed and active.
 *
 * @return bool
 */
function epc_is_wpforms_active() {
	if ( defined( 'WPFORMS_VERSION' ) && function_exists( 'wpforms' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'wpforms-lite/wpforms.php' ) || epc_is_plugin_active( 'wpforms/wpforms.php' );
}

/**
 * Whether WPLoyalty (free or Pro) is installed and active.
 *
 * @return bool
 */
function epc_is_wployalty_active() {
	if ( defined( 'WLR_PLUGIN_VERSION' ) && class_exists( 'Wlr\\App\\Router' ) ) {
		return true;
	}

	return epc_is_plugin_active( 'wployalty/wp-loyalty-rules-lite.php' ) || epc_is_plugin_active( 'wp-loyalty-rules/wp-loyalty-rules.php' );
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
 * A user's name for screens and emails: first and last name from their profile, else their
 * display name (often the username, e.g. for shop customers).
 *
 * @param WP_User|false|null $user User.
 * @return string
 */
function epc_user_name( $user ) {
	if ( ! $user instanceof WP_User ) {
		return '';
	}
	return epc_format_user_full_name( (string) $user->first_name, (string) $user->last_name, (string) $user->display_name );
}

/**
 * Resolve the WordPress user that should own a gift card wallet pass.
 *
 * Prefers a user matching the recipient email. If none exists, falls back to
 * the WooCommerce order customer (then billing email) so the purchaser can
 * still see the pass under My Account → Wallet passes.
 *
 * @param string $recipient_email Gift card recipient email.
 * @param int    $order_id        Originating order ID.
 * @return int User ID or 0.
 */
function epc_resolve_gift_card_user_id( $recipient_email, $order_id = 0 ) {
	$recipient_email = sanitize_email( (string) $recipient_email );
	if ( $recipient_email && is_email( $recipient_email ) ) {
		$user = get_user_by( 'email', $recipient_email );
		if ( $user ) {
			return (int) $user->ID;
		}
	}

	$order_id = absint( $order_id );
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return 0;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return 0;
	}

	$user_id = (int) $order->get_user_id();
	if ( $user_id > 0 ) {
		return $user_id;
	}

	$billing = sanitize_email( (string) $order->get_billing_email() );
	if ( $billing && is_email( $billing ) ) {
		$user = get_user_by( 'email', $billing );
		if ( $user ) {
			return (int) $user->ID;
		}
	}

	return 0;
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
 * MySQL datetime for POST /pass-expire when restoring a pass.
 *
 * A missing or already-passed time becomes +99 years so the pass is in the future
 * and update-pass is allowed again.
 *
 * @param int $timestamp Expiry unix timestamp.
 * @return string Y-m-d H:i:s in the site timezone.
 */
function epc_pass_expire_mysql_timestamp( $timestamp ) {
	$ts = is_numeric( $timestamp ) ? (int) $timestamp : 0;

	if ( $ts <= time() || $ts >= PHP_INT_MAX ) {
		$ts = epc_lifetime_expiry_timestamp();
	}

	return wp_date( 'Y-m-d H:i:s', $ts );
}

/**
 * MySQL datetime for POST /pass-expire from a source expiry string.
 *
 * @param string $datetime MySQL/datetime string or empty.
 * @return string Y-m-d H:i:s
 */
function epc_pass_expire_mysql_datetime( $datetime ) {
	$datetime = trim( (string) $datetime );

	if ( '' === $datetime || '0000-00-00' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
		return epc_pass_expire_mysql_timestamp( 0 );
	}

	if ( class_exists( 'MeprUtils' ) && method_exists( 'MeprUtils', 'db_lifetime' ) && $datetime === MeprUtils::db_lifetime() ) {
		return epc_pass_expire_mysql_timestamp( 0 );
	}

	$ts = strtotime( $datetime );

	return epc_pass_expire_mysql_timestamp( $ts ? (int) $ts : 0 );
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
 * Capability for EpassCard settings (connection, API log, setup wizard, integration settings).
 *
 * @return string
 */
function epc_settings_capability() {
	return 'manage_options';
}

/**
 * Staff capability for day-to-day pass work: pass buttons, issued passes and the Loyalty screens.
 *
 * Administrators (manage_options) always have access as well. Default: manage_woocommerce
 * (Shop Manager), which matches EpassCard 1.1.x.
 *
 * @return string
 */
function epc_manage_passes_capability() {
	/**
	 * Filter the staff capability for day-to-day pass work.
	 *
	 * @param string $capability Default 'manage_woocommerce'.
	 */
	$capability = apply_filters( 'epc_manage_passes_cap', 'manage_woocommerce' );

	return is_string( $capability ) ? $capability : 'manage_woocommerce';
}

/**
 * Whether the current user can do day-to-day pass work.
 *
 * @return bool
 */
function epc_current_user_can_manage_passes() {
	if ( current_user_can( epc_settings_capability() ) ) {
		return true;
	}

	$capability = epc_manage_passes_capability();

	return '' !== $capability && current_user_can( $capability );
}

/**
 * Menu capability for screens open to pass staff.
 *
 * add_submenu_page() takes one capability, so administrators get theirs and everyone else the staff one.
 *
 * @return string
 */
function epc_manage_passes_menu_capability() {
	if ( current_user_can( epc_settings_capability() ) ) {
		return epc_settings_capability();
	}

	$capability = epc_manage_passes_capability();

	return '' !== $capability ? $capability : epc_settings_capability();
}

/**
 * Add UTM tags to a link to epasscard.com, so installs, sign-ups and upgrades from the plugin
 * can be measured. Links to other hosts are returned unchanged. Nothing about the site or the
 * user is added: only the fixed source and medium and the name of the screen.
 *
 * @param string $url      URL.
 * @param string $campaign Screen the link is on (home, setup, try, cards, design, settings, help...).
 *                         Empty: the current EpassCard screen.
 * @return string
 */
function epc_utm_url( $url, $campaign = '' ) {
	$url  = (string) $url;
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( ! in_array( $host, array( 'epasscard.com', 'www.epasscard.com', 'app.epasscard.com' ), true ) ) {
		return $url;
	}

	$campaign = sanitize_key( (string) $campaign );
	if ( '' === $campaign && class_exists( 'EPC_Admin_App' ) ) {
		$page = EPC_Admin_App::current_page();
		if ( EPC_Admin_App::PAGE_SETUP === $page ) {
			$campaign = 'setup';
		} elseif ( EPC_Admin_App::PAGE_TRY === $page ) {
			$campaign = 'try';
		} elseif ( '' !== $page ) {
			$campaign = EPC_Admin_App::section_for_page( $page );
		}
	}

	$args = array(
		'utm_source' => 'wp-plugin',
		'utm_medium' => 'plugin-admin',
	);
	if ( '' !== $campaign ) {
		$args['utm_campaign'] = $campaign;
	}

	/**
	 * Filter the UTM tags added to links to epasscard.com. Return an empty array for none.
	 *
	 * @param array<string, string> $args     Query args.
	 * @param string                $url      URL without the tags.
	 * @param string                $campaign Screen name.
	 */
	$args = apply_filters( 'epc_utm_args', $args, $url, $campaign );

	return is_array( $args ) && ! empty( $args ) ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
}

/**
 * EpassCard Terms and Conditions URL.
 *
 * @return string
 */
function epc_terms_url() {
	/**
	 * Filter the EpassCard Terms and Conditions URL shown at sign-up and sign-in.
	 *
	 * @param string $url URL.
	 */
	return (string) apply_filters( 'epc_terms_url', 'https://www.epasscard.com/terms-and-conditions/' );
}

/**
 * EpassCard Privacy Policy URL.
 *
 * @return string
 */
function epc_privacy_url() {
	/**
	 * Filter the EpassCard Privacy Policy URL shown at sign-up and sign-in.
	 *
	 * @param string $url URL.
	 */
	return (string) apply_filters( 'epc_privacy_url', 'https://www.epasscard.com/privacy-policy/' );
}

/**
 * Consent notice shown next to each button that sends account data to EpassCard.
 *
 * States exactly what each action sends (see EPC_Api_Client::sign_up(), generate_api_key(),
 * validate_api_key() and api_request_headers(), which adds the site address to every request).
 *
 * @param string $context sign_up, sign_in or api_key.
 * @return string Escaped HTML (text with two links).
 */
function epc_consent_notice_html( $context ) {
	$terms   = '<a href="' . esc_url( epc_terms_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms and Conditions', 'epasscard' ) . '</a>';
	$privacy = '<a href="' . esc_url( epc_privacy_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'epasscard' ) . '</a>';

	switch ( sanitize_key( (string) $context ) ) {
		case 'sign_up':
			/* translators: 1: Terms and Conditions link, 2: Privacy Policy link. */
			$text = esc_html__( 'By creating an account you agree to EpassCard\'s %1$s and %2$s. Your name, email address and site address are sent to EpassCard to create it.', 'epasscard' );
			break;
		case 'sign_in':
			/* translators: 1: Terms and Conditions link, 2: Privacy Policy link. */
			$text = esc_html__( 'Your email and password are sent to EpassCard once to create an API key, together with your site name, site address and timezone. The password is not stored. By connecting you agree to EpassCard\'s %1$s and %2$s.', 'epasscard' );
			break;
		default:
			/* translators: 1: Terms and Conditions link, 2: Privacy Policy link. */
			$text = esc_html__( 'The API key and your site address are sent to EpassCard to check the key. By connecting you agree to EpassCard\'s %1$s and %2$s.', 'epasscard' );
			break;
	}

	return sprintf( $text, $terms, $privacy );
}

/**
 * Allowed HTML for epc_consent_notice_html().
 *
 * @return array<string, array<string, bool>>
 */
function epc_consent_notice_allowed_html() {
	return array(
		'a' => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);
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

	if ( 0 === strpos( $file, '/' ) || ( 1 < strlen( $file ) && ':' === $file[1] ) ) {
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

/**
 * Create the wallet card for a record of an active integration, or update it when it exists
 * (a voided card is made live again). Runs the integration's own sync: same design, same
 * fields, never a second card.
 *
 * Example: epc_issue_pass( 'paid-memberships-pro', $membership_row_id, array( 'email' => true ) ).
 *
 * @param string               $module    Integration slug, e.g. paid-memberships-pro, woocommerce-loyalty.
 * @param string|int           $source_id Record id used by the integration (membership row, subscription,
 *                                        loyalty customer's user id, attendee, gift card …).
 * @param array<string, mixed> $args      Optional: email (bool) – email the card link once.
 * @return array<string, mixed>|\WP_Error Card data: module, source_id, entity_id, user_id, pass_uid,
 *                                        pass_link, status, is_test, created_at, updated_at, created, emailed.
 */
function epc_issue_pass( $module, $source_id, array $args = array() ) {
	return EPC_Developer_Api::issue( $module, $source_id, $args );
}

/**
 * Update a record's existing card with the record's current values. Fails with
 * epc_pass_missing when the record has no card.
 *
 * @param string     $module    Integration slug.
 * @param string|int $source_id Record id.
 * @return array<string, mixed>|\WP_Error Card data.
 */
function epc_update_pass( $module, $source_id ) {
	return EPC_Developer_Api::update( $module, $source_id );
}

/**
 * Void a record's card: it expires in Apple Wallet and Google Wallet and shows as revoked.
 *
 * @param string     $module    Integration slug.
 * @param string|int $source_id Record id.
 * @return array<string, mixed>|\WP_Error Card data.
 */
function epc_void_pass( $module, $source_id ) {
	return EPC_Developer_Api::void( $module, $source_id );
}

/**
 * A record's card data, or a WP_Error epc_pass_missing.
 *
 * @param string     $module    Integration slug.
 * @param string|int $source_id Record id.
 * @return array<string, mixed>|\WP_Error Card data.
 */
function epc_get_pass( $module, $source_id ) {
	return EPC_Developer_Api::get( $module, $source_id );
}
