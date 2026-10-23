<?php
/**
 * Connect forms: API key, sign in, sign up. Used on Home (step 1) and Settings → Account & plan.
 *
 * Script: admin/js/connection.js.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="epc-connection-status" class="epc-notice" aria-live="polite"></div>
<div class="epc-grid">
	<div class="epc-card">
		<h2><?php esc_html_e( 'Use API key', 'epasscard' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Paste an existing EpassCard API key.', 'epasscard' ); ?></p>
		<p>
			<label for="epc-api-key"><strong><?php esc_html_e( 'API key', 'epasscard' ); ?></strong></label><br />
			<input type="password" id="epc-api-key" class="regular-text" autocomplete="off" />
		</p>
		<p style="margin-bottom:0;">
			<button type="button" class="button button-primary" id="epc-connect-key">
				<?php esc_html_e( 'Connect', 'epasscard' ); ?>
			</button>
			<a class="button button-secondary" href="<?php echo esc_url( 'https://app.epasscard.com/api-keys' ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Get an API key', 'epasscard' ); ?>
			</a>
		</p>
		<p class="description epc-consent-notice"><?php echo wp_kses( epc_consent_notice_html( 'api_key' ), epc_consent_notice_allowed_html() ); ?></p>
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
						<input type="email" id="epc-signin-email" class="regular-text" autocomplete="username" />
					</p>
					<p>
						<label for="epc-signin-password"><strong><?php esc_html_e( 'Password', 'epasscard' ); ?></strong></label><br />
						<input type="password" id="epc-signin-password" class="regular-text" autocomplete="current-password" />
					</p>
					<p style="margin-bottom:0;">
						<button type="button" class="button button-primary" id="epc-connect-credentials">
							<?php esc_html_e( 'Generate & connect', 'epasscard' ); ?>
						</button>
					</p>
					<p class="description epc-consent-notice"><?php echo wp_kses( epc_consent_notice_html( 'sign_in' ), epc_consent_notice_allowed_html() ); ?></p>
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
							/>
							<span id="epc-signup-email-error" class="epc-field-error" hidden></span>
						</p>
						<p style="margin-bottom:0;">
							<button type="submit" class="button button-primary" id="epc-signup-now">
								<?php esc_html_e( 'Sign up & Connect Now', 'epasscard' ); ?>
							</button>
						</p>
						<p class="description epc-consent-notice"><?php echo wp_kses( epc_consent_notice_html( 'sign_up' ), epc_consent_notice_allowed_html() ); ?></p>
					</form>
				</div>
			</div>
		</div>
	</div>

</div>
