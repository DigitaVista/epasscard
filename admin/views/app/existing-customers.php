<?php
/**
 * Cards → Give cards to existing customers.
 *
 * Variables (EPC_Existing_Customers::render()): $modules, $selected, $job, $check, $notice, $left.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epc_status      = (string) $job['status'];
$epc_active      = in_array( $epc_status, array( 'running', 'paused' ), true );
$epc_finished    = in_array( $epc_status, array( 'done', 'cancelled', 'stopped' ), true );
$epc_job_module  = EPC_Module_Loader::get_module_instance( (string) $job['module'] );
$epc_job_label   = $epc_job_module instanceof EPC_Module ? $epc_job_module->get_label() : (string) $job['module'];
$epc_check_mod   = null !== $check && isset( $modules[ (string) $check['module'] ] ) ? $modules[ (string) $check['module'] ] : null;
$epc_post_url    = admin_url( 'admin-post.php' );
$epc_settings    = EPC_Admin_App::url( 'settings', array( 'tab' => 'emails' ) );

/**
 * Customer words for a record: name and email of its user, else "Record N".
 *
 * @param string $source_id Record id.
 * @param int    $user_id   User id.
 * @return string
 */
$epc_customer = static function ( $source_id, $user_id ) {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( $user ) {
		return '' !== (string) $user->user_email ? $user->display_name . ' (' . $user->user_email . ')' : $user->display_name;
	}
	/* translators: %s: record id in the integration (member, attendee, gift card) */
	return sprintf( __( 'Record %s', 'epasscard' ), $source_id );
};

/**
 * Small admin-post form with one button.
 *
 * @param string $command Command.
 * @param string $label   Button label.
 * @param string $class   Button classes.
 * @return void
 */
$epc_control = static function ( $command, $label, $class = 'button' ) use ( $epc_post_url ) {
	?>
	<form method="post" action="<?php echo esc_url( $epc_post_url ); ?>" class="epc-existing__inline-form">
		<input type="hidden" name="action" value="epc_existing_control" />
		<input type="hidden" name="command" value="<?php echo esc_attr( $command ); ?>" />
		<?php wp_nonce_field( 'epc_existing' ); ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<section id="epc-existing" class="epc-panel epc-existing" aria-labelledby="epc-existing-title">
	<div class="epc-panel__head">
		<h3 id="epc-existing-title" class="epc-panel__title"><?php esc_html_e( 'Give cards to existing customers', 'epasscard' ); ?></h3>
	</div>

	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $epc_active ) : ?>
		<div
			class="epc-existing__run"
			data-epc-existing-run
			data-status="<?php echo esc_attr( $epc_status ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'epc_existing' ) ); ?>"
		>
			<p class="epc-existing__run-title">
				<strong>
					<?php
					/* translators: %s: integration name */
					echo esc_html( sprintf( __( 'Giving cards to existing customers of %s', 'epasscard' ), $epc_job_label ) );
					?>
				</strong>
				<span class="epc-pill<?php echo 'paused' === $epc_status ? ' is-warning' : ''; ?>"><?php echo 'paused' === $epc_status ? esc_html__( 'Paused', 'epasscard' ) : esc_html__( 'Running', 'epasscard' ); ?></span>
			</p>
			<div class="epc-existing__bar" role="progressbar" aria-label="<?php esc_attr_e( 'Progress', 'epasscard' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) EPC_Existing_Customers::percent( $job ) ); ?>">
				<span style="width: <?php echo esc_attr( (string) EPC_Existing_Customers::percent( $job ) ); ?>%"></span>
			</div>
			<p class="epc-existing__progress" data-epc-existing-text aria-live="polite"><?php echo esc_html( EPC_Existing_Customers::progress_text( $job ) ); ?></p>
			<p class="description">
				<?php
				if ( 'paused' === $epc_status ) {
					esc_html_e( 'Paused. Nothing more is created or emailed until you resume.', 'epasscard' );
				} else {
					esc_html_e( 'Runs in the background in small batches, so you can leave this page. Nobody gets a second card.', 'epasscard' );
				}
				?>
			</p>
			<div class="epc-existing__actions">
				<?php
				if ( 'paused' === $epc_status ) {
					$epc_control( 'resume', __( 'Resume', 'epasscard' ), 'button button-primary' );
				} else {
					$epc_control( 'pause', __( 'Pause', 'epasscard' ) );
				}
				$epc_control( 'stop', __( 'Stop', 'epasscard' ) );
				?>
			</div>
		</div>
	<?php elseif ( $epc_finished ) : ?>
		<?php
		$epc_results  = EPC_Existing_Customers::get_results();
		$epc_problems = array();
		foreach ( $epc_results as $epc_result ) {
			if ( in_array( (string) $epc_result[1], array( 'not_emailed', 'no_email', 'failed' ), true ) ) {
				$epc_problems[] = $epc_result;
			}
		}
		$epc_leads = array(
			'done'      => __( 'Finished', 'epasscard' ),
			'cancelled' => __( 'Stopped by you', 'epasscard' ),
			'stopped'   => __( 'Stopped early', 'epasscard' ),
		);
		?>
		<div class="epc-existing__result">
			<p class="epc-existing__run-title">
				<strong>
					<?php
					/* translators: 1: Finished / Stopped, 2: integration name */
					echo esc_html( sprintf( __( '%1$s: existing customers of %2$s', 'epasscard' ), $epc_leads[ $epc_status ], $epc_job_label ) );
					?>
				</strong>
			</p>
			<p><?php echo esc_html( EPC_Existing_Customers::progress_text( $job ) ); ?></p>
			<?php if ( 'stopped' === $epc_status && '' !== (string) $job['stop_reason'] ) : ?>
				<p class="epc-existing__stop"><strong><?php echo esc_html( (string) $job['stop_reason'] ); ?></strong></p>
			<?php endif; ?>
			<?php if ( ! empty( $epc_problems ) ) : ?>
				<table class="widefat striped epc-existing__problems">
					<caption class="screen-reader-text"><?php esc_html_e( 'Customers who did not get their card by email', 'epasscard' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Customer', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Detail', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $epc_problems, 0, 50 ) as $epc_problem ) : ?>
							<tr>
								<td><?php echo esc_html( $epc_customer( (string) $epc_problem[0], (int) $epc_problem[3] ) ); ?></td>
								<td><?php echo esc_html( EPC_Existing_Customers::outcome_label( (string) $epc_problem[1] ) ); ?></td>
								<td><?php echo esc_html( (string) $epc_problem[2] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $epc_problems ) > 50 ) : ?>
					<p class="description">
						<?php
						/* translators: %s: number of rows */
						echo esc_html( sprintf( __( 'Showing 50 of %s. The CSV has every record.', 'epasscard' ), number_format_i18n( count( $epc_problems ) ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<div class="epc-existing__actions">
				<?php if ( ! empty( $epc_results ) ) : ?>
					<form method="post" action="<?php echo esc_url( $epc_post_url ); ?>" class="epc-existing__inline-form">
						<input type="hidden" name="action" value="epc_existing_results" />
						<?php wp_nonce_field( 'epc_existing' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Download results (CSV)', 'epasscard' ); ?></button>
					</form>
				<?php endif; ?>
				<?php $epc_control( 'dismiss', __( 'Dismiss', 'epasscard' ), 'button-link' ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $epc_active && $epc_check_mod instanceof EPC_Module ) : ?>
		<?php
		$epc_will   = count( (array) $check['will'] );
		$epc_sample = EPC_Existing_Customers::sample_email( $check );
		$epc_limit  = 0;
		if ( null !== $left && $left < $epc_will ) {
			$epc_limit = $left;
		}
		$epc_upgrade = EPC_Template_Starter::upgrade_url();
		?>
		<div class="epc-existing__check">
			<p class="epc-existing__run-title">
				<strong>
					<?php
					/* translators: %s: integration name */
					echo esc_html( sprintf( __( '%s · review first', 'epasscard' ), $epc_check_mod->get_label() ) );
					?>
				</strong>
			</p>
			<p class="description">
				<?php
				/* translators: %s: who counts, e.g. "Members with an active membership level" */
				echo esc_html( sprintf( __( 'Counted: %s.', 'epasscard' ), $epc_check_mod->get_backfill_scope_label() ) );
				?>
				<?php esc_html_e( 'Nothing is created or emailed until you confirm. Emails go out from your site\'s own mail (WordPress mail or your SMTP plugin).', 'epasscard' ); ?>
			</p>
			<?php if ( 'woocommerce-loyalty' === $epc_check_mod->get_slug() ) : ?>
				<p class="description">
					<?php esc_html_e( 'Customers who bought before loyalty was on have no points yet, so they are not counted.', 'epasscard' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-woocommerce-loyalty#epc-tab-loyalty-history' ) ); ?>"><?php esc_html_e( 'Credit their past orders first', 'epasscard' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $check['truncated'] ) ) : ?>
				<p class="epc-existing__stop">
					<?php esc_html_e( 'The check stopped early because this integration has a lot of records. The counts cover the records checked; check again after this run for the rest.', 'epasscard' ); ?>
				</p>
			<?php endif; ?>

			<ul class="epc-existing__tiles">
				<li class="epc-existing__tile is-good">
					<span class="epc-existing__tile-label"><?php esc_html_e( 'Will get a card', 'epasscard' ); ?></span>
					<span class="epc-existing__tile-value"><?php echo esc_html( number_format_i18n( $epc_will ) ); ?></span>
					<span class="epc-existing__tile-note"><?php esc_html_e( 'Card made and emailed', 'epasscard' ); ?></span>
				</li>
				<li class="epc-existing__tile">
					<span class="epc-existing__tile-label"><?php esc_html_e( 'Already have one', 'epasscard' ); ?></span>
					<span class="epc-existing__tile-value"><?php echo esc_html( number_format_i18n( (int) $check['have'] ) ); ?></span>
					<span class="epc-existing__tile-note"><?php esc_html_e( 'Skipped', 'epasscard' ); ?></span>
				</li>
				<li class="epc-existing__tile">
					<span class="epc-existing__tile-label"><?php esc_html_e( 'No card by your settings', 'epasscard' ); ?></span>
					<span class="epc-existing__tile-value"><?php echo esc_html( number_format_i18n( (int) $check['excluded'] ) ); ?></span>
					<span class="epc-existing__tile-note">
						<?php
						$epc_items = array_keys( (array) $check['excluded_items'] );
						if ( empty( $epc_items ) ) {
							esc_html_e( 'Items set to No card', 'epasscard' );
						} else {
							$epc_more = count( $epc_items ) - 3;
							echo esc_html( implode( ', ', array_slice( $epc_items, 0, 3 ) ) );
							if ( $epc_more > 0 ) {
								/* translators: %d: number of further items */
								echo esc_html( sprintf( __( ' and %d more', 'epasscard' ), $epc_more ) );
							}
						}
						?>
					</span>
				</li>
				<li class="epc-existing__tile<?php echo (int) $check['no_email'] > 0 ? ' is-warning' : ''; ?>">
					<span class="epc-existing__tile-label"><?php esc_html_e( 'Can\'t send', 'epasscard' ); ?></span>
					<span class="epc-existing__tile-value"><?php echo esc_html( number_format_i18n( (int) $check['no_email'] ) ); ?></span>
					<span class="epc-existing__tile-note"><?php esc_html_e( 'No email address, no card made', 'epasscard' ); ?></span>
				</li>
			</ul>

			<?php if ( ! empty( $check['excluded_items'] ) ) : ?>
				<p class="description">
					<?php esc_html_e( '"No card by your settings" are on items without a design or set to No card.', 'epasscard' ); ?>
					<a href="<?php echo esc_url( EPC_Admin_Design::url_for_module( $epc_check_mod->get_slug() ) ); ?>"><?php esc_html_e( 'Change that in Design', 'epasscard' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $check['no_email_ids'] ) ) : ?>
				<details class="epc-existing__details">
					<summary><?php esc_html_e( 'Who can\'t be emailed', 'epasscard' ); ?></summary>
					<ul>
						<?php foreach ( (array) $check['no_email_ids'] as $epc_row ) : ?>
							<li><?php echo esc_html( $epc_customer( (string) $epc_row[0], (int) $epc_row[1] ) ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( (int) $check['no_email'] > count( (array) $check['no_email_ids'] ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: number of customers */
							echo esc_html( sprintf( __( 'Showing 50 of %s.', 'epasscard' ), number_format_i18n( (int) $check['no_email'] ) ) );
							?>
						</p>
					<?php endif; ?>
				</details>
			<?php endif; ?>

			<?php if ( null !== $epc_sample ) : ?>
				<details class="epc-existing__details epc-existing__email">
					<summary><?php esc_html_e( 'See the email first', 'epasscard' ); ?></summary>
					<dl>
						<dt><?php esc_html_e( 'To', 'epasscard' ); ?></dt>
						<dd><?php echo esc_html( $epc_sample['to'] ); ?></dd>
						<dt><?php esc_html_e( 'Subject', 'epasscard' ); ?></dt>
						<dd><?php echo esc_html( $epc_sample['subject'] ); ?></dd>
						<dt><?php esc_html_e( 'Message', 'epasscard' ); ?></dt>
						<dd><pre class="epc-existing__body"><?php echo esc_html( $epc_sample['body'] ); ?></pre></dd>
					</dl>
					<p class="description">
						<?php esc_html_e( 'This is the newest customer on the list. Each customer gets the link to their own card.', 'epasscard' ); ?>
						<a href="<?php echo esc_url( $epc_settings ); ?>"><?php esc_html_e( 'Edit the email', 'epasscard' ); ?></a>
					</p>
				</details>
			<?php endif; ?>

			<?php if ( $epc_will > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( $epc_post_url ); ?>" class="epc-existing__confirm">
					<input type="hidden" name="action" value="epc_existing_start" />
					<input type="hidden" name="module" value="<?php echo esc_attr( $epc_check_mod->get_slug() ); ?>" />
					<input type="hidden" name="limit" value="<?php echo esc_attr( (string) $epc_limit ); ?>" />
					<?php wp_nonce_field( 'epc_existing' ); ?>
					<p class="epc-existing__plan">
						<?php
						if ( null === $left ) {
							esc_html_e( 'Each card counts toward your EpassCard plan.', 'epasscard' );
						} elseif ( $left >= $epc_will ) {
							/* translators: %s: number of cards */
							echo esc_html( sprintf( _n( 'Your plan has %s card left, counting the cards this site has made: enough for everyone here.', 'Your plan has %s cards left, counting the cards this site has made: enough for everyone here.', $left, 'epasscard' ), number_format_i18n( $left ) ) );
						} elseif ( $left > 0 ) {
							/* translators: %s: number of cards */
							echo esc_html( sprintf( _n( 'Your plan has %s card left, counting the cards this site has made.', 'Your plan has %s cards left, counting the cards this site has made.', $left, 'epasscard' ), number_format_i18n( $left ) ) ) . ' ';
							/* translators: %s: number of customers in the check */
							echo esc_html( sprintf( __( 'Start with the newest customers now, as many as your plan allows, or upgrade and give cards to all %s.', 'epasscard' ), number_format_i18n( $epc_will ) ) );
						} else {
							esc_html_e( 'Your plan has no cards left. Upgrade to give cards to these customers.', 'epasscard' );
						}
						?>
						<?php if ( null === $left || $left > 0 ) : ?>
							<?php esc_html_e( 'It runs in the background in small batches with progress; you can pause it, and nobody gets a second card.', 'epasscard' ); ?>
						<?php endif; ?>
					</p>
					<?php if ( null === $left || $left > 0 ) : ?>
						<?php $epc_count = $epc_limit > 0 ? $epc_limit : $epc_will; ?>
						<p>
							<label>
								<input type="checkbox" name="confirm" value="1" required />
								<?php
								/* translators: %s: number of customers */
								echo esc_html( sprintf( _n( 'Create %s card and email it to the customer.', 'Create %s cards and email each customer their card.', $epc_count, 'epasscard' ), number_format_i18n( $epc_count ) ) );
								?>
							</label>
						</p>
					<?php endif; ?>
					<div class="epc-existing__actions">
						<?php if ( null === $left || $left > 0 ) : ?>
							<button type="submit" class="button button-primary">
								<?php
								if ( $epc_limit > 0 ) {
									echo esc_html(
										1 === $epc_limit
											? __( 'Give a card to the newest customer', 'epasscard' )
											/* translators: %s: number of customers */
											: sprintf( _n( 'Give cards to the %s newest customer', 'Give cards to the %s newest customers', $epc_limit, 'epasscard' ), number_format_i18n( $epc_limit ) )
									);
								} else {
									/* translators: %s: number of customers */
									echo esc_html( sprintf( _n( 'Give a card to %s customer', 'Give cards to all %s', $epc_will, 'epasscard' ), number_format_i18n( $epc_will ) ) );
								}
								?>
							</button>
						<?php endif; ?>
						<?php if ( null !== $left && $left < $epc_will ) : ?>
							<a class="button" href="<?php echo esc_url( $epc_upgrade ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade plan', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
						<?php endif; ?>
					</div>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Nobody here needs a card right now.', 'epasscard' ); ?></p>
			<?php endif; ?>
			<div class="epc-existing__actions">
				<?php $epc_control( 'discard', __( 'Discard this check', 'epasscard' ), 'button-link' ); ?>
			</div>
		</div>
	<?php elseif ( ! $epc_active ) : ?>
		<form method="post" action="<?php echo esc_url( $epc_post_url ); ?>" class="epc-existing__start" data-epc-existing-check>
			<input type="hidden" name="action" value="epc_existing_check" />
			<?php wp_nonce_field( 'epc_existing' ); ?>
			<p class="description"><?php esc_html_e( 'Customers who joined before EpassCard have no card yet. Check who would get one first: nothing is created or emailed until you confirm.', 'epasscard' ); ?></p>
			<p>
				<?php if ( count( $modules ) > 1 ) : ?>
					<label for="epc-existing-module"><?php esc_html_e( 'Integration', 'epasscard' ); ?></label>
					<select id="epc-existing-module" name="module">
						<?php foreach ( $modules as $epc_slug => $epc_module ) : ?>
							<option value="<?php echo esc_attr( $epc_slug ); ?>" <?php selected( $selected, $epc_slug ); ?>><?php echo esc_html( $epc_module->get_label() . ' · ' . $epc_module->get_backfill_scope_label() ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="hidden" name="module" value="<?php echo esc_attr( $selected ); ?>" />
					<span><?php echo esc_html( $modules[ $selected ]->get_label() . ' · ' . $modules[ $selected ]->get_backfill_scope_label() ); ?></span>
				<?php endif; ?>
				<button type="submit" class="button button-primary" data-checking="<?php esc_attr_e( 'Checking…', 'epasscard' ); ?>"><?php esc_html_e( 'Check customers', 'epasscard' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</section>
