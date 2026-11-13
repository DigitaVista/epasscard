<?php
/**
 * Settings → Reminders & push: the reminder and push message settings of one integration at a time.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epc_push_modules = EPC_Admin_Settings::push_modules();
$epc_push_module  = EPC_Admin_Settings::current_push_module();

// The kind of card each integration gives, for its colour (the same colours as Setup).
$epc_push_jobs = array();
foreach ( EPC_Jobs::get_jobs() as $epc_job_slug => $epc_job ) {
	foreach ( (array) $epc_job['modules'] as $epc_job_module ) {
		if ( ! isset( $epc_push_jobs[ (string) $epc_job_module ] ) ) {
			$epc_push_jobs[ (string) $epc_job_module ] = (string) $epc_job_slug;
		}
	}
}
?>
<p class="epc-lead"><?php esc_html_e( 'Messages that appear on the customer\'s lock screen through their wallet card: reminders before a card expires or an event starts.', 'epasscard' ); ?></p>

<?php if ( EPC_Admin_App::loyalty_enabled() ) : ?>
	<p class="epc-push-note">
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
		<span>
			<?php esc_html_e( 'Loyalty emails and push messages are on Loyalty customers, Rules & rewards, in the Notifications tab.', 'epasscard' ); ?>
			<a href="<?php echo esc_url( EPC_Admin_App::url( 'loyalty-rules' ) . '#epc-tab-loyalty-notify' ); ?>"><?php esc_html_e( 'Open', 'epasscard' ); ?></a>
		</span>
	</p>
<?php endif; ?>

<?php if ( null === $epc_push_module ) : ?>
	<div class="epc-card">
		<p style="margin:0;"><?php esc_html_e( 'None of the integrations you use has reminders. Membership, subscription and event integrations do.', 'epasscard' ); ?></p>
	</div>
<?php else : ?>
	<?php if ( count( $epc_push_modules ) > 1 ) : ?>
		<nav class="epc-push-picker" aria-label="<?php esc_attr_e( 'Integration', 'epasscard' ); ?>">
			<ul>
				<?php foreach ( $epc_push_modules as $epc_slug => $epc_module ) : ?>
					<?php $epc_job_class = isset( $epc_push_jobs[ $epc_slug ] ) ? ' epc-push-picker__item--' . sanitize_html_class( $epc_push_jobs[ $epc_slug ] ) : ''; ?>
					<li>
						<a class="<?php echo esc_attr( 'epc-push-picker__item' . $epc_job_class . ( $epc_module === $epc_push_module ? ' is-selected' : '' ) ); ?>" href="<?php echo esc_url( EPC_Admin_App::url( 'settings', array( 'tab' => 'push', 'module' => $epc_slug ) ) ); ?>"<?php echo $epc_module === $epc_push_module ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $epc_module->get_label() ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
	<div class="epc-settings__push">
		<?php $epc_push_module->render_settings_part( 'push' ); ?>
	</div>
<?php endif; ?>
