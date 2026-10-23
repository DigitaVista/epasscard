<?php
/**
 * Premium SaaS admin shell (sidebar + top bar).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared layout wrapper for all EpassCard admin screens.
 */
class EPC_Admin_Shell {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 5 );
	}

	/**
	 * Append body class on plugin admin screens.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		if ( ! self::is_epc_screen() ) {
			return $classes;
		}

		return $classes . ' epc-admin-ui';
	}

	/**
	 * Whether the current admin screen belongs to EpassCard.
	 *
	 * @param string|null $hook Optional hook suffix.
	 * @return bool
	 */
	public static function is_epc_screen( $hook = null ) {
		if ( null === $hook ) {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();
			if ( ! $screen || empty( $screen->id ) ) {
				return false;
			}

			$hook = (string) $screen->id;
		}

		$hook = (string) $hook;

		return false !== strpos( $hook, 'epasscard' ) || false !== strpos( $hook, 'epc-' );
	}

	/**
	 * Whether the current screen is an integration's own page (epc-{module slug}).
	 *
	 * @return bool
	 */
	public static function is_module_page() {
		$page = EPC_Admin_App::current_page();
		foreach ( array_keys( EPC_Module_Loader::get_module_files() ) as $slug ) {
			if ( 'epc-' . $slug === $page ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue shell assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::is_epc_screen( $hook ) ) {
			return;
		}

		// Icon font for the integration pages only (the 1.2 screens use no icons). Disclosed in readme.txt.
		if ( self::is_module_page() ) {
			wp_enqueue_style(
				'epc-material-symbols',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap',
				array(),
				EPC_VERSION
			);
		}

		$shell_path = EPC_PLUGIN_DIR . 'admin/css/admin-shell.css';
		wp_enqueue_style(
			'epc-admin-shell',
			EPC_PLUGIN_URL . 'admin/css/admin-shell.css',
			array(),
			file_exists( $shell_path ) ? (string) filemtime( $shell_path ) : EPC_VERSION
		);

		$shell_js = EPC_PLUGIN_DIR . 'admin/js/admin-shell.js';
		wp_enqueue_script(
			'epc-admin-shell',
			EPC_PLUGIN_URL . 'admin/js/admin-shell.js',
			array(),
			file_exists( $shell_js ) ? (string) filemtime( $shell_js ) : EPC_VERSION,
			true
		);

		$forms_js = EPC_PLUGIN_DIR . 'admin/js/admin-forms.js';
		wp_enqueue_script(
			'epc-admin-forms',
			EPC_PLUGIN_URL . 'admin/js/admin-forms.js',
			array( 'jquery' ),
			file_exists( $forms_js ) ? (string) filemtime( $forms_js ) : EPC_VERSION,
			true
		);

		wp_localize_script(
			'epc-admin-forms',
			'epcForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epc_admin' ),
				'i18n'    => array(
					'saving' => __( 'Saving…', 'epasscard' ),
					'saved'  => __( 'Settings saved.', 'epasscard' ),
					'error'  => __( 'Something went wrong. Please try again.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Docs base URL.
	 *
	 * @return string
	 */
	public static function docs_url() {
		return 'https://www.epasscard.com/documentation/';
	}

	/**
	 * Open app shell markup: the EpassCard top bar (sections, plan meter, help), then an
	 * optional sub-navigation column and the page content.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string     $context        connection|module|api-log|setup-wizard|app.
	 *     @type string     $title          Page title (screen-reader heading and sub-navigation label).
	 *     @type string     $subtitle       Kept for older callers; not shown.
	 *     @type EPC_Module $module         Module, for integration pages.
	 *     @type string     $active_section Active sub-navigation item.
	 *     @type string     $section        Top-bar section to highlight (defaults from the page).
	 *     @type array      $subnav         Sub-navigation items (id, label, url). Older contexts build their own.
	 * }
	 * @return void
	 */
	public static function render_open( array $args = array() ) {
		$defaults = array(
			'context'        => 'connection',
			'title'          => __( 'EpassCard', 'epasscard' ),
			'subtitle'       => '',
			'module'         => null,
			'active_section' => '',
			'section'        => '',
			'subnav'         => null,
		);

		$args    = wp_parse_args( $args, $defaults );
		$section = '' !== (string) $args['section'] ? (string) $args['section'] : EPC_Admin_App::section_for_page( EPC_Admin_App::current_page() );
		$subnav  = is_array( $args['subnav'] ) ? $args['subnav'] : self::legacy_subnav( $args );
		$meter   = class_exists( 'EPC_Plan' ) && current_user_can( 'manage_options' ) ? EPC_Plan::get_meter() : array();

		?>
		<div class="wrap epc-wrap">
			<h1 class="screen-reader-text"><?php echo esc_html( (string) $args['title'] ); ?></h1>
			<hr class="wp-header-end" />
		<div class="epc-app-root">
		<div class="epc-app" data-epc-context="<?php echo esc_attr( (string) $args['context'] ); ?>">
			<div class="epc-topbar">
				<a class="epc-topbar__logo" href="<?php echo esc_url( EPC_Admin_App::url( 'home' ) ); ?>">
					<img src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/epasscard-logo.webp' ); ?>" alt="<?php esc_attr_e( 'EpassCard home', 'epasscard' ); ?>" width="96" height="32" />
				</a>
				<nav class="epc-topbar__nav" aria-label="<?php esc_attr_e( 'EpassCard sections', 'epasscard' ); ?>">
					<?php foreach ( EPC_Admin_App::sections() as $id => $item ) : ?>
						<a href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $id === $section ? ' aria-current="page" class="is-active"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="epc-topbar__right">
					<?php if ( ! empty( $meter ) ) : ?>
						<?php self::render_meter( $meter ); ?>
					<?php endif; ?>
					<a class="epc-topbar__link" href="<?php echo esc_url( self::docs_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Help', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
				</div>
			</div>
			<div class="epc-app__body<?php echo empty( $subnav ) ? '' : ' has-subnav'; ?>">
				<?php if ( ! empty( $subnav ) ) : ?>
					<nav class="epc-subnav" aria-label="<?php echo esc_attr( (string) $args['title'] ); ?>">
						<?php foreach ( $subnav as $item ) : ?>
							<?php self::render_nav_item( $item, (string) $args['active_section'] ); ?>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
				<div class="epc-app__content">
		<?php
	}

	/**
	 * The plan pill in the top bar.
	 *
	 * @param array<string, mixed> $meter EPC_Plan::get_meter().
	 * @return void
	 */
	private static function render_meter( array $meter ) {
		$limit = (int) $meter['limit'];
		$used  = (int) $meter['used'];
		$class = 'epc-meter' . ( ! empty( $meter['warn'] ) ? ' is-warning' : '' );
		$title = __( 'Active cards issued from this site, not counting test cards. Cards made on other sites or in the EpassCard app are not included.', 'epasscard' );
		?>
		<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( EPC_Admin_App::url( 'settings', array( 'tab' => 'account' ) ) ); ?>" title="<?php echo esc_attr( $title ); ?>">
			<?php if ( '' !== (string) $meter['plan'] ) : ?>
				<span class="epc-meter__plan"><?php echo esc_html( (string) $meter['plan'] ); ?></span>
			<?php endif; ?>
			<span class="epc-meter__cards">
				<?php
				if ( $limit > 0 ) {
					/* translators: 1: cards used, 2: card limit of the plan */
					echo esc_html( sprintf( __( 'Cards %1$s / %2$s', 'epasscard' ), number_format_i18n( $used ), number_format_i18n( $limit ) ) );
				} else {
					/* translators: %s: cards used */
					echo esc_html( sprintf( __( 'Cards %s', 'epasscard' ), number_format_i18n( $used ) ) );
				}
				?>
			</span>
		</a>
		<?php
	}

	/**
	 * Close app shell markup.
	 *
	 * @return void
	 */
	public static function render_close() {
		?>
				</div>
			</div>
		</div>
		</div>
		</div>
		<?php
	}

	/**
	 * Sub-navigation for the older screens (integration pages, API log).
	 *
	 * @param array<string, mixed> $args Shell args.
	 * @return array<int, array<string, string>>
	 */
	private static function legacy_subnav( array $args ) {
		$context = (string) $args['context'];
		if ( 'module' === $context && $args['module'] instanceof EPC_Module ) {
			$nav = self::build_nav_items( $args );
			return $nav['primary'];
		}
		if ( 'api-log' === $context ) {
			return EPC_Admin_Settings::subnav_items();
		}
		return array();
	}

	/**
	 * Build navigation items for the current screen.
	 *
	 * @param array<string, mixed> $args Shell args.
	 * @return array{primary: array<int, array<string, string>>, secondary: array<int, array<string, string>>}
	 */
	private static function build_nav_items( array $args ) {
		$context = (string) $args['context'];

		if ( 'module' === $context && $args['module'] instanceof EPC_Module ) {
			/** @var EPC_Module $module */
			$module   = $args['module'];
			$page_url = admin_url( 'admin.php?page=epc-' . $module->get_slug() );
			$primary  = array(
				array(
					'id'    => 'overview',
					'label' => __( 'Overview', 'epasscard' ),
					'url'   => $page_url . '#epc-section-overview',
					'icon'  => 'dashboard',
				),
			);

			if ( $module->has_pass_behavior_settings() ) {
				$primary[] = array(
					'id'    => 'pass-behavior',
					'label' => __( 'Pass Behavior', 'epasscard' ),
					'url'   => $page_url . '#epc-section-pass-behavior',
					'icon'  => 'settings_accessibility',
				);
			}

			if ( $module->uses_native_reminder_timing() ) {
				$primary[] = array(
					'id'    => 'reminders',
					'label' => __( 'Reminders', 'epasscard' ),
					'url'   => $page_url . '#epc-section-reminders',
					'icon'  => 'notifications_active',
				);
			}

			if ( ! empty( $module->get_notification_types() ) ) {
				$primary[] = array(
					'id'    => 'push',
					'label' => __( 'Push Notifications', 'epasscard' ),
					'url'   => $page_url . '#epc-section-push',
					'icon'  => 'send_to_mobile',
				);
			}

			foreach ( $module->get_extra_admin_nav_items() as $extra ) {
				if ( empty( $extra['id'] ) || empty( $extra['label'] ) ) {
					continue;
				}
				$section = isset( $extra['section'] ) ? sanitize_html_class( (string) $extra['section'] ) : sanitize_html_class( (string) $extra['id'] );
				$primary[] = array(
					'id'    => sanitize_key( (string) $extra['id'] ),
					'label' => (string) $extra['label'],
					'url'   => ! empty( $extra['url'] )
						? (string) $extra['url']
						: $page_url . '#epc-section-' . $section,
					'icon'  => isset( $extra['icon'] ) ? (string) $extra['icon'] : 'tune',
				);
			}

			$primary[] = array(
				'id'    => 'mapping',
				'label' => __( 'Pass Template mapping', 'epasscard' ),
				'url'   => $page_url . '#epc-section-mapping',
				'icon'  => 'map',
			);
			$primary[] = array(
				'id'    => 'passes',
				'label' => __( 'Issued Passes', 'epasscard' ),
				'url'   => $module->get_issued_passes_admin_url(),
				'icon'  => 'confirmation_number',
			);

			return array(
				'primary'   => $primary,
				'secondary' => array(),
			);
		}

		return array(
			'primary'   => array(),
			'secondary' => array(),
		);
	}

	/**
	 * Render one nav link.
	 *
	 * @param array<string, string> $item           Nav item.
	 * @param string                $active_section Active section id.
	 * @return void
	 */
	private static function render_nav_item( array $item, $active_section ) {
		$is_active = '' !== $active_section && $active_section === ( $item['id'] ?? '' );
		$classes   = 'epc-app__nav-link' . ( $is_active ? ' is-active' : '' );
		?>
		<a class="<?php echo esc_attr( $classes ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( (string) $item['url'] ); ?>" data-epc-section="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>">
			<span><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
		</a>
		<?php
	}
}
