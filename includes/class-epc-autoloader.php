<?php
/**
 * Class autoloader: EpassCard's classes are loaded the first time they are used.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads EpassCard classes on first use instead of all at once, so a request only reads the
 * code it runs (a front-end page does not load the admin screens). Integration modules
 * (modules/module-*.php) are still loaded by EPC_Module_Loader, only when enabled.
 */
final class EPC_Autoloader {

	/**
	 * Class => file, relative to the plugin folder.
	 *
	 * @var array<string, string>
	 */
	const CLASSES = array(
		'EPC_Activator'                    => 'includes/class-epc-activator.php',
		'EPC_Admin_App'                    => 'includes/admin/class-epc-admin-app.php',
		'EPC_Admin_Cards'                  => 'includes/admin/class-epc-admin-cards.php',
		'EPC_Admin_Design'                 => 'includes/admin/class-epc-admin-design.php',
		'EPC_Admin_Home'                   => 'includes/admin/class-epc-admin-home.php',
		'EPC_Admin_Menu'                   => 'includes/class-epc-admin-menu.php',
		'EPC_Admin_Settings'               => 'includes/admin/class-epc-admin-settings.php',
		'EPC_Admin_Setup'                  => 'includes/admin/class-epc-admin-setup.php',
		'EPC_Admin_Shell'                  => 'includes/class-epc-admin-shell.php',
		'EPC_Admin_Try'                    => 'includes/admin/class-epc-admin-try.php',
		'EPC_Api_Client'                   => 'includes/class-epc-api-client.php',
		'EPC_Api_Log'                      => 'includes/class-epc-api-log.php',
		'EPC_Appsero'                      => 'includes/class-epc-appsero.php',
		'EPC_Card_Preview'                 => 'includes/admin/class-epc-card-preview.php',
		'EPC_Cards_List_Table'             => 'includes/admin/class-epc-cards-list-table.php',
		'EPC_Connection'                   => 'includes/class-epc-connection.php',
		'EPC_DB'                           => 'includes/class-epc-db.php',
		'EPC_Default_Design'               => 'includes/class-epc-default-design.php',
		'EPC_Developer_Api'                => 'includes/class-epc-developer-api.php',
		'EPC_Encryption'                   => 'includes/class-epc-encryption.php',
		'EPC_Existing_Customers'           => 'includes/class-epc-existing-customers.php',
		'EPC_Frontend'                     => 'includes/class-epc-frontend.php',
		'EPC_Help'                         => 'includes/admin/class-epc-help.php',
		'EPC_Jobs'                         => 'includes/class-epc-jobs.php',
		'EPC_Loyalty_Account_Service'      => 'includes/loyalty/class-epc-loyalty-account-service.php',
		'EPC_Loyalty_Blocks_Integration'   => 'includes/loyalty/class-epc-loyalty-blocks-integration.php',
		'EPC_Loyalty_Customer_Service'     => 'includes/loyalty/class-epc-loyalty-customer-service.php',
		'EPC_Loyalty_Customers_List_Table' => 'includes/loyalty/class-epc-loyalty-customers-list-table.php',
		'EPC_Loyalty_Frontend'             => 'includes/loyalty/class-epc-loyalty-frontend.php',
		'EPC_Loyalty_Ledger_Service'       => 'includes/loyalty/class-epc-loyalty-ledger-service.php',
		'EPC_Loyalty_Notification_Service' => 'includes/loyalty/class-epc-loyalty-notification-service.php',
		'EPC_Loyalty_Order_Service'        => 'includes/loyalty/class-epc-loyalty-order-service.php',
		'EPC_Loyalty_Order_Sync_Service'   => 'includes/loyalty/class-epc-loyalty-order-sync-service.php',
		'EPC_Loyalty_Pass_Design_Service'  => 'includes/loyalty/class-epc-loyalty-pass-design-service.php',
		'EPC_Loyalty_Redemption_Service'   => 'includes/loyalty/class-epc-loyalty-redemption-service.php',
		'EPC_Loyalty_Reward_Service'       => 'includes/loyalty/class-epc-loyalty-reward-service.php',
		'EPC_Loyalty_Rule_Service'         => 'includes/loyalty/class-epc-loyalty-rule-service.php',
		'EPC_Loyalty_Starter'              => 'includes/loyalty/class-epc-loyalty-starter.php',
		'EPC_Module'                       => 'includes/abstract-class-epc-module.php',
		'EPC_Module_Amelia_Bookings'       => 'modules/abstract-module-amelia.php',
		'EPC_Module_List_Table'            => 'includes/admin/class-epc-module-list-table.php',
		'EPC_Module_Loader'                => 'includes/class-epc-module-loader.php',
		'EPC_Module_Settings'              => 'includes/class-epc-module-settings.php',
		'EPC_Module_WC_Product_Cards'      => 'modules/abstract-module-woocommerce-product-cards.php',
		'EPC_Onboarding'                   => 'includes/admin/class-epc-onboarding.php',
		'EPC_Pass_Email'                   => 'includes/class-epc-pass-email.php',
		'EPC_Pass_Issues'                  => 'includes/class-epc-pass-issues.php',
		'EPC_Pass_Notifications'           => 'includes/class-epc-pass-notifications.php',
		'EPC_Pass_Service'                 => 'includes/class-epc-pass-service.php',
		'EPC_Pass_Status'                  => 'includes/class-epc-pass-status.php',
		'EPC_Plan'                         => 'includes/admin/class-epc-plan.php',
		'EPC_Retry_Failed'                 => 'includes/class-epc-retry-failed.php',
		'EPC_Review_Prompt'                => 'includes/admin/class-epc-review-prompt.php',
		'EPC_Setup_Help'                   => 'includes/class-epc-setup-help.php',
		'EPC_Setup_Help_Notice'            => 'includes/class-epc-setup-help-notice.php',
		'EPC_Setup_Wizard'                 => 'includes/class-epc-setup-wizard.php',
		'EPC_Support_Chat'                 => 'includes/admin/class-epc-support-chat.php',
		'EPC_Template_Starter'             => 'includes/class-epc-template-starter.php',
		'EPC_Test_Cards'                   => 'includes/admin/class-epc-test-cards.php',
		'EPC_User_Fields'                  => 'includes/class-epc-user-fields.php',
		'EPC_User_Pass_Sync'               => 'includes/class-epc-user-pass-sync.php',
		'EPC_WC_Product_Cards'             => 'includes/class-epc-wc-product-cards.php',
	);

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load one class.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( isset( self::CLASSES[ $class_name ] ) ) {
			require_once EPC_PLUGIN_DIR . self::CLASSES[ $class_name ];
		}
	}
}
