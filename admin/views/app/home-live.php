<?php
/**
 * Home after setup: what the site's cards are doing.
 *
 * Variables: $issues, $active, $week, $jobs.
 *
 * Only numbers this site knows are shown. Whether a card was added to a wallet or scanned is
 * not reported back to WordPress, so it is not shown here.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Failed records per integration, for the "needs attention" strip.
$epc_failed_by_module = array();
foreach ( $issues as $epc_issue ) {
	$epc_issue_module = (string) ( $epc_issue['module'] ?? '' );
	if ( '' !== $epc_issue_module ) {
		$epc_failed_by_module[ $epc_issue_module ] = ( $epc_failed_by_module[ $epc_issue_module ] ?? 0 ) + 1;
	}
}

$epc_state_words = array(
	EPC_Jobs::LIVE        => __( 'Live', 'epasscard' ),
	EPC_Jobs::READY       => __( 'Ready, waiting for the first card', 'epasscard' ),
	EPC_Jobs::NEEDS_SETUP => __( 'Not set up', 'epasscard' ),
);

$epc_setup_module = EPC_Onboarding::get_state()['module'];
?>
<div class="epc-home epc-home--live">
	<div class="epc-page-head">
		<h2 class="epc-page-head__title"><?php esc_html_e( 'Your cards', 'epasscard' ); ?></h2>
		<p class="epc-page-head__lead"><?php esc_html_e( 'New customers get a card automatically. This page shows what needs you.', 'epasscard' ); ?></p>
	</div>

	<?php if ( ! empty( $epc_failed_by_module ) ) : ?>
		<section class="epc-attention" aria-labelledby="epc-attention-title">
			<h3 id="epc-attention-title" class="epc-attention__title">
				<?php
				$epc_failed_total = array_sum( $epc_failed_by_module );
				/* translators: %d: number of customers */
				echo esc_html( sprintf( _n( '%d customer did not get their card update', '%d customers did not get their card update', $epc_failed_total, 'epasscard' ), $epc_failed_total ) );
				?>
			</h3>
			<p class="epc-attention__text"><?php esc_html_e( 'EpassCard could not create or update these cards. Their data in WordPress is fine; only the wallet card is behind.', 'epasscard' ); ?></p>
			<ul class="epc-attention__list">
				<?php foreach ( $epc_failed_by_module as $epc_slug => $epc_count ) : ?>
					<?php $epc_module = EPC_Module_Loader::get_module_instance( $epc_slug ); ?>
					<li>
						<?php if ( $epc_module instanceof EPC_Module ) : ?>
							<a href="<?php echo esc_url( EPC_Admin_App::url( 'cards', array( 'epc_module' => $epc_slug ) ) . '#epc-section-pass-issues-' . $epc_slug ); ?>">
								<?php
								/* translators: 1: integration name, 2: number of records */
								echo esc_html( sprintf( _n( '%1$s: %2$d record, see why', '%1$s: %2$d records, see why', $epc_count, 'epasscard' ), $epc_module->get_label(), $epc_count ) );
								?>
							</a>
						<?php else : ?>
							<?php
							/* translators: 1: integration slug, 2: number of records */
							echo esc_html( sprintf( _n( '%1$s: %2$d record', '%1$s: %2$d records', $epc_count, 'epasscard' ), $epc_slug, $epc_count ) );
							?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( class_exists( 'EPC_Retry_Failed' ) ) : ?>
				<div class="epc-attention__actions"><?php echo wp_kses( EPC_Retry_Failed::render_controls(), EPC_Retry_Failed::allowed_html() ); ?></div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<div class="epc-home__grid">
		<div class="epc-home__main">
			<div class="epc-stats" role="list">
				<div class="epc-stat" role="listitem">
					<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( $active ) ); ?></span>
					<span class="epc-stat__label"><?php esc_html_e( 'Active cards', 'epasscard' ); ?></span>
				</div>
				<div class="epc-stat" role="listitem">
					<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( $week ) ); ?></span>
					<span class="epc-stat__label"><?php esc_html_e( 'New in the last 7 days', 'epasscard' ); ?></span>
				</div>
				<div class="epc-stat<?php echo ! empty( $issues ) ? ' is-warning' : ''; ?>" role="listitem">
					<span class="epc-stat__value"><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span>
					<span class="epc-stat__label"><?php esc_html_e( 'Need attention', 'epasscard' ); ?></span>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'Counts are cards issued from this site to real customers; test cards are not counted.', 'epasscard' ); ?></p>

			<section class="epc-panel" aria-labelledby="epc-jobs-title">
				<div class="epc-panel__head">
					<h3 id="epc-jobs-title" class="epc-panel__title"><?php esc_html_e( 'What you give customers', 'epasscard' ); ?></h3>
				</div>
				<ul class="epc-jobs">
					<?php foreach ( $jobs as $epc_job => $epc_status ) : ?>
						<?php
						$epc_copy   = EPC_Jobs::get_copy( $epc_job );
						$epc_module = '' !== $epc_status['module'] ? EPC_Module_Loader::get_module_instance( $epc_status['module'] ) : null;
						?>
						<li class="epc-jobs__item is-<?php echo esc_attr( $epc_status['state'] ); ?>">
							<span class="epc-jobs__title">
								<?php echo esc_html( $epc_copy['title'] ); ?>
								<?php if ( $epc_module instanceof EPC_Module ) : ?>
									<span class="epc-jobs__module"><?php echo esc_html( $epc_module->get_label() ); ?></span>
								<?php endif; ?>
							</span>
							<span class="epc-jobs__state">
								<?php echo esc_html( $epc_state_words[ $epc_status['state'] ] ?? '' ); ?>
								<?php if ( '' !== $epc_status['blocker'] && EPC_Jobs::LIVE !== $epc_status['state'] ) : ?>
									<span class="epc-jobs__blocker">· <?php echo esc_html( $epc_status['blocker'] ); ?></span>
								<?php endif; ?>
							</span>
							<span class="epc-jobs__action">
								<?php if ( EPC_Jobs::LIVE === $epc_status['state'] || EPC_Jobs::READY === $epc_status['state'] ) : ?>
									<a href="<?php echo esc_url( EPC_Admin_App::url( 'cards' ) ); ?>"><?php esc_html_e( 'Cards', 'epasscard' ); ?></a>
									· <a href="<?php echo esc_url( EPC_Admin_Home::design_url( $epc_status['module'] ) ); ?>"><?php esc_html_e( 'Design', 'epasscard' ); ?></a>
								<?php else : ?>
									<a href="<?php echo esc_url( EPC_Admin_App::url( 'setup', array( 'job' => $epc_job ) ) ); ?>"><?php esc_html_e( 'Set up', 'epasscard' ); ?></a>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		</div>

		<aside class="epc-home__aside" aria-label="<?php esc_attr_e( 'Your card', 'epasscard' ); ?>">
			<?php if ( '' !== $epc_setup_module ) : ?>
				<div class="epc-panel">
					<div class="epc-panel__head">
						<h3 class="epc-panel__title"><?php esc_html_e( 'Your card', 'epasscard' ); ?></h3>
						<span class="epc-panel__meta"><?php esc_html_e( 'Sample data', 'epasscard' ); ?></span>
					</div>
					<?php EPC_Card_Preview::render( $epc_setup_module ); ?>
					<p class="epc-panel__links">
						<a href="<?php echo esc_url( EPC_Admin_Home::design_url( $epc_setup_module ) ); ?>"><?php esc_html_e( 'Edit design', 'epasscard' ); ?></a>
						· <a href="<?php echo esc_url( EPC_Admin_App::url( 'try' ) ); ?>"><?php esc_html_e( 'Test card', 'epasscard' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		</aside>
	</div>
</div>
