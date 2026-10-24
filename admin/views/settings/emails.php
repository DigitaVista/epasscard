<?php
/**
 * Settings → Emails to customers (pass link email).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
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
			<?php if ( EPC_Module_Settings::is_enabled( 'pw-gift-cards' ) || EPC_Module_Settings::is_enabled( 'yith-gift-cards' ) ) : ?>
				<p>
					<label class="epc-toggle">
						<input type="checkbox" name="epc_email_gift_recipient" value="1" <?php checked( ! empty( $email_settings['gift_recipient'] ) ); ?> />
						<span class="epc-toggle__track" aria-hidden="true"></span>
						<span class="epc-toggle__label"><?php esc_html_e( 'Gift cards: email the card to the gift recipient', 'epasscard' ); ?></span>
					</label>
					<span class="description epc-toggle__help"><?php esc_html_e( 'Uses the recipient email on the gift card. When a gift card has none, the card goes to the buyer as before.', 'epasscard' ); ?></span>
				</p>
			<?php else : ?>
				<input type="hidden" name="epc_email_gift_recipient" value="<?php echo ! empty( $email_settings['gift_recipient'] ) ? '1' : ''; ?>" />
			<?php endif; ?>

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
				<code>{pass_name}</code>,
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
