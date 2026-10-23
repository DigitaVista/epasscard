<?php
/**
 * Settings → Account & plan.
 *
 * Variables: $connected, $email, $package, $expiry, $meter.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $connected ) {
	?>
	<p class="epc-lead"><?php esc_html_e( 'Connect this site to your EpassCard account. EpassCard signs and delivers the wallet cards; your customer data stays in WordPress.', 'epasscard' ); ?></p>
	<?php
	include EPC_PLUGIN_DIR . 'admin/views/settings/connect-forms.php';
	return;
}

$epc_plan_name = (string) ( $package['package_name'] ?? '' );
$epc_limit     = (int) $meter['limit'];
$epc_used      = (int) $meter['used'];
$epc_percent   = $epc_limit > 0 ? min( 100, (int) round( $epc_used * 100 / $epc_limit ) ) : 0;
?>
<div id="epc-connection-status" class="epc-notice" aria-live="polite"></div>
<div class="epc-card">
	<h2><?php esc_html_e( 'Plan', 'epasscard' ); ?></h2>
	<p class="epc-plan-line">
		<strong><?php echo esc_html( '' !== $epc_plan_name ? $epc_plan_name : __( 'Unknown plan', 'epasscard' ) ); ?></strong>
		<?php if ( $epc_limit > 0 ) : ?>
			<?php
			/* translators: %s: number of cards included in the plan */
			echo esc_html( sprintf( __( '· %s active cards', 'epasscard' ), number_format_i18n( $epc_limit ) ) );
			?>
		<?php endif; ?>
	</p>
	<?php if ( $epc_limit > 0 ) : ?>
		<div class="epc-bar<?php echo $meter['warn'] ? ' is-warning' : ''; ?>" role="img" aria-label="
			<?php
			/* translators: 1: cards used, 2: card limit */
			echo esc_attr( sprintf( __( '%1$s of %2$s cards used', 'epasscard' ), number_format_i18n( $epc_used ), number_format_i18n( $epc_limit ) ) );
			?>
		"><span style="width:<?php echo esc_attr( (string) $epc_percent ); ?>%"></span></div>
		<p class="description">
			<?php
			/* translators: 1: cards used, 2: card limit */
			echo esc_html( sprintf( __( '%1$s of %2$s cards used by this site.', 'epasscard' ), number_format_i18n( $epc_used ), number_format_i18n( $epc_limit ) ) );
			?>
			<?php esc_html_e( 'Only active cards issued from this site are counted here, not test cards. Cards made on other sites or in the EpassCard app count toward the same plan but are not visible to this site.', 'epasscard' ); ?>
		</p>
	<?php endif; ?>
	<p style="margin-bottom:0;">
		<a class="button button-primary" href="<?php echo esc_url( 'https://app.epasscard.com' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Change plan on EpassCard', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
	</p>
</div>

<div class="epc-card">
	<h2><?php esc_html_e( 'Connection', 'epasscard' ); ?></h2>
	<dl class="epc-facts">
		<?php if ( '' !== $email ) : ?>
			<dt><?php esc_html_e( 'Account', 'epasscard' ); ?></dt>
			<dd><?php echo esc_html( $email ); ?></dd>
		<?php endif; ?>
		<?php if ( '' !== $expiry ) : ?>
			<dt><?php esc_html_e( 'API key valid until', 'epasscard' ); ?></dt>
			<dd><?php echo esc_html( $expiry ); ?> <span class="description"><?php esc_html_e( '(extended automatically before then)', 'epasscard' ); ?></span></dd>
		<?php endif; ?>
	</dl>
	<p class="description"><?php esc_html_e( 'The plan shown here is the one EpassCard reported when this site connected.', 'epasscard' ); ?></p>
	<p style="margin-bottom:0;">
		<button type="button" class="button button-secondary" id="epc-disconnect"><?php esc_html_e( 'Disconnect', 'epasscard' ); ?></button>
	</p>
</div>
