<?php
/**
 * Cards section: every card this site issued, from every integration.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cards section.
 */
class EPC_Admin_Cards {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
	}

	/**
	 * Integrations whose cards the current user may see.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function modules() {
		$out = array();
		foreach ( EPC_Admin_Design::modules() as $slug => $module ) {
			if ( $module->current_user_can_manage_passes() ) {
				$out[ $slug ] = $module;
			}
		}
		return $out;
	}

	/**
	 * Pass-action script (Update, Email, Expire, Retry) on the Cards list.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( EPC_Admin_App::PAGE_CARDS !== EPC_Admin_App::current_page() ) {
			return;
		}
		$modules = self::modules();
		$first   = reset( $modules );
		if ( $first instanceof EPC_Module ) {
			// Each button names its own integration; this only loads the script and its strings.
			$first->enqueue_designer_assets();
		}
	}

	/**
	 * Where cards can be given to customers who joined before EpassCard, or '' when the
	 * integration cannot do that.
	 *
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	public static function existing_customers_url( $module ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( 'woocommerce-loyalty' === $module->get_slug() ) {
			// "Past orders" tab: credits past orders and creates their cards.
			return admin_url( 'admin.php?page=epc-woocommerce-loyalty#epc-section-loyalty-program' );
		}
		if ( $module->supports_backfill() ) {
			return EPC_Admin_Design::url_for_module( $module->get_slug() ) . '#epc-section-mapping';
		}
		return '';
	}

	/**
	 * Filters from the URL.
	 *
	 * @param array<string, EPC_Module> $modules Integrations.
	 * @return array{module: string, view: string, search: string}
	 */
	private static function filters( array $modules ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$module = isset( $_GET['epc_module'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_module'] ) ) : '';
		$view   = isset( $_GET['epc_view'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_view'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'module' => isset( $modules[ $module ] ) ? $module : '',
			'view'   => in_array( $view, array( 'all', 'active', 'revoked', 'test' ), true ) ? $view : 'all',
			'search' => mb_substr( $search, 0, 100 ),
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		$modules = self::modules();
		$filters = self::filters( $modules );
		$issues  = array();
		foreach ( EPC_Pass_Issues::all() as $issue ) {
			if ( isset( $modules[ (string) ( $issue['module'] ?? '' ) ] ) ) {
				$issues[] = $issue;
			}
		}

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'cards',
				'title'   => __( 'Cards', 'epasscard' ),
				'subnav'  => array(),
			)
		);
		?>
		<div class="epc-cards">
			<div class="epc-page-head">
				<h2 class="epc-page-head__title"><?php esc_html_e( 'Cards', 'epasscard' ); ?></h2>
				<p class="epc-page-head__lead"><?php esc_html_e( 'Every wallet card this site has issued, from all integrations.', 'epasscard' ); ?></p>
			</div>

			<?php if ( empty( $modules ) ) : ?>
				<div class="epc-panel"><p><?php esc_html_e( 'No integration is turned on yet.', 'epasscard' ); ?></p></div>
			<?php else : ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="epc-stats" role="list">
						<div class="epc-stat" role="listitem">
							<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( EPC_DB::count_all_real_passes( 'active' ) ) ); ?></span>
							<span class="epc-stat__label"><?php esc_html_e( 'Active cards', 'epasscard' ); ?></span>
						</div>
						<div class="epc-stat" role="listitem">
							<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( EPC_DB::count_emailed_real_passes() ) ); ?></span>
							<span class="epc-stat__label"><?php esc_html_e( 'Emailed to customers', 'epasscard' ); ?></span>
						</div>
						<div class="epc-stat" role="listitem">
							<span class="epc-stat__value">&mdash;</span>
							<span class="epc-stat__label"><?php esc_html_e( 'Added to a wallet: coming when EpassCard reports it', 'epasscard' ); ?></span>
						</div>
						<div class="epc-stat<?php echo ! empty( $issues ) ? ' is-warning' : ''; ?>" role="listitem">
							<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span>
							<span class="epc-stat__label"><?php esc_html_e( 'Need attention', 'epasscard' ); ?></span>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'Counts leave test cards out.', 'epasscard' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $issues ) ) : ?>
					<div class="epc-cards__attention">
						<?php foreach ( $modules as $module ) : ?>
							<?php EPC_Pass_Issues::render_module_panel( $module, true ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<form method="get" class="epc-cards__filters" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( EPC_Admin_App::PAGE_CARDS ); ?>" />
					<label class="screen-reader-text" for="epc-cards-search"><?php esc_html_e( 'Search cards by name, email or card id', 'epasscard' ); ?></label>
					<input type="search" id="epc-cards-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Name, email or card id', 'epasscard' ); ?>" />
					<?php if ( count( $modules ) > 1 ) : ?>
						<label class="screen-reader-text" for="epc-cards-module"><?php esc_html_e( 'Integration', 'epasscard' ); ?></label>
						<select id="epc-cards-module" name="epc_module">
							<option value=""><?php esc_html_e( 'All integrations', 'epasscard' ); ?></option>
							<?php foreach ( $modules as $slug => $module ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['module'], $slug ); ?>><?php echo esc_html( $module->get_label() ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<label class="screen-reader-text" for="epc-cards-view"><?php esc_html_e( 'Status', 'epasscard' ); ?></label>
					<select id="epc-cards-view" name="epc_view">
						<option value="all" <?php selected( $filters['view'], 'all' ); ?>><?php esc_html_e( 'All customer cards', 'epasscard' ); ?></option>
						<option value="active" <?php selected( $filters['view'], 'active' ); ?>><?php esc_html_e( 'Active', 'epasscard' ); ?></option>
						<option value="revoked" <?php selected( $filters['view'], 'revoked' ); ?>><?php esc_html_e( 'Revoked', 'epasscard' ); ?></option>
						<option value="test" <?php selected( $filters['view'], 'test' ); ?>><?php esc_html_e( 'Test cards', 'epasscard' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'epasscard' ); ?></button>
				</form>

				<?php
				require_once EPC_PLUGIN_DIR . 'includes/admin/class-epc-cards-list-table.php';
				$table = new EPC_Cards_List_Table( $modules, $filters );
				$table->prepare_items();
				$table->display();
				?>

				<?php
				$existing = array();
				foreach ( $modules as $slug => $module ) {
					$url = self::existing_customers_url( $module );
					if ( '' !== $url ) {
						$existing[ $slug ] = $url;
					}
				}
				?>
				<?php if ( ! empty( $existing ) ) : ?>
					<section class="epc-panel epc-cards__existing" aria-labelledby="epc-cards-existing">
						<h3 id="epc-cards-existing" class="epc-panel__title"><?php esc_html_e( 'Give cards to existing customers', 'epasscard' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Customers who joined before EpassCard have no card yet. Each card counts toward your EpassCard plan.', 'epasscard' ); ?></p>
						<ul class="epc-summary__list">
							<?php foreach ( $existing as $slug => $url ) : ?>
								<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $modules[ $slug ]->get_label() ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		EPC_Admin_Shell::render_close();
	}
}
