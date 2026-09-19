<?php
/**
 * WooCommerce Subscriptions integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for WooCommerce Subscriptions.
 */
class EPC_Module_WooCommerce_Subscriptions extends EPC_Module {

	/**
	 * Subscription IDs whose status just changed (skip duplicate update hook).
	 *
	 * @var array<int, bool>
	 */
	private static array $status_just_changed = array();

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'woocommerce-subscriptions';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WooCommerce Subscriptions', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_woocommerce_subscriptions_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'WooCommerce Subscriptions is not installed or activated. Install the extension, activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name'   => __( 'Display name', 'epasscard' ),
			'user_email'          => __( 'Email', 'epasscard' ),
			'user_first_name'     => __( 'First name', 'epasscard' ),
			'user_last_name'      => __( 'Last name', 'epasscard' ),
			'user_full_name'      => __( 'Full name', 'epasscard' ),
			'subscription_id'     => __( 'Subscription ID', 'epasscard' ),
			'subscription_status' => __( 'Status', 'epasscard' ),
			'product_name'        => __( 'Product name', 'epasscard' ),
			'start_date'          => __( 'Start date', 'epasscard' ),
			'next_payment_date'   => __( 'Next payment date', 'epasscard' ),
			'end_date'            => __( 'End date', 'epasscard' ),
			'order_id'            => __( 'Parent order ID', 'epasscard' ),
		);
	}

	/**
	 * Option key for per-status pass behavior rules.
	 *
	 * @return string
	 */
	public function get_status_rules_option_key() {
		return 'epc_wcs_status_rules';
	}

	/**
	 * Pass actions available per subscription status.
	 *
	 * @return array<string, string>
	 */
	public function get_status_action_options() {
		return array(
			'none'   => __( 'Do nothing', 'epasscard' ),
			'sync'   => __( 'Create or update pass', 'epasscard' ),
			'update' => __( 'Update pass only', 'epasscard' ),
			'revoke' => __( 'Revoke pass', 'epasscard' ),
		);
	}

	/**
	 * Default pass behavior per subscription status.
	 *
	 * @return array<string, string>
	 */
	public function get_default_status_rules() {
		return array(
			'pending'        => 'none',
			'active'         => 'sync',
			'on-hold'        => 'update',
			'pending-cancel' => 'update',
			'cancelled'      => 'revoke',
			'expired'        => 'revoke',
			'switched'       => 'revoke',
		);
	}

	/**
	 * Subscription statuses available for configuration.
	 *
	 * @return array<string, string> status slug => label
	 */
	public function get_configurable_subscription_statuses() {
		if ( function_exists( 'wcs_get_subscription_statuses' ) ) {
			$statuses = array();
			foreach ( wcs_get_subscription_statuses() as $key => $label ) {
				$slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
				$statuses[ $slug ] = $label;
			}
			return $statuses;
		}

		return array(
			'pending'        => __( 'Pending', 'epasscard' ),
			'active'         => __( 'Active', 'epasscard' ),
			'on-hold'        => __( 'On hold', 'epasscard' ),
			'pending-cancel' => __( 'Pending cancellation', 'epasscard' ),
			'cancelled'      => __( 'Cancelled', 'epasscard' ),
			'expired'        => __( 'Expired', 'epasscard' ),
			'switched'       => __( 'Switched', 'epasscard' ),
		);
	}

	/**
	 * Saved status rules merged with defaults.
	 *
	 * @return array<string, string>
	 */
	public function get_status_rules() {
		$saved    = get_option( $this->get_status_rules_option_key(), array() );
		$defaults = $this->get_default_status_rules();
		$merged   = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		$allowed  = array_keys( $this->get_status_action_options() );

		foreach ( $merged as $status => $action ) {
			if ( ! in_array( $action, $allowed, true ) ) {
				$merged[ $status ] = $defaults[ $status ] ?? 'none';
			}
		}

		return $merged;
	}

	/**
	 * Get configured action for a subscription status.
	 *
	 * @param string $status Subscription status slug.
	 * @return string
	 */
	public function get_status_rule( $status ) {
		$status = sanitize_key( (string) $status );
		$rules  = $this->get_status_rules();

		return $rules[ $status ] ?? 'none';
	}

	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		return $this->get_configurable_subscription_statuses();
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}

		$statuses = $this->get_configurable_subscription_statuses();
		$actions  = $this->get_status_action_options();
		$rules    = $this->get_status_rules();
		?>
		<div id="epc-section-pass-behavior" class="epc-section epc-section--status-rules">
			<h2><?php esc_html_e( 'Pass behavior by subscription status', 'epasscard' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose what happens to the wallet pass when a subscription reaches each status or is saved while in that status.', 'epasscard' ); ?>
			</p>
			<form class="epc-ajax-form" data-epc-action="<?php echo esc_attr( 'epc_save_status_rules_' . $this->get_slug() ); ?>" method="post" action="">
				<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
				<table class="widefat striped epc-status-rules-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Subscription status', 'epasscard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pass action', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $statuses as $status => $label ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $label ); ?></strong></td>
								<td>
									<select name="epc_status_rule_<?php echo esc_attr( $status ); ?>">
										<?php foreach ( $actions as $action_key => $action_label ) : ?>
											<option value="<?php echo esc_attr( $action_key ); ?>" <?php selected( $rules[ $status ] ?? 'none', $action_key ); ?>>
												<?php echo esc_html( $action_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<?php submit_button( __( 'Save pass behavior', 'epasscard' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
		$this->render_native_reminder_notice(
			admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ),
			__( 'Open reminder timing (Subscriptions settings)', 'epasscard' )
		);
		$this->render_push_notification_settings();
	}

	/**
	 * @inheritDoc
	 */
	public function uses_native_reminder_timing() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'renewal'           => __( 'Next payment / renewal', 'epasscard' ),
			'trial_end'         => __( 'Trial ending', 'epasscard' ),
			'subscription_end'  => __( 'Subscription ending', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'renewal' => array(
				'enabled' => false,
				'title'   => __( 'Subscription renewal coming up', 'epasscard' ),
				'message' => __( 'Your {product_name} subscription renews on {next_payment_date}.', 'epasscard' ),
			),
			'trial_end' => array(
				'enabled' => false,
				'title'   => __( 'Trial ending soon', 'epasscard' ),
				'message' => __( 'Your {product_name} trial ends on {trial_end_date}.', 'epasscard' ),
			),
			'subscription_end' => array(
				'enabled' => false,
				'title'   => __( 'Subscription ending soon', 'epasscard' ),
				'message' => __( 'Your {product_name} subscription ends on {end_date}. Renew to keep your pass active.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array(
			'product_name',
			'subscription_id',
			'next_payment_date',
			'trial_end_date',
			'end_date',
			'user_full_name',
			'user_first_name',
			'user_last_name',
			'user_email',
		);
	}

	/**
	 * Send wallet push when WooCommerce Subscriptions fires a customer notification.
	 *
	 * @param int $subscription_id Subscription id.
	 * @return void
	 */
	public function on_wcs_customer_notification( $subscription_id ) {
		if ( ! EPC_Api_Client::is_configured() || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		if ( class_exists( 'WC_Subscriptions_Email_Notifications' ) && ! WC_Subscriptions_Email_Notifications::should_send_notification() ) {
			return;
		}

		$subscription = wcs_get_subscription( absint( $subscription_id ) );
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( ! $this->wcs_notification_would_send_email( $subscription ) ) {
			return;
		}

		$type = $this->get_notification_type_for_action();
		if ( '' === $type ) {
			return;
		}

		$copy = $this->build_push_notification_copy( $type, $this->build_wcs_push_replacements( $subscription ) );
		if ( null === $copy ) {
			return;
		}

		EPC_Pass_Notifications::send_for_module_source(
			$this->get_slug(),
			(int) $subscription->get_id(),
			$copy['title'],
			$copy['message']
		);
	}

	/**
	 * Whether WCS would send the customer notification email for this hook.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return bool
	 */
	private function wcs_notification_would_send_email( $subscription ) {
		$email = $this->get_wcs_notification_email_for_action( $subscription );
		return $email && $email->is_enabled() && $email->should_send_reminder_email( $subscription );
	}

	/**
	 * Map current WCS notification action to EpassCard notification type.
	 *
	 * @return string
	 */
	private function get_notification_type_for_action() {
		switch ( current_action() ) {
			case 'woocommerce_scheduled_subscription_customer_notification_renewal':
				return 'renewal';
			case 'woocommerce_scheduled_subscription_customer_notification_trial_expiration':
				return 'trial_end';
			case 'woocommerce_scheduled_subscription_customer_notification_expiration':
				return 'subscription_end';
		}

		return '';
	}

	/**
	 * Placeholder values for WCS push copy.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<string, string>
	 */
	private function build_wcs_push_replacements( $subscription ) {
		$user_id    = (int) $subscription->get_user_id();
		$user       = get_userdata( $user_id );
		$product_id = $this->get_primary_product_id( $subscription );
		$first_name = (string) get_user_meta( $user_id, 'first_name', true );
		$last_name  = (string) get_user_meta( $user_id, 'last_name', true );
		$next       = $subscription->get_date( 'next_payment' );
		$trial_end  = $subscription->get_date( 'trial_end' );
		$end        = $subscription->get_date( 'end' );

		return array(
			'product_name'        => $this->get_entity_label( $product_id ),
			'subscription_id'     => (string) $subscription->get_id(),
			'next_payment_date'   => $next ? mysql2date( get_option( 'date_format' ), $next ) : '',
			'trial_end_date'      => $trial_end ? mysql2date( get_option( 'date_format' ), $trial_end ) : '',
			'end_date'            => epc_format_pass_expiry_datetime( $end ? (string) $end : '' ),
			'user_full_name'      => $user ? $this->get_user_full_name( $first_name, $last_name, $user->display_name ) : '',
			'user_first_name'     => $first_name,
			'user_last_name'      => $last_name,
			'user_email'          => $user ? (string) $user->user_email : (string) $subscription->get_billing_email(),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! is_object( $pass_row ) ) {
			return array();
		}

		$subscription = wcs_get_subscription( (int) $pass_row->source_id );
		if ( ! $subscription ) {
			return array();
		}

		return $this->build_wcs_push_replacements( $subscription );
	}

	/**
	 * Resolve the WCS customer notification email for the current action.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return WCS_Email_Customer_Notification|null
	 */
	private function get_wcs_notification_email_for_action( $subscription ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$action = current_action();

		switch ( $action ) {
			case 'woocommerce_scheduled_subscription_customer_notification_renewal':
				if ( $subscription->get_total() <= 0 ) {
					return null;
				}

				if ( $subscription->is_manual() ) {
					return $emails['WCS_Email_Customer_Notification_Manual_Renewal'] ?? null;
				}

				return $emails['WCS_Email_Customer_Notification_Auto_Renewal'] ?? null;

			case 'woocommerce_scheduled_subscription_customer_notification_trial_expiration':
				if ( $subscription->is_manual() ) {
					return $emails['WCS_Email_Customer_Notification_Manual_Trial_Expiration'] ?? null;
				}

				return $emails['WCS_Email_Customer_Notification_Auto_Trial_Expiration'] ?? null;

			case 'woocommerce_scheduled_subscription_customer_notification_expiration':
				return $emails['WCS_Email_Customer_Notification_Subscription_Expiration'] ?? null;
		}

		return null;
	}

	/**
	 * Register WooCommerce Subscriptions customer notification hooks.
	 *
	 * @return void
	 */
	private function register_notification_push_hooks() {
		$hooks = array(
			'woocommerce_scheduled_subscription_customer_notification_renewal',
			'woocommerce_scheduled_subscription_customer_notification_trial_expiration',
			'woocommerce_scheduled_subscription_customer_notification_expiration',
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'on_wcs_customer_notification' ), 20 );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$statuses = array( 'publish', 'private', 'draft', 'pending' );
		$types    = array( 'subscription', 'variable-subscription' );

		$products = wc_get_products(
			array(
				'type'   => $types,
				'limit'  => -1,
				'status' => $statuses,
				'return' => 'objects',
			)
		);

		$out = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $product->get_id(),
				'label' => (string) $product->get_name(),
			);
		}

		if ( ! empty( $out ) ) {
			return $out;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Fallback when WC product helpers unavailable; terms list is small/fixed.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => $types,
					),
				),
			)
		);

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $product->get_id(),
				'label' => (string) $product->get_name(),
			);
		}

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Subscription product', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create a WooCommerce product with type Simple subscription or Variable subscription. Simple or variable products without a subscription type cannot be mapped here.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'post-new.php?post_type=product' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Create subscription product', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '#' . absint( $entity_id );
		}
		$product = wc_get_product( absint( $entity_id ) );
		return $product ? (string) $product->get_name() : '#' . absint( $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		if ( parent::should_enqueue_pass_action_assets( $hook ) ) {
			return true;
		}

		if ( 'woocommerce_page_wc-orders--shop_subscription' === $hook ) {
			return true;
		}

		if ( 'edit.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'shop_subscription' === $screen->post_type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		$this->register_notification_push_hooks();

		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_subscription_status_updated' ), 10, 3 );
		add_action( 'woocommerce_update_subscription', array( $this, 'on_subscription_updated' ), 10, 2 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'on_renewal_complete' ), 10, 2 );

		add_filter( 'woocommerce_shop_subscription_list_table_columns', array( $this, 'subscription_columns' ) );
		add_filter( 'manage_edit-shop_subscription_columns', array( $this, 'subscription_columns' ) );
		add_action( 'woocommerce_shop_subscription_list_table_custom_column', array( $this, 'render_subscription_column' ), 10, 2 );
		add_action( 'manage_shop_subscription_posts_custom_column', array( $this, 'render_subscription_column' ), 10, 2 );
		add_filter( 'woocommerce_subscription_list_table_actions', array( $this, 'subscription_list_pass_actions' ), 20, 2 );
	}

	/**
	 * Apply configured pass action when subscription status changes.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $new_status   New status.
	 * @param string          $old_status   Old status.
	 * @return void
	 */
	public function on_subscription_status_updated( $subscription, $new_status, $old_status ) {
		unset( $old_status );

		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		self::$status_just_changed[ (int) $subscription->get_id() ] = true;
		$this->apply_status_rule( $subscription, (string) $new_status );
	}

	/**
	 * Update pass when subscription data is saved (admin edit, dates, billing, etc.).
	 *
	 * @param int             $subscription_id Subscription id.
	 * @param WC_Subscription $subscription    Subscription.
	 * @return void
	 */
	public function on_subscription_updated( $subscription_id, $subscription ) {
		$subscription_id = absint( $subscription_id );

		if ( isset( self::$status_just_changed[ $subscription_id ] ) ) {
			unset( self::$status_just_changed[ $subscription_id ] );
			return;
		}

		if ( ! $subscription instanceof WC_Subscription ) {
			$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		}

		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$this->apply_status_rule( $subscription, (string) $subscription->get_status() );
	}

	/**
	 * Update pass on renewal.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param WC_Order        $order        Renewal order.
	 * @return void
	 */
	public function on_renewal_complete( $subscription, $order ) {
		unset( $order );

		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		$this->sync_subscription( $subscription, 'sync' );
	}

	/**
	 * Run configured pass action for a subscription status.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $status       Status slug.
	 * @return void
	 */
	private function apply_status_rule( $subscription, $status ) {
		if ( ! EPC_Api_Client::is_configured() ) {
			return;
		}

		$action = $this->get_status_rule( $status );

		switch ( $action ) {
			case 'sync':
				$this->sync_subscription( $subscription, 'sync' );
				break;
			case 'update':
				$this->sync_subscription( $subscription, 'update' );
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (int) $subscription->get_id() );
				break;
			case 'none':
			default:
				break;
		}
	}

	/**
	 * Add EpassCard column to subscriptions list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function subscription_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_items' === $key ) {
				$new['epasscard_pass'] = __( 'EpassCard', 'epasscard' );
			}
		}

		if ( ! isset( $new['epasscard_pass'] ) ) {
			$new['epasscard_pass'] = __( 'EpassCard', 'epasscard' );
		}

		return $new;
	}

	/**
	 * Render EpassCard column on subscriptions list.
	 *
	 * @param string          $column       Column key.
	 * @param WC_Subscription $subscription Subscription object or id.
	 * @return void
	 */
	public function render_subscription_column( $column, $subscription ) {
		if ( 'epasscard_pass' !== $column ) {
			return;
		}

		if ( ! $subscription instanceof WC_Subscription ) {
			$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription ) : null;
		}

		if ( ! $subscription instanceof WC_Subscription ) {
			echo '&mdash;';
			return;
		}

		$links = $this->render_pass_action_links( (int) $subscription->get_id(), $this->get_subscriptions_list_url() );
		echo '' !== $links ? wp_kses( (string) $links, EPC_Module::get_pass_action_allowed_html() ) : '&mdash;';
	}

	/**
	 * Add pass actions to subscription row actions.
	 *
	 * @param array<string, string> $actions      Row actions.
	 * @param WC_Subscription       $subscription Subscription.
	 * @return array<string, string>
	 */
	public function subscription_list_pass_actions( $actions, $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return $actions;
		}

		$links = $this->render_pass_action_links( (int) $subscription->get_id(), $this->get_subscriptions_list_url() );
		if ( '' === $links ) {
			return $actions;
		}

		$actions['epasscard_pass'] = sprintf(
			'<span class="epasscard-pass-actions">%s</span>',
			wp_kses( (string) $links, EPC_Module::get_pass_action_allowed_html() )
		);

		return $actions;
	}

	/**
	 * Subscriptions admin list URL for redirects.
	 *
	 * @return string
	 */
	private function get_subscriptions_list_url() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && function_exists( 'wc_get_container' ) ) {
			return admin_url( 'admin.php?page=wc-orders--shop_subscription' );
		}

		return admin_url( 'edit.php?post_type=shop_subscription' );
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_Error( 'epc_wcs_missing', __( 'WooCommerce Subscriptions is not available.', 'epasscard' ) );
		}

		$subscription = wcs_get_subscription( absint( $source_id ) );
		if ( ! $subscription instanceof WC_Subscription ) {
			return new WP_Error( 'epc_invalid_subscription', __( 'Subscription not found.', 'epasscard' ) );
		}

		return $this->sync_subscription( $subscription, $mode );
	}

	/**
	 * Sync pass for a subscription.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param string          $mode         sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_subscription( $subscription, $mode = 'sync' ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return new WP_Error( 'epc_invalid_subscription', __( 'Subscription not found.', 'epasscard' ) );
		}

		$product_id = $this->get_primary_product_id( $subscription );
		if ( $product_id <= 0 ) {
			return new WP_Error( 'epc_no_product', __( 'No subscription product found on this record.', 'epasscard' ) );
		}

		$mapping = $this->get_mapping( $product_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this subscription product.', 'epasscard' ) );
		}

		$user_id = (int) $subscription->get_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'epc_no_user', __( 'Subscriber user not found.', 'epasscard' ) );
		}

		$product_name = $this->get_entity_label( $product_id );
		$start        = $subscription->get_date( 'start' );
		$next         = $subscription->get_date( 'next_payment' );
		$end          = $subscription->get_date( 'end' );
		$first_name   = (string) get_user_meta( $user_id, 'first_name', true );
		$last_name    = (string) get_user_meta( $user_id, 'last_name', true );

		$values = array(
			'user_display_name'   => $user->display_name,
			'user_email'          => $user->user_email,
			'user_first_name'     => $first_name,
			'user_last_name'      => $last_name,
			'user_full_name'      => $this->get_user_full_name( $first_name, $last_name, $user->display_name ),
			'subscription_id'     => (string) $subscription->get_id(),
			'subscription_status' => (string) $subscription->get_status(),
			'product_name'        => $product_name,
			'start_date'          => $start ? mysql2date( get_option( 'date_format' ), $start ) : '',
			'next_payment_date'   => $next ? mysql2date( get_option( 'date_format' ), $next ) : '',
			'end_date'            => epc_format_pass_expiry_datetime( $end ? (string) $end : '' ),
			'order_id'            => (string) $subscription->get_parent_id(),
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			(int) $subscription->get_id(),
			$product_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}

	/**
	 * First subscription line item product id.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return int
	 */
	private function get_primary_product_id( $subscription ) {
		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( $product_id > 0 ) {
				return $product_id;
			}
			$variation_id = (int) $item->get_variation_id();
			if ( $variation_id > 0 && function_exists( 'wc_get_product' ) ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					return (int) $variation->get_parent_id();
				}
			}
		}
		return 0;
	}

	/**
	 * Build full name from first and last name fields.
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @param string $fallback   Fallback when both names are empty.
	 * @return string
	 */
	private function get_user_full_name( $first_name, $last_name, $fallback = '' ) {
		return epc_format_user_full_name( $first_name, $last_name, $fallback );
	}
}
