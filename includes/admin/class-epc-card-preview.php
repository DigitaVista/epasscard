<?php
/**
 * A preview of a job's card with sample data, drawn in HTML.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card preview for Home, Setup and Try it.
 *
 * Built from the design the plugin stores (the loyalty design, or the ready-made design of an
 * integration) and the sample values of its preset. A site that uses its own EpassCard template
 * gets the same layout with default colours, labelled as a sample.
 */
class EPC_Card_Preview {

	/**
	 * Preview data for a module.
	 *
	 * @param string $slug Module slug.
	 * @return array{org: string, logo: string, background: string, text: string, label: string, kind: string, primary: array{label: string, value: string}, fields: array<int, array{label: string, value: string}>, own_template: bool}
	 */
	public static function get_data( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$org  = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$data = array(
			'org'          => '' !== $org ? $org : 'EpassCard',
			'logo'         => '',
			'background'   => '#1E1B4B',
			'text'         => '#FFFFFF',
			'label'        => '#C7D2FE',
			'kind'         => '',
			'primary'      => array(
				'label' => '',
				'value' => '',
			),
			'fields'       => array(),
			'own_template' => false,
		);

		if ( 'woocommerce-loyalty' === $slug && class_exists( 'EPC_Loyalty_Pass_Design_Service' ) ) {
			$design  = EPC_Loyalty_Pass_Design_Service::get_design();
			$samples = EPC_Loyalty_Pass_Design_Service::preview_sample_values();
			$second  = array(
				'tier'        => $samples['tier'],
				'next_reward' => $samples['next_reward'],
				'milestone'   => $samples['milestone'],
			);
			$data['kind']       = __( 'Loyalty', 'epasscard' );
			$data['org']        = '' !== (string) $design['organization_name'] ? (string) $design['organization_name'] : $data['org'];
			$data['logo']       = (string) $design['logo_url'];
			$data['background'] = '' !== (string) $design['colors']['background'] ? (string) $design['colors']['background'] : $data['background'];
			$data['text']       = '' !== (string) $design['colors']['text'] ? (string) $design['colors']['text'] : $data['text'];
			$data['label']      = $data['text'];
			$data['primary']    = array(
				'label' => (string) $design['points_label'],
				'value' => (string) $samples['points'],
			);
			$data['fields']     = array(
				array(
					'label' => (string) $design['name_label'],
					'value' => (string) $samples['name'],
				),
				array(
					'label' => (string) $design['secondary_label'],
					'value' => (string) ( $second[ $design['secondary_mode'] ] ?? $samples['tier'] ),
				),
			);
			$data['own_template'] = 'builder' === (string) $design['design_source'];
			return $data;
		}

		$module = EPC_Module_Loader::get_module_instance( $slug );
		$preset = EPC_Template_Starter::get_preset( $slug );
		if ( ! $module instanceof EPC_Module || null === $preset ) {
			return $data;
		}

		$design = EPC_Template_Starter::get_design( $module );
		$labels = (array) $design['labels'];
		$label  = static function ( array $field ) use ( $labels ) {
			return isset( $labels[ $field['name'] ] ) ? (string) $labels[ $field['name'] ] : (string) $field['label'];
		};

		$kinds = array(
			'membership' => __( 'Member', 'epasscard' ),
			'events'     => __( 'Ticket', 'epasscard' ),
			'gift-cards' => __( 'Gift card', 'epasscard' ),
			'voucher'    => __( 'Voucher', 'epasscard' ),
			'card'       => __( 'Card', 'epasscard' ),
			'loyalty'    => __( 'Loyalty', 'epasscard' ),
		);

		$data['kind']       = isset( $kinds[ $preset['type'] ] ) ? $kinds[ $preset['type'] ] : '';
		$data['org']        = (string) $design['organization_name'];
		$data['logo']       = (string) $design['logo_url'];
		$data['background'] = (string) $design['colors']['background'];
		$data['text']       = (string) $design['colors']['text'];
		$data['label']      = (string) $design['colors']['label'];

		$primary         = (array) ( $preset['primary'][0] ?? $preset['header'][0] ?? array() );
		$data['primary'] = array(
			'label' => ! empty( $primary ) ? $label( $primary ) : '',
			'value' => (string) ( $primary['sample'] ?? '' ),
		);
		foreach ( array_slice( (array) $preset['secondary'], 0, 2 ) as $field ) {
			$data['fields'][] = array(
				'label' => $label( $field ),
				'value' => (string) $field['sample'],
			);
		}

		// Mapped to a template the merchant built in EpassCard rather than the ready-made one.
		$saved = EPC_Template_Starter::get_saved( $slug );
		foreach ( $module->get_mappings() as $mapping ) {
			if ( is_array( $mapping ) && ! empty( $mapping['template_uid'] ) && ( ! $saved || (string) $saved['template_uid'] !== (string) $mapping['template_uid'] ) ) {
				$data['own_template'] = true;
				break;
			}
		}

		return $data;
	}

	/**
	 * Print the preview.
	 *
	 * @param string $slug  Module slug.
	 * @param string $class Extra CSS class.
	 * @return void
	 */
	public static function render( $slug, $class = '' ) {
		$data  = self::get_data( $slug );
		$style = sprintf(
			'--epc-card-bg:%1$s;--epc-card-text:%2$s;--epc-card-label:%3$s;',
			self::color( $data['background'], '#1E1B4B' ),
			self::color( $data['text'], '#FFFFFF' ),
			self::color( $data['label'], self::color( $data['text'], '#FFFFFF' ) )
		);
		?>
		<div class="epc-card-preview <?php echo esc_attr( $class ); ?>" style="<?php echo esc_attr( $style ); ?>" role="img" aria-label="<?php esc_attr_e( 'Card preview with sample data', 'epasscard' ); ?>">
			<div class="epc-card-preview__head">
				<?php if ( '' !== $data['logo'] ) : ?>
					<img class="epc-card-preview__logo" src="<?php echo esc_url( $data['logo'] ); ?>" alt="" />
				<?php endif; ?>
				<span class="epc-card-preview__org"><?php echo esc_html( $data['org'] ); ?></span>
				<?php if ( '' !== $data['kind'] ) : ?>
					<span class="epc-card-preview__kind"><?php echo esc_html( $data['kind'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $data['primary']['label'] ) : ?>
				<div class="epc-card-preview__primary">
					<span class="epc-card-preview__label"><?php echo esc_html( $data['primary']['label'] ); ?></span>
					<span class="epc-card-preview__value"><?php echo esc_html( $data['primary']['value'] ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $data['fields'] ) ) : ?>
				<div class="epc-card-preview__fields">
					<?php foreach ( $data['fields'] as $field ) : ?>
						<div>
							<span class="epc-card-preview__label"><?php echo esc_html( $field['label'] ); ?></span>
							<span class="epc-card-preview__field"><?php echo esc_html( $field['value'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="epc-card-preview__code" aria-hidden="true"><span></span></div>
		</div>
		<?php if ( $data['own_template'] ) : ?>
			<p class="epc-card-preview__note"><?php esc_html_e( 'You use your own EpassCard template; this is a sample layout.', 'epasscard' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * A safe colour value.
	 *
	 * @param string $value    Colour.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function color( $value, $fallback ) {
		$hex = sanitize_hex_color( (string) $value );
		return is_string( $hex ) && '' !== $hex ? $hex : $fallback;
	}
}
