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
	 * Enqueue shell assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::is_epc_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'epc-fonts',
			'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap',
			array(),
			EPC_VERSION
		);

		wp_enqueue_style(
			'epc-material-symbols',
			'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap',
			array(),
			EPC_VERSION
		);

		$shell_path = EPC_PLUGIN_DIR . 'admin/css/admin-shell.css';
		wp_enqueue_style(
			'epc-admin-shell',
			EPC_PLUGIN_URL . 'admin/css/admin-shell.css',
			array( 'epc-fonts' ),
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
		return 'https://epasscard.com/wp-plugin/docs/index.html';
	}

	/**
	 * Open app shell markup.
	 *
	 * @param array<string, mixed> $args Shell arguments.
	 * @return void
	 */
	public static function render_open( array $args = array() ) {
		$defaults = array(
			'context'       => 'connection',
			'title'         => __( 'EpassCard', 'epasscard' ),
			'subtitle'      => '',
			'module'        => null,
			'active_section'=> '',
		);

		$args = wp_parse_args( $args, $defaults );
		$nav  = self::build_nav_items( $args );

		?>
		<div class="epc-app-root">
		<div class="epc-app" data-epc-context="<?php echo esc_attr( (string) $args['context'] ); ?>">
			<aside class="epc-app__sidebar" aria-label="<?php esc_attr_e( 'EpassCard navigation', 'epasscard' ); ?>">
				<div class="epc-app__brand">
					<img
						class="epc-app__brand-logo"
						src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/epasscard-logo.webp' ); ?>"
						alt="<?php esc_attr_e( 'EpassCard', 'epasscard' ); ?>"
						width="200"
						height="67"
					/>
					<p class="epc-app__brand-version"><?php echo esc_html( sprintf( /* translators: %s: plugin version */ __( 'v%s', 'epasscard' ), EPC_VERSION ) ); ?></p>
				</div>

				<nav class="epc-app__nav epc-app__nav--primary">
					<?php foreach ( $nav['primary'] as $item ) : ?>
						<?php self::render_nav_item( $item, $args['active_section'] ); ?>
					<?php endforeach; ?>
				</nav>

				<?php if ( ! empty( $nav['secondary'] ) ) : ?>
					<nav class="epc-app__nav epc-app__nav--secondary">
						<?php foreach ( $nav['secondary'] as $item ) : ?>
							<?php self::render_nav_item( $item, $args['active_section'] ); ?>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>

				<div class="epc-app__sidebar-footer">
					<?php EPC_Setup_Help::render_sidebar_card(); ?>
					<a class="epc-app__support-btn" href="https://epasscard.com/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Support', 'epasscard' ); ?>
					</a>
				</div>
			</aside>

			<div class="epc-app__main">
				<header class="epc-app__topbar">
					<div class="epc-app__topbar-title">
						<h2><?php echo esc_html( (string) $args['title'] ); ?></h2>
						<?php if ( '' !== (string) $args['subtitle'] ) : ?>
							<p><?php echo esc_html( (string) $args['subtitle'] ); ?></p>
						<?php endif; ?>
					</div>
					<nav class="epc-app__topbar-links" aria-label="<?php esc_attr_e( 'Help links', 'epasscard' ); ?>">
						<a href="<?php echo esc_url( self::docs_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Docs', 'epasscard' ); ?></a>
						<a href="https://epasscard.com/contact-us/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Help Center', 'epasscard' ); ?></a>
						<a href="https://wordpress.org/support/plugin/epasscard/reviews/#new-post" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Feedback', 'epasscard' ); ?></a>
					</nav>
				</header>
				<div class="epc-app__content">
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
		<?php
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
				'label' => __( 'Template Mapping', 'epasscard' ),
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
				'secondary' => self::secondary_nav_items(),
			);
		}

		if ( 'api-log' === $context ) {
			return array(
				'primary'   => array(
					array(
						'id'    => 'api-log',
						'label' => __( 'API Logs', 'epasscard' ),
						'url'   => admin_url( 'admin.php?page=epc-api-log' ),
						'icon'  => 'code',
					),
				),
				'secondary' => self::secondary_nav_items( true ),
			);
		}

		$connection_url = admin_url( 'admin.php?page=epasscard' );
		$primary        = array(
			array(
				'id'    => 'overview',
				'label' => __( 'Overview', 'epasscard' ),
				'url'   => $connection_url . '#epc-section-overview',
				'icon'  => 'dashboard',
			),
			array(
				'id'    => 'connect',
				'label' => __( 'Connection', 'epasscard' ),
				'url'   => $connection_url . '#epc-section-connect',
				'icon'  => 'link',
			),
			array(
				'id'    => 'integrations',
				'label' => __( 'Integrations', 'epasscard' ),
				'url'   => $connection_url . '#epc-section-integrations',
				'icon'  => 'extension',
			),
		);

		if ( EPC_Connection::is_connected() ) {
			$primary[] = array(
				'id'    => 'email',
				'label' => __( 'Pass Email', 'epasscard' ),
				'url'   => $connection_url . '#epc-section-email',
				'icon'  => 'mail',
			);
		}

		$primary[] = array(
			'id'    => 'our-products',
			'label' => __( 'Our Products', 'epasscard' ),
			'url'   => $connection_url . '#epc-section-our-products',
			'icon'  => 'storefront',
		);

		return array(
			'primary'   => $primary,
			'secondary' => self::secondary_nav_items(),
		);
	}

	/**
	 * Footer navigation links.
	 *
	 * @param bool $on_api_log Whether API log is the current page.
	 * @return array<int, array<string, string>>
	 */
	private static function secondary_nav_items( $on_api_log = false ) {
		$items = array();

		$items[] = array(
			'id'    => 'setup-wizard',
			'label' => __( 'Setup Wizard', 'epasscard' ),
			'url'   => admin_url( 'admin.php?page=epc-setup-wizard' ),
			'icon'  => 'rocket_launch',
		);

		if ( ! $on_api_log ) {
			$items[] = array(
				'id'    => 'api-log',
				'label' => __( 'API Logs', 'epasscard' ),
				'url'   => admin_url( 'admin.php?page=epc-api-log' ),
				'icon'  => 'code',
			);
		}

		$items[] = array(
			'id'    => 'settings',
			'label' => __( 'Settings', 'epasscard' ),
			'url'   => admin_url( 'admin.php?page=epasscard' ),
			'icon'  => 'settings',
		);

		return $items;
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
		<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( (string) $item['url'] ); ?>" data-epc-section="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>">
			<span class="epc-icon" aria-hidden="true"><?php echo esc_html( (string) ( $item['icon'] ?? 'circle' ) ); ?></span>
			<span><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
		</a>
		<?php
	}
}
