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
	 * Instantiated module registry (not necessarily initialized).
	 *
	 * @var array<string, EPC_Module>|null
	 */
	private static ?array $registry = null;

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
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		self::$registry = array();

		foreach ( self::get_module_files() as $slug => $file ) {
			$path = apply_filters( 'epc_module_path', EPC_PLUGIN_DIR . 'modules/' . $file, $slug, $file );
			if ( ! is_string( $path ) || ! file_exists( $path ) ) {
				continue;
			}

			require_once $path;

			$class = self::class_for_slug( $slug );
			if ( ! $class || ! class_exists( $class ) ) {
				continue;
			}

			/** @var EPC_Module $module */
			$module                    = new $class();
			self::$registry[ $slug ] = $module;
		}

		return self::$registry;
	}

	/**
	 * Initialize enabled modules only.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function load_modules() {
		$loaded = array();

		foreach ( self::get_registry() as $slug => $module ) {
			if ( ! EPC_Module_Settings::is_enabled( $slug ) ) {
				continue;
			}

			$module->init();
			$loaded[ $slug ] = $module;
		}

		return $loaded;
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
