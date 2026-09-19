<?php
/**
 * Enabled integration module settings.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores which integration modules are enabled in wp-admin.
 */
class EPC_Module_Settings {

	/**
	 * Option key for enabled module slugs.
	 */
	public const OPTION_KEY = 'epc_enabled_modules';

	/**
	 * Whether module instances were initialized.
	 *
	 * @var array<string, bool>
	 */
	private static array $initialized = array();

	/**
	 * Get enabled module slugs (saved + dependency available).
	 *
	 * @return array<int, string>
	 */
	public static function get_enabled_slugs() {
		$saved    = self::get_saved_slugs();
		$registry = EPC_Module_Loader::get_registry();
		$enabled  = array();

		foreach ( $saved as $slug ) {
			if ( isset( $registry[ $slug ] ) && $registry[ $slug ]->is_available() ) {
				$enabled[] = $slug;
			}
		}

		return array_values( array_unique( $enabled ) );
	}

	/**
	 * Get module slugs stored in settings (before availability filter).
	 *
	 * @return array<int, string>
	 */
	public static function get_saved_slugs() {
		$saved = get_option( self::OPTION_KEY, null );

		if ( null === $saved ) {
			$defaults = self::default_enabled_slugs();
			update_option( self::OPTION_KEY, $defaults );
			return $defaults;
		}

		if ( ! is_array( $saved ) ) {
			return array();
		}

		$known = array_keys( EPC_Module_Loader::get_module_files() );
		$out   = array();

		foreach ( $saved as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && in_array( $slug, $known, true ) ) {
				$out[] = $slug;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a module slug is enabled.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		return in_array( sanitize_key( (string) $slug ), self::get_enabled_slugs(), true );
	}

	/**
	 * Default enabled modules on first run (available dependencies only).
	 *
	 * @return array<int, string>
	 */
	public static function default_enabled_slugs() {
		$slugs = array();

		foreach ( EPC_Module_Loader::get_registry() as $slug => $module ) {
			if ( $module->is_available() ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Save enabled modules (only known slugs with active dependencies).
	 *
	 * @param array<int, string> $requested_slugs Requested module slugs.
	 * @return array<int, string> Saved slugs.
	 */
	public static function save_enabled_slugs( array $requested_slugs ) {
		$registry = EPC_Module_Loader::get_registry();
		$enabled  = array();

		foreach ( $requested_slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! isset( $registry[ $slug ] ) ) {
				continue;
			}

			if ( ! $registry[ $slug ]->is_available() ) {
				continue;
			}

			$enabled[] = $slug;
		}

		$enabled = array_values( array_unique( $enabled ) );
		update_option( self::OPTION_KEY, $enabled );

		return $enabled;
	}

	/**
	 * Mark a module instance as initialized.
	 *
	 * @param string $slug Module slug.
	 * @return bool True if this call should proceed with init.
	 */
	public static function mark_initialized( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( isset( self::$initialized[ $slug ] ) ) {
			return false;
		}

		self::$initialized[ $slug ] = true;
		return true;
	}
}
