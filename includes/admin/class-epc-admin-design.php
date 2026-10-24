<?php
/**
 * Design section: one card design per integration, and which plans, events or products use it.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design section.
 *
 * Index: one card per integration with its preview and "Applies to" line.
 * Editor (?module=slug): the designer (logo, colours, labels, code, live preview) and the
 * "Applies to" table. Loyalty keeps its own single-design form.
 */
class EPC_Admin_Design {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_epc_design_applies', array( __CLASS__, 'handle_applies' ) );
	}

	/**
	 * Where a module's design is edited.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	public static function url_for_module( $slug ) {
		return EPC_Admin_App::url( 'design', array( 'module' => sanitize_key( (string) $slug ) ) );
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
	 * Module being edited, from the URL.
	 *
	 * @return EPC_Module|null
	 */
	public static function current_module() {
		if ( EPC_Admin_App::PAGE_DESIGN !== EPC_Admin_App::current_page() ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$slug    = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : '';
		$modules = self::modules();
		return isset( $modules[ $slug ] ) ? $modules[ $slug ] : null;
	}

	/**
	 * Designer scripts on the editor.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$module = self::current_module();
		if ( null === $module || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		// Loyalty loads its own designer script; the others also get the template-mapping dialog.
		$module->enqueue_designer_assets( 'woocommerce-loyalty' !== $module->get_slug() );
	}

	/**
	 * State of each plan, event or product: which design it uses.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<int, array{id: int, label: string, state: string, template: string, cards: int}>
	 *         state: this (the integration's design), default (the design, through "new items use it"),
	 *         other (another EpassCard template), none (no card).
	 */
	public static function rows( $module ) {
		$slug     = $module->get_slug();
		$saved    = EPC_Template_Starter::get_saved( $slug );
		$uid      = $saved ? (string) $saved['template_uid'] : '';
		$mappings = $module->get_mappings();
		$cards    = EPC_DB::count_active_real_passes_by_entity( $slug );
		$rows     = array();

		foreach ( $module->get_filtered_mappable_entities() as $entity ) {
			$id     = absint( $entity['id'] ?? 0 );
			$stored = isset( $mappings[ (string) $id ] ) && is_array( $mappings[ (string) $id ] ) ? $mappings[ (string) $id ] : array();
			$own    = (string) ( $stored['template_uid'] ?? '' );
			if ( '' !== $own ) {
				$state = ( '' !== $uid && $own === $uid ) ? 'this' : 'other';
			} else {
				$state = empty( EPC_Default_Design::for_entity( $slug, $id ) ) ? 'none' : 'default';
			}
			$rows[] = array(
				'id'       => $id,
				'label'    => (string) ( $entity['label'] ?? $module->get_entity_label( $id ) ),
				'state'    => $state,
				'template' => (string) ( $stored['template_name'] ?? '' ),
				'cards'    => (int) ( $cards[ $id ] ?? 0 ),
			);
		}
		return $rows;
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

		$rows   = self::rows( $module );
		$mapped = 0;
		foreach ( $rows as $row ) {
			if ( 'none' !== $row['state'] ) {
				++$mapped;
			}
		}

		$copy = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $module->get_slug() ) );
		if ( $mapped === count( $rows ) && $mapped > 0 ) {
			/* translators: 1: number, 2: e.g. "membership plans" */
			return sprintf( __( 'All %1$s %2$s', 'epasscard' ), number_format_i18n( $mapped ), $copy['items'] );
		}
		return sprintf(
			/* translators: 1: number with a design, 2: total number, 3: e.g. "membership plans" */
			__( '%1$s of %2$s %3$s', 'epasscard' ),
			number_format_i18n( $mapped ),
			number_format_i18n( count( $rows ) ),
			$copy['items']
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		$module = self::current_module();

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'design',
				'title'   => __( 'Design', 'epasscard' ),
				'subnav'  => array(),
			)
		);

		if ( null !== $module ) {
			self::render_editor( $module );
		} else {
			self::render_index();
		}

		EPC_Admin_Shell::render_close();
	}

	/**
	 * One card per integration.
	 *
	 * @return void
	 */
	private static function render_index() {
		$modules = self::modules();
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
								<p><a class="button button-secondary" href="<?php echo esc_url( self::url_for_module( $slug ) ); ?>"><?php esc_html_e( 'Edit design', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $copy['title'] ); ?></span></a></p>
							<?php else : ?>
								<p><?php esc_html_e( 'No design yet. Customers get no card until there is one.', 'epasscard' ); ?></p>
								<p><a class="button button-primary" href="<?php echo esc_url( self::url_for_module( $slug ) ); ?>"><?php esc_html_e( 'Create the design', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $copy['title'] ); ?></span></a></p>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Where to go after the first design is saved: Home while setup is not finished.
	 *
	 * @return string
	 */
	private static function after_save_url() {
		return EPC_Onboarding::is_complete() ? '' : EPC_Admin_App::url( 'home' );
	}

	/**
	 * Designer and "Applies to".
	 *
	 * @param EPC_Module $module Module.
	 * @return void
	 */
	private static function render_editor( $module ) {
		$slug  = $module->get_slug();
		$copy  = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $slug ) );
		$saved = 'woocommerce-loyalty' === $slug ? null : EPC_Template_Starter::get_saved( $slug );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time message after a redirect.
		$notice = isset( $_GET['epc_design'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_design'] ) ) : '';
		?>
		<div class="epc-design epc-design--editor">
			<p class="epc-back"><a href="<?php echo esc_url( EPC_Admin_App::url( 'design' ) ); ?>">&larr; <?php esc_html_e( 'All designs', 'epasscard' ); ?></a></p>
			<div class="epc-page-head">
				<h2 class="epc-page-head__title">
					<?php
					/* translators: %s: card type, e.g. "Member card for my members" */
					echo esc_html( sprintf( __( 'Design: %s', 'epasscard' ), $copy['title'] ) );
					?>
				</h2>
				<p class="epc-page-head__lead"><?php echo esc_html( $module->get_label() ); ?></p>
			</div>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="epc-message is-success" role="status"><p><?php esc_html_e( 'Saved.', 'epasscard' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! EPC_Connection::is_connected() ) : ?>
				<div class="epc-panel">
					<p><?php esc_html_e( 'Connect your EpassCard account first; the design is saved there.', 'epasscard' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'home' ) ); ?>"><?php esc_html_e( 'Connect', 'epasscard' ); ?></a></p>
				</div>
				<?php
				echo '</div>';
				return;
			endif;
			?>

			<?php if ( 'woocommerce-loyalty' === $slug ) : ?>
				<?php
				$design       = EPC_Loyalty_Pass_Design_Service::get_design();
				$preview      = EPC_Loyalty_Pass_Design_Service::preview_sample_values();
				$is_connected = true;
				include EPC_PLUGIN_DIR . 'admin/views/loyalty/pass-design.php';
				?>
				<p class="description"><?php esc_html_e( 'One loyalty program, one card: this design is used for every loyalty customer.', 'epasscard' ); ?></p>
			<?php elseif ( EPC_Template_Starter::supports( $module ) ) : ?>
				<section class="epc-panel epc-designer" aria-labelledby="epc-designer-title">
					<div class="epc-panel__head">
						<h3 id="epc-designer-title" class="epc-panel__title"><?php echo esc_html( $saved ? __( 'Your card', 'epasscard' ) : __( 'Start from a ready-made card', 'epasscard' ) ); ?></h3>
						<a class="epc-panel__meta" href="<?php echo esc_url( epc_utm_url( 'https://app.epasscard.com/pass-templates' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in EpassCard', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
					</div>
					<?php if ( ! $saved ) : ?>
						<p class="description"><?php esc_html_e( 'Your site name is already on the card. Saving creates one template in your EpassCard account and uses it for every item below; no card is sent to anyone.', 'epasscard' ); ?></p>
					<?php endif; ?>
					<?php EPC_Template_Starter::render_inline( $module, $saved ? '' : self::after_save_url() ); ?>
				</section>

				<?php if ( $saved ) : ?>
					<?php self::render_applies( $module, $saved, $copy ); ?>
				<?php endif; ?>

				<?php self::render_behavior( $module ); ?>

				<details class="epc-panel epc-advanced" id="epc-advanced">
					<summary class="epc-panel__title"><?php esc_html_e( 'Use your own EpassCard templates (advanced)', 'epasscard' ); ?></summary>
					<p class="description"><?php esc_html_e( 'For a plan, event or product that should use a template you built in EpassCard: choose it and map its fields. Here you can also give cards to members who joined before EpassCard.', 'epasscard' ); ?></p>
					<?php $module->render_mapping_section( $module->get_filtered_mappable_entities(), $module->get_mappings(), true ); ?>
				</details>
			<?php else : ?>
				<?php // Integrations added by other plugins have no ready-made design: templates from EpassCard. ?>
				<div class="epc-panel">
					<?php $module->render_mapping_section( $module->get_filtered_mappable_entities(), $module->get_mappings(), true ); ?>
				</div>
				<?php self::render_behavior( $module ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What happens to a card when the membership, booking or gift card changes status.
	 *
	 * @param EPC_Module $module Module.
	 * @return void
	 */
	private static function render_behavior( $module ) {
		if ( ! $module->has_pass_behavior_settings() ) {
			return;
		}
		echo '<div class="epc-panel epc-behavior">';
		$module->render_settings_part( 'behavior' );
		echo '</div>';
	}

	/**
	 * Which plans, events or products use the design.
	 *
	 * @param EPC_Module           $module Module.
	 * @param array<string, mixed> $saved  The integration's design record.
	 * @param array<string, string> $copy  Job copy.
	 * @return void
	 */
	private static function render_applies( $module, array $saved, array $copy ) {
		$slug    = $module->get_slug();
		$rows    = self::rows( $module );
		$default = EPC_Default_Design::get( $slug );
		$uses    = null !== $default && (string) $default['template_uid'] === (string) $saved['template_uid'];
		?>
		<section class="epc-panel epc-applies" aria-labelledby="epc-applies-title">
			<h3 id="epc-applies-title" class="epc-panel__title"><?php esc_html_e( 'Applies to', 'epasscard' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="epc_design_applies" />
				<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>" />
				<?php wp_nonce_field( 'epc_design_applies_' . $slug ); ?>

				<?php if ( empty( $rows ) ) : ?>
					<p><?php echo esc_html( '' !== $copy['no_items'] ? $copy['no_items'] . '.' : __( 'Nothing to apply the design to yet.', 'epasscard' ) ); ?></p>
				<?php else : ?>
					<table class="widefat striped epc-applies__table">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html( ucfirst( $copy['items'] ) ); ?></th>
								<th scope="col"><?php esc_html_e( 'Card', 'epasscard' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Active cards', 'epasscard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php $field = 'epc-applies-' . $row['id']; ?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $row['label'] ); ?></label></th>
									<td>
										<?php if ( 'other' === $row['state'] ) : ?>
											<span id="<?php echo esc_attr( $field ); ?>">
												<?php
												/* translators: %s: template name */
												echo esc_html( sprintf( __( 'Your EpassCard template "%s"', 'epasscard' ), '' !== $row['template'] ? $row['template'] : __( 'unnamed', 'epasscard' ) ) );
												?>
											</span>
											· <a href="#epc-section-mapping"><?php esc_html_e( 'Change', 'epasscard' ); ?></a>
										<?php else : ?>
											<?php $locked = in_array( $row['state'], array( 'this', 'default' ), true ) && $row['cards'] > 0; ?>
											<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( 'applies[' . $row['id'] . ']' ); ?>"<?php echo $locked ? ' aria-describedby="' . esc_attr( $field . '-note' ) . '"' : ''; ?>>
												<option value="this" <?php selected( 'none' !== $row['state'] ); ?>><?php esc_html_e( 'This design', 'epasscard' ); ?></option>
												<?php if ( ! $locked ) : ?>
													<option value="none" <?php selected( 'none', $row['state'] ); ?>><?php esc_html_e( 'No card', 'epasscard' ); ?></option>
												<?php endif; ?>
											</select>
											<?php if ( $locked ) : ?>
												<span id="<?php echo esc_attr( $field . '-note' ); ?>" class="description"><?php esc_html_e( 'Has cards, so it keeps this design.', 'epasscard' ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<td class="num"><?php echo esc_html( number_format_i18n( $row['cards'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="epc-applies__default">
					<label>
						<input type="checkbox" name="use_default" value="1" <?php checked( $uses ); ?> />
						<?php
						/* translators: %s: e.g. "membership plans" */
						echo esc_html( sprintf( __( 'New %s you add later use this design automatically', 'epasscard' ), $copy['items'] ) );
						?>
					</label>
				</p>
				<p class="epc-step__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'epasscard' ); ?></button>
					<span class="description"><?php esc_html_e( 'A plan set to No card gets no new cards; cards already issued stay as they are.', 'epasscard' ); ?></span>
				</p>
			</form>
		</section>
		<?php
	}

	/**
	 * Admin-post: save "Applies to".
	 *
	 * @return void
	 */
	public static function handle_applies() {
		$slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_design_applies_' . $slug );

		$modules = self::modules();
		$saved   = isset( $modules[ $slug ] ) ? EPC_Template_Starter::get_saved( $slug ) : null;
		if ( null === $saved ) {
			wp_die( esc_html__( 'Save the design first.', 'epasscard' ), '', array( 'back_link' => true ) );
		}

		$module = $modules[ $slug ];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against a fixed list below.
		$posted   = isset( $_POST['applies'] ) && is_array( $_POST['applies'] ) ? wp_unslash( $_POST['applies'] ) : array();
		$mappings = $module->get_mappings();
		$excluded = array();
		$changed  = false;

		foreach ( self::rows( $module ) as $row ) {
			$id     = (int) $row['id'];
			$choice = isset( $posted[ $id ] ) ? sanitize_key( (string) $posted[ $id ] ) : '';
			if ( 'other' === $row['state'] || ! in_array( $choice, array( 'this', 'none' ), true ) ) {
				continue; // Another template is changed on the integration page; unknown values are ignored.
			}
			if ( 'none' === $choice && $row['cards'] > 0 && 'none' !== $row['state'] ) {
				$choice = 'this'; // An item with cards keeps its design.
			}

			if ( 'this' === $choice && 'this' !== $row['state'] ) {
				$mappings[ (string) $id ] = array(
					'template_uid'  => (string) $saved['template_uid'],
					'template_name' => (string) $saved['template_name'],
					'template_id'   => (int) ( $saved['template_id'] ?? 0 ),
					'field_mapping' => (array) $saved['field_mapping'],
					'pass_fields'   => (array) $saved['pass_fields'],
				);
				$changed                  = true;
			} elseif ( 'none' === $choice ) {
				if ( isset( $mappings[ (string) $id ] ) ) {
					unset( $mappings[ (string) $id ] );
					$changed = true;
				}
				$excluded[] = $id;
			}
		}

		if ( $changed ) {
			update_option( $module->get_mappings_option_key(), $mappings, false );
		}

		if ( ! empty( $_POST['use_default'] ) ) {
			EPC_Default_Design::set( $slug, $saved, $excluded );
		} else {
			EPC_Default_Design::delete( $slug );
		}

		wp_safe_redirect( EPC_Admin_App::url( 'design', array( 'module' => $slug, 'epc_design' => 'saved' ) ) . '#epc-applies-title' );
		exit;
	}
}
