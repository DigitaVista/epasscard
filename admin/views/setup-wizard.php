<?php
/**
 * Setup wizard admin view.
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $state
 * @var string $step
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="epc-wizard" data-epc-wizard data-epc-step="<?php echo esc_attr( $step ); ?>">
	<div class="epc-wizard__chrome">
		<div class="epc-wizard__brand">
			<img
				class="epc-wizard__brand-logo"
				src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/epasscard-logo.webp' ); ?>"
				alt="<?php esc_attr_e( 'EpassCard', 'epasscard' ); ?>"
				width="160"
				height="54"
			/>
			<span class="epc-wizard__brand-divider" aria-hidden="true"></span>
			<h1 class="epc-wizard__brand-title"><?php esc_html_e( 'Setup Wizard', 'epasscard' ); ?></h1>
		</div>
		<a class="epc-wizard__exit" href="<?php echo esc_url( admin_url( 'admin.php?page=epasscard' ) ); ?>">
			<span class="epc-icon" aria-hidden="true">close</span>
			<?php esc_html_e( 'Exit setup', 'epasscard' ); ?>
		</a>
	</div>
	<div class="epc-wizard__busy" hidden>
		<span class="epc-wizard__spinner" aria-hidden="true"></span>
		<span><?php esc_html_e( 'Working…', 'epasscard' ); ?></span>
	</div>
	<div id="epc-wizard-inner" class="epc-wizard__inner">
		<?php include EPC_PLUGIN_DIR . 'admin/views/setup-wizard-inner.php'; ?>
	</div>
	<p class="epc-wizard__reset">
		<button type="button" class="button-link" data-epc-wizard-reset><?php esc_html_e( 'Reset setup and start over', 'epasscard' ); ?></button>
		<span class="epc-wizard__reset-note"><?php esc_html_e( 'Disconnects EpassCard, clears wizard progress, and removes saved pass mappings. Customer points and issued passes are kept.', 'epasscard' ); ?></span>
	</p>

	<div class="epc-wizard-confirm" id="epc-wizard-reset-confirm" hidden>
		<div class="epc-wizard-confirm__dialog" role="dialog" aria-modal="true" aria-labelledby="epc-wizard-reset-title">
			<h2 id="epc-wizard-reset-title"><?php esc_html_e( 'Reset setup and start over?', 'epasscard' ); ?></h2>
			<p><?php esc_html_e( 'This disconnects EpassCard, clears wizard progress, and removes saved pass mappings. Customer points and issued passes are kept.', 'epasscard' ); ?></p>
			<p class="epc-wizard-confirm__actions">
				<button type="button" class="button" data-epc-wizard-reset-cancel><?php esc_html_e( 'Cancel', 'epasscard' ); ?></button>
				<button type="button" class="button button-primary epc-wizard__btn" data-epc-wizard-reset-confirm><?php esc_html_e( 'Reset setup', 'epasscard' ); ?></button>
			</p>
		</div>
	</div>
</div>
