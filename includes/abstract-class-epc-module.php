<?php
/**
 * Base class for integration modules.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Abstract integration module.
 */
abstract class EPC_Module {

	/**
	 * Unique module slug.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Admin menu label.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Whether dependency plugin is active.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Mappable source fields for this module.
	 *
	 * @return array<string, string> slug => label
	 */
	abstract public function get_source_fields();

	/**
	 * Source fields available in the mapping UI (filterable).
	 *
	 * @return array<string, string> slug => label
	 */
	public function get_mapping_source_fields() {
		/**
		 * Filter mappable source fields for the admin mapping UI.
		 *
		 * @param array<string,string> $fields Source field slug => label.
		 * @param string               $slug   Module slug.
		 * @param EPC_Module           $module Module instance.
		 */
		return (array) apply_filters( 'epc_mapping_source_fields', $this->get_source_fields(), $this->get_slug(), $this );
	}

	/**
	 * Mapping mode options for the admin UI.
	 *
	 * @return array<string, string> mode => label
	 */
	public function get_mapping_modes() {
		$modes = array(
			'source' => __( 'Source field', 'epasscard' ),
			'custom' => __( 'Custom value', 'epasscard' ),
		);

		/**
		 * Filter mapping mode options shown in the field mapping modal.
		 *
		 * @param array<string,string> $modes  Mode slug => label.
		 * @param string               $slug   Module slug.
		 * @param EPC_Module           $module Module instance.
		 */
		return (array) apply_filters( 'epc_mapping_modes', $modes, $this->get_slug(), $this );
	}

	/**
	 * Mappable entities with extension filter.
	 *
	 * @return array<int, array{id: int, label: string}>
	 */
	public function get_filtered_mappable_entities() {
		/**
		 * Filter items shown in the template mapping table.
		 *
		 * @param array<int,array{id:int,label:string}> $entities Entity list.
		 * @param string                                $slug     Module slug.
		 * @param EPC_Module                            $module   Module instance.
		 */
		return (array) apply_filters( 'epc_mappable_entities', $this->get_mappable_entities(), $this->get_slug(), $this );
	}

	/**
	 * Items that can be mapped (membership levels, subscription products).
	 *
	 * @return array<int, array{id: int, label: string}>
	 */
	abstract public function get_mappable_entities();

	/**
	 * Human label for an entity id (product / membership level).
	 *
	 * @param int $entity_id Entity id.
	 * @return string
	 */
	abstract public function get_entity_label( $entity_id );

	/**
	 * Manually create, update, or sync a pass for a source record.
	 *
	 * @param int|string $source_id Subscription/membership record id.
	 * @param string $mode      sync|create|update.
	 * @return true|\WP_Error
	 */
	abstract public function sync_by_source_id( $source_id, $mode = 'sync' );

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! EPC_Module_Settings::mark_initialized( $this->get_slug() ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_epc_save_mapping_' . $this->get_slug(), array( $this, 'ajax_save_mapping' ) );
		add_action( 'wp_ajax_epc_pass_action_' . $this->get_slug(), array( $this, 'ajax_pass_action' ) );
		add_action( 'wp_ajax_epc_send_pass_email_' . $this->get_slug(), array( $this, 'ajax_send_pass_email' ) );
		add_action( 'wp_ajax_epc_send_test_push_' . $this->get_slug(), array( $this, 'ajax_send_test_push' ) );
		add_action( 'wp_ajax_epc_save_notification_rules_' . $this->get_slug(), array( $this, 'ajax_save_notification_rules' ) );
		add_action( 'wp_ajax_epc_save_status_rules_' . $this->get_slug(), array( $this, 'ajax_save_status_rules' ) );
		add_action( 'admin_notices', array( $this, 'render_pass_action_notice' ) );

		if ( $this->is_available() ) {
			$this->register_event_hooks();
		}
	}

	/**
	 * Human-readable dependency plugin name.
	 *
	 * @return string
	 */
	public function get_dependency_label() {
		return $this->get_label();
	}

	/**
	 * Packages required for this module (install / activate / external get).
	 *
	 * Each item:
	 * - key (string)
	 * - label (string)
	 * - mode (wporg|external)
	 * - slug (string) WP.org slug when mode=wporg
	 * - plugin_file (string) relative plugin bootstrap when mode=wporg
	 * - url (string) vendor URL when mode=external
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => $this->get_slug(),
				'label'       => $this->get_dependency_label(),
				'mode'        => 'external',
				'slug'        => '',
				'plugin_file' => '',
				'url'         => '',
			),
		);
	}

	/**
	 * Message shown when the dependency plugin is missing.
	 *
	 * @return string
	 */
	public function get_unavailable_message() {
		return sprintf(
			/* translators: %s: integration label */
			__( '%s is not installed or activated. Install and activate it to configure pass mappings.', 'epasscard' ),
			$this->get_label()
		);
	}

	/**
	 * Column label for mappable items in the mapping table.
	 *
	 * @return string
	 */
	public function get_entity_column_label() {
		return __( 'Product / plan', 'epasscard' );
	}

	/**
	 * Message when no mappable items exist yet.
	 *
	 * @return string
	 */
	public function get_empty_entities_message() {
		return __( 'No items are available to map yet. Create one in your membership or subscription plugin, then return here.', 'epasscard' );
	}

	/**
	 * Admin URL to create a new mappable item.
	 *
	 * @return string
	 */
	public function get_create_entity_url() {
		return admin_url( 'post-new.php?post_type=product' );
	}

	/**
	 * Label for the create-item button in the empty state.
	 *
	 * @return string
	 */
	public function get_create_entity_label() {
		return __( 'Create product', 'epasscard' );
	}

	/**
	 * Optional module settings above template mapping (override in module).
	 *
	 * @return void
	 */
	public function render_module_settings() {
		// Modules may override.
	}

	/**
	 * Whether this module exposes pass behavior (status rules) settings.
	 *
	 * @return bool
	 */
	public function has_pass_behavior_settings() {
		return false;
	}

	/**
	 * Status slugs => labels for pass behavior rules (override in modules).
	 *
	 * @return array<string, string>
	 */
	public function get_pass_behavior_statuses() {
		return array();
	}

	/**
	 * Option key for pass behavior status rules (override in modules).
	 *
	 * @return string
	 */
	public function get_status_rules_option_key() {
		return '';
	}

	/**
	 * Pass action options for status rules (override in modules).
	 *
	 * @return array<string, string>
	 */
	public function get_status_action_options() {
		return array();
	}

	/**
	 * Explain that push timing follows the integration's built-in reminder system.
	 *
	 * @param string $settings_url   Admin URL for reminder timing/settings.
	 * @param string $settings_label Link label for timing settings.
	 * @return void
	 */
	protected function render_native_reminder_notice( $settings_url, $settings_label ) {
		if ( '' === $settings_url || '' === $settings_label ) {
			return;
		}
		?>
		<div id="epc-section-reminders" class="epc-section epc-section--native-reminders">
			<div class="epc-card">
				<div class="epc-card__icon-head">
					<span class="epc-icon" aria-hidden="true">schedule</span>
					<h2><?php esc_html_e( 'Reminder timing', 'epasscard' ); ?></h2>
				</div>
				<p class="description">
					<?php esc_html_e( 'When each push is sent is controlled by this integration’s reminder system. Configure how many days before an event reminders fire using the link below.', 'epasscard' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="button epc-btn-outline-primary">
						<?php echo esc_html( $settings_label ); ?>
						<span class="epc-icon" aria-hidden="true" style="font-size:16px;vertical-align:middle;">open_in_new</span>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Pass status options for list filters.
	 *
	 * @return array<string, string>
	 */
	public function get_pass_status_options() {
		return array(
			''        => __( 'All statuses', 'epasscard' ),
			'active'  => __( 'Active', 'epasscard' ),
			'revoked' => __( 'Revoked', 'epasscard' ),
		);
	}

	/**
	 * Extra sidebar nav items for this module admin page.
	 *
	 * @return array<int, array{id: string, label: string, url?: string, icon?: string, section?: string}>
	 */
	public function get_extra_admin_nav_items() {
		return array();
	}

	/**
	 * Whether the current user can manage passes.
	 *
	 * @return bool
	 */
	public function current_user_can_manage_passes() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Allowed HTML tags/attrs for pass action buttons in admin list tables.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function get_pass_action_allowed_html() {
		return array(
			'span'   => array(
				'class' => true,
			),
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			),
			'button' => array(
				'type'              => true,
				'class'             => true,
				'data-module'       => true,
				'data-source-id'    => true,
				'data-pass-action'  => true,
				'data-pass-nonce'   => true,
				'data-email-nonce'  => true,
				'disabled'          => true,
			),
		);
	}

	/**
	 * Escape pass action markup for admin list tables.
	 *
	 * @param string $html Rendered pass action markup.
	 * @return string
	 */
	public static function kses_pass_action_html( $html ) {
		return wp_kses( (string) $html, self::get_pass_action_allowed_html() );
	}

	/**
	 * Build admin URL for a manual pass action.
	 *
	 * @deprecated 1.0.0 Pass actions use AJAX; kept for backward compatibility.
	 * @param int|string $source_id Source record id.
	 * @param string     $action    sync|create|update.
	 * @param string     $redirect  Optional redirect URL after action.
	 * @return string
	 */
	public function get_pass_action_url( $source_id, $action, $redirect = '' ) {
		$source_id = EPC_DB::sanitize_source_id( $source_id );
		$action    = sanitize_key( (string) $action );

		if ( '' === $source_id ) {
			return '';
		}

		$args = array(
			'epc_module'      => $this->get_slug(),
			'epc_pass_action' => $action,
			'epc_source_id'   => $source_id,
		);

		if ( '' !== $redirect ) {
			$args['epc_redirect'] = rawurlencode( $redirect );
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			'epc_pass_action_' . $source_id,
			'epc_nonce'
		);
	}

	/**
	 * Render pass action control for a source record.
	 *
	 * @param int|string $source_id Source record id.
	 * @param string     $redirect  Unused; kept for call-site compatibility.
	 * @return string
	 */
	public function render_pass_action_links( $source_id, $redirect = '' ) {
		unset( $redirect );

		$source_id = EPC_DB::sanitize_source_id( $source_id );
		if ( '' === $source_id || ! $this->current_user_can_manage_passes() ) {
			return '';
		}

		$existing = EPC_DB::get_pass( $this->get_slug(), $source_id );
		$has_pass = $existing && ! empty( $existing->pass_uid );

		if ( $has_pass ) {
			$html = $this->render_pass_action_button( $source_id, 'update', __( 'Update pass', 'epasscard' ) );
			if ( ! empty( $existing->pass_link ) ) {
				$html .= ' ' . $this->render_view_pass_link( (string) $existing->pass_link );
				$html .= ' ' . $this->render_pass_email_button( $source_id );
			}
			return $html;
		}

		return $this->render_pass_action_button( $source_id, 'create', __( 'Create pass', 'epasscard' ) );
	}

	/**
	 * Render a link that opens the wallet pass URL.
	 *
	 * @param string $pass_link Pass URL.
	 * @return string
	 */
	protected function render_view_pass_link( $pass_link ) {
		$pass_link = esc_url( (string) $pass_link );
		if ( '' === $pass_link ) {
			return '';
		}

		return sprintf(
			'<a class="button button-small epc-view-pass" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			$pass_link,
			esc_html__( 'View pass', 'epasscard' )
		);
	}

	/**
	 * Render a button to email the pass link to the member.
	 *
	 * @param int|string $source_id Source record id.
	 * @return string
	 */
	protected function render_pass_email_button( $source_id ) {
		$source_id = EPC_DB::sanitize_source_id( $source_id );
		if ( '' === $source_id || ! $this->current_user_can_manage_passes() ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button button-small epc-send-pass-email" data-module="%3$s" data-source-id="%1$s" data-email-nonce="%2$s">%4$s</button>',
			esc_attr( $source_id ),
			esc_attr( wp_create_nonce( 'epc_send_pass_email_' . $source_id ) ),
			esc_attr( $this->get_slug() ),
			esc_html__( 'Email pass link', 'epasscard' )
		);
	}

	/**
	 * AJAX: send pass link email for a source record.
	 *
	 * @return void
	 */
	public function ajax_send_pass_email() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$source_id = isset( $_POST['source_id'] ) ? EPC_DB::sanitize_source_id( wp_unslash( (string) $_POST['source_id'] ) ) : '';
		$nonce     = isset( $_POST['email_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['email_nonce'] ) ) : '';

		if ( '' === $source_id || ! wp_verify_nonce( $nonce, 'epc_send_pass_email_' . $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'epasscard' ) ), 400 );
		}

		if ( ! class_exists( 'EPC_Pass_Email' ) ) {
			wp_send_json_error( array( 'message' => __( 'Email module is not available.', 'epasscard' ) ), 500 );
		}

		$result = EPC_Pass_Email::send_for_source(
			$this->get_slug(),
			$source_id,
			array(
				'module_label' => $this->get_label(),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Pass link email sent.', 'epasscard' ),
			)
		);
	}

	/**
	 * Render pass action buttons for a WordPress user (integration list tables).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function render_pass_actions_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! $this->current_user_can_manage_passes() ) {
			return '';
		}

		$source_ids = $this->get_pass_action_source_ids_for_user( $user_id );
		if ( empty( $source_ids ) ) {
			return '&mdash;';
		}

		$parts = array();
		foreach ( $source_ids as $source_id ) {
			$link = $this->render_pass_action_links( $source_id );
			if ( '' !== $link ) {
				$parts[] = $link;
			}
		}

		if ( empty( $parts ) ) {
			return '&mdash;';
		}

		return '<span class="epasscard-pass-actions">' . implode( ' ', $parts ) . '</span>';
	}

	/**
	 * Source record ids that can receive a pass action for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, int|string>
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		return array();
	}

	/**
	 * Render a single AJAX pass action button.
	 *
	 * @param int|string $source_id Source record id.
	 * @param string     $action    create|update|sync.
	 * @param string     $label     Button label.
	 * @return string
	 */
	protected function render_pass_action_button( $source_id, $action, $label ) {
		$source_id = EPC_DB::sanitize_source_id( $source_id );
		$action    = sanitize_key( (string) $action );

		if ( '' === $source_id ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button button-small epc-pass-action" data-module="%5$s" data-source-id="%1$s" data-pass-action="%2$s" data-pass-nonce="%3$s">%4$s</button>',
			esc_attr( $source_id ),
			esc_attr( $action ),
			esc_attr( wp_create_nonce( 'epc_pass_action_' . $source_id ) ),
			esc_html( $label ),
			esc_attr( $this->get_slug() )
		);
	}

	/**
	 * AJAX: manually create or update a pass.
	 *
	 * @return void
	 */
	public function ajax_pass_action() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->is_available() || ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$source_id = isset( $_POST['source_id'] ) ? EPC_DB::sanitize_source_id( wp_unslash( (string) $_POST['source_id'] ) ) : '';
		$action    = isset( $_POST['pass_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['pass_action'] ) ) : '';
		$nonce     = isset( $_POST['pass_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pass_nonce'] ) ) : '';

		if ( '' === $source_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid record.', 'epasscard' ) ), 400 );
		}

		if ( ! wp_verify_nonce( $nonce, 'epc_pass_action_' . $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'epasscard' ) ), 403 );
		}

		if ( ! in_array( $action, array( 'sync', 'create', 'update' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pass action.', 'epasscard' ) ), 400 );
		}

		$result = $this->sync_by_source_id( $source_id, $action );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$existing = EPC_DB::get_pass( $this->get_slug(), $source_id );
		$has_pass = $existing && ! empty( $existing->pass_uid );
		$pass_link = ( $has_pass && ! empty( $existing->pass_link ) ) ? (string) $existing->pass_link : '';

		$payload = array(
			'message'      => 'create' === $action
				? __( 'Pass created successfully.', 'epasscard' )
				: __( 'Pass updated successfully.', 'epasscard' ),
			'has_pass'     => $has_pass,
			'action'       => $has_pass ? 'update' : $action,
			'action_label' => $has_pass ? __( 'Update pass', 'epasscard' ) : __( 'Create pass', 'epasscard' ),
			'pass_nonce'   => wp_create_nonce( 'epc_pass_action_' . $source_id ),
			'pass_link'    => $pass_link,
			'view_label'   => __( 'View pass', 'epasscard' ),
			'email_nonce'  => $pass_link ? wp_create_nonce( 'epc_send_pass_email_' . $source_id ) : '',
			'email_label'  => __( 'Email pass link', 'epasscard' ),
			'module'       => $this->get_slug(),
			'source_id'    => $source_id,
			'pass_uid'     => $has_pass ? (string) $existing->pass_uid : '',
			'status'       => $has_pass ? (string) $existing->status : '',
			'status_label' => $has_pass ? ucfirst( (string) $existing->status ) : '',
			'updated_at'   => $has_pass ? (string) $existing->updated_at : '',
		);

		wp_send_json_success( array_merge( $payload, $this->get_pass_action_extra_success_data( $source_id, $existing ) ) );
	}

	/**
	 * Extra AJAX payload after a successful pass create/update.
	 *
	 * @param string      $source_id Source record id.
	 * @param object|null $existing  Pass row.
	 * @return array<string, mixed>
	 */
	protected function get_pass_action_extra_success_data( $source_id, $existing ) {
		unset( $source_id, $existing );
		return array();
	}

	/**
	 * Show admin notice after manual pass action.
	 *
	 * @return void
	 */
	public function render_pass_action_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice from redirect query args; capability gated by admin screen.
		if ( ! isset( $_GET['epc_notice'], $_GET['epc_message'] ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		if ( isset( $_GET['epc_module'] ) ) {
			if ( sanitize_key( wp_unslash( (string) $_GET['epc_module'] ) ) !== $this->get_slug() ) {
				return;
			}
		} elseif ( 'epc-' . $this->get_slug() !== $page && $this->get_issued_passes_page_slug() !== $page ) {
			return;
		}

		$type    = sanitize_key( wp_unslash( (string) $_GET['epc_notice'] ) );
		$message = sanitize_text_field( wp_unslash( (string) $_GET['epc_message'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $message ) {
			return;
		}

		$class = 'success' === $type ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Register integration-specific event hooks.
	 *
	 * @return void
	 */
	abstract protected function register_event_hooks();

	/**
	 * Register admin submenu under EpassCard.
	 *
	 * @return void
	 */
	public function register_submenu() {
		add_submenu_page(
			'epasscard',
			$this->get_label(),
			$this->get_label(),
			'manage_options',
			'epc-' . $this->get_slug(),
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Option key for mappings.
	 *
	 * @return string
	 */
	public function get_mappings_option_key() {
		return 'epc_mappings_' . $this->get_slug();
	}

	/**
	 * Get all saved mappings keyed by entity id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_mappings() {
		$saved = get_option( $this->get_mappings_option_key(), array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Get mapping for one entity.
	 *
	 * @param int $entity_id Entity id.
	 * @return array<string, mixed>
	 */
	public function get_mapping( $entity_id ) {
		$all = $this->get_mappings();
		$key = (string) absint( $entity_id );
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
	}

	/**
	 * Save mapping for entity.
	 *
	 * @param int                  $entity_id Entity id.
	 * @param array<string, mixed> $mapping   Mapping data.
	 * @return void
	 */
	public function save_mapping( $entity_id, array $mapping ) {
		$all = $this->get_mappings();
		$all[ (string) absint( $entity_id ) ] = $mapping;
		update_option( $this->get_mappings_option_key(), $all );
	}

	/**
	 * Whether to load pass-action assets on the current admin screen.
	 *
	 * @param string $hook Current admin hook.
	 * @return bool
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		return 'epasscard_page_epc-' . $this->get_slug() === $hook;
	}

	/**
	 * Enqueue shared pass-action assets (module page and integration lists).
	 *
	 * @param bool $include_mapping Include template-mapping data for module settings page.
	 * @return void
	 */
	protected function enqueue_pass_action_assets( $include_mapping = false ) {
		if ( wp_style_is( 'epc-admin', 'enqueued' ) ) {
			return;
		}

		$style_deps = array();
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && EPC_Admin_Shell::is_epc_screen( (string) $screen->id ) ) {
				$style_deps[] = 'epc-admin-shell';
			}
		}

		$style_path = EPC_PLUGIN_DIR . 'admin/css/admin.css';
		wp_enqueue_style(
			'epc-admin',
			EPC_PLUGIN_URL . 'admin/css/admin.css',
			$style_deps,
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : EPC_VERSION
		);

		$script_path = EPC_PLUGIN_DIR . 'admin/js/admin.js';
		wp_enqueue_script(
			'epc-admin',
			EPC_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : EPC_VERSION,
			true
		);

		$i18n = array(
			'loading'        => __( 'Loading…', 'epasscard' ),
			'error'          => __( 'Something went wrong. Please try again.', 'epasscard' ),
			'saving'         => __( 'Saving…', 'epasscard' ),
			'saved'          => __( 'Settings saved.', 'epasscard' ),
			'passCreating'   => __( 'Creating pass…', 'epasscard' ),
			'passUpdating'   => __( 'Updating pass…', 'epasscard' ),
			'passCreated'    => __( 'Pass created successfully.', 'epasscard' ),
			'passUpdated'    => __( 'Pass updated successfully.', 'epasscard' ),
			'passEmailSending' => __( 'Sending email…', 'epasscard' ),
			'passEmailSent'    => __( 'Pass link email sent.', 'epasscard' ),
		);

		if ( $include_mapping ) {
			$i18n = array_merge(
				$i18n,
				array(
					'selectTemplate'    => __( 'Select template', 'epasscard' ),
					'saveMapping'       => __( 'Save mapping', 'epasscard' ),
					'saved'             => __( 'Mapping saved.', 'epasscard' ),
					'passField'         => __( 'Pass field', 'epasscard' ),
					'sourceField'       => __( 'Membership data', 'epasscard' ),
					'mappingSource'     => __( 'Source field', 'epasscard' ),
					'mappingCustom'     => __( 'Custom value', 'epasscard' ),
					'customPlaceholder' => __( 'Enter a fixed value', 'epasscard' ),
					'modeValuePlaceholder' => __( 'Value for custom mode', 'epasscard' ),
					'noFields'          => __( 'No pass fields found for this template.', 'epasscard' ),
					'refreshTemplates'  => __( 'Refresh template list', 'epasscard' ),
					'templatesRefreshed'=> __( 'Template list refreshed.', 'epasscard' ),
					'savingMapping'     => __( 'Saving…', 'epasscard' ),
				)
			);
		}

		if ( ! empty( $this->get_notification_types() ) ) {
			$i18n = array_merge(
				$i18n,
				array(
					'testPushSending'    => __( 'Sending test notification…', 'epasscard' ),
					'testPushSent'       => __( 'Test notification sent.', 'epasscard' ),
					'testPushSelectType' => __( 'Select a reminder type.', 'epasscard' ),
					'testPushEnterPass'  => __( 'Enter a pass UUID, record ID, or source ID.', 'epasscard' ),
					'testPushSend'       => __( 'Send test notification', 'epasscard' ),
				)
			);
		}

		$localize = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'epc_admin' ),
			'module'  => $this->get_slug(),
			'i18n'    => $i18n,
		);

		if ( $include_mapping ) {
			$localize['sourceFields']  = $this->get_mapping_source_fields();
			$localize['mappingModes']  = $this->get_mapping_modes();
		}

		wp_localize_script( 'epc-admin', 'epcAdmin', $localize );
	}

	/**
	 * Enqueue module admin assets on module page.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		$page = 'epasscard_page_epc-' . $this->get_slug();

		if ( $this->should_enqueue_pass_action_assets( $hook ) && $this->is_available() && $this->current_user_can_manage_passes() ) {
			$this->enqueue_pass_action_assets( $page === $hook );
		}

		if ( $hook !== $page || ! $this->is_available() ) {
			return;
		}
	}

	/**
	 * Admin page slug for the issued-passes list.
	 *
	 * Override to host the table on a dedicated screen instead of the dashboard.
	 *
	 * @return string
	 */
	public function get_issued_passes_page_slug() {
		return 'epc-' . $this->get_slug();
	}

	/**
	 * Admin URL for the issued-passes list.
	 *
	 * @return string
	 */
	public function get_issued_passes_admin_url() {
		$slug = $this->get_issued_passes_page_slug();
		$url  = admin_url( 'admin.php?page=' . $slug );

		if ( $slug === 'epc-' . $this->get_slug() ) {
			return $url . '#epc-section-passes';
		}

		return $url;
	}

	/**
	 * Whether the issued-passes table should render on the module dashboard.
	 *
	 * @return bool
	 */
	public function should_render_issued_passes_on_dashboard() {
		return $this->get_issued_passes_page_slug() === 'epc-' . $this->get_slug();
	}

	/**
	 * Description shown above the issued-passes table.
	 *
	 * @return string
	 */
	protected function get_issued_passes_description() {
		return __( 'Passes created for active subscriptions or memberships.', 'epasscard' );
	}

	/**
	 * Render module admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! $this->current_user_can_manage_passes() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		if ( ! $this->is_available() ) {
			EPC_Admin_Shell::render_open(
				array(
					'context' => 'module',
					'title'   => $this->get_label(),
					'module'  => $this,
				)
			);
			?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( $this->get_unavailable_message() ); ?></p>
			</div>
			<?php
			EPC_Admin_Shell::render_close();
			return;
		}

		$entities = $this->get_filtered_mappable_entities();
		$mappings = $this->get_mappings();

		$pass_totals = EPC_DB::query_passes(
			array(
				'module'     => $this->get_slug(),
				'count_only' => true,
				'per_page'   => 1,
			)
		);
		$active_totals = EPC_DB::query_passes(
			array(
				'module'     => $this->get_slug(),
				'status'     => 'active',
				'count_only' => true,
				'per_page'   => 1,
			)
		);

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'module',
				'title'   => sprintf(
					/* translators: %s: integration name */
					__( '%s Dashboard', 'epasscard' ),
					$this->get_label()
				),
				'module'  => $this,
			)
		);
		?>
		<script>window.epcSavedMappings = <?php echo wp_json_encode( $mappings ); ?>;</script>
			<div id="epc-section-overview" class="epc-section epc-section--overview">
				<div class="epc-page-header">
					<h1 class="epc-page-title"><?php echo esc_html( $this->get_label() ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Map membership data to pass templates, manage issued passes, and configure push notification copy for this integration.', 'epasscard' ); ?>
					</p>
				</div>
				<?php $this->render_module_overview( $entities, $mappings, (int) $pass_totals['total'], (int) $active_totals['total'] ); ?>
			</div>

			<?php if ( ! EPC_Connection::is_connected() ) : ?>
				<div class="epc-connection-alert">
					<p>
						<?php
						printf(
							/* translators: %s: settings page link */
							esc_html__( 'Connect your EpassCard account first on the %s page.', 'epasscard' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=epasscard' ) ) . '">' . esc_html__( 'Connection', 'epasscard' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_module_settings(); ?>

			<div id="epc-section-mapping" class="epc-section epc-section--mapping">
				<h2><?php esc_html_e( 'Template mapping', 'epasscard' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Select a plan or product, choose an EpassCard pass template, then map each pass field to subscription or membership data.', 'epasscard' ); ?>
				</p>

				<?php if ( empty( $entities ) ) : ?>
					<div class="epc-card epc-empty-mapping">
						<p><strong><?php esc_html_e( 'No mappable items found.', 'epasscard' ); ?></strong></p>
						<p><?php echo esc_html( $this->get_empty_entities_message() ); ?></p>
						<?php if ( '' !== $this->get_create_entity_url() ) : ?>
							<p>
								<a href="<?php echo esc_url( $this->get_create_entity_url() ); ?>" class="button button-primary">
									<?php echo esc_html( $this->get_create_entity_label() ); ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<table class="widefat striped epc-mapping-table">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html( $this->get_entity_column_label() ); ?></th>
								<th scope="col"><?php esc_html_e( 'Pass template', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Mapped fields', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'epasscard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entities as $entity ) : ?>
								<?php
								$eid       = (int) $entity['id'];
								$map_key   = (string) $eid;
								$saved     = isset( $mappings[ $map_key ] ) && is_array( $mappings[ $map_key ] ) ? $mappings[ $map_key ] : array();
								$mapped    = ! empty( $saved['template_uid'] );
								$tpl_label = '';
								if ( $mapped ) {
									$tpl_label = ! empty( $saved['template_name'] )
										? (string) $saved['template_name']
										: (string) $saved['template_uid'];
								}
								$field_count = isset( $saved['field_mapping'] ) && is_array( $saved['field_mapping'] )
									? count( $saved['field_mapping'] )
									: 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( (string) $entity['label'] ); ?></strong></td>
									<td>
										<?php if ( $mapped ) : ?>
											<?php echo esc_html( $tpl_label ); ?>
										<?php else : ?>
											<em><?php esc_html_e( 'Not mapped', 'epasscard' ); ?></em>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $mapped ) : ?>
											<?php
											printf(
												/* translators: %d: number of mapped fields */
												esc_html( _n( '%d field', '%d fields', $field_count, 'epasscard' ) ),
												(int) $field_count
											);
											?>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td>
										<button
											type="button"
											class="button button-primary epc-map-trigger"
											data-entity-id="<?php echo esc_attr( (string) $eid ); ?>"
											data-entity-label="<?php echo esc_attr( (string) $entity['label'] ); ?>"
										>
											<?php echo $mapped ? esc_html__( 'Edit mapping', 'epasscard' ) : esc_html__( 'Set up mapping', 'epasscard' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php
			if ( $this->should_render_issued_passes_on_dashboard() ) {
				$this->render_issued_passes_section();
			}
			?>

			<div id="epc-mapping-modal" class="epc-modal" hidden>
				<div class="epc-modal__backdrop" data-epc-close></div>
				<div class="epc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="epc-modal-title">
					<header class="epc-modal__header">
						<h2 id="epc-modal-title"><?php esc_html_e( 'Template mapping', 'epasscard' ); ?></h2>
						<button type="button" class="epc-modal__close" data-epc-close aria-label="<?php esc_attr_e( 'Close', 'epasscard' ); ?>">&times;</button>
					</header>
					<div class="epc-modal__body">
						<input type="hidden" id="epc-mapping-entity-id" value="" />
						<p class="epc-modal-entity-label"></p>
						<div class="epc-template-field">
							<label for="epc-template-select"><strong><?php esc_html_e( 'Pass template', 'epasscard' ); ?></strong></label>
							<div class="epc-template-toolbar">
								<select id="epc-template-select" class="regular-text">
									<option value=""><?php esc_html_e( '— Select —', 'epasscard' ); ?></option>
								</select>
								<button type="button" class="button epc-refresh-templates" id="epc-refresh-templates" title="<?php esc_attr_e( 'Refresh template list', 'epasscard' ); ?>" aria-label="<?php esc_attr_e( 'Refresh template list', 'epasscard' ); ?>">
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
								</button>
							</div>
							<p class="epc-template-actions">
								<a href="<?php echo esc_url( 'https://app.epasscard.com/pass-templates' ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Create a new template', 'epasscard' ); ?>
									<span class="dashicons dashicons-external" aria-hidden="true"></span>
								</a>
							</p>
						</div>
						<div id="epc-mapping-rows"></div>
						<p class="epc-modal-status" aria-live="polite"></p>
					</div>
					<footer class="epc-modal__footer">
						<button type="button" class="button button-primary" id="epc-save-mapping">
							<span class="epc-btn-label"><?php esc_html_e( 'Save mapping', 'epasscard' ); ?></span>
							<span class="spinner"></span>
						</button>
						<button type="button" class="button" data-epc-close><?php esc_html_e( 'Cancel', 'epasscard' ); ?></button>
					</footer>
				</div>
			</div>
		<?php
		EPC_Admin_Shell::render_close();
	}

	/**
	 * Render the issued-passes table, filters, and search.
	 *
	 * @return void
	 */
	protected function render_issued_passes_section() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters; page requires manage capability.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$filters = array(
			'status'    => isset( $_GET['epc_pass_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['epc_pass_status'] ) ) : '',
			'entity_id' => isset( $_GET['epc_entity_id'] ) ? absint( wp_unslash( $_GET['epc_entity_id'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page_slug        = $this->get_issued_passes_page_slug();
		$entities         = $this->get_filtered_mappable_entities();
		$status_options   = $this->get_pass_status_options();
		$filter_entity_id = $filters['entity_id'];
		$filter_status    = $filters['status'];
		$redirect_url     = add_query_arg(
			array_filter(
				array(
					'page'            => $page_slug,
					'epc_pass_status' => $filter_status,
					'epc_entity_id'   => $filter_entity_id > 0 ? $filter_entity_id : null,
					's'               => '' !== $search ? $search : null,
				)
			),
			admin_url( 'admin.php' )
		);

		$table = new EPC_Module_List_Table( $this, $search, $filters, $redirect_url );
		$table->prepare_items();
		?>
		<div id="epc-section-passes" class="epc-section epc-section--passes">
			<h2><?php esc_html_e( 'Issued passes', 'epasscard' ); ?></h2>
			<p class="description"><?php echo esc_html( $this->get_issued_passes_description() ); ?></p>

			<form method="get" class="epc-pass-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
				<div class="epc-pass-filters__row">
					<label for="epc-pass-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'epasscard' ); ?></label>
					<select name="epc_pass_status" id="epc-pass-status-filter">
						<?php foreach ( $status_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="epc-entity-filter" class="screen-reader-text"><?php echo esc_html( $this->get_entity_column_label() ); ?></label>
					<select name="epc_entity_id" id="epc-entity-filter">
						<option value="0"><?php esc_html_e( 'All plans / products', 'epasscard' ); ?></option>
						<?php foreach ( $entities as $entity ) : ?>
							<option value="<?php echo esc_attr( (string) $entity['id'] ); ?>" <?php selected( $filter_entity_id, (int) $entity['id'] ); ?>>
								<?php echo esc_html( (string) $entity['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php $table->search_box( __( 'Search passes', 'epasscard' ), 'epc-search' ); ?>
					<?php submit_button( __( 'Filter', 'epasscard' ), 'secondary', 'filter_action', false ); ?>
				</div>
			</form>

			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Overview metric cards for a module admin page.
	 *
	 * @param array<int, array<string, mixed>>      $entities      Mappable entities.
	 * @param array<string, array<string, mixed>>   $mappings      Saved mappings.
	 * @param int                                   $total_passes  Total issued passes.
	 * @param int                                   $active_passes Active passes.
	 * @return void
	 */
	protected function render_module_overview( array $entities, array $mappings, $total_passes, $active_passes ) {
		$mapped_count = 0;
		foreach ( $entities as $entity ) {
			$key = (string) ( $entity['id'] ?? '' );
			if ( '' !== $key && ! empty( $mappings[ $key ]['template_uid'] ) ) {
				++$mapped_count;
			}
		}

		$entity_total = count( $entities );
		?>
		<div class="epc-metrics">
			<div class="epc-metric-card">
				<p class="epc-metric-card__label">
					<a href="<?php echo esc_url( $this->get_issued_passes_admin_url() ); ?>"><?php esc_html_e( 'Issued passes', 'epasscard' ); ?></a>
				</p>
				<p class="epc-metric-card__value"><?php echo esc_html( number_format_i18n( $total_passes ) ); ?></p>
				<p class="epc-metric-card__hint">
					<?php
					printf(
						/* translators: %s: active pass count */
						esc_html__( '%s active', 'epasscard' ),
						esc_html( number_format_i18n( $active_passes ) )
					);
					?>
				</p>
			</div>
			<div class="epc-metric-card">
				<p class="epc-metric-card__label"><?php esc_html_e( 'Mapped templates', 'epasscard' ); ?></p>
				<p class="epc-metric-card__value"><?php echo esc_html( number_format_i18n( $mapped_count ) ); ?></p>
				<p class="epc-metric-card__hint">
					<?php
					printf(
						/* translators: 1: mapped count, 2: total entities */
						esc_html__( '%1$s of %2$s plans mapped', 'epasscard' ),
						esc_html( number_format_i18n( $mapped_count ) ),
						esc_html( number_format_i18n( $entity_total ) )
					);
					?>
				</p>
			</div>
			<div class="epc-metric-card">
				<p class="epc-metric-card__label"><?php esc_html_e( 'API connection', 'epasscard' ); ?></p>
				<p class="epc-metric-card__value" style="font-size:20px;">
					<?php if ( EPC_Connection::is_connected() ) : ?>
						<span class="epc-status-badge epc-status-badge--active"><?php esc_html_e( 'Connected', 'epasscard' ); ?></span>
					<?php else : ?>
						<span class="epc-status-badge epc-status-badge--pending"><?php esc_html_e( 'Not connected', 'epasscard' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: save mapping for entity.
	 *
	 * @return void
	 */
	public function ajax_save_mapping() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		if ( ! $this->is_available() ) {
			wp_send_json_error( array( 'message' => $this->get_unavailable_message() ), 400 );
		}

		$entity_id = isset( $_POST['entity_id'] ) ? absint( wp_unslash( $_POST['entity_id'] ) ) : 0;
		if ( $entity_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item.', 'epasscard' ) ), 400 );
		}

		$template_uid = isset( $_POST['template_uid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_uid'] ) ) : '';
		$san_uid      = EPC_Api_Client::sanitize_uid( $template_uid );
		if ( false === $san_uid ) {
			wp_send_json_error( array( 'message' => __( 'Invalid template.', 'epasscard' ) ), 400 );
		}

		$template_name = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template_name'] ) ) : '';
		$template_id   = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded JSON sanitized below.
		$mapping_raw = isset( $_POST['field_mapping'] ) ? wp_unslash( $_POST['field_mapping'] ) : '';
		$mapping_arr = is_string( $mapping_raw ) ? json_decode( $mapping_raw, true ) : array();
		if ( ! is_array( $mapping_arr ) ) {
			$mapping_arr = array();
		}

		$clean_map = array();
		foreach ( $mapping_arr as $pass_uid => $entry ) {
			$puid = EPC_Api_Client::sanitize_uid( (string) $pass_uid );
			if ( false === $puid ) {
				continue;
			}

			$normalized = EPC_Pass_Service::normalize_mapping_entry( $entry );
			if ( null !== $normalized ) {
				$clean_map[ $puid ] = $normalized;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON snapshot.
		$fields_raw = isset( $_POST['pass_fields'] ) ? wp_unslash( $_POST['pass_fields'] ) : '';
		$fields_arr = is_string( $fields_raw ) ? json_decode( $fields_raw, true ) : array();
		if ( ! is_array( $fields_arr ) ) {
			$fields_arr = array();
		}

		$this->save_mapping(
			$entity_id,
			array(
				'template_uid'  => $san_uid,
				'template_name' => $template_name,
				'template_id'   => $template_id,
				'field_mapping' => $clean_map,
				'pass_fields'   => $fields_arr,
			)
		);

		wp_send_json_success( array( 'message' => __( 'Mapping saved.', 'epasscard' ) ) );
	}

	/**
	 * Whether reminder timing is controlled by the integration (not EpassCard).
	 *
	 * @return bool
	 */
	public function uses_native_reminder_timing() {
		return false;
	}

	/**
	 * Build push copy from saved rules for a notification type.
	 *
	 * @param string               $type         Notification type slug.
	 * @param array<string, string> $replacements Placeholder values.
	 * @return array{title: string, message: string}|null
	 */
	public function build_push_notification_copy( $type, array $replacements, $for_test = false ) {
		$type  = sanitize_key( (string) $type );
		$rules = $this->get_notification_rules();
		$rule  = $rules[ $type ] ?? array();

		if ( ! $for_test && empty( $rule['enabled'] ) ) {
			return null;
		}

		$title   = EPC_Pass_Notifications::replace_tags( (string) ( $rule['title'] ?? '' ), $replacements );
		$message = EPC_Pass_Notifications::replace_tags( (string) ( $rule['message'] ?? '' ), $replacements );

		if ( '' === trim( $title ) || '' === trim( $message ) ) {
			return null;
		}

		return array(
			'title'   => $title,
			'message' => $message,
		);
	}

	/**
	 * Option key for push notification rules (override when supported).
	 *
	 * @return string
	 */
	public function get_notification_rules_option_key() {
		return 'epc_' . str_replace( '-', '_', $this->get_slug() ) . '_notification_rules';
	}

	/**
	 * Configurable push notification types for this module.
	 *
	 * @return array<string, string> type slug => admin label
	 */
	public function get_notification_types() {
		return array();
	}

	/**
	 * Default push notification rules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_default_notification_rules() {
		return array();
	}

	/**
	 * Saved notification rules merged with defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_notification_rules() {
		$types    = $this->get_notification_types();
		$defaults = $this->get_default_notification_rules();
		$saved    = get_option( $this->get_notification_rules_option_key(), array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$merged   = array();

		foreach ( $types as $type => $label ) {
			unset( $label );
			$type = sanitize_key( (string) $type );
			$base = isset( $defaults[ $type ] ) && is_array( $defaults[ $type ] ) ? $defaults[ $type ] : array();
			$rule = isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) ? $saved[ $type ] : array();

			$merged[ $type ] = array(
				'enabled' => ! empty( $rule['enabled'] ),
				'title'   => sanitize_text_field( (string) ( $rule['title'] ?? $base['title'] ?? '' ) ),
				'message' => sanitize_textarea_field( (string) ( $rule['message'] ?? $base['message'] ?? '' ) ),
			);

			if ( ! $this->uses_native_reminder_timing() ) {
				$merged[ $type ]['days'] = max( 1, absint( $rule['days'] ?? $base['days'] ?? 7 ) );
			}
		}

		return $merged;
	}

	/**
	 * Render push notification settings when the module defines types.
	 *
	 * @return void
	 */
	public function render_push_notification_settings() {
		$types = $this->get_notification_types();
		if ( empty( $types ) || ! $this->is_available() ) {
			return;
		}

		$rules         = $this->get_notification_rules();
		$tags          = $this->get_notification_template_tags();
		$native_timing = $this->uses_native_reminder_timing();
		$enabled_count = 0;
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				++$enabled_count;
			}
		}
		?>
		<div id="epc-section-push" class="epc-section epc-section--notification-rules">
			<div class="epc-page-header">
				<h2 class="epc-page-title"><?php esc_html_e( 'Push notification copy', 'epasscard' ); ?></h2>
				<p class="description">
					<?php
					if ( $native_timing ) {
						esc_html_e( 'Plain-text wallet push title and message for each reminder. Timing follows your integration’s reminder settings; only the push copy is configured here.', 'epasscard' );
					} else {
						esc_html_e( 'Send wallet push notifications before important dates. Reminders are checked once per day and each reminder is sent only once per pass.', 'epasscard' );
					}
					?>
				</p>
			</div>

			<?php if ( ! $native_timing ) : ?>
				<form class="epc-ajax-form epc-card" data-epc-action="<?php echo esc_attr( 'epc_save_notification_rules_' . $this->get_slug() ); ?>" method="post" action="">
					<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />
					<table class="widefat striped epc-notification-rules-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Reminder', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Push enabled', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Days before', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Push title', 'epasscard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Push message', 'epasscard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $types as $type => $label ) : ?>
								<?php $rule = $rules[ $type ] ?? array(); ?>
								<tr>
									<td><strong><?php echo esc_html( $label ); ?></strong></td>
									<td>
										<label class="epc-toggle">
											<input type="checkbox" name="epc_notification_enabled_<?php echo esc_attr( $type ); ?>" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> />
											<span class="epc-toggle__track" aria-hidden="true"></span>
										</label>
									</td>
									<td>
										<input type="number" class="small-text" min="1" max="90" name="epc_notification_days_<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( (string) ( $rule['days'] ?? 7 ) ); ?>" />
									</td>
									<td>
										<input type="text" class="regular-text" name="epc_notification_title_<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( (string) ( $rule['title'] ?? '' ) ); ?>" />
									</td>
									<td>
										<textarea class="large-text" rows="2" name="epc_notification_message_<?php echo esc_attr( $type ); ?>"><?php echo esc_textarea( (string) ( $rule['message'] ?? '' ) ); ?></textarea>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:16px;margin-bottom:0;">
						<?php submit_button( __( 'Save push notification settings', 'epasscard' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
			<?php else : ?>
				<div class="epc-push-layout">
					<div class="epc-push-sidebar">
						<?php if ( ! empty( $tags ) ) : ?>
							<div class="epc-card">
								<div class="epc-card__icon-head">
									<span class="epc-icon" aria-hidden="true">data_object</span>
									<h3><?php esc_html_e( 'Dynamic tags', 'epasscard' ); ?></h3>
								</div>
								<p class="description" style="margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.04em;">
									<?php esc_html_e( 'Available placeholders', 'epasscard' ); ?>
								</p>
								<div class="epc-tag-list">
									<?php foreach ( $tags as $tag ) : ?>
										<span class="epc-tag">{<?php echo esc_html( $tag ); ?>}</span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<form class="epc-ajax-form epc-push-card" data-epc-action="<?php echo esc_attr( 'epc_save_notification_rules_' . $this->get_slug() ); ?>" method="post" action="">
						<input type="hidden" name="epc_module_slug" value="<?php echo esc_attr( $this->get_slug() ); ?>" />

						<div class="epc-push-card__header">
							<h3><?php esc_html_e( 'Push notification copy', 'epasscard' ); ?></h3>
							<?php if ( $enabled_count > 0 ) : ?>
								<span class="epc-status-badge epc-status-badge--active"><?php esc_html_e( 'Syncing active', 'epasscard' ); ?></span>
							<?php else : ?>
								<span class="epc-status-badge epc-status-badge--pending"><?php esc_html_e( 'All disabled', 'epasscard' ); ?></span>
							<?php endif; ?>
						</div>

						<?php foreach ( $types as $type => $label ) : ?>
							<?php $rule = $rules[ $type ] ?? array(); ?>
							<div class="epc-push-row">
								<div class="epc-push-row__head">
									<div>
										<p class="epc-push-row__title"><?php echo esc_html( $label ); ?></p>
										<p class="epc-push-row__desc"><?php echo esc_html( $this->get_notification_type_description( $type ) ); ?></p>
									</div>
									<label class="epc-toggle">
										<span class="epc-toggle__label"><?php esc_html_e( 'Enabled', 'epasscard' ); ?></span>
										<input type="checkbox" name="epc_notification_enabled_<?php echo esc_attr( $type ); ?>" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> />
										<span class="epc-toggle__track" aria-hidden="true"></span>
									</label>
								</div>
								<div class="epc-push-row__fields">
									<div>
										<label for="epc-notification-title-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Push title', 'epasscard' ); ?></label>
										<input type="text" class="regular-text" id="epc-notification-title-<?php echo esc_attr( $type ); ?>" name="epc_notification_title_<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( (string) ( $rule['title'] ?? '' ) ); ?>" />
									</div>
									<div>
										<label for="epc-notification-message-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Push message', 'epasscard' ); ?></label>
										<textarea class="large-text" rows="3" id="epc-notification-message-<?php echo esc_attr( $type ); ?>" name="epc_notification_message_<?php echo esc_attr( $type ); ?>"><?php echo esc_textarea( (string) ( $rule['message'] ?? '' ) ); ?></textarea>
									</div>
								</div>
							</div>
						<?php endforeach; ?>

						<div class="epc-push-card__footer">
							<?php submit_button( __( 'Save reminder settings', 'epasscard' ), 'primary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php $this->render_push_notification_test_tool( $types ); ?>
		</div>
		<?php
	}

	/**
	 * Short helper text for a notification type row (override in module).
	 *
	 * @param string $type Notification type key.
	 * @return string
	 */
	public function get_notification_type_description( $type ) {
		unset( $type );
		return '';
	}

	/**
	 * Manual test push tool for module notification settings.
	 *
	 * @param array<string, string> $types Notification types.
	 * @return void
	 */
	protected function render_push_notification_test_tool( array $types ) {
		if ( empty( $types ) ) {
			return;
		}
		?>
		<div class="epc-test-push-tool epc-card">
			<h3><?php esc_html_e( 'Send test notification', 'epasscard' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Send a one-off push using the title and message above for the selected reminder type. Pass UUID is shown in the Issued passes list.', 'epasscard' ); ?>
			</p>
			<?php if ( ! EPC_Api_Client::is_configured() ) : ?>
				<p class="description"><?php esc_html_e( 'Connect your EpassCard API key before sending test notifications.', 'epasscard' ); ?></p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="epc-test-push-pass-id"><?php esc_html_e( 'Pass ID', 'epasscard' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="epc-test-push-pass-id" placeholder="<?php esc_attr_e( 'Pass UUID, record ID, or source ID', 'epasscard' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="epc-test-push-type"><?php esc_html_e( 'Reminder type', 'epasscard' ); ?></label>
						</th>
						<td>
							<select id="epc-test-push-type">
								<option value=""><?php esc_html_e( '— Select —', 'epasscard' ); ?></option>
								<?php foreach ( $types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-secondary" id="epc-send-test-push" <?php disabled( ! EPC_Api_Client::is_configured() ); ?>>
					<span class="epc-btn-label"><?php esc_html_e( 'Send test notification', 'epasscard' ); ?></span>
					<span class="spinner"></span>
				</button>
			</p>
			<p class="epc-test-push-status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Placeholder values for a pass row (override per module with notifications).
	 *
	 * @param object $pass_row Pass row.
	 * @return array<string, string>
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		return array();
	}

	/**
	 * AJAX: send a test push notification for a pass.
	 *
	 * @return void
	 */
	public function ajax_send_test_push() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! $this->current_user_can_manage_passes() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		if ( ! $this->is_available() ) {
			wp_send_json_error( array( 'message' => $this->get_unavailable_message() ), 400 );
		}

		if ( ! EPC_Api_Client::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your EpassCard API key first.', 'epasscard' ) ), 400 );
		}

		$types = $this->get_notification_types();
		if ( empty( $types ) ) {
			wp_send_json_error( array( 'message' => __( 'Push notifications are not configured for this module.', 'epasscard' ) ), 400 );
		}

		$identifier = isset( $_POST['pass_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pass_id'] ) ) : '';
		$type       = isset( $_POST['notification_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['notification_type'] ) ) : '';

		if ( '' === trim( $identifier ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a pass UUID, record ID, or source ID.', 'epasscard' ) ), 400 );
		}

		if ( '' === $type || ! isset( $types[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a reminder type.', 'epasscard' ) ), 400 );
		}

		$pass = EPC_DB::resolve_pass_for_module( $this->get_slug(), $identifier );
		if ( ! $pass ) {
			wp_send_json_error( array( 'message' => __( 'Pass not found for this module.', 'epasscard' ) ), 404 );
		}

		if ( empty( $pass->pass_uid ) ) {
			wp_send_json_error( array( 'message' => __( 'This pass has no wallet UID yet.', 'epasscard' ) ), 400 );
		}

		$replacements = $this->build_push_replacements_for_pass( $pass );
		$title_raw    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$message_raw  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '';

		if ( '' !== trim( $title_raw ) && '' !== trim( $message_raw ) ) {
			$title   = EPC_Pass_Notifications::replace_tags( $title_raw, $replacements );
			$message = EPC_Pass_Notifications::replace_tags( $message_raw, $replacements );
		} else {
			$copy = $this->build_push_notification_copy( $type, $replacements, true );
			if ( null === $copy ) {
				wp_send_json_error(
					array(
						'message' => __( 'Add a push title and message for this reminder type in the form above.', 'epasscard' ),
					),
					400
				);
			}
			$title   = $copy['title'];
			$message = $copy['message'];
		}

		$result = EPC_Api_Client::send_pass_push( (string) $pass->pass_uid, $title, $message );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Test notification sent.', 'epasscard' ),
			)
		);
	}

	/**
	 * Template tags available in notification copy (override per module).
	 *
	 * @return array<int, string>
	 */
	public function get_notification_template_tags() {
		return array();
	}

	/**
	 * Save push notification rules from request data.
	 *
	 * @return true|\WP_Error
	 */
	public function save_notification_rules_from_request() {
		if ( ! $this->is_available() || empty( $this->get_notification_types() ) ) {
			return new WP_Error( 'epc_unavailable', __( 'This integration is not available.', 'epasscard' ) );
		}

		if ( ! $this->current_user_can_manage_passes() ) {
			return new WP_Error( 'epc_forbidden', __( 'Permission denied.', 'epasscard' ) );
		}

		$rules = array();
		foreach ( array_keys( $this->get_notification_types() ) as $type ) {
			$type = sanitize_key( (string) $type );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_notification_rules().
			$rules[ $type ] = array(
				'enabled' => isset( $_POST[ 'epc_notification_enabled_' . $type ] ),
				'title'   => isset( $_POST[ 'epc_notification_title_' . $type ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ 'epc_notification_title_' . $type ] ) ) : '',
				'message' => isset( $_POST[ 'epc_notification_message_' . $type ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ 'epc_notification_message_' . $type ] ) ) : '',
			);

			if ( ! $this->uses_native_reminder_timing() ) {
				$rules[ $type ]['days'] = isset( $_POST[ 'epc_notification_days_' . $type ] ) ? absint( wp_unslash( $_POST[ 'epc_notification_days_' . $type ] ) ) : 7;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		update_option( $this->get_notification_rules_option_key(), $rules );

		return true;
	}

	/**
	 * AJAX: save push notification rules.
	 *
	 * @return void
	 */
	public function ajax_save_notification_rules() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		$result = $this->save_notification_rules_from_request();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Notification settings saved.', 'epasscard' ),
			)
		);
	}

	/**
	 * Save pass behavior status rules from request data.
	 *
	 * @return true|\WP_Error
	 */
	public function save_status_rules_from_request() {
		if ( ! $this->has_pass_behavior_settings() || ! $this->is_available() ) {
			return new WP_Error( 'epc_unavailable', __( 'Pass behavior settings are not available for this integration.', 'epasscard' ) );
		}

		if ( ! $this->current_user_can_manage_passes() ) {
			return new WP_Error( 'epc_forbidden', __( 'Permission denied.', 'epasscard' ) );
		}

		$allowed_actions = array_keys( $this->get_status_action_options() );
		$rules           = array();

		foreach ( array_keys( $this->get_pass_behavior_statuses() ) as $status ) {
			$field = 'epc_status_rule_' . $status;
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_status_rules().
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$action = sanitize_key( wp_unslash( (string) $_POST[ $field ] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( in_array( $action, $allowed_actions, true ) ) {
				$rules[ $status ] = $action;
			}
		}

		update_option( $this->get_status_rules_option_key(), $rules );

		return true;
	}

	/**
	 * AJAX: save pass behavior status rules.
	 *
	 * @return void
	 */
	public function ajax_save_status_rules() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		$result = $this->save_status_rules_from_request();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Pass behavior settings saved.', 'epasscard' ),
			)
		);
	}
}

/**
 * List table for module pass records.
 */
class EPC_Module_List_Table extends WP_List_Table {

	/**
	 * Module instance.
	 *
	 * @var EPC_Module
	 */
	private EPC_Module $module;

	/**
	 * Search string.
	 *
	 * @var string
	 */
	private string $search;

	/**
	 * List filters.
	 *
	 * @var array<string, mixed>
	 */
	private array $filters;

	/**
	 * Redirect URL for row actions.
	 *
	 * @var string
	 */
	private string $redirect_url;

	/**
	 * Constructor.
	 *
	 * @param EPC_Module         $module       Module.
	 * @param string             $search       Search query.
	 * @param array<string,mixed> $filters     Filters.
	 * @param string             $redirect_url Redirect after pass actions.
	 */
	public function __construct( EPC_Module $module, $search, array $filters = array(), $redirect_url = '' ) {
		parent::__construct(
			array(
				'plural'   => 'epc-passes',
				'singular' => 'epc-pass',
				'ajax'     => false,
			)
		);

		$this->module  = $module;
		$this->search  = $search;
		$this->filters = $filters;
		$this->redirect_url = '' !== $redirect_url
			? $redirect_url
			: admin_url( 'admin.php?page=epc-' . $module->get_slug() );
	}

	/**
	 * Build query args for DB layer.
	 *
	 * @param int  $page       Page number.
	 * @param int  $per_page   Per page.
	 * @param bool $count_only Count only flag.
	 * @return array<string, mixed>
	 */
	private function build_query_args( $page, $per_page, $count_only = false ) {
		$args = array(
			'module'     => $this->module->get_slug(),
			'search'     => $this->search,
			'page'       => $page,
			'per_page'   => $per_page,
			'count_only' => $count_only,
		);

		if ( ! empty( $this->filters['status'] ) ) {
			$args['status'] = sanitize_key( (string) $this->filters['status'] );
		}

		if ( ! empty( $this->filters['entity_id'] ) ) {
			$args['entity_id'] = absint( $this->filters['entity_id'] );
		}

		return $args;
	}

	/**
	 * Primary column for list table row markup.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'source_id';
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'source_id'  => __( 'Record ID', 'epasscard' ),
			'user'       => __( 'Member', 'epasscard' ),
			'entity'     => $this->module->get_entity_column_label(),
			'pass_uid'   => __( 'Pass ID', 'epasscard' ),
			'pass_link'  => __( 'Pass link', 'epasscard' ),
			'status'     => __( 'Status', 'epasscard' ),
			'updated_at' => __( 'Updated', 'epasscard' ),
			'actions'    => __( 'Actions', 'epasscard' ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		$count_result = EPC_DB::query_passes(
			$this->build_query_args( 1, 1, true )
		);

		$total_items = (int) $count_result['total'];
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);

		$current_page = $this->get_pagenum();

		$result = EPC_DB::query_passes(
			$this->build_query_args( $current_page, $per_page )
		);

		$this->items = $result['items'];

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
		);
	}

	/**
	 * Output a table row with a stable source id for AJAX updates.
	 *
	 * @param object $item Pass row.
	 * @return void
	 */
	public function single_row( $item ) {
		echo '<tr data-epc-source-id="' . esc_attr( (string) $item->source_id ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Default column output.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'source_id':
				return esc_html( (string) $item->source_id );
			case 'user':
				$user = get_userdata( (int) $item->user_id );
				if ( $user ) {
					return esc_html( $user->display_name ) . '<br /><small>' . esc_html( $user->user_email ) . '</small>';
				}
				return esc_html( (string) $item->user_id );
			case 'entity':
				return esc_html( $this->module->get_entity_label( (int) $item->entity_id ) );
			case 'pass_uid':
				return '<code>' . esc_html( (string) $item->pass_uid ) . '</code>';
			case 'pass_link':
				$link = (string) $item->pass_link;
				if ( '' === $link ) {
					return '—';
				}
				return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View pass', 'epasscard' ) . '</a>';
			case 'status':
				return esc_html( ucfirst( (string) $item->status ) );
			case 'updated_at':
				return esc_html( (string) $item->updated_at );
			case 'actions':
				return $this->module->render_pass_action_links( (string) $item->source_id, $this->redirect_url );
			default:
				return '';
		}
	}

	/**
	 * Empty state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No passes issued yet.', 'epasscard' );
	}
}
