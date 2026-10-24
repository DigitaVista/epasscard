<?php
/**
 * Test fixture: a minimal integration and a fake EpassCard API, so pass creation runs end to
 * end on a plain WordPress install. Loaded by the integration tests with wp eval-file.
 *
 * The integration's slug comes from EPC_TEST_MODULE (default "epc-test") and it belongs to a
 * job of its own, so the tests never touch real integrations or real cards.
 *
 * EPC_FAKE_FAIL_CREATE=1 makes create calls fail like a plan limit.
 * EPC_FAKE_DELAY_MS delays each create call.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/**
 * Slug of the test integration.
 *
 * @return string
 */
function epc_test_module_slug() {
	$slug = sanitize_key( (string) getenv( 'EPC_TEST_MODULE' ) );
	return '' !== $slug ? $slug : 'epc-test';
}

if ( ! class_exists( 'EPC_Module_Test_Fixture' ) ) {
	/**
	 * Test integration: one item (id 1); a record id is a user id.
	 */
	class EPC_Module_Test_Fixture extends EPC_Module {
		/** @inheritDoc */
		public function get_slug() {
			return epc_test_module_slug();
		}
		/** @inheritDoc */
		public function get_label() {
			return 'Test integration';
		}
		/** @inheritDoc */
		public function is_available() {
			return true;
		}
		/** @inheritDoc */
		public function get_source_fields() {
			return array( 'name' => 'Name' );
		}
		/** @inheritDoc */
		public function get_mappable_entities() {
			return array(
				array(
					'id'    => 1,
					'label' => 'Test plan',
				),
			);
		}
		/** @inheritDoc */
		public function get_entity_label( $entity_id ) {
			return 'Test plan';
		}
		/** @inheritDoc */
		public function sync_by_source_id( $source_id, $mode = 'sync' ) {
			$id = absint( $source_id );
			return EPC_Pass_Service::sync_pass( $this->get_slug(), $id, 1, $id, $this->get_mapping( 1 ), array( 'name' => 'Member ' . $id ), $mode );
		}
		/**
		 * Existing records for "Give cards to existing customers": $GLOBALS['epc_test_backfill']
		 * maps an entity id to its record ids (user ids). Unsupported when not set.
		 *
		 * @inheritDoc
		 */
		public function get_backfill_source_ids( $entity_id, $limit, $offset ) {
			if ( ! isset( $GLOBALS['epc_test_backfill'] ) ) {
				return null;
			}
			$ids = $GLOBALS['epc_test_backfill'][ absint( $entity_id ) ] ?? array();
			return array_slice( $ids, absint( $offset ), absint( $limit ) );
		}
		/** @inheritDoc */
		public function get_backfill_user_id( $source_id ) {
			return absint( $source_id );
		}
		/**
		 * Status rules when $GLOBALS['epc_test_statuses'] lists statuses (status => label).
		 *
		 * @inheritDoc
		 */
		public function has_pass_behavior_settings() {
			return ! empty( $GLOBALS['epc_test_statuses'] );
		}
		/** @inheritDoc */
		public function get_pass_behavior_statuses() {
			return isset( $GLOBALS['epc_test_statuses'] ) ? (array) $GLOBALS['epc_test_statuses'] : array();
		}
		/** @inheritDoc */
		public function get_status_rules_option_key() {
			return 'epc_' . str_replace( '-', '_', $this->get_slug() ) . '_status_rules';
		}
		/** @inheritDoc */
		public function get_default_status_rules() {
			return array_fill_keys( array_keys( $this->get_pass_behavior_statuses() ), 'sync' );
		}
		/** @inheritDoc */
		protected function register_event_hooks() {}
	}
}

add_filter(
	'epc_module_files',
	static function ( $files ) {
		$files[ epc_test_module_slug() ] = 'fixture';
		return $files;
	}
);
add_filter(
	'epc_module_classes',
	static function ( $classes ) {
		$classes[ epc_test_module_slug() ] = 'EPC_Module_Test_Fixture';
		return $classes;
	}
);
add_filter(
	'epc_module_path',
	static function ( $path, $slug ) {
		return epc_test_module_slug() === $slug ? __FILE__ : $path;
	},
	10,
	2
);
add_filter(
	'epc_jobs',
	static function ( $jobs ) {
		$jobs['epc-test-job'] = array(
			'label'   => 'Test job',
			'modules' => array( epc_test_module_slug() ),
		);
		return $jobs;
	}
);

// Fake EpassCard API.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'epasscard' ) ) {
			return $pre;
		}
		$body = array(
			'status'  => 200,
			'success' => true,
			'data'    => array(),
		);
		if ( false !== strpos( (string) $url, 'create-single-pass' ) ) {
			usleep( max( 0, (int) getenv( 'EPC_FAKE_DELAY_MS' ) ) * 1000 );
			if ( getenv( 'EPC_FAKE_FAIL_CREATE' ) ) {
				$body = array(
					'status'  => 402,
					'message' => 'Pass limit reached for your plan',
				);
			} else {
				$uid  = wp_generate_uuid4();
				$body = array(
					'status'   => 200,
					'passUid'  => $uid,
					'passLink' => 'https://pass.example.test/' . $uid,
				);
			}
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Mail is accepted without sending.
add_filter(
	'pre_wp_mail',
	static function () {
		return true;
	}
);

/**
 * Connect the site with a dummy key (the fake API answers) and map the test item.
 *
 * What it changes is saved in an option, so another process can undo it
 * (epc_test_fixture_teardown()).
 *
 * @return void
 */
function epc_test_fixture_setup() {
	$slug = epc_test_module_slug();
	add_option(
		'epc_test_fixture_' . $slug,
		array(
			'settings' => get_option( 'epc_connection_settings', null ),
			'enabled'  => get_option( 'epc_enabled_modules', null ),
		),
		'',
		false
	);
	$enabled   = get_option( 'epc_enabled_modules', array() );
	$enabled   = is_array( $enabled ) ? $enabled : array();
	$enabled[] = $slug;
	update_option( 'epc_enabled_modules', array_values( array_unique( $enabled ) ) );
	if ( '' === EPC_Connection::get_api_key() ) {
		$settings                      = EPC_Connection::get_settings();
		$settings['api_key_encrypted'] = EPC_Encryption::encrypt( 'epc-test-key' );
		update_option( 'epc_connection_settings', $settings );
	}
	EPC_Module_Loader::get_module_instance( $slug )->save_mapping(
		1,
		array(
			'template_uid'  => '11111111-2222-4333-8444-555555555555',
			'field_mapping' => array(
				'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' => array(
					'type'   => 'source',
					'source' => 'name',
				),
			),
			'pass_fields'   => array(),
		)
	);
}

/**
 * Remove what the fixture and the tests created: the test integration's cards, mapping and
 * failure records, the test users, the dummy key and the enabled-integration change.
 *
 * @return void
 */
function epc_test_fixture_teardown() {
	global $wpdb;
	$slug  = epc_test_module_slug();
	$saved = get_option( 'epc_test_fixture_' . $slug, array() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE module = %s', EPC_DB::table_name(), $slug ) );
	delete_option( 'epc_mappings_' . $slug );
	delete_option( 'epc_' . str_replace( '-', '_', $slug ) . '_status_rules' );
	foreach ( EPC_Pass_Issues::for_module( $slug ) as $issue ) {
		EPC_Pass_Issues::clear( $slug, (string) $issue['source_id'] );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'epc_t_' ) . '%' ) ) as $user_id ) {
		wp_delete_user( (int) $user_id );
	}

	if ( is_array( $saved ) && array_key_exists( 'enabled', $saved ) ) {
		if ( null === $saved['enabled'] ) {
			delete_option( 'epc_enabled_modules' );
		} else {
			update_option( 'epc_enabled_modules', $saved['enabled'] );
		}
	}
	if ( is_array( $saved ) && array_key_exists( 'settings', $saved ) ) {
		if ( null === $saved['settings'] ) {
			delete_option( 'epc_connection_settings' );
		} else {
			update_option( 'epc_connection_settings', $saved['settings'] );
		}
	}
	delete_option( 'epc_test_fixture_' . $slug );
}

/**
 * New subscriber for one check.
 *
 * @return int
 */
function epc_test_fixture_user() {
	$login = 'epc_t_' . wp_generate_password( 10, false, false );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		echo 'FAIL could not create test user: ', esc_html( $id->get_error_message() ), "\n";
		exit( 1 );
	}
	return (int) $id;
}
