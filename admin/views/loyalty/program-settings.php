<?php
/**
 * Non-technical loyalty program settings GUI.
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $program
 * @var array<string, mixed> $email
 * @var array<int, array<string, mixed>> $rules
 * @var array<int, array<string, mixed>> $tiers
 * @var array<int, array<string, mixed>> $milestones
 * @var array<string, mixed> $redemption
 * @var array<string, array<string, mixed>> $notifications
 * @var array<string, string> $order_statuses
 * @var array<int, string> $product_categories
 * @var array<string, string> $role_choices
 * @var array<string, string> $notification_types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

$reward_types = array(
	'bonus_points'      => __( 'Bonus points', 'epasscard' ),
	'fixed_coupon'      => __( 'Fixed amount coupon', 'epasscard' ),
	'percentage_coupon' => __( 'Percentage coupon', 'epasscard' ),
	'free_shipping'     => __( 'Free shipping', 'epasscard' ),
	'free_product'      => __( 'Free product', 'epasscard' ),
);

$refund_policies = array(
	'proportional' => __( 'Restore points in proportion to the refund', 'epasscard' ),
	'full'         => __( 'Restore all redeemed points on any refund', 'epasscard' ),
	'none'         => __( 'Do not restore points on refunds', 'epasscard' ),
);

/**
 * Render a category multiselect bound to a data-field.
 *
 * @param string             $data_field Field key.
 * @param array<int, int>    $selected Selected IDs.
 * @param array<int, string> $choices Choices.
 * @param string             $placeholder Placeholder.
 * @return void
 */
$epc_loyalty_render_cats = static function ( $data_field, array $selected, array $choices, $placeholder ) {
	?>
	<select data-field="<?php echo esc_attr( $data_field ); ?>" class="wc-enhanced-select" multiple="multiple" style="width:100%;" data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
		<?php foreach ( $choices as $term_id => $term_name ) : ?>
			<option value="<?php echo esc_attr( (string) (int) $term_id ); ?>" <?php selected( in_array( (int) $term_id, $selected, true ) ); ?>>
				<?php echo esc_html( $term_name ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
};
?>
<div id="epc-section-loyalty-program" class="epc-section">
	<div class="epc-page-header">
		<h2 class="epc-page-title"><?php esc_html_e( 'Loyalty rules and rewards', 'epasscard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Set how customers earn points, unlock tiers and rewards, redeem at checkout, and get notified. No coding required.', 'epasscard' ); ?>
		</p>
	</div>

	<form id="epc-loyalty-program-form" class="epc-card epc-loyalty-program-form" method="post" action="">
		<p class="epc-form-notice" aria-live="polite"></p>

		<div class="epc-tabs epc-loyalty-program-tabs" data-epc-tabs>
			<div class="epc-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Loyalty program sections', 'epasscard' ); ?>">
				<button type="button" class="epc-tabs__tab is-active" role="tab" id="epc-tab-loyalty-basics" aria-controls="epc-panel-loyalty-basics" aria-selected="true" tabindex="0"><?php esc_html_e( 'Basics', 'epasscard' ); ?></button>
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-earning" aria-controls="epc-panel-loyalty-earning" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Earning', 'epasscard' ); ?></button>
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-tiers" aria-controls="epc-panel-loyalty-tiers" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Tiers', 'epasscard' ); ?></button>
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-rewards" aria-controls="epc-panel-loyalty-rewards" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Rewards', 'epasscard' ); ?></button>
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-redeem" aria-controls="epc-panel-loyalty-redeem" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Checkout redeem', 'epasscard' ); ?></button>
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-notify" aria-controls="epc-panel-loyalty-notify" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Notifications', 'epasscard' ); ?></button>
			</div>

			<div class="epc-tabs__panels">
				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-basics" aria-labelledby="epc-tab-loyalty-basics">
					<p class="description"><?php esc_html_e( 'Choose when orders earn points, how points are rounded, and whether loyalty passes appear in order emails.', 'epasscard' ); ?></p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="epc-loyalty-statuses"><?php esc_html_e( 'Orders that earn points', 'epasscard' ); ?></label></th>
								<td>
									<select id="epc-loyalty-statuses" name="qualifying_statuses" class="wc-enhanced-select epc-loyalty-status-multiselect" multiple="multiple" style="width:100%; max-width:420px;" data-placeholder="<?php esc_attr_e( 'Select order statuses…', 'epasscard' ); ?>">
										<?php foreach ( $order_statuses as $status_slug => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( in_array( $status_slug, $program['qualifying_statuses'], true ) ); ?>><?php echo esc_html( $status_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Typical choices: Processing and Completed.', 'epasscard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="epc-loyalty-rounding"><?php esc_html_e( 'Point rounding', 'epasscard' ); ?></label></th>
								<td>
									<select id="epc-loyalty-rounding" name="rounding">
										<option value="floor" <?php selected( $program['rounding'], 'floor' ); ?>><?php esc_html_e( 'Always round down (safer for you)', 'epasscard' ); ?></option>
										<option value="round" <?php selected( $program['rounding'], 'round' ); ?>><?php esc_html_e( 'Round to nearest whole point', 'epasscard' ); ?></option>
										<option value="ceil" <?php selected( $program['rounding'], 'ceil' ); ?>><?php esc_html_e( 'Always round up (more generous)', 'epasscard' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Order emails', 'epasscard' ); ?></th>
								<td>
									<label class="epc-loyalty-check">
										<input type="checkbox" name="include_pass_on_order_emails" value="1" <?php checked( ! empty( $email['include_pass_on_order_emails'] ) ); ?> />
										<?php esc_html_e( 'Add the customer’s loyalty pass link to order emails when the order used loyalty', 'epasscard' ); ?>
									</label>
									<label class="epc-loyalty-check">
										<input type="checkbox" name="ensure_pass_before_order_emails" value="1" <?php checked( ! empty( $email['ensure_pass_before_order_emails'] ) ); ?> />
										<?php esc_html_e( 'Create the loyalty pass first if they do not have one yet', 'epasscard' ); ?>
									</label>
									<p>
										<label for="epc-loyalty-order-email-statuses"><strong><?php esc_html_e( 'Email statuses', 'epasscard' ); ?></strong></label><br />
										<select id="epc-loyalty-order-email-statuses" name="order_email_statuses" class="wc-enhanced-select epc-loyalty-status-multiselect" multiple="multiple" style="width:100%; max-width:420px;" data-placeholder="<?php esc_attr_e( 'Select order statuses…', 'epasscard' ); ?>">
											<?php foreach ( $order_statuses as $status_slug => $status_label ) : ?>
												<option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( in_array( $status_slug, $email['order_email_statuses'], true ) ); ?>><?php echo esc_html( $status_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-earning" aria-labelledby="epc-tab-loyalty-earning" hidden>
					<p class="description"><?php esc_html_e( 'Create one or more rules. Lower priority numbers run first. If a rule does not allow stacking, later rules are skipped for that order.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="rules" data-next-index="<?php echo esc_attr( (string) count( $rules ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $rules as $index => $rule ) : ?>
								<div class="epc-loyalty-item" data-epc-repeater-item>
									<div class="epc-loyalty-item__head">
										<div class="epc-loyalty-item__head-main">
											<strong class="epc-loyalty-item__title"><?php echo esc_html( $rule['name'] ? (string) $rule['name'] : __( 'Earning rule', 'epasscard' ) ); ?></strong>
											<label class="epc-loyalty-switch">
												<input type="checkbox" data-field="active" value="1" <?php checked( ! empty( $rule['active'] ) ); ?> />
												<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
											</label>
										</div>
										<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
									</div>
									<input type="hidden" data-field="id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" data-locked="1" />
									<div class="epc-loyalty-fields">
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label"><?php esc_html_e( 'Rule name', 'epasscard' ); ?></span>
											<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( (string) $rule['name'] ); ?>" required />
										</label>
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label"><?php esc_html_e( 'Priority', 'epasscard' ); ?></span>
											<input type="number" data-field="priority" value="<?php echo esc_attr( (string) (int) $rule['priority'] ); ?>" />
											<span class="description"><?php esc_html_e( 'Lower numbers run first', 'epasscard' ); ?></span>
										</label>
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label"><?php esc_html_e( 'How points are awarded', 'epasscard' ); ?></span>
											<select data-field="award_type" class="epc-loyalty-award-type">
												<option value="per_currency" <?php selected( $rule['award_type'], 'per_currency' ); ?>>
													<?php
													printf(
														/* translators: %s: currency symbol */
														esc_html__( 'Points for every %s spent', 'epasscard' ),
														esc_html( $currency )
													);
													?>
												</option>
												<option value="fixed" <?php selected( $rule['award_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed points per qualifying order', 'epasscard' ); ?></option>
											</select>
										</label>
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label epc-loyalty-points-label"><?php echo 'fixed' === $rule['award_type'] ? esc_html__( 'Points per order', 'epasscard' ) : esc_html__( 'Points per currency unit', 'epasscard' ); ?></span>
											<input type="number" step="0.01" min="0" data-field="points" value="<?php echo esc_attr( (string) $rule['points'] ); ?>" />
										</label>
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label"><?php esc_html_e( 'Minimum spend', 'epasscard' ); ?></span>
											<input type="number" step="0.01" min="0" data-field="minimum_spend" value="<?php echo esc_attr( (string) $rule['minimum_spend'] ); ?>" />
										</label>
										<label class="epc-loyalty-field">
											<span class="epc-loyalty-field__label"><?php esc_html_e( 'Points expire after (days)', 'epasscard' ); ?></span>
											<input type="number" min="0" data-field="point_expiry_days" value="<?php echo esc_attr( (string) (int) $rule['point_expiry_days'] ); ?>" />
											<span class="description"><?php esc_html_e( '0 = never expire', 'epasscard' ); ?></span>
										</label>
									</div>
									<div class="epc-loyalty-options">
										<label class="epc-loyalty-switch">
											<input type="checkbox" data-field="stack" value="1" <?php checked( ! empty( $rule['stack'] ) ); ?> />
											<span><?php esc_html_e( 'Allow other rules to also apply (stack)', 'epasscard' ); ?></span>
										</label>
									</div>
									<details class="epc-loyalty-advanced">
										<summary><?php esc_html_e( 'Optional filters', 'epasscard' ); ?></summary>
										<div class="epc-loyalty-fields">
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these categories', 'epasscard' ); ?></span>
												<?php $epc_loyalty_render_cats( 'category_ids', (array) $rule['category_ids'], $product_categories, __( 'All categories', 'epasscard' ) ); ?>
											</label>
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude categories', 'epasscard' ); ?></span>
												<?php $epc_loyalty_render_cats( 'excluded_categories', (array) $rule['excluded_categories'], $product_categories, __( 'None', 'epasscard' ) ); ?>
											</label>
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these product IDs', 'epasscard' ); ?></span>
												<input type="text" data-field="product_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) $rule['product_ids'] ) ); ?>" placeholder="<?php esc_attr_e( 'Leave blank for all products', 'epasscard' ); ?>" />
											</label>
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude product IDs', 'epasscard' ); ?></span>
												<input type="text" data-field="excluded_products" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) $rule['excluded_products'] ) ); ?>" />
											</label>
											<label class="epc-loyalty-field epc-loyalty-field--full">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Customer roles', 'epasscard' ); ?></span>
												<select data-field="customer_roles" class="wc-enhanced-select" multiple="multiple" style="width:100%;" data-placeholder="<?php esc_attr_e( 'All customers', 'epasscard' ); ?>">
													<?php foreach ( $role_choices as $role_key => $role_label ) : ?>
														<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, (array) $rule['customer_roles'], true ) ); ?>><?php echo esc_html( $role_label ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Starts at (optional)', 'epasscard' ); ?></span>
												<input type="datetime-local" data-field="start_at" value="<?php echo esc_attr( $rule['start_at'] ? gmdate( 'Y-m-d\TH:i', strtotime( (string) $rule['start_at'] . ' UTC' ) ) : '' ); ?>" />
											</label>
											<label class="epc-loyalty-field">
												<span class="epc-loyalty-field__label"><?php esc_html_e( 'Ends at (optional)', 'epasscard' ); ?></span>
												<input type="datetime-local" data-field="end_at" value="<?php echo esc_attr( $rule['end_at'] ? gmdate( 'Y-m-d\TH:i', strtotime( (string) $rule['end_at'] . ' UTC' ) ) : '' ); ?>" />
											</label>
										</div>
										<div class="epc-loyalty-options">
											<label class="epc-loyalty-switch">
												<input type="checkbox" data-field="first_order" value="1" <?php checked( ! empty( $rule['first_order'] ) ); ?> />
												<span><?php esc_html_e( 'First order only', 'epasscard' ); ?></span>
											</label>
											<label class="epc-loyalty-switch">
												<input type="checkbox" data-field="include_sale_items" value="1" <?php checked( ! empty( $rule['include_sale_items'] ) ); ?> />
												<span><?php esc_html_e( 'Include sale items', 'epasscard' ); ?></span>
											</label>
										</div>
									</details>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="epc-loyalty-repeater__actions">
							<button type="button" class="button" data-epc-repeater-add><?php esc_html_e( 'Add earning rule', 'epasscard' ); ?></button>
						</p>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-tiers" aria-labelledby="epc-tab-loyalty-tiers" hidden>
					<p class="description"><?php esc_html_e( 'Tiers are based on lifetime points earned (not the current spendable balance). Example: Silver at 500, Gold at 2000.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="tiers" data-next-index="<?php echo esc_attr( (string) count( $tiers ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $tiers as $tier ) : ?>
								<div class="epc-loyalty-item epc-loyalty-item--compact" data-epc-repeater-item>
									<div class="epc-loyalty-grid epc-loyalty-grid--3">
										<label>
											<span><?php esc_html_e( 'Tier name', 'epasscard' ); ?></span>
											<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( (string) $tier['name'] ); ?>" required />
										</label>
										<label>
											<span><?php esc_html_e( 'Lifetime points needed', 'epasscard' ); ?></span>
											<input type="number" min="0" data-field="threshold" class="small-text" value="<?php echo esc_attr( (string) (int) $tier['threshold'] ); ?>" />
										</label>
										<label>
											<span><?php esc_html_e( 'Internal ID', 'epasscard' ); ?></span>
											<input type="text" data-field="id" class="regular-text code" value="<?php echo esc_attr( (string) $tier['id'] ); ?>" readonly data-locked="1" />
										</label>
									</div>
									<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove tier', 'epasscard' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<p>
							<button type="button" class="button" data-epc-repeater-add><?php esc_html_e( 'Add tier', 'epasscard' ); ?></button>
						</p>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-rewards" aria-labelledby="epc-tab-loyalty-rewards" hidden>
					<p class="description"><?php esc_html_e( 'Milestones unlock rewards when customers reach a lifetime points total. Keep reward IDs stable after customers start unlocking them.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="milestones" data-next-index="<?php echo esc_attr( (string) count( $milestones ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $milestones as $milestone ) : ?>
								<?php $reward = isset( $milestone['reward'] ) && is_array( $milestone['reward'] ) ? $milestone['reward'] : array(); ?>
								<div class="epc-loyalty-item" data-epc-repeater-item>
									<div class="epc-loyalty-item__head">
										<strong class="epc-loyalty-item__title"><?php echo esc_html( $milestone['name'] ? (string) $milestone['name'] : __( 'Reward', 'epasscard' ) ); ?></strong>
										<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
									</div>
									<div class="epc-loyalty-grid">
										<label>
											<span><?php esc_html_e( 'Reward name', 'epasscard' ); ?></span>
											<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( (string) $milestone['name'] ); ?>" required />
										</label>
										<label>
											<span><?php esc_html_e( 'Internal ID', 'epasscard' ); ?></span>
											<input type="text" data-field="id" class="regular-text code" value="<?php echo esc_attr( (string) $milestone['id'] ); ?>" readonly data-locked="1" />
										</label>
										<label class="epc-loyalty-check-inline">
											<input type="checkbox" data-field="active" value="1" <?php checked( ! empty( $milestone['active'] ) ); ?> />
											<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
										</label>
										<label>
											<span><?php esc_html_e( 'Lifetime points to unlock', 'epasscard' ); ?></span>
											<input type="number" min="1" data-field="threshold" class="small-text" value="<?php echo esc_attr( (string) (int) $milestone['threshold'] ); ?>" />
										</label>
										<label>
											<span><?php esc_html_e( 'Reward type', 'epasscard' ); ?></span>
											<select data-field="reward.type" class="epc-loyalty-reward-type">
												<?php foreach ( $reward_types as $type => $label ) : ?>
													<option value="<?php echo esc_attr( $type ); ?>" <?php selected( ( $reward['type'] ?? '' ), $type ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label class="epc-loyalty-reward-amount">
											<span><?php esc_html_e( 'Amount / points', 'epasscard' ); ?></span>
											<input type="number" step="0.01" min="0" data-field="reward.amount" class="small-text" value="<?php echo esc_attr( (string) ( $reward['amount'] ?? 0 ) ); ?>" />
										</label>
										<label class="epc-loyalty-reward-product" <?php echo ( 'free_product' === ( $reward['type'] ?? '' ) ) ? '' : 'hidden'; ?>>
											<span><?php esc_html_e( 'Free product ID', 'epasscard' ); ?></span>
											<input type="number" min="0" data-field="reward.product_id" class="small-text" value="<?php echo esc_attr( (string) (int) ( $reward['product_id'] ?? 0 ) ); ?>" />
										</label>
										<label>
											<span><?php esc_html_e( 'How customers claim it', 'epasscard' ); ?></span>
											<select data-field="claim_mode">
												<option value="automatic" <?php selected( $milestone['claim_mode'], 'automatic' ); ?>><?php esc_html_e( 'Automatic', 'epasscard' ); ?></option>
												<option value="manual" <?php selected( $milestone['claim_mode'], 'manual' ); ?>><?php esc_html_e( 'Customer claims manually', 'epasscard' ); ?></option>
											</select>
										</label>
										<label>
											<span><?php esc_html_e( 'Reward expires after (days)', 'epasscard' ); ?></span>
											<input type="number" min="0" data-field="expiry_days" class="small-text" value="<?php echo esc_attr( (string) (int) $milestone['expiry_days'] ); ?>" />
											<span class="description"><?php esc_html_e( '0 = no expiry', 'epasscard' ); ?></span>
										</label>
										<label class="epc-loyalty-check-inline">
											<input type="checkbox" data-field="repeatable" value="1" <?php checked( ! empty( $milestone['repeatable'] ) ); ?> />
											<span><?php esc_html_e( 'Repeat every time they reach this threshold again', 'epasscard' ); ?></span>
										</label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<p>
							<button type="button" class="button" data-epc-repeater-add><?php esc_html_e( 'Add reward milestone', 'epasscard' ); ?></button>
						</p>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-redeem" aria-labelledby="epc-tab-loyalty-redeem" hidden>
					<p class="description"><?php esc_html_e( 'Let customers spend points for a discount at cart and checkout (classic and block checkout).', 'epasscard' ); ?></p>
					<div class="epc-loyalty-grid" data-epc-redemption>
						<label class="epc-loyalty-check-inline">
							<input type="checkbox" data-field="enabled" value="1" <?php checked( ! empty( $redemption['enabled'] ) ); ?> />
							<span><?php esc_html_e( 'Enable checkout points redemption', 'epasscard' ); ?></span>
						</label>
						<label>
							<span><?php esc_html_e( 'Points needed for 1 currency unit', 'epasscard' ); ?></span>
							<input type="number" step="0.000001" min="0.000001" data-field="points_per_currency" class="regular-text" value="<?php echo esc_attr( (string) $redemption['points_per_currency'] ); ?>" />
							<span class="description">
								<?php
								printf(
									/* translators: %s: currency symbol */
									esc_html__( 'Example: 100 means 100 points = %s1 discount.', 'epasscard' ),
									esc_html( $currency )
								);
								?>
							</span>
						</label>
						<label>
							<span><?php esc_html_e( 'Minimum points balance to redeem', 'epasscard' ); ?></span>
							<input type="number" min="0" data-field="minimum_balance" class="small-text" value="<?php echo esc_attr( (string) (int) $redemption['minimum_balance'] ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Redeem in steps of', 'epasscard' ); ?></span>
							<input type="number" min="1" data-field="increment" class="small-text" value="<?php echo esc_attr( (string) (int) $redemption['increment'] ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Maximum % of order that points can cover', 'epasscard' ); ?></span>
							<input type="number" min="0" max="100" step="0.01" data-field="max_order_percent" class="small-text" value="<?php echo esc_attr( (string) $redemption['max_order_percent'] ); ?>" />
						</label>
						<label class="epc-loyalty-check-inline">
							<input type="checkbox" data-field="include_sale_items" value="1" <?php checked( ! empty( $redemption['include_sale_items'] ) ); ?> />
							<span><?php esc_html_e( 'Allow redeeming against sale items', 'epasscard' ); ?></span>
						</label>
						<label>
							<span><?php esc_html_e( 'If an order is refunded', 'epasscard' ); ?></span>
							<select data-field="refund_restore_policy">
								<?php foreach ( $refund_policies as $policy => $label ) : ?>
									<option value="<?php echo esc_attr( $policy ); ?>" <?php selected( $redemption['refund_restore_policy'], $policy ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Exclude categories from redemption', 'epasscard' ); ?></span>
							<?php $epc_loyalty_render_cats( 'excluded_categories', (array) ( $redemption['excluded_categories'] ?? array() ), $product_categories, __( 'None', 'epasscard' ) ); ?>
						</label>
						<label>
							<span><?php esc_html_e( 'Exclude product IDs', 'epasscard' ); ?></span>
							<input type="text" data-field="excluded_products" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) ( $redemption['excluded_products'] ?? array() ) ) ); ?>" />
						</label>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-notify" aria-labelledby="epc-tab-loyalty-notify" hidden>
					<p class="description">
						<?php esc_html_e( 'Turn email and wallet push notifications on or off, and edit the wording. Available tags: {customer_name}, {points}, {balance}, {tier}, {reward}, {pass_link}, {expires_at}, {reason}', 'epasscard' ); ?>
					</p>
					<div class="epc-loyalty-notify-list" data-epc-notifications>
						<?php foreach ( $notification_types as $type => $type_label ) : ?>
							<?php $rule = isset( $notifications[ $type ] ) && is_array( $notifications[ $type ] ) ? $notifications[ $type ] : array(); ?>
							<div class="epc-loyalty-item" data-notify-type="<?php echo esc_attr( $type ); ?>">
								<div class="epc-loyalty-item__head">
									<strong><?php echo esc_html( $type_label ); ?></strong>
								</div>
								<div class="epc-loyalty-grid">
									<label class="epc-loyalty-check-inline">
										<input type="checkbox" data-field="email_enabled" value="1" <?php checked( ! empty( $rule['email_enabled'] ) ); ?> />
										<span><?php esc_html_e( 'Send email', 'epasscard' ); ?></span>
									</label>
									<label class="epc-loyalty-check-inline">
										<input type="checkbox" data-field="push_enabled" value="1" <?php checked( ! empty( $rule['push_enabled'] ) ); ?> />
										<span><?php esc_html_e( 'Send wallet push', 'epasscard' ); ?></span>
									</label>
									<?php if ( 'points_expiring' === $type ) : ?>
										<label>
											<span><?php esc_html_e( 'Warn this many days before expiry', 'epasscard' ); ?></span>
											<input type="number" min="1" max="90" data-field="days" class="small-text" value="<?php echo esc_attr( (string) (int) ( $rule['days'] ?? 30 ) ); ?>" />
										</label>
									<?php endif; ?>
									<label>
										<span><?php esc_html_e( 'Email subject', 'epasscard' ); ?></span>
										<input type="text" data-field="subject" class="regular-text" value="<?php echo esc_attr( (string) ( $rule['subject'] ?? '' ) ); ?>" />
									</label>
									<label>
										<span><?php esc_html_e( 'Push title', 'epasscard' ); ?></span>
										<input type="text" data-field="title" class="regular-text" value="<?php echo esc_attr( (string) ( $rule['title'] ?? '' ) ); ?>" />
									</label>
									<label class="epc-loyalty-span-2">
										<span><?php esc_html_e( 'Message', 'epasscard' ); ?></span>
										<textarea data-field="message" class="large-text" rows="3"><?php echo esc_textarea( (string) ( $rule['message'] ?? '' ) ); ?></textarea>
									</label>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save loyalty program', 'epasscard' ); ?></button>
		</p>
	</form>
</div>

<template id="epc-loyalty-tpl-rule">
	<div class="epc-loyalty-item" data-epc-repeater-item>
		<div class="epc-loyalty-item__head">
			<div class="epc-loyalty-item__head-main">
				<strong class="epc-loyalty-item__title"><?php esc_html_e( 'New earning rule', 'epasscard' ); ?></strong>
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="active" value="1" checked />
					<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
				</label>
			</div>
			<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
		</div>
		<input type="hidden" data-field="id" value="" />
		<div class="epc-loyalty-fields">
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label"><?php esc_html_e( 'Rule name', 'epasscard' ); ?></span>
				<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="" required />
			</label>
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label"><?php esc_html_e( 'Priority', 'epasscard' ); ?></span>
				<input type="number" data-field="priority" value="10" />
				<span class="description"><?php esc_html_e( 'Lower numbers run first', 'epasscard' ); ?></span>
			</label>
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label"><?php esc_html_e( 'How points are awarded', 'epasscard' ); ?></span>
				<select data-field="award_type" class="epc-loyalty-award-type">
					<option value="per_currency"><?php esc_html_e( 'Points for every currency unit spent', 'epasscard' ); ?></option>
					<option value="fixed"><?php esc_html_e( 'Fixed points per qualifying order', 'epasscard' ); ?></option>
				</select>
			</label>
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label epc-loyalty-points-label"><?php esc_html_e( 'Points per currency unit', 'epasscard' ); ?></span>
				<input type="number" step="0.01" min="0" data-field="points" value="1" />
			</label>
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label"><?php esc_html_e( 'Minimum spend', 'epasscard' ); ?></span>
				<input type="number" step="0.01" min="0" data-field="minimum_spend" value="0" />
			</label>
			<label class="epc-loyalty-field">
				<span class="epc-loyalty-field__label"><?php esc_html_e( 'Points expire after (days)', 'epasscard' ); ?></span>
				<input type="number" min="0" data-field="point_expiry_days" value="0" />
				<span class="description"><?php esc_html_e( '0 = never expire', 'epasscard' ); ?></span>
			</label>
		</div>
		<div class="epc-loyalty-options">
			<label class="epc-loyalty-switch">
				<input type="checkbox" data-field="stack" value="1" checked />
				<span><?php esc_html_e( 'Allow other rules to also apply (stack)', 'epasscard' ); ?></span>
			</label>
		</div>
		<details class="epc-loyalty-advanced">
			<summary><?php esc_html_e( 'Optional filters', 'epasscard' ); ?></summary>
			<div class="epc-loyalty-fields">
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these product IDs', 'epasscard' ); ?></span>
					<input type="text" data-field="product_ids" class="regular-text" value="" placeholder="<?php esc_attr_e( 'Leave blank for all products', 'epasscard' ); ?>" />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude product IDs', 'epasscard' ); ?></span>
					<input type="text" data-field="excluded_products" class="regular-text" value="" />
				</label>
				<input type="hidden" data-field="category_ids" value="" />
				<input type="hidden" data-field="excluded_categories" value="" />
				<input type="hidden" data-field="customer_roles" value="" />
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Starts at (optional)', 'epasscard' ); ?></span>
					<input type="datetime-local" data-field="start_at" value="" />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Ends at (optional)', 'epasscard' ); ?></span>
					<input type="datetime-local" data-field="end_at" value="" />
				</label>
			</div>
			<div class="epc-loyalty-options">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="first_order" value="1" />
					<span><?php esc_html_e( 'First order only', 'epasscard' ); ?></span>
				</label>
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="include_sale_items" value="1" checked />
					<span><?php esc_html_e( 'Include sale items', 'epasscard' ); ?></span>
				</label>
			</div>
		</details>
	</div>
</template>

<template id="epc-loyalty-tpl-tier">
	<div class="epc-loyalty-item epc-loyalty-item--compact" data-epc-repeater-item>
		<div class="epc-loyalty-grid epc-loyalty-grid--3">
			<label>
				<span><?php esc_html_e( 'Tier name', 'epasscard' ); ?></span>
				<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="" required />
			</label>
			<label>
				<span><?php esc_html_e( 'Lifetime points needed', 'epasscard' ); ?></span>
				<input type="number" min="0" data-field="threshold" class="small-text" value="0" />
			</label>
			<label>
				<span><?php esc_html_e( 'Internal ID', 'epasscard' ); ?></span>
				<input type="text" data-field="id" class="regular-text code" value="" />
			</label>
		</div>
		<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove tier', 'epasscard' ); ?></button>
	</div>
</template>

<template id="epc-loyalty-tpl-milestone">
	<div class="epc-loyalty-item" data-epc-repeater-item>
		<div class="epc-loyalty-item__head">
			<strong class="epc-loyalty-item__title"><?php esc_html_e( 'New reward', 'epasscard' ); ?></strong>
			<button type="button" class="button-link-delete epc-loyalty-item__remove" data-epc-repeater-remove><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
		</div>
		<div class="epc-loyalty-grid">
			<label>
				<span><?php esc_html_e( 'Reward name', 'epasscard' ); ?></span>
				<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="" required />
			</label>
			<label>
				<span><?php esc_html_e( 'Internal ID', 'epasscard' ); ?></span>
				<input type="text" data-field="id" class="regular-text code" value="" />
			</label>
			<label class="epc-loyalty-check-inline">
				<input type="checkbox" data-field="active" value="1" checked />
				<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
			</label>
			<label>
				<span><?php esc_html_e( 'Lifetime points to unlock', 'epasscard' ); ?></span>
				<input type="number" min="1" data-field="threshold" class="small-text" value="100" />
			</label>
			<label>
				<span><?php esc_html_e( 'Reward type', 'epasscard' ); ?></span>
				<select data-field="reward.type" class="epc-loyalty-reward-type">
					<?php foreach ( $reward_types as $type => $label ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="epc-loyalty-reward-amount">
				<span><?php esc_html_e( 'Amount / points', 'epasscard' ); ?></span>
				<input type="number" step="0.01" min="0" data-field="reward.amount" class="small-text" value="0" />
			</label>
			<label class="epc-loyalty-reward-product" hidden>
				<span><?php esc_html_e( 'Free product ID', 'epasscard' ); ?></span>
				<input type="number" min="0" data-field="reward.product_id" class="small-text" value="0" />
			</label>
			<label>
				<span><?php esc_html_e( 'How customers claim it', 'epasscard' ); ?></span>
				<select data-field="claim_mode">
					<option value="automatic"><?php esc_html_e( 'Automatic', 'epasscard' ); ?></option>
					<option value="manual"><?php esc_html_e( 'Customer claims manually', 'epasscard' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Reward expires after (days)', 'epasscard' ); ?></span>
				<input type="number" min="0" data-field="expiry_days" class="small-text" value="0" />
			</label>
			<label class="epc-loyalty-check-inline">
				<input type="checkbox" data-field="repeatable" value="1" />
				<span><?php esc_html_e( 'Repeat every time they reach this threshold again', 'epasscard' ); ?></span>
			</label>
		</div>
	</div>
</template>
