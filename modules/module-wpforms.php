<?php
/**
 * WPForms integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A wallet card for each submission of a form that has a card design.
 *
 * - Item: the form. A form gets cards once a design is saved for it; forms without a design
 *   are left alone.
 * - Record: a row in EpassCard's form submissions table (EPC_DB::form_submissions_table_name()).
 *   WPForms Lite keeps no entries on the site, so the row keeps the values the card shows (only
 *   the fields the form's design uses, plus the name and email for the card email), the
 *   address and the card code. With WPForms Pro the entry id is kept as well.
 * - The card goes to the form's first Email field; a logged-in visitor's card is also linked
 *   to their account. The confirmation message gets an "Add to wallet" link.
 * - WordPress's Erase Personal Data tool removes a person's rows and voids their cards.
 */
class EPC_Module_WPForms extends EPC_Module {

	/**
	 * Field types that hold no answer, or one that must not go on a card.
	 *
	 * @var array<int, string>
	 */
	const SKIPPED_FIELD_TYPES = array( 'divider', 'html', 'pagebreak', 'captcha', 'content', 'layout', 'repeater', 'entry-preview', 'internal-information', 'password', 'file-upload', 'signature', 'camera' );

	/**
	 * Card made in this request, per form id: for the confirmation message.
	 *
	 * @var array<int, string>
	 */
	private $made_in_request = array();

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'wpforms';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WPForms', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_label() {
		return __( 'WPForms', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_dependency_install_info() {
		return array(
			array(
				'key'         => 'wpforms-lite',
				'label'       => __( 'WPForms', 'epasscard' ),
				'mode'        => 'wporg',
				'slug'        => 'wpforms-lite',
				'plugin_file' => 'wpforms-lite/wpforms.php',
				'url'         => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_wpforms_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __( 'WPForms is not installed or activated. Activate WPForms (Lite or Pro), then return here to set up form cards.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_page_intro() {
		return __( 'A wallet card for each submission of a form that has a card design. The card goes to the form\'s first Email field and is linked on the confirmation message.', 'epasscard' );
	}

	/**
	 * Card fields every form has.
	 *
	 * @return array<string, string>
	 */
	private function common_fields() {
		return array(
			'user_full_name' => __( 'Name (the form\'s first Name field)', 'epasscard' ),
			'user_email'     => __( 'Email (the form\'s first Email field)', 'epasscard' ),
			'form_title'     => __( 'Form name', 'epasscard' ),
			'submitted_date' => __( 'Submitted on', 'epasscard' ),
			'card_code'      => __( 'Card code (10 characters)', 'epasscard' ),
			'submission_id'  => __( 'Submission number', 'epasscard' ),
		);
	}

	/**
	 * Common fields, then each form's own fields as "Form: Field".
	 *
	 * @inheritDoc
	 */
	public function get_source_fields() {
		$out = $this->common_fields();
		foreach ( $this->get_forms() as $form ) {
			foreach ( $this->form_fields( $form ) as $field_id => $field ) {
				/* translators: 1: form name, 2: field label */
				$out[ self::field_key( (int) $form->ID, $field_id ) ] = sprintf( __( '%1$s: %2$s', 'epasscard' ), $this->form_title( $form ), $field['label'] );
			}
		}
		return $out;
	}

	/**
	 * Source key of a form's field.
	 *
	 * @param int        $form_id  Form id.
	 * @param int|string $field_id Field id.
	 * @return string
	 */
	public static function field_key( $form_id, $field_id ) {
		return 'f' . absint( $form_id ) . '_' . absint( $field_id );
	}

	/* -------------------------------------------------------------------------
	 * Items: forms
	 * ---------------------------------------------------------------------- */

	/**
	 * Published forms, up to 100 (read directly: WPForms' own getter checks the current user's rights).
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_forms() {
		$forms = get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => 'publish',
				'posts_per_page' => 100, // The first 100 by name are listed; any form with a design still gives cards.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return is_array( $forms ) ? $forms : array();
	}

	/**
	 * A form's settings and fields.
	 *
	 * @param WP_Post|int $form Form.
	 * @return array<string, mixed>
	 */
	private function form_data( $form ) {
		$post = $form instanceof WP_Post ? $form : get_post( absint( $form ) );
		if ( ! $post || 'wpforms' !== $post->post_type ) {
			return array();
		}
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( (string) $post->post_content, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fields of a form that can go on a card.
	 *
	 * @param WP_Post|int $form Form.
	 * @return array<int, array{type: string, label: string}>
	 */
	private function form_fields( $form ) {
		$data = $this->form_data( $form );
		$out  = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( ! isset( $field['id'] ) || '' === $type || in_array( $type, self::SKIPPED_FIELD_TYPES, true ) ) {
				continue;
			}
			$label = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? (string) $field['label'] : sprintf(
				/* translators: %d: field number */
				__( 'Field %d', 'epasscard' ),
				absint( $field['id'] )
			);
			$out[ absint( $field['id'] ) ] = array(
				'type'  => $type,
				'label' => wp_strip_all_tags( $label ),
			);
		}
		return $out;
	}

	/**
	 * Form name.
	 *
	 * @param WP_Post $form Form.
	 * @return string
	 */
	private function form_title( $form ) {
		$title = trim( wp_strip_all_tags( (string) $form->post_title ) );
		return '' !== $title ? $title : '#' . (int) $form->ID;
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		$out = array();
		foreach ( $this->get_forms() as $form ) {
			$out[] = array(
				'id'    => (int) $form->ID,
				'label' => $this->form_title( $form ),
			);
		}
		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$post = get_post( absint( $entity_id ) );
		return $post && 'wpforms' === $post->post_type ? $this->form_title( $post ) : '#' . absint( $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Form', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __( 'No published forms yet.', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'admin.php?page=wpforms-builder' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Add a form in WPForms', 'epasscard' );
	}

	/* -------------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		add_action( 'wpforms_process_complete', array( $this, 'on_submission' ), 20, 4 );
		add_filter( 'wpforms_frontend_confirmation_message', array( $this, 'add_confirmation_link' ), 20, 4 );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
	}

	/**
	 * A form was submitted: keep the card's values and make the card, when the form has a design.
	 *
	 * @param array<int|string, array<string, mixed>> $fields    Processed fields.
	 * @param array<string, mixed>                    $entry     Raw submission.
	 * @param array<string, mixed>                    $form_data Form settings and fields.
	 * @param int                                     $entry_id  Entry id (0 in WPForms Lite).
	 * @return void
	 */
	public function on_submission( $fields, $entry, $form_data, $entry_id ) {
		unset( $entry );
		$form_id = absint( $form_data['id'] ?? 0 );
		$mapping = $form_id > 0 ? $this->get_mapping( $form_id ) : array();
		if ( empty( $mapping['template_uid'] ) || ! EPC_Api_Client::is_configured() ) {
			return;
		}
		$row_id = $this->save_submission( $form_id, absint( $entry_id ), (array) $fields, $mapping );
		if ( $row_id <= 0 ) {
			return;
		}
		// A failure is recorded by the pass service and shown on Cards, where it can be retried.
		$result = $this->sync_by_source_id( (string) $row_id, 'sync' );
		if ( true === $result ) {
			$this->made_in_request[ $form_id ] = (string) $row_id;
		}
	}

	/**
	 * Keep one submission: the values the card shows, its address and a new card code.
	 *
	 * @param int                                     $form_id  Form id.
	 * @param int                                     $entry_id WPForms Pro entry id, or 0.
	 * @param array<int|string, array<string, mixed>> $fields   Processed fields.
	 * @param array<string, mixed>                    $mapping  The form's design mapping.
	 * @return int Row id, or 0.
	 */
	public function save_submission( $form_id, $entry_id, array $fields, array $mapping ) {
		global $wpdb;
		$values = $this->submission_values( $form_id, $fields );
		$email  = $values['user_email'];
		$user   = wp_get_current_user();
		/*
		 * The card is the logged-in visitor's when the form has no email or theirs. Another
		 * address means someone (often staff) filled the form in for that person: the card
		 * then goes to the form's email, not to the account of whoever was logged in.
		 */
		$owner = $user && $user->exists() && ( '' === $email || 0 === strcasecmp( $email, (string) $user->user_email ) ) ? $user : null;
		if ( $owner ) {
			$email = (string) $owner->user_email;
			if ( '' === $values['user_full_name'] ) {
				$values['user_full_name'] = (string) $owner->display_name;
			}
		}

		// Only what the design shows, plus the name and email the card email uses.
		$keep = array( 'user_full_name' => true );
		foreach ( (array) ( $mapping['field_mapping'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && 'source' === ( $entry['type'] ?? '' ) && '' !== (string) ( $entry['source'] ?? '' ) ) {
				$keep[ (string) $entry['source'] ] = true;
			}
		}
		$stored = array_intersect_key( $values, $keep );
		unset( $stored['user_email'], $stored['card_code'], $stored['submission_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- EpassCard's own table.
		$ok = $wpdb->insert(
			EPC_DB::form_submissions_table_name(),
			array(
				'module'       => $this->get_slug(),
				'form_id'      => absint( $form_id ),
				'entry_id'     => absint( $entry_id ),
				'user_id'      => $owner ? (int) $owner->ID : 0,
				'email'        => is_email( $email ) ? $email : '',
				'card_code'    => EPC_WC_Product_Cards::new_code(),
				'field_values' => wp_json_encode( $stored ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Card values of a submission: the common fields and every field of the form.
	 *
	 * @param int                                     $form_id Form id.
	 * @param array<int|string, array<string, mixed>> $fields  Processed fields.
	 * @return array<string, string>
	 */
	public function submission_values( $form_id, array $fields ) {
		$post   = get_post( absint( $form_id ) );
		$values = array(
			'user_full_name' => '',
			'user_email'     => '',
			'form_title'     => $post ? $this->form_title( $post ) : '',
		);
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, self::SKIPPED_FIELD_TYPES, true ) ) {
				continue;
			}
			$value = trim( (string) preg_replace( '/\s*\R\s*/', ', ', wp_strip_all_tags( (string) ( $field['value'] ?? '' ) ) ) );
			$values[ self::field_key( $form_id, $field['id'] ) ] = $value;
			if ( 'name' === $type && '' === $values['user_full_name'] ) {
				$values['user_full_name'] = $value;
			}
			if ( 'email' === $type && '' === $values['user_email'] && is_email( $value ) ) {
				$values['user_email'] = $value;
			}
		}
		return $values;
	}

	/**
	 * A kept submission.
	 *
	 * @param int|string $row_id Row id.
	 * @return object|null
	 */
	public function get_submission( $row_id ) {
		global $wpdb;
		$row_id = absint( $row_id );
		if ( $row_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- EpassCard's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND module = %s', EPC_DB::form_submissions_table_name(), $row_id, $this->get_slug() ) );
		return $row ? $row : null;
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$row = $this->get_submission( $source_id );
		if ( ! $row ) {
			return new WP_Error( 'epc_invalid_source', __( 'Form submission not found.', 'epasscard' ) );
		}
		$mapping = $this->get_mapping( (int) $row->form_id );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No card design for this form yet.', 'epasscard' ) );
		}
		return EPC_Pass_Service::sync_pass( $this->get_slug(), (string) $row->id, (int) $row->form_id, (int) $row->user_id, $mapping, $this->build_values( $row ), $mode );
	}

	/**
	 * Field values of a card, from the kept submission.
	 *
	 * @param object $row Submission row.
	 * @return array<string, string>
	 */
	public function build_values( $row ) {
		$stored = json_decode( (string) $row->field_values, true );
		$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$time   = strtotime( (string) $row->created_at . ' UTC' );
		$form   = get_post( (int) $row->form_id );

		return array_merge(
			array(
				'form_title' => $form ? $this->form_title( $form ) : '',
			),
			$stored,
			array(
				'user_email'     => (string) $row->email,
				'card_code'      => (string) $row->card_code,
				'submission_id'  => (string) $row->id,
				'submitted_date' => $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '',
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Where the card goes
	 * ---------------------------------------------------------------------- */

	/**
	 * "Add to wallet" under the confirmation message of a submission that got a card.
	 *
	 * @param string               $message   Confirmation HTML.
	 * @param array<string, mixed> $form_data Form settings.
	 * @param array<mixed>         $fields    Fields.
	 * @param int                  $entry_id  Entry id.
	 * @return string
	 */
	public function add_confirmation_link( $message, $form_data, $fields, $entry_id ) {
		unset( $fields, $entry_id );
		$form_id = absint( $form_data['id'] ?? 0 );
		if ( ! isset( $this->made_in_request[ $form_id ] ) ) {
			return $message;
		}
		$pass = EPC_DB::get_pass( $this->get_slug(), $this->made_in_request[ $form_id ] );
		if ( ! $pass || empty( $pass->pass_link ) || 'active' !== (string) $pass->status ) {
			return $message;
		}
		return (string) $message . '<p class="epc-wpforms-card"><a class="epc-wpforms-card__link" href="' . esc_url( (string) $pass->pass_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Add your card to Apple Wallet or Google Wallet', 'epasscard' ) . '</a></p>';
	}

	/**
	 * A submission without an account: the card goes to its email.
	 *
	 * @inheritDoc
	 */
	public function get_guest_recipient( $source_id ) {
		$row = $this->get_submission( $source_id );
		if ( ! $row || (int) $row->user_id > 0 || ! is_email( (string) $row->email ) ) {
			return null;
		}
		$values = $this->build_values( $row );
		return array(
			'email' => (string) $row->email,
			'name'  => (string) ( $values['user_full_name'] ?? '' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_record_customer( $source_id ) {
		$row = $this->get_submission( $source_id );
		if ( ! $row ) {
			return null;
		}
		$values = $this->build_values( $row );
		return array(
			'name'  => (string) ( $values['user_full_name'] ?? '' ),
			'email' => (string) $row->email,
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- EpassCard's own table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE module = %s AND user_id = %d ORDER BY id DESC LIMIT 50', EPC_DB::form_submissions_table_name(), $this->get_slug(), $user_id ) );
		return array_map( 'absint', (array) $ids );
	}

	/* -------------------------------------------------------------------------
	 * Personal data (Tools → Export / Erase Personal Data)
	 * ---------------------------------------------------------------------- */

	/**
	 * Kept submissions of an email address.
	 *
	 * @param string $email Email.
	 * @return array<int, object>
	 */
	private function submissions_for_email( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		$user  = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( '' === $email ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- EpassCard's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE module = %s AND ( email = %s OR ( user_id > 0 AND user_id = %d ) ) ORDER BY id ASC', EPC_DB::form_submissions_table_name(), $this->get_slug(), $email, $user ? (int) $user->ID : 0 ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_privacy_exporter( $exporters ) {
		$exporters['epasscard-wpforms'] = array(
			'exporter_friendly_name' => __( 'EpassCard form cards (WPForms)', 'epasscard' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function register_privacy_eraser( $erasers ) {
		$erasers['epasscard-wpforms'] = array(
			'eraser_friendly_name' => __( 'EpassCard form cards (WPForms)', 'epasscard' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export: the kept values of each submission and its card link.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export_personal_data( $email, $page = 1 ) {
		unset( $page );
		$fields = $this->get_source_fields();
		$items  = array();
		foreach ( $this->submissions_for_email( $email ) as $row ) {
			$data = array();
			foreach ( $this->build_values( $row ) as $key => $value ) {
				if ( '' !== $value ) {
					$data[] = array(
						'name'  => isset( $fields[ $key ] ) ? $fields[ $key ] : $key,
						'value' => $value,
					);
				}
			}
			$pass = EPC_DB::get_pass( $this->get_slug(), (string) $row->id );
			if ( $pass && ! empty( $pass->pass_link ) ) {
				$data[] = array(
					'name'  => __( 'Wallet card', 'epasscard' ),
					'value' => (string) $pass->pass_link,
				);
			}
			$items[] = array(
				'group_id'    => 'epasscard-wpforms',
				'group_label' => __( 'Wallet cards from forms', 'epasscard' ),
				'item_id'     => 'epasscard-wpforms-' . (int) $row->id,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase: void each card and delete the kept values. A card EpassCard cannot void right now
	 * is retried by the pass service; the values are deleted either way.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$removed  = false;
		$messages = array();
		foreach ( $this->submissions_for_email( $email ) as $row ) {
			EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) $row->id );
			$pass = EPC_DB::get_pass( $this->get_slug(), (string) $row->id );
			if ( $pass && ! empty( $pass->pass_uid ) && 'revoked' !== (string) $pass->status ) {
				// The pass service retries the void from the card row; the values are not needed.
				$messages[] = __( 'A wallet card from a form could not be voided in EpassCard yet; EpassCard tries again by itself.', 'epasscard' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- EpassCard's own table.
			$removed = (bool) $wpdb->delete( EPC_DB::form_submissions_table_name(), array( 'id' => (int) $row->id ), array( '%d' ) ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => true,
		);
	}
}
