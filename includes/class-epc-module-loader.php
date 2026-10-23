<?php
/**
 * Loads integration modules when dependencies are active.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module loader — one file per integration.
 */
class EPC_Module_Loader {

	/**
	 * Module instances created so far, by slug (one instance per slug per request).
	 *
	 * @var array<string, EPC_Module>
	 */
	private static $instances = array();

	/**
	 * Slugs whose file or class could not be loaded.
	 *
	 * @var array<string, bool>
	 */
	private static $missing = array();

	/**
	 * Whether every module file has been loaded (get_registry() was called).
	 *
	 * @var bool
	 */
	private static $registry_complete = false;

	/**
	 * Module file definitions.
	 *
	 * @return array<string, string> slug => file path relative to modules dir
	 */
	public static function get_module_files() {
		return apply_filters(
			'epc_module_files',
			array(
				'woocommerce-loyalty'       => 'module-woocommerce-loyalty.php',
				'memberpress'               => 'module-memberpress.php',
				'woocommerce-subscriptions' => 'module-woocommerce-subscriptions.php',
				'ultimate-membership-pro'   => 'module-ultimate-membership-pro.php',
				'paid-memberships-pro'      => 'module-paid-memberships-pro.php',
				'simple-membership'         => 'module-simple-membership.php',
				'the-events-calendar'       => 'module-the-events-calendar.php',
				'events-manager'            => 'module-events-manager.php',
				'pw-gift-cards'             => 'module-pw-gift-cards.php',
				'yith-gift-cards'           => 'module-yith-gift-cards.php',
			)
		);
	}

	/**
	 * Get all module instances without registering hooks.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function get_registry() {
		$registry = array();

		foreach ( array_keys( self::get_module_files() ) as $slug ) {
			$module = self::get_module_instance( $slug );
			if ( $module ) {
				$registry[ $slug ] = $module;
			}
		}

		self::$registry_complete = true;

		return $registry;
	}

	/**
	 * One module instance, loading only that module's file.
	 *
	 * Everyday requests use this so integrations that are not enabled are never loaded
	 * (about 1.2 MB less memory per request on hosts without an opcode cache). Screens that
	 * list every integration call get_registry().
	 *
	 * @param string $slug Module slug.
	 * @return EPC_Module|null
	 */
	public static function get_module_instance( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || isset( self::$missing[ $slug ] ) ) {
			return null;
		}
		if ( isset( self::$instances[ $slug ] ) ) {
			return self::$instances[ $slug ];
		}

		$files = self::get_module_files();
		if ( ! isset( $files[ $slug ] ) ) {
			self::$missing[ $slug ] = true;
			return null;
		}

		$file = $files[ $slug ];
		$path = apply_filters( 'epc_module_path', EPC_PLUGIN_DIR . 'modules/' . $file, $slug, $file );
		if ( ! is_string( $path ) || ! file_exists( $path ) ) {
			self::$missing[ $slug ] = true;
			return null;
		}

		require_once $path;

		$class = self::class_for_slug( $slug );
		if ( ! $class || ! class_exists( $class ) ) {
			self::$missing[ $slug ] = true;
			return null;
		}

		/** @var EPC_Module $module */
		$module                   = new $class();
		self::$instances[ $slug ] = $module;

		return $module;
	}

	/**
	 * Enabled modules whose host plugin is active, loading only their files.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function get_enabled_modules() {
		$enabled      = array();
		$enabled_slugs = EPC_Module_Settings::get_enabled_slugs();

		// Keep the module-file order (it sets the order of EpassCard's admin submenu items).
		foreach ( array_keys( self::get_module_files() ) as $slug ) {
			if ( ! in_array( $slug, $enabled_slugs, true ) ) {
				continue;
			}
			$module = self::get_module_instance( $slug );
			if ( $module ) {
				$enabled[ $slug ] = $module;
			}
		}

		return $enabled;
	}

	/**
	 * Initialize enabled modules only.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function load_modules() {
		$loaded = array();

		foreach ( self::get_enabled_modules() as $slug => $module ) {
			$module->init();
			$loaded[ $slug ] = $module;
		}

		return $loaded;
	}

	/**
	 * Whether every module file has been loaded in this request (for tests and diagnostics).
	 *
	 * @return bool
	 */
	public static function is_registry_loaded() {
		return self::$registry_complete;
	}

	/**
	 * Map slug to class name.
	 *
	 * @param string $slug Module slug.
	 * @return string|null
	 */
	private static function class_for_slug( $slug ) {
		$map = apply_filters(
			'epc_module_classes',
			array(
				'woocommerce-loyalty'       => 'EPC_Module_WooCommerce_Loyalty',
				'memberpress'               => 'EPC_Module_MemberPress',
				'woocommerce-subscriptions' => 'EPC_Module_WooCommerce_Subscriptions',
				'ultimate-membership-pro'   => 'EPC_Module_Ultimate_Membership_Pro',
				'paid-memberships-pro'      => 'EPC_Module_Paid_Memberships_Pro',
				'simple-membership'         => 'EPC_Module_Simple_Membership',
				'the-events-calendar'       => 'EPC_Module_The_Events_Calendar',
				'events-manager'            => 'EPC_Module_Events_Manager',
				'pw-gift-cards'             => 'EPC_Module_PW_Gift_Cards',
				'yith-gift-cards'           => 'EPC_Module_YITH_Gift_Cards',
			)
		);

		$class = $map[ $slug ] ?? null;

		return is_string( $class ) && class_exists( $class ) ? $class : null;
	}
}
