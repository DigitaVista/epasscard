<?php
/**
 * Home: setup checklist.
 *
 * Variables: $state, $steps, $current, $job, $status, $copy, $module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epc_connected = EPC_Connection::is_connected();
$epc_settings  = EPC_Connection::get_settings();
$epc_email     = isset( $epc_settings['connected_email'] ) ? (string) $epc_settings['connected_email'] : '';
$epc_slug      = $module instanceof EPC_Module ? $module->get_slug() : '';
$epc_status_words = array(
	'done'     => __( 'Done', 'epasscard' ),
	'current'  => __( 'Now', 'epasscard' ),
	'todo'     => __( 'Next', 'epasscard' ),
	'optional' => __( 'Optional', 'epasscard' ),
	'skipped'  => __( 'Skipped', 'epasscard' ),
);
$epc_number = 0;

// Plain-words "how the first real card happens", per job.
$epc_first_card = array(
	'loyalty'    => __( 'The next order from a registered customer that reaches a status that earns points creates their card.', 'epasscard' ),
	'membership' => __( 'The next member who joins a plan that has this design gets a card.', 'epasscard' ),
	'events'     => __( 'The next attendee who registers or books gets a ticket.', 'epasscard' ),
	'gift-cards' => __( 'The next gift card that is created (sold, or added by you) gets a wallet card.', 'epasscard' ),
	'products'   => __( 'The next paid order for a product with a wallet card gives the buyer one card for each one bought.', 'epasscard' ),
);

?>
<div class="epc-home">
	<div class="epc-page-head">
		<h2 class="epc-page-head__title"><?php echo esc_html( $copy ? $copy['goal'] : __( 'Put your cards in your customers\' wallets', 'epasscard' ) ); ?></h2>
		<p class="epc-page-head__lead"><?php esc_html_e( 'Live means: new customers get a card automatically, and your first real customer has one.', 'epasscard' ); ?></p>
	</div>

	<ol class="epc-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'epasscard' ); ?>">
		<?php foreach ( $steps as $epc_id => $epc_step ) : ?>
			<?php ++$epc_number; ?>
			<li class="epc-stepper__step is-<?php echo esc_attr( $epc_step['status'] ); ?>"<?php echo 'current' === $epc_step['status'] ? ' aria-current="step"' : ''; ?>>
				<span class="epc-stepper__dot" aria-hidden="true"><?php echo in_array( $epc_step['status'], array( 'done', 'skipped' ), true ) ? '&#10003;' : esc_html( (string) $epc_number ); ?></span>
				<span class="epc-stepper__text">
					<span class="epc-stepper__state"><?php echo esc_html( $epc_status_words[ $epc_step['status'] ] ); ?></span>
					<span class="epc-stepper__label"><?php echo esc_html( $epc_step['label'] ); ?></span>
				</span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="epc-home__grid">
		<div class="epc-home__main">

			<?php /* 1. Connect */ ?>
			<section class="epc-step is-<?php echo esc_attr( $steps['connect']['status'] ); ?>" aria-labelledby="epc-step-connect">
				<div class="epc-step__head">
					<h3 id="epc-step-connect" class="epc-step__title"><?php esc_html_e( '1 · Connect your EpassCard account', 'epasscard' ); ?></h3>
					<?php if ( 'done' === $steps['connect']['status'] ) : ?>
						<span class="epc-step__meta">
							<?php
							echo '' !== $epc_email
								/* translators: %s: account email */
								? esc_html( sprintf( __( 'Connected as %s', 'epasscard' ), $epc_email ) )
								: esc_html__( 'Connected', 'epasscard' );
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php if ( 'current' === $steps['connect']['status'] ) : ?>
					<p class="epc-step__text"><?php esc_html_e( 'EpassCard creates and signs the wallet cards. Connect an account; the free plan includes 50 live cards.', 'epasscard' ); ?></p>
					<?php include EPC_PLUGIN_DIR . 'admin/views/settings/connect-forms.php'; ?>
				<?php endif; ?>
			</section>

			<?php /* 2. Job & rules */ ?>
			<section class="epc-step is-<?php echo esc_attr( $steps['job']['status'] ); ?>" aria-labelledby="epc-step-job">
				<div class="epc-step__head">
					<h3 id="epc-step-job" class="epc-step__title"><?php esc_html_e( '2 · Choose what to give your customers', 'epasscard' ); ?></h3>
					<?php if ( 'done' === $steps['job']['status'] && $copy ) : ?>
						<span class="epc-step__meta">
							<?php echo esc_html( $copy['title'] . ( $module instanceof EPC_Module ? ' · ' . $module->get_label() : '' ) ); ?>
							· <a href="<?php echo esc_url( EPC_Admin_App::url( 'setup', array( 'job' => $job ) ) ); ?>"><?php esc_html_e( 'Edit rules', 'epasscard' ); ?></a>
						</span>
					<?php elseif ( 'todo' === $steps['job']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'After step 1', 'epasscard' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'current' === $steps['job']['status'] ) : ?>
					<?php if ( '' !== $job && $copy ) : ?>
						<p class="epc-step__text">
							<?php
							/* translators: %s: card type, e.g. "Loyalty card for my store" */
							echo esc_html( sprintf( __( 'You picked: %s. Confirm the rules to continue.', 'epasscard' ), $copy['title'] ) );
							?>
						</p>
						<p class="epc-step__actions"><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'setup', array( 'job' => $job ) ) ); ?>"><?php esc_html_e( 'Review the rules', 'epasscard' ); ?></a></p>
					<?php else : ?>
						<p class="epc-step__text"><?php esc_html_e( 'A loyalty card, a member card, event tickets or gift cards. We check which plugins this site has and show what we will set up before anything is created.', 'epasscard' ); ?></p>
						<p class="epc-step__actions"><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'setup' ) ); ?>"><?php esc_html_e( 'Choose', 'epasscard' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<?php /* 3. Design */ ?>
			<section class="epc-step is-<?php echo esc_attr( $steps['design']['status'] ); ?>" aria-labelledby="epc-step-design">
				<div class="epc-step__head">
					<h3 id="epc-step-design" class="epc-step__title"><?php esc_html_e( '3 · Design your card', 'epasscard' ); ?></h3>
					<?php if ( 'done' === $steps['design']['status'] && '' !== $epc_slug ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'Saved', 'epasscard' ); ?> · <a href="<?php echo esc_url( EPC_Admin_Home::design_url( $epc_slug ) ); ?>"><?php esc_html_e( 'Edit design', 'epasscard' ); ?></a></span>
					<?php elseif ( 'todo' === $steps['design']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'After step 2', 'epasscard' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'current' === $steps['design']['status'] && '' !== $epc_slug ) : ?>
					<?php if ( $status && 0 === (int) $status['items'] && '' !== $copy['no_items'] ) : ?>
						<p class="epc-step__text epc-step__text--warn">
							<?php
							/* translators: 1: e.g. "No membership plans yet", 2: plugin name */
							echo esc_html( sprintf( __( '%1$s. Create one in %2$s first; the card design is applied to each of them.', 'epasscard' ), $copy['no_items'], $module->get_dependency_label() ) );
							?>
						</p>
					<?php else : ?>
						<p class="epc-step__text"><?php esc_html_e( 'Your site name is already on the card. Add your logo and colours, check the preview, and save. Saving a design does not create or send any cards.', 'epasscard' ); ?></p>
						<p class="epc-step__actions"><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_Home::design_url( $epc_slug ) ); ?>"><?php esc_html_e( 'Open the designer', 'epasscard' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<?php /* 4. Try it (optional) */ ?>
			<section class="epc-step is-<?php echo esc_attr( $steps['try']['status'] ); ?>" aria-labelledby="epc-step-try">
				<div class="epc-step__head">
					<h3 id="epc-step-try" class="epc-step__title"><?php esc_html_e( '4 · Try it on your phone', 'epasscard' ); ?> <span class="epc-step__optional"><?php esc_html_e( '(optional)', 'epasscard' ); ?></span></h3>
					<?php if ( 'done' === $steps['try']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'Tested', 'epasscard' ); ?> · <a href="<?php echo esc_url( EPC_Admin_App::url( 'try' ) ); ?>"><?php esc_html_e( 'Open', 'epasscard' ); ?></a></span>
					<?php elseif ( 'skipped' === $steps['try']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'Skipped', 'epasscard' ); ?> · <a href="<?php echo esc_url( EPC_Admin_App::url( 'try' ) ); ?>"><?php esc_html_e( 'Try it now', 'epasscard' ); ?></a></span>
					<?php elseif ( 'optional' === $steps['try']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'After step 3', 'epasscard' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'current' === $steps['try']['status'] ) : ?>
					<p class="epc-step__text"><?php esc_html_e( 'Put a test card with your name on your own phone, or skip and test later. The card preview on this page shows the card without creating anything.', 'epasscard' ); ?></p>
					<div class="epc-step__actions">
						<a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'try' ) ); ?>"><?php esc_html_e( 'Send a test card to my phone', 'epasscard' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="epc-inline-form">
							<input type="hidden" name="action" value="epc_try_skip" />
							<?php wp_nonce_field( 'epc_try_skip' ); ?>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Skip, I\'ll test later', 'epasscard' ); ?></button>
						</form>
					</div>
				<?php endif; ?>
			</section>

			<?php /* 5. Go live */ ?>
			<section class="epc-step is-<?php echo esc_attr( $steps['live']['status'] ); ?>" aria-labelledby="epc-step-live">
				<div class="epc-step__head">
					<h3 id="epc-step-live" class="epc-step__title"><?php esc_html_e( '5 · Go live', 'epasscard' ); ?></h3>
					<?php if ( 'todo' === $steps['live']['status'] && 'done' !== $steps['design']['status'] ) : ?>
						<span class="epc-step__meta"><?php esc_html_e( 'After step 3', 'epasscard' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'current' === $steps['live']['status'] || ( 'todo' === $steps['live']['status'] && 'done' === $steps['design']['status'] ) ) : ?>
					<ul class="epc-checks">
						<li class="is-done">
							<?php esc_html_e( 'Automatic cards are on: new customers get a card without you doing anything.', 'epasscard' ); ?>
						</li>
						<li>
							<?php esc_html_e( 'Waiting for your first real customer card.', 'epasscard' ); ?>
							<?php echo esc_html( $epc_first_card[ $job ] ?? '' ); ?>
						</li>
					</ul>
					<p class="epc-step__text"><?php esc_html_e( 'Cards are emailed from your site\'s own mail (WordPress mail or your SMTP plugin) when the automatic email is on.', 'epasscard' ); ?> <a href="<?php echo esc_url( EPC_Admin_App::url( 'settings', array( 'tab' => 'emails' ) ) ); ?>"><?php esc_html_e( 'Email settings', 'epasscard' ); ?></a></p>
					<?php $epc_existing = $module instanceof EPC_Module ? EPC_Admin_Cards::existing_customers_url( $module ) : ''; ?>
					<?php if ( '' !== $epc_existing ) : ?>
						<p class="epc-step__actions"><a class="button button-secondary" href="<?php echo esc_url( $epc_existing ); ?>"><?php esc_html_e( 'Give cards to existing customers', 'epasscard' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>
		</div>

		<aside class="epc-home__aside" aria-label="<?php esc_attr_e( 'Your card and help', 'epasscard' ); ?>">
			<?php if ( '' !== $epc_slug ) : ?>
				<div class="epc-panel">
					<div class="epc-panel__head">
						<h3 class="epc-panel__title"><?php esc_html_e( 'Your card so far', 'epasscard' ); ?></h3>
						<span class="epc-panel__meta"><?php esc_html_e( 'Sample data', 'epasscard' ); ?></span>
					</div>
					<?php EPC_Card_Preview::render( $epc_slug ); ?>
				</div>
			<?php endif; ?>

			<?php
			if ( ! $epc_connected ) {
				// First visit: what EpassCard does, before the first step.
				EPC_Help::render(
					'home',
					array(
						'layout'  => 'aside',
						'heading' => __( 'How EpassCard works', 'epasscard' ),
					)
				);
			}
			if ( '' !== $current ) {
				EPC_Help::render(
					$current,
					array(
						'layout'  => 'aside',
						'heading' => __( 'How this step works', 'epasscard' ),
					)
				);
			}
			?>

		</aside>
	</div>
</div>
