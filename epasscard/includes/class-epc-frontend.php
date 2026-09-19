<?php
/**
 * Frontend pass access (My Account, shortcodes).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member-facing wallet pass UI.
 */
class EPC_Frontend {

	public const WC_ENDPOINT = 'wallet-passes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'epc_my_passes', array( __CLASS__, 'shortcode_my_passes' ) );

		add_action( 'init', array( __CLASS__, 'register_wc_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'wc_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::WC_ENDPOINT . '_endpoint', array( __CLASS__, 'render_wc_account_passes' ) );

		add_action( 'mepr_account_nav', array( __CLASS__, 'mepr_account_nav_link' ) );
		add_action( 'mepr_account_nav_content', array( __CLASS__, 'mepr_account_content' ), 10, 2 );
	}

	/**
	 * Register WooCommerce account endpoint.
	 *
	 * @return void
	 */
	public static function register_wc_endpoint() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_rewrite_endpoint( self::WC_ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Enqueue frontend styles for pass lists.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$should_load = false;

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$should_load = true;
		}

		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'epc_my_passes' ) ) {
				$should_load = true;
			}
		}

		/**
		 * Filter whether frontend pass list styles should load.
		 *
		 * @param bool $should_load Load flag.
		 */
		if ( ! apply_filters( 'epc_enqueue_frontend_assets', $should_load ) ) {
			return;
		}

		wp_enqueue_style(
			'epc-frontend',
			EPC_PLUGIN_URL . 'assets/frontend/wallet-passes.css',
			array(),
			EPC_VERSION
		);
	}

	/**
	 * Add WooCommerce My Account menu item.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function wc_account_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::WC_ENDPOINT ] = __( 'Wallet passes', 'epasscard' );
			}
		}

		if ( ! isset( $new[ self::WC_ENDPOINT ] ) ) {
			$new[ self::WC_ENDPOINT ] = __( 'Wallet passes', 'epasscard' );
		}

		return $new;
	}

	/**
	 * Render WooCommerce account endpoint content.
	 *
	 * @return void
	 */
	public static function render_wc_account_passes() {
		self::render_passes_list( get_current_user_id() );
	}

	/**
	 * MemberPress account nav link.
	 *
	 * @param MeprUser $user MemberPress user.
	 * @return void
	 */
	public static function mepr_account_nav_link( $user ) {
		if ( ! class_exists( 'MeprUser' ) || ! class_exists( 'MeprOptions' ) || ! $user instanceof MeprUser ) {
			return;
		}

		$mepr_options = MeprOptions::fetch();
		$account_url  = $mepr_options->account_page_url();
		$delim        = MeprAppCtrl::get_param_delimiter_char( $account_url );
		$url          = $account_url . $delim . 'action=wallet_passes';
		?>
		<span class="mepr-nav-item">
			<a href="<?php echo esc_url( $url ); ?>" id="mepr-account-wallet-passes"><?php esc_html_e( 'Wallet Passes', 'epasscard' ); ?></a>
		</span>
		<?php
	}

	/**
	 * MemberPress account tab content.
	 *
	 * @param string $action Current action.
	 * @param array  $atts   Shortcode atts.
	 * @return void
	 */
	public static function mepr_account_content( $action, $atts ) {
		unset( $atts );
		if ( 'wallet_passes' !== $action || ! class_exists( 'MeprUtils' ) ) {
			return;
		}

		$user = MeprUtils::get_currentuserinfo();
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}

		echo '<div class="mepr-wallet-passes">';
		self::render_passes_list( (int) $user->ID );
		echo '</div>';
	}

	/**
	 * Shortcode [epc_my_passes].
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_my_passes( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="epc-my-passes epc-my-passes--guest">' . esc_html__( 'Please log in to view your wallet passes.', 'epasscard' ) . '</p>';
		}

		ob_start();
		echo '<div class="epc-my-passes">';
		self::render_passes_list( get_current_user_id() );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Output HTML list of passes for a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function render_passes_list( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			echo '<p>' . esc_html__( 'No wallet passes found.', 'epasscard' ) . '</p>';
			return;
		}

		$passes = EPC_DB::get_active_passes_for_user( $user_id );
		$passes = array_values(
			array_filter(
				$passes,
				static function ( $pass ) {
					if ( ! is_object( $pass ) || empty( $pass->pass_link ) ) {
						return false;
					}

					$slug = isset( $pass->module ) ? sanitize_key( (string) $pass->module ) : '';
					return '' !== $slug && class_exists( 'EPC_Module_Settings' ) && EPC_Module_Settings::is_enabled( $slug );
				}
			)
		);

		/**
		 * Filter passes shown on the frontend for a user.
		 *
		 * @param array<int, object> $passes  Pass rows.
		 * @param int                $user_id User id.
		 */
		$passes = self::unique_passes( (array) apply_filters( 'epc_frontend_user_passes', $passes, $user_id ) );

		$pass_items = array();
		foreach ( $passes as $pass ) {
			$item = self::get_pass_item( $pass );
			if ( ! empty( $item['url'] ) ) {
				$pass_items[] = $item;
			}
		}

		include EPC_PLUGIN_DIR . 'assets/frontend/wallet-passes.php';
	}

	/**
	 * Drop duplicate pass rows that share a pass UID or link.
	 *
	 * @param array<int, mixed> $passes Pass rows.
	 * @return array<int, object>
	 */
	private static function unique_passes( array $passes ) {
		$seen = array();
		$out  = array();

		foreach ( $passes as $pass ) {
			if ( ! is_object( $pass ) ) {
				continue;
			}

			$uid  = isset( $pass->pass_uid ) ? (string) $pass->pass_uid : '';
			$link = isset( $pass->pass_link ) ? (string) $pass->pass_link : '';
			$key  = '';
			if ( '' !== $uid ) {
				$key = 'uid:' . $uid;
			} elseif ( '' !== $link ) {
				$key = 'link:' . $link;
			} elseif ( ! empty( $pass->id ) ) {
				$key = 'id:' . (int) $pass->id;
			}

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = $pass;
		}

		return $out;
	}

	/**
	 * Display data for one pass card.
	 *
	 * @param object $pass Pass row.
	 * @return array{title: string, type: string, button: string, url: string, module: string}
	 */
	private static function get_pass_item( $pass ) {
		$module_slug = ! empty( $pass->module ) ? (string) $pass->module : '';
		$module      = self::resolve_pass_module( $module_slug );
		$type        = self::get_pass_type_label( $module_slug, $module );
		$title       = '';

		if ( $module && ! empty( $pass->entity_id ) ) {
			$title = trim( (string) $module->get_entity_label( (int) $pass->entity_id ) );
			if ( '' === $title || 1 === preg_match( '/^#\d+$/', $title ) ) {
				$title = '';
			}
		}

		if ( '' === $title ) {
			$title = $type;
		}

		/**
		 * Filter frontend pass title (previously used as the button label).
		 *
		 * @param string $title Title.
		 * @param object $pass  Pass row.
		 */
		$title = (string) apply_filters( 'epc_frontend_pass_label', $title, $pass );

		return array(
			'title'  => '' !== $title ? $title : __( 'Wallet pass', 'epasscard' ),
			'type'   => $type,
			'button' => __( 'Add to wallet', 'epasscard' ),
			'url'    => (string) $pass->pass_link,
			'module' => $module_slug,
		);
	}

	/**
	 * Module instance even when the integration is currently disabled.
	 *
	 * @param string $slug Module slug.
	 * @return EPC_Module|null
	 */
	private static function resolve_pass_module( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || ! function_exists( 'epc_plugin' ) ) {
			return null;
		}

		$plugin = epc_plugin();
		$mod    = $plugin->get_module( $slug );
		if ( $mod ) {
			return $mod;
		}

		$all = $plugin->get_all_modules();
		return isset( $all[ $slug ] ) && $all[ $slug ] instanceof EPC_Module ? $all[ $slug ] : null;
	}

	/**
	 * Customer-facing pass type for a module slug.
	 *
	 * @param string          $slug   Module slug.
	 * @param EPC_Module|null $module Module instance.
	 * @return string
	 */
	private static function get_pass_type_label( $slug, $module ) {
		$map = array(
			'woocommerce-loyalty'       => __( 'Loyalty card', 'epasscard' ),
			'woocommerce-subscriptions' => __( 'Subscription', 'epasscard' ),
			'paid-memberships-pro'      => __( 'Membership', 'epasscard' ),
			'memberpress'               => __( 'Membership', 'epasscard' ),
			'ultimate-membership-pro'   => __( 'Membership', 'epasscard' ),
			'simple-membership'         => __( 'Membership', 'epasscard' ),
			'the-events-calendar'       => __( 'Event ticket', 'epasscard' ),
			'events-manager'            => __( 'Event ticket', 'epasscard' ),
			'pw-gift-cards'             => __( 'Gift card', 'epasscard' ),
			'yith-gift-cards'           => __( 'Gift card', 'epasscard' ),
		);

		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}

		if ( $module ) {
			return (string) $module->get_label();
		}

		return __( 'Wallet pass', 'epasscard' );
	}
}
