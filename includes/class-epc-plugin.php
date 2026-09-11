<?php
/**
 * Main plugin orchestrator.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EPC_PLUGIN_DIR . 'includes/class-epc-activator.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-encryption.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-connection.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-api-client.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-api-log.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-db.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-account-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-ledger-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-rule-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-reward-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-notification-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-order-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-redemption-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-pass-design-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-customer-service.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-customers-list-table.php';
require_once EPC_PLUGIN_DIR . 'includes/loyalty/class-epc-loyalty-frontend.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-module-settings.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-module-loader.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-admin-menu.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-admin-shell.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-welcome.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-setup-help.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-setup-help-notice.php';
require_once EPC_PLUGIN_DIR . 'includes/abstract-class-epc-module.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-pass-service.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-pass-email.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-frontend.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-pass-notifications.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-user-pass-sync.php';
require_once EPC_PLUGIN_DIR . 'includes/epc-dependencies.php';

/**
 * Singleton plugin class.
 */
final class EPC_Plugin {

	/**
	 * Instance.
	 *
	 * @var EPC_Plugin|null
	 */
	private static ?EPC_Plugin $instance = null;

	/**
	 * Loaded modules.
	 *
	 * @var array<string, EPC_Module>
	 */
	private array $modules = array();

	/**
	 * All registered module instances (enabled or not).
	 *
	 * @var array<string, EPC_Module>
	 */
	private array $all_modules = array();

	/**
	 * Get singleton.
	 *
	 * @return EPC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Boot admin and modules.
	 *
	 * @return void
	 */
	public function boot() {
		EPC_DB::maybe_upgrade();
		EPC_Api_Log::init();
		EPC_Api_Log::schedule_cron();
		EPC_Pass_Email::init();
		EPC_Frontend::init();
		EPC_Loyalty_Frontend::init();
		EPC_Loyalty_Customer_Service::init_privacy();
		EPC_Connection::init();
		EPC_Connection::schedule_cron();
		EPC_User_Pass_Sync::init();
		EPC_Pass_Notifications::init();
		EPC_Pass_Notifications::schedule_cron();

		EPC_Admin_Menu::init();
		EPC_Admin_Shell::init();
		EPC_Welcome::init();
		EPC_Setup_Help_Notice::init();

		$this->all_modules = EPC_Module_Loader::get_registry();
		$this->modules     = EPC_Module_Loader::load_modules();
	}

	/**
	 * Get loaded module by slug.
	 *
	 * @param string $slug Module slug.
	 * @return EPC_Module|null
	 */
	public function get_module( $slug ) {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * All loaded modules.
	 *
	 * @return array<string, EPC_Module>
	 */
	public function get_modules() {
		return $this->modules;
	}

	/**
	 * All registered modules regardless of enabled state.
	 *
	 * @return array<string, EPC_Module>
	 */
	public function get_all_modules() {
		return $this->all_modules;
	}
}
