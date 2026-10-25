<?php
/**
 * "User profile field" mapping mode: any user meta (or account field) on a card.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a design show any field of the card holder's WordPress profile, such as a member ID
 * another plugin keeps in user meta.
 *
 * - Mapping entry: { "type": "user_meta", "value": "<meta key>" }. The account fields
 *   user_email, display_name, user_login, user_url, user_registered and user_id work too.
 * - The value comes from the WordPress account the card belongs to. A card without an account
 *   (a guest) leaves the field out.
 * - Fields holding secrets (passwords, tokens, keys, sessions, roles) are refused.
 * - Changing a mapped field on a profile updates that person's cards.
 */
class EPC_User_Fields {

	/**
	 * Mapping mode slug.
	 */
	const MODE = 'user_meta';

	/**
	 * Prefix of the resolved values in the source values.
	 */
	const VALUE_PREFIX = '__user_meta:';

	/**
	 * Option caching the profile fields the designs use (autoloaded: read whenever user meta
	 * changes, which some plugins do on every page view).
	 */
	const CACHE_OPTION = 'epc_user_field_keys';

	/**
	 * Age after which the cache is rebuilt even if no design changed (seconds).
	 */
	const CACHE_TTL = 3600;

	/**
	 * Account fields (not user meta) a design can use.
	 *
	 * @var array<int, string>
	 */
	private static $account_fields = array( 'user_email', 'display_name', 'user_login', 'user_url', 'user_registered', 'user_id' );

	/**
	 * Mapped keys, per request.
	 *
	 * @var array<int, string>|null
	 */
	private static $mapped_keys = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta' ), 20, 3 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta' ), 20, 3 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'on_user_meta' ), 20, 3 );
		// A design saved, changed or removed: forget the cached field list.
		add_action( 'added_option', array( __CLASS__, 'on_option_change' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option_change' ) );
		add_action( 'deleted_option', array( __CLASS__, 'on_option_change' ) );
	}

	/**
	 * Whether a key may not be put on a card (it holds a secret, a session or a role).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_blocked( $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key || in_array( $key, array( 'session_tokens', 'user_level', 'user_pass', 'user_activation_key' ), true ) ) {
			return true;
		}
		if ( preg_match( '/(capabilities|user_level|password|passwd|pass_hash|token|secret|api_?key|private_?key|(^|_)t?otp(_|$)|2fa|two_factor|nonce|salt|activation_key|recovery|(^|_)auth(_|$))/', $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Value of a profile field of a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key or account field.
	 * @return string Empty when there is no such user or value.
	 */
	public static function value( $user_id, $key ) {
		$user = absint( $user_id ) > 0 ? get_userdata( absint( $user_id ) ) : false;
		$key  = trim( (string) $key );
		if ( ! $user || self::is_blocked( $key ) ) {
			return '';
		}
		switch ( $key ) {
			case 'user_id':
				return (string) $user->ID;
			case 'user_registered':
				$time = strtotime( (string) $user->user_registered . ' UTC' );
				return $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '';
			case 'user_email':
			case 'display_name':
			case 'user_login':
			case 'user_url':
				return (string) $user->{$key};
		}
		$value = get_user_meta( $user->ID, $key, true );
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}

	/**
	 * Add the values of the profile fields a mapping uses to the source values.
	 *
	 * @param array<string, mixed>  $mapping       Mapping.
	 * @param array<string, string> $source_values Source values.
	 * @param int                   $user_id       Card holder's user id (0 = none).
	 * @return array<string, string>
	 */
	public static function attach( array $mapping, array $source_values, $user_id ) {
		foreach ( self::keys_of( $mapping ) as $key ) {
			$source_values[ self::VALUE_PREFIX . $key ] = self::value( $user_id, $key );
		}
		return $source_values;
	}

	/**
	 * Profile field keys a mapping uses.
	 *
	 * @param array<string, mixed> $mapping Mapping.
	 * @return array<int, string>
	 */
	public static function keys_of( array $mapping ) {
		$keys = array();
		foreach ( (array) ( $mapping['field_mapping'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && self::MODE === ( $entry['type'] ?? '' ) && '' !== trim( (string) ( $entry['value'] ?? '' ) ) ) {
				$keys[] = trim( (string) $entry['value'] );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Profile field keys used by any design of the enabled integrations.
	 *
	 * Reading every integration's designs takes two queries per integration, so the list is
	 * kept in an autoloaded option. It is rebuilt when a design option changes
	 * (on_option_change()), when the enabled integrations change, and after CACHE_TTL.
	 *
	 * @return array<int, string>
	 */
	public static function mapped_keys() {
		if ( null !== self::$mapped_keys ) {
			return self::$mapped_keys;
		}
		$slugs  = array_map( 'strval', EPC_Module_Settings::get_enabled_slugs() );
		$cached = get_option( self::CACHE_OPTION, null );
		if (
			is_array( $cached )
			&& isset( $cached['slugs'], $cached['keys'], $cached['time'] )
			&& $slugs === $cached['slugs']
			&& is_array( $cached['keys'] )
			&& absint( $cached['time'] ) > time() - self::CACHE_TTL
		) {
			self::$mapped_keys = array_values( array_map( 'strval', $cached['keys'] ) );
			return self::$mapped_keys;
		}

		$keys    = array();
		$sources = array();
		foreach ( $slugs as $slug ) {
			$module = EPC_Module_Loader::get_module_instance( $slug );
			if ( ! $module instanceof EPC_Module ) {
				continue;
			}
			$sources[] = $module->get_mappings_option_key();
			$mappings  = (array) $module->get_mappings();
			if ( class_exists( 'EPC_Default_Design' ) ) {
				$sources[]  = EPC_Default_Design::OPTION_PREFIX . sanitize_key( $slug );
				$mappings[] = (array) EPC_Default_Design::get( $slug );
			}
			foreach ( $mappings as $mapping ) {
				$keys = array_merge( $keys, self::keys_of( (array) $mapping ) );
			}
		}
		self::$mapped_keys = array_values( array_unique( $keys ) );
		update_option(
			self::CACHE_OPTION,
			array(
				'slugs'   => $slugs,
				'keys'    => self::$mapped_keys,
				'sources' => array_values( array_unique( $sources ) ),
				'time'    => time(),
			),
			true
		);
		return self::$mapped_keys;
	}

	/**
	 * Forget the mapped keys (after a design is saved in this request).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$mapped_keys = null;
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * An option changed: when it holds designs of an integration, forget the cached field list.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option_change( $option ) {
		$option = (string) $option;
		if ( self::CACHE_OPTION === $option ) {
			return;
		}
		$is_design = 0 === strpos( $option, 'epc_mappings_' ) || 0 === strpos( $option, 'epc_default_mapping_' );
		if ( ! $is_design ) {
			// A custom integration may keep its designs under another name (get_mappings_option_key()).
			// Read from the autoloaded options only: no query when there is no cache yet.
			$all       = wp_load_alloptions();
			$cached    = isset( $all[ self::CACHE_OPTION ] ) ? maybe_unserialize( $all[ self::CACHE_OPTION ] ) : null;
			$is_design = is_array( $cached ) && in_array( $option, (array) ( $cached['sources'] ?? array() ), true );
		}
		if ( $is_design ) {
			self::reset_cache();
		}
	}

	/**
	 * A mapped profile field changed: update that person's cards (shortly, once).
	 *
	 * @param int|array<int, int> $meta_id  Meta id(s).
	 * @param int                 $user_id  User id.
	 * @param string              $meta_key Meta key.
	 * @return void
	 */
	public static function on_user_meta( $meta_id, $user_id, $meta_key ) {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, self::mapped_keys(), true ) ) {
			EPC_User_Pass_Sync::schedule_sync( (int) $user_id );
		}
	}

	/**
	 * Keys to suggest in the design screen: the account fields, then the user meta keys on
	 * this site (secrets left out).
	 *
	 * @return array<int, string>
	 */
	public static function suggestions() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin screen only: the profile field names in use.
		$keys = (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key ASC LIMIT 500" );
		$out  = self::$account_fields;
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( ! self::is_blocked( $key ) && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
