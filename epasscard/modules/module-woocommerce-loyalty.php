<?php
/**
 * WooCommerce loyalty integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides one customer loyalty pass backed by the immutable points ledger.
 */
class EPC_Module_WooCommerce_Loyalty extends EPC_Module {

	/**
	 * Synthetic loyalty program entity ID.
	 */
	private const PROGRAM_ENTITY_ID = 1;

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'woocommerce-loyalty';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WooCommerce Loyalty', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_woocommerce_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'WooCommerce', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'WooCommerce is not installed or activated. Activate WooCommerce to earn and manage loyalty points.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_full_name'  => __( 'Customer name', 'epasscard' ),
			'user_email'      => __( 'Customer email', 'epasscard' ),
			'member_id'       => __( 'Membership ID', 'epasscard' ),
			'points_balance'  => __( 'Spendable points', 'epasscard' ),
			'lifetime_points' => __( 'Lifetime earned points', 'epasscard' ),
			'tier'            => __( 'Tier', 'epasscard' ),
			'next_tier'       => __( 'Next tier', 'epasscard' ),
			'next_reward'     => __( 'Next reward', 'epasscard' ),
			'milestone'       => __( 'Milestone progress', 'epasscard' ),
			'reward_summary'  => __( 'Reward summary', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		return array(
			array(
				'id'    => self::PROGRAM_ENTITY_ID,
				'label' => __( 'Loyalty Program', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		unset( $entity_id );
		return __( 'Loyalty Program', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Program', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function get_extra_admin_nav_items() {
		return array(
			array(
				'id'    => 'loyalty-pass-design',
				'label' => __( 'Pass Design', 'epasscard' ),
				'section' => 'loyalty-pass-design',
				'icon'  => 'palette',
			),
			array(
				'id'      => 'loyalty-program',
				'label'   => __( 'Rules & Rewards', 'epasscard' ),
				'section' => 'loyalty-program',
				'icon'    => 'rule',
			),
			array(
				'id'    => 'loyalty-customers',
				'label' => __( 'Customers', 'epasscard' ),
				'url'   => $this->get_customers_admin_url(),
				'icon'  => 'group',
			),
		);
	}

	/**
	 * Dedicated customers screen slug.
	 *
	 * @return string
	 */
	public function get_customers_page_slug() {
		return 'epc-woocommerce-loyalty-customers';
	}

	/**
	 * Admin URL for the loyalty customers list.
	 *
	 * @return string
	 */
	public function get_customers_admin_url() {
		return admin_url( 'admin.php?page=' . $this->get_customers_page_slug() );
	}

	/**
	 * @inheritDoc
	 */
	public function get_issued_passes_page_slug() {
		return 'epc-woocommerce-loyalty-passes';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_issued_passes_description() {
		return __( 'Wallet passes issued to loyalty members.', 'epasscard' );
	}

	/**
	 * Loyalty pass actions are keyed by WordPress user ID.
	 *
	 * @param int $user_id User id.
	 * @return array<int, int>
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );
		return $user_id > 0 ? array( $user_id ) : array();
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$user_id = absint( $source_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'epc_loyalty_user_missing', __( 'Loyalty customer not found.', 'epasscard' ) );
		}

		$account = EPC_Loyalty_Account_Service::get_or_create( $user_id );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$mapping = $this->get_mapping( self::PROGRAM_ENTITY_ID );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_loyalty_no_mapping', __( 'No pass template is mapped to the loyalty program.', 'epasscard' ) );
		}

		$first_name = (string) get_user_meta( $user_id, 'first_name', true );
		$last_name  = (string) get_user_meta( $user_id, 'last_name', true );
		$tier       = EPC_Loyalty_Reward_Service::get_tier_progress( (int) $account->lifetime_points );
		$milestone  = EPC_Loyalty_Reward_Service::get_next_milestone( (int) $account->lifetime_points );
		$claims     = EPC_Loyalty_Reward_Service::get_available_claims( $user_id );
		$existing   = EPC_DB::get_pass( $this->get_slug(), $user_id );

		global $wpdb;
		$lock_name = 'epc_loyalty_pass_' . get_current_blog_id() . '_' . $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock prevents duplicate remote pass creation.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 15 ) );
		if ( 1 !== $locked ) {
			return new WP_Error( 'epc_loyalty_pass_locked', __( 'This loyalty pass is already being synchronized. Please try again.', 'epasscard' ) );
		}

		try {
			$result = EPC_Pass_Service::sync_pass(
				$this->get_slug(),
				$user_id,
				self::PROGRAM_ENTITY_ID,
				$user_id,
				$mapping,
				array(
					'user_full_name'  => epc_format_user_full_name( $first_name, $last_name, $user->display_name ),
					'user_email'      => (string) $user->user_email,
					'member_id'       => (string) $account->member_id,
					'points_balance'  => (string) $account->points_balance,
					'lifetime_points' => (string) $account->lifetime_points,
					'tier'            => $tier['current'] ? (string) $tier['current']['name'] : '',
					'next_tier'       => $tier['next'] ? (string) $tier['next']['name'] : '',
					'next_reward'     => $milestone ? (string) $milestone['name'] : '',
					'milestone'       => $milestone
						? sprintf( '%1$d / %2$d', (int) $account->lifetime_points, (int) $milestone['next_threshold'] )
						: '',
					'reward_summary'  => sprintf(
						/* translators: %d: number of rewards. */
						_n( '%d reward available', '%d rewards available', count( $claims ), 'epasscard' ),
						count( $claims )
					),
				),
				$mode
			);
			if ( ! is_wp_error( $result ) && ( ! $existing || empty( $existing->pass_uid ) ) ) {
				$pass = EPC_DB::get_pass( $this->get_slug(), $user_id );
				if ( $pass && ! empty( $pass->pass_link ) ) {
					do_action( 'epc_loyalty_pass_ready', $user_id, (string) $pass->pass_link );
				}
			}
			return $result;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the matching advisory lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Queue one debounced pass update after a committed balance change.
	 *
	 * @param object $entry Ledger entry.
	 * @param object $account Loyalty account.
	 * @return void
	 */
	public function on_balance_changed( $entry, $account ) {
		unset( $entry );

		$user_id = isset( $account->user_id ) ? absint( $account->user_id ) : 0;
		if ( $user_id <= 0 || ! EPC_Api_Client::is_configured() || EPC_Loyalty_Order_Sync_Service::should_skip_pass_sync() ) {
			return;
		}

		$args = array( $user_id );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'epc_loyalty_sync_pass', $args, 'epasscard-loyalty' ) ) {
				as_schedule_single_action( time() + 10, 'epc_loyalty_sync_pass', $args, 'epasscard-loyalty', true );
			}
			return;
		}

		if ( ! wp_next_scheduled( 'epc_loyalty_sync_pass', $args ) ) {
			wp_schedule_single_event( time() + 10, 'epc_loyalty_sync_pass', $args );
		}
	}

	/**
	 * Queue pass refresh when a reward claim changes.
	 *
	 * @param object $claim Reward claim.
	 * @return void
	 */
	public function on_reward_changed( $claim ) {
		$user_id = absint( $claim->user_id ?? 0 );
		$account = $user_id > 0 ? EPC_Loyalty_Account_Service::get( $user_id ) : null;
		if ( $account ) {
			$this->on_balance_changed( null, $account );
		}
	}

	/**
	 * Run a queued loyalty pass synchronization.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function run_scheduled_pass_sync( $user_id ) {
		$result = $this->sync_by_source_id( absint( $user_id ), 'sync' );
		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when a queued loyalty pass sync fails.
			 *
			 * @param \WP_Error $result Sync error.
			 * @param int       $user_id WordPress user ID.
			 */
			do_action( 'epc_loyalty_pass_sync_failed', $result, absint( $user_id ) );
		}
	}

	/**
	 * Render configurable loyalty definitions and the remote pass designer.
	 *
	 * Definitions use JSON so all rule filters and reward payload fields remain
	 * editable without silently discarding extension fields.
	 *
	 * @return void
	 */
	public function render_module_settings() {
		$design  = EPC_Loyalty_Pass_Design_Service::get_design();
		$preview = EPC_Loyalty_Pass_Design_Service::preview_sample_values();
		include EPC_PLUGIN_DIR . 'admin/views/loyalty/pass-design.php';

		$program            = EPC_Loyalty_Order_Service::get_settings();
		$email              = EPC_Loyalty_Customer_Service::get_email_settings();
		$rules              = EPC_Loyalty_Rule_Service::get_rules();
		$tiers              = EPC_Loyalty_Reward_Service::get_tiers();
		$milestones         = EPC_Loyalty_Reward_Service::get_milestones();
		$notifications      = EPC_Loyalty_Notification_Service::get_settings();
		$notification_types = EPC_Loyalty_Notification_Service::get_types();
		$redemption         = EPC_Loyalty_Redemption_Service::get_settings();
		$order_statuses     = $this->get_order_status_choices();
		$product_categories = $this->get_product_category_choices();
		$role_choices       = $this->get_role_choices();
		$order_sync         = EPC_Loyalty_Order_Sync_Service::get_job();

		include EPC_PLUGIN_DIR . 'admin/views/loyalty/program-settings.php';
	}

	/**
	 * Save the remote loyalty pass design after API success only.
	 *
	 * @return void
	 */
	public function ajax_save_pass_design() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$colors = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested colors are sanitized in EPC_Loyalty_Pass_Design_Service.
		if ( isset( $_POST['colors'] ) && is_array( $_POST['colors'] ) ) {
			$colors = wp_unslash( $_POST['colors'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$result = EPC_Loyalty_Pass_Design_Service::save_and_sync(
			array(
				'design_source'     => isset( $_POST['design_source'] ) ? sanitize_key( wp_unslash( (string) $_POST['design_source'] ) ) : 'form',
				'template_uid'      => isset( $_POST['template_uid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_uid'] ) ) : '',
				'template_name'     => isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_name'] ) ) : '',
				'organization_name' => isset( $_POST['organization_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['organization_name'] ) ) : '',
				'logo_id'           => isset( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0,
				'logo_url'          => isset( $_POST['logo_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['logo_url'] ) ) : '',
				'strip_id'          => isset( $_POST['strip_id'] ) ? absint( wp_unslash( $_POST['strip_id'] ) ) : 0,
				'strip_url'         => isset( $_POST['strip_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['strip_url'] ) ) : '',
				'points_label'      => isset( $_POST['points_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['points_label'] ) ) : '',
				'name_label'        => isset( $_POST['name_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name_label'] ) ) : '',
				'secondary_mode'    => isset( $_POST['secondary_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['secondary_mode'] ) ) : 'tier',
				'secondary_label'   => isset( $_POST['secondary_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['secondary_label'] ) ) : '',
				'barcode_format'    => isset( $_POST['barcode_format'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['barcode_format'] ) ) : 'QR',
				'pass_limit'        => 0,
				'expire_date'       => isset( $_POST['expire_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expire_date'] ) ) : '',
				'colors'            => $colors,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Loyalty pass design saved to EpassCard.', 'epasscard' ),
				'design'  => $result,
				'reload'  => true,
			)
		);
	}

	/**
	 * Create or refresh a test loyalty pass for the current admin.
	 *
	 * @return void
	 */
	public function ajax_test_pass() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$result  = EPC_Loyalty_Pass_Design_Service::issue_test_pass( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$pass = EPC_DB::get_pass( $this->get_slug(), $user_id );
		wp_send_json_success(
			array(
				'message'   => __( 'Test loyalty pass synchronized.', 'epasscard' ),
				'pass_link' => $pass && ! empty( $pass->pass_link ) ? (string) $pass->pass_link : '',
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		return $this->is_loyalty_admin_screen( $hook );
	}

	/**
	 * Whether the current request is a loyalty dashboard or list screen.
	 *
	 * @param string $hook Current admin hook.
	 * @return bool
	 */
	private function is_loyalty_admin_screen( $hook = '' ) {
		$pages = array(
			'epc-' . $this->get_slug(),
			$this->get_customers_page_slug(),
			$this->get_issued_passes_page_slug(),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( in_array( $page, $pages, true ) ) {
			return true;
		}

		$hooks = array();
		foreach ( $pages as $slug ) {
			$hooks[] = 'epasscard_page_' . $slug;
			$hooks[] = 'admin_page_' . $slug;
		}

		return in_array( (string) $hook, $hooks, true );
	}

	/**
	 * Enqueue loyalty designer assets on the module screen.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		parent::enqueue_admin_assets( $hook );

		if ( ! $this->is_loyalty_admin_screen( $hook ) || ! $this->is_available() || ! $this->current_user_can_manage_passes() ) {
			return;
		}

		$is_dashboard = ( 'epasscard_page_epc-' . $this->get_slug() ) === $hook
			|| ( isset( $_GET['page'] ) && 'epc-' . $this->get_slug() === sanitize_key( wp_unslash( (string) $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$style_deps   = array( 'epc-admin', 'dashicons' );
		$script_deps  = array( 'jquery', 'epc-admin' );

		if ( $is_dashboard ) {
			wp_enqueue_media();
		}

		if ( $is_dashboard && function_exists( 'WC' ) && WC() ) {
			wp_enqueue_style(
				'select2',
				WC()->plugin_url() . '/assets/css/select2.css',
				array(),
				defined( 'WC_VERSION' ) ? WC_VERSION : EPC_VERSION
			);
			wp_enqueue_script( 'wc-enhanced-select' );
			$style_deps[]  = 'select2';
			$script_deps[] = 'wc-enhanced-select';
		}

		$style_path  = EPC_PLUGIN_DIR . 'admin/css/loyalty-admin.css';
		$script_path = EPC_PLUGIN_DIR . 'admin/js/loyalty-admin.js';

		wp_enqueue_style(
			'epc-loyalty-admin',
			EPC_PLUGIN_URL . 'admin/css/loyalty-admin.css',
			$style_deps,
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : EPC_VERSION
		);
		wp_enqueue_script(
			'epc-loyalty-admin',
			EPC_PLUGIN_URL . 'admin/js/loyalty-admin.js',
			$script_deps,
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : EPC_VERSION,
			true
		);
		wp_localize_script(
			'epc-loyalty-admin',
			'epcLoyaltyAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epc_admin' ),
				'preview' => $is_dashboard ? EPC_Loyalty_Pass_Design_Service::preview_sample_values() : array(),
				'i18n'    => array(
					'saving'         => __( 'Saving pass design…', 'epasscard' ),
					'saved'          => __( 'Loyalty pass design saved.', 'epasscard' ),
					'error'          => __( 'Unable to save the loyalty pass design.', 'epasscard' ),
					'testing'        => __( 'Issuing test pass…', 'epasscard' ),
					'testOk'         => __( 'Test pass ready.', 'epasscard' ),
					'mediaTitle'     => __( 'Select loyalty pass image', 'epasscard' ),
					'mediaButton'    => __( 'Use image', 'epasscard' ),
					'selectTemplate'=> __( '— Select a template —', 'epasscard' ),
					'loading'        => __( 'Loading…', 'epasscard' ),
					'templatesRefreshed' => __( 'Template list refreshed.', 'epasscard' ),
					'programSaving'  => __( 'Saving loyalty program…', 'epasscard' ),
					'programSaved'   => __( 'Loyalty program saved.', 'epasscard' ),
					'programError'   => __( 'Unable to save the loyalty program.', 'epasscard' ),
					'pointsPerOrder' => __( 'Points per order', 'epasscard' ),
					'pointsPerUnit'  => __( 'Points per currency unit', 'epasscard' ),
					'rewardBonusPoints' => __( 'Bonus points', 'epasscard' ),
					'rewardCouponAmount' => __( 'Coupon amount', 'epasscard' ),
					'rewardDiscountPercent' => __( 'Discount percent', 'epasscard' ),
					'historyLoading' => __( 'Loading points history…', 'epasscard' ),
					'historyError'   => __( 'Unable to load points history.', 'epasscard' ),
					'historyEmpty'   => __( 'No loyalty activity yet.', 'epasscard' ),
					'historyPage'    => __( 'Page %1$s of %2$s (%3$s entries)', 'epasscard' ),
					'syncConfirm'    => __( 'Credit matching past orders with the current earning rules? This cannot un-award points later. Notifications will not be sent.', 'epasscard' ),
					'syncCounting'   => __( 'Counting matching orders…', 'epasscard' ),
					'syncStarting'   => __( 'Starting past-order sync…', 'epasscard' ),
					'syncStopping'   => __( 'Stopping…', 'epasscard' ),
					'syncProgress'   => __( 'Status: %1$s. Scanned %2$s of %3$s. Credited %4$s, skipped %5$s, errors %6$s.', 'epasscard' ),
					'syncError'      => __( 'Unable to run the past-order sync.', 'epasscard' ),
				),
			)
		);
	}

	/**
	 * Save loyalty definitions from the admin form.
	 *
	 * @return void
	 */
	public function ajax_save_loyalty_program() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$decoded = array();
		foreach ( array( 'earning_rules', 'tiers', 'milestones', 'redemption', 'notifications' ) as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded/array payloads are sanitized by domain services.
			$raw = $_POST[ $field ] ?? null;
			if ( is_string( $raw ) ) {
				$value = json_decode( wp_unslash( $raw ), true );
			} elseif ( is_array( $raw ) ) {
				$value = wp_unslash( $raw );
			} else {
				$value = ( 'redemption' === $field || 'notifications' === $field ) ? array() : array();
			}
			if ( ! is_array( $value ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: field name. */
							__( 'Invalid data in %s.', 'epasscard' ),
							sanitize_text_field( $field )
						),
					),
					400
				);
			}
			$decoded[ $field ] = $value;
		}

		$rounding = isset( $_POST['rounding'] ) ? sanitize_key( wp_unslash( (string) $_POST['rounding'] ) ) : 'floor';
		if ( ! in_array( $rounding, array( 'floor', 'round', 'ceil' ), true ) ) {
			$rounding = 'floor';
		}

		$statuses       = $this->sanitize_status_list( wp_unslash( $_POST['qualifying_statuses'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in helper.
		$email_statuses = $this->sanitize_status_list( wp_unslash( $_POST['order_email_statuses'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in helper.

		update_option(
			'epc_loyalty_program',
			array(
				'version'                         => 1,
				'points_per_currency'             => 1,
				'rounding'                        => $rounding,
				'qualifying_statuses'             => empty( $statuses ) ? array( 'processing', 'completed' ) : $statuses,
				'include_pass_on_order_emails'    => ! empty( $_POST['include_pass_on_order_emails'] ) ? 1 : 0,
				'ensure_pass_before_order_emails' => ! empty( $_POST['ensure_pass_before_order_emails'] ) ? 1 : 0,
				'order_email_statuses'            => empty( $email_statuses ) ? array( 'processing', 'completed' ) : $email_statuses,
			)
		);
		EPC_Loyalty_Rule_Service::save_rules( $decoded['earning_rules'] );
		EPC_Loyalty_Reward_Service::save_tiers( $decoded['tiers'] );
		EPC_Loyalty_Reward_Service::save_milestones( $decoded['milestones'] );
		EPC_Loyalty_Redemption_Service::save_settings( $decoded['redemption'] );
		EPC_Loyalty_Notification_Service::save_settings( $decoded['notifications'] );

		wp_send_json_success( array( 'message' => __( 'Loyalty program saved.', 'epasscard' ), 'reload' => true ) );
	}

	/**
	 * Issue a manual claim for the signed-in customer.
	 *
	 * @return void
	 */
	public function ajax_claim_reward() {
		check_ajax_referer( 'epc_loyalty_claim', 'nonce' );

		$user_id  = get_current_user_id();
		$claim_id = isset( $_POST['claim_id'] ) ? absint( wp_unslash( $_POST['claim_id'] ) ) : 0;
		if ( $user_id <= 0 || $claim_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid loyalty reward request.', 'epasscard' ) ), 400 );
		}

		$result = EPC_Loyalty_Reward_Service::issue_claim( $claim_id, $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Loyalty reward issued.', 'epasscard' ) ) );
	}

	/**
	 * Register under EpassCard using WooCommerce-capable staff access.
	 *
	 * @return void
	 */
	public function register_submenu() {
		add_submenu_page(
			'epasscard',
			$this->get_label(),
			$this->get_label(),
			'manage_woocommerce',
			'epc-' . $this->get_slug(),
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'epasscard',
			__( 'Loyalty Customers', 'epasscard' ),
			__( 'Loyalty Customers', 'epasscard' ),
			'manage_woocommerce',
			$this->get_customers_page_slug(),
			array( $this, 'render_customers_page' )
		);

		add_submenu_page(
			'epasscard',
			__( 'Issued Loyalty Passes', 'epasscard' ),
			__( 'Issued Loyalty Passes', 'epasscard' ),
			'manage_woocommerce',
			$this->get_issued_passes_page_slug(),
			array( $this, 'render_issued_passes_page' )
		);

		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ) );
		add_action( 'admin_head', array( $this, 'hide_extra_wp_submenu_css' ) );
	}

	/**
	 * Keep the WooCommerce Loyalty WP submenu selected on dedicated list screens.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public function filter_submenu_file( $submenu_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( $page === $this->get_customers_page_slug() || $page === $this->get_issued_passes_page_slug() ) {
			return 'epc-' . $this->get_slug();
		}

		return $submenu_file;
	}

	/**
	 * Hide dedicated list screens from the core WP submenu.
	 *
	 * They must remain registered under EpassCard so WordPress grants access.
	 *
	 * @return void
	 */
	public function hide_extra_wp_submenu_css() {
		$customers = esc_attr( $this->get_customers_page_slug() );
		$passes    = esc_attr( $this->get_issued_passes_page_slug() );
		echo '<style id="epc-loyalty-hidden-submenus">#adminmenu .wp-submenu li:has(> a[href*="page=' . $customers . '"]),#adminmenu .wp-submenu li:has(> a[href*="page=' . $passes . '"]){display:none}</style>';
	}

	/**
	 * Dedicated loyalty customers admin screen.
	 *
	 * @return void
	 */
	public function render_customers_page() {
		if ( ! $this->current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		if ( ! $this->is_available() ) {
			$this->render_unavailable_screen( __( 'Loyalty Customers', 'epasscard' ) );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only customer search filter.
		$search = isset( $_GET['epc_customer_s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['epc_customer_s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$module = $this;

		EPC_Admin_Shell::render_open(
			array(
				'context'        => 'module',
				'title'          => __( 'Loyalty Customers', 'epasscard' ),
				'module'         => $this,
				'active_section' => 'loyalty-customers',
			)
		);
		echo '<div class="wrap epc-wrap">';
		include EPC_PLUGIN_DIR . 'admin/views/loyalty/customers.php';
		echo '</div>';
		EPC_Admin_Shell::render_close();
	}

	/**
	 * Dedicated issued-passes admin screen.
	 *
	 * @return void
	 */
	public function render_issued_passes_page() {
		if ( ! $this->current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		if ( ! $this->is_available() ) {
			$this->render_unavailable_screen( __( 'Issued Loyalty Passes', 'epasscard' ) );
			return;
		}

		EPC_Admin_Shell::render_open(
			array(
				'context'        => 'module',
				'title'          => __( 'Issued Loyalty Passes', 'epasscard' ),
				'module'         => $this,
				'active_section' => 'passes',
			)
		);
		echo '<div class="wrap epc-wrap">';
		$this->render_pass_action_notice();
		$this->render_issued_passes_section();
		echo '</div>';
		EPC_Admin_Shell::render_close();
	}

	/**
	 * Unavailable-module placeholder for dedicated screens.
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	private function render_unavailable_screen( $title ) {
		EPC_Admin_Shell::render_open(
			array(
				'context' => 'module',
				'title'   => $title,
				'module'  => $this,
			)
		);
		?>
		<div class="wrap epc-wrap">
			<div class="notice notice-error">
				<p><?php echo esc_html( $this->get_unavailable_message() ); ?></p>
			</div>
		</div>
		<?php
		EPC_Admin_Shell::render_close();
	}

	/**
	 * Include the loyalty customer summary after a pass create/update.
	 *
	 * @param string      $source_id Source record id.
	 * @param object|null $existing  Pass row.
	 * @return array<string, mixed>
	 */
	protected function get_pass_action_extra_success_data( $source_id, $existing ) {
		unset( $existing );

		$summary = EPC_Loyalty_Customer_Service::get_customer_summary( absint( $source_id ) );
		return $summary ? array( 'customer' => $summary ) : array();
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		EPC_Loyalty_Order_Service::init();
		EPC_Loyalty_Order_Sync_Service::init();
		EPC_Loyalty_Redemption_Service::init();
		EPC_Loyalty_Reward_Service::init();
		EPC_Loyalty_Reward_Service::schedule_cron();
		EPC_Loyalty_Starter::maybe_install();
		EPC_Loyalty_Notification_Service::init();
		EPC_Loyalty_Customer_Service::init();
		add_action( 'epc_loyalty_balance_changed', array( $this, 'on_balance_changed' ), 10, 2 );
		add_action( 'epc_loyalty_reward_available', array( $this, 'on_reward_changed' ) );
		add_action( 'epc_loyalty_reward_issued', array( $this, 'on_reward_changed' ) );
		add_action( 'epc_loyalty_reward_expired', array( $this, 'on_reward_changed' ) );
		add_action( 'epc_loyalty_sync_pass', array( $this, 'run_scheduled_pass_sync' ) );
		add_action( 'wp_ajax_epc_save_loyalty_program', array( $this, 'ajax_save_loyalty_program' ) );
		add_action( 'wp_ajax_epc_save_loyalty_pass_design', array( $this, 'ajax_save_pass_design' ) );
		add_action( 'wp_ajax_epc_loyalty_test_pass', array( $this, 'ajax_test_pass' ) );
		add_action( 'wp_ajax_epc_claim_loyalty_reward', array( $this, 'ajax_claim_reward' ) );
	}

	/**
	 * WooCommerce order status choices keyed by bare slug (no wc- prefix).
	 *
	 * @return array<string, string>
	 */
	private function get_order_status_choices() {
		$choices = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( (array) wc_get_order_statuses() as $key => $label ) {
				$slug = sanitize_key( str_replace( 'wc-', '', (string) $key ) );
				if ( '' === $slug ) {
					continue;
				}
				$choices[ $slug ] = (string) $label;
			}
		}

		if ( empty( $choices ) ) {
			$choices = array(
				'pending'    => __( 'Pending payment', 'epasscard' ),
				'processing' => __( 'Processing', 'epasscard' ),
				'on-hold'    => __( 'On hold', 'epasscard' ),
				'completed'  => __( 'Completed', 'epasscard' ),
				'cancelled'  => __( 'Cancelled', 'epasscard' ),
				'refunded'   => __( 'Refunded', 'epasscard' ),
				'failed'     => __( 'Failed', 'epasscard' ),
			);
		}

		return $choices;
	}

	/**
	 * Product category choices for filters.
	 *
	 * @return array<int, string>
	 */
	private function get_product_category_choices() {
		$choices = array();
		$terms   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $choices;
		}
		foreach ( $terms as $term ) {
			$choices[ (int) $term->term_id ] = (string) $term->name;
		}
		return $choices;
	}

	/**
	 * Editable WordPress role choices.
	 *
	 * @return array<string, string>
	 */
	private function get_role_choices() {
		$choices = array();
		if ( ! function_exists( 'wp_roles' ) ) {
			return $choices;
		}
		foreach ( wp_roles()->get_names() as $key => $label ) {
			$choices[ sanitize_key( (string) $key ) ] = translate_user_role( (string) $label );
		}
		return $choices;
	}

	/**
	 * Sanitize a posted list of WooCommerce status slugs.
	 *
	 * @param mixed $raw Posted value (array, string, or empty).
	 * @return array<int, string>
	 */
	private function sanitize_status_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = array_keys( $this->get_order_status_choices() );
		$out     = array();
		foreach ( $raw as $status ) {
			$slug = sanitize_key( str_replace( 'wc-', '', trim( (string) $status ) ) );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! empty( $allowed ) && ! in_array( $slug, $allowed, true ) ) {
				continue;
			}
			$out[] = $slug;
		}

		return array_values( array_unique( $out ) );
	}
}
