<?php
/**
 * Loyalty pass designer and representative preview.
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $design
 * @var array<string, string> $preview
 * @var bool $is_connected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$design  = isset( $design ) && is_array( $design ) ? $design : EPC_Loyalty_Pass_Design_Service::get_design();
$preview = isset( $preview ) && is_array( $preview ) ? $preview : EPC_Loyalty_Pass_Design_Service::preview_sample_values();
$is_connected = isset( $is_connected ) ? (bool) $is_connected : EPC_Connection::is_connected();

$secondary_value = $preview['tier'];
if ( 'next_reward' === ( $design['secondary_mode'] ?? '' ) ) {
	$secondary_value = $preview['next_reward'];
} elseif ( 'milestone' === ( $design['secondary_mode'] ?? '' ) ) {
	$secondary_value = $preview['milestone'];
}

$bg   = ! empty( $design['colors']['background'] ) ? $design['colors']['background'] : '#1E1B4B';
$text = ! empty( $design['colors']['text'] ) ? $design['colors']['text'] : '#FFFFFF';
$member_id = (string) ( $preview['member_id'] ?? 'LYL-DEMO-001' );
$barcode_format = strtoupper( (string) ( $design['barcode_format'] ?? 'QR' ) );
$barcode_preview_url = EPC_Loyalty_Pass_Design_Service::preview_barcode_image_url( $barcode_format, $member_id );
?>
<div id="epc-section-loyalty-pass-design" class="epc-section epc-loyalty-pass-design">
	<div class="epc-page-header">
		<h2 class="epc-page-title"><?php esc_html_e( 'Pass Design', 'epasscard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Design the loyalty StoreCard template remotely through EpassCard API v2. The preview is representative — Apple and Google layout rules differ, so positioning is not pixel-identical.', 'epasscard' ); ?>
		</p>
	</div>

	<?php if ( ! $is_connected ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %s: Connection admin link. */
					esc_html__( 'Connect EpassCard on the %s page before saving a loyalty pass design.', 'epasscard' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=epasscard' ) ) . '">' . esc_html__( 'Connection', 'epasscard' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="epc-loyalty-designer" data-epc-loyalty-designer>
		<form id="epc-loyalty-pass-design-form" class="epc-card epc-loyalty-designer__form" method="post" action="">
			<p class="epc-form-notice" aria-live="polite"></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="epc-loyalty-template-name"><?php esc_html_e( 'Template name', 'epasscard' ); ?></label></th>
						<td>
							<input id="epc-loyalty-template-name" name="template_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['template_name'] ); ?>" required />
							<?php if ( ! empty( $design['template_uid'] ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: template UUID. */
										esc_html__( 'Remote template: %s', 'epasscard' ),
										esc_html( (string) $design['template_uid'] )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-org-name"><?php esc_html_e( 'Organization name', 'epasscard' ); ?></label></th>
						<td><input id="epc-loyalty-org-name" name="organization_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['organization_name'] ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'epasscard' ); ?></th>
						<td>
							<input type="hidden" name="logo_id" id="epc-loyalty-logo-id" value="<?php echo esc_attr( (string) (int) $design['logo_id'] ); ?>" />
							<input type="hidden" name="logo_url" id="epc-loyalty-logo-url" value="<?php echo esc_attr( (string) $design['logo_url'] ); ?>" />
							<button type="button" class="button epc-loyalty-media-pick" data-target="logo"><?php esc_html_e( 'Select logo', 'epasscard' ); ?></button>
							<button type="button" class="button-link-delete epc-loyalty-media-clear" data-target="logo"><?php esc_html_e( 'Clear', 'epasscard' ); ?></button>
							<p class="description"><?php esc_html_e( 'Upper-left mark (also used as the pass icon). The image URL must be publicly reachable so EpassCard can fetch it.', 'epasscard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Strip image', 'epasscard' ); ?></th>
						<td>
							<input type="hidden" name="strip_id" id="epc-loyalty-strip-id" value="<?php echo esc_attr( (string) (int) $design['strip_id'] ); ?>" />
							<input type="hidden" name="strip_url" id="epc-loyalty-strip-url" value="<?php echo esc_attr( (string) $design['strip_url'] ); ?>" />
							<button type="button" class="button epc-loyalty-media-pick" data-target="strip"><?php esc_html_e( 'Select strip', 'epasscard' ); ?></button>
							<button type="button" class="button-link-delete epc-loyalty-media-clear" data-target="strip"><?php esc_html_e( 'Clear', 'epasscard' ); ?></button>
							<p class="description"><?php esc_html_e( 'Wide banner under the header on StoreCard layouts.', 'epasscard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Colors', 'epasscard' ); ?></th>
						<td class="epc-loyalty-color-grid">
							<label>
								<span><?php esc_html_e( 'Background', 'epasscard' ); ?></span>
								<input type="color" name="colors[background]" id="epc-loyalty-color-background" value="<?php echo esc_attr( $bg ); ?>" />
							</label>
							<label>
								<span><?php esc_html_e( 'Text', 'epasscard' ); ?></span>
								<input type="color" name="colors[text]" id="epc-loyalty-color-text" value="<?php echo esc_attr( $text ); ?>" />
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-points-label"><?php esc_html_e( 'Points header label', 'epasscard' ); ?></label></th>
						<td><input id="epc-loyalty-points-label" name="points_label" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['points_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-name-label"><?php esc_html_e( 'Name field label', 'epasscard' ); ?></label></th>
						<td><input id="epc-loyalty-name-label" name="name_label" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['name_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-secondary-mode"><?php esc_html_e( 'Secondary value', 'epasscard' ); ?></label></th>
						<td>
							<select id="epc-loyalty-secondary-mode" name="secondary_mode">
								<option value="tier" <?php selected( $design['secondary_mode'], 'tier' ); ?>><?php esc_html_e( 'Tier', 'epasscard' ); ?></option>
								<option value="next_reward" <?php selected( $design['secondary_mode'], 'next_reward' ); ?>><?php esc_html_e( 'Next reward', 'epasscard' ); ?></option>
								<option value="milestone" <?php selected( $design['secondary_mode'], 'milestone' ); ?>><?php esc_html_e( 'Milestone progress', 'epasscard' ); ?></option>
							</select>
							<input id="epc-loyalty-secondary-label" name="secondary_label" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['secondary_label'] ); ?>" aria-label="<?php esc_attr_e( 'Secondary field label', 'epasscard' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-barcode-format"><?php esc_html_e( 'QR/Barcode format', 'epasscard' ); ?></label></th>
						<td>
							<select id="epc-loyalty-barcode-format" name="barcode_format">
								<?php foreach ( array( 'QR', 'PDF417', 'AZTEC', 'CODE128' ) as $format ) : ?>
									<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $design['barcode_format'], $format ); ?>><?php echo esc_html( $format ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Encoded value is the non-secret loyalty membership ID.', 'epasscard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epc-loyalty-expire-date"><?php esc_html_e( 'Template expiry', 'epasscard' ); ?></label></th>
						<td>
							<input id="epc-loyalty-expire-date" name="expire_date" type="text" class="regular-text" value="<?php echo esc_attr( (string) $design['expire_date'] ); ?>" placeholder="<?php esc_attr_e( 'Optional — YYYY-MM-DD HH:mm:ss', 'epasscard' ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Leave blank for near-lifetime passes (~99 years). Otherwise use a MySQL datetime in your organization timezone, or a placeholder such as {Valid Until}.', 'epasscard' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" <?php disabled( ! $is_connected ); ?>>
					<?php esc_html_e( 'Save pass design', 'epasscard' ); ?>
				</button>
				<button type="button" class="button epc-loyalty-test-pass" <?php disabled( ! $is_connected || empty( $design['template_uid'] ) ); ?>>
					<?php esc_html_e( 'Issue test pass for me', 'epasscard' ); ?>
				</button>
			</p>
		</form>

		<aside class="epc-card epc-loyalty-designer__preview" aria-label="<?php esc_attr_e( 'Loyalty pass preview', 'epasscard' ); ?>">
			<h3><?php esc_html_e( 'Preview', 'epasscard' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Representative layout for Apple/Google StoreCard style passes.', 'epasscard' ); ?></p>

			<div
				class="epc-loyalty-preview-card"
				id="epc-loyalty-preview-card"
				style="--epc-loyalty-bg: <?php echo esc_attr( $bg ); ?>; --epc-loyalty-text: <?php echo esc_attr( $text ); ?>; --epc-loyalty-strip: <?php echo esc_attr( $bg ); ?>;"
			>
				<div class="epc-loyalty-preview-card__header">
					<div class="epc-loyalty-preview-card__logo-wrap">
						<img class="epc-loyalty-preview-card__logo" id="epc-loyalty-preview-logo" src="<?php echo esc_url( (string) $design['logo_url'] ); ?>" alt="" <?php echo empty( $design['logo_url'] ) ? 'hidden' : ''; ?> />
						<span class="epc-loyalty-preview-card__logo-fallback" id="epc-loyalty-preview-logo-fallback" <?php echo empty( $design['logo_url'] ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Logo', 'epasscard' ); ?></span>
					</div>
					<div class="epc-loyalty-preview-card__points">
						<span class="epc-loyalty-preview-card__points-label" id="epc-loyalty-preview-points-label"><?php echo esc_html( (string) $design['points_label'] ); ?></span>
						<strong class="epc-loyalty-preview-card__points-value" id="epc-loyalty-preview-points-value"><?php echo esc_html( $preview['points'] ); ?></strong>
					</div>
				</div>
				<div class="epc-loyalty-preview-card__strip" id="epc-loyalty-preview-strip">
					<img id="epc-loyalty-preview-strip-img" src="<?php echo esc_url( (string) $design['strip_url'] ); ?>" alt="" <?php echo empty( $design['strip_url'] ) ? 'hidden' : ''; ?> />
				</div>
				<div class="epc-loyalty-preview-card__body">
					<div class="epc-loyalty-preview-card__field">
						<span class="epc-loyalty-preview-card__field-label" id="epc-loyalty-preview-name-label"><?php echo esc_html( (string) $design['name_label'] ); ?></span>
						<span class="epc-loyalty-preview-card__field-value" id="epc-loyalty-preview-name-value"><?php echo esc_html( $preview['name'] ); ?></span>
					</div>
					<div class="epc-loyalty-preview-card__field epc-loyalty-preview-card__field--right">
						<span class="epc-loyalty-preview-card__field-label" id="epc-loyalty-preview-secondary-label"><?php echo esc_html( (string) $design['secondary_label'] ); ?></span>
						<span class="epc-loyalty-preview-card__field-value" id="epc-loyalty-preview-secondary-value"><?php echo esc_html( $secondary_value ); ?></span>
					</div>
				</div>
				<div class="epc-loyalty-preview-card__barcode">
					<img
						class="epc-loyalty-preview-card__barcode-art<?php echo in_array( $barcode_format, array( 'CODE128', 'PDF417' ), true ) ? ' is-linear' : ''; ?>"
						id="epc-loyalty-preview-barcode"
						src="<?php echo esc_url( $barcode_preview_url ); ?>"
						alt=""
						data-member-id="<?php echo esc_attr( $member_id ); ?>"
						width="140"
						height="140"
						decoding="async"
					/>
					<code id="epc-loyalty-preview-member-id"><?php echo esc_html( $member_id ); ?></code>
				</div>
			</div>
		</aside>
	</div>
</div>
