<?php
/**
 * Try it: the admin's test card.
 *
 * Variables: $slug, $module, $ready, $card, $notice, $error.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epc_messages = array(
	'created'       => array( 'success', __( 'Your test card is ready. Scan the code with your phone.', 'epasscard' ) ),
	'emailed'       => array( 'success', __( 'Sent. Open the email on your phone.', 'epasscard' ) ),
	'email_failed'  => array( 'error', __( 'The email could not be sent from this site. Scan the code or copy the link instead, and check your site\'s mail setup.', 'epasscard' ) ),
	'failed'        => array( 'error', __( 'The test card could not be created.', 'epasscard' ) ),
	'deleted'       => array( 'success', __( 'The test card was deleted.', 'epasscard' ) ),
	'delete_failed' => array( 'error', __( 'The test card could not be deleted.', 'epasscard' ) ),
);
$epc_is_loyalty = 'woocommerce-loyalty' === $slug;
?>
<div class="epc-try">
	<p class="epc-back"><a href="<?php echo esc_url( EPC_Admin_App::url( 'home' ) ); ?>">&larr; <?php esc_html_e( 'Home', 'epasscard' ); ?></a></p>
	<div class="epc-page-head">
		<h2 class="epc-page-head__title"><?php esc_html_e( 'Put a test card on your phone', 'epasscard' ); ?></h2>
		<p class="epc-page-head__lead"><?php esc_html_e( 'See what your customers will see before anyone else does.', 'epasscard' ); ?></p>
	</div>
	<?php EPC_Help::render( 'try' ); ?>

	<?php if ( isset( $epc_messages[ $notice ] ) ) : ?>
		<div class="epc-message is-<?php echo esc_attr( $epc_messages[ $notice ][0] ); ?>" role="<?php echo 'error' === $epc_messages[ $notice ][0] ? 'alert' : 'status'; ?>">
			<p>
				<?php echo esc_html( $epc_messages[ $notice ][1] ); ?>
				<?php if ( '' !== $error ) : ?>
					<br /><strong><?php esc_html_e( 'EpassCard said:', 'epasscard' ); ?></strong> <?php echo esc_html( $error ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $ready ) : ?>
		<div class="epc-panel">
			<p><?php esc_html_e( 'Finish the steps before this one first: connect your account, choose what to give your customers, and save a card design.', 'epasscard' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( EPC_Admin_App::url( 'home' ) ); ?>"><?php esc_html_e( 'Back to the checklist', 'epasscard' ); ?></a></p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="epc-home__grid">
		<div class="epc-home__main">
			<?php if ( ! $card ) : ?>
				<section class="epc-panel" aria-labelledby="epc-try-create">
					<h3 id="epc-try-create" class="epc-panel__title"><?php esc_html_e( 'Create your test card', 'epasscard' ); ?></h3>
					<p>
						<?php
						echo esc_html(
							$epc_is_loyalty
								? __( 'Your own loyalty card, with your name and your current points, marked as a test card on this site.', 'epasscard' )
								: __( 'A card with your name and sample data. No member, booking or gift card is created on your site.', 'epasscard' )
						);
						?>
					</p>
					<p class="description">
						<?php
						echo esc_html(
							$epc_is_loyalty
								? __( 'It counts as 1 card on your EpassCard plan. It is not counted as a customer card on this site.', 'epasscard' )
								: __( 'It counts as 1 card on your EpassCard plan until you delete it. It is not counted as a customer card on this site.', 'epasscard' )
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_try_issue" />
						<?php wp_nonce_field( 'epc_try_issue' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create my test card', 'epasscard' ); ?></button>
					</form>
				</section>
			<?php else : ?>
				<section class="epc-panel epc-try__card" aria-labelledby="epc-try-scan">
					<h3 id="epc-try-scan" class="epc-panel__title"><?php esc_html_e( 'Scan with your phone camera', 'epasscard' ); ?></h3>
					<div class="epc-try__scan">
						<div class="epc-qr" data-epc-qr="<?php echo esc_attr( $card['pass_link'] ); ?>" role="img" aria-label="<?php esc_attr_e( 'QR code of your test card link', 'epasscard' ); ?>"></div>
						<ol class="epc-try__steps">
							<li><?php esc_html_e( 'Open the camera on your phone and point it at the code.', 'epasscard' ); ?></li>
							<li><?php esc_html_e( 'Tap the link that appears.', 'epasscard' ); ?></li>
							<li><?php esc_html_e( 'Tap "Add to Apple Wallet" or "Add to Google Wallet".', 'epasscard' ); ?></li>
						</ol>
					</div>
					<div class="epc-try__other">
						<p class="epc-try__other-title"><?php esc_html_e( 'Or:', 'epasscard' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="epc-inline-form">
							<input type="hidden" name="action" value="epc_try_email" />
							<?php wp_nonce_field( 'epc_try_email' ); ?>
							<button type="submit" class="button button-secondary">
								<?php
								/* translators: %s: admin email */
								echo esc_html( sprintf( __( 'Email it to %s', 'epasscard' ), wp_get_current_user()->user_email ) );
								?>
							</button>
						</form>
						<button type="button" class="button button-secondary" data-epc-copy="<?php echo esc_attr( $card['pass_link'] ); ?>"><?php esc_html_e( 'Copy link', 'epasscard' ); ?></button>
						<a class="button button-secondary" href="<?php echo esc_url( $card['pass_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open on this device', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
						<span class="epc-copy-status" aria-live="polite"></span>
					</div>
				</section>

				<section class="epc-panel" aria-labelledby="epc-try-result">
					<h3 id="epc-try-result" class="epc-panel__title"><?php esc_html_e( 'Is the card in your wallet?', 'epasscard' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="epc-step__actions">
						<input type="hidden" name="action" value="epc_try_result" />
						<?php wp_nonce_field( 'epc_try_result' ); ?>
						<button type="submit" name="result" value="worked" class="button button-primary"><?php esc_html_e( 'Yes, it worked', 'epasscard' ); ?></button>
						<button type="submit" name="result" value="help" class="button button-secondary"><?php esc_html_e( 'Something went wrong', 'epasscard' ); ?></button>
					</form>
				</section>

				<?php if ( 'help' === $notice ) : ?>
					<section id="epc-try-help" class="epc-panel" aria-labelledby="epc-try-help-title" tabindex="-1">
						<h3 id="epc-try-help-title" class="epc-panel__title"><?php esc_html_e( 'What usually fixes it', 'epasscard' ); ?></h3>
						<ul class="epc-summary__list">
							<li><?php esc_html_e( 'The link opens but there is no "Add" button: open it in Safari (iPhone) or Chrome (Android), not inside another app.', 'epasscard' ); ?></li>
							<li><?php esc_html_e( 'The code does not scan: use "Email it to me" or "Copy link" and open the link on the phone.', 'epasscard' ); ?></li>
							<li><?php esc_html_e( 'The card shows the wrong logo or colours: change the design, then create a new test card.', 'epasscard' ); ?></li>
						</ul>
						<p>
							<a href="<?php echo esc_url( EPC_Admin_Home::design_url( $slug ) ); ?>"><?php esc_html_e( 'Edit the design', 'epasscard' ); ?></a>
							· <a href="<?php echo esc_url( EPC_Admin_App::url( 'api-log' ) ); ?>"><?php esc_html_e( 'See what EpassCard answered (API log)', 'epasscard' ); ?></a>
						</p>
					</section>
				<?php endif; ?>

				<?php if ( ! $epc_is_loyalty ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="epc-try__delete">
						<input type="hidden" name="action" value="epc_try_delete" />
						<?php wp_nonce_field( 'epc_try_delete' ); ?>
						<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete the test card', 'epasscard' ); ?></button>
						<span class="description"><?php esc_html_e( 'EpassCard expires it, which removes it from your wallet.', 'epasscard' ); ?></span>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<aside class="epc-home__aside" aria-label="<?php esc_attr_e( 'Card preview', 'epasscard' ); ?>">
			<div class="epc-panel">
				<div class="epc-panel__head">
					<h3 class="epc-panel__title"><?php esc_html_e( 'Your card', 'epasscard' ); ?></h3>
					<span class="epc-panel__meta"><?php esc_html_e( 'Sample data', 'epasscard' ); ?></span>
				</div>
				<?php EPC_Card_Preview::render( $slug ); ?>
			</div>
		</aside>
	</div>
</div>
