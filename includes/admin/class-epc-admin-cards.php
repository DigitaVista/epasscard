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
		add_action( 'admin_post_epc_cards_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * What a card shows now: points and tier for loyalty, else the plan, event or product.
	 *
	 * @param object     $item   Pass row.
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	public static function shows( $item, $module ) {
		if ( 'woocommerce-loyalty' === (string) $item->module && class_exists( 'EPC_Loyalty_Account_Service' ) ) {
			$account = EPC_Loyalty_Account_Service::get( (int) $item->user_id );
			if ( ! $account ) {
				return '';
			}
			$tier = EPC_Loyalty_Reward_Service::get_tier_progress( (int) $account->lifetime_points );
			/* translators: %s: number of points */
			$out = sprintf( _n( '%s point', '%s points', (int) $account->points_balance, 'epasscard' ), number_format_i18n( (int) $account->points_balance ) );
			if ( ! empty( $tier['current']['name'] ) ) {
				$out .= ' · ' . (string) $tier['current']['name'];
			}
			return $out;
		}
		return $module->get_entity_label( (int) $item->entity_id );
	}

	/**
	 * Admin-post: the cards of the current filters as CSV (at most 10,000 rows).
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! epc_current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_cards_export' );

		$modules = self::modules();
		$filters = self::filters( $modules );
		$list    = '' !== $filters['module'] ? array( $filters['module'] ) : array_keys( $modules );

		$user_ids = null;
		if ( '' !== $filters['search'] ) {
			$user_ids = get_users(
				array(
					'search'         => '*' . $filters['search'] . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 200,
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=epasscard-cards-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV download.
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to start the export.', 'epasscard' ) );
		}

		$safe = array( 'EPC_Loyalty_Customer_Service', 'csv_safe_cell' );
		fputcsv(
			$output,
			array_map(
				$safe,
				array(
					__( 'Customer', 'epasscard' ),
					__( 'Email', 'epasscard' ),
					__( 'Card', 'epasscard' ),
					__( 'Integration', 'epasscard' ),
					__( 'Status', 'epasscard' ),
					__( 'Shows', 'epasscard' ),
					__( 'Record ID', 'epasscard' ),
					__( 'Card ID', 'epasscard' ),
					__( 'Card link', 'epasscard' ),
					__( 'Last update', 'epasscard' ),
				)
			),
			',',
			'"',
			'\\'
		);

		$page     = 1;
		$exported = 0;
		do {
			$result = EPC_DB::query_all_passes(
				array(
					'modules'  => $list,
					'view'     => $filters['view'],
					'search'   => $filters['search'],
					'user_ids' => $user_ids,
					'page'     => $page,
					'per_page' => 100,
				)
			);
			foreach ( $result['items'] as $item ) {
				$module = $modules[ (string) $item->module ];
				$user   = (int) $item->user_id > 0 ? get_userdata( (int) $item->user_id ) : false;
				$status = EPC_Pass_Status::get_display_status( $item );
				$copy   = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( (string) $item->module ) );
				fputcsv(
					$output,
					array_map(
						$safe,
						array(
							$user ? $user->display_name : '',
							$user ? $user->user_email : '',
							$copy['title'],
							$module->get_label(),
							$status['label'] . ( $status['is_test'] ? ' (' . __( 'Test', 'epasscard' ) . ')' : '' ),
							self::shows( $item, $module ),
							(string) $item->source_id,
							(string) $item->pass_uid,
							(string) $item->pass_link,
							(string) $item->updated_at,
						)
					),
					',',
					'"',
					'\\'
				);
				++$exported;
				if ( $exported >= 10000 ) {
					break 2;
				}
			}
			++$page;
		} while ( ( $page - 1 ) * 100 < (int) $result['total'] );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV download.
		exit;
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters (the export checks its nonce first).
		$module = isset( $_REQUEST['epc_module'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['epc_module'] ) ) : '';
		$view   = isset( $_REQUEST['epc_view'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['epc_view'] ) ) : 'all';
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
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
					<?php
					$export_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'     => 'epc_cards_export',
								'epc_module' => $filters['module'],
								'epc_view'   => $filters['view'],
								's'          => $filters['search'],
							),
							admin_url( 'admin-post.php' )
						),
						'epc_cards_export'
					);
					?>
					<a class="button epc-cards__export" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'epasscard' ); ?></a>
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
