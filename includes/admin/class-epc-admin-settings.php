<?php
/**
 * Settings section: Account & plan, Emails to customers, Reminders & push, Integrations, Advanced.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen. The forms are the ones the Connection page had; they post to the same handlers.
 */
class EPC_Admin_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
	}

	/**
	 * Integrations with reminders or push messages, keyed by slug (loyalty has its own on Customers).
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function push_modules() {
		$out = array();
		foreach ( EPC_Admin_Design::modules() as $slug => $module ) {
			if ( 'woocommerce-loyalty' !== $slug && ( $module->uses_native_reminder_timing() || ! empty( $module->get_notification_types() ) ) ) {
				$out[ $slug ] = $module;
			}
		}
		return $out;
	}

	/**
	 * Integration shown on Reminders & push.
	 *
	 * @return EPC_Module|null
	 */
	public static function current_push_module() {
		$modules = self::push_modules();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$slug = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : '';
		if ( isset( $modules[ $slug ] ) ) {
			return $modules[ $slug ];
		}
		$first = reset( $modules );
		return $first instanceof EPC_Module ? $first : null;
	}

	/**
	 * Scripts for the push messages form and the test push button.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( EPC_Admin_App::PAGE_SETTINGS !== EPC_Admin_App::current_page() || 'push' !== self::current_tab() ) {
			return;
		}
		$module = self::current_push_module();
		if ( null !== $module ) {
			$module->enqueue_designer_assets( false );
		}
	}

	/**
	 * Tabs: id => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs() {
		return array(
			'account'      => __( 'Account & plan', 'epasscard' ),
			'emails'       => __( 'Emails to customers', 'epasscard' ),
			'push'         => __( 'Reminders & push', 'epasscard' ),
			'integrations' => __( 'Integrations', 'epasscard' ),
			'advanced'     => __( 'Advanced', 'epasscard' ),
		);
	}

	/**
	 * Sub-navigation items (also used by the API log page).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function subnav_items() {
		$items = array();
		foreach ( self::tabs() as $id => $label ) {
			$items[] = array(
				'id'    => $id,
				'label' => $label,
				'url'   => EPC_Admin_App::url( 'settings', array( 'tab' => $id ) ),
			);
		}
		$items[] = array(
			'id'    => 'api-log',
			'label' => __( 'API log', 'epasscard' ),
			'url'   => EPC_Admin_App::url( 'api-log' ),
		);
		return $items;
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'account';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'account';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		$tab   = self::current_tab();
		$tabs  = self::tabs();
		$title = $tabs[ $tab ];

		EPC_Admin_Shell::render_open(
			array(
				'context'        => 'app',
				'section'        => 'settings',
				'title'          => __( 'Settings', 'epasscard' ),
				'subnav'         => self::subnav_items(),
				'active_section' => $tab,
			)
		);
		?>
		<div class="epc-page-head">
			<h2 class="epc-page-head__title"><?php echo esc_html( $title ); ?></h2>
		</div>
		<div class="<?php echo esc_attr( 'epc-settings epc-settings--' . $tab ); ?>">
		<?php
		$connected = EPC_Connection::is_connected();
		switch ( $tab ) {
			case 'emails':
				$email_settings = EPC_Pass_Email::get_settings();
				include EPC_PLUGIN_DIR . 'admin/views/settings/emails.php';
				break;
			case 'push':
				include EPC_PLUGIN_DIR . 'admin/views/settings/push.php';
				break;
			case 'integrations':
				$modules = EPC_Module_Loader::get_registry();
				$enabled = EPC_Module_Settings::get_enabled_slugs();
				include EPC_PLUGIN_DIR . 'admin/views/settings/integrations.php';
				break;
			case 'advanced':
				include EPC_PLUGIN_DIR . 'admin/views/settings/advanced.php';
				break;
			default:
				$settings = EPC_Connection::get_settings();
				$email    = isset( $settings['connected_email'] ) ? (string) $settings['connected_email'] : '';
				$package  = EPC_Connection::get_package_details();
				$expiry   = EPC_Connection::get_expiry_display();
				$meter    = EPC_Plan::get_meter();
				include EPC_PLUGIN_DIR . 'admin/views/settings/account.php';
		}
		echo '</div>';
		EPC_Admin_Shell::render_close();
	}
}
