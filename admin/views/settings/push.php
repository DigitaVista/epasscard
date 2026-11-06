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
?>
<p class="epc-lead"><?php esc_html_e( 'Messages that appear on the customer\'s lock screen through their wallet card: reminders before a card expires or an event starts.', 'epasscard' ); ?></p>

<?php if ( EPC_Admin_App::loyalty_enabled() ) : ?>
	<p class="description">
		<?php esc_html_e( 'Loyalty emails and push messages are on Customers, Rules & rewards, in the Notifications tab.', 'epasscard' ); ?>
		<a href="<?php echo esc_url( EPC_Admin_App::url( 'loyalty-rules' ) . '#epc-tab-loyalty-notify' ); ?>"><?php esc_html_e( 'Open', 'epasscard' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( null === $epc_push_module ) : ?>
	<div class="epc-card">
		<p style="margin:0;"><?php esc_html_e( 'None of the integrations you use has reminders. Membership, subscription and event integrations do.', 'epasscard' ); ?></p>
	</div>
<?php else : ?>
	<?php if ( count( $epc_push_modules ) > 1 ) : ?>
		<nav aria-label="<?php esc_attr_e( 'Integration', 'epasscard' ); ?>">
			<ul class="epc-choices">
				<?php foreach ( $epc_push_modules as $epc_slug => $epc_module ) : ?>
					<li>
						<a class="epc-choice<?php echo $epc_module === $epc_push_module ? ' is-selected' : ''; ?>" href="<?php echo esc_url( EPC_Admin_App::url( 'settings', array( 'tab' => 'push', 'module' => $epc_slug ) ) ); ?>"<?php echo $epc_module === $epc_push_module ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $epc_module->get_label() ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
	<div class="epc-settings__push">
		<?php $epc_push_module->render_settings_part( 'push' ); ?>
	</div>
<?php endif; ?>
