<?php
/**
 * Design section: one card design per integration.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design section.
 *
 * Until the new designer ships, each design opens the integration's existing design screen.
 */
class EPC_Admin_Design {

	/**
	 * Where a module's design is edited.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	public static function url_for_module( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( 'woocommerce-loyalty' === $slug ) {
			return admin_url( 'admin.php?page=epc-woocommerce-loyalty#epc-section-loyalty-pass-design' );
		}
		return admin_url( 'admin.php?page=epc-' . $slug . '#epc-section-mapping' );
	}

	/**
	 * Integrations to show: turned on and with their plugin active.
	 *
	 * @return array<string, EPC_Module>
	 */
	public static function modules() {
		$out = array();
		foreach ( EPC_Module_Settings::get_enabled_slugs() as $slug ) {
			$module = EPC_Module_Loader::get_module_instance( $slug );
			if ( $module instanceof EPC_Module && $module->is_available() ) {
				$out[ $slug ] = $module;
			}
		}
		return $out;
	}

	/**
	 * "Applies to" line of a module's design.
	 *
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	public static function applies_to( $module ) {
		if ( 'woocommerce-loyalty' === $module->get_slug() ) {
			return __( 'Every loyalty customer', 'epasscard' );
		}

		$entities = $module->get_filtered_mappable_entities();
		$mappings = $module->get_mappings();
		$mapped   = 0;
		foreach ( $entities as $entity ) {
			$key = (string) absint( $entity['id'] ?? 0 );
			if ( ! empty( $mappings[ $key ]['template_uid'] ) ) {
				++$mapped;
			}
		}

		$copy = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $module->get_slug() ) );
		return sprintf(
			/* translators: 1: number with a design, 2: total number, 3: e.g. "membership plans" */
			__( '%1$s of %2$s %3$s', 'epasscard' ),
			number_format_i18n( $mapped ),
			number_format_i18n( count( $entities ) ),
			$copy['items']
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		$modules = self::modules();

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'design',
				'title'   => __( 'Design', 'epasscard' ),
				'subnav'  => array(),
			)
		);
		?>
		<div class="epc-design">
			<div class="epc-page-head">
				<h2 class="epc-page-head__title"><?php esc_html_e( 'Card designs', 'epasscard' ); ?></h2>
				<p class="epc-page-head__lead"><?php esc_html_e( 'One design for each kind of card you give.', 'epasscard' ); ?></p>
			</div>

			<?php if ( empty( $modules ) ) : ?>
				<div class="epc-panel">
					<p><?php esc_html_e( 'Choose what to give your customers first.', 'epasscard' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'setup' ) ); ?>"><?php esc_html_e( 'Choose', 'epasscard' ); ?></a></p>
				</div>
			<?php else : ?>
				<div class="epc-designs">
					<?php foreach ( $modules as $slug => $module ) : ?>
						<?php $copy = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $slug ) ); ?>
						<section class="epc-panel epc-designs__item" aria-labelledby="<?php echo esc_attr( 'epc-design-' . $slug ); ?>">
							<div class="epc-panel__head">
								<h3 id="<?php echo esc_attr( 'epc-design-' . $slug ); ?>" class="epc-panel__title"><?php echo esc_html( $copy['title'] ); ?></h3>
								<span class="epc-panel__meta"><?php echo esc_html( $module->get_label() ); ?></span>
							</div>
							<?php if ( EPC_Jobs::has_design( $module ) ) : ?>
								<?php EPC_Card_Preview::render( $slug ); ?>
								<p class="epc-designs__applies">
									<strong><?php esc_html_e( 'Applies to:', 'epasscard' ); ?></strong>
									<?php echo esc_html( self::applies_to( $module ) ); ?>
								</p>
								<p><a class="button button-secondary" href="<?php echo esc_url( self::url_for_module( $slug ) ); ?>"><?php esc_html_e( 'Edit design', 'epasscard' ); ?></a></p>
							<?php else : ?>
								<p><?php esc_html_e( 'No design yet. Customers get no card until there is one.', 'epasscard' ); ?></p>
								<p><a class="button button-primary" href="<?php echo esc_url( self::url_for_module( $slug ) ); ?>"><?php esc_html_e( 'Create the design', 'epasscard' ); ?></a></p>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		EPC_Admin_Shell::render_close();
	}
}
