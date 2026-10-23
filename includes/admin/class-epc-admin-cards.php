<?php
/**
 * Cards section: the cards issued to customers.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cards section.
 *
 * Until the combined card list ships, this shows each integration's numbers and opens its
 * existing list of issued cards.
 */
class EPC_Admin_Cards {

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
			return admin_url( 'admin.php?page=epc-' . $module->get_slug() . '#epc-section-mapping' );
		}
		return '';
	}

	/**
	 * Whether the current user can open a module's list of issued cards.
	 *
	 * @param EPC_Module $module Module.
	 * @return bool
	 */
	private static function can_open_list( $module ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// Only the loyalty screens are open to pass staff.
		return 'woocommerce-loyalty' === $module->get_slug() && epc_current_user_can_manage_passes();
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		$modules = EPC_Admin_Design::modules();

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
				<p class="epc-page-head__lead"><?php esc_html_e( 'The wallet cards this site has issued to customers.', 'epasscard' ); ?></p>
			</div>

			<?php if ( empty( $modules ) ) : ?>
				<div class="epc-panel"><p><?php esc_html_e( 'No integration is turned on yet.', 'epasscard' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped epc-cards__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Card', 'epasscard' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Active', 'epasscard' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Need attention', 'epasscard' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'epasscard' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $modules as $slug => $module ) : ?>
							<?php
							$copy     = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $slug ) );
							$failed   = count( EPC_Pass_Issues::for_module( $slug ) );
							$existing = self::existing_customers_url( $module );
							?>
							<tr>
								<th scope="row">
									<strong><?php echo esc_html( $copy['title'] ); ?></strong><br />
									<span class="description"><?php echo esc_html( $module->get_label() ); ?></span>
								</th>
								<td class="num"><?php echo esc_html( number_format_i18n( EPC_DB::count_real_passes( $slug, 'active' ) ) ); ?></td>
								<td class="num<?php echo $failed > 0 ? ' is-warning' : ''; ?>"><?php echo esc_html( number_format_i18n( $failed ) ); ?></td>
								<td class="epc-cards__actions">
									<?php if ( self::can_open_list( $module ) ) : ?>
										<a href="<?php echo esc_url( $module->get_issued_passes_admin_url() ); ?>"><?php esc_html_e( 'See cards', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $copy['title'] ); ?></span></a>
									<?php endif; ?>
									<?php if ( '' !== $existing ) : ?>
										· <a href="<?php echo esc_url( $existing ); ?>"><?php esc_html_e( 'Give cards to existing customers', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $copy['title'] ); ?></span></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Active counts real customer cards issued from this site; test cards are not counted.', 'epasscard' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		EPC_Admin_Shell::render_close();
	}
}
