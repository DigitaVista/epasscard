<?php
/**
 * Shared “Use template builder” panel (loyalty + membership/events/gift cards).
 *
 * @package EpassCard
 *
 * @var array{notice:string,checklist:array<int,string>,fields:array<int,string>} $builder
 * @var array{template_name:string,preview_kicker:string,preview_title:string,header_label:string,header_value:string,secondary_label:string,secondary_value:string,barcode_alt:string} $starter
 * @var string $builder_select_id Select element id.
 * @var string $builder_preview_id Prefix for preview element ids (unique per form).
 * @var string $builder_preview_name Card title shown in the sample preview.
 * @var bool   $builder_disabled Whether controls are disabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$builder              = isset( $builder ) && is_array( $builder ) ? $builder : EPC_Setup_Wizard::builder_guidance_for_goal( 'membership' );
$starter              = isset( $starter ) && is_array( $starter ) ? $starter : EPC_Setup_Wizard::starter_copy_for_goal( 'membership' );
$builder_select_id    = isset( $builder_select_id ) ? (string) $builder_select_id : 'epc-wizard-map-template';
$builder_preview_id   = isset( $builder_preview_id ) ? sanitize_html_class( (string) $builder_preview_id ) : 'map';
$builder_preview_name = isset( $builder_preview_name ) ? (string) $builder_preview_name : (string) ( $starter['template_name'] ?? __( 'Pass', 'epasscard' ) );
$builder_disabled     = ! empty( $builder_disabled );
$preview_mark         = strtoupper( substr( $builder_preview_name, 0, 1 ) );
$checklist            = isset( $builder['checklist'] ) && is_array( $builder['checklist'] ) ? $builder['checklist'] : array();
$notice               = isset( $builder['notice'] ) ? (string) $builder['notice'] : '';
?>
<div class="epc-wizard__setup-grid epc-wizard__builder-grid" data-epc-wizard-design="builder" hidden>
	<div class="epc-wizard__form">
		<label class="epc-wizard__field" for="<?php echo esc_attr( $builder_select_id ); ?>">
			<span><?php esc_html_e( 'Template builder', 'epasscard' ); ?></span>
			<span class="epc-wizard__field-row">
				<select id="<?php echo esc_attr( $builder_select_id ); ?>" <?php disabled( $builder_disabled ); ?>>
					<option value=""><?php esc_html_e( 'Loading templates…', 'epasscard' ); ?></option>
				</select>
				<button type="button" class="button" data-epc-wizard-refresh-templates <?php disabled( $builder_disabled ); ?>><?php esc_html_e( 'Refresh', 'epasscard' ); ?></button>
			</span>
		</label>
		<p class="epc-wizard__builder-link">
			<a href="https://app.epasscard.com/pass-templates" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open template builder', 'epasscard' ); ?></a>
		</p>
		<?php if ( '' !== $notice ) : ?>
			<div class="epc-wizard__notice epc-wizard__notice--fields" role="note">
				<p>
					<?php
					echo wp_kses(
						$notice,
						array(
							'strong' => array(),
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $checklist ) ) : ?>
			<div class="epc-wizard__builder-map">
				<p class="epc-wizard__builder-map-title"><?php esc_html_e( 'We will auto-map these when names match', 'epasscard' ); ?></p>
				<ul class="epc-wizard__builder-checklist">
					<?php foreach ( $checklist as $item ) : ?>
						<li><?php echo esc_html( (string) $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<div class="epc-wizard__preview">
		<p class="epc-wizard__preview-label"><?php esc_html_e( 'Sample layout', 'epasscard' ); ?></p>
		<div class="epc-wizard-pass epc-wizard-pass--hero" id="epc-wizard-builder-preview-<?php echo esc_attr( $builder_preview_id ); ?>" aria-hidden="true">
			<div class="epc-aw__header">
				<div class="epc-aw__brand">
					<span class="epc-aw__logo">
						<span><?php echo esc_html( $preview_mark ? $preview_mark : 'P' ); ?></span>
					</span>
					<span class="epc-aw__org"><?php echo esc_html( $builder_preview_name ); ?></span>
				</div>
				<div class="epc-aw__hfield">
					<span class="epc-aw__label"><?php echo esc_html( (string) ( $starter['header_label'] ?? '' ) ); ?></span>
					<span class="epc-aw__hvalue"><?php echo esc_html( (string) ( $starter['header_value'] ?? '' ) ); ?></span>
				</div>
			</div>
			<div class="epc-aw__strip">
				<span class="epc-aw__strip-kicker"><?php echo esc_html( (string) ( $starter['preview_kicker'] ?? '' ) ); ?></span>
				<span class="epc-aw__strip-title"><?php echo esc_html( (string) ( $starter['preview_title'] ?? '' ) ); ?></span>
			</div>
			<div class="epc-aw__fields epc-aw__fields--aux">
				<div class="epc-aw__field">
					<span class="epc-aw__label"><?php esc_html_e( 'Name', 'epasscard' ); ?></span>
					<span class="epc-aw__value"><?php esc_html_e( 'Alex Rivera', 'epasscard' ); ?></span>
				</div>
				<div class="epc-aw__field epc-aw__field--end">
					<span class="epc-aw__label"><?php echo esc_html( (string) ( $starter['secondary_label'] ?? '' ) ); ?></span>
					<span class="epc-aw__value"><?php echo esc_html( (string) ( $starter['secondary_value'] ?? '' ) ); ?></span>
				</div>
			</div>
			<div class="epc-aw__barcode">
				<span class="epc-aw__qr" aria-hidden="true">
					<img src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/wizard-dummy-qr.png' ); ?>" alt="" width="78" height="78" />
				</span>
				<span class="epc-aw__alt"><?php echo esc_html( (string) ( $starter['barcode_alt'] ?? 'PASS-0001' ) ); ?></span>
			</div>
		</div>
		<p class="epc-wizard__hint"><?php esc_html_e( 'Preview is representative. Your EpassCard template design is used on the real pass.', 'epasscard' ); ?></p>
	</div>
</div>
