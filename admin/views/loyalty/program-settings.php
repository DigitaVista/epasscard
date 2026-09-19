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
 * @var array<string, mixed> $order_sync
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

$epc_loyalty_reward_amount_label = static function ( $type ) {
	if ( 'fixed_coupon' === $type ) {
		return __( 'Coupon amount', 'epasscard' );
	}
	if ( 'percentage_coupon' === $type ) {
		return __( 'Discount percent', 'epasscard' );
	}
	return __( 'Bonus points', 'epasscard' );
};

/**
 * Render one reward milestone card.
 *
 * @param array<string, mixed> $milestone Milestone values.
 * @param bool                 $lock_id   Whether the internal ID is locked.
 * @param bool                 $open      Whether the accordion starts expanded.
 * @return void
 */
/**
 * Render one reward milestone card.
 *
 * @param array<string, mixed> $milestone Milestone values.
 * @param bool                 $lock_id   Whether the internal ID is locked.
 * @param bool                 $open      Whether the accordion starts expanded.
 * @return void
 */
$epc_loyalty_render_milestone = static function ( array $milestone, $lock_id, $open = false ) use ( $reward_types, $epc_loyalty_reward_amount_label ) {
	$reward       = isset( $milestone['reward'] ) && is_array( $milestone['reward'] ) ? $milestone['reward'] : array();
	$type         = sanitize_key( (string) ( $reward['type'] ?? 'bonus_points' ) );
	$claim_mode   = sanitize_key( (string) ( $milestone['claim_mode'] ?? 'automatic' ) );
	$name         = (string) ( $milestone['name'] ?? '' );
	$title        = '' !== $name ? $name : ( $lock_id ? __( 'Reward', 'epasscard' ) : __( 'New reward', 'epasscard' ) );
	$threshold    = (int) ( $milestone['threshold'] ?? 100 );
	$type_label   = isset( $reward_types[ $type ] ) ? $reward_types[ $type ] : $type;
	$hide_amount  = in_array( $type, array( 'free_shipping', 'free_product' ), true );
	$show_product = 'free_product' === $type;
	$preview      = sprintf(
		/* translators: 1: lifetime points, 2: reward type */
		__( '%1$s pts · %2$s', 'epasscard' ),
		(string) $threshold,
		$type_label
	);
	?>
	<div class="epc-loyalty-item epc-loyalty-item--accordion epc-loyalty-item--reward<?php echo $open ? ' is-open' : ''; ?>" data-epc-repeater-item>
		<div class="epc-loyalty-item__head">
			<button type="button" class="epc-loyalty-item__toggle" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
				<span class="epc-loyalty-item__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				<span class="epc-loyalty-item__toggle-text">
					<strong class="epc-loyalty-item__title"><?php echo esc_html( $title ); ?></strong>
					<span class="epc-loyalty-item__preview"><?php echo esc_html( $preview ); ?></span>
				</span>
			</button>
			<div class="epc-loyalty-item__head-actions">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="active" value="1" <?php checked( ! isset( $milestone['active'] ) || ! empty( $milestone['active'] ) ); ?> />
					<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
				</label>
				<button
					type="button"
					class="button-link-delete epc-loyalty-item__remove epc-loyalty-item__remove--icon"
					data-epc-repeater-remove
					data-epc-confirm-remove
					aria-label="<?php esc_attr_e( 'Remove reward', 'epasscard' ); ?>"
					title="<?php esc_attr_e( 'Remove reward', 'epasscard' ); ?>"
				>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		<div class="epc-loyalty-item__body" <?php echo $open ? '' : 'hidden'; ?>>
			<input type="hidden" data-field="id" value="<?php echo esc_attr( (string) ( $milestone['id'] ?? '' ) ); ?>" <?php echo $lock_id ? 'readonly data-locked="1"' : ''; ?> />
			<div class="epc-loyalty-fields">
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Reward name', 'epasscard' ); ?></span>
					<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( $name ); ?>" required />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Lifetime points to unlock', 'epasscard' ); ?></span>
					<input type="number" min="1" data-field="threshold" value="<?php echo esc_attr( (string) $threshold ); ?>" />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Reward type', 'epasscard' ); ?></span>
					<select data-field="reward.type" class="epc-loyalty-reward-type">
						<?php foreach ( $reward_types as $reward_type => $label ) : ?>
							<option value="<?php echo esc_attr( $reward_type ); ?>" <?php selected( $type, $reward_type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="epc-loyalty-field epc-loyalty-reward-amount" <?php echo $hide_amount ? 'hidden' : ''; ?>>
					<span class="epc-loyalty-field__label epc-loyalty-reward-amount-label"><?php echo esc_html( $epc_loyalty_reward_amount_label( $type ) ); ?></span>
					<input type="number" step="0.01" min="0" data-field="reward.amount" value="<?php echo esc_attr( (string) ( $reward['amount'] ?? 0 ) ); ?>" />
				</label>
				<label class="epc-loyalty-field epc-loyalty-reward-product" <?php echo $show_product ? '' : 'hidden'; ?>>
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Free product ID', 'epasscard' ); ?></span>
					<input type="number" min="0" data-field="reward.product_id" value="<?php echo esc_attr( (string) (int) ( $reward['product_id'] ?? 0 ) ); ?>" />
					<span class="description"><?php esc_html_e( 'WooCommerce product ID given to the customer', 'epasscard' ); ?></span>
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'How customers claim it', 'epasscard' ); ?></span>
					<select data-field="claim_mode">
						<option value="automatic" <?php selected( $claim_mode, 'automatic' ); ?>><?php esc_html_e( 'Automatic', 'epasscard' ); ?></option>
						<option value="manual" <?php selected( $claim_mode, 'manual' ); ?>><?php esc_html_e( 'Customer claims manually', 'epasscard' ); ?></option>
					</select>
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Expires after (days)', 'epasscard' ); ?></span>
					<input type="number" min="0" data-field="expiry_days" value="<?php echo esc_attr( (string) (int) ( $milestone['expiry_days'] ?? 0 ) ); ?>" />
					<span class="description"><?php esc_html_e( '0 = no expiry', 'epasscard' ); ?></span>
				</label>
			</div>
			<div class="epc-loyalty-options">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="repeatable" value="1" <?php checked( ! empty( $milestone['repeatable'] ) ); ?> />
					<span><?php esc_html_e( 'Repeat every time they reach this threshold again', 'epasscard' ); ?></span>
				</label>
			</div>
		</div>
	</div>
	<?php
};

/**
 * Render one tier accordion card.
 *
 * @param array<string, mixed> $tier    Tier values.
 * @param bool                 $lock_id Whether the internal ID is locked.
 * @param bool                 $open    Whether the accordion starts expanded.
 * @return void
 */
$epc_loyalty_render_tier = static function ( array $tier, $lock_id, $open = false ) {
	$name      = (string) ( $tier['name'] ?? '' );
	$title     = '' !== $name ? $name : ( $lock_id ? __( 'Tier', 'epasscard' ) : __( 'New tier', 'epasscard' ) );
	$threshold = (int) ( $tier['threshold'] ?? 0 );
	$preview   = sprintf(
		/* translators: %s: lifetime points threshold */
		__( '%s lifetime pts', 'epasscard' ),
		(string) $threshold
	);
	?>
	<div class="epc-loyalty-item epc-loyalty-item--accordion epc-loyalty-item--tier<?php echo $open ? ' is-open' : ''; ?>" data-epc-repeater-item>
		<div class="epc-loyalty-item__head">
			<button type="button" class="epc-loyalty-item__toggle" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
				<span class="epc-loyalty-item__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				<span class="epc-loyalty-item__toggle-text">
					<strong class="epc-loyalty-item__title"><?php echo esc_html( $title ); ?></strong>
					<span class="epc-loyalty-item__preview"><?php echo esc_html( $preview ); ?></span>
				</span>
			</button>
			<div class="epc-loyalty-item__head-actions">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="active" value="1" <?php checked( ! isset( $tier['active'] ) || ! empty( $tier['active'] ) ); ?> />
					<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
				</label>
				<button
					type="button"
					class="button-link-delete epc-loyalty-item__remove epc-loyalty-item__remove--icon"
					data-epc-repeater-remove
					data-epc-confirm-remove
					aria-label="<?php esc_attr_e( 'Remove tier', 'epasscard' ); ?>"
					title="<?php esc_attr_e( 'Remove tier', 'epasscard' ); ?>"
				>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		<div class="epc-loyalty-item__body" <?php echo $open ? '' : 'hidden'; ?>>
			<input type="hidden" data-field="id" value="<?php echo esc_attr( (string) ( $tier['id'] ?? '' ) ); ?>" <?php echo $lock_id ? 'readonly data-locked="1"' : ''; ?> />
			<div class="epc-loyalty-fields">
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Tier name', 'epasscard' ); ?></span>
					<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( $name ); ?>" required />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Lifetime points needed', 'epasscard' ); ?></span>
					<input type="number" min="0" data-field="threshold" class="epc-loyalty-points-needed" value="<?php echo esc_attr( (string) $threshold ); ?>" />
				</label>
			</div>
		</div>
	</div>
	<?php
};

/**
 * Render one earning rule accordion card.
 *
 * @param array<string, mixed> $rule         Rule values.
 * @param bool                 $lock_id      Whether the internal ID is locked.
 * @param bool                 $open         Whether the accordion starts expanded.
 * @param bool                 $full_filters Whether to include category/role filter selects.
 * @return void
 */
$epc_loyalty_render_rule = static function ( array $rule, $lock_id, $open = false, $full_filters = true ) use ( $currency, $product_categories, $role_choices, $epc_loyalty_render_cats ) {
	$name       = (string) ( $rule['name'] ?? '' );
	$title      = '' !== $name ? $name : ( $lock_id ? __( 'Earning rule', 'epasscard' ) : __( 'New earning rule', 'epasscard' ) );
	$award_type = sanitize_key( (string) ( $rule['award_type'] ?? 'per_currency' ) );
	$points     = (string) ( $rule['points'] ?? '1' );
	$priority   = (int) ( $rule['priority'] ?? 10 );
	if ( 'fixed' === $award_type ) {
		$preview = sprintf(
			/* translators: 1: points amount, 2: priority number */
			__( 'Fixed %1$s pts · Priority %2$s', 'epasscard' ),
			$points,
			(string) $priority
		);
	} else {
		$preview = sprintf(
			/* translators: 1: points amount, 2: priority number */
			__( '%1$s pts per unit · Priority %2$s', 'epasscard' ),
			$points,
			(string) $priority
		);
	}
	?>
	<div class="epc-loyalty-item epc-loyalty-item--accordion epc-loyalty-item--rule<?php echo $open ? ' is-open' : ''; ?>" data-epc-repeater-item>
		<div class="epc-loyalty-item__head">
			<button type="button" class="epc-loyalty-item__toggle" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
				<span class="epc-loyalty-item__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				<span class="epc-loyalty-item__toggle-text">
					<strong class="epc-loyalty-item__title"><?php echo esc_html( $title ); ?></strong>
					<span class="epc-loyalty-item__preview"><?php echo esc_html( $preview ); ?></span>
				</span>
			</button>
			<div class="epc-loyalty-item__head-actions">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="active" value="1" <?php checked( ! empty( $rule['active'] ) ); ?> />
					<span><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
				</label>
				<button
					type="button"
					class="button-link-delete epc-loyalty-item__remove epc-loyalty-item__remove--icon"
					data-epc-repeater-remove
					data-epc-confirm-remove
					aria-label="<?php esc_attr_e( 'Remove earning rule', 'epasscard' ); ?>"
					title="<?php esc_attr_e( 'Remove earning rule', 'epasscard' ); ?>"
				>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		<div class="epc-loyalty-item__body" <?php echo $open ? '' : 'hidden'; ?>>
			<input type="hidden" data-field="id" value="<?php echo esc_attr( (string) ( $rule['id'] ?? '' ) ); ?>" <?php echo $lock_id ? 'data-locked="1"' : ''; ?> />
			<div class="epc-loyalty-fields">
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Rule name', 'epasscard' ); ?></span>
					<input type="text" data-field="name" class="regular-text epc-loyalty-item__name" value="<?php echo esc_attr( $name ); ?>" required />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Priority', 'epasscard' ); ?></span>
					<input type="number" data-field="priority" value="<?php echo esc_attr( (string) $priority ); ?>" />
					<span class="description"><?php esc_html_e( 'Lower numbers run first', 'epasscard' ); ?></span>
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'How points are awarded', 'epasscard' ); ?></span>
					<select data-field="award_type" class="epc-loyalty-award-type">
						<option value="per_currency" <?php selected( $award_type, 'per_currency' ); ?>>
							<?php
							printf(
								/* translators: %s: currency symbol */
								esc_html__( 'Points for every %s spent', 'epasscard' ),
								esc_html( $currency )
							);
							?>
						</option>
						<option value="fixed" <?php selected( $award_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed points per qualifying order', 'epasscard' ); ?></option>
					</select>
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label epc-loyalty-points-label"><?php echo 'fixed' === $award_type ? esc_html__( 'Points per order', 'epasscard' ) : esc_html__( 'Points per currency unit', 'epasscard' ); ?></span>
					<input type="number" step="0.01" min="0" data-field="points" value="<?php echo esc_attr( $points ); ?>" />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Minimum spend', 'epasscard' ); ?></span>
					<input type="number" step="0.01" min="0" data-field="minimum_spend" value="<?php echo esc_attr( (string) ( $rule['minimum_spend'] ?? '0' ) ); ?>" />
				</label>
				<label class="epc-loyalty-field">
					<span class="epc-loyalty-field__label"><?php esc_html_e( 'Points expire after (days)', 'epasscard' ); ?></span>
					<input type="number" min="0" data-field="point_expiry_days" value="<?php echo esc_attr( (string) (int) ( $rule['point_expiry_days'] ?? 0 ) ); ?>" />
					<span class="description"><?php esc_html_e( '0 = never expire', 'epasscard' ); ?></span>
				</label>
			</div>
			<div class="epc-loyalty-options">
				<label class="epc-loyalty-switch">
					<input type="checkbox" data-field="stack" value="1" <?php checked( ! isset( $rule['stack'] ) || ! empty( $rule['stack'] ) ); ?> />
					<span><?php esc_html_e( 'Allow other rules to also apply (stack)', 'epasscard' ); ?></span>
				</label>
			</div>
			<details class="epc-loyalty-advanced">
				<summary><?php esc_html_e( 'Optional filters', 'epasscard' ); ?></summary>
				<?php if ( $full_filters ) : ?>
					<div class="epc-loyalty-fields">
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these categories', 'epasscard' ); ?></span>
							<?php $epc_loyalty_render_cats( 'category_ids', (array) ( $rule['category_ids'] ?? array() ), $product_categories, __( 'All categories', 'epasscard' ) ); ?>
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude categories', 'epasscard' ); ?></span>
							<?php $epc_loyalty_render_cats( 'excluded_categories', (array) ( $rule['excluded_categories'] ?? array() ), $product_categories, __( 'None', 'epasscard' ) ); ?>
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these product IDs', 'epasscard' ); ?></span>
							<input type="text" data-field="product_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) ( $rule['product_ids'] ?? array() ) ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 12, 45, 108', 'epasscard' ); ?>" />
							<span class="description"><?php esc_html_e( 'WooCommerce product IDs, separated by commas or spaces. Leave blank to include all products. Find an ID in Products → edit a product (look at the URL: post=123).', 'epasscard' ); ?></span>
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude product IDs', 'epasscard' ); ?></span>
							<input type="text" data-field="excluded_products" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) ( $rule['excluded_products'] ?? array() ) ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 15, 22', 'epasscard' ); ?>" />
							<span class="description"><?php esc_html_e( 'Same format: comma- or space-separated IDs. These products never earn points from this rule.', 'epasscard' ); ?></span>
						</label>
						<label class="epc-loyalty-field epc-loyalty-field--full">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Customer roles', 'epasscard' ); ?></span>
							<select data-field="customer_roles" class="wc-enhanced-select" multiple="multiple" style="width:100%;" data-placeholder="<?php esc_attr_e( 'All customers', 'epasscard' ); ?>">
								<?php foreach ( $role_choices as $role_key => $role_label ) : ?>
									<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, (array) ( $rule['customer_roles'] ?? array() ), true ) ); ?>><?php echo esc_html( $role_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Starts at (optional)', 'epasscard' ); ?></span>
							<input type="datetime-local" data-field="start_at" value="<?php echo esc_attr( ! empty( $rule['start_at'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( (string) $rule['start_at'] . ' UTC' ) ) : '' ); ?>" />
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Ends at (optional)', 'epasscard' ); ?></span>
							<input type="datetime-local" data-field="end_at" value="<?php echo esc_attr( ! empty( $rule['end_at'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( (string) $rule['end_at'] . ' UTC' ) ) : '' ); ?>" />
						</label>
					</div>
				<?php else : ?>
					<div class="epc-loyalty-fields">
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Only these product IDs', 'epasscard' ); ?></span>
							<input type="text" data-field="product_ids" class="regular-text" value="" placeholder="<?php esc_attr_e( 'e.g. 12, 45, 108', 'epasscard' ); ?>" />
							<span class="description"><?php esc_html_e( 'WooCommerce product IDs, separated by commas or spaces. Leave blank to include all products. Find an ID in Products → edit a product (look at the URL: post=123).', 'epasscard' ); ?></span>
						</label>
						<label class="epc-loyalty-field">
							<span class="epc-loyalty-field__label"><?php esc_html_e( 'Exclude product IDs', 'epasscard' ); ?></span>
							<input type="text" data-field="excluded_products" class="regular-text" value="" placeholder="<?php esc_attr_e( 'e.g. 15, 22', 'epasscard' ); ?>" />
							<span class="description"><?php esc_html_e( 'Same format: comma- or space-separated IDs. These products never earn points from this rule.', 'epasscard' ); ?></span>
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
				<?php endif; ?>
				<div class="epc-loyalty-options">
					<label class="epc-loyalty-switch">
						<input type="checkbox" data-field="first_order" value="1" <?php checked( ! empty( $rule['first_order'] ) ); ?> />
						<span><?php esc_html_e( 'First order only', 'epasscard' ); ?></span>
					</label>
					<label class="epc-loyalty-switch">
						<input type="checkbox" data-field="include_sale_items" value="1" <?php checked( ! isset( $rule['include_sale_items'] ) || ! empty( $rule['include_sale_items'] ) ); ?> />
						<span><?php esc_html_e( 'Include sale items', 'epasscard' ); ?></span>
					</label>
				</div>
			</details>
		</div>
	</div>
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
				<button type="button" class="epc-tabs__tab" role="tab" id="epc-tab-loyalty-history" aria-controls="epc-panel-loyalty-history" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Past orders', 'epasscard' ); ?></button>
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
					<p class="description"><?php esc_html_e( 'Starter rules are included and left inactive. Review the values, turn on the ones you want, then save. Lower priority numbers run first. If a rule does not allow stacking, later rules are skipped for that order.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="rules" data-next-index="<?php echo esc_attr( (string) count( $rules ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $rules as $rule ) : ?>
								<?php $epc_loyalty_render_rule( $rule, true, false, true ); ?>
							<?php endforeach; ?>
						</div>
						<p class="epc-loyalty-repeater__actions">
							<button type="button" class="button" data-epc-repeater-add><?php esc_html_e( 'Add earning rule', 'epasscard' ); ?></button>
						</p>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-tiers" aria-labelledby="epc-tab-loyalty-tiers" hidden>
					<p class="description"><?php esc_html_e( 'Starter tiers are included and left inactive. They use lifetime points earned, not the spendable balance. Review the thresholds, turn on the ones you want, then save.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="tiers" data-next-index="<?php echo esc_attr( (string) count( $tiers ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $tiers as $tier ) : ?>
								<?php $epc_loyalty_render_tier( $tier, true ); ?>
							<?php endforeach; ?>
						</div>
						<p class="epc-loyalty-repeater__actions">
							<button type="button" class="button" data-epc-repeater-add><?php esc_html_e( 'Add tier', 'epasscard' ); ?></button>
						</p>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-rewards" aria-labelledby="epc-tab-loyalty-rewards" hidden>
					<p class="description"><?php esc_html_e( 'Starter rewards cover every reward type and are left inactive. Adjust the points, amounts, or free product ID, then turn on the ones you want. Keep reward IDs stable after customers start unlocking them.', 'epasscard' ); ?></p>
					<div class="epc-loyalty-repeater" data-epc-repeater="milestones" data-next-index="<?php echo esc_attr( (string) count( $milestones ) ); ?>">
						<div class="epc-loyalty-repeater__list" data-epc-repeater-list>
							<?php foreach ( $milestones as $milestone ) : ?>
								<?php $epc_loyalty_render_milestone( $milestone, true ); ?>
							<?php endforeach; ?>
						</div>
						<p class="epc-loyalty-repeater__actions">
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
							<input type="text" data-field="excluded_products" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) ( $redemption['excluded_products'] ?? array() ) ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 15, 22', 'epasscard' ); ?>" />
							<span class="description"><?php esc_html_e( 'Comma- or space-separated WooCommerce product IDs that cannot be paid for with points.', 'epasscard' ); ?></span>
						</label>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-history" aria-labelledby="epc-tab-loyalty-history" hidden>
					<?php
					$sync_job    = isset( $order_sync ) && is_array( $order_sync ) ? $order_sync : array();
					$sync_status = sanitize_key( (string) ( $sync_job['status'] ?? 'idle' ) );
					$sync_found  = max( 0, (int) ( $sync_job['found'] ?? 0 ) );
					$sync_done   = max( 0, (int) ( $sync_job['processed'] ?? 0 ) );
					$sync_pct    = $sync_found > 0 ? min( 100, (int) floor( ( $sync_done / $sync_found ) * 100 ) ) : ( 'completed' === $sync_status ? 100 : 0 );
					?>
					<p class="description">
						<?php esc_html_e( 'Existing orders do not earn points when you turn loyalty on. Only new qualifying orders are credited automatically. If this shop already has history, decide whether those older registered-customer orders should receive points under the current earning rules.', 'epasscard' ); ?>
					</p>
					<div class="epc-loyalty-history-sync" data-epc-order-sync data-status="<?php echo esc_attr( $sync_status ); ?>">
						<div class="epc-loyalty-fields">
							<label class="epc-loyalty-field">
								<span class="epc-loyalty-field__label"><?php esc_html_e( 'From date', 'epasscard' ); ?></span>
								<input type="date" data-sync-field="from" value="<?php echo esc_attr( (string) ( $sync_job['from'] ?? '' ) ); ?>" />
								<span class="description"><?php esc_html_e( 'Leave empty to start from the first order.', 'epasscard' ); ?></span>
							</label>
							<label class="epc-loyalty-field">
								<span class="epc-loyalty-field__label"><?php esc_html_e( 'To date', 'epasscard' ); ?></span>
								<input type="date" data-sync-field="to" value="<?php echo esc_attr( (string) ( $sync_job['to'] ?? '' ) ); ?>" />
								<span class="description"><?php esc_html_e( 'Leave empty to include today. Guest checkouts are skipped.', 'epasscard' ); ?></span>
							</label>
						</div>
						<div class="epc-loyalty-options">
							<label class="epc-loyalty-switch">
								<input type="checkbox" data-sync-field="grant_rewards" value="1" <?php checked( ! empty( $sync_job['grant_rewards'] ) ); ?> />
								<span><?php esc_html_e( 'Also create milestone rewards (coupons, free products) from historical points', 'epasscard' ); ?></span>
							</label>
							<label class="epc-loyalty-switch">
								<input type="checkbox" data-sync-field="sync_passes" value="1" <?php checked( ! isset( $sync_job['sync_passes'] ) || ! empty( $sync_job['sync_passes'] ) ); ?> />
								<span><?php esc_html_e( 'Queue wallet pass updates for customers who receive points', 'epasscard' ); ?></span>
							</label>
						</div>
						<p class="description">
							<?php esc_html_e( 'Runs in the background, 25 orders at a time. Already credited orders are not awarded twice. Refunds are applied. Email and wallet notifications are not sent for this backfill. Save your earning rules before starting.', 'epasscard' ); ?>
						</p>
						<p class="epc-loyalty-history-sync__actions">
							<button type="button" class="button" data-epc-order-sync-preview><?php esc_html_e( 'Count matching orders', 'epasscard' ); ?></button>
							<button type="button" class="button button-primary" data-epc-order-sync-start><?php esc_html_e( 'Credit past orders', 'epasscard' ); ?></button>
							<button type="button" class="button" data-epc-order-sync-cancel <?php echo 'running' === $sync_status ? '' : 'hidden'; ?>><?php esc_html_e( 'Stop', 'epasscard' ); ?></button>
						</p>
						<p class="epc-loyalty-history-sync__message" aria-live="polite"></p>
						<div class="epc-loyalty-history-sync__progress" <?php echo in_array( $sync_status, array( 'running', 'completed', 'cancelled', 'failed' ), true ) ? '' : 'hidden'; ?>>
							<div class="epc-loyalty-history-sync__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $sync_pct ); ?>">
								<span style="width: <?php echo esc_attr( (string) $sync_pct ); ?>%"></span>
							</div>
							<p class="description epc-loyalty-history-sync__stats">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: status, 2: processed, 3: found, 4: awarded, 5: skipped, 6: errors */
										__( 'Status: %1$s. Scanned %2$s of %3$s. Credited %4$s, skipped %5$s, errors %6$s.', 'epasscard' ),
										$sync_status,
										number_format_i18n( $sync_done ),
										number_format_i18n( $sync_found ),
										number_format_i18n( (int) ( $sync_job['awarded'] ?? 0 ) ),
										number_format_i18n( (int) ( $sync_job['skipped'] ?? 0 ) ),
										number_format_i18n( (int) ( $sync_job['errors'] ?? 0 ) )
									)
								);
								?>
							</p>
						</div>
					</div>
				</div>

				<div class="epc-tabs__panel" role="tabpanel" id="epc-panel-loyalty-notify" aria-labelledby="epc-tab-loyalty-notify" hidden>
					<p class="description">
						<?php esc_html_e( 'Configure email and wallet push separately for each event. Push only works after the customer has already added the pass to Apple Wallet or Google Wallet. Available tags: {customer_name}, {points}, {balance}, {tier}, {reward}, {pass_link}, {expires_at}, {reason}', 'epasscard' ); ?>
					</p>
					<div class="epc-loyalty-notify-list" data-epc-notifications>
						<?php foreach ( $notification_types as $type => $type_label ) : ?>
							<?php
							$rule          = isset( $notifications[ $type ] ) && is_array( $notifications[ $type ] ) ? $notifications[ $type ] : array();
							$legacy_msg    = (string) ( $rule['message'] ?? '' );
							$email_message = (string) ( $rule['email_message'] ?? $legacy_msg );
							$push_message  = (string) ( $rule['push_message'] ?? $legacy_msg );
							$allows_push   = class_exists( 'EPC_Loyalty_Notification_Service' ) && EPC_Loyalty_Notification_Service::supports_push( $type );
							?>
							<div class="epc-loyalty-item epc-loyalty-notify-card" data-notify-type="<?php echo esc_attr( $type ); ?>">
								<div class="epc-loyalty-item__head">
									<strong><?php echo esc_html( $type_label ); ?></strong>
								</div>
								<?php if ( 'points_expiring' === $type ) : ?>
									<div class="epc-loyalty-notify-card__timing">
										<label>
											<span><?php esc_html_e( 'Warn this many days before expiry', 'epasscard' ); ?></span>
											<input type="number" min="1" max="90" data-field="days" class="small-text" value="<?php echo esc_attr( (string) (int) ( $rule['days'] ?? 30 ) ); ?>" />
										</label>
									</div>
								<?php endif; ?>
								<div class="epc-loyalty-notify-channels<?php echo $allows_push ? '' : ' epc-loyalty-notify-channels--email-only'; ?>">
									<div class="epc-loyalty-notify-channel epc-loyalty-notify-channel--email">
										<div class="epc-loyalty-notify-channel__head">
											<strong><?php esc_html_e( 'Email', 'epasscard' ); ?></strong>
											<label class="epc-loyalty-check-inline">
												<input type="checkbox" data-field="email_enabled" value="1" <?php checked( ! empty( $rule['email_enabled'] ) ); ?> />
												<span><?php esc_html_e( 'Send email', 'epasscard' ); ?></span>
											</label>
										</div>
										<label>
											<span><?php esc_html_e( 'Email subject', 'epasscard' ); ?></span>
											<input type="text" data-field="subject" class="regular-text" value="<?php echo esc_attr( (string) ( $rule['subject'] ?? '' ) ); ?>" />
										</label>
										<label>
											<span><?php esc_html_e( 'Email message', 'epasscard' ); ?></span>
											<textarea data-field="email_message" class="large-text" rows="3"><?php echo esc_textarea( $email_message ); ?></textarea>
										</label>
									</div>
									<?php if ( $allows_push ) : ?>
										<div class="epc-loyalty-notify-channel epc-loyalty-notify-channel--push">
											<div class="epc-loyalty-notify-channel__head">
												<strong><?php esc_html_e( 'Wallet push', 'epasscard' ); ?></strong>
												<label class="epc-loyalty-check-inline">
													<input type="checkbox" data-field="push_enabled" value="1" <?php checked( ! empty( $rule['push_enabled'] ) ); ?> />
													<span><?php esc_html_e( 'Send wallet push', 'epasscard' ); ?></span>
												</label>
											</div>
											<label>
												<span><?php esc_html_e( 'Push title', 'epasscard' ); ?></span>
												<input type="text" data-field="title" class="regular-text" value="<?php echo esc_attr( (string) ( $rule['title'] ?? '' ) ); ?>" />
											</label>
											<label>
												<span><?php esc_html_e( 'Push message', 'epasscard' ); ?></span>
												<textarea data-field="push_message" class="large-text" rows="3"><?php echo esc_textarea( $push_message ); ?></textarea>
												<span class="description"><?php esc_html_e( 'Keep this short — it appears as a phone notification.', 'epasscard' ); ?></span>
											</label>
										</div>
									<?php else : ?>
										<div class="epc-loyalty-notify-channel epc-loyalty-notify-channel--note">
											<p class="description" style="margin:0;">
												<?php esc_html_e( 'Wallet push is not available here. The pass has just been created and is not in the customer’s wallet yet — email them the {pass_link} so they can add it. Push notifications work for later events after the pass is installed.', 'epasscard' ); ?>
											</p>
											<input type="hidden" data-field="push_enabled" value="0" />
											<input type="hidden" data-field="title" value="" />
											<input type="hidden" data-field="push_message" value="" />
										</div>
									<?php endif; ?>
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
	<?php
	$epc_loyalty_render_rule(
		array(
			'name'               => '',
			'id'                 => '',
			'active'             => true,
			'priority'           => 10,
			'award_type'         => 'per_currency',
			'points'             => '1',
			'minimum_spend'      => '0',
			'point_expiry_days'  => 0,
			'stack'              => true,
			'first_order'        => false,
			'include_sale_items' => true,
		),
		false,
		false,
		false
	);
	?>
</template>

<template id="epc-loyalty-tpl-tier">
	<?php
	$epc_loyalty_render_tier(
		array(
			'name'      => '',
			'id'        => '',
			'threshold' => 0,
			'active'    => true,
		),
		false
	);
	?>
</template>

<template id="epc-loyalty-tpl-milestone">
	<?php
	$epc_loyalty_render_milestone(
		array(
			'name'        => '',
			'id'          => '',
			'active'      => true,
			'threshold'   => 100,
			'repeatable'  => false,
			'claim_mode'  => 'automatic',
			'expiry_days' => 0,
			'reward'      => array(
				'type'       => 'bonus_points',
				'amount'     => 0,
				'product_id' => 0,
			),
		),
		false,
		true
	);
	?>
</template>
