<?php
/**
 * Connection admin view.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

EPC_Admin_Shell::render_open(
	array(
		'context' => 'connection',
		'title'   => __( 'EpassCard Dashboard', 'epasscard' ),
	)
);
?>
<div class="wrap epc-wrap">
	<div id="epc-section-overview" class="epc-section epc-section--overview">
		<div class="epc-overview-hero">
			<div class="epc-page-header">
				<h1 class="epc-page-title"><?php esc_html_e( 'EpassCard Connection', 'epasscard' ); ?></h1>
				<p class="description">
					<?php
					printf(
						/* translators: %s: EpassCard app URL */
						esc_html__( 'Connect your site to EpassCard to issue wallet passes. Get an API key from %s or sign in below to generate one.', 'epasscard' ),
						'<a href="https://app.epasscard.com" target="_blank" rel="noopener noreferrer">app.epasscard.com</a>'
					);
					?>
				</p>

				
		<?php if ( $connected ) : ?>
			<div class="epc-connection-alert epc-connection-alert--connected">
				<p><strong><?php esc_html_e( 'Connected', 'epasscard' ); ?></strong>
				<?php if ( '' !== $email ) : ?>
					— <?php echo esc_html( $email ); ?>
				<?php endif; ?>
				<?php
				$package_name = isset( $package['package_name'] ) ? (string) $package['package_name'] : '';
				$pass_limit   = isset( $package['num_of_pass'] ) ? (int) $package['num_of_pass'] : 0;
				if ( '' !== $package_name ) :
					?>
					<br /><span class="description">
						<?php
						if ( $pass_limit > 0 ) {
							printf(
								/* translators: 1: plan name, 2: number of passes */
								esc_html__( 'Plan: %1$s (%2$s passes)', 'epasscard' ),
								esc_html( $package_name ),
								esc_html( number_format_i18n( $pass_limit ) )
							);
						} else {
							printf(
								/* translators: %s: plan name */
								esc_html__( 'Plan: %s', 'epasscard' ),
								esc_html( $package_name )
							);
						}
						?>
					</span>
				<?php endif; ?>
				<?php if ( '' !== $expiry ) : ?>
					<br /><span class="description"><?php esc_html_e( 'Key expires:', 'epasscard' ); ?> <?php echo esc_html( $expiry ); ?></span>
				<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
			</div>
			<div class="epc-overview-video">
				<div class="epc-overview-video__frame">
					<iframe
						src="<?php echo esc_url( 'https://www.youtube-nocookie.com/embed/EYD18OHWUhM' ); ?>"
						title="<?php echo esc_attr__( 'EpassCard connection overview', 'epasscard' ); ?>"
						allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
						allowfullscreen
						loading="lazy"
						referrerpolicy="strict-origin-when-cross-origin"
					></iframe>
				</div>
			</div>
		</div>

		<div id="epc-connection-status" class="epc-notice" aria-live="polite"></div>

	</div>

	<div id="epc-section-connect" class="epc-section epc-section--connect">
		<?php if ( $connected ) : ?>
			<div class="epc-card epc-card--success" style="margin-bottom:16px;">
				<h2><?php esc_html_e( 'Account connected', 'epasscard' ); ?></h2>

				<details class="epc-developer-api">
					<summary><?php esc_html_e( 'Developer: X-Api-Key for custom endpoints', 'epasscard' ); ?></summary>
					<div class="epc-developer-api__body">
						<p class="description">
							<?php esc_html_e( 'Connection data is stored in wp_options. The API key is encrypted in the database; use epc_get_api_key() to get the decrypted X-Api-Key value.', 'epasscard' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Option name:', 'epasscard' ); ?></strong>
							<code><?php echo esc_html( EPC_Connection::OPTION ); ?></code>
						</p>
						<div class="epc-code-block">
							<pre id="epc-developer-snippet" class="epc-code-block__pre"><?php echo esc_html( EPC_Connection::get_developer_snippet() ); ?></pre>
							<button type="button" class="button button-small epc-copy-snippet" data-copy-target="epc-developer-snippet">
								<?php esc_html_e( 'Copy code', 'epasscard' ); ?>
							</button>
						</div>
					</div>
				</details>

				<p style="margin-bottom:0;">
					<button type="button" class="button button-secondary" id="epc-disconnect">
						<?php esc_html_e( 'Disconnect', 'epasscard' ); ?>
					</button>
				</p>
			</div>
		<?php endif; ?>

		<div class="epc-grid">
			<div class="epc-card">
				<h2><?php esc_html_e( 'Use API key', 'epasscard' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste an existing EpassCard API key.', 'epasscard' ); ?></p>
				<p>
					<label for="epc-api-key"><strong><?php esc_html_e( 'API key', 'epasscard' ); ?></strong></label><br />
					<input type="password" id="epc-api-key" class="regular-text" autocomplete="off" <?php disabled( $connected ); ?> />
				</p>
				<p style="margin-bottom:0;">
					<button type="button" class="button button-primary" id="epc-connect-key" <?php disabled( $connected ); ?>>
						<?php esc_html_e( 'Connect', 'epasscard' ); ?>
					</button>
					<a class="button button-secondary" href="<?php echo esc_url( 'https://app.epasscard.com/api-keys' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get an API key', 'epasscard' ); ?>
					</a>
				</p>
			</div>

			<div class="epc-card epc-card--tabs">
				<div class="epc-tabs" data-epc-tabs>
					<div class="epc-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Account', 'epasscard' ); ?>">
						<button
							type="button"
							class="epc-tabs__tab is-active"
							role="tab"
							id="epc-tab-sign-in"
							aria-controls="epc-panel-sign-in"
							aria-selected="true"
							tabindex="0"
						>
							<?php esc_html_e( 'Sign in', 'epasscard' ); ?>
						</button>
						<button
							type="button"
							class="epc-tabs__tab"
							role="tab"
							id="epc-tab-sign-up"
							aria-controls="epc-panel-sign-up"
							aria-selected="false"
							tabindex="-1"
						>
							<?php esc_html_e( 'Sign up', 'epasscard' ); ?>
						</button>
					</div>

					<div class="epc-tabs__panels">
						<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-sign-in" aria-labelledby="epc-tab-sign-in">
							<p class="description"><?php esc_html_e( 'Use your EpassCard email and password to generate and store an API key.', 'epasscard' ); ?></p>
							<p>
								<label for="epc-signin-email"><strong><?php esc_html_e( 'Email', 'epasscard' ); ?></strong></label><br />
								<input type="email" id="epc-signin-email" class="regular-text" autocomplete="username" <?php disabled( $connected ); ?> />
							</p>
							<p>
								<label for="epc-signin-password"><strong><?php esc_html_e( 'Password', 'epasscard' ); ?></strong></label><br />
								<input type="password" id="epc-signin-password" class="regular-text" autocomplete="current-password" <?php disabled( $connected ); ?> />
							</p>
							<p style="margin-bottom:0;">
								<button type="button" class="button button-primary" id="epc-connect-credentials" <?php disabled( $connected ); ?>>
									<?php esc_html_e( 'Generate & connect', 'epasscard' ); ?>
								</button>
							</p>
						</div>

						<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-sign-up" aria-labelledby="epc-tab-sign-up" hidden>
							<p class="description"><?php esc_html_e( 'Create an EpassCard account. Your password will be emailed to you, and this site will connect with the new API key.', 'epasscard' ); ?></p>
							<form id="epc-signup-form" class="epc-signup-form" method="post" action="" novalidate>
								<p>
									<label for="epc-signup-name">
										<strong><?php esc_html_e( 'Name / Business Name', 'epasscard' ); ?></strong>
										<span class="epc-required" aria-hidden="true">*</span>
									</label><br />
									<input
										type="text"
										id="epc-signup-name"
										class="regular-text"
										name="epc_signup_name"
										autocomplete="name"
										required
										aria-required="true"
										aria-describedby="epc-signup-name-error"
										minlength="2"
										maxlength="255"
										<?php disabled( $connected ); ?>
									/>
									<span id="epc-signup-name-error" class="epc-field-error" hidden></span>
								</p>
								<p>
									<label for="epc-signup-email">
										<strong><?php esc_html_e( 'Email', 'epasscard' ); ?></strong>
										<span class="epc-required" aria-hidden="true">*</span>
									</label><br />
									<input
										type="email"
										id="epc-signup-email"
										class="regular-text"
										name="epc_signup_email"
										autocomplete="email"
										required
										aria-required="true"
										aria-describedby="epc-signup-email-error"
										maxlength="254"
										<?php disabled( $connected ); ?>
									/>
									<span id="epc-signup-email-error" class="epc-field-error" hidden></span>
								</p>
								<p style="margin-bottom:0;">
									<button type="submit" class="button button-primary" id="epc-signup-now" <?php disabled( $connected ); ?>>
										<?php esc_html_e( 'Sign up & Connect Now', 'epasscard' ); ?>
									</button>
								</p>
							</form>
						</div>
					</div>
				</div>
			</div>

		</div>
	</div>

	<div id="epc-section-integrations" class="epc-section epc-section--integrations">
		<div class="epc-card epc-card--modules">
			<h2><?php esc_html_e( 'Integrations', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enable the membership or subscription plugins you want to connect to EpassCard. Only enabled integrations appear in the admin menu.', 'epasscard' ); ?>
			</p>

			<?php if ( empty( $modules ) ) : ?>
				<p><?php esc_html_e( 'No integration modules are available.', 'epasscard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped epc-modules-table">
					<thead>
						<tr>
							<th scope="col" class="check-column"><?php esc_html_e( 'Enable', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Integration', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Required plugin', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $modules as $slug => $module ) : ?>
							<?php
							$available  = $module->is_available();
							$is_enabled = in_array( $slug, $enabled, true );
							?>
							<tr>
								<th scope="row" class="check-column">
									<input
										type="checkbox"
										name="epc_enabled_modules[]"
										value="<?php echo esc_attr( $slug ); ?>"
										id="epc-module-<?php echo esc_attr( $slug ); ?>"
										<?php checked( $is_enabled ); ?>
										<?php disabled( ! $available ); ?>
									/>
								</th>
								<td>
									<label for="epc-module-<?php echo esc_attr( $slug ); ?>">
										<strong><?php echo esc_html( $module->get_label() ); ?></strong>
									</label>
								</td>
								<td><?php echo esc_html( $module->get_dependency_label() ); ?></td>
								<td>
									<?php if ( $available ) : ?>
										<span class="epc-badge epc-badge--ok"><?php esc_html_e( 'Installed', 'epasscard' ); ?></span>
									<?php else : ?>
										<span class="epc-badge epc-badge--muted"><?php esc_html_e( 'Not installed', 'epasscard' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-bottom:0;">
					<button type="button" class="button button-secondary" id="epc-save-modules">
						<?php esc_html_e( 'Save integrations', 'epasscard' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $connected ) : ?>
		<div id="epc-section-email" class="epc-section epc-section--email">
			<div class="epc-card epc-card--email">
				<h2><?php esc_html_e( 'Pass link email', 'epasscard' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure how wallet pass links are emailed to members. You can also send links manually from each integration’s Issued passes list.', 'epasscard' ); ?>
				</p>

				<form class="epc-ajax-form" data-epc-action="epc_save_pass_email_settings" method="post" action="">

					<p>
						<label class="epc-toggle">
							<input type="checkbox" name="epc_email_auto_on_create" value="1" <?php checked( ! empty( $email_settings['auto_on_create'] ) ); ?> />
							<span class="epc-toggle__track" aria-hidden="true"></span>
							<span class="epc-toggle__label"><?php esc_html_e( 'Automatically email pass link when a new pass is created', 'epasscard' ); ?></span>
						</label>
					</p>
					<p>
						<label class="epc-toggle">
							<input type="checkbox" name="epc_email_include_on_wc_order" value="1" <?php checked( ! empty( $email_settings['include_on_wc_order'] ) ); ?> />
							<span class="epc-toggle__track" aria-hidden="true"></span>
							<span class="epc-toggle__label"><?php esc_html_e( 'Include pass links in WooCommerce order emails', 'epasscard' ); ?></span>
						</label>
					</p>

					<p>
						<label for="epc-email-subject"><strong><?php esc_html_e( 'Email subject', 'epasscard' ); ?></strong></label><br />
						<input type="text" class="large-text" id="epc-email-subject" name="epc_email_subject" value="<?php echo esc_attr( (string) $email_settings['subject'] ); ?>" />
					</p>
					<p>
						<label for="epc-email-body"><strong><?php esc_html_e( 'Email body (plain text)', 'epasscard' ); ?></strong></label><br />
						<textarea class="large-text" rows="8" id="epc-email-body" name="epc_email_body"><?php echo esc_textarea( (string) $email_settings['body'] ); ?></textarea>
					</p>
					<p class="description">
						<?php esc_html_e( 'Placeholders:', 'epasscard' ); ?>
						<code>{pass_link}</code>,
						<code>{user_first_name}</code>,
						<code>{user_last_name}</code>,
						<code>{user_display_name}</code>,
						<code>{user_email}</code>,
						<code>{membership_title}</code>,
						<code>{module_label}</code>,
						<code>{site_name}</code>
					</p>
					<p style="margin-bottom:0;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save email settings', 'epasscard' ); ?></button>
					</p>
				</form>

				<p class="description" style="margin-top:16px;margin-bottom:0;">
					<?php esc_html_e( 'Members can also view passes in My Account (WooCommerce), MemberPress account, or via the [epc_my_passes] shortcode.', 'epasscard' ); ?>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<div id="epc-section-our-products" class="epc-section epc-section--our-products">
		<div class="epc-card epc-card--our-products">
			<h2><?php esc_html_e( 'Our Products', 'epasscard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Discover more plugins from WebCartisan to grow your WordPress site.', 'epasscard' ); ?></p>
			<div class="epc-our-products-wrap">
				<?php include EPC_PLUGIN_DIR . 'admin/views/our-products.php'; ?>
			</div>
		</div>
	</div>
</div>
<?php
EPC_Admin_Shell::render_close();
