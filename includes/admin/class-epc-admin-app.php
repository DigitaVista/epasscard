<?php
/**
 * EpassCard admin: the five sections (Home, Cards, Design, Customers, Settings).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, section routing and URLs for the 1.2 admin.
 *
 * Older screens (integration pages, loyalty pages, API log, setup wizard) keep their slugs and
 * render inside the same top bar; this class decides which section each one belongs to.
 */
class EPC_Admin_App {

	/**
	 * Page slugs of the sections.
	 */
	const PAGE_HOME     = 'epasscard';
	const PAGE_CARDS    = 'epc-cards';
	const PAGE_DESIGN   = 'epc-design';
	const PAGE_SETTINGS = 'epc-settings';
	const PAGE_SETUP    = 'epc-setup';
	const PAGE_TRY      = 'epc-try';

	/**
	 * Customer list, the landing page of the Customers section (shown while loyalty is enabled).
	 */
	const PAGE_CUSTOMERS = 'epc-woocommerce-loyalty-customers';

	/**
	 * Loyalty rules and rewards, the second page of the Customers section.
	 */
	const PAGE_LOYALTY_RULES = 'epc-woocommerce-loyalty';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
		// After WordPress checked access to the page (that check needs the submenu entries) and
		// before the menu is printed.
		add_action( 'admin_head', array( __CLASS__, 'tidy_menu' ), 999 );
		add_filter( 'parent_file', array( __CLASS__, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'filter_submenu_file' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 16 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_retired_pages' ) );
	}

	/**
	 * The 1.1 page of each integration is retired: its design, status rules and template
	 * mapping are on Design, its reminders on Settings, its cards on Cards. Old links and
	 * bookmarks land there. The loyalty program page stays (Customers).
	 *
	 * @return void
	 */
	public static function redirect_retired_pages() {
		if ( wp_doing_ajax() || ! is_admin() ) {
			return;
		}
		$page = self::current_page();
		if ( 'epc-woocommerce-loyalty-passes' === $page ) {
			wp_safe_redirect( self::url( 'cards', array( 'epc_module' => 'woocommerce-loyalty' ) ) );
			exit;
		}
		if ( 0 !== strpos( $page, 'epc-' ) ) {
			return;
		}
		$slug = substr( $page, 4 );
		if ( 'woocommerce-loyalty' === $slug || ! array_key_exists( $slug, EPC_Module_Loader::get_module_files() ) ) {
			return;
		}
		wp_safe_redirect( self::url( 'design', array( 'module' => $slug ) ) );
		exit;
	}

	/**
	 * Admin URL of a section.
	 *
	 * @param string               $section home|cards|design|customers|loyalty-rules|settings|setup|try|api-log.
	 * @param array<string, mixed> $args    Extra query args.
	 * @return string
	 */
	public static function url( $section, array $args = array() ) {
		$pages = array(
			'home'          => self::PAGE_HOME,
			'cards'         => self::PAGE_CARDS,
			'design'        => self::PAGE_DESIGN,
			'customers'     => self::PAGE_CUSTOMERS,
			'loyalty-rules' => self::PAGE_LOYALTY_RULES,
			'settings'      => self::PAGE_SETTINGS,
			'setup'         => self::PAGE_SETUP,
			'try'           => self::PAGE_TRY,
			'api-log'       => 'epc-api-log',
		);
		$page = isset( $pages[ $section ] ) ? $pages[ $section ] : self::PAGE_HOME;
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Register the section pages.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'EpassCard', 'epasscard' ),
			__( 'EpassCard', 'epasscard' ),
			'manage_options',
			self::PAGE_HOME,
			array( __CLASS__, 'render_home' ),
			EPC_PLUGIN_URL . 'admin/images/epass-icon.webp',
			58
		);

		add_submenu_page( self::PAGE_HOME, __( 'Home', 'epasscard' ), __( 'Home', 'epasscard' ), 'manage_options', self::PAGE_HOME, array( __CLASS__, 'render_home' ) );
		add_submenu_page( self::PAGE_HOME, __( 'Cards', 'epasscard' ), __( 'Cards', 'epasscard' ), epc_manage_passes_menu_capability(), self::PAGE_CARDS, array( __CLASS__, 'render_cards' ) );
		add_submenu_page( self::PAGE_HOME, __( 'Design', 'epasscard' ), __( 'Design', 'epasscard' ), 'manage_options', self::PAGE_DESIGN, array( __CLASS__, 'render_design' ) );
		add_submenu_page( self::PAGE_HOME, __( 'Settings', 'epasscard' ), __( 'Settings', 'epasscard' ), 'manage_options', self::PAGE_SETTINGS, array( __CLASS__, 'render_settings' ) );

		// Reached from Home, not from the menu.
		self::add_hidden_page( self::PAGE_SETUP, __( 'Set up EpassCard', 'epasscard' ), array( 'EPC_Admin_Setup', 'render' ) );
		self::add_hidden_page( self::PAGE_TRY, __( 'Try your card', 'epasscard' ), array( 'EPC_Admin_Try', 'render' ) );
		self::add_hidden_page( EPC_Help::PAGE, __( 'Tutorials', 'epasscard' ), array( 'EPC_Help', 'render_page' ) );
	}

	/**
	 * Register a page without a menu entry, with a title (pages missing from the menu get none
	 * from WordPress).
	 *
	 * Registered under EpassCard and then taken out of the submenu by tidy_menu(): a page
	 * registered with an empty parent makes WordPress reset the highlighted menu to none.
	 *
	 * @param string   $slug     Page slug.
	 * @param string   $title    Page title.
	 * @param callable $callback Render callback.
	 * @return void
	 */
	private static function add_hidden_page( $slug, $title, $callback ) {
		$hook = add_submenu_page( self::PAGE_HOME, $title, $title, 'manage_options', $slug, $callback );
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () use ( $title ) {
					$GLOBALS['title'] = $title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- hidden page has no title.
				}
			);
		}
	}

	/**
	 * Keep the menu to the five sections. Older pages stay reachable by URL and from the
	 * sections that link to them.
	 *
	 * Runs on admin_head: WordPress only lets a user open a plugin page that is still in the
	 * submenu when it checks access, so entries are removed after that check.
	 *
	 * @return void
	 */
	public static function tidy_menu() {
		global $submenu;
		if ( empty( $submenu[ self::PAGE_HOME ] ) || ! is_array( $submenu[ self::PAGE_HOME ] ) ) {
			return;
		}

		$keep      = array( self::PAGE_HOME, self::PAGE_CARDS, self::PAGE_DESIGN, self::PAGE_SETTINGS );
		$customers = null;
		foreach ( $submenu[ self::PAGE_HOME ] as $index => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( self::PAGE_CUSTOMERS === $slug ) {
				$customers    = $item;
				$customers[0] = __( 'Customers', 'epasscard' );
			}
			if ( ! in_array( $slug, $keep, true ) ) {
				unset( $submenu[ self::PAGE_HOME ][ $index ] );
			}
		}

		// Order: Home, Cards, Design, Customers (loyalty only), Settings.
		$ordered = array();
		foreach ( $submenu[ self::PAGE_HOME ] as $item ) {
			if ( self::PAGE_SETTINGS === (string) $item[2] && null !== $customers ) {
				$ordered[]  = $customers;
				$customers = null;
			}
			$ordered[] = $item;
		}
		if ( null !== $customers ) {
			// Pass staff have no Settings item.
			$ordered[] = $customers;
		}
		$submenu[ self::PAGE_HOME ] = array_values( $ordered ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering our own submenu.
	}

	/**
	 * Section a page belongs to.
	 *
	 * @param string $page Page slug.
	 * @return string home|cards|design|customers|settings|help
	 */
	public static function section_for_page( $page ) {
		$page = sanitize_key( (string) $page );
		switch ( $page ) {
			case self::PAGE_HOME:
			case self::PAGE_SETUP:
			case self::PAGE_TRY:
			case 'epc-setup-wizard':
				return 'home';
			case self::PAGE_CARDS:
			case 'epc-woocommerce-loyalty-passes':
				return 'cards';
			case self::PAGE_SETTINGS:
			case 'epc-api-log':
				return 'settings';
			case self::PAGE_CUSTOMERS:
			case self::PAGE_LOYALTY_RULES:
				return 'customers';
			case EPC_Help::PAGE:
				return 'help';
		}
		return 0 === strpos( $page, 'epc-' ) ? 'design' : 'home';
	}

	/**
	 * Current page slug.
	 *
	 * @return string
	 */
	public static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/**
	 * Keep EpassCard open in the WordPress menu on every EpassCard page.
	 *
	 * @param string $parent_file Parent file.
	 * @return string
	 */
	public static function filter_parent_file( $parent_file ) {
		$page = self::current_page();
		if ( self::PAGE_HOME === $page || 0 === strpos( $page, 'epc-' ) ) {
			return self::PAGE_HOME;
		}
		return $parent_file;
	}

	/**
	 * Highlight the section's submenu item on pages that are not in the menu.
	 *
	 * @param string|null $submenu_file Submenu file.
	 * @param string      $parent_file  Parent file.
	 * @return string|null
	 */
	public static function filter_submenu_file( $submenu_file, $parent_file ) {
		if ( self::PAGE_HOME !== $parent_file ) {
			return $submenu_file;
		}
		$map = array(
			'home'      => self::PAGE_HOME,
			'cards'     => self::PAGE_CARDS,
			'design'    => self::PAGE_DESIGN,
			'customers' => self::PAGE_CUSTOMERS,
			'settings'  => self::PAGE_SETTINGS,
		);
		$section = self::section_for_page( self::current_page() );
		return isset( $map[ $section ] ) ? $map[ $section ] : $submenu_file;
	}

	/**
	 * Whether the loyalty program is turned on (shows the Customers section).
	 *
	 * @return bool
	 */
	public static function loyalty_enabled() {
		return class_exists( 'EPC_Module_Settings' ) && EPC_Module_Settings::is_enabled( 'woocommerce-loyalty' );
	}

	/**
	 * Sections for the top bar.
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function sections() {
		$sections = array(
			'home'   => array(
				'label' => __( 'Home', 'epasscard' ),
				'url'   => self::url( 'home' ),
			),
			'cards'  => array(
				'label' => __( 'Cards', 'epasscard' ),
				'url'   => self::url( 'cards' ),
			),
			'design' => array(
				'label' => __( 'Design', 'epasscard' ),
				'url'   => self::url( 'design' ),
			),
		);
		if ( self::loyalty_enabled() ) {
			$sections['customers'] = array(
				'label' => __( 'Customers', 'epasscard' ),
				'url'   => self::url( 'customers' ),
			);
		}
		$sections['settings'] = array(
			'label' => __( 'Settings', 'epasscard' ),
			'url'   => self::url( 'settings' ),
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			// Pass staff see only what they can open.
			$sections = array_intersect_key( $sections, array_flip( array( 'cards', 'customers' ) ) );
		}

		return $sections;
	}

	/**
	 * Enqueue the bundled fonts and the 1.2 screen styles on EpassCard pages.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! EPC_Admin_Shell::is_epc_screen( $hook ) ) {
			return;
		}

		EPC_Card_Preview::register_style();
		$path = EPC_PLUGIN_DIR . 'admin/css/app.css';
		wp_enqueue_style(
			'epc-app',
			EPC_PLUGIN_URL . 'admin/css/app.css',
			array( 'epc-admin-shell', 'epc-card-preview' ),
			file_exists( $path ) ? (string) filemtime( $path ) : EPC_VERSION
		);

		$deps = array();
		if ( self::PAGE_TRY === self::current_page() ) {
			// qrcode-generator 1.4.4 by Kazuhiko Arase, MIT license.
			wp_enqueue_script( 'epc-qrcode', EPC_PLUGIN_URL . 'admin/js/vendor/qrcode.js', array(), '1.4.4', true );
			$deps[] = 'epc-qrcode';
		}

		$script = EPC_PLUGIN_DIR . 'admin/js/app.js';
		wp_enqueue_script(
			'epc-app',
			EPC_PLUGIN_URL . 'admin/js/app.js',
			$deps,
			file_exists( $script ) ? (string) filemtime( $script ) : EPC_VERSION,
			true
		);
		wp_localize_script(
			'epc-app',
			'epcApp',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'installNonce' => current_user_can( 'install_plugins' ) ? wp_create_nonce( 'epc_wizard' ) : '',
				'helpNonce'    => wp_create_nonce( 'epc_help' ),
				'i18n'         => array(
					'installing' => __( 'Installing and activating…', 'epasscard' ),
					'installed'  => __( 'Plugin ready. Reloading…', 'epasscard' ),
					'error'      => __( 'Something went wrong. Please try again.', 'epasscard' ),
					'copied'     => __( 'Link copied.', 'epasscard' ),
					'copyFailed' => __( 'Could not copy. Use the email button instead.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Home.
	 *
	 * @return void
	 */
	public static function render_home() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}
		EPC_Admin_Home::render();
	}

	/**
	 * Cards.
	 *
	 * @return void
	 */
	public static function render_cards() {
		if ( ! epc_current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}
		EPC_Admin_Cards::render();
	}

	/**
	 * Design.
	 *
	 * @return void
	 */
	public static function render_design() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}
		EPC_Admin_Design::render();
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}
		EPC_Admin_Settings::render();
	}
}
