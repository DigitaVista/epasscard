<?php
/**
 * Ultimate Member integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A member card for each Ultimate Member user.
 *
 * - Record: the WordPress user id; item: the user's role (Ultimate Member's priority role when
 *   the user has several), so each role can have its own design. Administrators get no card.
 * - The account status decides the card: Approved creates it; Inactive and Rejected void it;
 *   pending ones wait (changeable per status). Live changes wait until Ultimate Member has set a
 *   status; "Give cards to existing members" counts a user without one as Approved, as
 *   Ultimate Member itself sets it.
 */
class EPC_Module_Ultimate_Member extends EPC_Module {

	/**
	 * Option: role key => item id (items are numbers; roles are names).
	 */
	const ROLE_IDS_OPTION = 'epc_um_role_ids';

	/**
	 * Option: the one-time 1.2.0 change of non-member roles has run (roles it changed, notice).
	 */
	const ROLES_LIMITED_OPTION = 'epc_um_roles_limited';

	/**
	 * Member roles of this request (see member_roles()).
	 *
	 * @var array<int, string>|null
	 */
	private $member_roles = null;

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'ultimate-member';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Ultimate Member', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'Ultimate Member', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => 'ultimate-member',
				'label'       => __( 'Ultimate Member', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'ultimate-member',
				'plugin_file' => 'ultimate-member/ultimate-member.php',
				'url'         => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_ultimate_member_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'Ultimate Member is not installed or activated. Activate Ultimate Member, then return here to set up member cards.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A member card for each Ultimate Member user, with a design per role. The account status decides when the card is created or voided.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name' => __( 'Display name', 'epasscard' ),
			'user_email'        => __( 'Email', 'epasscard' ),
			'user_first_name'   => __( 'First name', 'epasscard' ),
			'user_last_name'    => __( 'Last name', 'epasscard' ),
			'user_full_name'    => __( 'Full name', 'epasscard' ),
			'member_id'         => __( 'Member ID (user ID)', 'epasscard' ),
			'role_name'         => __( 'Role', 'epasscard' ),
			'membership_status' => __( 'Account status', 'epasscard' ),
			'member_since'      => __( 'Member since', 'epasscard' ),
			'profile_url'       => __( 'Profile page', 'epasscard' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Items: roles
	 * ---------------------------------------------------------------------- */

	/**
	 * Item id of a role, made once and kept.
	 *
	 * @param string $role Role key.
	 * @return int
	 */
	public function role_id( $role ) {
		$role = sanitize_key( (string) $role );
		if ( '' === $role ) {
			return 0;
		}
		$ids = get_option( self::ROLE_IDS_OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		if ( ! isset( $ids[ $role ] ) ) {
			$ids[ $role ] = empty( $ids ) ? 1 : max( array_map( 'absint', $ids ) ) + 1;
			update_option( self::ROLE_IDS_OPTION, $ids, false );
		}
		return absint( $ids[ $role ] );
	}

	/**
	 * Role key of an item id, or ''.
	 *
	 * @param int $entity_id Item id.
	 * @return string
	 */
	public function role_for_id( $entity_id ) {
		$ids  = get_option( self::ROLE_IDS_OPTION, array() );
		$role = is_array( $ids ) ? array_search( absint( $entity_id ), array_map( 'absint', $ids ), true ) : false;
		return false === $role ? '' : (string) $role;
	}

	/**
	 * Roles that can have a card: every role but administrator.
	 *
	 * @return array<string, string> role key => name
	 */
	private function card_roles() {
		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		unset( $names['administrator'] );
		return array_map( 'translate_user_role', $names );
	}

	/**
	 * Roles people get by registering, and Ultimate Member's own roles. Other roles (shop
	 * customers, shop managers, booking customers, editors) are often given to everyone who
	 * buys or works on the site, and Ultimate Member approves those accounts too, so they get
	 * no member card unless the merchant picks the design for them under Design → Applies to.
	 *
	 * @return array<int, string> Role keys.
	 */
	private function member_roles() {
		if ( null !== $this->member_roles ) {
			return $this->member_roles;
		}
		$roles = array( (string) get_option( 'default_role', 'subscriber' ) );
		if ( function_exists( 'UM' ) ) {
			$roles[] = (string) UM()->options()->get( 'register_role' );
			if ( method_exists( UM()->form(), 'assigned_role' ) ) {
				$forms = get_posts(
					array(
						'post_type'   => 'um_form',
						'post_status' => 'publish',
						'numberposts' => 50,
						'fields'      => 'ids',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Registration forms only; a site has a few forms.
						'meta_query'  => array(
							array(
								'key'   => '_um_mode',
								'value' => 'register',
							),
						),
					)
				);
				foreach ( $forms as $form_id ) {
					$roles[] = (string) UM()->form()->assigned_role( (int) $form_id );
				}
			}
		}
		foreach ( array_keys( $this->card_roles() ) as $role ) {
			if ( 0 === strpos( (string) $role, 'um_' ) ) {
				$roles[] = (string) $role;
			}
		}
		$this->member_roles = array_values( array_unique( array_filter( $roles ) ) );
		return $this->member_roles;
	}

	/**
	 * Roles that are not member roles get no card until the merchant picks the design for them.
	 *
	 * @inheritDoc
	 */
	public function starts_without_card( $entity_id ) {
		$role = $this->role_for_id( $entity_id );
		return '' !== $role && ! in_array( $role, $this->member_roles(), true );
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		$out = array();
		foreach ( $this->card_roles() as $role => $name ) {
			$out[] = array(
				'id'    => $this->role_id( $role ),
				'label' => (string) $name,
			);
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$role  = $this->role_for_id( $entity_id );
		$names = $this->card_roles();
		return isset( $names[ $role ] ) ? $names[ $role ] : ( '' !== $role ? $role : '#' . absint( $entity_id ) );
	}

	/**
	 * A card's item is a role, which is no name for the card ("Your Subscriber wallet pass").
	 *
	 * @inheritDoc
	 */
	public function get_pass_name( $entity_id ) {
		unset( $entity_id );
		return __( 'Membership', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Role', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __( 'No roles found.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=um_roles' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Ultimate Member roles', 'epasscard' );
	}

	/**
	 * The role a user's card is for: Ultimate Member's priority role, never administrator.
	 *
	 * @param int $user_id User id.
	 * @return string Role key, or '' for no card.
	 */
	public function user_role( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user || empty( $user->roles ) ) {
			return '';
		}
		$role = '';
		if ( function_exists( 'UM' ) && method_exists( UM()->roles(), 'get_priority_user_role' ) ) {
			$role = (string) UM()->roles()->get_priority_user_role( $user->ID );
		}
		if ( '' === $role ) {
			$roles = array_values( (array) $user->roles );
			$role  = (string) $roles[0];
		}
		return 'administrator' === $role || in_array( 'administrator', (array) $user->roles, true ) ? '' : $role;
	}

	/**
	 * Account status of a user ('' counts as approved).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function user_status( $user_id ) {
		$status = (string) get_user_meta( absint( $user_id ), 'account_status', true );
		return '' === $status ? 'approved' : $status;
	}

	/* -------------------------------------------------------------------------
	 * Status rules
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	public function get_status_rules_option_key() {
		return 'epc_ultimate_member_status_rules';
	}

	/**
	 * @inheritDoc
	 */
	public function has_pass_behavior_settings() {
		return true;
	}

	/**
	 * Ultimate Member account statuses.
	 *
	 * @inheritDoc
	 */
	public function get_pass_behavior_statuses() {
		if ( function_exists( 'UM' ) && method_exists( UM()->common()->users(), 'statuses_list' ) ) {
			return array_map( 'strval', (array) UM()->common()->users()->statuses_list() );
		}
		return array(
			'approved'                    => __( 'Approved', 'epasscard' ),
			'awaiting_admin_review'       => __( 'Pending administrator review', 'epasscard' ),
			'awaiting_email_confirmation' => __( 'Waiting email confirmation', 'epasscard' ),
			'inactive'                    => __( 'Membership inactive', 'epasscard' ),
			'rejected'                    => __( 'Membership rejected', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_status_rules() {
		return array(
			'approved'                    => 'sync',
			'awaiting_admin_review'       => 'none',
			'awaiting_email_confirmation' => 'none',
			'inactive'                    => 'revoke',
			'rejected'                    => 'revoke',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}
		$this->render_status_rules_form(
			array(
				'heading'      => __( 'Member cards by account status', 'epasscard' ),
				'description'  => __( 'Choose what happens to the member card when an Ultimate Member account is approved, deactivated or rejected.', 'epasscard' ),
				'status_label' => __( 'Account status', 'epasscard' ),
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'um_after_user_status_is_changed', array( $this, 'on_status_changed' ), 20, 2 );
		add_action( 'um_registration_complete', array( $this, 'on_user_event' ), 20, 1 );
		add_action( 'set_user_role', array( $this, 'on_user_event' ), 20, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20, 1 );
		add_action( 'delete_user', array( $this, 'on_delete_user' ), 10, 1 );
		add_filter( 'um_account_page_default_tabs_hook', array( $this, 'add_account_tab' ) );
		add_filter( 'um_account_content_hook_epasscard', array( $this, 'render_account_tab' ) );
		add_action( 'admin_init', array( $this, 'maybe_limit_roles_once' ) );
		add_action( 'admin_notices', array( $this, 'render_roles_limited_notice' ) );
		add_action( 'admin_post_epc_um_roles_notice_dismiss', array( $this, 'handle_roles_notice_dismiss' ) );
	}

	/**
	 * Once (1.2.0): before, saving the design set every role to it, so shop customers that
	 * Ultimate Member approves got member cards. Roles that are not member roles, still use the
	 * design EpassCard made and have no cards yet go back to "No card". A role that has cards,
	 * or that the merchant gave another design, is left as it is; no card is changed.
	 *
	 * @return void
	 */
	public function maybe_limit_roles_once() {
		if ( false !== get_option( self::ROLES_LIMITED_OPTION, false ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$saved   = class_exists( 'EPC_Template_Starter' ) ? EPC_Template_Starter::get_saved( $this->get_slug() ) : null;
		$changed = array();
		if ( null !== $saved ) {
			$mappings = $this->get_mappings();
			$cards    = EPC_DB::count_active_real_passes_by_entity( $this->get_slug() );
			foreach ( $this->card_roles() as $role => $name ) {
				$id   = $this->role_id( $role );
				$own  = isset( $mappings[ (string) $id ] ) && is_array( $mappings[ (string) $id ] ) ? $mappings[ (string) $id ] : array();
				$keep = ! $this->starts_without_card( $id )
					|| (string) ( $own['template_uid'] ?? '' ) !== (string) $saved['template_uid']
					|| (int) ( $cards[ $id ] ?? 0 ) > 0;
				if ( ! $keep ) {
					unset( $mappings[ (string) $id ] );
					$changed[] = (string) $name;
				}
			}
			if ( ! empty( $changed ) ) {
				update_option( $this->get_mappings_option_key(), $mappings, false );
			}
		}
		update_option(
			self::ROLES_LIMITED_OPTION,
			array(
				'roles'  => $changed,
				'notice' => ! empty( $changed ),
			)
		);
	}

	/**
	 * Say once which roles the 1.2.0 change set to "No card".
	 *
	 * @return void
	 */
	public function render_roles_limited_notice() {
		$state = get_option( self::ROLES_LIMITED_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['notice'] ) || empty( $state['roles'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=epc_um_roles_notice_dismiss' ), 'epc_um_roles_notice_dismiss' );
		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: role names, e.g. "Customer, Shop manager" */
				__( 'EpassCard: Ultimate Member member cards now go to member roles only. These roles get no new member cards: %s. Cards already issued are unchanged. To give one of them cards, choose "This design" under Design → Applies to.', 'epasscard' ),
				implode( ', ', array_map( 'strval', (array) $state['roles'] ) )
			)
		);
		echo '</p><p><a class="button" href="' . esc_url( EPC_Admin_Design::url_for_module( $this->get_slug() ) ) . '">' . esc_html__( 'Review roles', 'epasscard' ) . '</a> <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'epasscard' ) . '</a></p></div>';
	}

	/**
	 * Admin-post: hide the notice.
	 *
	 * @return void
	 */
	public function handle_roles_notice_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_um_roles_notice_dismiss' );
		$state = get_option( self::ROLES_LIMITED_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		update_option( self::ROLES_LIMITED_OPTION, array_merge( $state, array( 'notice' => false ) ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * "Wallet card" tab on the Ultimate Member account page. Ultimate Member hides a tab whose
	 * content is empty, so members without a card do not see it.
	 *
	 * @param array<int, array<string, array<string, mixed>>> $tabs Tabs by position.
	 * @return array<int, array<string, array<string, mixed>>>
	 */
	public function add_account_tab( $tabs ) {
		$tabs = is_array( $tabs ) ? $tabs : array();
		$tabs[150]['epasscard'] = array(
			'icon'        => 'um-faicon-credit-card',
			'title'       => __( 'Wallet card', 'epasscard' ),
			'show_button' => false,
			// The account template shows tabs it knows of only when turned on in its settings, or "custom".
			'custom'      => true,
		);
		return $tabs;
	}

	/**
	 * The tab: a link that adds the member card to Apple Wallet or Google Wallet.
	 *
	 * @param string $output Tab HTML.
	 * @return string
	 */
	public function render_account_tab( $output ) {
		$user_id = get_current_user_id();
		$card    = $user_id > 0 ? EPC_DB::find_active_card( $this->get_slug(), $user_id ) : null;
		if ( ! $card || empty( $card->pass_link ) ) {
			return (string) $output;
		}
		return (string) $output . '<div class="um-field epc-um-card"><p>' . esc_html__( 'Your member card for Apple Wallet and Google Wallet.', 'epasscard' ) . '</p><p><a class="um-button" href="' . esc_url( (string) $card->pass_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Add to wallet', 'epasscard' ) . '</a></p></div>';
	}

	/**
	 * Status changed.
	 *
	 * @param string $status  New status.
	 * @param int    $user_id User id.
	 * @return void
	 */
	public function on_status_changed( $status, $user_id ) {
		unset( $status );
		$this->handle_user( absint( $user_id ) );
	}

	/**
	 * Registered, or role changed: apply the rule of the current status.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_user_event( $user_id ) {
		$this->handle_user( absint( $user_id ) );
	}

	/**
	 * Profile saved: refresh an existing card only (name or email may have changed).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_profile_update( $user_id ) {
		$user_id = absint( $user_id );
		$pass    = EPC_DB::get_pass( $this->get_slug(), (string) $user_id );
		if ( ! $pass || empty( $pass->pass_uid ) || 'active' !== (string) $pass->status || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$this->sync_by_source_id( (string) $user_id, 'update' );
	}

	/**
	 * User deleted: void the card.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_delete_user( $user_id ) {
		EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) absint( $user_id ) );
	}

	/**
	 * Apply the status rule to a user's card.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function handle_user( $user_id ) {
		if ( $user_id <= 0 || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$status = (string) get_user_meta( $user_id, 'account_status', true );
		if ( '' === $status ) {
			// A registration in progress: Ultimate Member sets the status next, and that change
			// brings the user back here.
			return;
		}
		$action = $this->get_status_rule( $status );
		if ( '' === $this->user_role( $user_id ) && 'revoke' !== $action ) {
			$action = 'revoke'; // Became an administrator, or has no role: no member card.
		}
		switch ( $action ) {
			case 'sync':
			case 'update':
				// A failure is recorded by the pass service and shown on Cards.
				$result = $this->sync_by_source_id( (string) $user_id, $action );
				if ( is_wp_error( $result ) && 'epc_no_mapping' === $result->get_error_code() ) {
					// The member's role (now) has no card: a card from an earlier role must not
					// stay live showing that role.
					EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $user_id );
				}
				break;
			case 'revoke':
				EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $user_id );
				break;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$user_id = absint( $source_id );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'epc_invalid_source', __( 'User not found.', 'epasscard' ) );
		}
		$role = $this->user_role( $user_id );
		if ( '' === $role ) {
			return new WP_Error( 'epc_invalid_source', __( 'Administrators and users without a role get no member card.', 'epasscard' ) );
		}
		$entity_id = $this->role_id( $role );
		$mapping   = $this->get_mapping( $entity_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for this role yet.', 'epasscard' ) );
		}

		// The role decides the design: after a role change to a role on another design, the old
		// card is voided and one on the new design is issued.
		return EPC_Pass_Service::sync_pass_or_reissue( $this->get_slug(), (string) $user_id, $entity_id, $user_id, $mapping, $this->build_values( $user, $role ), $mode );
	}

	/**
	 * Field values of a member card.
	 *
	 * @param WP_User $user User.
	 * @param string  $role Role key.
	 * @return array<string, string>
	 */
	private function build_values( $user, $role ) {
		$first    = (string) get_user_meta( $user->ID, 'first_name', true );
		$last     = (string) get_user_meta( $user->ID, 'last_name', true );
		$statuses = $this->get_pass_behavior_statuses();
		$status   = $this->user_status( $user->ID );
		$names    = $this->card_roles();
		$since    = strtotime( (string) $user->user_registered . ' UTC' );

		return array(
			'user_display_name' => (string) $user->display_name,
			'user_email'        => (string) $user->user_email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, (string) $user->display_name ),
			'member_id'         => (string) $user->ID,
			'role_name'         => isset( $names[ $role ] ) ? (string) $names[ $role ] : $role,
			'membership_status' => isset( $statuses[ $status ] ) ? (string) $statuses[ $status ] : $status,
			'member_since'      => $since ? wp_date( (string) get_option( 'date_format' ), $since ) : '',
			'profile_url'       => function_exists( 'um_user_profile_url' ) ? (string) um_user_profile_url( $user->ID ) : '',
		);
	}

	/* -------------------------------------------------------------------------
	 * Existing members
	 * ---------------------------------------------------------------------- */

	/**
	 * Users with this role (as their card role) whose status gives a card, oldest first.
	 *
	 * @inheritDoc
	 */
	public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
		$role = $this->role_for_id( absint( $entity_id ) );
		if ( '' === $role ) {
			return array();
		}
		$ids = get_users(
			array(
				'role'    => $role,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => absint( $limit ),
				'offset'  => absint( $offset ),
			)
		);
		$out = array();
		foreach ( (array) $ids as $user_id ) {
			$user_id = absint( $user_id );
			$ok      = $this->user_role( $user_id ) === $role && 'sync' === $this->get_status_rule( $this->user_status( $user_id ) );
			// One entry per row read keeps paging by offset correct.
			$out[] = $ok ? (string) $user_id : '';
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_scope_label() {
		return __( 'Approved members of these roles', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_backfill_user_id( $source_id ) {
		return absint( $source_id );
	}
}
