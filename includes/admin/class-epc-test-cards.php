<?php
/**
 * Test cards for the admin ("Try it").
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin's own test card for a job.
 *
 * Loyalty: the admin's own loyalty account card, stored as a test card (is_test = 1).
 * Other jobs: there is no member, booking or gift card behind a test card, so it is not stored
 * in wp_epc_passes (the notification job and status rules read every row there and would look
 * up a record that does not exist). Its pass id and link are kept in one option instead, so it
 * can be shown again and expired.
 */
class EPC_Test_Cards {

	/**
	 * Option: module slug => {pass_uid, pass_link, template_uid, created_at}.
	 */
	const OPTION = 'epc_test_cards';

	/**
	 * The current test card of a module, if any.
	 *
	 * @param string $slug Module slug.
	 * @return array{pass_uid: string, pass_link: string, template_uid: string, created_at: int}|null
	 */
	public static function get( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( 'woocommerce-loyalty' === $slug ) {
			$row = EPC_DB::get_pass( $slug, get_current_user_id() );
			if ( $row && '' !== (string) $row->pass_link && 'active' === (string) $row->status ) {
				return array(
					'pass_uid'     => (string) $row->pass_uid,
					'pass_link'    => (string) $row->pass_link,
					'template_uid' => (string) $row->template_uid,
					'created_at'   => (int) strtotime( (string) $row->created_at ),
				);
			}
			return null;
		}

		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $slug ]['pass_link'] ) ? $all[ $slug ] : null;
	}

	/**
	 * Create the admin's test card (or return the existing one).
	 *
	 * @param string $slug Module slug.
	 * @return array{pass_uid: string, pass_link: string, template_uid: string, created_at: int}|\WP_Error
	 */
	public static function issue( $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( 'woocommerce-loyalty' === $slug ) {
			$result = EPC_Loyalty_Pass_Design_Service::issue_test_pass( get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$card = self::get( $slug );
			return $card ? $card : new WP_Error( 'epc_test_card_missing', __( 'The card was created but no wallet link was returned. Check the API log.', 'epasscard' ) );
		}

		$module = EPC_Module_Loader::get_module_instance( $slug );
		if ( ! $module instanceof EPC_Module || ! $module->is_available() ) {
			return new WP_Error( 'epc_test_card_module', __( 'This integration is not available.', 'epasscard' ) );
		}

		$mapping = self::first_mapping( $module );
		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_test_card_design', __( 'Save a card design first.', 'epasscard' ) );
		}

		$existing = self::get( $slug );
		if ( $existing && (string) $existing['template_uid'] === (string) $mapping['template_uid'] ) {
			return $existing;
		}

		// Profile fields show the values of your own profile on the test card.
		$fields = EPC_Pass_Service::build_create_fields( $mapping, EPC_User_Fields::attach( $mapping, self::sample_values( $module ), get_current_user_id() ), $slug );
		$fields = self::make_unique_fields_unique( $fields, $mapping );
		if ( empty( $fields ) ) {
			return new WP_Error( 'epc_no_fields', __( 'No mapped fields to send.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $slug . ':test_card' );
		}
		$created = EPC_Api_Client::create_pass( (string) $mapping['template_uid'], $fields );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$card         = array(
			'pass_uid'     => (string) $created['passUid'],
			'pass_link'    => esc_url_raw( (string) $created['passLink'] ),
			'template_uid' => (string) $mapping['template_uid'],
			'created_at'   => time(),
		);
		$all          = get_option( self::OPTION, array() );
		$all          = is_array( $all ) ? $all : array();
		$replaced     = isset( $all[ $slug ] ) ? $all[ $slug ] : null;
		$all[ $slug ] = $card;
		update_option( self::OPTION, $all, false );

		// A test card made for an older design is expired so it does not linger in the account.
		if ( is_array( $replaced ) && ! empty( $replaced['pass_uid'] ) ) {
			EPC_Api_Client::expire_pass( (string) $replaced['pass_uid'] );
		}

		return $card;
	}

	/**
	 * Expire and forget a module's test card (not loyalty: that card is the admin's own account).
	 *
	 * @param string $slug Module slug.
	 * @return true|\WP_Error
	 */
	public static function delete( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$all  = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $slug ]['pass_uid'] ) ) {
			return true;
		}
		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $slug . ':test_card_expire' );
		}
		$result = EPC_Api_Client::expire_pass( (string) $all[ $slug ]['pass_uid'] );
		if ( is_wp_error( $result ) && ! EPC_Api_Client::is_ownership_error( $result ) ) {
			return $result;
		}
		unset( $all[ $slug ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/**
	 * The mapping the test card uses: the first item with a design.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<string, mixed>
	 */
	public static function first_mapping( $module ) {
		foreach ( $module->get_filtered_mappable_entities() as $entity ) {
			$mapping = $module->get_mapping( absint( $entity['id'] ?? 0 ) );
			if ( ! empty( $mapping['template_uid'] ) ) {
				return $mapping;
			}
		}
		foreach ( $module->get_mappings() as $mapping ) {
			if ( is_array( $mapping ) && ! empty( $mapping['template_uid'] ) ) {
				return $mapping;
			}
		}
		return array();
	}

	/**
	 * Sample values for every source field: the admin's own name and email, the preset's
	 * samples, and the field's label for anything else.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<string, string>
	 */
	public static function sample_values( $module ) {
		$values = array();
		foreach ( $module->get_mapping_source_fields() as $source => $label ) {
			/* translators: %s: name of a data field, e.g. "Membership level" */
			$values[ (string) $source ] = sprintf( __( 'Sample %s', 'epasscard' ), wp_strip_all_tags( (string) $label ) );
		}

		$preset = EPC_Template_Starter::get_preset( $module->get_slug() );
		if ( is_array( $preset ) ) {
			foreach ( array( 'header', 'primary', 'secondary', 'auxiliary', 'back' ) as $area ) {
				foreach ( (array) ( $preset[ $area ] ?? array() ) as $field ) {
					if ( ! empty( $field['source'] ) && '' !== (string) $field['sample'] ) {
						$values[ (string) $field['source'] ] = (string) $field['sample'];
					}
				}
			}
			// The barcode field is unique on the ready-made template: never reuse a real card's id.
			if ( ! empty( $preset['barcode']['source'] ) ) {
				$values[ (string) $preset['barcode']['source'] ] = 'TEST-' . strtoupper( wp_generate_password( 8, false, false ) );
			}
		}

		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$first = (string) get_user_meta( $user->ID, 'first_name', true );
			$last  = (string) get_user_meta( $user->ID, 'last_name', true );
			$name  = epc_format_user_full_name( $first, $last, $user->display_name );
			/* translators: %s: the admin's name */
			$test_name = sprintf( __( '%s (test)', 'epasscard' ), $name );
			foreach ( array( 'user_full_name', 'user_display_name', 'member_name', 'attendee_name', 'customer_name' ) as $key ) {
				$values[ $key ] = $test_name;
			}
			$values['user_first_name'] = $first;
			$values['user_last_name']  = $last;
			$values['user_email']      = (string) $user->user_email;
		}

		return $values;
	}

	/**
	 * Give fields the template marks as unique a value no real card has.
	 *
	 * @param array<int, array{uid: string, fieldValue: string}> $fields  Fields to send.
	 * @param array<string, mixed>                               $mapping Mapping (with the pass_fields snapshot).
	 * @return array<int, array{uid: string, fieldValue: string}>
	 */
	private static function make_unique_fields_unique( array $fields, array $mapping ) {
		$unique = array();
		foreach ( (array) ( $mapping['pass_fields'] ?? array() ) as $pass_field ) {
			if ( is_array( $pass_field ) && ! empty( $pass_field['uid'] ) && ! empty( $pass_field['is_unique'] ) && 'false' !== (string) $pass_field['is_unique'] ) {
				$unique[ strtolower( (string) $pass_field['uid'] ) ] = true;
			}
		}
		foreach ( $fields as $i => $field ) {
			if ( isset( $unique[ strtolower( (string) $field['uid'] ) ] ) ) {
				$fields[ $i ]['fieldValue'] = 'TEST-' . strtoupper( wp_generate_password( 8, false, false ) );
			}
		}
		return $fields;
	}
}
